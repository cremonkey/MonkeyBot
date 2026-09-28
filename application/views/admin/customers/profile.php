<?php
$plat_name = array('fb' => 'Messenger', 'ig' => 'Instagram', 'wa' => 'WhatsApp', 'tg' => 'Telegram', 'web' => 'Website');
if (!function_exists('cu_plat')) { function cu_plat($p) { global $plat_name; return isset($plat_name[$p]) ? $plat_name[$p] : $p; } }
?>
<section class="section cu-page">
  <div class="section-header">
    <h1><i class="fas fa-user"></i> <?php echo htmlspecialchars($page_title); ?></h1>
    <div class="section-header-breadcrumb">
      <a href="<?php echo base_url('customers'); ?>" class="btn btn-outline-secondary"><i class="fas fa-arrow-left"></i> All customers</a>
    </div>
  </div>

  <div class="section-body">
    <div class="row">
      <!-- LEFT: identity + score -->
      <div class="col-lg-4 mb-3">
        <div class="card">
          <div class="card-body">
            <h5 class="card-title"><?php echo htmlspecialchars($page_title); ?></h5>
            <p class="text-muted mb-1"><?php echo $customer['phone'] ? htmlspecialchars($customer['phone']) : '&mdash;'; ?></p>
            <p class="text-muted mb-3"><?php echo $customer['email'] ? htmlspecialchars($customer['email']) : '&mdash;'; ?></p>
            <p><span class="badge badge-primary cu-score-badge">Lead score: <?php echo (int) $customer['lead_score']; ?></span></p>
            <hr>
            <h6>Channels (<?php echo (int) $profile['channel_count']; ?>)</h6>
            <?php foreach ($profile['by_channel'] as $platform => $idents): ?>
              <div class="mb-1"><i class="fas fa-circle cu-channel-dot"></i> <?php echo htmlspecialchars(cu_plat($platform)); ?>
                <span class="text-muted small">(<?php echo count($idents); ?>)</span></div>
            <?php endforeach; ?>
            <?php if (empty($profile['by_channel'])): ?><p class="text-muted small">No channel identities yet.</p><?php endif; ?>
            <hr>
            <h6>Labels</h6>
            <?php foreach ($profile['labels'] as $l): ?>
              <span class="badge cu-label-badge" style="<?php echo $l['color'] ? 'background:' . htmlspecialchars($l['color']) . ';' : ''; ?>"><?php echo htmlspecialchars($l['name']); ?></span>
            <?php endforeach; ?>
            <?php if (empty($profile['labels'])): ?><p class="text-muted small">No labels.</p><?php endif; ?>
          </div>
        </div>

        <div class="card">
          <div class="card-body">
            <h6>Pipeline</h6>
            <?php if ($profile['lead_stage']['open_deal']): ?>
              <p class="mb-1"><?php echo htmlspecialchars($profile['lead_stage']['open_deal']['title']); ?></p>
              <p class="text-muted small"><?php echo htmlspecialchars($profile['lead_stage']['open_deal']['value']); ?> <?php echo htmlspecialchars($profile['lead_stage']['open_deal']['currency']); ?></p>
            <?php else: ?>
              <p class="text-muted small">No open deal.</p>
            <?php endif; ?>
            <p class="text-muted small mb-0">Open: <?php echo (int) $profile['lead_stage']['open_count']; ?> · Won: <?php echo (int) $profile['lead_stage']['won_count']; ?> · Lost: <?php echo (int) $profile['lead_stage']['lost_count']; ?></p>
          </div>
        </div>
      </div>

      <!-- RIGHT: conversations, orders, appointments, notes, events -->
      <div class="col-lg-8 mb-3">
        <div class="card mb-3">
          <div class="card-header"><i class="fas fa-comments"></i> Conversations</div>
          <div class="card-body p-0">
            <?php if (empty($profile['conversations'])): ?>
              <p class="text-muted p-3 mb-0">No conversations yet.</p>
            <?php else: ?>
            <div class="table-responsive"><table class="table table-sm mb-0">
              <thead><tr><th>Channel</th><th>Status</th><th>State</th><th>Last message</th></tr></thead>
              <tbody>
              <?php foreach ($profile['conversations'] as $c): ?>
                <tr><td><?php echo htmlspecialchars(cu_plat($c['platform'])); ?></td><td><?php echo htmlspecialchars($c['status']); ?></td>
                    <td><?php echo htmlspecialchars($c['handoff_state']); ?></td><td><?php echo htmlspecialchars($c['last_message_at']); ?></td></tr>
              <?php endforeach; ?>
              </tbody>
            </table></div>
            <?php endif; ?>
          </div>
        </div>

        <?php if (!empty($profile['ai_summary'])): ?>
        <div class="card mb-3">
          <div class="card-header"><i class="fas fa-robot"></i> AI summaries</div>
          <div class="card-body">
            <?php foreach ($profile['ai_summary'] as $s): ?>
              <p class="mb-2"><span class="badge badge-light"><?php echo htmlspecialchars(cu_plat($s['platform'])); ?></span> <?php echo nl2br(htmlspecialchars($s['last_summary'])); ?></p>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endif; ?>

        <div class="card mb-3">
          <div class="card-header"><i class="fas fa-shopping-cart"></i> Orders</div>
          <div class="card-body p-0">
            <?php if (empty($profile['orders'])): ?>
              <p class="text-muted p-3 mb-0">No orders yet.</p>
            <?php else: ?>
            <div class="table-responsive"><table class="table table-sm mb-0">
              <thead><tr><th>Date</th><th>Buyer</th><th>Total</th></tr></thead>
              <tbody>
              <?php foreach ($profile['orders'] as $o): ?>
                <tr><td><?php echo htmlspecialchars($o['ordered_at']); ?></td>
                    <td><?php echo htmlspecialchars(trim($o['buyer_first_name'] . ' ' . $o['buyer_last_name'])); ?></td>
                    <td><?php echo htmlspecialchars($o['payment_amount']); ?> <?php echo htmlspecialchars($o['currency']); ?></td></tr>
              <?php endforeach; ?>
              </tbody>
            </table></div>
            <?php endif; ?>
          </div>
        </div>

        <div class="card mb-3">
          <div class="card-header"><i class="fas fa-calendar-check"></i> Appointments</div>
          <div class="card-body p-0">
            <?php if (empty($profile['appointments'])): ?>
              <p class="text-muted p-3 mb-0">No appointments yet.</p>
            <?php else: ?>
            <div class="table-responsive"><table class="table table-sm mb-0">
              <thead><tr><th>Starts</th><th>Status</th><th>Source</th></tr></thead>
              <tbody>
              <?php foreach ($profile['appointments'] as $a): ?>
                <tr><td><?php echo htmlspecialchars($a['starts_at']); ?></td><td><?php echo htmlspecialchars($a['status']); ?></td><td><?php echo htmlspecialchars($a['source']); ?></td></tr>
              <?php endforeach; ?>
              </tbody>
            </table></div>
            <?php endif; ?>
          </div>
        </div>

        <div class="card mb-3">
          <div class="card-header"><i class="fas fa-sticky-note"></i> Internal notes</div>
          <div class="card-body">
            <?php if (empty($profile['notes'])): ?>
              <p class="text-muted mb-0">No internal notes yet.</p>
            <?php else: foreach ($profile['notes'] as $n): ?>
              <p class="mb-2"><strong><?php echo htmlspecialchars($n['author_name']); ?></strong> <span class="text-muted small"><?php echo htmlspecialchars($n['created_at']); ?></span><br><?php echo nl2br(htmlspecialchars($n['note'])); ?></p>
            <?php endforeach; endif; ?>
          </div>
        </div>

        <div class="card">
          <div class="card-header"><i class="fas fa-history"></i> Recent events</div>
          <div class="card-body p-0">
            <?php if (empty($profile['events'])): ?>
              <p class="text-muted p-3 mb-0">No recorded events yet.</p>
            <?php else: ?>
            <ul class="list-group list-group-flush">
              <?php foreach ($profile['events'] as $e): ?>
                <li class="list-group-item"><?php echo htmlspecialchars($e['event_type']); ?> <span class="text-muted small float-right"><?php echo htmlspecialchars($e['created_at']); ?></span></li>
              <?php endforeach; ?>
            </ul>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>
