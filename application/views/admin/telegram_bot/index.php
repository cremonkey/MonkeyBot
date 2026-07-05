<section class="section">
  <div class="section-header"><h1><i class="fab fa-telegram"></i> <?php echo $page_title; ?></h1></div>
  <?php $this->load->view('admin/theme/message'); ?>
  <div class="section-body">
    <div class="row">
      <div class="col-lg-5">
        <div class="card">
          <div class="card-header"><h4>Connect a Telegram Bot</h4></div>
          <form action="<?php echo base_url('telegram_bot/save'); ?>" method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo $this->session->userdata('csrf_token_session'); ?>">
            <div class="card-body">
              <div class="form-group">
                <label>Bot Token (from @BotFather)</label>
                <input name="bot_token" class="form-control" placeholder="123456:ABC-DEF..." required>
              </div>
              <div class="form-group">
                <label class="custom-switch mt-2">
                  <input type="checkbox" name="ai_enabled" value="1" class="custom-switch-input" checked>
                  <span class="custom-switch-indicator"></span>
                  <span class="custom-switch-description">Enable AI auto-reply</span>
                </label>
              </div>
              <p class="text-muted small">On save we validate the token, then automatically register the webhook.</p>
            </div>
            <div class="card-footer"><button class="btn btn-primary btn-block"><i class="fab fa-telegram"></i> Connect Bot</button></div>
          </form>
        </div>
      </div>
      <div class="col-lg-7">
        <div class="card">
          <div class="card-header"><h4>Connected Bots</h4></div>
          <div class="card-body">
            <table class="table">
              <thead><tr><th>Bot</th><th>AI</th><th>Status</th><th></th></tr></thead>
              <tbody>
                <?php if(!empty($accounts)): foreach($accounts as $a): ?>
                <tr>
                  <td>@<?php echo htmlspecialchars($a['bot_username']); ?></td>
                  <td><?php echo $a['ai_enabled']=='1'?'<span class="badge badge-success">On</span>':'<span class="badge badge-secondary">Off</span>'; ?></td>
                  <td><?php echo $a['status']=='1'?'<span class="badge badge-primary">Active</span>':'Inactive'; ?></td>
                  <td><a href="<?php echo base_url('telegram_bot/delete/'.$a['id'].'?t='.$this->session->userdata('csrf_token_session')); ?>" class="btn btn-sm btn-danger" onclick="return confirm('Remove this bot?')">Delete</a></td>
                </tr>
                <?php endforeach; else: ?>
                <tr><td colspan="4" class="text-muted">No bots connected yet.</td></tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>
