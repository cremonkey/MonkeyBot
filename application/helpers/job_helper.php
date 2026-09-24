<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/*
 | Generic job queue (remaining-work Task 11 / B3, brief §42).
 |
 | A `jobs` row is "do <type> with <payload_json> for <user_id>, no sooner than <run_after>".
 | `job_enqueue()` writes one. `Job_worker.php` (CLI only) calls `job_claim()` in a loop, runs
 | the handler registered for the job's `type` (see `job_handlers()` below), and reports the
 | outcome back with `job_complete()` / `job_retry_or_dead_letter()`.
 |
 | CLAIMING is two steps because MariaDB 10.11 has no UPDATE ... ORDER BY ... LIMIT that also
 | returns the claimed rows:
 |   1. UPDATE jobs SET status='running', locked_by=<token>, locked_at=NOW(), attempts=attempts+1
 |        WHERE status='queued' AND run_after<=NOW() ORDER BY run_after ASC LIMIT <n>
 |   2. SELECT * FROM jobs WHERE locked_by=<token>
 | The token is unique per claim call (uniqid with more entropy), so step 2 only ever sees the
 | rows this exact call just claimed, even with several workers running the same tick.
 |
 | FEATURE FLAG (brief 50 style): whether anything gets ENQUEUED is decided by each caller
 | checking config item 'jobs_enabled' itself (this helper does not gate that — see
 | Ai_knowledge_base.php and Inbox.php for the flag-off = today's inline behaviour path).
 | job_enqueue()/job_claim() themselves never check the flag: once a row exists it should be
 | processed, and the worker itself no-ops when the table is missing (pre-migration safety).
 */

if (!function_exists('job_enqueue')) {
    /**
     * @param int    $user_id
     * @param string $type         registry key, see job_handlers()
     * @param array  $payload
     * @param array  $opts         run_after (datetime string|int timestamp), max_attempts (int)
     * @return int inserted job id, or 0 if the job could not be stored (never throws)
     */
    function job_enqueue($user_id, $type, array $payload = array(), array $opts = array())
    {
        try {
            $user_id = (int) $user_id;
            $type = trim((string) $type);
            if ($user_id <= 0 || $type === '') return 0;

            $CI =& get_instance();
            if (!isset($CI->db) || !$CI->db->table_exists('jobs')) return 0;

            $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
            if ($json === false) $json = '{}';

            $run_after = isset($opts['run_after']) ? $opts['run_after'] : date('Y-m-d H:i:s');
            if (is_int($run_after)) $run_after = date('Y-m-d H:i:s', $run_after);

            $max_attempts = isset($opts['max_attempts']) ? max(1, (int) $opts['max_attempts']) : 5;
            $now = date('Y-m-d H:i:s');

            $CI->db->insert('jobs', array(
                'user_id'      => $user_id,
                'type'         => mb_substr($type, 0, 64),
                'payload_json' => $json,
                'status'       => 'queued',
                'attempts'     => 0,
                'max_attempts' => $max_attempts,
                'run_after'    => $run_after,
                'locked_at'    => null,
                'locked_by'    => null,
                'last_error'   => null,
                'created_at'   => $now,
                'updated_at'   => $now,
            ));
            return (int) $CI->db->insert_id();
        } catch (Throwable $e) {
            log_message('error', 'job_enqueue(' . $type . '): ' . $e->getMessage());
            return 0;
        }
    }
}

if (!function_exists('job_claim')) {
    /**
     * Atomically claim up to $limit queued, due jobs for this worker.
     *
     * @param int    $limit
     * @param string $worker_token unique per claim call; caller may pass one to make the
     *                             claim deterministic in tests, otherwise one is generated
     * @return array list of claimed job rows (payload_json left as raw JSON string)
     */
    function job_claim($limit = 10, $worker_token = null)
    {
        $CI =& get_instance();
        if (!isset($CI->db) || !$CI->db->table_exists('jobs')) return array();

        $limit = max(1, min(200, (int) $limit));
        $token = $worker_token !== null ? $worker_token : uniqid('job_', true);
        $now = date('Y-m-d H:i:s');

        $CI->db->query(
            "UPDATE jobs SET status='running', locked_by=?, locked_at=?, attempts=attempts+1, updated_at=?"
            . " WHERE status='queued' AND run_after<=? ORDER BY run_after ASC, id ASC LIMIT " . $limit,
            array($token, $now, $now, $now)
        );

        return $CI->db->from('jobs')->where('locked_by', $token)->where('status', 'running')
            ->order_by('id', 'ASC')->get()->result_array();
    }
}

if (!function_exists('job_complete')) {
    /** Mark a claimed job done. */
    function job_complete($job_id)
    {
        $CI =& get_instance();
        if (!isset($CI->db) || !$CI->db->table_exists('jobs')) return;
        $CI->db->where('id', (int) $job_id)->update('jobs', array(
            'status' => 'done', 'locked_by' => null, 'locked_at' => null,
            'last_error' => null, 'updated_at' => date('Y-m-d H:i:s'),
        ));
    }
}

if (!function_exists('job_retry_or_dead_letter')) {
    /**
     * A claimed job's handler failed. Re-queue with exponential backoff
     * (2^attempts minutes, capped at 60) unless attempts has reached max_attempts,
     * in which case the job is dead-lettered (status='dead') and stays for inspection.
     *
     * @param array  $job   the claimed row (needs id, attempts, max_attempts)
     * @param string $error
     * @return string 'retry' or 'dead'
     */
    function job_retry_or_dead_letter(array $job, $error)
    {
        $CI =& get_instance();
        $attempts = (int) $job['attempts'];
        $max_attempts = (int) $job['max_attempts'];
        $error = mb_substr((string) $error, 0, 2000);

        if ($attempts >= $max_attempts) {
            if (isset($CI->db) && $CI->db->table_exists('jobs')) {
                $CI->db->where('id', (int) $job['id'])->update('jobs', array(
                    'status' => 'dead', 'locked_by' => null, 'locked_at' => null,
                    'last_error' => $error, 'updated_at' => date('Y-m-d H:i:s'),
                ));
            }
            return 'dead';
        }

        $backoff_minutes = min(60, (int) pow(2, $attempts));
        $run_after = date('Y-m-d H:i:s', time() + $backoff_minutes * 60);
        if (isset($CI->db) && $CI->db->table_exists('jobs')) {
            $CI->db->where('id', (int) $job['id'])->update('jobs', array(
                'status' => 'queued', 'locked_by' => null, 'locked_at' => null,
                'run_after' => $run_after, 'last_error' => $error, 'updated_at' => date('Y-m-d H:i:s'),
            ));
        }
        return 'retry';
    }
}

if (!function_exists('job_payload')) {
    /** Decode a jobs row's payload_json to an array (never null). */
    function job_payload($row)
    {
        $raw = is_array($row) ? (isset($row['payload_json']) ? $row['payload_json'] : '') : $row;
        $d = json_decode((string) $raw, true);
        return is_array($d) ? $d : array();
    }
}

if (!function_exists('job_handlers')) {
    /**
     * type => callable($payload array, $job array): void. Throw to fail the job (it will be
     * retried with backoff, then dead-lettered after max_attempts). Kept as a plain function
     * (not a DB-backed registry) so the set of job types is reviewable in one place.
     */
    function job_handlers()
    {
        return array(
            'kb_chunk_embed'       => 'job_handle_kb_chunk_embed',
            'conversation_summary' => 'job_handle_conversation_summary',
        );
    }
}

if (!function_exists('job_handle_kb_chunk_embed')) {
    /**
     * payload: source_id, user_id, text. Chunks the source text (same helper the inline path
     * used) and embeds the chunks. Mirrors the inline block that used to run synchronously in
     * Ai_knowledge_base::add_source_action() (see docs/... task-11 report).
     */
    function job_handle_kb_chunk_embed(array $payload, array $job)
    {
        $CI =& get_instance();
        $CI->load->helper('ai_knowledge');

        $source_id = (int) ($payload['source_id'] ?? 0);
        $user_id = (int) ($payload['user_id'] ?? 0);
        $text = (string) ($payload['text'] ?? '');
        if ($source_id <= 0 || $user_id <= 0) throw new Exception('kb_chunk_embed: missing source_id/user_id');

        $chunks = function_exists('ai_chunk_text_ai') ? ai_chunk_text_ai($text, $user_id) : ai_chunk_text($text, 1000, 200);
        $chunk_count = 0;
        if (!empty($chunks)) {
            $batch = array();
            foreach ($chunks as $index => $chunk) {
                $batch[] = array(
                    'source_id' => $source_id, 'chunk_text' => $chunk,
                    'chunk_order' => $index, 'created_at' => date('Y-m-d H:i:s'),
                );
                if (count($batch) >= 50) { $CI->db->insert_batch('ai_knowledge_chunks', $batch); $chunk_count += count($batch); $batch = array(); }
            }
            if (!empty($batch)) { $CI->db->insert_batch('ai_knowledge_chunks', $batch); $chunk_count += count($batch); }
        }

        $CI->db->where('id', $source_id)->where('user_id', $user_id)
            ->update('ai_knowledge_sources', array('total_chunks' => $chunk_count));

        if (function_exists('ai_embed_source_chunks')) ai_embed_source_chunks($source_id, $user_id);
    }
}

if (!function_exists('job_handle_conversation_summary')) {
    /**
     * payload: conversation_id, user_id. Regenerates a conversation's cached summary the same
     * way Inbox::summarize() does inline, but without an HTTP response to write to.
     */
    function job_handle_conversation_summary(array $payload, array $job)
    {
        $CI =& get_instance();
        $conversation_id = (int) ($payload['conversation_id'] ?? 0);
        $user_id = (int) ($payload['user_id'] ?? 0);
        if ($conversation_id <= 0 || $user_id <= 0) throw new Exception('conversation_summary: missing conversation_id/user_id');

        $CI->load->helper('conversation');
        $conv = conversation_get($conversation_id, $user_id);
        if (empty($conv)) return; // conversation gone/not this tenant's any more — nothing to do

        $ai_cfg = $CI->db->from('open_ai_config')->where('user_id', $user_id)->limit(1)->get()->row_array();
        if (empty($ai_cfg)) return; // same "nothing to generate with" case Inbox::summarize() handles

        $CI->db->select('id, sender, agent_name, message_content, conversation_time')
            ->from('livechat_messages')->where('conversation_id', $conv['id'])
            ->order_by('id', 'DESC')->limit(50);
        $messages = array_reverse($CI->db->get()->result_array());

        $CI->load->library('Conversation_summarizer');
        $result = $CI->conversation_summarizer->summarize($ai_cfg, $messages, $conv['id']);
        if (empty($result['ok'])) throw new Exception('conversation_summary: ' . ($result['message'] ?? 'summarizer failed'));

        $now = date('Y-m-d H:i:s');
        $CI->db->where('id', $conv['id'])->where('user_id', $user_id)
            ->update('conversations', array('last_summary' => $result['summary'], 'last_summary_at' => $now, 'updated_at' => $now));
    }
}
