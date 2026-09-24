<?php
$has_stays = !empty($cfg['accommodation']) || !empty($cfg['day_use']);
$e = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
?>
<section class="section">
  <div class="section-header"><h1><i class="fas fa-tags"></i> <?php echo $page_title; ?></h1>
    <div class="section-header-breadcrumb">
      <?php if ($has_own): ?>
      <label class="custom-switch mt-2 mb-0" title="When off, this list is not used. A page then falls back to the default list; with no list the AI hands price questions to your team.">
        <input type="checkbox" id="pr_active" class="custom-switch-input" <?php echo $active ? 'checked' : ''; ?>>
        <span class="custom-switch-indicator"></span><span class="custom-switch-description">AI uses this list</span>
      </label>
      <?php endif; ?>
    </div>
  </div>
  <div class="section-body">
    <p class="text-muted mb-2">The AI never invents a price. It asks the price list of the page the customer wrote to and quotes the exact result.
      Changes apply from the next customer message.
      <br><small>البوت مبيألفش أسعار: بياخد السعر من قايمة الصفحة اللي العميل بيكلمها، وأي تعديل بيشتغل من الرسالة الجاية.</small></p>

    <?php if ($warning !== ''): ?><div class="alert alert-warning"><?php echo $e($warning); ?></div><?php endif; ?>

    <div class="card mb-3" id="pr_scope_card"><div class="card-body py-3">
      <div class="form-row align-items-end">
        <div class="col-md-6">
          <label class="mb-1"><b>Price list for</b> <small class="text-muted">· قايمة الأسعار دي لـ</small></label>
          <select id="pr_scope" class="form-control">
            <option value="" <?php echo $scope_key === '' ? 'selected' : ''; ?>>All pages (default list)<?php echo $default_exists ? '' : ' — empty'; ?></option>
            <?php foreach ($targets as $t): ?>
              <option value="<?php echo $e($t['scope']); ?>" <?php echo $scope_key === $t['scope'] ? 'selected' : ''; ?>>
                <?php echo $e($t['label'] . ($t['note'] !== '' ? ' — ' . $t['note'] : '') . ($t['has_list'] ? '  ✓ own list' : '')); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-6 mt-2 mt-md-0">
          <?php if ($scope_key === ''): ?>
            <small class="text-muted">Every page without a list of its own uses this one. Give a page its own list to sell different products or prices there.</small>
          <?php elseif ($has_own): ?>
            <small class="text-success"><i class="fas fa-check"></i> This page has its own list. Other pages don't see it.</small>
          <?php elseif ($default_exists): ?>
            <small class="text-info" id="pr_no_own">No own list — this page currently uses the default list. Save here to give it its own.<br>الصفحة دي لسه بتستخدم القايمة العامة.</small>
          <?php else: ?>
            <small class="text-warning" id="pr_no_own">No own list and no default list — the AI will not quote prices on this page until you save one.</small>
          <?php endif; ?>
        </div>
      </div>
      <?php if (!$scoped): ?>
        <div class="alert alert-light mt-2 mb-0"><small>Per-page lists appear after the next system update. Until then this is the list every page uses.</small></div>
      <?php endif; ?>
      <div class="form-row mt-3">
        <?php if ($has_own && !empty($targets)): ?>
        <div class="col-md-6 mb-2">
          <div class="input-group input-group-sm">
            <select id="pr_copy_to" class="form-control">
              <option value="">Copy this list to…</option>
              <?php foreach ($targets as $t): if ($t['scope'] === $scope_key) continue; ?>
                <option value="<?php echo $e($t['scope']); ?>"><?php echo $e($t['label'] . ($t['note'] !== '' ? ' — ' . $t['note'] : '')); ?></option>
              <?php endforeach; ?>
              <?php if ($scope_key !== ''): ?><option value="">All pages (default list)</option><?php endif; ?>
            </select>
            <div class="input-group-append"><button class="btn btn-outline-primary" id="pr_copy"><i class="fas fa-copy"></i> Copy</button></div>
          </div>
        </div>
        <?php endif; ?>
        <?php if ($scope_key === '' && $default_exists && !empty($targets) && $scoped): ?>
        <div class="col-md-6 mb-2">
          <div class="input-group input-group-sm">
            <select id="pr_move_to" class="form-control">
              <option value="">Move the default list to a page…</option>
              <?php foreach ($targets as $t): ?>
                <option value="<?php echo $e($t['scope']); ?>"><?php echo $e($t['label'] . ($t['note'] !== '' ? ' — ' . $t['note'] : '')); ?></option>
              <?php endforeach; ?>
            </select>
            <div class="input-group-append"><button class="btn btn-outline-warning" id="pr_move"><i class="fas fa-share"></i> Move</button></div>
          </div>
          <small class="text-muted">After moving, only that page uses these prices; other pages stop quoting them.</small>
        </div>
        <?php endif; ?>
        <?php if ($scope_key !== '' && $has_own): ?>
        <div class="col-md-12"><button class="btn btn-sm btn-link text-danger p-0" id="pr_remove"><i class="fas fa-trash"></i> Remove this page's own list (it goes back to the default list)</button></div>
        <?php endif; ?>
      </div>
    </div></div>

    <div class="row">
      <div class="col-lg-8">
        <div class="form-row mb-2">
          <div class="col-md-4"><label class="mb-1">Currency label</label><input id="pr_currency" class="form-control"></div>
          <div class="col-md-8 pt-md-4"><label class="mb-0"><input type="checkbox" id="pr_stays_on" <?php echo $has_stays ? 'checked' : ''; ?>> This page sells stays / day visits
            <small class="text-muted">(rooms, day-use packages, children rules)</small></label></div>
        </div>
        <ul class="nav nav-tabs" role="tablist">
          <li class="nav-item"><a class="nav-link active" data-toggle="tab" href="#tab_items">Products &amp; services</a></li>
          <li class="nav-item pr-stay"><a class="nav-link" data-toggle="tab" href="#tab_rooms">Rooms</a></li>
          <li class="nav-item pr-stay"><a class="nav-link" data-toggle="tab" href="#tab_dayuse">Day use</a></li>
          <li class="nav-item pr-stay"><a class="nav-link" data-toggle="tab" href="#tab_children">Children</a></li>
          <li class="nav-item"><a class="nav-link" data-toggle="tab" href="#tab_json">Advanced (JSON)</a></li>
        </ul>
        <div class="tab-content card"><div class="card-body">
          <div class="tab-pane fade show active" id="tab_items">
            <p class="text-muted"><small>Anything you sell: products, services, sessions, hours, kilos… The AI multiplies price × quantity for the customer.
              Options = sizes or types with their own price, e.g. <span dir="ltr">Small=100, Large=150</span>. Other names = what customers call it.
              <br>أي حاجة بتبيعها: منتج، خدمة، جلسة، ساعة، كيلو. الاختيارات = مقاسات أو أنواع ليها سعر مختلف.</small></p>
            <div class="table-responsive"><table class="table table-sm"><thead><tr>
              <th class="pr-col-name">Name <small class="text-muted">/ category</small></th><th class="pr-col-w120">Unit</th>
              <th class="pr-col-w120">Price</th><th class="pr-col-wide">Options <small class="text-muted">name = price</small></th>
              <th class="pr-col-wide">Other names <small class="text-muted">/ note</small></th><th></th></tr></thead>
              <tbody id="pr_items"></tbody></table></div>
            <button type="button" class="btn btn-sm btn-outline-primary" id="pr_add_item"><i class="fas fa-plus"></i> Add product or service</button>
          </div>
          <div class="tab-pane fade" id="tab_rooms">
            <div class="form-row mb-2">
              <div class="col-md-12"><label>Note shown with room prices</label><input id="pr_note" class="form-control" placeholder="e.g. Price is per room, not per person"></div>
            </div>
            <table class="table table-sm"><thead><tr><th>Room name</th><th class="pr-col-w90">Guests</th><th class="pr-col-w120">Per night</th><th>Package offers <small class="text-muted">nights = total</small></th><th></th></tr></thead>
              <tbody id="pr_rooms"></tbody></table>
            <button type="button" class="btn btn-sm btn-outline-primary" id="pr_add_room"><i class="fas fa-plus"></i> Add room</button>
          </div>
          <div class="tab-pane fade" id="tab_dayuse">
            <table class="table table-sm"><thead><tr><th>Package name</th><th class="pr-col-w150">Price per person</th><th></th></tr></thead>
              <tbody id="pr_packages"></tbody></table>
            <button type="button" class="btn btn-sm btn-outline-primary" id="pr_add_pkg"><i class="fas fa-plus"></i> Add package</button>
            <div class="form-row mt-3">
              <div class="col-md-12"><label class="mb-1"><input type="checkbox" id="pr_nf_on"> Group offer</label></div>
              <div class="col-md-3"><label>Every</label><input type="number" min="2" id="pr_nf_every" class="form-control"></div>
              <div class="col-md-3"><label>Pay for</label><input type="number" min="1" id="pr_nf_pay" class="form-control"></div>
              <div class="col-md-6 pt-4 text-muted"><small>e.g. every 5 guests pay for 4</small></div>
            </div>
          </div>
          <div class="tab-pane fade" id="tab_children">
            <div class="form-row">
              <div class="col-md-4"><label>Free up to age</label><input type="number" min="0" id="pr_c_free_max" class="form-control"></div>
              <div class="col-md-4"><label>Max free children</label><input type="number" min="0" id="pr_c_free_cap" class="form-control"></div>
              <div class="col-md-4"><label>Full price from age</label><input type="number" min="0" id="pr_c_full_min" class="form-control"></div>
              <div class="col-md-4 mt-2"><label>Half price from age</label><input type="number" min="0" id="pr_c_half_min" class="form-control"></div>
              <div class="col-md-4 mt-2"><label>Half price up to age</label><input type="number" min="0" id="pr_c_half_max" class="form-control"></div>
              <div class="col-md-4 mt-2 pt-4"><label><input type="checkbox" id="pr_c_half_known"> Half price is confirmed</label>
                <br><small class="text-muted">Unchecked = the AI asks your team for children in the half-price band.</small></div>
            </div>
          </div>
          <div class="tab-pane fade" id="tab_json">
            <p class="text-muted mb-1"><small>Full configuration, including fields not shown in the form (media, room aliases). Edits here replace the form.</small></p>
            <textarea id="pr_json" class="form-control pr-json-area" dir="ltr"></textarea>
            <button type="button" class="btn btn-sm btn-outline-secondary mt-2" id="pr_json_apply">Apply JSON to form</button>
          </div>
        </div></div>

        <div id="pr_errors" class="alert alert-danger" style="display:none"></div>
        <div class="form-inline">
          <input id="pr_summary" class="form-control mr-2 pr-summary-input" placeholder="What changed? (shown in history)">
          <button class="btn btn-primary" id="pr_save"><i class="fas fa-save"></i> Save prices<?php echo $scope_key !== '' ? ' for this page' : ''; ?></button>
        </div>
      </div>

      <div class="col-lg-4">
        <div class="card"><div class="card-header"><h4>Test a quote</h4></div><div class="card-body">
          <p class="text-muted"><small>Runs the same calculator the AI uses, on what you see now (even unsaved).</small></p>
          <select id="t_kind" class="form-control mb-2"><option value="item">Product / service</option><option value="accommodation" class="pr-stay-opt">Stay</option><option value="day_use" class="pr-stay-opt">Day use</option></select>
          <input id="t_item" class="form-control mb-2" placeholder="Product / room / package">
          <div class="form-row t-item">
            <div class="col-7 mb-2"><label class="mb-0"><small>Option</small></label><input id="t_variant" class="form-control" placeholder="optional"></div>
            <div class="col-5 mb-2"><label class="mb-0"><small>Quantity</small></label><input id="t_qty" type="number" min="0" step="any" value="1" class="form-control"></div>
          </div>
          <div class="form-row t-stay" style="display:none">
            <div class="col-6 mb-2"><label class="mb-0"><small>Nights</small></label><input id="t_nights" type="number" min="1" value="1" class="form-control"></div>
            <div class="col-6 mb-2"><label class="mb-0"><small>Adults</small></label><input id="t_adults" type="number" min="0" value="2" class="form-control"></div>
            <div class="col-4"><label class="mb-0"><small>Kids free</small></label><input id="t_cfree" type="number" min="0" value="0" class="form-control"></div>
            <div class="col-4"><label class="mb-0"><small>Kids half</small></label><input id="t_chalf" type="number" min="0" value="0" class="form-control"></div>
            <div class="col-4"><label class="mb-0"><small>Kids full</small></label><input id="t_cfull" type="number" min="0" value="0" class="form-control"></div>
          </div>
          <button class="btn btn-outline-primary btn-block mt-3" id="t_run">Calculate</button>
          <div id="t_out" class="mt-3"></div>
        </div></div>

        <div class="card"><div class="card-header"><h4>History</h4></div><div class="card-body p-0">
          <?php if (empty($history)): ?><p class="text-muted p-3 mb-0">No changes yet.</p><?php else: ?>
          <ul class="list-group list-group-flush">
            <?php foreach ($history as $h): ?>
              <li class="list-group-item"><small class="text-muted"><?php echo $e($h['created_at']); ?> · <?php echo $e($h['name'] ?? '—'); ?></small><br>
                <span class="badge badge-light mb-1"><i class="fas fa-map-pin"></i> <?php echo $e($h['for']); ?></span><br>
                <?php echo $e($h['summary'] ?: 'Prices updated'); ?></li>
            <?php endforeach; ?>
          </ul><?php endif; ?>
        </div></div>
      </div>
    </div>
  </div>
</section>

<script>
(function () {
  var TOKEN = "<?php echo $this->session->userdata('csrf_token_session'); ?>", BASE = "<?php echo base_url(); ?>";
  var SCOPE = <?php echo json_encode($scope_key); ?>;
  var UNITS = {item: 'Item', piece: 'Piece', hour: 'Hour', session: 'Session', person: 'Person', night: 'Night', day: 'Day', month: 'Month', kg: 'Kg', custom: 'Other…'};
  // Whole config lives here; the form edits it in place so unknown keys (media, aliases) survive.
  var cfg = <?php echo json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP); ?>;
  var esc = function (s) { return $('<div>').text(s == null ? '' : String(s)).html(); };
  var stays = function () { return $('#pr_stays_on').is(':checked'); };
  var splitList = function (t) { return (t || '').split(/[,،]/).map(function (x) { return x.trim(); }).filter(function (x) { return x !== ''; }); };
  function ensure() {
    cfg.catalog = cfg.catalog || {items: []}; cfg.catalog.items = cfg.catalog.items || [];
    if (stays()) {
      cfg.accommodation = cfg.accommodation || {rooms: []}; cfg.accommodation.rooms = cfg.accommodation.rooms || [];
      cfg.day_use = cfg.day_use || {packages: []}; cfg.day_use.packages = cfg.day_use.packages || [];
      cfg.children = cfg.children || {free_max_age: 5, free_cap: 2, half_min_age: 6, half_max_age: 11, full_min_age: 12, half_price_known: false};
    }
  }
  function offersText(o) { return o ? Object.keys(o).map(function (k) { return k + '=' + o[k]; }).join(', ') : ''; }
  function parseOffers(t) {
    var o = {}; (t || '').split(',').forEach(function (p) { var m = p.split('='); if (m.length === 2 && m[0].trim() !== '') o[m[0].trim()] = Number(m[1]); });
    return Object.keys(o).length ? o : undefined;
  }
  function variantsText(v) { return (v || []).map(function (x) { return x.name + '=' + (x.price == null ? '' : x.price); }).join(', '); }
  function parseVariants(t, old) {
    var byName = {}; (old || []).forEach(function (x) { byName[x.name] = x; });
    var out = splitList(t).map(function (p) {
      var i = p.lastIndexOf('='), name = (i < 0 ? p : p.slice(0, i)).trim(), price = i < 0 ? '' : p.slice(i + 1).trim();
      var v = $.extend({}, byName[name] || {}); v.name = name; v.price = price === '' ? undefined : Number(price); return v;
    });
    return out.length ? out : undefined;
  }
  function showStays() {
    $('.pr-stay').toggle(stays()); $('.pr-stay-opt').prop('disabled', !stays());
    if (!stays() && $('#t_kind').val() !== 'item') $('#t_kind').val('item').trigger('change');
  }
  function render() {
    ensure();
    $('#pr_currency').val(cfg.currency || '');
    $('#pr_items').html(cfg.catalog.items.map(function (it, i) {
      var u = it.unit || 'item';
      return '<tr data-i="' + i + '"><td><input class="form-control form-control-sm i_name mb-1" placeholder="Name" value="' + esc(it.name) + '">' +
        '<input class="form-control form-control-sm i_cat" placeholder="Category (optional)" value="' + esc(it.category) + '"></td>' +
        '<td><select class="form-control form-control-sm i_unit mb-1">' + Object.keys(UNITS).map(function (k) { return '<option value="' + k + '"' + (k === u ? ' selected' : '') + '>' + UNITS[k] + '</option>'; }).join('') + '</select>' +
        '<input class="form-control form-control-sm i_ulabel" placeholder="Unit name" value="' + esc(it.unit_label) + '"' + (u === 'custom' ? '' : ' style="display:none"') + '></td>' +
        '<td><input type="number" min="0" step="any" class="form-control form-control-sm i_price mb-1" value="' + esc(it.price) + '"' + (it.price_on_request ? ' disabled' : '') + '>' +
        '<label class="mb-0"><small><input type="checkbox" class="i_por"' + (it.price_on_request ? ' checked' : '') + '> Price on request</small></label></td>' +
        '<td><input class="form-control form-control-sm i_vars" placeholder="Small=100, Large=150" value="' + esc(variantsText(it.variants)) + '"></td>' +
        '<td><input class="form-control form-control-sm i_alias mb-1" placeholder="e.g. haircut, قص" value="' + esc((it.aliases || []).join(', ')) + '">' +
        '<input class="form-control form-control-sm i_note" placeholder="Note (optional)" value="' + esc(it.note) + '"></td>' +
        '<td><button class="btn btn-sm btn-link text-danger i_del" title="Remove">×</button></td></tr>';
    }).join('') || '<tr class="pr-empty"><td colspan="6" class="text-muted"><small>No products or services yet.</small></td></tr>');
    if (stays()) {
      $('#pr_note').val(cfg.accommodation.note || '');
      $('#pr_rooms').html(cfg.accommodation.rooms.map(function (r, i) {
        return '<tr data-i="' + i + '"><td><input class="form-control form-control-sm r_name" value="' + esc(r.name) + '"></td>' +
          '<td><input type="number" min="1" class="form-control form-control-sm r_cap" value="' + esc(r.capacity) + '"></td>' +
          '<td><input type="number" min="0" class="form-control form-control-sm r_night" value="' + esc(r.nightly) + '"></td>' +
          '<td><input class="form-control form-control-sm r_offers" dir="ltr" placeholder="4=11500" value="' + esc(offersText(r.offers)) + '"></td>' +
          '<td><button class="btn btn-sm btn-link text-danger r_del">×</button></td></tr>';
      }).join(''));
      $('#pr_packages').html(cfg.day_use.packages.map(function (p, i) {
        return '<tr data-i="' + i + '"><td><input class="form-control form-control-sm p_name" value="' + esc(p.name) + '"></td>' +
          '<td><input type="number" min="0" class="form-control form-control-sm p_price" value="' + esc(p.per_person) + '"></td>' +
          '<td><button class="btn btn-sm btn-link text-danger p_del">×</button></td></tr>';
      }).join(''));
      var nf = cfg.day_use.nth_free; $('#pr_nf_on').prop('checked', !!nf);
      $('#pr_nf_every').val(nf ? nf.every : ''); $('#pr_nf_pay').val(nf ? nf.pay : '');
      var c = cfg.children;
      $('#pr_c_free_max').val(c.free_max_age); $('#pr_c_free_cap').val(c.free_cap); $('#pr_c_full_min').val(c.full_min_age);
      $('#pr_c_half_min').val(c.half_min_age); $('#pr_c_half_max').val(c.half_max_age); $('#pr_c_half_known').prop('checked', !!c.half_price_known);
    }
    showStays();
    $('#pr_json').val(JSON.stringify(cfg, null, 2));
  }
  function collect() {
    ensure();
    var n = function (v) { return v === '' || v == null ? undefined : Number(v); };
    var opt = function (o, k, v) { if (v === '' || v == null) delete o[k]; else o[k] = v; };
    cfg.currency = $('#pr_currency').val();
    $('#pr_items tr[data-i]').each(function () {
      var it = cfg.catalog.items[$(this).data('i')], unit = $(this).find('.i_unit').val();
      it.name = $(this).find('.i_name').val().trim(); opt(it, 'category', $(this).find('.i_cat').val().trim());
      it.unit = unit; opt(it, 'unit_label', unit === 'custom' ? $(this).find('.i_ulabel').val().trim() : '');
      var por = $(this).find('.i_por').is(':checked'); if (por) it.price_on_request = true; else delete it.price_on_request;
      opt(it, 'price', n($(this).find('.i_price').val()));
      var v = parseVariants($(this).find('.i_vars').val(), it.variants); if (v) it.variants = v; else delete it.variants;
      var a = splitList($(this).find('.i_alias').val()); if (a.length) it.aliases = a; else delete it.aliases;
      opt(it, 'note', $(this).find('.i_note').val().trim());
    });
    if (!cfg.catalog.items.length && Object.keys(cfg.catalog).length === 1) delete cfg.catalog;   // keep stay-only lists unchanged
    if (stays()) {
      cfg.accommodation.note = $('#pr_note').val();
      $('#pr_rooms tr').each(function () {
        var r = cfg.accommodation.rooms[$(this).data('i')];
        r.name = $(this).find('.r_name').val().trim(); r.capacity = n($(this).find('.r_cap').val());
        r.nightly = n($(this).find('.r_night').val());
        var o = parseOffers($(this).find('.r_offers').val()); if (o) r.offers = o; else delete r.offers;
      });
      $('#pr_packages tr').each(function () {
        var p = cfg.day_use.packages[$(this).data('i')];
        p.name = $(this).find('.p_name').val().trim(); p.per_person = n($(this).find('.p_price').val());
      });
      cfg.day_use.nth_free = $('#pr_nf_on').is(':checked') ? {every: n($('#pr_nf_every').val()), pay: n($('#pr_nf_pay').val())} : null;
      var c = cfg.children;
      c.free_max_age = n($('#pr_c_free_max').val()); c.free_cap = n($('#pr_c_free_cap').val()); c.full_min_age = n($('#pr_c_full_min').val());
      c.half_min_age = n($('#pr_c_half_min').val()); c.half_max_age = n($('#pr_c_half_max').val()); c.half_price_known = $('#pr_c_half_known').is(':checked');
      if (!cfg.day_use.packages.length && !cfg.day_use.nth_free) delete cfg.day_use;
      if (!cfg.accommodation.rooms.length && !cfg.accommodation.note && !cfg.day_use) delete cfg.accommodation;
    }
    $('#pr_json').val(JSON.stringify(cfg, null, 2));
    return cfg;
  }
  function showErrors(list) {
    if (!list || !list.length) { $('#pr_errors').hide(); return; }
    $('#pr_errors').html('<b>Please fix:</b><ul class="mb-0">' + list.map(function (e) { return '<li>' + esc(e) + '</li>'; }).join('') + '</ul>').show();
  }
  function toast(ok, msg) { (typeof iziToast !== 'undefined') ? iziToast[ok ? 'success' : 'error']({message: msg, position: 'topRight'}) : alert(msg); }
  function go(scope) { location.href = BASE + 'pricing' + (scope ? '?scope=' + encodeURIComponent(scope) : ''); }

  $('#pr_scope').on('change', function () { go($(this).val()); });
  $('#pr_stays_on').on('change', function () {
    collect();
    if (!stays()) {
      var busy = (cfg.accommodation && (cfg.accommodation.rooms || []).length) || (cfg.day_use && (cfg.day_use.packages || []).length);
      if (busy && !confirm('Remove the rooms and day-use packages from this list?')) { $(this).prop('checked', true); return; }
      delete cfg.accommodation; delete cfg.day_use; delete cfg.children;
    }
    render();
  });
  $('#pr_add_item').on('click', function () { collect(); ensure(); cfg.catalog.items.push({name: '', unit: 'item', price: ''}); render(); });
  $(document).on('click', '.i_del', function () { collect(); ensure(); cfg.catalog.items.splice($(this).closest('tr').data('i'), 1); render(); });
  $(document).on('change', '.i_unit', function () { $(this).closest('td').find('.i_ulabel').toggle($(this).val() === 'custom'); });
  $(document).on('change', '.i_por', function () { $(this).closest('td').find('.i_price').prop('disabled', this.checked); });
  $('#pr_add_room').on('click', function () { collect(); ensure(); cfg.accommodation.rooms.push({name: '', capacity: 2, nightly: ''}); render(); });
  $('#pr_add_pkg').on('click', function () { collect(); ensure(); cfg.day_use.packages.push({name: '', per_person: ''}); render(); });
  $(document).on('click', '.r_del', function () { collect(); cfg.accommodation.rooms.splice($(this).closest('tr').data('i'), 1); render(); });
  $(document).on('click', '.p_del', function () { collect(); cfg.day_use.packages.splice($(this).closest('tr').data('i'), 1); render(); });
  $('a[data-toggle="tab"][href="#tab_json"]').on('show.bs.tab', collect);
  $('#pr_json_apply').on('click', function () {
    try { cfg = JSON.parse($('#pr_json').val()); $('#pr_stays_on').prop('checked', !!(cfg.accommodation || cfg.day_use)); render(); showErrors([]); }
    catch (e) { showErrors(['Invalid JSON: ' + e.message]); }
  });
  $('#pr_save').on('click', function () {
    var btn = $(this).prop('disabled', true);
    $.post(BASE + 'pricing/save', {csrf_token: TOKEN, scope: SCOPE, config_json: JSON.stringify(collect()), summary: $('#pr_summary').val()}, function (r) {
      btn.prop('disabled', false);
      if (r.status === '1') { showErrors([]); toast(true, r.message); setTimeout(function () { go(SCOPE); }, 900); }
      else showErrors(r.errors || [r.message || 'Save failed']);
    }, 'json').fail(function () { btn.prop('disabled', false); showErrors(['Network error']); });
  });
  function scopeAction(url, to, label) {
    $.post(BASE + url, {csrf_token: TOKEN, scope: SCOPE, to: to}, function r1(r) {
      if (r.status === '1') { toast(true, r.message); setTimeout(function () { go(r.scope); }, 900); return; }
      if (r.code === 'exists' && confirm(label + ' already has its own price list. Replace it?')) {
        $.post(BASE + url, {csrf_token: TOKEN, scope: SCOPE, to: to, overwrite: '1'}, function (r2) {
          if (r2.status === '1') { toast(true, r2.message); setTimeout(function () { go(r2.scope); }, 900); } else showErrors(r2.errors || ['Failed']);
        }, 'json');
        return;
      }
      if (r.code !== 'exists') showErrors(r.errors || ['Failed']);
    }, 'json');
  }
  $('#pr_copy').on('click', function () {
    var o = $('#pr_copy_to option:selected'); if (!o.index()) return;
    scopeAction('pricing/copy', o.val(), o.text().trim());
  });
  $('#pr_move').on('click', function () {
    var o = $('#pr_move_to option:selected'); if (!o.val()) return;
    if (!confirm('Move the default list to "' + o.text().trim() + '"? Other pages will stop quoting these prices.')) return;
    scopeAction('pricing/move', o.val(), o.text().trim());
  });
  $('#pr_remove').on('click', function () {
    if (!confirm('Remove this page\'s own list? The page goes back to the default list.')) return;
    $.post(BASE + 'pricing/remove', {csrf_token: TOKEN, scope: SCOPE}, function (r) {
      if (r.status === '1') { toast(true, r.message); setTimeout(function () { go(SCOPE); }, 900); } else showErrors(r.errors || ['Failed']);
    }, 'json');
  });
  $('#t_kind').on('change', function () { var it = $(this).val() === 'item'; $('.t-item').toggle(it); $('.t-stay').toggle(!it); });
  $('#t_run').on('click', function () {
    $.post(BASE + 'pricing/calc', {csrf_token: TOKEN, config_json: JSON.stringify(collect()), kind: $('#t_kind').val(), item: $('#t_item').val(),
      variant: $('#t_variant').val(), quantity: $('#t_qty').val(),
      nights: $('#t_nights').val(), adults: $('#t_adults').val(), c_free: $('#t_cfree').val(), c_half: $('#t_chalf').val(), c_full: $('#t_cfull').val()}, function (r) {
      if (r.status !== '1') { $('#t_out').html('<div class="text-danger">' + (r.errors || []).map(esc).join('<br>') + '</div>'); return; }
      var x = r.result;
      if (!x.ok) { $('#t_out').html('<div class="text-danger">' + esc(x.reason === 'unknown' ? 'Not in this price list — the AI will hand it to your team.' : x.error) + '</div>'); return; }
      var head = x.total === null ? 'Team confirms price' : esc(x.total) + ' ' + esc(x.currency || '');
      var line = '<b>' + esc(x.item) + '</b>' + (x.variant ? ' · ' + esc(x.variant) : '') + (x.nights ? ' · ' + esc(x.nights) + ' night(s)' : '') +
        (x.kind === 'item' && x.unit_price != null ? ' · ' + esc(x.quantity) + ' × ' + esc(x.unit_price) : '');
      var cands = (x.candidates || []).length ? '<div class="mt-1"><small>The AI will ask which one: ' + x.candidates.map(esc).join(' / ') + '</small></div>' : '';
      $('#t_out').html('<div class="h5 mb-1">' + head + '</div><div>' + line + '</div>' + cands +
        (x.needs_team ? '<div class="badge badge-warning mt-1">AI will ask your team to confirm</div>' : '') +
        '<ul class="mt-2 mb-0 pl-3">' + (x.notes || []).map(function (n) { return '<li><small>' + esc(n) + '</small></li>'; }).join('') + '</ul>');
    }, 'json');
  });
  $('#pr_active').on('change', function () {
    $.post(BASE + 'pricing/toggle', {csrf_token: TOKEN, scope: SCOPE, active: this.checked ? '1' : '0'}, null, 'json');
  });
  render();
})();
</script>
