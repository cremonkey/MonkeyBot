<section class="section">
  <div class="section-header"><h1><i class="fas fa-robot"></i> <?php echo $page_title; ?></h1>
    <div class="section-header-breadcrumb">
      <a href="<?php echo base_url('crm'); ?>" class="btn btn-outline-primary"><i class="fas fa-funnel-dollar"></i> CRM</a>
      <button id="run_now" class="btn btn-info"><i class="fas fa-play"></i> Run now (test)</button>
    </div>
  </div>
  <div class="section-body">
    <form id="sa_form">
      <div class="row">
        <div class="col-lg-6">
          <div class="card">
            <div class="card-header"><h4><i class="fas fa-reply"></i> Silent-lead follow-up</h4></div>
            <div class="card-body">
              <label class="custom-switch"><input type="checkbox" name="followup_enabled" value="1" class="custom-switch-input" <?php if(($config['followup_enabled'] ?? '0')==='1') echo 'checked'; ?>><span class="custom-switch-indicator"></span><span class="custom-switch-description">Enabled</span></label>
              <div class="form-group mt-3"><label>Follow up after (minutes of silence)</label>
                <input type="number" name="followup_delay_minutes" min="15" class="form-control" value="<?php echo (int)($config['followup_delay_minutes'] ?? 180); ?>">
                <small class="form-text text-muted">One message per conversation, only inside Meta's 24-hour messaging window. Skips paused chats, captured leads, and TikTok (no DM API).</small>
              </div>
              <div class="form-group"><label>Message (Arabic customers) <small class="text-muted">blank = default</small></label>
                <textarea name="followup_message_ar" class="form-control" rows="2" placeholder="لسه معاك 😊 لو حابب نكمل على استفسارك أنا موجود، وتحب أخلي الفريق يتواصل معاك على الواتساب؟"><?php echo htmlspecialchars($config['followup_message_ar'] ?? ''); ?></textarea>
              </div>
              <div class="form-group"><label>Message (English customers) <small class="text-muted">blank = default</small></label>
                <textarea name="followup_message_en" class="form-control" rows="2" placeholder="Still here for you 😊 Want to pick up where we left off?"><?php echo htmlspecialchars($config['followup_message_en'] ?? ''); ?></textarea>
              </div>
            </div>
          </div>
        </div>
        <div class="col-lg-6">
          <div class="card">
            <div class="card-header"><h4><i class="fas fa-envelope-open-text"></i> Daily digest</h4></div>
            <div class="card-body">
              <label class="custom-switch"><input type="checkbox" name="digest_enabled" value="1" class="custom-switch-input" <?php if(($config['digest_enabled'] ?? '0')==='1') echo 'checked'; ?>><span class="custom-switch-indicator"></span><span class="custom-switch-description">Enabled</span></label>
              <div class="form-row mt-3">
                <div class="form-group col-md-8"><label>Email</label>
                  <input type="email" name="digest_email" class="form-control" value="<?php echo htmlspecialchars($config['digest_email'] ?? ''); ?>" placeholder="you@company.com"></div>
                <div class="form-group col-md-4"><label>Send at (hour 0-23)</label>
                  <input type="number" name="digest_hour" min="0" max="23" class="form-control" value="<?php echo (int)($config['digest_hour'] ?? 8); ?>"></div>
              </div>
              <div class="form-group"><label>WhatsApp number <small class="text-muted">(needs a connected WhatsApp account)</small></label>
                <input type="text" name="digest_whatsapp" class="form-control" value="<?php echo htmlspecialchars($config['digest_whatsapp'] ?? ''); ?>" placeholder="2010xxxxxxxx"></div>
              <p class="text-muted mb-0">The digest includes: conversations by platform, new leads, follow-ups sent, tasks due today, and unanswered questions — once per day.</p>
            </div>
          </div>
          <div class="card">
            <div class="card-header"><h4><i class="fas fa-bell"></i> Gap alerts</h4></div>
            <div class="card-body">
              <label class="custom-switch"><input type="checkbox" name="deflect_alert_enabled" value="1" class="custom-switch-input" <?php if(($config['deflect_alert_enabled'] ?? '0')==='1') echo 'checked'; ?>><span class="custom-switch-indicator"></span><span class="custom-switch-description">Enabled</span></label>
              <div class="form-row mt-3">
                <div class="form-group col-md-8"><label>Email</label>
                  <input type="email" name="deflect_alert_email" class="form-control" value="<?php echo htmlspecialchars($config['deflect_alert_email'] ?? ''); ?>" placeholder="you@company.com"></div>
                <div class="form-group col-md-4"><label>Alert after N misses</label>
                  <input type="number" name="deflect_alert_threshold" min="2" max="50" class="form-control" value="<?php echo (int)($config['deflect_alert_threshold'] ?? 3); ?>"></div>
              </div>
              <div class="form-group"><label>WhatsApp number <small class="text-muted">(needs a connected WhatsApp account)</small></label>
                <input type="text" name="deflect_alert_whatsapp" class="form-control" value="<?php echo htmlspecialchars($config['deflect_alert_whatsapp'] ?? ''); ?>" placeholder="2010xxxxxxxx"></div>
              <p class="text-muted mb-0">When the bot can't answer the SAME question this many times, you get one alert with the question and a link to answer it. One alert per gap — no repeats.</p>
            </div>
          </div>
        </div>
      </div>
      <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Settings</button>
      <span id="sa_result" class="ml-3"></span>
    </form>

    <div class="card mt-4">
      <div class="card-header"><h4>Recent follow-ups</h4></div>
      <div class="card-body p-0">
        <?php if(!empty($recent)): ?>
        <div class="table-responsive"><table class="table table-striped mb-0">
          <thead><tr><th>When</th><th>Channel</th><th>Customer</th><th>Message</th><th>Status</th></tr></thead>
          <tbody>
          <?php foreach($recent as $f): ?>
            <tr>
              <td><small><?php echo $f['created_at']; ?></small></td>
              <td><span class="badge badge-primary"><?php echo htmlspecialchars($f['social_media']); ?></span></td>
              <td><small><?php echo htmlspecialchars($f['subscribe_id']); ?></small></td>
              <td style="max-width:380px"><small><?php echo htmlspecialchars((string)$f['message']); ?></small></td>
              <td><?php echo $f['status']==='sent' ? '<span class="badge badge-success">sent</span>' : '<span class="badge badge-danger" title="'.htmlspecialchars((string)$f['error']).'">failed</span>'; ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table></div>
        <?php else: ?><p class="text-muted" style="padding:20px">No follow-ups sent yet.</p><?php endif; ?>
      </div>
    </div>
  </div>
</section>
<script>
var SA_TOKEN="<?php echo $this->session->userdata('csrf_token_session'); ?>", SA_BASE="<?php echo base_url(); ?>";
function saMsg(ok,m){ $('#sa_result').html('<span class="badge badge-'+(ok?'success':'danger')+'">'+$('<i>').text(m).html()+'</span>'); }
$('#sa_form').on('submit', function(e){
  e.preventDefault();
  var d=$(this).serializeArray(); d.push({name:'csrf_token',value:SA_TOKEN});
  $.post(SA_BASE+'sales_automation/save', $.param(d), function(r){ saMsg(r.status=='1', r.message); }, 'json');
});
$('#run_now').on('click', function(){
  var b=$(this).prop('disabled',true);
  $.post(SA_BASE+'sales_automation/run_now', {csrf_token:SA_TOKEN}, function(r){ saMsg(r.status=='1', r.message); setTimeout(function(){location.reload();},1500); }, 'json').always(function(){ b.prop('disabled',false); });
});
</script>
