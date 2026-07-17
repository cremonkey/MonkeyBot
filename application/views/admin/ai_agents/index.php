<section class="section">
  <div class="section-header"><h1><i class="fas fa-user-astronaut"></i> <?php echo $page_title; ?></h1>
    <div class="section-header-breadcrumb"><a href="<?php echo base_url('integration/open_ai_api_credentials'); ?>" class="btn btn-outline-primary"><i class="fas fa-key"></i> AI Credentials (key)</a></div>
  </div>
  <?php $this->load->view('admin/theme/message'); $tk=$this->session->userdata('csrf_token_session'); ?>
  <div class="section-body">
    <p class="text-muted">Create distinct agent identities (brand voice) and assign each to a page or channel. The API key &amp; provider come from <b>AI Credentials</b>; profiles override only the persona &amp; behavior.</p>
    <ul class="nav nav-tabs" role="tablist">
      <li class="nav-item"><a class="nav-link active" data-toggle="tab" href="#tab-profiles">Profiles</a></li>
      <li class="nav-item"><a class="nav-link" data-toggle="tab" href="#tab-assign">Channel Assignments</a></li>
    </ul>
    <div class="tab-content pt-3">

      <div class="tab-pane fade show active" id="tab-profiles">
        <div class="row">
          <div class="col-lg-5">
            <div class="card"><div class="card-header"><h4 id="form-title">New Agent Profile</h4></div>
              <form action="<?php echo base_url('ai_agents/save_profile'); ?>" method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $tk; ?>">
                <input type="hidden" name="id" id="p_id" value="">
                <div class="card-body">
                  <div class="alert alert-info py-2 px-3 mb-3" style="font-size:13px;">
                    <i class="fas fa-shield-alt"></i> The <strong>Master Rules</strong> in
                    <a href="<?php echo base_url('integration/open_ai_api_credentials'); ?>">Integration &rarr; Sales System Prompt</a>
                    apply to <strong>every page and every agent</strong> and are always sent first.
                    Everything below <em>adds</em> the business details this agent speaks from &mdash; it can never replace or disable the master rules.
                  </div>
                  <div class="form-group"><label>Profile name (internal)</label><input name="name" id="p_name" class="form-control" required placeholder="e.g. Brand A - Sales"></div>
                  <div class="form-group"><label>Agent name <small class="text-muted">(sent to the AI as its identity)</small></label><input name="agent_name" id="p_agent_name" class="form-control" placeholder="e.g. Sara from Brand A"></div>
                  <div class="form-group"><label>Instruction / persona</label><textarea name="instruction_to_ai" id="p_instruction" class="form-control" rows="3"></textarea></div>
                  <div class="form-group">
                    <label class="custom-switch mt-2"><input type="checkbox" name="sales_mode_enabled" id="p_sales" value="1" class="custom-switch-input" checked><span class="custom-switch-indicator"></span><span class="custom-switch-description">Sales Mode</span></label>
                    <small class="d-block text-muted">Tunes selling behaviour (closing push + playbook stages). The master rules and the price/scope guardrails apply either way.</small>
                  </div>
                  <div class="form-group"><label>Agent prompt <small class="text-muted">(this business's details, offers and prices &mdash; added to the master rules)</small></label><textarea name="sales_system_prompt" id="p_salesprompt" class="form-control" rows="6"></textarea></div>
                  <div class="row">
                    <div class="col-6 form-group"><label>Model override <small class="text-muted">(optional)</small></label><input name="model" id="p_model" class="form-control" placeholder="blank = account default"></div>
                    <div class="col-6 form-group"><label>Temperature</label><input name="temperature" id="p_temp" type="number" step="0.1" min="0" max="2" class="form-control" value="0.7"></div>
                    <div class="col-6 form-group"><label>History msgs</label><input name="max_history_messages" id="p_hist" type="number" min="1" max="20" class="form-control" value="6"></div>
                    <div class="col-6 form-group"><label>Memory TTL (h)</label><input name="memory_ttl_hours" id="p_ttl" type="number" min="1" class="form-control" value="24"></div>
                  </div>
                  <label class="custom-switch mt-1"><input type="checkbox" name="auto_language" id="p_lang" value="1" class="custom-switch-input" checked><span class="custom-switch-indicator"></span><span class="custom-switch-description">Auto language</span></label>
                  <label class="custom-switch mt-1"><input type="checkbox" name="sentiment_enabled" id="p_sent" value="1" class="custom-switch-input"><span class="custom-switch-indicator"></span><span class="custom-switch-description">Sentiment</span></label>
                  <label class="custom-switch mt-1"><input type="checkbox" name="ai_tools_enabled" id="p_tools" value="1" class="custom-switch-input"><span class="custom-switch-indicator"></span><span class="custom-switch-description">AI actions (tools)</span></label>
                </div>
                <div class="card-footer">
                  <button class="btn btn-primary"><i class="fas fa-save"></i> Save Profile</button>
                  <button type="button" class="btn btn-light" onclick="crmResetForm()">Clear</button>
                  <small class="d-block text-muted mt-1">Saving also updates this agent's Messenger/Instagram templates automatically.</small>
                </div>
              </form>
              <div class="card-body border-top">
                <label><i class="fas fa-vial"></i> Test before saving</label>
                <div class="input-group">
                  <input id="p_test_msg" class="form-control" placeholder="Type a customer message, e.g. الداي يوز بكام؟">
                  <div class="input-group-append"><button type="button" id="p_test_btn" class="btn btn-info">Test</button></div>
                </div>
                <div id="p_test_out" class="mt-2" style="font-size:13px;white-space:pre-line;"></div>
                <small class="text-muted">Runs the currently typed prompt against the model — no save, no history. A quick preview of the brand voice and answers.</small>
              </div>
            </div>
          </div>
          <div class="col-lg-7">
            <div class="card"><div class="card-header"><h4>Your Agent Profiles</h4></div><div class="card-body">
              <table class="table"><thead><tr><th>Name</th><th>Agent</th><th>Mode</th><th></th></tr></thead><tbody>
                <?php if(!empty($profiles)): foreach($profiles as $p): ?>
                <tr>
                  <td><?php echo htmlspecialchars($p['name']); ?></td>
                  <td><?php echo htmlspecialchars($p['agent_name']); ?></td>
                  <td><?php echo $p['sales_mode_enabled']=='1'?'<span class="badge badge-success">Sales</span>':'<span class="badge badge-secondary">Chat</span>'; ?></td>
                  <td>
                    <?php // JSON must be HTML-encoded: a raw apostrophe in any field ends the attribute and kills the handler
                          $p_json = htmlspecialchars(json_encode($p), ENT_QUOTES, 'UTF-8'); ?>
                    <button class="btn btn-sm btn-outline-primary" onclick="crmEdit(<?php echo $p_json; ?>,false)">Edit</button>
                    <button class="btn btn-sm btn-outline-info" onclick="crmEdit(<?php echo $p_json; ?>,true)">Copy</button>
                    <a class="btn btn-sm btn-outline-danger" href="<?php echo base_url('ai_agents/delete_profile/'.$p['id'].'?t='.$tk); ?>" onclick="return confirm('Delete profile?')">×</a>
                  </td>
                </tr>
                <?php endforeach; else: ?><tr><td colspan="4" class="text-muted">No profiles yet. Create your first agent identity.</td></tr><?php endif; ?>
              </tbody></table>
            </div></div>
          </div>
        </div>
      </div>

      <div class="tab-pane fade" id="tab-assign">
        <div class="card"><div class="card-header"><h4>Assign a profile to each channel</h4></div>
          <form action="<?php echo base_url('ai_agents/save_assignments'); ?>" method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo $tk; ?>">
            <div class="card-body">
              <?php if(empty($channels)): ?>
                <p class="text-muted">No channels connected yet. Connect a Facebook/Instagram page, WhatsApp, Telegram, or Web Chat first.</p>
              <?php else: ?>
              <table class="table">
                <thead><tr><th>Channel</th><th>Type</th><th>Agent Profile</th></tr></thead>
                <tbody>
                <?php foreach($channels as $c): $key=$c['channel'].'|'.$c['target']; $cur=$assign_map[$key] ?? ''; ?>
                  <tr>
                    <td><i class="<?php echo $c['icon']; ?>"></i> <?php echo htmlspecialchars($c['label']); ?></td>
                    <td><small class="text-muted"><?php echo htmlspecialchars($c['note']); ?></small></td>
                    <td>
                      <select name="assign[<?php echo $key; ?>]" class="form-control">
                        <option value="">— Account default —</option>
                        <?php foreach($profiles as $p): ?>
                          <option value="<?php echo $p['id']; ?>" <?php echo $cur==$p['id']?'selected':''; ?>><?php echo htmlspecialchars($p['name']); ?></option>
                        <?php endforeach; ?>
                      </select>
                    </td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
              <?php endif; ?>
            </div>
            <?php if(!empty($channels)): ?><div class="card-footer"><button class="btn btn-primary"><i class="fas fa-save"></i> Save Assignments</button></div><?php endif; ?>
          </form>
        </div>
      </div>

    </div>
  </div>
</section>
<script>
function crmResetForm(){document.getElementById('p_id').value='';document.getElementById('form-title').textContent='New Agent Profile';
  ['p_name','p_agent_name','p_instruction','p_salesprompt','p_model'].forEach(function(i){document.getElementById(i).value='';});
  document.getElementById('p_temp').value='0.7';document.getElementById('p_hist').value='6';document.getElementById('p_ttl').value='24';
  document.getElementById('p_sales').checked=true;document.getElementById('p_lang').checked=true;document.getElementById('p_sent').checked=false;document.getElementById('p_tools').checked=false;}
function crmEdit(p,copy){
  document.getElementById('p_id').value=copy?'':p.id;
  document.getElementById('form-title').textContent=copy?'Copy of '+(p.name||''):'Edit: '+(p.name||'');
  document.getElementById('p_name').value=(copy?'Copy of ':'')+(p.name||'');
  document.getElementById('p_agent_name').value=p.agent_name||'';
  document.getElementById('p_instruction').value=p.instruction_to_ai||'';
  document.getElementById('p_salesprompt').value=p.sales_system_prompt||'';
  document.getElementById('p_model').value=p.model||'';
  document.getElementById('p_temp').value=p.temperature||'0.7';
  document.getElementById('p_hist').value=p.max_history_messages||'6';
  document.getElementById('p_ttl').value=p.memory_ttl_hours||'24';
  document.getElementById('p_sales').checked=p.sales_mode_enabled=='1';
  document.getElementById('p_lang').checked=p.auto_language=='1';
  document.getElementById('p_sent').checked=p.sentiment_enabled=='1';
  document.getElementById('p_tools').checked=p.ai_tools_enabled=='1';
  window.scrollTo(0,0);
}
document.getElementById('p_test_btn').addEventListener('click',function(){
  var out=document.getElementById('p_test_out'), b=this;
  var msg=document.getElementById('p_test_msg').value.trim();
  if(!msg){out.textContent='Type a test message first.';return;}
  b.disabled=true; out.textContent='Thinking…';
  var fd=new FormData();
  fd.append('csrf_token','<?php echo $tk; ?>');
  fd.append('message',msg);
  fd.append('agent_name',document.getElementById('p_agent_name').value);
  fd.append('instruction_to_ai',document.getElementById('p_instruction').value);
  fd.append('sales_system_prompt',document.getElementById('p_salesprompt').value);
  fetch('<?php echo base_url('ai_agents/preview'); ?>',{method:'POST',body:fd}).then(r=>r.json()).then(function(r){
    b.disabled=false;
    if(r.status=='1'){out.innerHTML='<b>Bot:</b> '+(r.reply||'').replace(/</g,'&lt;')+'<br><small class="text-muted">prompt '+r.chars+' chars</small>';}
    else{out.textContent=r.message||'Preview failed';}
  }).catch(function(){b.disabled=false;out.textContent='Preview failed';});
});
</script>
