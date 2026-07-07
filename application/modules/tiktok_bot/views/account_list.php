<?php $this->load->view('admin/theme/message'); ?>

<section class="section section_custom">
  <div class="section-header">
    <h1><i class="fab fa-tiktok"></i> <?php echo $this->lang->line("TikTok Accounts"); ?></h1>
    <div class="section-header-button">
      <a class="btn btn-primary" href="<?php echo base_url('tiktok_bot/connect'); ?>">
        <i class="fas fa-plus-circle"></i> <?php echo $this->lang->line("Add Account"); ?>
      </a>
    </div>
    <div class="section-header-breadcrumb">
      <div class="breadcrumb-item"><?php echo $this->lang->line("TikTok"); ?></div>
      <div class="breadcrumb-item"><?php echo $this->lang->line("Accounts"); ?></div>
    </div>
  </div>

  <div class="section-body">
    <div class="row">
      <?php if(!empty($accounts)) { foreach($accounts as $acc) { ?>
        <div class="col-12 col-md-6 col-lg-4">
          <div class="card card-primary">
            <div class="card-header">
              <h4 class="d-inline"><?php echo htmlspecialchars($acc['display_name']); ?></h4>
              <div class="card-header-action float-right">
                <?php if($acc['status'] == 'active') { ?>
                  <span class="badge badge-success"><?php echo $this->lang->line("Active"); ?></span>
                <?php } else { ?>
                  <span class="badge badge-danger"><?php echo $this->lang->line("Expired"); ?></span>
                <?php } ?>
              </div>
            </div>
            <div class="card-body">
              <div class="author-box-picture mb-3 text-center">
                <?php if(!empty($acc['profile_picture'])) { ?>
                  <img src="<?php echo $acc['profile_picture']; ?>" class="rounded-circle" width="80" height="80" alt="">
                <?php } else { ?>
                  <div class="rounded-circle bg-light d-inline-flex align-items-center justify-content-center" style="width:80px;height:80px;">
                    <i class="fab fa-tiktok fa-3x text-dark"></i>
                  </div>
                <?php } ?>
              </div>
              <ul class="list-group list-group-flush">
                <li class="list-group-item d-flex justify-content-between align-items-center">
                  <?php echo $this->lang->line("Open ID"); ?>
                  <span class="text-muted text-truncate" style="max-width:60%;" title="<?php echo $acc['open_id']; ?>"><?php echo $acc['open_id']; ?></span>
                </li>
                <li class="list-group-item d-flex justify-content-between align-items-center">
                  <?php echo $this->lang->line("Expires"); ?>
                  <span class="text-muted"><?php echo $acc['expires_at'] ? date('M d, Y H:i', strtotime($acc['expires_at'])) : '-'; ?></span>
                </li>
              </ul>
            </div>
            <div class="card-footer bg-whitesmoke text-center">
              <a href="<?php echo base_url('tiktok_bot/campaigns?account_id='.$acc['id']); ?>" class="btn btn-primary btn-sm mr-1">
                <i class="fas fa-robot"></i> <?php echo $this->lang->line("Campaigns"); ?>
              </a>
              <a href="<?php echo base_url('tiktok_bot/reauthorize/'.$acc['id']); ?>" class="btn btn-warning btn-sm mr-1">
                <i class="fas fa-sync"></i> <?php echo $this->lang->line("Reconnect"); ?>
              </a>
              <a href="<?php echo base_url('tiktok_bot/delete_account_action/'.$acc['id']); ?>" class="btn btn-danger btn-sm" onclick="return confirm('<?php echo $this->lang->line("Delete this account?"); ?>');">
                <i class="fas fa-trash"></i> <?php echo $this->lang->line("Delete"); ?>
              </a>
            </div>
          </div>
        </div>
      <?php } } else { ?>
        <div class="col-12">
          <div class="card">
            <div class="card-body text-center">
              <h5 class="text-muted"><?php echo $this->lang->line("No TikTok accounts connected yet."); ?></h5>
              <a href="<?php echo base_url('tiktok_bot/connect'); ?>" class="btn btn-primary mt-3">
                <i class="fab fa-tiktok"></i> <?php echo $this->lang->line("Connect TikTok Account"); ?>
              </a>
            </div>
          </div>
        </div>
      <?php } ?>
    </div>
  </div>
</section>
