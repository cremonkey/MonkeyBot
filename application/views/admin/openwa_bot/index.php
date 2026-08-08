<section class="section">
  <div class="section-header">
    <h1><i class="fab fa-whatsapp"></i> <?php echo $page_title; ?></h1>
  </div>
  <?php $this->load->view('admin/theme/message'); ?>
  <div class="section-body">
    <div class="alert alert-light border">
      Connect OpenWA, then build bots with the <strong>same Visual Flow Builder</strong> and keyword settings used for Facebook &amp; Instagram (<code>media_type=wa</code>).
    </div>
    <?php
      $stale = false;
      if (!empty($accounts)) {
        foreach ($accounts as $_a) {
          if (isset($_a['owa_healthy']) && $_a['owa_healthy'] === false) { $stale = true; break; }
        }
      }
    ?>
    <?php if ($stale): ?>
    <div class="alert alert-warning">
      <strong>OpenWA session needs reconnect.</strong>
      The bot <em>is matching</em> keywords/flows, but WhatsApp outbound messages stay <code>pending</code> because the gateway session is stale.
      Open <a href="https://wa.cremonkey.com" target="_blank" rel="noopener">OpenWA dashboard</a>, scan QR again for session <code>012</code>, then retest with <code>hi</code>.
    </div>
    <?php endif; ?>
    <div class="row">
      <div class="col-lg-5">
        <div class="card">
          <div class="card-header"><h4>Connect OpenWA Session</h4></div>
          <form action="<?php echo base_url('openwa_bot/save'); ?>" method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo $this->session->userdata('csrf_token_session'); ?>">
            <div class="card-body">
              <div class="form-group"><label>Label</label><input name="label" class="form-control" placeholder="Creative Monkey WA"></div>
              <div class="form-group"><label>OpenWA Base URL</label><input name="base_url" class="form-control" value="<?php echo htmlspecialchars($default_base_url); ?>" required></div>
              <div class="form-group"><label>API Key</label><input name="api_key" class="form-control" type="password" autocomplete="off" required placeholder="API_MASTER_KEY or owa_k1_…"></div>
              <div class="form-group"><label>Session ID</label><input name="session_id" class="form-control" required placeholder="uuid from /api/sessions"></div>
              <div class="form-group">
                <label class="custom-switch mt-2">
                  <input type="checkbox" name="bot_enabled" value="1" class="custom-switch-input" checked>
                  <span class="custom-switch-indicator"></span><span class="custom-switch-description">Enable bot (flows + keywords + no-match)</span>
                </label>
              </div>
              <div class="form-group">
                <label class="custom-switch mt-2">
                  <input type="checkbox" name="ai_enabled" value="1" class="custom-switch-input" checked>
                  <span class="custom-switch-indicator"></span><span class="custom-switch-description">AI fallback if no flow/keyword matched</span>
                </label>
              </div>
            </div>
            <div class="card-footer">
              <button class="btn btn-success btn-block"><i class="fab fa-whatsapp"></i> Connect &amp; register webhook</button>
            </div>
          </form>
        </div>

        <?php if(!empty($accounts)): ?>
        <div class="card">
          <div class="card-header"><h4>Quick keyword reply <small class="text-muted">(optional shortcut)</small></h4></div>
          <form action="<?php echo base_url('openwa_bot/save_keyword_bot'); ?>" method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo $this->session->userdata('csrf_token_session'); ?>">
            <div class="card-body">
              <p class="text-muted small mb-3">For full flows and templates like Facebook/Instagram, use <strong>Open Flow Builder</strong> or <strong>Bot Settings</strong> on the right.</p>
              <div class="form-group">
                <label>Account</label>
                <select name="account_id" class="form-control" required>
                  <?php foreach($accounts as $a): ?>
                  <option value="<?php echo (int)$a['id']; ?>"><?php echo htmlspecialchars($a['label'].' ('.$a['display_phone'].')'); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="form-group"><label>Keywords (comma-separated)</label><input name="keywords" class="form-control" placeholder="hello, hi, مرحبا" required></div>
              <div class="form-group"><label>Reply text</label><textarea name="reply_text" class="form-control" rows="3" required></textarea></div>
            </div>
            <div class="card-footer"><button class="btn btn-outline-primary btn-block">Save quick keyword</button></div>
          </form>
        </div>
        <?php endif; ?>
      </div>

      <div class="col-lg-7">
        <div class="card">
          <div class="card-header">
            <h4>Connected sessions</h4>
            <div class="card-header-action">
              <a href="<?php echo base_url('visual_flow_builder/flowbuilder_manager?media_type=wa'); ?>" class="btn btn-primary btn-sm">
                <i class="fas fa-project-diagram"></i> All flows
              </a>
            </div>
          </div>
          <div class="card-body table-responsive">
            <table class="table">
              <thead>
                <tr>
                  <th>Label</th>
                  <th>Phone</th>
                  <th>Status</th>
                  <th>Customize (like FB/IG)</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                <?php if(!empty($accounts)): foreach($accounts as $a): ?>
                <tr>
                  <td>
                    <?php echo htmlspecialchars($a['label']); ?><br>
                    <small class="text-muted"><?php echo htmlspecialchars($a['session_id']); ?></small>
                    <?php if(!empty($a['openwa_webhook_id'])): ?>
                      <br><small class="text-success">Webhook linked</small>
                    <?php else: ?>
                      <br><small class="text-warning">Webhook: <code><?php echo $webhook_base.$a['id']; ?></code></small>
                    <?php endif; ?>
                  </td>
                  <td><?php echo htmlspecialchars($a['display_phone']); ?></td>
                  <td>
                    <?php echo $a['bot_enabled']=='1'?'<span class="badge badge-success">Bot</span>':'<span class="badge badge-secondary">Bot off</span>'; ?>
                    <?php echo $a['ai_enabled']=='1'?'<span class="badge badge-info">AI</span>':''; ?>
                    <?php if (isset($a['owa_healthy'])): ?>
                      <br>
                      <?php if ($a['owa_healthy']): ?>
                        <span class="badge badge-success mt-1">WA <?php echo htmlspecialchars($a['owa_status']); ?></span>
                      <?php else: ?>
                        <span class="badge badge-danger mt-1">WA stale/<?php echo htmlspecialchars($a['owa_status'] ?: 'unknown'); ?></span>
                        <?php if (!empty($a['owa_last_active'])): ?>
                          <br><small class="text-danger">Last active: <?php echo htmlspecialchars($a['owa_last_active']); ?></small>
                        <?php endif; ?>
                      <?php endif; ?>
                    <?php endif; ?>
                  </td>
                  <td style="min-width:220px">
                    <a class="btn btn-sm btn-primary mb-1" href="<?php echo base_url('visual_flow_builder/load_builder/'.$a['id'].'/1/wa'); ?>">
                      <i class="fas fa-project-diagram"></i> Open Flow Builder
                    </a><br>
                    <a class="btn btn-sm btn-outline-primary mb-1" href="<?php echo base_url('visual_flow_builder/flowbuilder_manager/'.$a['id'].'?media_type=wa'); ?>">
                      <i class="fas fa-list"></i> Manage Flows
                    </a><br>
                    <a class="btn btn-sm btn-outline-info mb-1" href="<?php echo base_url('messenger_bot/bot_settings/'.$a['id'].'/1?media_type=wa'); ?>">
                      <i class="fas fa-robot"></i> Bot Keyword Settings
                    </a><br>
                    <?php if (!empty($a['nomatch_bot_id'])): ?>
                    <a class="btn btn-sm btn-outline-warning mb-1" href="<?php echo base_url('messenger_bot/edit_bot/'.(int)$a['nomatch_bot_id'].'/1/nomatch/wa'); ?>">
                      <i class="fas fa-comment-slash"></i> Edit No Match Reply
                    </a>
                    <?php endif; ?>
                  </td>
                  <td>
                    <a href="<?php echo base_url('openwa_bot/delete/'.$a['id'].'?t='.$this->session->userdata('csrf_token_session')); ?>"
                       class="btn btn-sm btn-danger" onclick="return confirm('Remove OpenWA account and its WA bots?')">Delete</a>
                  </td>
                </tr>
                <?php endforeach; else: ?>
                <tr><td colspan="5" class="text-muted">No OpenWA sessions yet. Connect a session, then use Flow Builder like Facebook/Instagram.</td></tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>
