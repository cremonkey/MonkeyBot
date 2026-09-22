<?php
$age = function ($s) { if ($s === null) return 'never'; if ($s < 120) return $s . ' s ago'; if ($s < 7200) return round($s / 60) . ' min ago'; return round($s / 3600, 1) . ' h ago'; };
$ago = function ($t) { if (!$t) return '—'; $s = time() - strtotime($t); if ($s < 3600) return round($s / 60) . ' min'; if ($s < 172800) return round($s / 3600) . ' h'; return round($s / 86400) . ' d'; };
$ch_icon = array('fb' => 'fab fa-facebook-messenger', 'ig' => 'fab fa-instagram', 'wa' => 'fab fa-whatsapp', 'tg' => 'fab fa-telegram', 'web' => 'fas fa-globe');
$sched_ok = $cron_age !== null && $cron_age <= 900;
?>
<section class="section">
  <div class="section-header"><h1><i class="fas fa-building"></i> <?php echo $page_title; ?></h1>
    <div class="section-header-breadcrumb">
      <button type="button" class="btn btn-primary" data-toggle="modal" data-target="#new_client_modal"><i class="fas fa-plus"></i> New client</button>
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
        array('Renewals due soon', $totals['renewals_due_soon'], 'fas fa-calendar-times', $totals['renewals_due_soon'] ? 'danger' : 'success'),
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
          <thead><tr><th>Client</th><th>Package</th><th title="Days until package expiry">Renewal</th>
            <th title="Usage vs the package's monthly_limit for AI replies (module 340)">AI usage</th>
            <th>Channels</th><th title="Customer messages in the last 7 days">In (7d)</th><th title="Bot replies per customer message">Bot %</th>
            <th>Last message</th><th>Leads</th><th>Won</th><th title="Waiting for a human">Waiting</th><th title="Unanswered questions">Missed</th>
            <th title="Price-guard blocks, 7d">Guard</th><th>AI cost</th><th>Attention</th></tr></thead>
          <tbody>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td><b><?php echo htmlspecialchars($r['name']); ?></b><?php if ($r['type'] === 'Admin'): ?> <span class="badge badge-light">agency</span><?php endif; ?>
                <br><small class="text-muted"><?php echo htmlspecialchars($r['email']); ?></small></td>
              <td><?php echo htmlspecialchars($r['package_name']); ?></td>
              <td><?php if ($r['expiry']['status'] === 'none'): ?><span class="text-muted">—</span>
                <?php else: ?><span class="badge badge-<?php echo $r['expiry']['flag'] ? $r['expiry']['flag'] : 'light'; ?>"><?php echo htmlspecialchars($r['expiry']['label']); ?></span>
                <?php endif; ?></td>
              <td style="min-width:110px">
                <?php if ($r['usage']['unlimited']): ?><span class="text-muted">Unlimited</span>
                <?php else: ?>
                  <div class="progress" style="height:14px" title="<?php echo $r['usage']['usage'] . ' / ' . $r['usage']['limit']; ?>">
                    <div class="progress-bar bg-<?php echo $r['usage']['percent'] >= 90 ? 'danger' : ($r['usage']['percent'] >= 70 ? 'warning' : 'success'); ?>"
                      style="width:<?php echo $r['usage']['percent']; ?>%"></div>
                  </div>
                  <small class="text-muted"><?php echo $r['usage']['usage'] . '/' . $r['usage']['limit'] . ' (' . $r['usage']['percent'] . '%)'; ?></small>
                <?php endif; ?>
              </td>
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
      AI cost uses recorded estimates where available, otherwise the model price table.
      Package/renewal/AI-usage figures are readiness &amp; visibility only, not billing or invoicing —
      that already runs through Payment.php's own gateways.</small></p>
  </div>
</section>

<!-- New client (brief §31/§32) — posts to Agency_provision::provision_client(). This is the
     only write action on this page; Agency.php itself stays read-only. -->
<div class="modal fade" tabindex="-1" role="dialog" id="new_client_modal" data-backdrop="static">
  <div class="modal-dialog" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fas fa-user-plus"></i> New client</h5>
        <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
      </div>
      <div class="modal-body">
        <div id="nc_errors" class="alert alert-danger" style="display:none"></div>

        <div id="nc_form">
          <div class="form-group">
            <label>Business name</label>
            <input type="text" class="form-control" id="nc_name" placeholder="e.g. Sunrise Clinic">
          </div>
          <div class="form-group">
            <label>Contact email</label>
            <input type="email" class="form-control" id="nc_email" placeholder="owner@client.com" dir="ltr">
          </div>
          <div class="form-group">
            <label>Package</label>
            <select class="form-control" id="nc_package">
              <option value="">— choose a package —</option>
              <?php foreach ($packages as $p): ?>
                <option value="<?php echo (int) $p['id']; ?>"><?php echo htmlspecialchars($p['package_name']); ?> (<?php echo (int) $p['validity']; ?> days)</option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label>Starter business type <small class="text-muted">(optional — pre-fills their first Setup Guide step)</small></label>
            <select class="form-control" id="nc_template">
              <option value="">— none, start blank —</option>
              <?php foreach ($templates as $key => $t): ?>
                <option value="<?php echo htmlspecialchars($key); ?>"><?php echo htmlspecialchars($t['display_name']['en'] ?? $key); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <div id="nc_success" style="display:none">
          <div class="alert alert-success"><i class="fas fa-check-circle"></i> Client account created.</div>
          <div class="alert alert-warning">
            <b>Copy this now — it will not be shown again.</b>
            <div class="mt-2">
              <div>Email: <b id="nc_out_email" dir="ltr"></b></div>
              <div>Temporary password: <code id="nc_out_password" dir="ltr" style="font-size:15px"></code>
                <button type="button" class="btn btn-sm btn-outline-secondary ml-1" id="nc_copy">Copy</button></div>
            </div>
          </div>
          <p class="text-muted small mb-0">Hand these to the client and ask them to change the password after their first login.</p>
        </div>
      </div>
      <div class="modal-footer bg-whitesmoke">
        <button type="button" class="btn btn-primary" id="nc_submit"><i class="fas fa-check"></i> Create client</button>
        <button type="button" class="btn btn-secondary" id="nc_done" style="display:none" data-dismiss="modal">Done</button>
        <button type="button" class="btn btn-secondary" data-dismiss="modal" id="nc_cancel">Cancel</button>
      </div>
    </div>
  </div>
</div>

<script>
(function () {
  var TOKEN = "<?php echo $this->session->userdata('csrf_token_session'); ?>", BASE = "<?php echo base_url(); ?>";
  var esc = function (s) { return $('<div>').text(s == null ? '' : String(s)).html(); };
  function showErrors(list) {
    if (!list || !list.length) { $('#nc_errors').hide(); return; }
    $('#nc_errors').html('<b>Please fix:</b><ul class="mb-0">' + list.map(function (e) { return '<li>' + esc(e) + '</li>'; }).join('') + '</ul>').show();
  }
  $('#new_client_modal').on('hidden.bs.modal', function () {
    // Reset for next open. If a client was just created, refresh so the dashboard row shows.
    var created = $('#nc_success').is(':visible');
    $('#nc_name,#nc_email').val(''); $('#nc_package,#nc_template').val('');
    $('#nc_form').show(); $('#nc_success').hide(); showErrors([]);
    $('#nc_submit,#nc_cancel').show(); $('#nc_done').hide();
    if (created) location.reload();
  });
  $('#nc_submit').on('click', function () {
    var btn = $(this).prop('disabled', true);
    $.post(BASE + 'agency_provision/provision_client', {
      csrf_token: TOKEN,
      business_name: $('#nc_name').val(),
      email: $('#nc_email').val(),
      package_id: $('#nc_package').val(),
      template_key: $('#nc_template').val()
    }, function (r) {
      btn.prop('disabled', false);
      if (r.status !== '1') { showErrors(r.errors && r.errors.length ? r.errors : [r.message || 'Could not create the client account.']); return; }
      showErrors([]);
      $('#nc_out_email').text(r.client.email);
      $('#nc_out_password').text(r.temp_password);
      $('#nc_form').hide(); $('#nc_success').show();
      $('#nc_submit,#nc_cancel').hide(); $('#nc_done').show();
      if (typeof iziToast !== 'undefined') iziToast.success({message: r.message, position: 'topRight'});
    }, 'json').fail(function () { btn.prop('disabled', false); showErrors(['Network error. Nothing was saved — try again.']); });
  });
  $('#nc_copy').on('click', function () {
    var pw = $('#nc_out_password').text();
    if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(pw);
    if (typeof iziToast !== 'undefined') iziToast.info({message: 'Password copied.', position: 'topRight'});
  });
})();
</script>
