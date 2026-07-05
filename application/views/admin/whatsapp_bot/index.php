<section class="section">
  <div class="section-header"><h1><i class="fab fa-whatsapp"></i> <?php echo $page_title; ?></h1></div>
  <?php $this->load->view('admin/theme/message'); ?>
  <div class="section-body">
    <div class="row">
      <div class="col-lg-5">
        <div class="card">
          <div class="card-header"><h4>Connect a WhatsApp Number</h4></div>
          <form action="<?php echo base_url('whatsapp_bot/save'); ?>" method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo $this->session->userdata('csrf_token_session'); ?>">
            <div class="card-body">
              <div class="form-group"><label>Label</label><input name="label" class="form-control" placeholder="My Store"></div>
              <div class="form-group"><label>WABA ID</label><input name="waba_id" class="form-control"></div>
              <div class="form-group"><label>Phone Number ID</label><input name="phone_number_id" class="form-control" required></div>
              <div class="form-group"><label>Display Phone</label><input name="display_phone" class="form-control" placeholder="+1..."></div>
              <div class="form-group"><label>Permanent Access Token</label><input name="access_token" class="form-control" required></div>
              <div class="form-group"><label>Meta App Secret <small class="text-muted">(recommended — verifies inbound webhook signatures)</small></label><input name="app_secret" class="form-control"></div>
              <div class="form-group">
                <label class="custom-switch mt-2">
                  <input type="checkbox" name="ai_enabled" value="1" class="custom-switch-input" checked>
                  <span class="custom-switch-indicator"></span><span class="custom-switch-description">Enable AI auto-reply</span>
                </label>
              </div>
            </div>
            <div class="card-footer"><button class="btn btn-success btn-block"><i class="fab fa-whatsapp"></i> Connect</button></div>
          </form>
        </div>
      </div>
      <div class="col-lg-7">
        <div class="card">
          <div class="card-header"><h4>Connected Numbers</h4></div>
          <div class="card-body">
            <table class="table">
              <thead><tr><th>Label</th><th>Phone</th><th>AI</th><th>Webhook / Verify Token</th><th></th></tr></thead>
              <tbody>
                <?php if(!empty($accounts)): foreach($accounts as $a): ?>
                <tr>
                  <td><?php echo htmlspecialchars($a['label']); ?></td>
                  <td><?php echo htmlspecialchars($a['display_phone']); ?></td>
                  <td><?php echo $a['ai_enabled']=='1'?'<span class="badge badge-success">On</span>':'<span class="badge badge-secondary">Off</span>'; ?></td>
                  <td><small>Callback: <code><?php echo $webhook_base.$a['id']; ?></code><br>Verify: <code><?php echo htmlspecialchars($a['verify_token']); ?></code></small></td>
                  <td><a href="<?php echo base_url('whatsapp_bot/delete/'.$a['id'].'?t='.$this->session->userdata('csrf_token_session')); ?>" class="btn btn-sm btn-danger" onclick="return confirm('Remove?')">Delete</a></td>
                </tr>
                <?php endforeach; else: ?>
                <tr><td colspan="5" class="text-muted">No numbers connected. Paste the callback URL &amp; verify token into your Meta App → WhatsApp → Configuration.</td></tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>
