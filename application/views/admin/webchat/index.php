<?php $tk = $this->session->userdata('csrf_token_session'); ?>
<section class="section">
  <div class="section-header"><h1><i class="fas fa-comment-dots"></i> <?php echo $page_title; ?></h1></div>
  <?php $this->load->view('admin/theme/message'); ?>
  <div class="section-body">

    <div class="alert alert-info py-2 px-3" style="font-size:13px;">
      <i class="fas fa-info-circle"></i> Create one widget per website. Assign each widget the <strong>AI Agent</strong> whose prompt that site should use — the Kemzo site gets Kemzo's agent, the Creative Monkey site gets Creative Monkey's, and so on. Manage the agents themselves in <a href="<?php echo base_url('ai_agents'); ?>">AI Agents</a>.
    </div>

    <div class="card">
      <div class="card-header">
        <h4>Your Widgets</h4>
        <div class="card-header-action">
          <form action="<?php echo base_url('webchat/add'); ?>" method="POST" class="d-flex" style="gap:8px;">
            <input type="hidden" name="csrf_token" value="<?php echo $tk; ?>">
            <input name="title" class="form-control form-control-sm" placeholder="New widget name (e.g. Kemzo site)" style="min-width:220px;">
            <button class="btn btn-sm btn-primary"><i class="fas fa-plus"></i> New Widget</button>
          </form>
        </div>
      </div>
    </div>

    <?php foreach ($widgets as $w):
      $assigned = isset($assign_map[$w['widget_key']]) ? (int)$assign_map[$w['widget_key']] : 0;
      $embed = '<script src="'.$base.'webchat/widget/'.$w['widget_key'].'" async></script>';
    ?>
    <div class="card">
      <div class="card-header">
        <h4><i class="fas fa-comment-dots"></i> <?php echo htmlspecialchars($w['title'] ?: 'Web Chat Widget'); ?></h4>
        <div class="card-header-action">
          <a href="<?php echo base_url('webchat/delete_widget/'.$w['id'].'?t='.$tk); ?>" class="btn btn-sm btn-danger" onclick="return confirm('Delete this widget? The embed on that site will stop working.');"><i class="fas fa-trash"></i></a>
        </div>
      </div>
      <form action="<?php echo base_url('webchat/save'); ?>" method="POST">
        <input type="hidden" name="csrf_token" value="<?php echo $tk; ?>">
        <input type="hidden" name="id" value="<?php echo $w['id']; ?>">
        <div class="card-body">
          <div class="row">
            <div class="col-md-6">
              <div class="form-group"><label>Widget name</label><input name="title" class="form-control" value="<?php echo htmlspecialchars($w['title']); ?>"></div>
              <div class="form-group">
                <label>AI Agent (this site's prompt)</label>
                <select name="profile_id" class="form-control">
                  <option value="0">— Account default (no agent) —</option>
                  <?php foreach ($profiles as $p): ?>
                    <option value="<?php echo $p['id']; ?>" <?php echo $assigned===(int)$p['id']?'selected':''; ?>><?php echo htmlspecialchars($p['name']); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="row">
                <div class="col-6 form-group"><label>Color</label><input name="color" type="color" class="form-control" value="<?php echo htmlspecialchars($w['color'] ?: '#4a6cf7'); ?>"></div>
                <div class="col-6 form-group"><label>Greeting</label><input name="greeting" class="form-control" value="<?php echo htmlspecialchars($w['greeting']); ?>"></div>
              </div>
              <label class="custom-switch mt-2">
                <input type="checkbox" name="ai_enabled" value="1" class="custom-switch-input" <?php echo $w['ai_enabled']=='1'?'checked':''; ?>>
                <span class="custom-switch-indicator"></span><span class="custom-switch-description">Enable AI auto-reply</span>
              </label>
            </div>
            <div class="col-md-6">
              <label>Embed on your website</label>
              <p class="text-muted" style="font-size:12px;">Paste before <code>&lt;/body&gt;</code> on that site only:</p>
              <textarea class="form-control" rows="3" readonly onclick="this.select()"><?php echo htmlspecialchars($embed); ?></textarea>
              <small class="text-muted">Key: <code><?php echo htmlspecialchars($w['widget_key']); ?></code></small>
            </div>
          </div>
        </div>
        <div class="card-footer"><button class="btn btn-primary">Save</button></div>
      </form>
    </div>
    <?php endforeach; ?>

  </div>
</section>
