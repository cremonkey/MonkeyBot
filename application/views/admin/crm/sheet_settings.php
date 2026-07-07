<section class="section">
  <div class="section-header"><h1><i class="fas fa-file-excel"></i> <?php echo $page_title; ?></h1>
    <div class="section-header-breadcrumb">
      <a href="<?php echo base_url('crm'); ?>" class="btn btn-outline-primary"><i class="fas fa-funnel-dollar"></i> Dashboard</a>
      <a href="<?php echo base_url('crm/pipeline'); ?>" class="btn btn-primary"><i class="fas fa-columns"></i> Pipeline</a>
    </div>
  </div>
  <div class="section-body">
    <div class="row">
      <div class="col-lg-7">
        <div class="card">
          <div class="card-header"><h4>Google Sheet Connection</h4></div>
          <div class="card-body">
            <form id="sheet_settings_form">
              <div class="form-group">
                <label>Service Account JSON key <?php if(!empty($sa_email)): ?><span class="badge badge-success">saved: <?php echo htmlspecialchars($sa_email); ?></span><?php endif; ?></label>
                <textarea name="service_account_json" class="form-control" rows="6" placeholder='<?php echo !empty($sa_email) ? "Leave empty to keep the saved key" : '{"type":"service_account","client_email":"...","private_key":"..."}'; ?>'></textarea>
                <small class="form-text text-muted">Paste the JSON key file of a Google Cloud service account (see steps on the right).</small>
              </div>
              <div class="form-group">
                <label>Spreadsheet URL or ID <span class="text-danger">*</span></label>
                <input type="text" name="spreadsheet_id" class="form-control" value="<?php echo htmlspecialchars($config['spreadsheet_id'] ?? ''); ?>" placeholder="https://docs.google.com/spreadsheets/d/....">
              </div>
              <div class="form-row">
                <div class="form-group col-md-6">
                  <label>Sheet tab name</label>
                  <input type="text" name="sheet_tab" class="form-control" value="<?php echo htmlspecialchars($config['sheet_tab'] ?? 'Sheet1'); ?>">
                  <small class="form-text text-muted">Auto-corrected on Test if the tab doesn't exist.</small>
                </div>
                <div class="form-group col-md-6">
                  <label>Sync status</label>
                  <select name="status" class="form-control">
                    <option value="1" <?php if(($config['status'] ?? '1')==='1') echo 'selected'; ?>>Enabled</option>
                    <option value="0" <?php if(($config['status'] ?? '1')==='0') echo 'selected'; ?>>Disabled</option>
                  </select>
                </div>
              </div>
              <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save</button>
              <button type="button" id="sheet_test_btn" class="btn btn-info"><i class="fas fa-plug"></i> Test Connection</button>
              <button type="button" id="sheet_backfill_btn" class="btn btn-outline-secondary"><i class="fas fa-history"></i> Export Existing Deals</button>
            </form>
            <div id="sheet_result" class="mt-3"></div>
            <?php if(!empty($config['last_error'])): ?>
              <div class="alert alert-warning mt-3"><strong>Last sync error:</strong> <?php echo htmlspecialchars($config['last_error']); ?></div>
            <?php endif; ?>
            <?php if(!empty($config['last_synced_at'])): ?>
              <small class="text-muted">Last successful sync: <?php echo $config['last_synced_at']; ?></small>
            <?php endif; ?>
          </div>
        </div>
      </div>
      <div class="col-lg-5">
        <div class="card">
          <div class="card-header"><h4>How to set it up (once)</h4></div>
          <div class="card-body">
            <ol style="padding-left:18px">
              <li>Go to <a href="https://console.cloud.google.com/" target="_blank" rel="noopener">Google Cloud Console</a> and create (or pick) a project.</li>
              <li>Enable the <strong>Google Sheets API</strong> (APIs &amp; Services &rarr; Library).</li>
              <li>Create a <strong>Service Account</strong> (IAM &amp; Admin &rarr; Service Accounts) &mdash; no roles needed.</li>
              <li>Open the service account &rarr; <strong>Keys</strong> &rarr; Add key &rarr; JSON. Download it and paste its content here.</li>
              <li>Create a Google Sheet, click <strong>Share</strong>, and add the service account email (<code>...@...iam.gserviceaccount.com</code>) as <strong>Editor</strong>.</li>
              <li>Paste the sheet URL here, Save, then <strong>Test Connection</strong>.</li>
            </ol>
            <p class="text-muted mb-0">After that, every lead the AI captures (phone/email + the customer's request) is appended to the sheet automatically: <em>Date, Channel, Name, Phone, Email, Request, Deal #, Status</em>.</p>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>
<script>
var CRM_TOKEN="<?php echo $this->session->userdata('csrf_token_session'); ?>", CRM_BASE="<?php echo base_url(); ?>";
function sheetMsg(ok, msg){ $('#sheet_result').html('<div class="alert alert-'+(ok?'success':'danger')+'">'+$('<span>').text(msg).html()+'</div>'); }
$('#sheet_settings_form').on('submit', function(e){
  e.preventDefault();
  var data = $(this).serializeArray(); data.push({name:'csrf_token', value:CRM_TOKEN});
  $.post(CRM_BASE+'crm/sheet_settings_save', $.param(data), function(r){ sheetMsg(r.status=='1', r.message); }, 'json');
});
$('#sheet_test_btn').on('click', function(){
  var btn=$(this).prop('disabled',true);
  $.post(CRM_BASE+'crm/sheet_test', {csrf_token:CRM_TOKEN}, function(r){ sheetMsg(r.status=='1', r.message); }, 'json').always(function(){ btn.prop('disabled',false); });
});
$('#sheet_backfill_btn').on('click', function(){
  if(!confirm('Export all existing CRM deals to the sheet? Rows are appended (duplicates are possible if you run it twice).')) return;
  var btn=$(this).prop('disabled',true);
  $.post(CRM_BASE+'crm/sheet_backfill', {csrf_token:CRM_TOKEN}, function(r){ sheetMsg(r.status=='1', r.message); }, 'json').always(function(){ btn.prop('disabled',false); });
});
</script>
