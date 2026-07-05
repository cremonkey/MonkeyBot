<section class="section">
  <div class="section-header"><h1><i class="fas fa-comment-dots"></i> <?php echo $page_title; ?></h1></div>
  <?php $this->load->view('admin/theme/message'); ?>
  <div class="section-body">
    <div class="row">
      <div class="col-lg-6">
        <div class="card">
          <div class="card-header"><h4>Widget Settings</h4></div>
          <form action="<?php echo base_url('webchat/save'); ?>" method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo $this->session->userdata('csrf_token_session'); ?>">
            <div class="card-body">
              <div class="form-group"><label>Title</label><input name="title" class="form-control" value="<?php echo htmlspecialchars($settings['title']); ?>"></div>
              <div class="form-group"><label>Color</label><input name="color" type="color" class="form-control" value="<?php echo htmlspecialchars($settings['color']); ?>"></div>
              <div class="form-group"><label>Greeting</label><input name="greeting" class="form-control" value="<?php echo htmlspecialchars($settings['greeting']); ?>"></div>
              <div class="form-group">
                <label class="custom-switch mt-2">
                  <input type="checkbox" name="ai_enabled" value="1" class="custom-switch-input" <?php echo $settings['ai_enabled']=='1'?'checked':''; ?>>
                  <span class="custom-switch-indicator"></span><span class="custom-switch-description">Enable AI auto-reply</span>
                </label>
              </div>
            </div>
            <div class="card-footer"><button class="btn btn-primary">Save</button></div>
          </form>
        </div>
      </div>
      <div class="col-lg-6">
        <div class="card">
          <div class="card-header"><h4>Embed on your website</h4></div>
          <div class="card-body">
            <p>Paste this snippet before <code>&lt;/body&gt;</code> on any website:</p>
            <textarea class="form-control" rows="3" readonly onclick="this.select()"><?php echo htmlspecialchars($embed); ?></textarea>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>
