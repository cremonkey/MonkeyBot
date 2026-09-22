<?php
$age = function ($s) { if ($s === null) return 'never'; if ($s < 120) return $s . ' s ago'; if ($s < 7200) return round($s / 60) . ' min ago'; return round($s / 3600, 1) . ' h ago'; };
$ago = function ($t) { if (!$t) return '—'; $s = time() - strtotime($t); if ($s < 3600) return round($s / 60) . ' min'; if ($s < 172800) return round($s / 3600) . ' h'; return round($s / 86400) . ' d'; };
$ch_icon = array('fb' => 'fab fa-facebook-messenger', 'ig' => 'fab fa-instagram', 'wa' => 'fab fa-whatsapp', 'tg' => 'fab fa-telegram', 'web' => 'fas fa-globe');
$sched_ok = $cron_age !== null && $cron_age <= 900;
?>
<section class="section">
  <div class="section-header"><h1><i class="fas fa-building"></i> <?php echo $page_title; ?></h1>
    <div class="section-header-breadcrumb">
      <a href="<?php echo base_url('system_health'); ?>" class="badge <?php echo $sched_ok ? 'badge-success' : 'badge-danger'; ?>" style="font-size:13px">
        Automation <?php echo $sched_ok ? 'running' : 'NOT running'; ?> · <?php echo $age($cron_age); ?></a>
    </div>
  </div>
  <div class="section-body">
    <div class="row">
      <?php foreach (array(
        array('Clients', $totals['clients'], 'fas fa-users', 'primary'),
        array('Live channels', $totals['active_channels'], 'fas fa-plug', 'info'),
        array('Waiting for a human', $totals['waiting'], 'fas fa-user-clock', $totals['waiting'] ? 'danger' : 'success'),
        array('Unanswered questions', $totals['missed'], 'fas fa-question-circle', $totals['missed'] ? 'warning' : 'success'),
        array('Leads (' . $days . 'd)', $totals['leads_30'], 'fas fa-funnel-dollar', 'success'),
        array('AI cost (' . $days . 'd)', '$' . number_format($totals['ai_cost_30'], 2), 'fas fa-robot', 'secondary'),
      ) as $k): ?>
      <div class="col-lg-2 col-md-4 col-6"><div class="card card-statistic-1">
        <div class="card-icon bg-<?php echo $k[3]; ?>"><i class="<?php echo $k[2]; ?>"></i></div>
        <div class="card-wrap"><div class="card-header"><h4><?php echo $k[0]; ?></h4></div><div class="card-body"><?php echo $k[1]; ?></div></div>
      </div></div>
      <?php endforeach; ?>
    </div>

    <div class="card"><div class="card-header"><h4>Clients — needs attention first</h4></div>
      <div class="card-body p-0"><div class="table-responsive">
        <table class="table table-striped mb-0">
          <thead><tr><th>Client</th><th>Channels</th><th title="Customer messages in the last 7 days">In (7d)</th><th title="Bot replies per customer message">Bot %</th>
            <th>Last message</th><th>Leads</th><th>Won</th><th title="Waiting for a human">Waiting</th><th title="Unanswered questions">Missed</th>
            <th title="Price-guard blocks, 7d">Guard</th><th>AI cost</th><th>Attention</th></tr></thead>
          <tbody>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td><b><?php echo htmlspecialchars($r['name']); ?></b><?php if ($r['type'] === 'Admin'): ?> <span class="badge badge-light">agency</span><?php endif; ?>
                <br><small class="text-muted"><?php echo htmlspecialchars($r['email']); ?></small></td>
              <td style="white-space:nowrap"><?php foreach ($r['channels'] as $k => $n): if ($n > 0): ?>
                <span class="mr-1" title="<?php echo $k . ': ' . $n; ?>"><i class="<?php echo $ch_icon[$k]; ?>"></i><?php echo $n > 1 ? '<sup>' . $n . '</sup>' : ''; ?></span>
                <?php endif; endforeach; ?><?php if (!array_sum($r['channels'])): ?><span class="text-muted">none</span><?php endif; ?></td>
              <td><?php echo $r['msgs_in_7']; ?></td>
              <td><?php echo $r['bot_reply_ratio'] === null ? '—' : $r['bot_reply_ratio'] . '%'; ?></td>
              <td><?php echo $ago($r['last_message']); ?></td>
              <td><?php echo $r['leads_30']; ?></td>
              <td><?php echo $r['won_30'] ? number_format($r['won_30']) : '—'; ?></td>
              <td><?php echo $r['waiting'] ? '<span class="badge badge-danger">' . $r['waiting'] . '</span>' : '0'; ?></td>
              <td><?php echo $r['missed']; ?></td>
              <td><?php echo $r['guard_7']; ?></td>
              <td>$<?php echo number_format($r['ai_cost_30'], 2); ?></td>
              <td><?php foreach ($r['flags'] as $f): ?><span class="badge badge-<?php echo $f[0]; ?> mb-1"><?php echo htmlspecialchars($f[1]); ?></span> <?php endforeach; ?>
                <?php if (!$r['flags']): ?><span class="text-success"><i class="fas fa-check"></i> OK</span><?php endif; ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div></div>
    </div>
    <p class="text-muted"><small>Read-only. Figures are per client account; no client can open this page.
      AI cost uses recorded estimates where available, otherwise the model price table.</small></p>
  </div>
</section>
