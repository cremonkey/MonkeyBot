<section class="section">
  <div class="section-header"><h1><i class="fas fa-columns"></i> Pipeline</h1>
    <div class="section-header-breadcrumb">
      <button class="btn btn-primary" onclick="crmNewDeal()"><i class="fas fa-plus"></i> New Deal</button>
      <a href="<?php echo base_url('crm'); ?>" class="btn btn-outline-primary">Dashboard</a>
    </div>
  </div>
  <div class="section-body">
    <div style="display:flex;gap:12px;overflow-x:auto;padding-bottom:10px">
      <?php foreach($stages as $s): ?>
      <div style="min-width:260px;flex:0 0 260px" data-stage="<?php echo $s['id']; ?>">
        <div style="border-top:3px solid <?php echo $s['color']; ?>;background:#f8f9fa;border-radius:8px;padding:10px;min-height:400px">
          <h6 style="font-weight:700"><?php echo htmlspecialchars($s['name']); ?> <span class="badge badge-light"><?php echo count($s['deals']); ?></span></h6>
          <div class="crm-col" data-stage="<?php echo $s['id']; ?>">
            <?php foreach($s['deals'] as $d): ?>
            <div class="card crm-deal" draggable="true" data-id="<?php echo $d['id']; ?>" style="margin-bottom:8px;cursor:grab">
              <div class="card-body p-2">
                <a href="<?php echo base_url('crm/deal_detail/'.$d['id']); ?>" style="font-weight:600"><?php echo htmlspecialchars($d['title']); ?></a>
                <div class="text-muted small"><?php echo htmlspecialchars($d['contact_name']); ?></div>
                <div class="small"><b><?php echo number_format($d['value'],2).' '.htmlspecialchars($d['currency']); ?></b> <span class="badge badge-light float-right"><?php echo htmlspecialchars($d['source']); ?></span></div>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<div class="modal fade" id="dealModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
  <div class="modal-header"><h5>New Deal</h5><button class="close" data-dismiss="modal">&times;</button></div>
  <div class="modal-body">
    <input type="hidden" id="d_id">
    <div class="form-group"><label>Title</label><input id="d_title" class="form-control"></div>
    <div class="form-group"><label>Stage</label><select id="d_stage" class="form-control"><?php foreach($stages as $s): ?><option value="<?php echo $s['id']; ?>"><?php echo htmlspecialchars($s['name']); ?></option><?php endforeach; ?></select></div>
    <div class="row"><div class="col-8 form-group"><label>Value</label><input id="d_value" type="number" step="0.01" class="form-control" value="0"></div><div class="col-4 form-group"><label>Currency</label><input id="d_currency" class="form-control" value="USD"></div></div>
    <div class="form-group"><label>Contact Name</label><input id="d_cname" class="form-control"></div>
    <div class="row"><div class="col-6 form-group"><label>Email</label><input id="d_cemail" class="form-control"></div><div class="col-6 form-group"><label>Phone</label><input id="d_cphone" class="form-control"></div></div>
  </div>
  <div class="modal-footer"><button class="btn btn-primary" onclick="crmSaveDeal()">Save</button></div>
</div></div></div>

<script>
var CRM_TOKEN="<?php echo $this->session->userdata('csrf_token_session'); ?>", CRM_BASE="<?php echo base_url(); ?>";
function crmNewDeal(){$('#d_id').val('');$('#d_title').val('');$('#d_value').val(0);$('#d_cname,#d_cemail,#d_cphone').val('');$('#dealModal').modal('show');}
function crmSaveDeal(){
  $.post(CRM_BASE+'crm/deal_save',{csrf_token:CRM_TOKEN,id:$('#d_id').val(),title:$('#d_title').val(),stage_id:$('#d_stage').val(),value:$('#d_value').val(),currency:$('#d_currency').val(),contact_name:$('#d_cname').val(),contact_email:$('#d_cemail').val(),contact_phone:$('#d_cphone').val()},function(){location.reload();},'json');
}
var dragId=null;
document.addEventListener('dragstart',function(e){if(e.target.classList.contains('crm-deal'))dragId=e.target.dataset.id;});
document.querySelectorAll('.crm-col').forEach(function(col){
  col.addEventListener('dragover',function(e){e.preventDefault();});
  col.addEventListener('drop',function(e){e.preventDefault();if(!dragId)return;var stage=col.dataset.stage;
    $.post(CRM_BASE+'crm/deal_move',{csrf_token:CRM_TOKEN,id:dragId,stage_id:stage},function(){location.reload();},'json');});
});
</script>
