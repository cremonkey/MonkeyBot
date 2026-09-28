<?php $this->load->view('admin/onboarding/_shell'); ?>
<p class="text-muted">Optional extras for this business type — a starter follow-up sequence, one automation rule, and (if the
  template offers it) a default appointment service. Everything you tick is created OFF; open Sequences, Automations, or
  Appointments afterwards to turn it on.
  <br><span dir="rtl">إضافات اختيارية لنوع النشاط ده — سلسلة متابعة، قاعدة أتمتة، وميعاد افتراضي لو النشاط بيسمح. أي حاجة تعلّمها بتتعمل
  "متوقفة"، وتقدر تشغّلها بعدين من صفحة السيكونسز أو الأتمتة أو المواعيد.</span></p>

<?php if (empty($tpl['sequences']) && empty($tpl['automation_rules']) && empty($tpl['media']) && empty($tpl['appointments'])): ?>
  <div class="alert alert-light">This business type has no extras to offer yet.</div>
<?php endif; ?>

<?php if (!empty($tpl['sequences'])): ?>
<div class="card mb-3"><div class="card-header"><strong>Follow-up sequences</strong> <span class="text-muted" dir="rtl">سلاسل متابعة</span></div>
  <div class="card-body">
    <?php foreach ($extras['sequences'] as $s): $already = $s['op'] === 'skip'; ?>
      <div class="form-check mb-2">
        <input class="form-check-input ob-extra-seq" type="checkbox" value="<?php echo htmlspecialchars($s['source_key']); ?>"
               id="seq_<?php echo htmlspecialchars($s['source_key']); ?>" <?php echo $already ? 'checked disabled' : ''; ?>>
        <label class="form-check-label" for="seq_<?php echo htmlspecialchars($s['source_key']); ?>">
          <strong><?php echo htmlspecialchars($s['name']); ?></strong>
          <?php if ($already): ?><span class="badge badge-success ml-1">already added</span><?php endif; ?>
          <br><small class="text-muted"><?php echo count($s['steps']); ?> step(s), created as a draft (off) sequence.</small>
        </label>
      </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<?php if (!empty($tpl['automation_rules'])): ?>
<div class="card mb-3"><div class="card-header"><strong>Automation rules</strong> <span class="text-muted" dir="rtl">قواعد الأتمتة</span></div>
  <div class="card-body">
    <?php foreach ($extras['automation_rules'] as $r): $already = $r['op'] === 'skip'; ?>
      <div class="form-check mb-2">
        <input class="form-check-input ob-extra-auto" type="checkbox" value="<?php echo htmlspecialchars($r['source_key']); ?>"
               id="auto_<?php echo htmlspecialchars($r['source_key']); ?>" <?php echo $already ? 'checked disabled' : ''; ?>>
        <label class="form-check-label" for="auto_<?php echo htmlspecialchars($r['source_key']); ?>">
          <strong><?php echo htmlspecialchars($r['name']); ?></strong>
          <?php if ($already): ?><span class="badge badge-success ml-1">already added</span><?php endif; ?>
          <br><small class="text-muted">Created as a draft (off) rule, trigger: <?php echo htmlspecialchars($r['trigger_event']); ?></small>
        </label>
      </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<?php if (!empty($tpl['appointments'])): ?>
<div class="card mb-3"><div class="card-header"><strong>Appointment default</strong> <span class="text-muted" dir="rtl">ميعاد افتراضي</span></div>
  <div class="card-body">
    <?php if ($extras['has_service']): ?>
      <div class="alert alert-light mb-0">You already have at least one appointment service — this step never overwrites it.
        <br><span dir="rtl">عندك بالفعل خدمة مواعيد — الخطوة دي مش هتغيّرها.</span></div>
    <?php else: ?>
      <div class="form-check mb-2">
        <input class="form-check-input" type="checkbox" id="appt_check" value="1">
        <label class="form-check-label" for="appt_check">
          Create a starter appointment service (<?php echo (int) $tpl['appointments']['default_duration_minutes']; ?> minutes),
          created OFF — turn it on from the Appointments page when you're ready.
          <br><small class="text-muted" dir="rtl">إنشاء خدمة مواعيد افتراضية (<?php echo (int) $tpl['appointments']['default_duration_minutes']; ?> دقيقة)، هتتعمل موقوفة، تقدر تشغّلها من صفحة المواعيد.</small>
        </label>
      </div>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<?php if (!empty($tpl['media'])): ?>
<div class="card mb-3"><div class="card-header"><strong>Media to prepare</strong> <span class="text-muted" dir="rtl">صور مقترحة</span></div>
  <div class="card-body">
    <p class="text-muted">Nothing is uploaded here — these are just the photos this business type suggests you add
      through the Knowledge / Media library when you have them.</p>
    <ul class="mb-0">
      <?php foreach ($extras['media'] as $m): ?>
        <li><strong><?php echo htmlspecialchars($m['key']); ?></strong> — <?php echo htmlspecialchars($m['caption']); ?></li>
      <?php endforeach; ?>
    </ul>
  </div>
</div>
<?php endif; ?>

<button class="btn btn-primary ob-save" id="ob_go">Save extras and continue</button>
<a class="btn btn-link" href="<?php echo base_url('onboarding/step/9'); ?>">Skip this step</a>
<?php $this->load->view('admin/onboarding/_foot'); ?>
<script>
$(function () {
  $('#ob_go').on('click', function () {
    var payload = {};
    $('.ob-extra-seq:checked:not(:disabled)').each(function (i) { payload['sequences[' + i + ']'] = $(this).val(); });
    $('.ob-extra-auto:checked:not(:disabled)').each(function (i) { payload['automation_rules[' + i + ']'] = $(this).val(); });
    payload['appointment'] = $('#appt_check').is(':checked') ? '1' : '0';
    OB.post('save_extras', payload, function (r) {
      OB.msg('success', 'Saved: ' + r.sequences_created + ' sequence(s), ' + r.automation_rules_created + ' rule(s)'
        + (r.appointment_created ? ', 1 appointment service' : '') + ' — all created off.');
      setTimeout(OB.next, 900);
    });
  });
});
</script>
