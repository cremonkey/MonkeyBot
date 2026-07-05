<section class="section">
  <div class="section-header"><h1><i class="fas fa-users"></i> Contacts</h1>
    <div class="section-header-breadcrumb"><a href="<?php echo base_url('crm'); ?>" class="btn btn-outline-primary">Dashboard</a></div>
  </div>
  <div class="section-body">
    <div class="card"><div class="card-body">
      <table class="table table-striped" id="crmContacts">
        <thead><tr><th>Name</th><th>Channel</th><th>Email</th><th>Phone</th><th>Lead Score</th><th></th></tr></thead>
        <tbody></tbody>
      </table>
    </div></div>
  </div>
</section>
<script>
var CRM_TOKEN="<?php echo $this->session->userdata('csrf_token_session'); ?>", CRM_BASE="<?php echo base_url(); ?>";
function esc(s){return (s||'').toString().replace(/&/g,'&amp;').replace(/</g,'&lt;');}
function band(sc){sc=+sc||0;return sc>=50?'badge-danger':(sc>=20?'badge-warning':'badge-secondary');}
$(function(){
  $.getJSON(CRM_BASE+'crm/contacts_data',function(r){
    var tb='';(r.data||[]).forEach(function(c){
      var name=esc(c.full_name|| ((c.first_name||'')+' '+(c.last_name||'')));
      tb+='<tr><td>'+name+'</td><td>'+esc(c.social_media)+'</td><td>'+esc(c.email)+'</td><td>'+esc(c.phone_number)+'</td>'+
          '<td><span class="badge '+band(c.lead_score)+'">'+(+c.lead_score||0)+'</span></td>'+
          '<td><button class="btn btn-sm btn-outline-primary crm-mkdeal" data-id="'+c.id+'">Create Deal</button></td></tr>';
    });
    $('#crmContacts tbody').html(tb);
    if($.fn.dataTable) $('#crmContacts').DataTable({order:[[4,'desc']]});
  });
  $(document).on('click','.crm-mkdeal',function(){
    $.post(CRM_BASE+'crm/create_deal_from_contact',{csrf_token:CRM_TOKEN,subscriber_id:$(this).data('id')},function(r){
      if(r.status=='1') window.location=CRM_BASE+'crm/deal_detail/'+r.id;
    },'json');
  });
});
</script>
