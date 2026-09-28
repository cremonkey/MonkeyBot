<?php $tk = $this->session->userdata('csrf_token_session'); ?>
<style>
  .cmb-outbox { --cmb-ok: #2e7d32; --cmb-pending: #b26a00; --cmb-fail: #c62828; --cmb-muted: #6c757d; }
  .cmb-outbox .badge-cmb-sent { background: var(--cmb-ok); color: #fff; }
  .cmb-outbox .badge-cmb-pending, .cmb-outbox .badge-cmb-processing { background: var(--cmb-pending); color: #fff; }
  .cmb-outbox .badge-cmb-failed { background: var(--cmb-fail); color: #fff; }
  .cmb-outbox .cmb-error { font-size: 12px; color: var(--cmb-fail); max-width: 320px; display: inline-block; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; vertical-align: bottom; }
  .cmb-outbox .cmb-muted { color: var(--cmb-muted); }
</style>
<section class="section cmb-outbox">
  <div class="section-header"><h1><i class="fas fa-inbox"></i> <?php echo $page_title; ?></h1></div>
  <?php $this->load->view('admin/theme/message'); ?>
  <div class="section-body">
    <div class="row mb-2">
      <div class="col-12">
        <span class="badge badge-cmb-pending mr-1"><?php echo (int) ($counts['pending'] ?? 0); ?> pending</span>
        <span class="badge badge-cmb-processing mr-1"><?php echo (int) ($counts['processing'] ?? 0); ?> processing</span>
        <span class="badge badge-cmb-sent mr-1"><?php echo (int) ($counts['sent'] ?? 0); ?> sent</span>
        <span class="badge badge-cmb-failed"><?php echo (int) ($counts['failed'] ?? 0); ?> failed</span>
      </div>
    </div>
    <div class="card">
      <div class="card-header"><h4>Queued pushes (8xCRM + Google Sheet)</h4></div>
      <div class="card-body table-responsive">
        <table class="table table-sm">
          <thead>
            <tr><th>#</th><th>Destination</th><th>Deal</th><th>Contact</th><th>Status</th><th>Attempts</th><th>Error</th><th>Queued</th><th></th></tr>
          </thead>
          <tbody>
          <?php if (empty($rows)): ?>
            <tr><td colspan="9" class="cmb-muted text-center">Nothing queued.</td></tr>
          <?php endif; ?>
          <?php foreach ($rows as $r): ?>
            <tr data-outbox-row="<?php echo (int) $r['id']; ?>">
              <td><?php echo (int) $r['id']; ?></td>
              <td><?php echo htmlspecialchars($r['provider_label']); ?></td>
              <td><?php echo $r['deal_id'] ? '#' . (int) $r['deal_id'] : '<span class="cmb-muted">—</span>'; ?></td>
              <td><?php echo htmlspecialchars($r['phone'] ?: '—'); ?></td>
              <td><span class="badge badge-cmb-<?php echo htmlspecialchars($r['status']); ?>"><?php echo htmlspecialchars($r['status']); ?></span></td>
              <td><?php echo (int) $r['attempts']; ?></td>
              <td><?php echo $r['error'] ? '<span class="cmb-error" title="' . htmlspecialchars($r['error']) . '">' . htmlspecialchars($r['error']) . '</span>' : '—'; ?></td>
              <td><?php echo htmlspecialchars($r['created_at'] ?: '—'); ?></td>
              <td>
                <?php if (in_array($r['status'], array('pending', 'failed'), true)): ?>
                <button type="button" class="btn btn-sm btn-outline-primary cmb-retry-btn" data-id="<?php echo (int) $r['id']; ?>">Retry</button>
                <?php else: ?>
                <span class="cmb-muted">—</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</section>
<script>
(function () {
  var csrfToken = <?php echo json_encode((string) $tk); ?>;
  document.querySelectorAll('.cmb-retry-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var id = btn.getAttribute('data-id');
      btn.disabled = true;
      var body = new URLSearchParams();
      body.set('id', id);
      body.set('csrf_token', csrfToken);
      fetch('<?php echo base_url('crm/outbox_retry'); ?>', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
        body: body.toString()
      }).then(function (r) { return r.json(); }).then(function (j) {
        if (j.status === '1') { location.reload(); }
        else { btn.disabled = false; alert(j.message || 'Retry failed'); }
      }).catch(function () { btn.disabled = false; });
    });
  });
})();
</script>
