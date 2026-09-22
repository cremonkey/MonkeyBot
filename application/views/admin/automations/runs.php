<section class="section">
  <div class="section-header">
    <h1><i class="fas fa-history"></i> <?php echo $page_title; ?></h1>
    <div class="section-header-breadcrumb">
      <a href="<?php echo base_url('automations'); ?>" class="btn btn-outline-secondary">Back to automations</a>
    </div>
  </div>

  <div class="section-body">
    <p class="text-muted">Every time an automation looked at something that happened, this is what it did —
      including the times it decided to do nothing, and why. Click a line to see each step.</p>

    <div class="card">
      <div class="card-body p-0">
        <?php if (empty($runs)): ?>
          <div class="p-5 text-center text-muted">Nothing has run yet.</div>
        <?php else: ?>
        <table class="table table-striped mb-0">
          <thead><tr>
            <th style="width:150px">When</th><th>Automation</th><th>Because of</th>
            <th style="width:110px">Result</th><th>What happened</th>
          </tr></thead>
          <tbody>
          <?php foreach ($runs as $run): ?>
            <?php $badge = array('success' => 'success', 'failed' => 'danger', 'skipped' => 'light'); ?>
            <tr class="js-run" data-id="<?php echo (int) $run['id']; ?>" style="cursor:pointer">
              <td><?php echo htmlspecialchars($run['created_at']); ?></td>
              <td><?php echo htmlspecialchars($run['rule_name']); ?></td>
              <td><?php echo htmlspecialchars($run['event_type'] ? event_label($run['event_type']) : '—'); ?></td>
              <td><span class="badge badge-<?php echo $badge[$run['status']]; ?>">
                <?php echo $run['status'] === 'success' ? 'done' : ($run['status'] === 'failed' ? 'failed' : 'nothing to do'); ?>
              </span></td>
              <td class="small text-muted"><?php echo htmlspecialchars((string) $run['detail']); ?></td>
            </tr>
            <tr class="js-detail d-none" data-for="<?php echo (int) $run['id']; ?>"><td colspan="5" class="bg-light small"></td></tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </div>
    </div>
  </div>
</section>

<script>
(function () {
  var BASE = "<?php echo base_url(); ?>";
  var esc = function (s) { return $('<div>').text(s == null ? '' : String(s)).html(); };
  var LABEL = {
    success: '<span class="badge badge-success">done</span>',
    failed: '<span class="badge badge-danger">failed</span>',
    skipped: '<span class="badge badge-light">skipped</span>',
    skipped_policy: '<span class="badge badge-warning">not allowed on this channel</span>'
  };
  $(document).on('click', '.js-run', function () {
    var id = $(this).data('id'), row = $('.js-detail[data-for="' + id + '"]'), cell = row.find('td');
    if (!row.hasClass('d-none')) { row.addClass('d-none'); return; }
    row.removeClass('d-none');
    cell.html('Loading&hellip;');
    $.get(BASE + 'automations/run_detail/' + id, function (r) {
      r = typeof r === 'string' ? JSON.parse(r) : r;
      if (r.status !== '1') { cell.html('Not available.'); return; }
      if (!r.actions.length) { cell.html('No steps ran.'); return; }
      cell.html('<ol class="mb-0">' + r.actions.map(function (a) {
        return '<li>' + esc(a.action_type) + ' — ' + (LABEL[a.status] || esc(a.status)) +
          (a.error ? ' <span class="text-danger">' + esc(a.error) + '</span>' : '') +
          (Number(a.retry_count) > 0 ? ' <span class="text-muted">(tried ' + (Number(a.retry_count) + 1) + ' times)</span>' : '') + '</li>';
      }).join('') + '</ol>');
    });
  });
})();
</script>
