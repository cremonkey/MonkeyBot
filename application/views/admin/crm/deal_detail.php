<section class="section">
  <div class="section-header"><h1><i class="fas fa-handshake"></i> <?php echo htmlspecialchars($deal['title']); ?></h1>
    <div class="section-header-breadcrumb"><a href="<?php echo base_url('crm/pipeline'); ?>" class="btn btn-outline-primary">Back to Pipeline</a></div>
  </div>
  <div class="section-body">
    <div class="row">
      <div class="col-lg-4">
        <div class="card"><div class="card-header"><h4>Overview</h4></div><div class="card-body">
          <p><b>Value:</b> <?php echo number_format($deal['value'],2).' '.$deal['currency']; ?></p>
          <p><b>Status:</b> <span class="badge badge-<?php echo $deal['status']=='won'?'success':($deal['status']=='lost'?'danger':'primary'); ?>"><?php echo $deal['status']; ?></span></p>
          <p><b>Source:</b> <?php echo $deal['source']; ?></p>
          <p><b>Contact:</b> <?php echo htmlspecialchars($deal['contact_name']); ?><br><?php echo htmlspecialchars($deal['contact_email']); ?> <?php echo htmlspecialchars($deal['contact_phone']); ?></p>
          <?php if(!empty($subscriber)): ?><p><b>Lead score:</b> <?php echo (int)($subscriber['lead_score'] ?? 0); ?></p><?php endif; ?>
        </div></div>
        <div class="card"><div class="card-header"><h4>Notes &amp; Activities</h4></div><div class="card-body" style="max-height:300px;overflow-y:auto">
          <?php if(!empty($activities)): foreach($activities as $a): ?>
            <div style="margin-bottom:10px;padding-bottom:8px;border-bottom:1px solid #f0f0f0">
              <small class="text-muted"><?php echo $a['created_at']; ?> — <?php echo htmlspecialchars($a['subject']); ?></small>
              <div style="white-space:pre-line"><?php echo htmlspecialchars($a['description']); ?></div>
            </div>
          <?php endforeach; else: ?><p class="text-muted">No notes yet.</p><?php endif; ?>
        </div></div>
        <div class="card"><div class="card-header"><h4>Timeline</h4></div><div class="card-body">
          <ul class="list-unstyled">
          <?php foreach($timeline as $t): ?><li><small class="text-muted"><?php echo $t['created_at']; ?></small> — <?php echo htmlspecialchars($t['action']); ?></li><?php endforeach; ?>
          </ul>
        </div></div>
      </div>
      <div class="col-lg-8">
        <div class="card"><div class="card-header"><h4>Orders</h4></div><div class="card-body">
          <?php if(!empty($orders)): ?><table class="table table-sm"><thead><tr><th>Amount</th><th>Status</th><th>When</th></tr></thead><tbody>
          <?php foreach($orders as $o): ?><tr><td><?php echo number_format($o['payment_amount'] ?? 0,2).' '.($o['currency'] ?? ''); ?></td><td><?php echo $o['status'] ?? ''; ?></td><td><?php echo $o['ordered_at'] ?? ''; ?></td></tr><?php endforeach; ?>
          </tbody></table><?php else: ?><p class="text-muted">No orders.</p><?php endif; ?>
        </div></div>
        <div class="card"><div class="card-header"><h4>Conversation</h4></div><div class="card-body" style="max-height:400px;overflow-y:auto">
          <?php if(!empty($conversation)): foreach(array_reverse($conversation) as $m): ?>
            <div class="mb-1"><span class="badge badge-<?php echo $m['sender']=='user'?'light':'primary'; ?>"><?php echo $m['sender']; ?></span> <?php echo htmlspecialchars($m['message_content']); ?></div>
          <?php endforeach; else: ?><p class="text-muted">No conversation history linked.</p><?php endif; ?>
        </div></div>
      </div>
    </div>
  </div>
</section>
