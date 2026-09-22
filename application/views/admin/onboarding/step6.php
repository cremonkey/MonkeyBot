<?php
$have = array();
foreach ($stages as $s) $have[mb_strtolower(trim($s['name']))] = true;
$this->load->view('admin/onboarding/_shell');
?>
<p class="text-muted">Every person who messages you moves through these stages, so you can see who is still deciding and who is ready to buy.
  <br><span dir="rtl">كل واحد بيكلمك بيعدي على المراحل دي، عشان تعرف مين لسه بيفكر ومين جاهز يشتري.</span></p>

<div class="row">
  <div class="col-lg-6">
    <div class="card"><div class="card-header"><h4>Recommended for <?php echo htmlspecialchars($tpl['display_name']['en']); ?></h4></div>
      <div class="card-body"><ul class="list-unstyled mb-0">
        <?php foreach ($tpl['pipeline']['stages'] as $s): $exists = isset($have[mb_strtolower(trim($s['name']))]); ?>
          <li class="mb-2">
            <span class="badge" style="background:<?php echo htmlspecialchars($s['color']); ?>;color:#fff">&nbsp;</span>
            <strong><?php echo htmlspecialchars($s['name']); ?></strong>
            <?php if ($s['stage_type'] !== 'open'): ?><small class="text-muted">(<?php echo $s['stage_type'] === 'won' ? 'success' : 'closed'; ?>)</small><?php endif; ?>
            <?php echo $exists ? '<span class="badge badge-success float-right">already there</span>' : '<span class="badge badge-light float-right">will be added</span>'; ?>
          </li>
        <?php endforeach; ?>
      </ul></div>
    </div>
  </div>
  <div class="col-lg-6">
    <div class="card"><div class="card-header"><h4>Your stages now <small class="text-muted" dir="rtl">مراحلك دلوقتي</small></h4></div>
      <div class="card-body">
        <?php if (empty($stages)): ?>
          <p class="text-muted mb-0">You have no stages yet — they will be created for you.
            <br><span dir="rtl">لسه مفيش مراحل، هتتعملك دلوقتي.</span></p>
        <?php else: ?>
          <ol class="mb-0"><?php foreach ($stages as $s): ?><li><?php echo htmlspecialchars($s['name']); ?></li><?php endforeach; ?></ol>
        <?php endif; ?>
      </div>
    </div>
    <div class="alert alert-light mb-0">Stages you already use are kept exactly as they are — nothing is renamed or removed.
      <br><span dir="rtl">المراحل اللي عندك بتفضل زي ما هي، مفيش حاجة بتتغير أو بتتمسح.</span></div>
  </div>
</div>

<button class="btn btn-primary ob-save" id="ob_go">Set up my stages and continue</button>
<?php $this->load->view('admin/onboarding/_foot'); ?>
<script>
$(function () {
  $('#ob_go').on('click', function () {
    OB.post('save_pipeline', {}, function (r) {
      OB.msg('success', r.added > 0 ? ('Added ' + r.added + ' stage(s).') : 'Your stages were already set up.');
      setTimeout(OB.next, 900);
    });
  });
});
</script>
