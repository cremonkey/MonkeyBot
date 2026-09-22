<?php
$existing = array();
foreach ($faq_rows as $r) $existing[(string) $r['source_key']] = $r;
$this->load->view('admin/onboarding/_shell');
?>
<p class="text-muted">These are the questions customers ask most. The answers below are placeholders — replace them with your real ones.
  Anything you leave blank keeps whatever is already saved.
  <br><span dir="rtl">دي الأسئلة اللي العملاء بيسألوها كتير. الإجابات تحت مجرد نماذج، غيّرها بإجاباتك الحقيقية. أي خانة تسيبها فاضية هتفضل زي ما هي.</span></p>

<div class="card"><div class="card-body">
  <?php foreach ($tpl['starter_faq'] as $f):
    $sk = 'tpl:' . $tpl['key'] . ':' . $f['key'];
    $row = $existing[$sk] ?? null;
    $answer = $row ? (string) $row['answer'] : '';
    $is_placeholder = $answer === '' || mb_stripos($answer, 'عدّلني') !== false;
  ?>
    <div class="form-group">
      <label class="mb-1" dir="rtl"><strong><?php echo htmlspecialchars($f['question']['ar']); ?></strong></label>
      <small class="text-muted d-block mb-1"><?php echo htmlspecialchars($f['question']['en']); ?>
        <?php if ($is_placeholder): ?><span class="badge badge-warning ml-1">edit me · عدّلني</span>
        <?php else: ?><span class="badge badge-success ml-1">your answer</span><?php endif; ?>
      </small>
      <textarea class="form-control ob-faq" data-key="<?php echo htmlspecialchars($f['key']); ?>" rows="2" dir="rtl"
                placeholder="<?php echo htmlspecialchars($f['answer']['ar']); ?>"><?php echo htmlspecialchars($is_placeholder ? '' : $answer); ?></textarea>
    </div>
  <?php endforeach; ?>
</div></div>

<div class="alert alert-light">
  Longer documents — price lists, brochures, policies — belong on the
  <a href="<?php echo base_url('ai_knowledge_base'); ?>" target="_blank">Knowledge</a> page. This step only sets the quick answers.
  <br><span dir="rtl">الملفات الطويلة زي قوايم الأسعار والبروشورات مكانها صفحة المعرفة. الخطوة دي للإجابات السريعة بس.</span>
</div>

<button class="btn btn-primary ob-save" id="ob_go">Save answers and continue</button>
<?php $this->load->view('admin/onboarding/_foot'); ?>
<script>
$(function () {
  $('#ob_go').on('click', function () {
    var payload = {};
    $('.ob-faq').each(function () { payload['faq[' + $(this).data('key') + ']'] = $(this).val(); });
    OB.post('save_knowledge', payload, function (r) {
      OB.msg('success', 'Saved ' + (r.inserted + r.updated) + ' answers (' + r.inserted + ' new, ' + r.updated + ' updated).');
      setTimeout(OB.next, 900);
    });
  });
});
</script>
