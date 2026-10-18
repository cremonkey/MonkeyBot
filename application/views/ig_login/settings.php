<?php $e = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }; $L = function ($k) { return htmlspecialchars((string) $this->lang->line($k), ENT_QUOTES, 'UTF-8'); }; ?>
<section class="section section_custom">
  <div class="section-header"><h1><i class="fab fa-instagram"></i> <?php echo $L('Instagram Login'); ?></h1></div>
  <div class="section-body">
    <?php if ($saved): ?><div class="alert alert-success"><?php echo $e($saved); ?></div><?php endif; ?>
    <div class="row">
      <div class="col-12 col-lg-6"><div class="card"><div class="card-header"><h4><?php echo $L('Instagram app'); ?></h4></div><div class="card-body">
        <form method="post">
          <div class="form-group"><label><?php echo $L('Instagram app ID'); ?></label><input class="form-control" name="app_id" value="<?php echo $e($row['app_id'] ?? ''); ?>" inputmode="numeric"></div>
          <div class="form-group"><label><?php echo $L('Instagram app secret'); ?></label><input class="form-control" type="password" name="app_secret" autocomplete="off" placeholder="<?php echo !empty($row['app_secret_enc']) ? $L('Saved. Leave blank to keep it') : ''; ?>"></div>
          <div class="form-group"><label><input type="checkbox" name="enabled" value="1" <?php echo !empty($row['enabled']) ? 'checked' : ''; ?>> <?php echo $L('Show "Connect with Instagram" to users'); ?></label></div>
          <button class="btn btn-primary"><i class="fas fa-save"></i> <?php echo $L('Save'); ?></button>
        </form>
      </div></div></div>
      <div class="col-12 col-lg-6"><div class="card"><div class="card-header"><h4><?php echo $L('Paste these into the Meta app'); ?></h4></div><div class="card-body">
        <p class="text-muted"><?php echo $L('Meta app → Instagram → API setup with Instagram login.'); ?></p>
        <div class="form-group"><label><?php echo $L('OAuth redirect URI'); ?></label><input class="form-control" readonly value="<?php echo $e($redirect_uri); ?>" onclick="this.select()"></div>
        <div class="form-group"><label><?php echo $L('Webhook callback URL'); ?></label><input class="form-control" readonly value="<?php echo $e($webhook_url); ?>" onclick="this.select()"><small class="text-muted"><?php echo $L('Use the same verify token as the Facebook app webhook.'); ?></small></div>
        <div class="form-group"><label><?php echo $L('Permissions'); ?></label><input class="form-control" readonly value="<?php echo $e($scopes); ?>"></div>
        <div class="form-group"><label><?php echo $L('Webhook fields'); ?></label><input class="form-control" readonly value="<?php echo $e($fields); ?>"></div>
      </div></div></div>
    </div>
  </div>
</section>
