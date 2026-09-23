<?php
/**
 * SPEC-20 backfill: generate embeddings for knowledge chunks that lack one.
 *
 * Idempotent — skips chunks already embedded with the current model, so it is
 * safe to re-run after a partial failure or a rate limit.
 *
 * Runs outside CodeIgniter (no bootstrap, no routable controller). It calls the
 * real ai_embed_texts()/ai_pack_vector() from the helper so the stored vectors
 * are byte-identical to what the request path produces.
 *
 * Usage:
 *   docker exec monkeybot-app-1 php /var/www/html/docker/scripts/backfill_kb_embeddings.php [--dry-run]
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

define('BASEPATH', true);
if (!function_exists('log_message')) {
    function log_message($level, $message)
    {
        fwrite(STDERR, "[{$level}] {$message}\n");
    }
}

$app_path = '/var/www/html/application';
require $app_path . '/helpers/ai_knowledge_helper.php';
// ai_embed_texts() records usage via ai_usage_helper (baseline item 17). Outside CodeIgniter
// it would call get_instance() to load it; load it here and give it the tiny db shim below.
require $app_path . '/helpers/ai_usage_helper.php';

$dry_run = in_array('--dry-run', $argv, true);

// --- DB connection, read from the live CodeIgniter config ------------------
$config_src = @file_get_contents($app_path . '/config/database.php');
if ($config_src === false) {
    exit("Cannot read database.php\n");
}
$cfg = array();
foreach (array('hostname', 'username', 'password', 'database') as $key) {
    if (preg_match("/\\\$db\\['default'\\]\\['{$key}'\\]\s*=\s*'([^']*)'/", $config_src, $m)) {
        $cfg[$key] = $m[1];
    }
}
if (count($cfg) !== 4) {
    exit("Could not parse DB credentials from database.php\n");
}

$mysqli = @new mysqli($cfg['hostname'], $cfg['username'], $cfg['password'], $cfg['database']);
if ($mysqli->connect_errno) {
    exit("DB connect failed: {$mysqli->connect_error}\n");
}
$mysqli->set_charset('utf8mb4');

/** Just the query-builder calls ai_usage_record()/ai_usage_price() make, over mysqli. */
final class BackfillUsageDb
{
    private $m; private $w = array(); private $t = '';
    public function __construct($m) { $this->m = $m; }
    public function table_exists($t) { $r = $this->m->query("SHOW TABLES LIKE '" . $this->m->real_escape_string($t) . "'"); return $r && $r->num_rows > 0; }
    public function field_exists($f, $t) { $r = $this->m->query('SHOW COLUMNS FROM `' . str_replace('`', '', $t) . "` LIKE '" . $this->m->real_escape_string($f) . "'"); return $r && $r->num_rows > 0; }
    public function from($t) { $this->t = $t; $this->w = array(); return $this; }
    public function where($k, $v) { $this->w[] = '`' . str_replace('`', '', $k) . "` = '" . $this->m->real_escape_string((string) $v) . "'"; return $this; }
    public function limit($n) { return $this; }
    public function get() { $r = $this->m->query('SELECT * FROM `' . str_replace('`', '', $this->t) . '`' . ($this->w ? ' WHERE ' . implode(' AND ', $this->w) : '') . ' LIMIT 1'); $this->w = array(); return new BackfillUsageRow($r ? $r->fetch_assoc() : null); }
    public function insert($t, $row) {
        $cols = array(); $vals = array();
        foreach ($row as $k => $v) { $cols[] = '`' . str_replace('`', '', $k) . '`'; $vals[] = "'" . $this->m->real_escape_string((string) $v) . "'"; }
        return $this->m->query('INSERT INTO `' . str_replace('`', '', $t) . '` (' . implode(',', $cols) . ') VALUES (' . implode(',', $vals) . ')');
    }
}
final class BackfillUsageRow { private $r; public function __construct($r) { $this->r = $r; } public function row_array() { return $this->r; } }
if (!function_exists('get_instance')) {
    $GLOBALS['__backfill_ci'] = (object) array('db' => new BackfillUsageDb($mysqli));
    function &get_instance() { return $GLOBALS['__backfill_ci']; }
}

// --- Group un-embedded chunks by owning user (the API key is per user) -----
$sql = "SELECT c.id, c.chunk_text, s.user_id
        FROM ai_knowledge_chunks c
        INNER JOIN ai_knowledge_sources s ON s.id = c.source_id
        WHERE c.embedding IS NULL OR c.embedding_model <> '" . $mysqli->real_escape_string(AI_EMBEDDING_MODEL) . "'
        ORDER BY s.user_id, c.id";
$res = $mysqli->query($sql);
if ($res === false) {
    exit("Query failed: {$mysqli->error}\n");
}

$by_user = array();
while ($row = $res->fetch_assoc()) {
    $by_user[(int) $row['user_id']][] = $row;
}

$total = array_sum(array_map('count', $by_user));
echo "Chunks needing embedding: {$total}\n";
if ($total === 0) {
    echo "Nothing to do.\n";
    exit(0);
}
if ($dry_run) {
    foreach ($by_user as $uid => $rows) {
        echo "  user {$uid}: " . count($rows) . " chunks\n";
    }
    echo "Dry run — no API calls made.\n";
    exit(0);
}

$embedded = 0;
$failed = 0;

foreach ($by_user as $user_id => $rows) {
    // Fetch this user's OpenAI key directly; ai_openai_key() needs CodeIgniter.
    $stmt = $mysqli->prepare('SELECT open_ai_secret_key FROM open_ai_config WHERE user_id = ? LIMIT 1');
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $key_row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $api_key = isset($key_row['open_ai_secret_key']) ? trim($key_row['open_ai_secret_key']) : '';
    if (strpos($api_key, 'enc::') === 0) {
        // Stored encrypted (baseline S-04). Decrypting needs CodeIgniter's Encryption library and
        // the live encryption_key, which this bootstrap-free script does not load; sending the
        // ciphertext to OpenAI would only fail. Skip loudly instead.
        echo "  user {$user_id}: OpenAI key is stored encrypted (enc::) - this script cannot decrypt it, skipping " . count($rows) . " chunks\n";
        $failed += count($rows);
        continue;
    }
    if ($api_key === '') {
        echo "  user {$user_id}: no OpenAI key, skipping " . count($rows) . " chunks\n";
        $failed += count($rows);
        continue;
    }

    foreach (array_chunk($rows, 96) as $batch) {
        $texts = array_column($batch, 'chunk_text');
        $vectors = ai_embed_texts($texts, $user_id, $api_key);

        if (!is_array($vectors)) {
            echo "  user {$user_id}: batch of " . count($batch) . " failed\n";
            $failed += count($batch);
            continue;
        }

        $update = $mysqli->prepare('UPDATE ai_knowledge_chunks SET embedding = ?, embedding_model = ? WHERE id = ?');
        foreach ($batch as $i => $row) {
            if (!isset($vectors[$i]) || !is_array($vectors[$i]) || empty($vectors[$i])) {
                $failed++;
                continue;
            }
            $blob = ai_pack_vector($vectors[$i]);
            $model = AI_EMBEDDING_MODEL;
            $chunk_id = (int) $row['id'];

            $null = null;
            $update->bind_param('bsi', $null, $model, $chunk_id);
            $update->send_long_data(0, $blob);
            if ($update->execute()) {
                $embedded++;
            } else {
                $failed++;
            }
        }
        $update->close();
        echo "  user {$user_id}: batch done ({$embedded} embedded so far)\n";
    }
}

echo "\nEmbedded: {$embedded}   Failed: {$failed}\n";

$check = $mysqli->query("SELECT COUNT(*) AS n FROM ai_knowledge_chunks WHERE embedding IS NOT NULL")->fetch_assoc();
echo "Chunks with an embedding: {$check['n']}\n";

$mysqli->close();
exit($failed > 0 ? 1 : 0);
