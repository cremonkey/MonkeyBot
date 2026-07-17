<?php $tk = $this->session->userdata('csrf_token_session'); $c = $config ?: array(); ?>
<section class="section">
  <div class="section-header"><h1><i class="fas fa-people-arrows"></i> <?php echo $page_title; ?></h1></div>
  <?php $this->load->view('admin/theme/message'); ?>
  <div class="section-body">
    <div class="row">
      <div class="col-lg-7">
        <div class="card">
          <div class="card-header"><h4>Connection</h4></div>
          <form action="<?php echo base_url('crm/external_settings_save'); ?>" method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo $tk; ?>">
            <div class="card-body">
              <div class="alert alert-info py-2 px-3" style="font-size:13px;">
                Every AI-captured lead is mirrored here automatically. Sends run in the background (every few minutes), so a slow CRM never delays a customer's reply.
              </div>
              <div class="form-group"><label>API base URL</label><input name="base_url" class="form-control" value="<?php echo htmlspecialchars($c['base_url'] ?? 'https://byootbay.8xcrm.com'); ?>"><small class="text-muted">Use the API host (…8xcrm.com), not the docs host (…8xcrm.net).</small></div>
              <div class="row">
                <div class="col-md-6 form-group"><label>Client ID</label><input name="client_id" class="form-control" value="<?php echo htmlspecialchars($c['client_id'] ?? ''); ?>"></div>
                <div class="col-md-6 form-group"><label>Client Secret</label><input name="client_secret" class="form-control" placeholder="<?php echo !empty($c['client_secret']) ? '•••••• (leave blank to keep)' : ''; ?>"></div>
              </div>
              <div class="row">
                <div class="col-md-6 form-group"><label>Username</label><input name="username" class="form-control" value="<?php echo htmlspecialchars($c['username'] ?? ''); ?>"></div>
                <div class="col-md-6 form-group"><label>Password</label><input name="password" type="password" class="form-control" placeholder="<?php echo !empty($c['password']) ? '•••••• (leave blank to keep)' : ''; ?>"></div>
              </div>
              <div class="row">
                <div class="col-md-6 form-group"><label>Form ID</label><input name="form_id" class="form-control" value="<?php echo htmlspecialchars($c['form_id'] ?? ''); ?>"></div>
                <div class="col-md-6 form-group"><label>Default country code</label><input name="default_country_code" class="form-control" value="<?php echo htmlspecialchars($c['default_country_code'] ?? 'EG'); ?>"></div>
              </div>
              <label class="custom-switch mt-2">
                <input type="checkbox" name="status" value="1" class="custom-switch-input" <?php echo (($c['status'] ?? '0')=='1')?'checked':''; ?>>
                <span class="custom-switch-indicator"></span><span class="custom-switch-description">Enable sync</span>
              </label>
            </div>
            <div class="card-footer"><button class="btn btn-primary">Save</button></div>
          </form>
        </div>
      </div>
      <div class="col-lg-5">
        <div class="card">
          <div class="card-header"><h4>Status</h4></div>
          <div class="card-body">
            <table class="table table-sm">
              <tr><td>Enabled</td><td><?php echo (($c['status'] ?? '0')=='1') ? '<span class="text-success">Yes</span>' : '<span class="text-danger">No</span>'; ?></td></tr>
              <tr><td>Leads sent</td><td><?php echo (int)($c['sent_count'] ?? 0); ?></td></tr>
              <tr><td>Last sync</td><td><?php echo htmlspecialchars($c['last_sync_at'] ?? '—'); ?></td></tr>
              <tr><td>Queue</td><td><?php echo (int)$queue['pending']; ?> pending · <?php echo (int)$queue['sent']; ?> sent · <?php echo (int)$queue['failed']; ?> failed</td></tr>
              <?php if (!empty($c['last_error'])): ?>
              <tr><td>Last error</td><td class="text-danger" style="font-size:12px;"><?php echo htmlspecialchars(mb_substr($c['last_error'],0,120)); ?></td></tr>
              <?php endif; ?>
            </table>
          </div>
        </div>
        <div class="card">
          <div class="card-header"><h4>Backfill</h4></div>
          <div class="card-body">
            <p style="font-size:13px;">Queue all existing deals to the CRM. Safe to re-run — already-sent leads are skipped.</p>
            <button id="bf" class="btn btn-outline-primary"><i class="fas fa-cloud-upload-alt"></i> Send existing leads</button>
            <div id="bfmsg" class="mt-2" style="font-size:13px;"></div>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>
<script>
document.getElementById('bf').addEventListener('click', function(){
  var b=this; b.disabled=true; document.getElementById('bfmsg').textContent='Queuing…';
  var fd=new FormData(); fd.append('csrf_token','<?php echo $tk; ?>');
  fetch('<?php echo base_url('crm/external_crm_backfill'); ?>',{method:'POST',body:fd}).then(r=>r.json()).then(function(r){
    document.getElementById('bfmsg').textContent=r.message||'Done'; b.disabled=false;
  }).catch(function(){document.getElementById('bfmsg').textContent='Failed';b.disabled=false;});
});
</script>
