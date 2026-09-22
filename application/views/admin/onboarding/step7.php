<?php
$wm = $tpl['working_mode'];
$mode = $state['data']['working_mode'] ?? $wm['recommended'];
$start = substr($state['data']['hours']['hours_start'] ?? $wm['hours_start'], 0, 5);
$end = substr($state['data']['hours']['hours_end'] ?? $wm['hours_end'], 0, 5);
$days = explode(',', (string) ($state['data']['hours']['hours_days'] ?? $wm['hours_days']));
$daynames = array(1 => 'Mon · الاتنين', 2 => 'Tue · التلات', 3 => 'Wed · الأربع', 4 => 'Thu · الخميس', 5 => 'Fri · الجمعة', 6 => 'Sat · السبت', 7 => 'Sun · الحد');
$this->load->view('admin/onboarding/_shell');
?>
<p class="text-muted">Choose when your AI employee answers. Either it answers all the time, or it stays quiet while your team is on shift and covers the rest.
  <br><span dir="rtl">اختار المساعد يرد إمتى. يا إما يرد طول الوقت، يا إما يسكت وقت ما فريقك بيشتغل ويغطي باقي اليوم.</span></p>

<div class="alert alert-light">
  <strong>Recommended for <?php echo htmlspecialchars($tpl['display_name']['en']); ?>:</strong>
  <?php echo htmlspecialchars($wm['note']['en']); ?>
  <br><span dir="rtl"><?php echo htmlspecialchars($wm['note']['ar']); ?></span>
</div>

<div class="card"><div class="card-body">
  <div class="form-group">
    <label class="d-block"><input type="radio" name="ob_mode" value="always_on" <?php echo $mode === 'always_on' ? 'checked' : ''; ?>>
      <strong>Answer all the time</strong> <small class="text-muted" dir="rtl">يرد طول الوقت</small></label>
    <label class="d-block"><input type="radio" name="ob_mode" value="staff_hours_silent" <?php echo $mode === 'staff_hours_silent' ? 'checked' : ''; ?>>
      <strong>Stay quiet while my team is working</strong> <small class="text-muted" dir="rtl">يسكت وقت ما فريقي شغال</small></label>
  </div>

  <div id="ob_hours" class="<?php echo $mode === 'staff_hours_silent' ? '' : 'd-none'; ?>">
    <div class="form-row">
      <div class="col-md-3"><label>Team starts <small class="text-muted" dir="rtl">الفريق بيبدأ</small></label>
        <input type="time" id="ob_start" class="form-control" value="<?php echo htmlspecialchars($start); ?>"></div>
      <div class="col-md-3"><label>Team ends <small class="text-muted" dir="rtl">الفريق بيخلص</small></label>
        <input type="time" id="ob_end" class="form-control" value="<?php echo htmlspecialchars($end); ?>"></div>
    </div>
    <label class="mt-3 d-block">Team working days <small class="text-muted" dir="rtl">أيام شغل الفريق</small></label>
    <?php foreach ($daynames as $n => $label): ?>
      <label class="mr-3"><input type="checkbox" class="ob-day" value="<?php echo $n; ?>" <?php echo in_array((string) $n, $days, true) ? 'checked' : ''; ?>> <?php echo $label; ?></label>
    <?php endforeach; ?>
    <p class="text-muted mt-2 mb-0"><small>Inside these hours the AI stays silent and your team replies. Outside them the AI answers.</small></p>
  </div>

  <p class="text-muted mt-3 mb-0"><small>
    <?php if ((int) $hours['rows'] === 0): ?>
      None of the pages you picked for this business has working-hours settings (only Facebook pages and personal WhatsApp numbers do), so this is only remembered as your choice.
    <?php else: ?>
      This applies only to the <?php echo (int) $hours['rows']; ?> page(s)/number(s) you picked for this business in step 2.
      <span dir="rtl">ده هيتطبّق على الصفحات اللي اخترتها للنشاط ده بس.</span>
    <?php endif; ?>
  </small></p>
</div></div>

<button class="btn btn-primary ob-save" id="ob_go">Save and continue</button>
<?php $this->load->view('admin/onboarding/_foot'); ?>
<script>
$(function () {
  $('input[name=ob_mode]').on('change', function () {
    $('#ob_hours').toggleClass('d-none', $(this).val() !== 'staff_hours_silent');
  });
  $('#ob_go').on('click', function () {
    var days = $('.ob-day:checked').map(function () { return $(this).val(); }).get().join(',');
    OB.post('save_hours', {
      mode: $('input[name=ob_mode]:checked').val(),
      hours_start: $('#ob_start').val(), hours_end: $('#ob_end').val(), hours_days: days
    }, function (r) {
      OB.msg('success', r.channels_updated > 0 ? ('Applied to ' + r.channels_updated + ' channel(s).') : 'Choice saved.');
      setTimeout(OB.next, 900);
    });
  });
});
</script>
