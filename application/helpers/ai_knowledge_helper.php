<?php if (!defined('BASEPATH')) {
    exit('No direct script access allowed');
}

/**
 * AI Knowledge Base helpers
 *
 * Extracts text from PDFs and URLs, chunks it, stores it, and retrieves
 * relevant chunks via MariaDB FULLTEXT search to inject into AI prompts.
 */

if (!function_exists('ai_extract_pdf_text')) {
    /**
     * Extract plain text from a PDF file.
     *
     * Uses pdftotext (poppler-utils) if available, otherwise falls back to a
     * basic pure-PHP stream scan.
     *
     * @param string $file_path Absolute path to the PDF.
     * @return string|false Extracted text or false on failure.
     */
    function ai_extract_pdf_text($file_path = '')
    {
        if (empty($file_path) || !file_exists($file_path)) {
            return false;
        }

        // Preferred: poppler-utils pdftotext (handles Arabic/English well).
        $pdftotext = trim(shell_exec('which pdftotext 2>/dev/null') ?: '');
        if ($pdftotext !== '' && is_executable($pdftotext)) {
            $tmp_file = tempnam(sys_get_temp_dir(), 'ai_pdf_');
            $cmd = escapeshellarg($pdftotext) . ' -layout -nopgbrk ' . escapeshellarg($file_path) . ' ' . escapeshellarg($tmp_file) . ' 2>/dev/null';
            @shell_exec($cmd);
            $text = file_exists($tmp_file) ? file_get_contents($tmp_file) : '';
            @unlink($tmp_file);
            if (!empty($text)) {
                return ai_normalize_text($text);
            }
        }

        // Fallback: naive stream extraction of text objects.
        $content = @file_get_contents($file_path);
        if ($content === false) {
            return false;
        }

        // Extract text between BT ... ET blocks and inside parentheses.
        if (preg_match_all('/BT\s*(.*?)\s*ET/s', $content, $matches)) {
            $text = implode("\n", $matches[1]);
            $text = preg_replace('/\(([^()]+)\)/s', '$1', $text);
            $text = preg_replace('/\\\d{3}/', '', $text);
            return ai_normalize_text($text);
        }

        // Last resort: strip binary and recover readable strings.
        $text = preg_replace('/[^\x20-\x7E\x{0600}-\x{06FF}\s]/u', ' ', $content);
        return ai_normalize_text($text);
    }
}

if (!defined('AI_URL_STRIP_SELECTORS')) {
    // Navigation chrome, boilerplate and cookie banners pollute every chunk
    // ("Terms of ServicePrivacy PolicyCookie Policy| Crafted by ...") and
    // dilute both lexical and semantic matching.
    define('AI_URL_STRIP_SELECTORS', 'script,style,noscript,nav,footer,header,aside,form,iframe,svg,button,select,option,template');
}

if (!function_exists('ai_extract_url_text')) {
    /**
     * Extract visible text from a web page URL.
     *
     * @param string $url Page URL.
     * @return string|false Extracted text or false on failure.
     */
    function ai_extract_url_text($url = '')
    {
        if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        $ch = curl_init();
        curl_setopt_array($ch, array(
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; MonkeyBot/1.0; +https://bot.cremonkey.com)',
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
        ));
        $html = curl_exec($ch);
        $http_code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http_code < 200 || $http_code >= 300 || empty($html)) {
            return false;
        }

        // Try to use the bundled Simple_html_dom library if available.
        $ci_available = function_exists('get_instance');
        $simple_html_loaded = false;
        if ($ci_available && file_exists(APPPATH . 'libraries/Simple_html_dom.php')) {
            $ci = &get_instance();
            if (is_object($ci)) {
                $ci->load->library('Simple_html_dom');
                if (isset($ci->simple_html_dom) && is_object($ci->simple_html_dom) && method_exists($ci->simple_html_dom, 'load')) {
                    $dom = $ci->simple_html_dom->load($html, true, false);
                    if (is_object($dom)) {
                        foreach ($dom->find(AI_URL_STRIP_SELECTORS) as $node) {
                            $node->outertext = '';
                        }
                        // Separate adjacent block/inline elements so their text
                        // does not concatenate ("ServicePrivacy" -> "Service Privacy").
                        foreach ($dom->find('a,li,p,div,br,h1,h2,h3,h4,h5,h6,td,th,span') as $node) {
                            $node->outertext = $node->outertext . ' ';
                        }
                        $text = $dom->plaintext;
                        return ai_normalize_text($text);
                    }
                }
            }
        }

        // Fallback native DOM extraction.
        $doc = new DOMDocument();
        @$doc->loadHTML('<?xml encoding="UTF-8">' . $html);
        $xpath = new DOMXPath($doc);
        $strip = array();
        foreach (explode(',', AI_URL_STRIP_SELECTORS) as $tag) {
            $strip[] = '//' . trim($tag);
        }
        // Snapshot the NodeList: removing nodes while iterating it skips siblings.
        $doomed = iterator_to_array($xpath->query(implode('|', $strip)));
        foreach ($doomed as $node) {
            if ($node->parentNode) {
                $node->parentNode->removeChild($node);
            }
        }
        // Insert separators so adjacent elements' text does not concatenate.
        foreach (iterator_to_array($xpath->query('//a|//li|//p|//div|//br|//h1|//h2|//h3|//h4|//h5|//h6|//td|//th|//span')) as $node) {
            if ($node->parentNode) {
                $node->parentNode->insertBefore($doc->createTextNode(' '), $node->nextSibling);
            }
        }
        $text = $doc->textContent;
        return ai_normalize_text($text);
    }
}

if (!function_exists('ai_normalize_text')) {
    /**
     * Normalize extracted text for storage and search.
     *
     * @param string $text Raw text.
     * @return string Normalized text.
     */
    function ai_normalize_text($text = '')
    {
        if (empty($text)) {
            return '';
        }

        // Convert common entities and normalize whitespace.
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);
        $text = preg_replace('/\s+/u', ' ', $text);
        return trim($text);
    }
}

if (!function_exists('ai_chunk_text')) {
    /**
     * Split text into overlapping chunks, breaking on word boundaries.
     *
     * Character-based slicing cut tokens in half ("From EG" instead of
     * "From EGP 5,650"), which corrupts both lexical and semantic matching.
     * Each chunk now ends at the last whitespace at or before the target size,
     * and the next chunk starts $overlap characters back from that boundary.
     *
     * @param string $text Text to chunk.
     * @param int    $chunk_size Target chunk size in characters.
     * @param int    $overlap Overlap in characters.
     * @return array Array of chunk strings.
     */
    function ai_chunk_text($text = '', $chunk_size = 1000, $overlap = 200)
    {
        $text = ai_normalize_text($text);
        if (empty($text)) {
            return array();
        }

        $chunk_size = max(50, (int) $chunk_size);
        $overlap = max(0, min((int) $overlap, $chunk_size - 1));

        $chunks = array();
        $length = mb_strlen($text, 'UTF-8');
        $start = 0;

        while ($start < $length) {
            $remaining = $length - $start;

            if ($remaining <= $chunk_size) {
                $chunk = ai_normalize_text(mb_substr($text, $start, $remaining, 'UTF-8'));
                if ($chunk !== '') {
                    $chunks[] = $chunk;
                }
                break;
            }

            $slice = mb_substr($text, $start, $chunk_size, 'UTF-8');

            // Back off to the last word boundary so we never split a token.
            $cut = mb_strrpos($slice, ' ', 0, 'UTF-8');
            if ($cut === false || $cut < (int) ($chunk_size / 2)) {
                // No usable boundary (e.g. a very long unbroken token): take the
                // hard slice rather than emitting a degenerate chunk.
                $cut = $chunk_size;
            }

            $chunk = ai_normalize_text(mb_substr($slice, 0, $cut, 'UTF-8'));
            if ($chunk !== '') {
                $chunks[] = $chunk;
            }

            // Advance past this chunk, then step back by the overlap. Snap the
            // resulting position forward to a word boundary so the next chunk
            // does not begin mid-token ("ults" instead of "Adults").
            $next = $start + $cut - $overlap;
            if ($next > $start && $next < $length) {
                $boundary = mb_strpos($text, ' ', $next, 'UTF-8');
                if ($boundary !== false && $boundary < $start + $cut) {
                    $next = $boundary + 1;
                }
            }
            // Guard against a non-positive step, which would loop forever.
            if ($next <= $start) {
                $next = $start + $cut;
            }
            $start = $next;
        }

        return $chunks;
    }
}

/*
 * ---------------------------------------------------------------------------
 * Retrieval layer (SPEC-20): hybrid semantic + lexical search.
 *
 * All retrieval logic lives in this helper, not in the ai_knowledge_base addon
 * module, because that module is a third-party addon that has been overwritten
 * by reinstalls before. This file is tracked in git.
 *
 * Every function degrades to the pre-SPEC-20 FULLTEXT-only behaviour on any
 * failure: missing column, missing API key, network error, NULL embedding.
 * ---------------------------------------------------------------------------
 */

if (!defined('AI_EMBEDDING_MODEL')) {
    define('AI_EMBEDDING_MODEL', 'text-embedding-3-small');
}
if (!defined('AI_EMBEDDING_DIMS')) {
    define('AI_EMBEDDING_DIMS', 1536);
}
if (!defined('AI_VECTOR_MIN_SCORE')) {
    // Cosine similarity floor: a NOISE FILTER, not an answerability judge.
    //
    // Calibrated 2026-07-09 against the live knowledge base (45 chunks) with a
    // 14-positive / 8-negative eval set. The two classes OVERLAP:
    //
    //   min(answerable)    0.2126  "المنتجع فين؟"        (answer is in the KB)
    //   max(unanswerable)  0.3111  "عندكم تذاكر طيران؟"  (answer is not)
    //
    // Cosine measures topical proximity, not whether the answer is present. A
    // flight-ticket question sits close to resort/booking text, so no single
    // threshold separates the classes. Anything high enough to reject it also
    // rejects "عندكم سبا؟" (0.2532), which the KB does answer.
    //
    // Deciding "do I actually know this?" is the model's job, and it is already
    // built and tested: guardrail rule 3 (ZERO OUTSIDE INFORMATION) plus the
    // [[UNANSWERED]] marker make it decline and hand off to the team when the
    // excerpts do not contain the answer.
    //
    // So this floor only discards obvious junk ("ما هي عاصمة اليابان؟" 0.0852,
    // "do you sell insurance" 0.1496) to save prompt tokens. At 0.20 the eval
    // set retains 14/14 answerable questions.
    define('AI_VECTOR_MIN_SCORE', 0.20);
}

if (!function_exists('ai_openai_key')) {
    /**
     * Fetch the account's OpenAI secret key.
     *
     * Embeddings always use OpenAI regardless of the configured ai_provider,
     * because Anthropic exposes no embeddings endpoint.
     *
     * @param int $user_id Owner user ID.
     * @return string API key, or '' when unavailable.
     */
    function ai_openai_key($user_id = 0)
    {
        $ci = &get_instance();
        if (empty($user_id) || !is_object($ci)) {
            return '';
        }
        $row = $ci->db->select('open_ai_secret_key')
            ->from('open_ai_config')
            ->where('user_id', (int) $user_id)
            ->limit(1)
            ->get()
            ->row_array();
        return isset($row['open_ai_secret_key']) ? trim((string) $row['open_ai_secret_key']) : '';
    }
}

if (!function_exists('ai_embed_texts')) {
    /**
     * Embed a batch of strings in a single OpenAI embeddings call.
     *
     * Batching matters: ingesting a source produces dozens of chunks, and one
     * HTTP round-trip per chunk would time out the upload request.
     *
     * Never throws. Returns false on any failure so callers can fall back to
     * lexical search.
     *
     * @param array  $texts   List of strings to embed (max 96 per call).
     * @param int    $user_id Owner user ID (supplies the API key).
     * @param string $api_key Optional key override, for CLI/backfill callers
     *                        that run outside a CodeIgniter request context.
     * @return array|false Vectors in input order, or false.
     */
    function ai_embed_texts($texts = array(), $user_id = 0, $api_key = '')
    {
        if (empty($texts) || !is_array($texts)) {
            return false;
        }

        $api_key = trim((string) $api_key);
        if ($api_key === '') {
            $api_key = ai_openai_key($user_id);
        }
        if ($api_key === '') {
            return false;
        }

        $inputs = array();
        foreach ($texts as $text) {
            $text = ai_normalize_text($text);
            if ($text === '') {
                // The API rejects empty strings; a placeholder keeps indices aligned.
                $text = '-';
            }
            // Guard against pathological inputs blowing the model's token limit.
            if (mb_strlen($text, 'UTF-8') > 8000) {
                $text = mb_substr($text, 0, 8000, 'UTF-8');
            }
            $inputs[] = $text;
        }

        $payload = json_encode(array(
            'model' => AI_EMBEDDING_MODEL,
            'input' => $inputs,
            'dimensions' => AI_EMBEDDING_DIMS,
        ), JSON_UNESCAPED_UNICODE);

        $ch = curl_init();
        curl_setopt_array($ch, array(
            CURLOPT_URL => 'https://api.openai.com/v1/embeddings',
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_HTTPHEADER => array(
                'Content-Type: application/json',
                'Authorization: Bearer ' . $api_key,
            ),
        ));
        $response = curl_exec($ch);
        $http_code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);

        if ($http_code !== 200 || $response === false) {
            log_message('error', 'ai_embed_texts failed: http=' . $http_code . ' curl=' . $curl_error);
            return false;
        }

        $decoded = json_decode($response, true);
        if (!isset($decoded['data']) || !is_array($decoded['data']) || count($decoded['data']) !== count($inputs)) {
            log_message('error', 'ai_embed_texts: unexpected response shape');
            return false;
        }

        // The API documents index-ordered results, but does not promise array
        // order. Re-order by the explicit index field rather than trusting it.
        $vectors = array();
        foreach ($decoded['data'] as $item) {
            if (!isset($item['index'], $item['embedding']) || !is_array($item['embedding'])) {
                log_message('error', 'ai_embed_texts: malformed embedding item');
                return false;
            }
            $vectors[(int) $item['index']] = $item['embedding'];
        }
        ksort($vectors);

        if (count($vectors) !== count($inputs)) {
            return false;
        }

        return array_values($vectors);
    }
}

if (!function_exists('ai_embed_text')) {
    /**
     * Embed a single string.
     *
     * @param string $text    Text to embed.
     * @param int    $user_id Owner user ID (supplies the API key).
     * @param string $api_key Optional key override, same contract as ai_embed_texts().
     * @return array|false Vector of floats, or false.
     */
    function ai_embed_text($text = '', $user_id = 0, $api_key = '')
    {
        $text = ai_normalize_text($text);
        if ($text === '') {
            return false;
        }
        $vectors = ai_embed_texts(array($text), $user_id, $api_key);
        return (is_array($vectors) && isset($vectors[0])) ? $vectors[0] : false;
    }
}

if (!function_exists('ai_pack_vector')) {
    /**
     * Pack a float vector into a compact little-endian float32 BLOB.
     *
     * @param array $vector Vector of floats.
     * @return string Binary blob.
     */
    function ai_pack_vector($vector = array())
    {
        if (empty($vector) || !is_array($vector)) {
            return '';
        }
        return pack('g*', ...array_map('floatval', $vector));
    }
}

if (!function_exists('ai_unpack_vector')) {
    /**
     * Unpack a float32 BLOB back into an array of floats.
     *
     * @param string $blob Binary blob written by ai_pack_vector().
     * @return array Vector of floats (empty on malformed input).
     */
    function ai_unpack_vector($blob = '')
    {
        if (!is_string($blob) || $blob === '' || (strlen($blob) % 4) !== 0) {
            return array();
        }
        $vector = @unpack('g*', $blob);
        return is_array($vector) ? array_values($vector) : array();
    }
}

if (!function_exists('ai_cosine_similarity')) {
    /**
     * Cosine similarity between two equal-length vectors.
     *
     * @param array $a First vector.
     * @param array $b Second vector.
     * @return float Similarity in [-1, 1]; 0.0 when incomparable.
     */
    function ai_cosine_similarity($a = array(), $b = array())
    {
        $len = count($a);
        if ($len === 0 || $len !== count($b)) {
            return 0.0;
        }

        $dot = 0.0;
        $norm_a = 0.0;
        $norm_b = 0.0;
        for ($i = 0; $i < $len; $i++) {
            $dot += $a[$i] * $b[$i];
            $norm_a += $a[$i] * $a[$i];
            $norm_b += $b[$i] * $b[$i];
        }

        if ($norm_a <= 0.0 || $norm_b <= 0.0) {
            return 0.0;
        }

        return $dot / (sqrt($norm_a) * sqrt($norm_b));
    }
}

if (!function_exists('ai_knowledge_has_embeddings')) {
    /**
     * Whether the embedding column exists on ai_knowledge_chunks.
     *
     * Lets the retrieval path skip vector search entirely on installs where the
     * SPEC-20 migration has not been applied.
     *
     * @return bool
     */
    function ai_knowledge_has_embeddings()
    {
        static $has = null;
        if ($has !== null) {
            return $has;
        }
        $ci = &get_instance();
        $has = is_object($ci) && $ci->db->field_exists('embedding', 'ai_knowledge_chunks');
        return $has;
    }
}

if (!function_exists('ai_vector_search')) {
    /**
     * Semantic search: cosine similarity between the query vector and every
     * embedded chunk in scope.
     *
     * Brute force in PHP. At the sizes this table reaches, an index buys
     * nothing, and MariaDB 10.11 has no VECTOR type anyway.
     *
     * @param int   $user_id   Owner user ID.
     * @param array $query_vec Embedded customer message.
     * @param int   $page_id   Page auto ID, or 0 for user-level sources.
     * @param int   $limit     Maximum chunks to return.
     * @return array Rows of ['chunk_text' => ..., 'score' => ...], best first.
     */
    function ai_vector_search($user_id = 0, $query_vec = array(), $page_id = 0, $limit = 5)
    {
        $ci = &get_instance();
        if (empty($user_id) || empty($query_vec) || !is_object($ci)) {
            return array();
        }
        if (!ai_knowledge_has_embeddings()) {
            return array();
        }

        $user_id = (int) $user_id;
        $page_id = (int) $page_id;
        $limit = (int) $limit;

        $scope = $page_id > 0
            ? "s.page_id = {$page_id}"
            : "(s.page_id IS NULL OR s.page_id = 0)";

        $sql = "SELECT c.chunk_text, c.embedding
                FROM ai_knowledge_chunks c
                INNER JOIN ai_knowledge_sources s ON s.id = c.source_id
                WHERE s.user_id = {$user_id}
                  AND {$scope}
                  AND s.status = 'active'
                  AND c.embedding IS NOT NULL";

        $rows = $ci->db->query($sql)->result_array();
        if (empty($rows)) {
            return array();
        }

        $scored = array();
        foreach ($rows as $row) {
            $vector = ai_unpack_vector($row['embedding']);
            if (empty($vector)) {
                continue;
            }
            $score = ai_cosine_similarity($query_vec, $vector);
            if ($score >= AI_VECTOR_MIN_SCORE) {
                $scored[] = array('chunk_text' => $row['chunk_text'], 'score' => $score);
            }
        }

        usort($scored, function ($a, $b) {
            if ($a['score'] === $b['score']) {
                return 0;
            }
            return ($a['score'] < $b['score']) ? 1 : -1;
        });

        return array_slice($scored, 0, $limit);
    }
}

if (!function_exists('ai_fulltext_search')) {
    /**
     * Lexical search via MariaDB FULLTEXT. Unchanged pre-SPEC-20 behaviour.
     *
     * Strong where embeddings are weak: exact product codes, model numbers,
     * literal prices.
     *
     * @param int    $user_id Owner user ID.
     * @param string $query   Customer message.
     * @param int    $page_id Page auto ID, or 0 for user-level sources.
     * @param int    $limit   Maximum chunks to return.
     * @return array Rows of ['chunk_text' => ..., 'score' => ...].
     */
    function ai_fulltext_search($user_id = 0, $query = '', $page_id = 0, $limit = 5)
    {
        $ci = &get_instance();
        if (empty($user_id) || empty($query) || !is_object($ci)) {
            return array();
        }

        $words = preg_split('/\s+/u', trim($query), -1, PREG_SPLIT_NO_EMPTY);
        $words = array_filter($words, function ($w) {
            return mb_strlen($w, 'UTF-8') > 1;
        });
        if (empty($words)) {
            return array();
        }

        // Optional terms (no '+' prefix): requiring every word breaks on InnoDB
        // stopwords ("what", "are", ...) which are never indexed, so a '+stopword'
        // can never match and the whole query returns nothing. Relevance ranking
        // still puts chunks matching the most words first.
        $search_terms = array();
        foreach (array_slice($words, 0, 12) as $word) {
            $word = preg_replace('/[^\p{L}\p{N}]/u', '', $word);
            if ($word !== '' && mb_strlen($word, 'UTF-8') >= 3) {
                $search_terms[] = $word;
            }
        }
        if (empty($search_terms)) {
            return array();
        }

        $against = $ci->db->escape(implode(' ', $search_terms));
        $user_id = (int) $user_id;
        $page_id = (int) $page_id;
        $limit = (int) $limit;

        $scope = $page_id > 0
            ? "s.page_id = {$page_id}"
            : "(s.page_id IS NULL OR s.page_id = 0)";

        $sql = "SELECT c.chunk_text,
                    MATCH(c.chunk_text) AGAINST (" . $against . " IN BOOLEAN MODE) AS score
                FROM ai_knowledge_chunks c
                INNER JOIN ai_knowledge_sources s ON s.id = c.source_id
                WHERE s.user_id = {$user_id}
                  AND {$scope}
                  AND s.status = 'active'
                  AND MATCH(c.chunk_text) AGAINST (" . $against . " IN BOOLEAN MODE)
                ORDER BY score DESC
                LIMIT {$limit}";

        return $ci->db->query($sql)->result_array();
    }
}

if (!function_exists('ai_merge_search_results')) {
    /**
     * Merge semantic and lexical hits, semantic first, de-duplicated by text.
     *
     * @param array $vector_rows   Semantic hits, best first.
     * @param array $fulltext_rows Lexical hits, best first.
     * @param int   $limit         Maximum rows to return.
     * @return array Merged rows.
     */
    function ai_merge_search_results($vector_rows = array(), $fulltext_rows = array(), $limit = 5)
    {
        $merged = array();
        $seen = array();

        foreach (array($vector_rows, $fulltext_rows) as $rows) {
            foreach ($rows as $row) {
                if (!isset($row['chunk_text'])) {
                    continue;
                }
                $key = md5($row['chunk_text']);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $merged[] = $row;
                if (count($merged) >= $limit) {
                    return $merged;
                }
            }
        }

        return $merged;
    }
}

if (!function_exists('ai_get_knowledge_context')) {
    /**
     * Retrieve relevant knowledge-base chunks for a user message.
     *
     * Hybrid: semantic search finds cross-lingual and paraphrased matches (an
     * Arabic question against English source text); lexical search finds exact
     * tokens. Semantic hits rank first.
     *
     * Page-scoped sources are searched first; if they yield nothing, user-level
     * sources are searched.
     *
     * Falls back to lexical-only whenever embedding is unavailable, so an
     * OpenAI outage cannot degrade the bot below its pre-SPEC-20 behaviour.
     *
     * @param int    $user_id Owner user ID.
     * @param string $query   Customer message.
     * @param int    $page_id Optional page auto ID for page-scoped sources.
     * @param int    $limit   Maximum chunks to return.
     * @return string Concatenated excerpts or empty string.
     */
    function ai_get_knowledge_context($user_id = 0, $query = '', $page_id = 0, $limit = 5)
    {
        $ci = &get_instance();
        if (empty($user_id) || empty($query) || !is_object($ci)) {
            return '';
        }

        if (!$ci->db->table_exists('ai_knowledge_sources') || !$ci->db->table_exists('ai_knowledge_chunks')) {
            return '';
        }

        $limit = (int) $limit;
        $page_id = (int) $page_id;

        // One embedding call per customer message, reused for both scopes.
        // false (outage, no key, no column) simply disables the semantic half.
        $query_vec = ai_knowledge_has_embeddings() ? ai_embed_text($query, $user_id) : false;
        if (!is_array($query_vec)) {
            $query_vec = array();
        }

        // Page-scoped sources first.
        if ($page_id > 0) {
            $rows = ai_merge_search_results(
                ai_vector_search($user_id, $query_vec, $page_id, $limit),
                ai_fulltext_search($user_id, $query, $page_id, $limit),
                $limit
            );
            if (!empty($rows)) {
                return ai_build_knowledge_context($rows);
            }
        }

        // Fall back to user-level sources (page_id IS NULL or 0).
        $rows = ai_merge_search_results(
            ai_vector_search($user_id, $query_vec, 0, $limit),
            ai_fulltext_search($user_id, $query, 0, $limit),
            $limit
        );

        return ai_build_knowledge_context($rows);
    }
}

if (!function_exists('ai_embed_source_chunks')) {
    /**
     * Generate and store embeddings for a source's chunks.
     *
     * Idempotent: skips chunks that already carry an embedding for the current
     * model, so it is safe to re-run after a partial failure. Must be called
     * outside a DB transaction — it makes one network call per chunk.
     *
     * A chunk left without an embedding is not lost: it stays reachable through
     * lexical search.
     *
     * @param int $source_id Knowledge source ID.
     * @param int $user_id   Owner user ID (supplies the API key).
     * @return array ['embedded' => int, 'failed' => int, 'skipped' => int]
     */
    function ai_embed_source_chunks($source_id = 0, $user_id = 0)
    {
        $result = array('embedded' => 0, 'failed' => 0, 'skipped' => 0);

        $ci = &get_instance();
        if (empty($source_id) || empty($user_id) || !is_object($ci)) {
            return $result;
        }
        if (!ai_knowledge_has_embeddings()) {
            return $result;
        }

        $rows = $ci->db->select('id, chunk_text, embedding, embedding_model')
            ->from('ai_knowledge_chunks')
            ->where('source_id', (int) $source_id)
            ->get()
            ->result_array();

        $pending = array();
        foreach ($rows as $row) {
            $already = !empty($row['embedding'])
                && isset($row['embedding_model'])
                && $row['embedding_model'] === AI_EMBEDDING_MODEL;
            if ($already) {
                $result['skipped']++;
                continue;
            }
            $pending[] = $row;
        }

        // Batch the API calls: one round-trip per 96 chunks, not per chunk.
        foreach (array_chunk($pending, 96) as $batch) {
            $texts = array_column($batch, 'chunk_text');
            $vectors = ai_embed_texts($texts, $user_id);

            if (!is_array($vectors)) {
                // Whole batch failed. Those chunks keep a NULL embedding and
                // stay reachable via lexical search.
                $result['failed'] += count($batch);
                continue;
            }

            foreach ($batch as $i => $row) {
                if (!isset($vectors[$i]) || !is_array($vectors[$i]) || empty($vectors[$i])) {
                    $result['failed']++;
                    continue;
                }
                $ci->db->where('id', (int) $row['id'])->update('ai_knowledge_chunks', array(
                    'embedding' => ai_pack_vector($vectors[$i]),
                    'embedding_model' => AI_EMBEDDING_MODEL,
                ));
                $result['embedded']++;
            }
        }

        return $result;
    }
}

if (!function_exists('ai_build_knowledge_context')) {
    /**
     * Build a concatenated context string from chunk rows.
     *
     * @param array $rows Database rows with chunk_text.
     * @return string Concatenated excerpts.
     */
    function ai_build_knowledge_context($rows = array())
    {
        if (empty($rows)) {
            return '';
        }
        $excerpts = array();
        foreach ($rows as $row) {
            $excerpt = ai_normalize_text($row['chunk_text']);
            if ($excerpt !== '') {
                $excerpts[] = $excerpt;
            }
        }
        return implode("\n\n---\n\n", $excerpts);
    }
}

/*
 * ---------------------------------------------------------------------------
 * Price grounding guard (SPEC-21).
 *
 * The model attaches REAL prices to the WRONG item. Two live examples:
 *   "الداي يوز بـ350؟"  -> bot agreed. 350 is the Coffee Break price;
 *                          day use starts from 900.
 *   "جناح الأجنحة الملكية" -> bot answered 7,800, the Junior Suite price.
 *
 * No prompt stops this (a dedicated rule failed; gpt-4o failed too), and a
 * code-side "is this number in the context?" check is useless — both numbers
 * ARE in the context, just against a different item.
 *
 * So a second model audits the reply against the FULL source text before it is
 * sent. Scored 7/7 on the known failures plus four correct replies.
 * ---------------------------------------------------------------------------
 */

if (!defined('AI_GUARD_MODEL')) {
    define('AI_GUARD_MODEL', 'gpt-4o-mini');
}
if (!defined('AI_GUARD_MAX_SOURCE_CHARS')) {
    // The judge needs the whole price list, not the retrieved excerpts: when it
    // saw only the top-5 chunks it rejected two CORRECT replies because the
    // confirming line was not among them. Beyond this size, fall back to
    // excerpts and accept the lower accuracy.
    define('AI_GUARD_MAX_SOURCE_CHARS', 60000);
}

if (!function_exists('ai_reply_quotes_a_price')) {
    /**
     * Whether a reply states a price, and therefore needs auditing.
     *
     * Deliberately narrow. A bare long number is NOT a price: the bot echoes
     * customers' phone numbers ("شكراً لمشاركتك رقمك 012788334522") and auditing
     * those would deflect a perfectly good reply. Require a thousands separator
     * or an explicit currency word.
     *
     * @param string $reply The assistant's reply text.
     * @return bool
     */
    function ai_reply_quotes_a_price($reply = '')
    {
        if (!is_string($reply) || $reply === '') {
            return false;
        }
        // 5,500 / 11,500
        if (preg_match('/\d{1,3},\d{3}\b/u', $reply)) {
            return true;
        }
        // 900 جنيه / 350 EGP / EGP 900 / ج.م 900
        if (preg_match('/(\d{2,6}\s*(جنيه|جنيهاً|جنيها|ج\.م|EGP|LE)|(جنيه|EGP|LE)\s*\d{2,6})/iu', $reply)) {
            return true;
        }
        return false;
    }
}

if (!function_exists('ai_reply_ties_price_to_headcount')) {
    /**
     * Whether the reply prices something per head or for a group.
     *
     * The judge is a model and misses this: it read "الداي يوز لفردين بيبدأ من
     * 900" as grounded because 900 really is the day-use starting price - the
     * "لفردين" slid past. But the source never says how many people 900 covers,
     * so quoting it for two is an invented claim, and one that costs money.
     *
     * A headcount claim is cheap to detect and expensive to get wrong, so it
     * gets a deterministic gate rather than a probabilistic one.
     *
     * @param string $reply The assistant's reply text.
     * @return bool
     */
    function ai_reply_ties_price_to_headcount($reply = '')
    {
        if (!is_string($reply) || $reply === '') {
            return false;
        }
        $patterns = array(
            '/للشخص/u', '/للفرد/u', '/لفردين/u', '/لشخصين/u', '/للفردين/u', '/للشخصين/u',
            '/\bلـ?\s*\d+\s*(شخص|أشخاص|اشخاص|فرد|أفراد|افراد)/u',
            '/\b(per|each)\s+(person|guest|adult|head)\b/i',
            '/\bfor\s+\d+\s+(people|persons|guests|adults)\b/i',
            '/\bfor\s+two\b/i',
        );
        foreach ($patterns as $p) {
            if (preg_match($p, $reply)) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('ai_source_states_per_person_pricing')) {
    /**
     * Whether the source anywhere prices something per person.
     *
     * If it never does, no reply may. If it does somewhere, the claim is at
     * least plausible and the judge decides.
     *
     * @param string $source Campaign instructions + knowledge base.
     * @return bool
     */
    function ai_source_states_per_person_pricing($source = '')
    {
        if (!is_string($source) || $source === '') {
            return false;
        }
        // \bpp\b, not pp\b: the latter matches the tail of "WhatsApp", which the
        // knowledge base contains, and would silently disable this whole gate.
        return (bool) preg_match('/(per\s+person|per\s+guest|per\s+adult|\bpp\b|للشخص|للفرد|للفرد الواحد)/iu', $source);
    }
}

if (!function_exists('ai_get_full_knowledge')) {
    /**
     * The complete active knowledge base for a scope, as one string.
     *
     * Page-scoped sources when $page_id is given, otherwise user-level ones —
     * the same scoping ai_get_knowledge_context() applies.
     *
     * @param int $user_id Owner user ID.
     * @param int $page_id Page auto ID, or 0.
     * @return string Concatenated chunk text, or '' when unavailable.
     */
    function ai_get_full_knowledge($user_id = 0, $page_id = 0)
    {
        $ci = &get_instance();
        if (empty($user_id) || !is_object($ci)) {
            return '';
        }
        if (!$ci->db->table_exists('ai_knowledge_chunks') || !$ci->db->table_exists('ai_knowledge_sources')) {
            return '';
        }

        $user_id = (int) $user_id;
        $page_id = (int) $page_id;
        // Request-level cache: the price guard calls this on every priced reply, right
        // after RAG already read the same chunks. Memoise per (user,page) for this request.
        static $cache = array();
        $ck = $user_id . ':' . $page_id;
        if (array_key_exists($ck, $cache)) return $cache[$ck];
        $scope = $page_id > 0 ? "s.page_id = {$page_id}" : "(s.page_id IS NULL OR s.page_id = 0)";

        $sql = "SELECT c.chunk_text
                FROM ai_knowledge_chunks c
                INNER JOIN ai_knowledge_sources s ON s.id = c.source_id
                WHERE s.user_id = {$user_id} AND {$scope} AND s.status = 'active'
                ORDER BY c.source_id ASC, c.chunk_order ASC";

        $rows = $ci->db->query($sql)->result_array();
        if (empty($rows) && $page_id > 0) {
            // Page has no sources of its own; fall back to user-level ones.
            return $cache[$ck] = ai_get_full_knowledge($user_id, 0);
        }

        $text = '';
        foreach ($rows as $row) {
            $text .= $row['chunk_text'] . "\n\n";
            if (mb_strlen($text, 'UTF-8') > AI_GUARD_MAX_SOURCE_CHARS) {
                break;
            }
        }
        return $cache[$ck] = trim($text);
    }
}

if (!function_exists('ai_verify_price_grounding')) {
    /**
     * Audit a reply's prices against the authoritative source text.
     *
     * Fails OPEN: any error, timeout, missing key or missing source returns
     * 'unknown', and the caller must send the reply unchanged. A judge outage
     * must never silence the bot.
     *
     * @param int    $user_id  Owner user ID (supplies the API key).
     * @param string $question The customer's message.
     * @param string $reply    The assistant's proposed reply.
     * @param string $source   Campaign instructions + full knowledge base.
     * @return string 'grounded' | 'ungrounded' | 'unknown'
     */
    /**
     * $context: the last few turns, "العميل: ...\nالبوت: ..." per line.
     *
     * Without it the judge sees one bare question and false-blocks every follow-up in a
     * multi-turn sale: the customer answers "الغرفة" to "تحب أنهي واحدة فيهم؟", the judge
     * has no idea which room that refers to, finds a same-named item at a different price
     * elsewhere in the source, and rules UNGROUNDED — the correct answer never ships and
     * the customer gets "I'll check with the team" for a price we just quoted. Measured on
     * the live Byoot profile: 3/6 without context, 6/6 with it, and the true blocks
     * (invented totals, accepting the customer's number) still block.
     */
    function ai_verify_price_grounding($user_id = 0, $question = '', $reply = '', $source = '', $context = '')
    {
        $source = trim((string) $source);
        if ($source === '' || trim((string) $reply) === '') {
            return 'unknown';
        }

        // Deterministic gate first — no model call, no ambiguity. A headcount
        // claim the source cannot support is always wrong, and the judge misses
        // it when the sentence also says "starts from".
        if (ai_reply_ties_price_to_headcount($reply) && !ai_source_states_per_person_pricing($source)) {
            log_message('error', 'SPEC21 price guard blocked a reply: headcount claim, source has no per-person pricing');
            return 'ungrounded';
        }

        $api_key = ai_openai_key($user_id);
        if ($api_key === '') {
            return 'unknown';
        }

        $system = "You audit a sales bot's reply for price grounding. The SOURCE below is the complete, authoritative price list.\n"
            . "Check every price the reply states. A price is GROUNDED only if the SOURCE attaches that exact number to the exact item the reply attaches it to.\n"
            . "- Use CONVERSATION SO FAR to resolve what the customer is referring to. A one-word follow-up like 'الغرفة' means whatever the bot just offered; judge the reply against THAT item, not against a similarly-named item elsewhere in the SOURCE.\n"
            . "Rules:\n"
            . "- 'starts from X' / 'تبدأ من X' is GROUNDED if the SOURCE says that item starts from X, or if X is the lowest price the SOURCE lists for that category.\n"
            . "- Restating a unit the SOURCE itself gives (the SOURCE says 'From EGP 4,502/night', the reply says 'لليلة') is GROUNDED.\n"
            . "- A number that belongs to a DIFFERENT item is UNGROUNDED, even if the number appears in the SOURCE.\n"
            . "- A number the customer proposed is UNGROUNDED unless the SOURCE independently confirms it for that item.\n"
            // Do NOT relax this into "check the math". Tried and measured: gpt-4o-mini
            // cannot verify arithmetic — asked to audit "4500 x 2 nights = 9,000" it
            // answered "UNGROUNDED: the total is incorrect". It scored 4/8 that way and
            // rejected every correct total. Arithmetic has a right answer, so it must not
            // depend on a probabilistic judge. The bot doesn't compute either: totals for
            // the quantities customers actually ask for are WRITTEN OUT in the prompt, so
            // the reply quotes a number the SOURCE states and this rule passes it.
            . "- ARITHMETIC: the bot may not multiply, add, or total. A computed figure (3 nights x the nightly rate) is UNGROUNDED unless the SOURCE states that total.\n"
            . "Reply with exactly one word, GROUNDED or UNGROUNDED, then ': ' and a short reason.";

        $user = "SOURCE:\n" . $source;
        $context = trim((string) $context);
        if ($context !== '') $user .= "\n\nCONVERSATION SO FAR:\n" . $context;
        $user .= "\n\nCUSTOMER ASKED: " . $question . "\n\nBOT REPLY: " . $reply;

        $payload = json_encode(array(
            'model' => AI_GUARD_MODEL,
            'temperature' => 0,
            'max_tokens' => 60,
            'messages' => array(
                array('role' => 'system', 'content' => $system),
                array('role' => 'user', 'content' => $user),
            ),
        ), JSON_UNESCAPED_UNICODE);

        $ch = curl_init();
        curl_setopt_array($ch, array(
            CURLOPT_URL => 'https://api.openai.com/v1/chat/completions',
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            // 8s: this is a ~60-token GROUNDED/UNGROUNDED classification. It fails open, so
            // a long wait buys nothing — it just stalls the customer's reply. (was 25s)
            CURLOPT_TIMEOUT => 8,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER => array(
                'Content-Type: application/json',
                'Authorization: Bearer ' . $api_key,
            ),
        ));
        $response = curl_exec($ch);
        $http_code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http_code !== 200 || $response === false) {
            log_message('error', 'ai_verify_price_grounding: http=' . $http_code);
            return 'unknown';
        }

        $decoded = json_decode($response, true);
        $verdict = isset($decoded['choices'][0]['message']['content'])
            ? trim($decoded['choices'][0]['message']['content'])
            : '';
        if ($verdict === '') {
            return 'unknown';
        }

        // "UNGROUNDED" contains "GROUNDED", so test the negative first.
        if (stripos($verdict, 'UNGROUNDED') === 0) {
            log_message('error', 'SPEC21 price guard blocked a reply: ' . mb_substr($verdict, 0, 160));
            return 'ungrounded';
        }
        if (stripos($verdict, 'GROUNDED') === 0) {
            return 'grounded';
        }
        return 'unknown';
    }
}

if (!function_exists('ai_price_deflection_text')) {
    /**
     * Replacement reply when a quoted price cannot be verified.
     *
     * Mirrors the customer's script, matching the bot's language rule.
     *
     * @param string $human The customer's message.
     * @return string
     */
    function ai_price_deflection_text($human = '')
    {
        $is_arabic = (bool) preg_match('/\p{Arabic}/u', (string) $human);
        return $is_arabic
            ? 'خليني أتأكد لك من السعر ده بالظبط من الفريق 👍 ممكن رقم حضرتك أو الواتساب؟'
            : "Let me confirm that exact price with the team. Could you share your phone or WhatsApp number?";
    }
}

if (!function_exists('ai_delete_knowledge_chunks')) {
    /**
     * Delete all chunks for a given knowledge source.
     *
     * @param int $source_id Knowledge source ID.
     * @return bool
     */
    function ai_delete_knowledge_chunks($source_id = 0)
    {
        $ci = &get_instance();
        if (empty($source_id) || !$ci->db->table_exists('ai_knowledge_chunks')) {
            return false;
        }
        $ci->db->where('source_id', (int) $source_id);
        $ci->db->delete('ai_knowledge_chunks');
        return true;
    }
}
