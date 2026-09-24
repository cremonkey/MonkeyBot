<section class="section">
  <div class="section-header">
    <h1><i class="fas fa-stream"></i> <?php echo $page_title; ?></h1>
    <div class="section-header-breadcrumb">
      <a href="<?php echo base_url('sequences/enrollments'); ?>" class="btn btn-outline-secondary mr-2"><i class="fas fa-list"></i> All enrollments</a>
      <a href="<?php echo base_url('sequences/form'); ?>" class="btn btn-primary"><i class="fas fa-plus"></i> New sequence</a>
    </div>
  </div>

  <div class="section-body">
    <p class="text-muted">A sequence sends a set of timed steps to whoever is enrolled in it — a
      help message 10 minutes in, a reminder a few hours later, a follow-up task for your team
      the next day. Enroll someone by hand, or let an automation do it for you.</p>

    <?php if (!$engine_on): ?>
      <div class="alert alert-warning">
        <strong>Sequences are not switched on for this system yet.</strong>
        You can build and test sequences now — steps will start firing as soon as your
        administrator enables the automation engine (the same switch Automations uses).
      </div>
    <?php endif; ?>

    <div class="card">
      <div class="card-body p-0">
        <?php if (empty($sequences)): ?>
          <div class="p-5 text-center text-muted">
            <p class="mb-3">You have no sequences yet.</p>
            <a href="<?php echo base_url('sequences/form'); ?>" class="btn btn-primary">Create your first one</a>
          </div>
        <?php else: ?>
        <table class="table table-striped mb-0">
          <thead><tr>
            <th>Name</th><th>Starts when</th><th class="sq-col-w90">Steps</th>
            <th class="sq-col-w260">Who is in it</th><th class="sq-col-w110">Status</th><th class="sq-col-w260"></th>
          </tr></thead>
          <tbody>
          <?php foreach ($sequences as $s): ?>
            <tr data-id="<?php echo (int) $s['id']; ?>">
              <td><a href="<?php echo base_url('sequences/form/' . (int) $s['id']); ?>"><?php echo htmlspecialchars($s['name']); ?></a></td>
              <td><?php echo $s['trigger_event'] === 'manual' ? 'Added by hand' : htmlspecialchars(event_label($s['trigger_event'])); ?></td>
              <td><?php echo (int) $s['step_count']; ?></td>
              <td>
                <span class="badge badge-primary"><?php echo (int) $s['tally']['active']; ?> in progress</span>
                <span class="badge badge-success"><?php echo (int) $s['tally']['completed']; ?> finished</span>
                <span class="badge badge-light"><?php echo (int) $s['tally']['stopped']; ?> stopped</span>
              </td>
              <td>
                <?php $cls = array('active' => 'success', 'paused' => 'warning', 'draft' => 'secondary'); ?>
                <span class="badge badge-<?php echo $cls[$s['status']]; ?> js-status"><?php echo htmlspecialchars($s['status'] === 'draft' ? 'not finished' : ($s['status'] === 'active' ? 'running' : 'paused')); ?></span>
              </td>
              <td class="text-right">
                <?php if ($s['status'] === 'active'): ?>
                  <button class="btn btn-sm btn-outline-warning js-set" data-status="paused">Pause</button>
                <?php else: ?>
                  <button class="btn btn-sm btn-outline-success js-set" data-status="active">Turn on</button>
                <?php endif; ?>
                <a class="btn btn-sm btn-outline-secondary" href="<?php echo base_url('sequences/enrollments/' . (int) $s['id']); ?>">Who's in it</a>
                <button class="btn btn-sm btn-outline-danger js-del">Delete</button>
              </td>
            </tr>
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
  var TOKEN = "<?php echo $this->session->userdata('csrf_token_session'); ?>", BASE = "<?php echo base_url(); ?>";
  function post(url, data, done) {
    data.csrf_token = TOKEN;
    $.post(BASE + url, data, function (r) { try { done(typeof r === 'string' ? JSON.parse(r) : r); } catch (e) { alert('Something went wrong.'); } });
  }
  $(document).on('click', '.js-set', function () {
    var tr = $(this).closest('tr'), id = tr.data('id'), status = $(this).data('status');
    post('sequences/set_status', {id: id, status: status}, function (r) {
      if (r.status === '1') location.reload(); else alert(r.message || 'Could not change status.');
    });
  });
  $(document).on('click', '.js-del', function () {
    if (!confirm('Delete this sequence? Enrollment history is kept, but the sequence and its steps are gone.')) return;
    var tr = $(this).closest('tr');
    post('sequences/delete_sequence', {id: tr.data('id')}, function (r) {
      if (r.status === '1') tr.remove(); else alert('Could not delete this sequence.');
    });
  });
})();
</script>
