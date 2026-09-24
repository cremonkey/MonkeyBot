<section class="section">
  <div class="section-header">
    <h1><i class="fas fa-stream"></i> <?php echo $page_title; ?></h1>
    <div class="section-header-breadcrumb">
      <a href="<?php echo base_url('sequences'); ?>" class="btn btn-outline-secondary">Back to sequences</a>
    </div>
  </div>

  <div class="section-body">
    <?php if ($sequence): ?>
    <div class="card mb-3">
      <div class="card-body">
        <h6>Enroll a customer by hand</h6>
        <div class="form-inline">
          <input id="sq_search" class="form-control mr-2 sq-search-input" placeholder="Search by name, phone or id">
          <select id="sq_pick" class="form-control mr-2 sq-search-input"><option value="">Search above, then pick&hellip;</option></select>
          <button type="button" class="btn btn-primary" id="sq_enroll_btn">Enroll</button>
        </div>
        <div id="sq_enroll_msg" class="mt-2"></div>
      </div>
    </div>
    <?php endif; ?>

    <?php
      // Step-outcome visibility (brief §51: no silent failure). Only the reasons that ever
      // occurred are shown; 'success'/'completed' are not skips and are left out on purpose.
      $skip_labels = array(
        'skipped_policy'         => array('label' => 'Channel policy (messaging window / opt-out via Meta)', 'class' => 'secondary'),
        'skipped_stop_condition' => array('label' => 'Stopped (stop condition matched)', 'class' => 'secondary'),
        'skipped_opt_out'        => array('label' => 'Opted out', 'class' => 'danger'),
        'skipped_quiet_hours'    => array('label' => 'Quiet hours', 'class' => 'warning'),
        'skipped_rate_limit'     => array('label' => 'Rate limit', 'class' => 'warning'),
        'failed'                 => array('label' => 'Failed', 'class' => 'danger'),
      );
      $any_skips = false;
      foreach ($skip_labels as $k => $l) if (!empty($step_log_tally[$k])) $any_skips = true;
    ?>
    <?php if ($any_skips): ?>
    <div class="card mb-3">
      <div class="card-body">
        <h6 class="mb-2">Step outcomes</h6>
        <?php foreach ($skip_labels as $k => $l): if (empty($step_log_tally[$k])) continue; ?>
          <span class="badge badge-<?php echo $l['class']; ?> mr-2 mb-1 sq-skip-badge">
            <?php echo (int) $step_log_tally[$k]; ?> skipped: <?php echo htmlspecialchars($l['label']); ?>
          </span>
        <?php endforeach; ?>
        <?php if (!empty($step_log_tally['skipped_quiet_hours']) || !empty($step_log_tally['skipped_rate_limit'])): ?>
          <div class="small text-muted mt-2">Quiet hours and rate-limit skips are not retried automatically — the sequence
            moves on to its next step. Adjust the sequence's send limits, or re-enroll the customer by hand, if a
            message genuinely needs to go out later.</div>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <div class="card">
      <div class="card-body p-0">
        <?php if (empty($enrollments)): ?>
          <div class="p-5 text-center text-muted">Nobody is enrolled yet.</div>
        <?php else: ?>
        <table class="table table-striped mb-0">
          <thead><tr>
            <th>Customer</th><?php if (!$sequence): ?><th>Sequence</th><?php endif; ?>
            <th>Channel</th><th class="sq-col-w90">Step</th><th class="sq-col-w110">Status</th>
            <th class="sq-col-w170">Next step due</th><th>Notes</th>
          </tr></thead>
          <tbody>
          <?php foreach ($enrollments as $e): ?>
            <tr>
              <td><?php echo htmlspecialchars($e['customer_name']); ?></td>
              <?php if (!$sequence): ?><td><?php echo htmlspecialchars($e['sequence_name']); ?></td><?php endif; ?>
              <td><?php echo htmlspecialchars(strtoupper($e['platform'])); ?></td>
              <td><?php echo (int) $e['current_step'] + 1; ?></td>
              <td>
                <?php $cls = array('active' => 'primary', 'completed' => 'success', 'stopped' => 'secondary', 'failed' => 'danger'); ?>
                <span class="badge badge-<?php echo $cls[$e['status']]; ?>"><?php echo htmlspecialchars($e['status']); ?></span>
              </td>
              <td><?php echo $e['next_run_at'] ? htmlspecialchars($e['next_run_at']) : '<span class="text-muted">&mdash;</span>'; ?></td>
              <td class="small text-muted"><?php echo htmlspecialchars($e['stopped_reason'] ?: ''); ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </div>
    </div>
  </div>
</section>

<?php if ($sequence): ?>
<script>
(function () {
  var TOKEN = "<?php echo $this->session->userdata('csrf_token_session'); ?>", BASE = "<?php echo base_url(); ?>";
  var SEQ_ID = <?php echo (int) $sequence['id']; ?>;
  var picked = null, timer = null;

  $('#sq_search').on('input', function () {
    var q = $(this).val();
    clearTimeout(timer);
    if (q.length < 2) { $('#sq_pick').html('<option value="">Search above, then pick&hellip;</option>'); return; }
    timer = setTimeout(function () {
      $.get(BASE + 'sequences/search_subscribers', {q: q}, function (r) {
        r = typeof r === 'string' ? JSON.parse(r) : r;
        var html = '<option value="">Pick a customer&hellip;</option>';
        (r.rows || []).forEach(function (row) {
          html += '<option value="' + row.id + '">' + $('<div>').text(row.full_name || '(no name)').html() +
            ' — ' + $('<div>').text(row.phone_number || row.social_media).html() + '</option>';
        });
        $('#sq_pick').html(html);
      });
    }, 300);
  });

  $('#sq_enroll_btn').on('click', function () {
    var sid = $('#sq_pick').val();
    if (!sid) { $('#sq_enroll_msg').html('<span class="text-danger">Pick a customer first.</span>'); return; }
    $.post(BASE + 'sequences/enroll_manual', {id: SEQ_ID, subscriber_id: sid, csrf_token: TOKEN}, function (r) {
      r = typeof r === 'string' ? JSON.parse(r) : r;
      if (r.status === '1') location.reload();
      else $('#sq_enroll_msg').html('<span class="text-danger">' + $('<div>').text(r.message || 'Could not enroll.').html() + '</span>');
    });
  });
})();
</script>
<?php endif; ?>
