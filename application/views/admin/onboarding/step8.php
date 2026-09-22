<?php $this->load->view('admin/onboarding/_shell'); ?>
<p class="text-muted">Write what a customer would write, and see exactly what your AI employee replies. This is a real reply from your settings — nothing is faked.
  <br><span dir="rtl">اكتب زي ما العميل هيكتب، وشوف المساعد بتاعك هيرد بإيه بالظبط. ده رد حقيقي من إعداداتك، مفيش حاجة متألّفة.</span></p>

<?php if ($profile === null): ?>
  <div class="alert alert-warning">Create your AI employee first — <a href="<?php echo base_url('onboarding/step/3'); ?>">go to step 3</a>.</div>
<?php elseif (!$ai_ready): ?>
  <div class="alert alert-warning">
    <strong>The AI brain is not connected yet.</strong>
    A test reply needs the AI key your agency sets up for your account. Until then this step cannot show a reply — and it will never show a made-up one.
    <br><span dir="rtl">مخ الذكاء الاصطناعي لسه مش متوصّل. التجربة محتاجة مفتاح الذكاء الاصطناعي اللي بيتظبط لحسابك. لحد ما يتظبط الخطوة دي مش هتعرض رد، ومش هتعرض رد متألّف أبدًا.</span>
    <br><a class="btn btn-sm btn-outline-secondary mt-2" href="<?php echo base_url('openAI'); ?>" target="_blank">Open AI settings</a>
  </div>
<?php endif; ?>

<div class="card"><div class="card-body">
  <div class="form-group">
    <label>Test message <small class="text-muted" dir="rtl">رسالة تجريبية</small></label>
    <input id="ob_msg_in" class="form-control" dir="rtl" placeholder="<?php echo htmlspecialchars($tpl['qualification_questions'][0]['ar'] ?? 'السلام عليكم، عايز أسأل عن الأسعار'); ?>"
           value="السلام عليكم، ممكن أعرف تفاصيل أكتر؟" <?php echo ($profile === null || !$ai_ready) ? 'disabled' : ''; ?>>
  </div>
  <button class="btn btn-secondary ob-save" id="ob_test" <?php echo ($profile === null || !$ai_ready) ? 'disabled' : ''; ?>>
    <i class="fas fa-paper-plane"></i> Send test message
  </button>
  <div id="ob_reply" class="mt-3"></div>
</div></div>

<button class="btn btn-primary" onclick="OB.next()">Continue</button>
<?php $this->load->view('admin/onboarding/_foot'); ?>
<script>
$(function () {
  $('#ob_test').on('click', function () {
    $('#ob_reply').html('<span class="text-muted">Waiting for the reply…</span>');
    OB.post('test_reply', {message: $('#ob_msg_in').val()}, function (r) {
      $('#ob_reply').html($('<div class="alert alert-success" dir="rtl">').text(r.reply));
    });
    // errors are shown by OB.post in the banner; clear the placeholder either way
    setTimeout(function () { if ($('#ob_reply').text().indexOf('Waiting') === 0 && $('#ob_msg .alert-danger').length) $('#ob_reply').html(''); }, 1200);
  });
});
</script>
