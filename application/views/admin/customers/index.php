<section class="section cu-page">
  <div class="section-header">
    <h1><i class="fas fa-address-book"></i> <?php echo $page_title; ?></h1>
  </div>

  <div class="section-body">
    <p class="text-muted">One profile per customer, built from every channel they have talked
      to you on — Messenger, Instagram, WhatsApp, Telegram or your website chat.</p>

    <div class="card">
      <div class="card-header p-2">
        <div class="d-flex flex-wrap cu-filter-bar">
          <input id="cu_search" class="form-control form-control-sm cu-filter-input" placeholder="Search name, phone or email…">
          <select id="cu_channel" class="form-control form-control-sm cu-filter-select">
            <option value="">All channels</option>
            <option value="fb">Messenger</option>
            <option value="ig">Instagram</option>
            <option value="wa">WhatsApp</option>
            <option value="tg">Telegram</option>
            <option value="web">Website</option>
          </select>
        </div>
      </div>
      <div class="card-body p-0">
        <div class="table-responsive"><table class="table table-striped mb-0">
          <thead><tr>
            <th>Customer</th><th>Phone</th><th>Email</th><th>Channels</th><th>Lead score</th><th></th>
          </tr></thead>
          <tbody id="cu_rows"><tr><td colspan="6" class="text-center text-muted p-4">Loading…</td></tr></tbody>
        </table></div>
      </div>
      <div class="card-footer d-flex justify-content-between align-items-center">
        <span id="cu_count" class="text-muted small"></span>
        <div>
          <button id="cu_prev" class="btn btn-sm btn-outline-secondary" disabled>Previous</button>
          <button id="cu_next" class="btn btn-sm btn-outline-secondary" disabled>Next</button>
        </div>
      </div>
    </div>
  </div>
</section>

<script>
(function () {
  var PAGE = 20, start = 0, total = 0;
  var $rows = document.getElementById('cu_rows');
  var $search = document.getElementById('cu_search');
  var $channel = document.getElementById('cu_channel');
  var $count = document.getElementById('cu_count');
  var $prev = document.getElementById('cu_prev');
  var $next = document.getElementById('cu_next');

  function escapeHtml(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function load() {
    var fd = new FormData();
    fd.append('search', $search.value);
    fd.append('channel', $channel.value);
    fd.append('start', start);
    fd.append('length', PAGE);

    fetch('<?php echo base_url('customers/customers_data'); ?>', { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (resp) {
        total = resp.total || 0;
        var rows = resp.rows || [];
        if (!rows.length) {
          $rows.innerHTML = '<tr><td colspan="6" class="text-center text-muted p-4">No customers yet.</td></tr>';
        } else {
          $rows.innerHTML = rows.map(function (r) {
            return '<tr>' +
              '<td><a href="<?php echo base_url('customers/profile/'); ?>' + r.id + '">' + escapeHtml(r.name) + '</a></td>' +
              '<td>' + escapeHtml(r.phone) + '</td>' +
              '<td>' + escapeHtml(r.email) + '</td>' +
              '<td>' + (r.channels || []).map(function (c) { return '<span class="badge badge-light cu-channel-badge">' + escapeHtml(c) + '</span>'; }).join(' ') + '</td>' +
              '<td>' + (r.lead_score || 0) + '</td>' +
              '<td><a class="btn btn-sm btn-link" href="<?php echo base_url('customers/profile/'); ?>' + r.id + '">View</a></td>' +
              '</tr>';
          }).join('');
        }
        $count.textContent = total + ' customer' + (total === 1 ? '' : 's');
        $prev.disabled = start <= 0;
        $next.disabled = start + PAGE >= total;
      });
  }

  $search.addEventListener('input', function () { start = 0; load(); });
  $channel.addEventListener('change', function () { start = 0; load(); });
  $prev.addEventListener('click', function () { start = Math.max(0, start - PAGE); load(); });
  $next.addEventListener('click', function () { start = start + PAGE; load(); });
  load();
})();
</script>
