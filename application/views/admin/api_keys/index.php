<section class="section ak-page">
  <div class="section-header">
    <h1><i class="fas fa-key"></i> <?php echo $page_title; ?>
      <small class="text-muted">(مفاتيح الـ API)</small></h1>
  </div>

  <div class="section-body">
    <?php if (empty($table_ready)) { ?>
      <div class="alert alert-warning">API tokens aren't available yet — try again shortly.
        <span class="d-block">مفاتيح الـ API غير متاحة الآن، حاول مرة أخرى بعد قليل.</span></div>
    <?php } ?>

    <p class="text-muted">Create a personal token to call the read-only API from your own scripts
      or integrations. Each token can read your own data only.
      <span class="d-block">أنشئ مفتاحًا شخصيًا لاستدعاء الـ API للقراءة فقط من السكريبتات أو التكاملات الخاصة بك. كل مفتاح يقرأ بياناتك أنت فقط.</span></p>

    <div class="card">
      <div class="card-header">
        <h4>New token <small class="text-muted">(مفتاح جديد)</small></h4>
      </div>
      <div class="card-body">
        <div class="form-row align-items-end">
          <div class="form-group col-md-4">
            <label>Name <small class="text-muted">(الاسم)</small></label>
            <input id="ak_name" class="form-control" placeholder="e.g. Reporting script" maxlength="100">
          </div>
          <div class="form-group col-md-3">
            <label>Expires in <small class="text-muted">(ينتهي بعد)</small></label>
            <select id="ak_expires" class="form-control">
              <option value="0">Never (لا ينتهي)</option>
              <option value="30">30 days</option>
              <option value="90">90 days</option>
              <option value="365">365 days</option>
            </select>
          </div>
          <div class="form-group col-md-3">
            <button id="ak_create" class="btn btn-primary btn-block" type="button">Create token <small>(إنشاء)</small></button>
          </div>
        </div>
        <div id="ak_new_token" class="alert alert-success d-none">
          <strong>Copy this token now — it will not be shown again.</strong>
          <span class="d-block">انسخ المفتاح الآن، لن يظهر مرة أخرى.</span>
          <div class="input-group mt-2">
            <input id="ak_new_token_value" class="form-control" readonly>
            <div class="input-group-append">
              <button id="ak_copy_token" class="btn btn-outline-secondary" type="button">Copy (نسخ)</button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="card mt-3">
      <div class="card-header"><h4>Your tokens <small class="text-muted">(مفاتيحك)</small></h4></div>
      <div class="card-body p-0">
        <div class="table-responsive"><table class="table table-striped mb-0">
          <thead><tr>
            <th>Name</th><th>Created</th><th>Last used</th><th>Status</th><th></th>
          </tr></thead>
          <tbody id="ak_rows"><tr><td colspan="5" class="text-center text-muted p-4">Loading…</td></tr></tbody>
        </table></div>
      </div>
    </div>

    <div class="card mt-3">
      <div class="card-header"><h4>Using your token <small class="text-muted">(استخدام المفتاح)</small></h4></div>
      <div class="card-body">
        <p class="text-muted mb-2">Send it as a Bearer token against the read-only API v2 endpoints:</p>
        <pre class="ak-usage-example">curl -H "Authorization: Bearer mb_xxxxxxxx..." \
  "<?php echo base_url('api/v2/conversations'); ?>?page=1&amp;per_page=20"</pre>
      </div>
    </div>
  </div>
</section>

<script>
(function () {
  var TOKEN = "<?php echo $this->session->userdata('csrf_token_session'); ?>", BASE = "<?php echo base_url(); ?>";
  var $rows = document.getElementById('ak_rows');
  var $name = document.getElementById('ak_name');
  var $expires = document.getElementById('ak_expires');
  var $create = document.getElementById('ak_create');
  var $newBox = document.getElementById('ak_new_token');
  var $newVal = document.getElementById('ak_new_token_value');
  var $copy = document.getElementById('ak_copy_token');

  function escapeHtml(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function statusBadge(r) {
    if (r.revoked_at) return '<span class="badge badge-secondary">Revoked</span>';
    if (r.expires_at && new Date(r.expires_at.replace(' ', 'T')) < new Date()) return '<span class="badge badge-warning">Expired</span>';
    return '<span class="badge badge-success">Active</span>';
  }

  function load() {
    fetch(BASE + 'api_keys/tokens_data', { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (resp) {
        var rows = (resp && resp.rows) || [];
        if (!rows.length) {
          $rows.innerHTML = '<tr><td colspan="5" class="text-center text-muted p-4">No tokens yet.</td></tr>';
          return;
        }
        $rows.innerHTML = rows.map(function (r) {
          var revokeBtn = r.revoked_at ? '' : '<button class="btn btn-sm btn-outline-danger ak-revoke" data-id="' + r.id + '">Revoke</button>';
          return '<tr>' +
            '<td>' + escapeHtml(r.name) + '</td>' +
            '<td>' + escapeHtml(r.created_at) + '</td>' +
            '<td>' + escapeHtml(r.last_used_at || '—') + '</td>' +
            '<td>' + statusBadge(r) + '</td>' +
            '<td>' + revokeBtn + '</td>' +
            '</tr>';
        }).join('');
      });
  }

  $create.addEventListener('click', function () {
    var name = ($name.value || '').trim();
    if (!name) { $name.focus(); return; }
    var fd = new FormData();
    fd.append('name', name);
    fd.append('expires_days', $expires.value);
    fd.append('csrf_token', TOKEN);
    $create.disabled = true;
    fetch(BASE + 'api_keys/create_token', { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (resp) {
        $create.disabled = false;
        if (resp && resp.status === '1') {
          $newVal.value = resp.token.plaintext;
          $newBox.classList.remove('d-none');
          $name.value = '';
          load();
        } else {
          alert((resp && resp.message) || 'Could not create token.');
        }
      })
      .catch(function () { $create.disabled = false; });
  });

  $copy.addEventListener('click', function () {
    $newVal.select();
    try { document.execCommand('copy'); } catch (e) {}
  });

  $rows.addEventListener('click', function (e) {
    var btn = e.target.closest('.ak-revoke');
    if (!btn) return;
    if (!confirm('Revoke this token? It will stop working immediately.')) return;
    var fd = new FormData();
    fd.append('id', btn.getAttribute('data-id'));
    fd.append('csrf_token', TOKEN);
    fetch(BASE + 'api_keys/revoke_token', { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function () { load(); });
  });

  load();
})();
</script>
