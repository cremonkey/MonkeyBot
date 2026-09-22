<section class="section">
  <div class="section-header"><h1><i class="fas fa-tags"></i> <?php echo $page_title; ?></h1>
    <div class="section-header-breadcrumb">
      <label class="custom-switch mt-2 mb-0" title="When off, the AI stops quoting computed prices and hands price questions to your team">
        <input type="checkbox" id="pr_active" class="custom-switch-input" <?php echo $active ? 'checked' : ''; ?>>
        <span class="custom-switch-indicator"></span><span class="custom-switch-description">AI uses these prices</span>
      </label>
    </div>
  </div>
  <div class="section-body">
    <p class="text-muted">The AI never invents a price. It asks this price list and quotes the exact result.
      Keep it correct here — changes apply from the next customer message.
      <?php if ($updated_at): ?><br><small>Last saved: <?php echo htmlspecialchars($updated_at); ?></small><?php endif; ?></p>

    <div class="row">
      <div class="col-lg-8">
        <ul class="nav nav-tabs" role="tablist">
          <li class="nav-item"><a class="nav-link active" data-toggle="tab" href="#tab_rooms">Rooms</a></li>
          <li class="nav-item"><a class="nav-link" data-toggle="tab" href="#tab_dayuse">Day use</a></li>
          <li class="nav-item"><a class="nav-link" data-toggle="tab" href="#tab_children">Children</a></li>
          <li class="nav-item"><a class="nav-link" data-toggle="tab" href="#tab_json">Advanced (JSON)</a></li>
        </ul>
        <div class="tab-content card"><div class="card-body">
          <div class="tab-pane fade show active" id="tab_rooms">
            <div class="form-row mb-2">
              <div class="col-md-4"><label>Currency label</label><input id="pr_currency" class="form-control"></div>
              <div class="col-md-8"><label>Note shown with room prices</label><input id="pr_note" class="form-control" placeholder="e.g. Price is per room, not per person"></div>
            </div>
            <table class="table table-sm"><thead><tr><th>Room name</th><th style="width:90px">Guests</th><th style="width:120px">Per night</th><th>Package offers <small class="text-muted">nights = total</small></th><th></th></tr></thead>
              <tbody id="pr_rooms"></tbody></table>
            <button type="button" class="btn btn-sm btn-outline-primary" id="pr_add_room"><i class="fas fa-plus"></i> Add room</button>
          </div>
          <div class="tab-pane fade" id="tab_dayuse">
            <table class="table table-sm"><thead><tr><th>Package name</th><th style="width:150px">Price per person</th><th></th></tr></thead>
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
            <textarea id="pr_json" class="form-control" style="height:360px;font-family:monospace;font-size:12px" dir="ltr"></textarea>
            <button type="button" class="btn btn-sm btn-outline-secondary mt-2" id="pr_json_apply">Apply JSON to form</button>
          </div>
        </div></div>

        <div id="pr_errors" class="alert alert-danger" style="display:none"></div>
        <div class="form-inline">
          <input id="pr_summary" class="form-control mr-2" style="flex:1" placeholder="What changed? (shown in history)">
          <button class="btn btn-primary" id="pr_save"><i class="fas fa-save"></i> Save prices</button>
        </div>
      </div>

      <div class="col-lg-4">
        <div class="card"><div class="card-header"><h4>Test a quote</h4></div><div class="card-body">
          <p class="text-muted"><small>Runs the same calculator the AI uses, on what you see now (even unsaved).</small></p>
          <select id="t_kind" class="form-control mb-2"><option value="accommodation">Stay</option><option value="day_use">Day use</option></select>
          <input id="t_item" class="form-control mb-2" placeholder="Room / package (optional)">
          <div class="form-row">
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
              <li class="list-group-item"><small class="text-muted"><?php echo htmlspecialchars($h['created_at']); ?> · <?php echo htmlspecialchars($h['name'] ?? '—'); ?></small><br>
                <?php echo htmlspecialchars($h['summary'] ?: 'Prices updated'); ?></li>
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
  // Whole config lives here; the form edits it in place so unknown keys (media, aliases) survive.
  var cfg = <?php echo json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP); ?>;
  var esc = function (s) { return $('<div>').text(s == null ? '' : String(s)).html(); };
  function ensure() {
    cfg.accommodation = cfg.accommodation || {rooms: []}; cfg.accommodation.rooms = cfg.accommodation.rooms || [];
    cfg.day_use = cfg.day_use || {packages: []}; cfg.day_use.packages = cfg.day_use.packages || [];
    cfg.children = cfg.children || {};
  }
  function offersText(o) { return o ? Object.keys(o).map(function (k) { return k + '=' + o[k]; }).join(', ') : ''; }
  function parseOffers(t) {
    var o = {}; (t || '').split(',').forEach(function (p) { var m = p.split('='); if (m.length === 2 && m[0].trim() !== '') o[m[0].trim()] = Number(m[1]); });
    return Object.keys(o).length ? o : undefined;
  }
  function render() {
    ensure();
    $('#pr_currency').val(cfg.currency || ''); $('#pr_note').val(cfg.accommodation.note || '');
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
    $('#pr_json').val(JSON.stringify(cfg, null, 2));
  }
  function collect() {
    ensure();
    var n = function (v) { return v === '' || v == null ? undefined : Number(v); };
    cfg.currency = $('#pr_currency').val(); cfg.accommodation.note = $('#pr_note').val();
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
    $('#pr_json').val(JSON.stringify(cfg, null, 2));
    return cfg;
  }
  function showErrors(list) {
    if (!list || !list.length) { $('#pr_errors').hide(); return; }
    $('#pr_errors').html('<b>Please fix:</b><ul class="mb-0">' + list.map(function (e) { return '<li>' + esc(e) + '</li>'; }).join('') + '</ul>').show();
  }
  $('#pr_add_room').on('click', function () { collect(); ensure(); cfg.accommodation.rooms.push({name: '', capacity: 2, nightly: ''}); render(); });
  $('#pr_add_pkg').on('click', function () { collect(); ensure(); cfg.day_use.packages.push({name: '', per_person: ''}); render(); });
  $(document).on('click', '.r_del', function () { collect(); cfg.accommodation.rooms.splice($(this).closest('tr').data('i'), 1); render(); });
  $(document).on('click', '.p_del', function () { collect(); cfg.day_use.packages.splice($(this).closest('tr').data('i'), 1); render(); });
  $('a[data-toggle="tab"][href="#tab_json"]').on('show.bs.tab', collect);
  $('#pr_json_apply').on('click', function () {
    try { cfg = JSON.parse($('#pr_json').val()); render(); showErrors([]); } catch (e) { showErrors(['Invalid JSON: ' + e.message]); }
  });
  $('#pr_save').on('click', function () {
    var btn = $(this).prop('disabled', true);
    $.post(BASE + 'pricing/save', {csrf_token: TOKEN, config_json: JSON.stringify(collect()), summary: $('#pr_summary').val()}, function (r) {
      btn.prop('disabled', false);
      if (r.status === '1') { showErrors([]); (typeof iziToast !== 'undefined') ? iziToast.success({message: r.message, position: 'topRight'}) : alert(r.message); setTimeout(function () { location.reload(); }, 900); }
      else showErrors(r.errors || [r.message || 'Save failed']);
    }, 'json').fail(function () { btn.prop('disabled', false); showErrors(['Network error']); });
  });
  $('#t_run').on('click', function () {
    $.post(BASE + 'pricing/calc', {csrf_token: TOKEN, config_json: JSON.stringify(collect()), kind: $('#t_kind').val(), item: $('#t_item').val(),
      nights: $('#t_nights').val(), adults: $('#t_adults').val(), c_free: $('#t_cfree').val(), c_half: $('#t_chalf').val(), c_full: $('#t_cfull').val()}, function (r) {
      if (r.status !== '1') { $('#t_out').html('<div class="text-danger">' + (r.errors || []).map(esc).join('<br>') + '</div>'); return; }
      var x = r.result;
      if (!x.ok) { $('#t_out').html('<div class="text-danger">' + esc(x.error) + '</div>'); return; }
      $('#t_out').html('<div class="h5 mb-1">' + (x.total === null ? 'Team confirms price' : esc(x.total) + ' ' + esc(x.currency || '')) + '</div>' +
        '<div><b>' + esc(x.item) + '</b>' + (x.nights ? ' · ' + esc(x.nights) + ' night(s)' : '') + '</div>' +
        (x.needs_team ? '<div class="badge badge-warning mt-1">AI will ask your team to confirm</div>' : '') +
        '<ul class="mt-2 mb-0 pl-3">' + (x.notes || []).map(function (n) { return '<li><small>' + esc(n) + '</small></li>'; }).join('') + '</ul>');
    }, 'json');
  });
  $('#pr_active').on('change', function () {
    $.post(BASE + 'pricing/toggle', {csrf_token: TOKEN, active: this.checked ? '1' : '0'}, null, 'json');
  });
  render();
})();
</script>
