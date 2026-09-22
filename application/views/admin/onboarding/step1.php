<?php $this->load->view('admin/onboarding/_shell'); ?>
<p class="text-muted">Pick what your business does. Everything after this step is filled in for you — you only correct it.
  <br><span dir="rtl">اختار نوع نشاطك. كل اللي بعد كده هيتملّي لوحده، وإنت بس بتعدّل اللي محتاج تعديل.</span></p>

<div class="form-group col-md-6 px-0">
  <label>Business name <small class="text-muted" dir="rtl">اسم النشاط</small></label>
  <input type="text" id="ob_biz" class="form-control" maxlength="120"
         value="<?php echo htmlspecialchars($state['data']['business_name'] ?? ''); ?>"
         placeholder="e.g. Byoot Resort">
  <small class="form-text text-muted">Used in what your AI employee says about itself.</small>
</div>

<?php if (empty($templates)): ?>
  <div class="alert alert-danger">
    Business types could not be loaded, so this step cannot be saved. Please contact support.
    <br><span dir="rtl">مقدرناش نحمّل أنواع النشاط، فالخطوة دي مش هتتحفظ. كلّم الدعم الفني.</span>
  </div>
<?php endif; ?>
<p class="mb-2"><strong>Business type</strong> <small class="text-muted" dir="rtl">نوع النشاط</small></p>
<div class="row">
  <?php foreach ($templates as $k => $t): $sel = ($state['template_key'] ?? '') === $k; ?>
    <div class="col-md-6 col-lg-3">
      <div class="card ob-card h-100 <?php echo $sel ? 'border-primary' : ''; ?>" data-key="<?php echo htmlspecialchars($k); ?>" style="cursor:pointer">
        <div class="card-body">
          <h4 class="mb-0"><?php echo htmlspecialchars($t['display_name']['en']); ?></h4>
          <p class="mb-2" dir="rtl"><strong><?php echo htmlspecialchars($t['display_name']['ar']); ?></strong></p>
          <p class="text-muted small mb-2"><?php echo htmlspecialchars($t['tagline']['en']); ?></p>
          <p class="text-muted small" dir="rtl"><?php echo htmlspecialchars($t['tagline']['ar']); ?></p>
          <ul class="small text-muted pl-3 mb-0">
            <li><?php echo count($t['starter_faq']); ?> ready-made answers to edit</li>
            <li><?php echo count($t['qualification_questions']); ?> questions it asks every customer</li>
            <li><?php echo count($t['pipeline']['stages']); ?> lead stages</li>
            <li><?php echo !empty($t['pricing_supported']) ? 'Price calculator included' : 'Prices kept as answers'; ?></li>
          </ul>
        </div>
        <div class="card-footer text-center py-2">
          <span class="badge badge-<?php echo $sel ? 'primary' : 'light'; ?> ob-badge"><?php echo $sel ? 'Selected' : 'Choose'; ?></span>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<button class="btn btn-primary ob-save" id="ob_go" disabled>Save and continue</button>
<small class="text-muted ml-2" id="ob_go_hint">Choose a business type above to continue. <span dir="rtl">اختار نوع النشاط الأول.</span></small>
<?php $this->load->view('admin/onboarding/_foot'); ?>
<script>
$(function () {
  var key = <?php echo json_encode($state['template_key'] ?? ''); ?>;
  function paint() {
    $('.ob-card').removeClass('border-primary').find('.ob-badge').removeClass('badge-primary').addClass('badge-light').text('Choose');
    if (key) $('.ob-card[data-key="' + key + '"]').addClass('border-primary').find('.ob-badge').removeClass('badge-light').addClass('badge-primary').text('Selected');
    $('#ob_go').prop('disabled', !key);
    $('#ob_go_hint').toggle(!key);
  }
  $('.ob-card').on('click', function () { key = $(this).data('key'); paint(); });
  $('#ob_go').on('click', function () {
    OB.post('save_business', {template_key: key, business_name: $('#ob_biz').val()}, function () { OB.next(); });
  });
  paint();
});
</script>
