<section class="section">
  <div class="section-header"><h1><i class="fas fa-tasks"></i> <?php echo $page_title; ?></h1>
    <div class="section-header-breadcrumb">
      <a href="<?php echo base_url('crm'); ?>" class="btn btn-outline-primary"><i class="fas fa-funnel-dollar"></i> Dashboard</a>
      <a href="<?php echo base_url('crm/pipeline'); ?>" class="btn btn-primary"><i class="fas fa-columns"></i> Pipeline</a>
    </div>
  </div>
  <div class="section-body">
    <div class="card">
      <div class="card-header"><h4>Pending Tasks <span class="badge badge-warning"><?php echo count($tasks); ?></span></h4></div>
      <div class="card-body p-0">
        <?php if(!empty($tasks)): ?>
        <div class="table-responsive"><table class="table table-striped mb-0">
          <thead><tr><th>Task</th><th>Customer</th><th>Phone</th><th>Email</th><th>Source</th><th>Due</th><th></th></tr></thead>
          <tbody>
          <?php foreach($tasks as $t): ?>
            <tr id="task_row_<?php echo $t['id']; ?>" <?php if(strpos((string)$t['subject'],'URGENT') === 0): ?>style="background:#fff5f5"<?php endif; ?>>
              <td style="max-width:380px">
                <div style="font-weight:600"><?php if(strpos((string)$t['subject'],'URGENT') === 0): ?><span class="badge badge-danger">URGENT</span> <?php endif; ?><?php echo htmlspecialchars($t['subject']); ?></div>
                <small class="text-muted" style="white-space:pre-line"><?php echo htmlspecialchars((string)$t['description']); ?></small>
              </td>
              <td>
                <?php if($t['deal_id']): ?><a href="<?php echo base_url('crm/deal_detail/'.$t['deal_id']); ?>" style="font-weight:600"><?php echo htmlspecialchars((string)$t['customer_name']); ?></a>
                <?php else: ?><?php echo htmlspecialchars((string)$t['customer_name']); ?><?php endif; ?>
              </td>
              <td><?php echo htmlspecialchars((string)$t['customer_phone']); ?></td>
              <td><?php echo htmlspecialchars((string)$t['customer_email']); ?></td>
              <td><?php if($t['source']): ?><span class="badge badge-primary"><?php echo htmlspecialchars((string)$t['source']); ?></span><?php endif; ?></td>
              <td><small class="text-muted"><?php echo $t['due_date']; ?></small></td>
              <td><button class="btn btn-success btn-sm task_done" data-id="<?php echo $t['id']; ?>"><i class="fas fa-check"></i> Done</button></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table></div>
        <?php else: ?><p class="text-muted" style="padding:20px">No pending tasks. 🎉</p><?php endif; ?>
      </div>
    </div>
  </div>
</section>
<script>
var CRM_TOKEN="<?php echo $this->session->userdata('csrf_token_session'); ?>", CRM_BASE="<?php echo base_url(); ?>";
$(document).on('click','.task_done',function(){
  var id=$(this).data('id');
  $.post(CRM_BASE+'crm/task_complete',{csrf_token:CRM_TOKEN,id:id},function(r){
    if(r.status=='1') $('#task_row_'+id).fadeOut();
  },'json');
});
</script>
