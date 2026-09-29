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

    <?php if (!empty($customer['merged_into_customer_id'])): ?>
    <div class="alert alert-warning cu-merged-banner">
      <i class="fas fa-code-branch"></i>
      Merged into <a href="<?php echo base_url('customers/profile/' . (int) $customer['merged_into_customer_id']); ?>">customer #<?php echo (int) $customer['merged_into_customer_id']; ?></a>.
      This record is read-only until undone.
      <br><span dir="rtl">تم دمجه في <a href="<?php echo base_url('customers/profile/' . (int) $customer['merged_into_customer_id']); ?>">العميل رقم <?php echo (int) $customer['merged_into_customer_id']; ?></a>. هذا السجل للقراءة فقط لحد ما يتراجع الدمج.</span>
      <?php if (!empty($profile['merge_source_log'])): ?>
        <button type="button" class="btn btn-sm btn-outline-secondary ml-2 cu-unmerge-btn"
                data-log-id="<?php echo (int) $profile['merge_source_log']['log_id']; ?>">
          <i class="fas fa-undo"></i> Undo merge <span dir="rtl">تراجع عن الدمج</span>
        </button>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if (!empty($profile['recent_merges_in'])): ?>
    <div class="card cu-recent-merges">
      <div class="card-header">
        <i class="fas fa-history"></i> Recently merged into this customer
        <span class="text-muted" dir="rtl">دُمج فيه مؤخرًا</span>
      </div>
      <div class="card-body p-0">
        <table class="table table-sm mb-0">
          <tbody>
          <?php foreach ($profile['recent_merges_in'] as $m): ?>
            <tr>
              <td><?php echo htmlspecialchars($m['source_display_name'] ?: ('Customer #' . (int) $m['source_customer_id'])); ?>
                <span class="text-muted small">(<?php echo htmlspecialchars($m['created_at']); ?>)</span></td>
              <td class="text-right">
                <button type="button" class="btn btn-sm btn-outline-secondary cu-unmerge-btn" data-log-id="<?php echo (int) $m['log_id']; ?>">
                  <i class="fas fa-undo"></i> Undo <span dir="rtl">تراجع</span>
                </button>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endif; ?>

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

        <?php if (!empty($profile['merge_suggestions'])): ?>
        <div class="card cu-merge-suggestions">
          <div class="card-header">
            <i class="fas fa-code-branch"></i> Possible duplicate
            <span class="text-muted" dir="rtl">عميل مكرر محتمل</span>
          </div>
          <div class="card-body">
            <?php foreach ($profile['merge_suggestions'] as $s):
                $other_id = ($s['customer_id_a'] === (int) $customer['id']) ? $s['customer_id_b'] : $s['customer_id_a'];
                $other_name = ($s['customer_id_a'] === (int) $customer['id']) ? $s['display_name_b'] : $s['display_name_a']; ?>
              <p class="mb-2">
                Same verified <?php echo htmlspecialchars(cu_plat($s['matched_platform'])); ?> (<?php echo htmlspecialchars($s['matched_value']); ?>)
                as <strong><?php echo htmlspecialchars($other_name); ?></strong>.
                <br><span class="text-muted small" dir="rtl">نفس <?php echo htmlspecialchars(cu_plat($s['matched_platform'])); ?> الموثّق (<?php echo htmlspecialchars($s['matched_value']); ?>) الخاص بـ<?php echo htmlspecialchars($other_name); ?>.</span>
              </p>
              <button type="button" class="btn btn-sm btn-outline-warning cu-merge-btn"
                      data-keep-id="<?php echo (int) $customer['id']; ?>" data-merge-id="<?php echo (int) $other_id; ?>"
                      data-other-name="<?php echo htmlspecialchars($other_name); ?>">
                <i class="fas fa-object-ungroup"></i> Merge <span dir="rtl">دمج</span>
              </button>
            <?php endforeach; ?>
            <p class="text-muted small mb-0 mt-2">This never happens automatically — you decide which record survives.
              <br><span dir="rtl">ده مبيحصلش لوحده أبدًا — إنت اللي بتقرر مين اللي فاضل.</span></p>
            <p class="text-muted small mb-0 mt-1">Only shown for a <strong>verified</strong> phone or email — a channel you have confirmed belongs to the customer, not just typed the same way twice.
              <br><span dir="rtl">بيظهر بس لو الرقم أو الإيميل <strong>موثّق</strong> — يعني اتأكدنا إنه فعلاً بتاع العميل، مش مجرد نفس الكتابة.</span></p>
          </div>
        </div>
        <?php endif; ?>

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

<script>
(function () {
  // Task 29 (C14): the "Merge" button on a suggestion is the ONLY thing that ever merges two
  // customers — always behind an explicit confirm, always reversible (an Undo endpoint exists:
  // customers/unmerge with the returned log_id). Same TOKEN/BASE pattern as admin/inbox/index.php.
  var TOKEN = "<?php echo $this->session->userdata('csrf_token_session'); ?>", BASE = "<?php echo base_url(); ?>";

  document.querySelectorAll('.cu-merge-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var keepId = btn.getAttribute('data-keep-id');
      var mergeId = btn.getAttribute('data-merge-id');
      var otherName = btn.getAttribute('data-other-name');
      if (!window.confirm('Merge "' + otherName + '" into this customer? This moves their conversations and channels here. / هل تريد دمج "' + otherName + '" في هذا العميل؟ ده هينقل محادثاته وقنواته هنا.')) return;

      var fd = new FormData();
      fd.append('csrf_token', TOKEN);
      fd.append('keep_id', keepId);
      fd.append('merge_id', mergeId);

      btn.disabled = true;
      fetch(BASE + 'customers/merge', { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (resp) {
          if (resp.status === '1') {
            window.location.reload();
          } else {
            btn.disabled = false;
            window.alert(resp.message || 'Could not merge. / لم يتم الدمج.');
          }
        })
        .catch(function () { btn.disabled = false; window.alert('Network error. / خطأ في الاتصال.'); });
    });
  });

  // Review round 1: every merge is reversible from the UI, not just via the API — the banner
  // on a merged-away customer's own profile and the "Recently merged into this customer" list
  // on the surviving customer's profile both use this same confirm-gated Undo button.
  document.querySelectorAll('.cu-unmerge-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var logId = btn.getAttribute('data-log-id');
      if (!window.confirm('Undo this merge? Everything it moved goes back to where it was. / هل تريد التراجع عن هذا الدمج؟ كل حاجة اتنقلت هترجع مكانها.')) return;

      var fd = new FormData();
      fd.append('csrf_token', TOKEN);
      fd.append('log_id', logId);

      btn.disabled = true;
      fetch(BASE + 'customers/unmerge', { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (resp) {
          if (resp.status === '1') {
            window.location.reload();
          } else {
            btn.disabled = false;
            window.alert(resp.message || 'Could not undo the merge. / لم يتم التراجع عن الدمج.');
          }
        })
        .catch(function () { btn.disabled = false; window.alert('Network error. / خطأ في الاتصال.'); });
    });
  });
})();
</script>
