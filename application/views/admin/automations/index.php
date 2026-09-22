<section class="section">
  <div class="section-header">
    <h1><i class="fas fa-bolt"></i> <?php echo $page_title; ?></h1>
    <div class="section-header-breadcrumb">
      <a href="<?php echo base_url('automations/runs'); ?>" class="btn btn-outline-secondary mr-2"><i class="fas fa-history"></i> Activity</a>
      <a href="<?php echo base_url('automations/form'); ?>" class="btn btn-primary"><i class="fas fa-plus"></i> New automation</a>
    </div>
  </div>

  <div class="section-body">
    <p class="text-muted">An automation watches for something happening in your business, checks a few conditions,
      and then does the work for you — tag the customer, create a deal, tell your team, or reply.
      Nothing runs until you switch it on.</p>

    <?php if (!$engine_on): ?>
      <div class="alert alert-warning">
        <strong>Automations are not switched on for this system yet.</strong>
        You can build and test rules now — they will start running as soon as your administrator enables them.
        Everything that happens in the meantime is still being recorded<?php if ($events_waiting > 0): ?>
          (<?php echo (int) $events_waiting; ?> waiting)<?php endif; ?>.
      </div>
    <?php endif; ?>

    <div class="card">
      <div class="card-body p-0">
        <?php if (empty($rules)): ?>
          <div class="p-5 text-center text-muted">
            <p class="mb-3">You have no automations yet.</p>
            <a href="<?php echo base_url('automations/form'); ?>" class="btn btn-primary">Create your first one</a>
          </div>
        <?php else: ?>
        <table class="table table-striped mb-0">
          <thead><tr>
            <th>Name</th><th>Starts when</th><th style="width:90px">Steps</th>
            <th style="width:220px">Last 30 days</th><th style="width:170px">Last activity</th>
            <th style="width:110px">Status</th><th style="width:210px"></th>
          </tr></thead>
          <tbody>
          <?php foreach ($rules as $r): ?>
            <tr data-id="<?php echo (int) $r['id']; ?>">
              <td><a href="<?php echo base_url('automations/form/' . (int) $r['id']); ?>"><?php echo htmlspecialchars($r['name']); ?></a></td>
              <td><?php echo htmlspecialchars(event_label($r['trigger_event'])); ?></td>
              <td><?php echo (int) $r['action_count']; ?></td>
              <td>
                <span class="badge badge-success"><?php echo (int) $r['tally']['success']; ?> done</span>
                <span class="badge badge-danger"><?php echo (int) $r['tally']['failed']; ?> failed</span>
                <span class="badge badge-light"><?php echo (int) $r['tally']['skipped']; ?> skipped</span>
              </td>
              <td><?php echo $r['last_run'] ? htmlspecialchars($r['last_run']['created_at']) : '<span class="text-muted">never</span>'; ?></td>
              <td>
                <?php $cls = array('active' => 'success', 'paused' => 'warning', 'draft' => 'secondary'); ?>
                <span class="badge badge-<?php echo $cls[$r['status']]; ?> js-status"><?php echo htmlspecialchars($r['status'] === 'draft' ? 'not finished' : ($r['status'] === 'active' ? 'running' : 'paused')); ?></span>
              </td>
              <td class="text-right">
                <?php if ($r['status'] === 'active'): ?>
                  <button class="btn btn-sm btn-outline-warning js-set" data-status="paused">Pause</button>
                <?php else: ?>
                  <button class="btn btn-sm btn-outline-success js-set" data-status="active">Turn on</button>
                <?php endif; ?>
                <a class="btn btn-sm btn-outline-secondary" href="<?php echo base_url('automations/runs/' . (int) $r['id']); ?>">Activity</a>
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
    var tr = $(this).closest('tr');
    post('automations/set_status', {id: tr.data('id'), status: $(this).data('status')}, function (r) {
      if (r.status === '1') location.reload(); else alert(r.message || 'Could not change the status.');
    });
  });
  $(document).on('click', '.js-del', function () {
    if (!confirm('Delete this automation? Its past activity stays in the log.')) return;
    var tr = $(this).closest('tr');
    post('automations/delete_rule', {id: tr.data('id')}, function (r) { if (r.status === '1') tr.remove(); else alert('Could not delete it.'); });
  });
})();
</script>
