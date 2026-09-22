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
          <input id="sq_search" class="form-control mr-2" style="min-width:260px" placeholder="Search by name, phone or id">
          <select id="sq_pick" class="form-control mr-2" style="min-width:260px"><option value="">Search above, then pick&hellip;</option></select>
          <button type="button" class="btn btn-primary" id="sq_enroll_btn">Enroll</button>
        </div>
        <div id="sq_enroll_msg" class="mt-2"></div>
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
            <th>Channel</th><th style="width:90px">Step</th><th style="width:110px">Status</th>
            <th style="width:170px">Next step due</th><th>Notes</th>
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
