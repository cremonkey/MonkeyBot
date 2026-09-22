<?php
$ai = $tpl['ai_employee'];
$ov = $state['data']['agent_overrides'] ?? array();
$v = function ($k, $fallback) use ($ov, $profile) { $x = trim((string)($ov[$k] ?? '')); return $x !== '' ? $x : $fallback; };
$this->load->view('admin/onboarding/_shell');
?>
<p class="text-muted">This is the person your customers will talk to. Give it a name and check what it is allowed to do.
  <br><span dir="rtl">ده الشخص اللي عملاؤك هيتكلموا معاه. سمّيه وشوف هو مسموح له يعمل إيه.</span></p>

<div class="row">
  <div class="col-lg-6">
    <div class="card"><div class="card-header"><h4>Who it is <small class="text-muted" dir="rtl">مين هو</small></h4></div>
      <div class="card-body">
        <div class="form-group">
          <label>Name <small class="text-muted" dir="rtl">الاسم</small></label>
          <input id="ob_name" class="form-control" maxlength="80" value="<?php echo htmlspecialchars($v('agent_name', $ai['name_suggestion']['ar'])); ?>">
        </div>
        <div class="form-group">
          <label>Job <small class="text-muted" dir="rtl">الوظيفة</small></label>
          <input id="ob_role" class="form-control" maxlength="120" value="<?php echo htmlspecialchars($v('role', $ai['role']['ar'])); ?>">
        </div>
        <div class="form-group">
          <label>Goal <small class="text-muted" dir="rtl">الهدف</small></label>
          <input id="ob_goal" class="form-control" maxlength="160" value="<?php echo htmlspecialchars($v('goal', $ai['goal']['ar'])); ?>">
        </div>
        <div class="form-group mb-0">
          <label>Anything else it must know <small class="text-muted" dir="rtl">أي حاجة تانية لازم يعرفها</small></label>
          <textarea id="ob_extra" class="form-control" rows="4" dir="rtl" placeholder="مثلًا: إحنا مقفولين يوم الجمعة."><?php echo htmlspecialchars($ov['extra'] ?? ''); ?></textarea>
        </div>
        <p class="text-muted mt-2 mb-0"><small>Speaking style: <strong>friendly Egyptian Arabic</strong>. It answers in the customer's language.</small></p>
      </div>
    </div>
  </div>
  <div class="col-lg-6">
    <div class="card"><div class="card-header"><h4>What it can do <small class="text-muted" dir="rtl">بيعمل إيه</small></h4></div>
      <div class="card-body">
        <ul class="list-unstyled mb-0">
          <?php foreach ($ai['capabilities'] as $c): ?>
            <li class="mb-1"><i class="fas fa-check text-success"></i> <?php echo htmlspecialchars($c['en']); ?>
              <br><small class="text-muted ml-4" dir="rtl"><?php echo htmlspecialchars($c['ar']); ?></small></li>
          <?php endforeach; ?>
        </ul>
      </div>
    </div>
    <div class="card"><div class="card-header"><h4>What it will never do <small class="text-muted" dir="rtl">مش هيعمله أبدًا</small></h4></div>
      <div class="card-body">
        <p class="mb-2"><span class="badge badge-danger">Maximum discount: <?php echo (int) $ai['restrictions']['max_discount_percent']; ?>%</span></p>
        <ul class="list-unstyled mb-0">
          <?php foreach ($ai['restrictions']['rules'] as $r): ?>
            <li class="mb-1"><i class="fas fa-ban text-danger"></i> <?php echo htmlspecialchars($r['en']); ?>
              <br><small class="text-muted ml-4" dir="rtl"><?php echo htmlspecialchars($r['ar']); ?></small></li>
          <?php endforeach; ?>
        </ul>
        <p class="text-muted mt-2 mb-0"><small>These rules are written into your AI employee. You can fine-tune them later on the AI Employees page.</small></p>
      </div>
    </div>
  </div>
</div>

<button class="btn btn-primary ob-save" id="ob_go"><?php echo $profile ? 'Update and continue' : 'Create and continue'; ?></button>
<?php $this->load->view('admin/onboarding/_foot'); ?>
<script>
$(function () {
  $('#ob_go').on('click', function () {
    OB.post('save_agent', {
      agent_name: $('#ob_name').val(), role: $('#ob_role').val(),
      goal: $('#ob_goal').val(), extra: $('#ob_extra').val()
    }, function (r) {
      var kept = (r.kept_existing || []).length;
      var m = r.assigned > 0 ? ('Saved. Your AI employee now answers on ' + r.assigned + ' of the pages you picked.') : 'Saved.';
      if (kept) m += ' ' + kept + ' page(s) already had another AI employee and were left as they are: ' + r.kept_existing.join(', ') + '.';
      if (r.assigned > 0 || kept) { OB.msg('success', m); setTimeout(OB.next, kept ? 2500 : 900); }
      else OB.next();
    });
  });
});
</script>
