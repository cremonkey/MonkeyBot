<?php $this->load->view('admin/onboarding/_shell'); ?>
<?php
/* Step 5 acts only on the pages chosen in step 2. A page that already has its own price list
 * keeps it: it is listed here with a link to edit it on Products & Pricing, never overwritten. */
$to_write = 0;
foreach ($pages as $p) if (empty($p['has_own'])) $to_write++;
$can_default = (int) $lists_total === 0 && !empty($pages);
$h = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
?>

<div class="card" id="pr_pages"><div class="card-body">
  <p class="mb-2"><strong>Pages of this business</strong> <small class="text-muted" dir="rtl">صفحات النشاط ده</small></p>
  <table class="table table-sm mb-2">
    <tbody>
    <?php foreach ($pages as $p): ?>
      <tr class="pr-page" data-key="<?php echo $h($p['key']); ?>">
        <td><?php echo $h($p['label']); ?><?php if ($p['note'] !== ''): ?> <small class="text-muted"><?php echo $h($p['note']); ?></small><?php endif; ?></td>
        <td>
          <?php if (!empty($p['has_own'])): ?>
            <span class="badge badge-success pr-has-list">Already has a price list</span>
            <?php if (empty($p['own_active'])): ?><span class="badge badge-warning">switched off</span><?php endif; ?>
            <small class="text-muted d-block" dir="rtl">عنده قايمة أسعار — هنسيبها زي ما هي</small>
          <?php elseif (!empty($p['resolves'])): ?>
            <span class="badge badge-info">Uses the list for all pages</span>
            <small class="text-muted d-block">Will get its own list from this step.</small>
          <?php else: ?>
            <span class="badge badge-light">No price list yet</span>
            <small class="text-muted d-block">Will get the list from this step.</small>
          <?php endif; ?>
        </td>
        <td class="text-right">
          <?php if (!empty($can_pricing)): ?>
          <a class="btn btn-sm btn-outline-secondary pr-open" target="_blank"
             href="<?php echo base_url('pricing?scope=' . rawurlencode($p['scope_key'])); ?>">Open in Products &amp; Pricing</a>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php if ($to_write === 0 && !empty($pages)): ?>
    <p class="mb-0 text-muted">Every page here already has its own price list, so this step changes nothing.
      <?php if (!empty($can_pricing)): ?>Edit them on <a href="<?php echo base_url('pricing'); ?>" target="_blank">Products &amp; Pricing</a>.<?php endif; ?></p>
  <?php endif; ?>
  <?php if ($can_default): ?>
    <div class="custom-control custom-checkbox mt-2">
      <input type="checkbox" class="custom-control-input" id="pr_all">
      <label class="custom-control-label" for="pr_all">Use this list for all my pages
        <small class="text-muted d-block">Saves it as the account-wide list that every page without its own list uses — including pages you add later.
          <span dir="rtl">استخدم القايمة دي لكل صفحاتي</span></small></label>
    </div>
  <?php endif; ?>
</div></div>

<?php if (empty($tpl['pricing_supported'])): ?>

  <p class="text-muted">Add a few of your products or services with their price. Your AI employee quotes these exactly — it never invents a price.
    <br><span dir="rtl">ضيف شوية من منتجاتك أو خدماتك بسعرها. المساعد بيقول الأسعار دي بالظبط وعمره ما بيألّف سعر.</span></p>

  <div class="card"><div class="card-body">
    <div class="form-group col-md-4 px-0">
      <label>Currency word <small class="text-muted" dir="rtl">كلمة العملة</small></label>
      <input id="pr_currency" class="form-control" value="جنيه">
    </div>
    <h6>Products &amp; services <small class="text-muted" dir="rtl">المنتجات والخدمات</small></h6>
    <table class="table table-sm">
      <thead><tr><th>Name</th><th class="ob-col-price">Price</th><th class="ob-col-per">Per <small class="text-muted">(optional)</small></th></tr></thead>
      <tbody id="pr_items"></tbody>
    </table>
    <button type="button" class="btn btn-sm btn-outline-primary" id="pr_add_item"><i class="fas fa-plus"></i> Add item</button>
    <p class="text-muted mt-3 mb-0"><small>Options, sizes, other names and "price on request" are set on the Products &amp; Pricing page.</small></p>
  </div></div>

  <div class="alert alert-light">
    <strong>Rather not list prices?</strong> Skip — your prices stay as answers, not as a calculator.
    <?php echo $h($tpl['pricing_note']['en']); ?>
    <span class="d-block" dir="rtl"><?php echo $h($tpl['pricing_note']['ar']); ?></span>
    Write your fees into the answers on <a href="<?php echo base_url('onboarding/step/4'); ?>">Knowledge</a>, or upload a price list on the
    <a href="<?php echo base_url('ai_knowledge_base'); ?>" target="_blank">Knowledge</a> page.
  </div>

  <button class="btn btn-primary ob-save" id="ob_go"><?php echo $to_write > 0 ? 'Save products and continue' : 'Continue'; ?></button>
  <button class="btn btn-link ob-save" id="ob_skip">Skip — keep prices as answers</button>

<?php else: ?>

  <p class="text-muted">A starter price list is ready below. Replace the example names and numbers with yours. Your AI employee quotes these exactly — it never invents a price.
    <br><span dir="rtl">في قايمة أسعار مبدئية تحت. غيّر الأسماء والأرقام بتاعتك. المساعد بيقول الأسعار دي بالظبط وعمره ما بيألّف سعر.</span></p>

  <div class="card"><div class="card-body">
    <div class="form-group col-md-4 px-0">
      <label>Currency word <small class="text-muted" dir="rtl">كلمة العملة</small></label>
      <input id="pr_currency" class="form-control" value="<?php echo $h($cfg['currency'] ?? 'جنيه'); ?>">
    </div>
    <h6>Rooms <small class="text-muted" dir="rtl">الغرف</small></h6>
    <table class="table table-sm">
      <thead><tr><th>Room name</th><th class="ob-col-guests">Guests</th><th class="ob-col-price">Per night</th></tr></thead>
      <tbody id="pr_rooms"></tbody>
    </table>
    <button type="button" class="btn btn-sm btn-outline-primary" id="pr_add_room"><i class="fas fa-plus"></i> Add room</button>

    <h6 class="mt-4">Day visit <small class="text-muted" dir="rtl">اليوم الواحد</small></h6>
    <table class="table table-sm">
      <thead><tr><th>Package name</th><th class="ob-col-pp">Price per person</th></tr></thead>
      <tbody id="pr_pkgs"></tbody>
    </table>
    <button type="button" class="btn btn-sm btn-outline-primary" id="pr_add_pkg"><i class="fas fa-plus"></i> Add package</button>
    <p class="text-muted mt-3 mb-0"><small>Children's age bands and multi-night offers come from the template and are kept as they are. Fine-tune them on the Products &amp; Pricing page.</small></p>
  </div></div>

  <button class="btn btn-primary ob-save" id="ob_go"><?php echo $to_write > 0 ? 'Save prices and continue' : 'Continue'; ?></button>

<?php endif; ?>
<?php $this->load->view('admin/onboarding/_foot'); ?>
<script>
$(function () {
  function useAll() { return $('#pr_all').is(':checked') ? '1' : '0'; }
  function done(r) {
    var kept = r.kept_existing || [], saved = r.saved_pages || [], m = [];
    if (r.mode === 'faq_only') { OB.next(); return; }
    if (saved.length) m.push('Saved on: ' + saved.join(', ') + '.');
    if (kept.length) m.push(kept.length + ' page(s) already had a price list and were left as they are: ' + kept.join(', ') + '.');
    if (m.length) { OB.msg('success', $('<div>').text(m.join(' ')).html()); setTimeout(OB.next, kept.length ? 2500 : 900); }
    else OB.next();
  }
<?php if (empty($tpl['pricing_supported'])): ?>
  var UNITS = <?php echo json_encode(array_values(array_diff(array_keys($units), array('custom')))); ?>;
  function itemRow(it) {
    var $u = $('<select class="form-control form-control-sm i-unit">');
    UNITS.forEach(function (u) { $u.append($('<option>').val(u).text(u === 'item' ? '—' : u)); });
    $u.val(it.unit || 'item');
    return $('<tr>').append(
      $('<td>').append($('<input class="form-control form-control-sm i-name" dir="auto">').val(it.name || '')),
      $('<td>').append($('<input type="number" min="0" step="any" class="form-control form-control-sm i-price">').val(it.price || '')),
      $('<td>').append($u)
    );
  }
  for (var i = 0; i < 3; i++) $('#pr_items').append(itemRow({}));
  $('#pr_add_item').on('click', function () { $('#pr_items').append(itemRow({})); });
  $('#ob_go').on('click', function () {
    var p = {currency: $('#pr_currency').val(), use_for_all: useAll()}, n = 0;
    $('#pr_items tr').each(function () {
      var $t = $(this);
      p['items[' + n + '][name]'] = $t.find('.i-name').val();
      p['items[' + n + '][price]'] = $t.find('.i-price').val();
      p['items[' + n + '][unit]'] = $t.find('.i-unit').val();
      n++;
    });
    OB.post('save_pricing', p, done);
  });
  $('#ob_skip').on('click', function () { OB.post('save_pricing', {skip: '1'}, function () { OB.next(); }); });
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
    OB.post('save_pricing', {config_json: JSON.stringify(out), use_for_all: useAll()}, done);
  });
<?php endif; ?>
});
</script>
