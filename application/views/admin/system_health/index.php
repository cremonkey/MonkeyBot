<?php
$fmt_age = function ($s) {
  if ($s === null) return 'never';
  if ($s < 120) return $s . ' s ago';
  if ($s < 7200) return round($s / 60) . ' min ago';
  return round($s / 3600, 1) . ' h ago';
};
$cron = isset($jobs['cron_hub']) ? $jobs['cron_hub'] : array();
$healthy = $cron_age !== null && $cron_age <= 900;
?>
<section class="section">
  <div class="section-header"><h1><i class="fas fa-heartbeat"></i> <?php echo $page_title; ?></h1></div>
  <div class="section-body">

    <div class="card">
      <div class="card-header"><h4>Automation scheduler
        <span class="badge <?php echo $healthy ? 'badge-success' : 'badge-danger'; ?>"><?php echo $healthy ? 'Running' : 'Not running'; ?></span></h4></div>
      <div class="card-body">
        <p class="mb-2">Runs follow-ups, coupon reminders, daily digest, re-engagement, CRM push, missed-question alerts and data retention every 5 minutes.</p>
        <table class="table table-sm mb-0">
          <tr><th style="width:30%">Last successful run</th><td><?php echo $fmt_age($cron_age); ?> <small class="text-muted"><?php echo htmlspecialchars($cron['last_success_at'] ?? ''); ?></small></td></tr>
          <tr><th>Last duration</th><td><?php echo isset($cron['last_duration_s']) ? $cron['last_duration_s'] . ' s' : '—'; ?></td></tr>
          <tr><th>Runs / failures</th><td><?php echo (int)($cron['runs'] ?? 0); ?> / <?php echo (int)($cron['failures'] ?? 0); ?>
            <?php if (!empty($cron['consecutive_failures'])): ?><span class="badge badge-danger"><?php echo (int)$cron['consecutive_failures']; ?> in a row</span><?php endif; ?></td></tr>
          <?php if (!empty($cron['last_error'])): ?>
          <tr><th>Last error</th><td class="text-danger"><small><?php echo htmlspecialchars($cron['last_error']); ?></small> <small class="text-muted"><?php echo htmlspecialchars($cron['last_failure_at'] ?? ''); ?></small></td></tr>
          <?php endif; ?>
          <tr><th>Last result</th><td><pre class="mb-0" style="white-space:pre-wrap;font-size:12px"><?php echo htmlspecialchars(json_encode($cron['last_result'] ?? new stdClass, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); ?></pre></td></tr>
        </table>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><h4>Webhook authenticity</h4></div>
      <div class="card-body">
        <p class="mb-2">Counts since the check was added. Switch a check to <b>enforce</b> only when real traffic shows <b>valid</b> and no unexplained <b>invalid</b>/<b>missing</b>.</p>
        <?php if (empty($webhooks)): ?><p class="text-muted mb-0">No webhook traffic recorded yet.</p><?php else: ?>
        <table class="table table-sm mb-0"><thead><tr><th>Check</th><th>valid</th><th>invalid</th><th>missing</th><th>no secret</th><th>last invalid/missing</th></tr></thead><tbody>
        <?php foreach ($webhooks as $name => $c): ?>
          <tr><td><?php echo htmlspecialchars($name); ?></td>
            <td><?php echo (int)($c['valid'] ?? 0); ?></td><td><?php echo (int)($c['invalid'] ?? 0); ?></td>
            <td><?php echo (int)($c['missing'] ?? 0); ?></td><td><?php echo (int)($c['no_secret'] ?? 0); ?></td>
            <td><small class="text-muted"><?php echo htmlspecialchars(trim(($c['last_invalid'] ?? '') . ' ' . ($c['last_missing'] ?? ''))); ?></small></td></tr>
        <?php endforeach; ?></tbody></table>
        <?php endif; ?>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><h4>Security switches</h4></div>
      <div class="card-body p-0"><table class="table table-sm mb-0">
        <?php foreach ($switches as $k => $v): ?>
          <tr><th style="width:30%" class="pl-4"><?php echo htmlspecialchars($k); ?></th>
            <td><span class="badge <?php echo in_array($v, array('enforce', 'closed (CLI only)')) ? 'badge-success' : 'badge-warning'; ?>"><?php echo htmlspecialchars($v); ?></span></td></tr>
        <?php endforeach; ?>
      </table></div>
    </div>

  </div>
</section>
