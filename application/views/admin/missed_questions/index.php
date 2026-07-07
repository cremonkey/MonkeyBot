<section class="section">
  <div class="section-header"><h1><i class="fas fa-question-circle"></i> <?php echo $page_title; ?></h1>
    <div class="section-header-breadcrumb">
      <a href="<?php echo base_url('missed_questions'); ?>" class="btn <?php echo $status==='new'?'btn-primary':'btn-outline-primary'; ?>">New</a>
      <a href="<?php echo base_url('missed_questions?status=resolved'); ?>" class="btn <?php echo $status==='resolved'?'btn-primary':'btn-outline-primary'; ?>">Resolved</a>
    </div>
  </div>
  <div class="section-body">
    <div class="card">
      <div class="card-header">
        <h4>Questions the bot could not answer
          <?php foreach($counts as $c): ?>
            <span class="badge <?php echo $c['status']==='new'?'badge-warning':'badge-secondary'; ?>"><?php echo $c['status'].': '.$c['c']; ?></span>
          <?php endforeach; ?>
        </h4>
        <div class="card-header-action">
          <?php foreach($by_channel as $bc): ?>
            <a href="<?php echo base_url('missed_questions?channel='.$bc['social_media']); ?>" class="badge badge-primary" style="margin-right:4px"><?php echo $bc['social_media'].' ('.$bc['c'].')'; ?></a>
          <?php endforeach; ?>
          <?php if($status==='new' && !empty($rows)): ?>
            <button id="resolve_all" class="btn btn-sm btn-outline-success ml-2">Mark all resolved</button>
          <?php endif; ?>
        </div>
      </div>
      <div class="card-body p-0">
        <?php if(!empty($rows)): ?>
        <div class="table-responsive"><table class="table table-striped mb-0">
          <thead><tr><th style="width:38%">Customer asked</th><th style="width:32%">Bot replied</th><th>Channel / Page</th><th>When</th><th></th></tr></thead>
          <tbody>
          <?php foreach($rows as $r): ?>
            <tr id="mq_<?php echo $r['id']; ?>">
              <td style="white-space:pre-line;font-weight:600"><?php echo htmlspecialchars((string)$r['question']); ?></td>
              <td style="white-space:pre-line"><small class="text-muted"><?php echo htmlspecialchars((string)$r['ai_reply']); ?></small></td>
              <td><span class="badge badge-primary"><?php echo htmlspecialchars((string)$r['social_media']); ?></span>
                  <?php if(!empty($r['page_name'])): ?><br><small><?php echo htmlspecialchars($r['page_name']); ?></small><?php endif; ?></td>
              <td><small class="text-muted"><?php echo $r['created_at']; ?></small></td>
              <td style="white-space:nowrap">
                <?php if($r['status']==='new'): ?><button class="btn btn-sm btn-success mq-resolve" data-id="<?php echo $r['id']; ?>" title="I added this info to the prompt/KB"><i class="fas fa-check"></i></button><?php endif; ?>
                <button class="btn btn-sm btn-outline-danger mq-del" data-id="<?php echo $r['id']; ?>">×</button>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table></div>
        <?php else: ?>
          <p class="text-muted" style="padding:20px"><?php echo $status==='new' ? 'No missed questions — the bot answered everything from its context. 🎉' : 'No resolved questions yet.'; ?></p>
        <?php endif; ?>
      </div>
      <div class="card-footer"><small class="text-muted">Tip: add the missing answer to that page's prompt or to the <a href="<?php echo base_url('ai_knowledge_base'); ?>">AI Knowledge Base</a>, then mark the question resolved. The bot improves with every gap you fill.</small></div>
    </div>
  </div>
</section>
<script>
var MQ_TOKEN="<?php echo $this->session->userdata('csrf_token_session'); ?>", MQ_BASE="<?php echo base_url(); ?>";
$(document).on('click','.mq-resolve',function(){var id=$(this).data('id');
  $.post(MQ_BASE+'missed_questions/resolve',{csrf_token:MQ_TOKEN,id:id},function(r){if(r.status=='1')$('#mq_'+id).fadeOut();},'json');});
$(document).on('click','.mq-del',function(){if(!confirm('Delete this question?'))return;var id=$(this).data('id');
  $.post(MQ_BASE+'missed_questions/delete',{csrf_token:MQ_TOKEN,id:id},function(r){if(r.status=='1')$('#mq_'+id).fadeOut();},'json');});
$('#resolve_all').on('click',function(){if(!confirm('Mark ALL new questions as resolved?'))return;
  $.post(MQ_BASE+'missed_questions/resolve_all',{csrf_token:MQ_TOKEN},function(r){if(r.status=='1')location.reload();},'json');});
</script>
