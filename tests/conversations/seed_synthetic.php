<?php
/**
 * Synthetic seed for the conversation-core tests. SYNTHETIC DATA ONLY - never point this
 * at a database that holds real tenants. Deterministic (fixed RNG seed), so two runs
 * produce the same rows.
 *
 * Prints SQL on stdout. Load it into a scratch DB that already has initial_db.sql and the
 * migrations up to (not including) 2026-09-23-conversation-core.sql, e.g.
 *   php tests/conversations/seed_synthetic.php | mariadb --default-character-set=utf8mb4 mb_conv_test
 *
 * Shape:
 *  - 3 tenants (users 9101, 9102, 9103), all five platforms fb/ig/wa/tg/web
 *  - FB pages (IG shares the page row), OpenWA + WhatsApp Cloud + Telegram accounts
 *  - tenant 9103 owns OpenWA account 9202 AND WhatsApp Cloud account 9202 (the
 *    account-id collision the backfill report must flag)
 *  - the same PSID on two pages of one tenant and on a page of another tenant
 *  - subscriber rows for most fb/ig/openwa threads, some tg, none for web / WA Cloud
 *  - some subscribers paused into the future, some with an expired pause
 *  - FB bot rows logged the channel_log_message way (page_table_id 0 + fb_page_id),
 *    system rows with user_id NULL (Home::system_message_insert_into_conversation)
 *  - unlinkable rows: empty subscriber_id, and user_id NULL with no page to resolve
 *  - Arabic, emoji (4-byte), mixed and long texts
 */

mt_srand(20260923);

$out = array();
$q = function ($v) {
    if ($v === null) return 'NULL';
    if (is_int($v)) return (string) $v;
    return "'" . str_replace(array('\\', "'"), array('\\\\', "\\'"), $v) . "'";
};

$out[] = "SET NAMES utf8mb4;";
$out[] = "SET SESSION sql_mode = '';";

// tenants
foreach (array(9101, 9102, 9103) as $uid) {
    $out[] = "INSERT INTO users (id, name, email, user_type, status, deleted) VALUES ($uid, 'Synthetic tenant $uid', 'tenant$uid@example.invalid', 'Member', '1', '0');";
}

// channel accounts
$pages = array( // page_table_id => [user_id, fb page id]
    9001 => array(9101, 'P9001'),
    9002 => array(9101, 'P9002'),
    9003 => array(9102, 'P9003'),
    9004 => array(9103, 'P9004'),
);
foreach ($pages as $id => $p) {
    $out[] = "INSERT INTO facebook_rx_fb_page_info (id, user_id, page_id, page_name) VALUES ($id, {$p[0]}, '{$p[1]}', 'Synthetic page $id');";
}
$openwa = array(9201 => 9101, 9202 => 9103);
foreach ($openwa as $id => $uid) {
    $out[] = "INSERT INTO openwa_accounts (id, user_id, api_key, session_id) VALUES ($id, $uid, 'synthetic', 'sess-$id');";
}
$wacloud = array(9201 => 9102, 9202 => 9103);
foreach ($wacloud as $id => $uid) {
    $out[] = "INSERT INTO whatsapp_accounts (id, user_id, phone_number_id, access_token, verify_token) VALUES ($id, $uid, 'pn-$id', 'synthetic', 'vt-$id');";
}
$tg = array(9301 => 9101, 9302 => 9102);
foreach ($tg as $id => $uid) {
    $out[] = "INSERT INTO telegram_accounts (id, user_id, bot_token, webhook_secret) VALUES ($id, $uid, 'synthetic-token-$id', 'ws-$id');";
}

$ar = array(
    'السلام عليكم، عايز أعرف سعر الغرفة المزدوجة',
    'تمام، هل في عرض لنهاية الأسبوع؟',
    'مُمْتَاز جِدًّا، شكراً لحضرتك',
    'ممكن أحجز يوم الخميس الساعة ٧ مساءً؟',
    'الأسعار: ١٢٠٠ جنيه لليلة شاملة الإفطار',
);
$emoji = array('👍', '😀🔥', 'تمام 👌', 'Hello 👋 مرحبا', '❤️‍🔥 شكراً', '🇪🇬🇪🇬', 'ok');
$en = array('Hi, is this available?', 'Price please', 'Thanks!', 'Can I talk to a human?', 'Where are you located?');
$text = function () use ($ar, $emoji, $en) {
    $r = mt_rand(1, 100);
    if ($r <= 45) return $ar[mt_rand(0, count($ar) - 1)];
    if ($r <= 70) return $emoji[mt_rand(0, count($emoji) - 1)];
    if ($r <= 97) return $en[mt_rand(0, count($en) - 1)];
    return str_repeat('نص طويل للاختبار 🚀 ', 150); // ~3k chars
};

// threads: [user_id, platform, account id (page_table_id), fb page id, external id, subscriber?]
$threads = array();
$ext_n = 0;
$add = function ($uid, $platform, $acct, $fbpid, $ext, $sub_prob) use (&$threads) {
    $threads[] = array($uid, $platform, $acct, $fbpid, $ext, mt_rand(1, 100) <= $sub_prob);
};
for ($i = 0; $i < 70; $i++) { $add(9101, 'fb', 9001, 'P9001', 'PSID' . (++$ext_n), 85); }
for ($i = 0; $i < 40; $i++) { $add(9101, 'fb', 9002, 'P9002', 'PSID' . (++$ext_n), 85); }
for ($i = 0; $i < 30; $i++) { $add(9101, 'ig', 9001, 'P9001', 'IGSID' . (++$ext_n), 85); }
for ($i = 0; $i < 25; $i++) { $add(9101, 'wa', 9201, '', '2010' . str_pad((string) (++$ext_n), 7, '0', STR_PAD_LEFT), 90); }
for ($i = 0; $i < 20; $i++) { $add(9101, 'tg', 9301, '', (string) (700000 + (++$ext_n)), 30); }
for ($i = 0; $i < 20; $i++) { $add(9101, 'web', 0, '', 'ws_' . substr(md5('w' . (++$ext_n)), 0, 20), 0); }
for ($i = 0; $i < 50; $i++) { $add(9102, 'fb', 9003, 'P9003', 'PSID' . (++$ext_n), 85); }
for ($i = 0; $i < 20; $i++) { $add(9102, 'ig', 9003, 'P9003', 'IGSID' . (++$ext_n), 85); }
for ($i = 0; $i < 20; $i++) { $add(9102, 'wa', 9201, '', '2011' . str_pad((string) (++$ext_n), 7, '0', STR_PAD_LEFT), 0); } // WA Cloud: no subscriber rows
for ($i = 0; $i < 15; $i++) { $add(9102, 'tg', 9302, '', (string) (700000 + (++$ext_n)), 30); }
for ($i = 0; $i < 15; $i++) { $add(9102, 'web', 0, '', 'ws_' . substr(md5('w' . (++$ext_n)), 0, 20), 0); }
for ($i = 0; $i < 30; $i++) { $add(9103, 'fb', 9004, 'P9004', 'PSID' . (++$ext_n), 85); }
for ($i = 0; $i < 15; $i++) { $add(9103, 'wa', 9202, '', '2012' . str_pad((string) (++$ext_n), 7, '0', STR_PAD_LEFT), 90); }
for ($i = 0; $i < 10; $i++) { $add(9103, 'web', 0, '', 'ws_' . substr(md5('w' . (++$ext_n)), 0, 20), 0); }
// the same PSID on two pages of tenant 9101 and on a page of tenant 9102: three conversations
$add(9101, 'fb', 9001, 'P9001', 'PSID-SHARED', 100);
$add(9101, 'fb', 9002, 'P9002', 'PSID-SHARED', 100);
$add(9102, 'fb', 9003, 'P9003', 'PSID-SHARED', 100);

$now = time();
$sub_n = 0;
foreach ($threads as $t) {
    list($uid, $platform, $acct, $fbpid, $ext, $has_sub) = $t;
    if (!$has_sub) continue;
    $sub_n++;
    $r = mt_rand(1, 100);
    $paused = null;
    if ($r <= 10) $paused = date('Y-m-d H:i:s', $now + mt_rand(600, 5 * 3600));      // active handoff
    elseif ($r <= 15) $paused = date('Y-m-d H:i:s', $now - mt_rand(600, 72 * 3600)); // expired
    $page_id = $platform === 'wa' ? 'sess-' . $acct : ($platform === 'tg' ? 'tg-' . $acct : $fbpid);
    $name = mt_rand(0, 1) ? 'عميل تجريبي ' . $sub_n : 'Synthetic Customer ' . $sub_n;
    $phone = $platform === 'wa' ? $ext : (mt_rand(1, 100) <= 30 ? '+2010' . mt_rand(1000000, 9999999) : '');
    $email = mt_rand(1, 100) <= 20 ? "c{$sub_n}@example.invalid" : '';
    $out[] = sprintf(
        "INSERT INTO messenger_bot_subscriber (user_id, page_table_id, page_id, subscribe_id, first_name, last_name, full_name, social_media, phone_number, email, lead_score, bot_paused_until, assigned_used_id, subscribed_at) VALUES (%d, %d, %s, %s, %s, '', %s, %s, %s, %s, %d, %s, %s, %s);",
        $uid, $acct, $q($page_id), $q($ext), $q($name), $q($name), $q($platform), $q($phone), $q($email),
        mt_rand(0, 90), $q($paused), $paused ? (string) mt_rand(1, 5) : 'NULL', $q(date('Y-m-d H:i:s', $now - 90 * 86400))
    );
}

// messages
$rows = array();
$msg_n = 0;
foreach ($threads as $t) {
    list($uid, $platform, $acct, $fbpid, $ext) = $t;
    $n = mt_rand(3, 30);
    $ts = $now - mt_rand(2, 100) * 86400;
    for ($i = 0; $i < $n; $i++) {
        $ts += mt_rand(20, 4000);
        $r = mt_rand(1, 100);
        $sender = $r <= 55 ? 'user' : ($r <= 95 ? 'bot' : 'system');
        $row_uid = $uid; $row_acct = $acct; $row_fbpid = $fbpid;
        if (in_array($platform, array('fb', 'ig'), true)) {
            if ($sender === 'bot' && mt_rand(0, 1)) { $row_acct = 0; }            // channel_log_message style
            if ($sender === 'system') { $row_uid = null; $row_fbpid = null; }    // Home system message, no user_id
        } else {
            $row_fbpid = '';
            if ($sender === 'system') $sender = 'bot';
        }
        $rows[] = sprintf("(%s, %s, %s, %s, %s, %s, %s, %s, %s)",
            $q($row_uid), $q($ext), $q($row_acct), $q($row_fbpid), $q($sender), $q($platform),
            $q($sender === 'bot' ? 'Bot' : ''), $q($text()), $q(date('Y-m-d H:i:s', $ts)));
        $msg_n++;
    }
}
// unlinkable rows
for ($i = 0; $i < 20; $i++) {
    $rows[] = sprintf("(9101, '', 9001, 'P9001', 'user', 'fb', '', %s, %s)", $q($text()), $q(date('Y-m-d H:i:s', $now - 3600)));
}
for ($i = 0; $i < 5; $i++) {
    $rows[] = sprintf("(NULL, 'PSID-NOTENANT', 0, '', 'system', 'fb', 'Bot', %s, %s)", $q($text()), $q(date('Y-m-d H:i:s', $now - 3600)));
}
shuffle($rows); // interleave threads like real traffic
foreach (array_chunk($rows, 500) as $chunk) {
    $out[] = "INSERT INTO livechat_messages (user_id, subscriber_id, page_table_id, fb_page_id, sender, platform, agent_name, message_content, conversation_time) VALUES\n" . implode(",\n", $chunk) . ";";
}

echo implode("\n", $out), "\n";
fwrite(STDERR, sprintf("seed: %d threads, %d subscribers, %d messages (+25 unlinkable)\n", count($threads), $sub_n, $msg_n));
