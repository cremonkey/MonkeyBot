<?php $ch = array('fb' => 'Messenger', 'ig' => 'Instagram', 'wa' => 'WhatsApp', 'tg' => 'Telegram', 'web' => 'Website', 'tiktok' => 'TikTok'); ?>
<section class="section">
  <div class="section-header"><h1><i class="fas fa-lightbulb"></i> <?php echo $page_title; ?></h1>
    <div class="section-header-breadcrumb"><a href="<?php echo base_url('missed_questions'); ?>" class="btn btn-sm btn-outline-secondary">Question-by-question view</a></div>
  </div>
  <div class="section-body">
    <p class="text-muted">Each card is one gap in what the bot knows. Answer it once — the bot uses the answer from the next message and every similar question is closed.</p>
    <ul class="nav nav-pills mb-3">
      <li class="nav-item"><a class="nav-link active" data-toggle="tab" href="#t_gaps">Knowledge gaps <span class="badge badge-white"><?php echo count($gaps); ?></span></a></li>
      <li class="nav-item"><a class="nav-link" data-toggle="tab" href="#t_guard">Price guard <span class="badge badge-white"><?php echo array_sum($guard_verdicts ?? array()); ?></span></a></li>
      <li class="nav-item"><a class="nav-link" data-toggle="tab" href="#t_faq">Answers &amp; impact <span class="badge badge-white"><?php echo count($faqs); ?></span></a></li>
      <li class="nav-item"><a class="nav-link" data-toggle="tab" href="#t_tool_failures">Tool failures <span class="badge badge-white"><?php echo count($tool_failures ?? array()); ?></span></a></li>
      <li class="nav-item"><a class="nav-link" data-toggle="tab" href="#t_escalations">Repeated escalations <span class="badge badge-white"><?php echo count($escalations ?? array()); ?></span></a></li>
      <li class="nav-item"><a class="nav-link" data-toggle="tab" href="#t_corrections">Human corrections <span class="badge badge-white"><?php echo count($corrections ?? array()); ?></span></a></li>
      <li class="nav-item"><a class="nav-link" data-toggle="tab" href="#t_low_confidence">Low confidence <span class="badge badge-white"><?php echo count($low_confidence ?? array()); ?></span></a></li>
    </ul>
    <div class="tab-content">
      <div class="tab-pane fade show active" id="t_gaps">
        <?php if (empty($gaps)): ?><div class="card"><div class="card-body text-success"><i class="fas fa-check"></i> No open gaps. The bot answered everything it was asked.</div></div><?php endif; ?>
        <?php foreach ($gaps as $g): ?>
        <div class="card mb-2 aii-gap" data-ids="<?php echo implode(',', $g['ids']); ?>">
          <div class="card-body py-3">
            <div class="d-flex justify-content-between">
              <div><span class="badge badge-<?php echo $g['count'] >= 5 ? 'danger' : ($g['count'] >= 2 ? 'warning' : 'light'); ?>">asked <?php echo $g['count']; ?>×</span>
                <b class="ml-1 aii-q"><?php echo htmlspecialchars($g['sample']); ?></b></div>
              <small class="text-muted"><?php foreach ($g['channels'] as $c) echo htmlspecialchars($ch[$c] ?? $c) . ' '; ?>· last <?php echo htmlspecialchars($g['last_at']); ?></small>
            </div>
            <div class="input-group mt-2">
              <textarea class="form-control aii-answer" rows="1" placeholder="Type the correct answer the bot should give…"></textarea>
              <div class="input-group-append">
                <button class="btn btn-primary aii-save">Add answer</button>
                <button class="btn btn-outline-secondary aii-dismiss" title="Not a real gap (spam, out of scope)">Dismiss</button>
              </div>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <div class="tab-pane fade" id="t_guard">
        <div class="card"><div class="card-body">
          <p class="text-muted mb-2">The bot tried to state a price, availability or link it could not prove from your data, so the reply was blocked and replaced. Frequent ones usually mean a price is missing in <a href="<?php echo base_url('pricing'); ?>">Products &amp; Pricing</a> or the knowledge base.</p>
          <?php if (!empty($guard_verdicts)): ?><p><?php foreach ($guard_verdicts as $v => $n) echo '<span class="badge badge-secondary mr-1">' . htmlspecialchars($v) . ': ' . (int) $n . '</span>'; ?> <small class="text-muted">last 90 days</small></p><?php endif; ?>
          <?php if (empty($guard)): ?><p class="text-success mb-0">No blocked replies in the last 90 days.</p><?php else: ?>
          <table class="table table-sm mb-0"><thead><tr><th>Times</th><th>Customer asked</th><th>Channels</th><th>Last</th></tr></thead><tbody>
            <?php foreach ($guard as $g): ?><tr><td><?php echo $g['count']; ?>×</td><td><?php echo htmlspecialchars($g['sample']); ?></td>
              <td><small><?php foreach ($g['channels'] as $c) echo htmlspecialchars($ch[$c] ?? $c) . ' '; ?></small></td><td><small><?php echo htmlspecialchars($g['last_at']); ?></small></td></tr><?php endforeach; ?>
          </tbody></table><?php endif; ?>
        </div></div>
      </div>
      <div class="tab-pane fade" id="t_faq">
        <div class="card"><div class="card-body p-0">
          <?php if (empty($faqs)): ?><p class="text-muted p-3 mb-0">No answers yet.</p><?php else: ?>
          <table class="table table-sm mb-0"><thead><tr><th>Question</th><th style="width:38%">Answer</th><th title="Similar questions before the answer">Before</th><th title="Similar questions the bot still could not answer after it">After</th><th>By</th><th></th></tr></thead><tbody>
          <?php foreach ($faqs as $f): ?>
            <tr data-id="<?php echo (int) $f['id']; ?>"><td><?php echo htmlspecialchars($f['question']); ?><br><small class="text-muted"><?php echo htmlspecialchars($f['created_at']); ?></small></td>
              <td><textarea class="form-control form-control-sm aii-faq-answer" rows="2"><?php echo htmlspecialchars($f['answer']); ?></textarea></td>
              <td><?php echo isset($f['questions_before']) && $f['questions_before'] ? (int) $f['questions_before'] : '—'; ?></td>
              <td><?php echo $f['asked_after'] ? '<span class="badge badge-warning" title="The answer may not cover how customers ask it">' . $f['asked_after'] . '</span>' : '<span class="text-success">0</span>'; ?></td>
              <td><small><?php echo htmlspecialchars($f['by_name'] ?? '—'); ?></small></td>
              <td><button class="btn btn-sm btn-outline-primary aii-faq-save">Save</button></td></tr>
          <?php endforeach; ?></tbody></table><?php endif; ?>
        </div></div>
      <div class="tab-pane fade" id="t_tool_failures">
        <div class="card"><div class="card-body">
          <p class="text-muted mb-2">Tool calls the AI made that failed (last 90 days). Recorded so an admin can see them, never shown to the customer.</p>
          <?php if (empty($tool_failures)): ?><p class="text-success mb-0">No tool failures recorded.</p><?php else: ?>
          <table class="table table-sm mb-0"><thead><tr><th>Times</th><th>Tool</th><th>Error</th><th>Last</th></tr></thead><tbody>
            <?php foreach ($tool_failures as $t): ?><tr><td><?php echo (int) $t['count']; ?>&times;</td>
              <td><code><?php echo htmlspecialchars($t['tool_name']); ?></code></td>
              <td><small><?php echo htmlspecialchars($t['error_class']); ?></small></td>
              <td><small><?php echo htmlspecialchars($t['last_at']); ?></small></td></tr><?php endforeach; ?>
          </tbody></table><?php endif; ?>
        </div></div>
      </div>
      <div class="tab-pane fade" id="t_escalations">
        <div class="card"><div class="card-body">
          <p class="text-muted mb-2">Conversations that were handed to a human agent more than once, grouped by what the customer first asked. Frequent groups usually mean the bot needs a better answer before it hands off.</p>
          <?php if (empty($escalations)): ?><p class="text-success mb-0">No repeated escalations in the last 90 days.</p><?php else: ?>
          <table class="table table-sm mb-0"><thead><tr><th>Times</th><th>Customer first asked</th><th>Channels</th><th>Last</th></tr></thead><tbody>
            <?php foreach ($escalations as $g): ?><tr><td><?php echo (int) $g['count']; ?>&times;</td>
              <td><?php echo htmlspecialchars($g['sample']); ?></td>
              <td><small><?php foreach ($g['channels'] as $c) echo htmlspecialchars($ch[$c] ?? $c) . ' '; ?></small></td>
              <td><small><?php echo htmlspecialchars($g['last_at']); ?></small></td></tr><?php endforeach; ?>
          </tbody></table><?php endif; ?>
        </div></div>
      </div>
      <div class="tab-pane fade" id="t_corrections">
        <div class="card"><div class="card-body">
          <p class="text-muted mb-2">A human agent replied within <?php echo (int) ($corrections_window_minutes ?? 10); ?> minutes after the bot, in the same conversation &mdash; usually a sign the bot's reply needed fixing.</p>
          <?php if (empty($corrections)): ?><p class="text-success mb-0">No corrections in the last 90 days.</p><?php else: ?>
          <table class="table table-sm mb-0"><thead><tr><th>Bot said</th><th>Agent corrected to</th><th>Minutes later</th><th>When</th></tr></thead><tbody>
            <?php foreach ($corrections as $c): ?><tr>
              <td><?php echo htmlspecialchars(mb_strimwidth($c['ai_message'], 0, 120, '…')); ?></td>
              <td><?php echo htmlspecialchars(mb_strimwidth($c['human_message'], 0, 120, '…')); ?> <small class="text-muted">(<?php echo htmlspecialchars($c['human_agent']); ?>)</small></td>
              <td><?php echo htmlspecialchars((string) $c['minutes']); ?></td>
              <td><small><?php echo htmlspecialchars($c['human_at']); ?></small></td></tr><?php endforeach; ?>
          </tbody></table><?php endif; ?>
        </div></div>
      </div>
      <div class="tab-pane fade" id="t_low_confidence">
        <div class="card"><div class="card-body">
          <p class="text-muted mb-2">The price guard could not tell if the bot's answer was grounded (verdict "unknown"), so it is worth checking manually.</p>
          <?php if (empty($low_confidence)): ?><p class="text-success mb-0">No low-confidence verdicts in the last 90 days.</p><?php else: ?>
          <table class="table table-sm mb-0"><thead><tr><th>Times</th><th>Customer asked</th><th>Channels</th><th>Last</th></tr></thead><tbody>
            <?php foreach ($low_confidence as $g): ?><tr><td><?php echo (int) $g['count']; ?>&times;</td>
              <td><?php echo htmlspecialchars($g['sample']); ?></td>
              <td><small><?php foreach ($g['channels'] as $c) echo htmlspecialchars($ch[$c] ?? $c) . ' '; ?></small></td>
              <td><small><?php echo htmlspecialchars($g['last_at']); ?></small></td></tr><?php endforeach; ?>
          </tbody></table><?php endif; ?>
        </div></div>
      </div>
      </div>
    </div>
  </div>
</section>
<script>
(function () {
  var TOKEN = "<?php echo $this->session->userdata('csrf_token_session'); ?>", BASE = "<?php echo base_url(); ?>";
  var note = function (m, ok) { if (typeof iziToast !== 'undefined') iziToast[ok ? 'success' : 'error']({message: m, position: 'topRight'}); else alert(m); };
  $('.aii-save').on('click', function () {
    var card = $(this).closest('.aii-gap'), ans = card.find('.aii-answer').val().trim();
    if (!ans) return note('Write the answer first.', false);
    $.post(BASE + 'ai_improvement/answer_group', {csrf_token: TOKEN, ids: String(card.data('ids')).split(','), answer: ans, question: card.find('.aii-q').text()}, function (r) {
      note(r.message, r.status === '1'); if (r.status === '1') card.slideUp();
    }, 'json');
  });
  $('.aii-dismiss').on('click', function () {
    var card = $(this).closest('.aii-gap');
    $.post(BASE + 'ai_improvement/dismiss_group', {csrf_token: TOKEN, ids: String(card.data('ids')).split(',')}, function () { card.slideUp(); }, 'json');
  });
  $('.aii-faq-save').on('click', function () {
    var tr = $(this).closest('tr');
    $.post(BASE + 'ai_improvement/update_answer', {csrf_token: TOKEN, id: tr.data('id'), answer: tr.find('.aii-faq-answer').val()}, function (r) {
      note(r.status === '1' ? 'Saved.' : (r.message || 'No change.'), r.status === '1');
    }, 'json');
  });
})();
</script>
