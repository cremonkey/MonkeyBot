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
                        foreach ($dom->find('script,style,noscript,nav,footer,header,aside') as $node) {
                            $node->outertext = '';
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
        foreach ($xpath->query('//script|//style|//noscript|//nav|//footer|//header|//aside') as $node) {
            $node->parentNode->removeChild($node);
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
     * Split text into overlapping chunks.
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

        $chunks = array();
        $length = mb_strlen($text, 'UTF-8');
        $step = max(1, $chunk_size - $overlap);

        for ($start = 0; $start < $length; $start += $step) {
            $chunk = mb_substr($text, $start, $chunk_size, 'UTF-8');
            if (empty($chunk)) {
                break;
            }
            $chunks[] = ai_normalize_text($chunk);
            if (mb_strlen($chunk, 'UTF-8') < $chunk_size) {
                break;
            }
        }

        return $chunks;
    }
}

if (!function_exists('ai_get_knowledge_context')) {
    /**
     * Retrieve relevant knowledge-base chunks for a user message.
     *
     * If $page_id is provided, page-specific active sources are searched first.
     * If no page-specific chunks are found (or no page_id is given), user-level
     * active sources are searched.
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

        // Ensure tables exist.
        if (!$ci->db->table_exists('ai_knowledge_sources') || !$ci->db->table_exists('ai_knowledge_chunks')) {
            return '';
        }

        // Build a boolean search string from significant words.
        $words = preg_split('/\s+/u', trim($query), -1, PREG_SPLIT_NO_EMPTY);
        $words = array_filter($words, function ($w) {
            return mb_strlen($w, 'UTF-8') > 1;
        });
        if (empty($words)) {
            return '';
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
            return '';
        }

        $against = $ci->db->escape(implode(' ', $search_terms));
        $user_id = (int) $user_id;
        $page_id = (int) $page_id;
        $limit = (int) $limit;

        // If a page is provided, try page-scoped sources first.
        if ($page_id > 0) {
            $sql = "SELECT c.chunk_text,
                        MATCH(c.chunk_text) AGAINST (" . $against . " IN BOOLEAN MODE) AS score
                    FROM ai_knowledge_chunks c
                    INNER JOIN ai_knowledge_sources s ON s.id = c.source_id
                    WHERE s.user_id = {$user_id}
                      AND s.page_id = {$page_id}
                      AND s.status = 'active'
                      AND MATCH(c.chunk_text) AGAINST (" . $against . " IN BOOLEAN MODE)
                    ORDER BY score DESC
                    LIMIT {$limit}";
            $rows = $ci->db->query($sql)->result_array();
            if (!empty($rows)) {
                return ai_build_knowledge_context($rows);
            }
        }

        // Fall back to user-level sources (page_id IS NULL or 0).
        $sql = "SELECT c.chunk_text,
                    MATCH(c.chunk_text) AGAINST (" . $against . " IN BOOLEAN MODE) AS score
                FROM ai_knowledge_chunks c
                INNER JOIN ai_knowledge_sources s ON s.id = c.source_id
                WHERE s.user_id = {$user_id}
                  AND (s.page_id IS NULL OR s.page_id = 0)
                  AND s.status = 'active'
                  AND MATCH(c.chunk_text) AGAINST (" . $against . " IN BOOLEAN MODE)
                ORDER BY score DESC
                LIMIT {$limit}";

        $rows = $ci->db->query($sql)->result_array();
        return ai_build_knowledge_context($rows);
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
