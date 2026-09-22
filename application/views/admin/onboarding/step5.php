<?php $this->load->view('admin/onboarding/_shell'); ?>
<?php if (empty($tpl['pricing_supported'])): ?>

  <div class="alert alert-info">
    <h5 class="mb-2">Your prices stay as answers, not as a calculator</h5>
    <p class="mb-2"><?php echo htmlspecialchars($tpl['pricing_note']['en']); ?></p>
    <p class="mb-0" dir="rtl"><?php echo htmlspecialchars($tpl['pricing_note']['ar']); ?></p>
  </div>
  <div class="card"><div class="card-body">
    <p class="mb-2"><strong>What to do instead</strong> <small class="text-muted" dir="rtl">اعمل إيه بدالها</small></p>
    <ol class="mb-0">
      <li>Write your fees into the answers on the previous step — <a href="<?php echo base_url('onboarding/step/4'); ?>">Knowledge</a>.</li>
      <li>Upload a full price list as a document on the <a href="<?php echo base_url('ai_knowledge_base'); ?>" target="_blank">Knowledge</a> page.</li>
      <li>Your AI employee quotes only what is written there, and says the team will confirm anything that is not.</li>
    </ol>
  </div></div>
  <button class="btn btn-primary ob-save" id="ob_go">Understood, continue</button>

<?php else: ?>

  <p class="text-muted">A starter price list is ready below. Replace the example names and numbers with yours. Your AI employee quotes these exactly — it never invents a price.
    <br><span dir="rtl">في قايمة أسعار مبدئية تحت. غيّر الأسماء والأرقام بتاعتك. المساعد بيقول الأسعار دي بالظبط وعمره ما بيألّف سعر.</span></p>
  <?php if ($cfg_saved): ?>
    <div class="alert alert-light">You already have a saved price list — it is loaded below. Full editing, the test calculator and the change history live on the
      <a href="<?php echo base_url('pricing'); ?>" target="_blank">Products &amp; Pricing</a> page.</div>
  <?php endif; ?>

  <div class="card"><div class="card-body">
    <div class="form-group col-md-4 px-0">
      <label>Currency word <small class="text-muted" dir="rtl">كلمة العملة</small></label>
      <input id="pr_currency" class="form-control" value="<?php echo htmlspecialchars($cfg['currency'] ?? 'جنيه'); ?>">
    </div>
    <h6>Rooms <small class="text-muted" dir="rtl">الغرف</small></h6>
    <table class="table table-sm">
      <thead><tr><th>Room name</th><th style="width:110px">Guests</th><th style="width:140px">Per night</th></tr></thead>
      <tbody id="pr_rooms"></tbody>
    </table>
    <button type="button" class="btn btn-sm btn-outline-primary" id="pr_add_room"><i class="fas fa-plus"></i> Add room</button>

    <h6 class="mt-4">Day visit <small class="text-muted" dir="rtl">اليوم الواحد</small></h6>
    <table class="table table-sm">
      <thead><tr><th>Package name</th><th style="width:170px">Price per person</th></tr></thead>
      <tbody id="pr_pkgs"></tbody>
    </table>
    <button type="button" class="btn btn-sm btn-outline-primary" id="pr_add_pkg"><i class="fas fa-plus"></i> Add package</button>
    <p class="text-muted mt-3 mb-0"><small>Children's age bands and multi-night offers come from the template and are kept as they are. Fine-tune them on the Products &amp; Pricing page.</small></p>
  </div></div>

  <button class="btn btn-primary ob-save" id="ob_go">Save prices and continue</button>

<?php endif; ?>
<?php $this->load->view('admin/onboarding/_foot'); ?>
<script>
$(function () {
<?php if (empty($tpl['pricing_supported'])): ?>
  $('#ob_go').on('click', function () { OB.post('save_pricing', {}, function () { OB.next(); }); });
<?php else: ?>
  var CFG = <?php echo json_encode($cfg, JSON_UNESCAPED_UNICODE); ?> || {};
  function roomRow(r) {
    return $('<tr>').append(
      $('<td>').append($('<input class="form-control form-control-sm r-name" dir="rtl">').val(r.name || '')),
      $('<td>').append($('<input type="number" min="1" class="form-control form-control-sm r-cap">').val(r.capacity || 2)),
      $('<td>').append($('<input type="number" min="1" class="form-control form-control-sm r-night">').val(r.nightly || 0))
    ).data('offers', r.offers || {});
  }
  function pkgRow(p) {
    return $('<tr>').append(
      $('<td>').append($('<input class="form-control form-control-sm p-name" dir="rtl">').val(p.name || '')),
      $('<td>').append($('<input type="number" min="1" class="form-control form-control-sm p-price">').val(p.per_person || 0))
    );
  }
  ((CFG.accommodation || {}).rooms || []).forEach(function (r) { $('#pr_rooms').append(roomRow(r)); });
  ((CFG.day_use || {}).packages || []).forEach(function (p) { $('#pr_pkgs').append(pkgRow(p)); });
  $('#pr_add_room').on('click', function () { $('#pr_rooms').append(roomRow({capacity: 2, nightly: 1000})); });
  $('#pr_add_pkg').on('click', function () { $('#pr_pkgs').append(pkgRow({per_person: 500})); });

  $('#ob_go').on('click', function () {
    var out = JSON.parse(JSON.stringify(CFG));
    out.currency = $('#pr_currency').val();
    out.accommodation = out.accommodation || {};
    out.accommodation.rooms = $('#pr_rooms tr').map(function () {
      var $t = $(this);
      return {name: $t.find('.r-name').val(), capacity: parseInt($t.find('.r-cap').val(), 10) || 1,
              nightly: parseInt($t.find('.r-night').val(), 10) || 0, offers: $t.data('offers') || {}};
    }).get();
    out.day_use = out.day_use || {};
    out.day_use.packages = $('#pr_pkgs tr').map(function () {
      var $t = $(this);
      return {name: $t.find('.p-name').val(), per_person: parseInt($t.find('.p-price').val(), 10) || 0};
    }).get();
    OB.post('save_pricing', {config_json: JSON.stringify(out)}, function () { OB.next(); });
  });
<?php endif; ?>
});
</script>
