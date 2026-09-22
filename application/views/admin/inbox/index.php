<?php
$plat_icon = array('fb' => 'fab fa-facebook-messenger', 'ig' => 'fab fa-instagram', 'wa' => 'fab fa-whatsapp', 'tg' => 'fab fa-telegram', 'web' => 'fas fa-globe');
$plat_name = array('fb' => 'Messenger', 'ig' => 'Instagram', 'wa' => 'WhatsApp', 'tg' => 'Telegram', 'web' => 'Website');
?>
<section class="section">
  <div class="section-header"><h1><i class="fas fa-inbox"></i> <?php echo $page_title; ?></h1></div>
  <div class="section-body">
    <div class="row" style="min-height:70vh">

      <!-- LEFT: conversation list -->
      <div class="col-lg-4 col-xl-3 mb-3">
        <div class="card" style="height:78vh;display:flex;flex-direction:column">
          <div class="card-header p-2">
            <input id="ib_search" class="form-control form-control-sm mb-2" placeholder="Search name, phone or message…">
            <div class="d-flex flex-wrap" style="gap:4px">
              <select id="ib_platform" class="form-control form-control-sm" style="width:auto">
                <option value="">All channels</option>
                <?php foreach ($platforms as $p): ?><option value="<?php echo $p; ?>"><?php echo htmlspecialchars($plat_name[$p] ?? $p); ?></option><?php endforeach; ?>
              </select>
              <select id="ib_status" class="form-control form-control-sm" style="width:auto">
                <option value="">Open &amp; closed</option><option value="open">Open</option><option value="resolved">Closed</option>
              </select>
              <label class="form-check form-check-sm ml-1 mt-1"><input type="checkbox" id="ib_unread" class="form-check-input"> <span class="form-check-label">Unread only</span></label>
            </div>
          </div>
          <div id="ib_list" class="card-body p-0" style="overflow-y:auto;flex:1"></div>
          <div class="card-footer p-1 text-center"><button id="ib_more" class="btn btn-sm btn-link" style="display:none">Load more</button></div>
        </div>
      </div>

      <!-- MIDDLE: thread -->
      <div class="col-lg-5 col-xl-6 mb-3">
        <div class="card" style="height:78vh;display:flex;flex-direction:column">
          <div id="ib_thread_header" class="card-header p-2"><span class="text-muted">Select a conversation</span></div>
          <div id="ib_thread" class="card-body" style="overflow-y:auto;flex:1;background:#f5f6fa"></div>
          <div class="card-footer p-2">
            <div id="ib_deny" class="alert alert-warning py-2 px-2 mb-2" style="display:none">
              <span id="ib_deny_text"></span>
              <button id="ib_save_note_instead" class="btn btn-sm btn-outline-dark float-right">Save as internal note</button>
            </div>
            <div class="input-group mb-1">
              <div class="input-group-prepend"><button class="btn btn-outline-secondary dropdown-toggle" data-toggle="dropdown">Canned</button>
                <div id="ib_canned_menu" class="dropdown-menu"></div>
              </div>
              <textarea id="ib_composer" class="form-control" rows="2" placeholder="Type a reply… (or a private note)" disabled></textarea>
            </div>
            <div class="d-flex justify-content-between">
              <label class="form-check form-check-sm mt-2"><input type="checkbox" id="ib_note_mode" class="form-check-input"> <span class="form-check-label">Internal note (not sent to customer)</span></label>
              <button id="ib_send" class="btn btn-primary" disabled><i class="fas fa-paper-plane"></i> Send</button>
            </div>
          </div>
        </div>
      </div>

      <!-- RIGHT: customer 360 + controls -->
      <div class="col-lg-3 col-xl-3 mb-3">
        <div id="ib_right" style="display:none">
          <div class="card mb-2"><div class="card-body">
            <h5 id="ib_c_name">—</h5>
            <p class="mb-1"><small class="text-muted">Phone</small><br><span id="ib_c_phone">—</span></p>
            <p class="mb-1"><small class="text-muted">Email</small><br><span id="ib_c_email">—</span></p>
            <p class="mb-0"><small class="text-muted">Channel</small><br><span id="ib_c_channel">—</span></p>
          </div></div>

          <div class="card mb-2"><div class="card-body">
            <p class="mb-1"><b id="ib_handoff_badge" class="badge badge-secondary">—</b></p>
            <div class="btn-group-vertical btn-block" style="gap:2px">
              <button class="btn btn-sm btn-outline-primary" id="ib_takeover">Take over from AI</button>
              <button class="btn btn-sm btn-outline-secondary" id="ib_return_bot">Return to AI</button>
              <button class="btn btn-sm btn-outline-success" id="ib_resolve">Mark resolved</button>
              <button class="btn btn-sm btn-outline-warning" id="ib_reopen">Reopen</button>
            </div>
          </div></div>

          <div class="card mb-2"><div class="card-body">
            <label class="mb-1"><small class="text-muted">Assigned to</small></label>
            <select id="ib_assign" class="form-control form-control-sm">
              <option value="0">Unassigned</option>
              <?php foreach ($team_members as $tm): ?><option value="<?php echo (int) $tm['id']; ?>"><?php echo htmlspecialchars($tm['name']); ?></option><?php endforeach; ?>
            </select>
          </div></div>

          <div class="card mb-2"><div class="card-header p-2 d-flex justify-content-between align-items-center">
            <b><i class="fas fa-robot"></i> Summary</b>
            <button class="btn btn-sm btn-outline-secondary" id="ib_summary_refresh" title="Regenerate"><i class="fas fa-sync-alt"></i></button>
          </div>
            <div id="ib_summary_body" class="card-body p-2">
              <small class="text-muted">No summary yet.</small>
            </div>
            <div class="card-footer p-2">
              <small class="text-muted"><i class="fas fa-exclamation-circle"></i> AI-generated — verify before acting on it.</small>
            </div>
          </div>

          <div class="card"><div class="card-header p-2"><b>Internal notes</b></div>
            <div id="ib_notes" class="card-body p-2" style="max-height:220px;overflow-y:auto"><small class="text-muted">No notes yet.</small></div>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<script>
(function () {
  var TOKEN = "<?php echo $this->session->userdata('csrf_token_session'); ?>", BASE = "<?php echo base_url(); ?>";
  var PLAT_ICON = <?php echo json_encode($plat_icon); ?>, PLAT_NAME = <?php echo json_encode($plat_name); ?>;
  var current = null, listStart = 0, threadBeforeId = 0;
  var esc = function (s) { return $('<div>').text(s == null ? '' : String(s)).html(); };
  var note = function (m, ok) { if (typeof iziToast !== 'undefined') iziToast[ok ? 'success' : 'error']({message: m, position: 'topRight'}); else alert(m); };
  var post = function (path, data, cb) { data.csrf_token = TOKEN; $.post(BASE + 'inbox/' + path, data, cb, 'json'); };

  function loadList(reset) {
    if (reset) listStart = 0;
    post('conversations_data', {
      platform: $('#ib_platform').val(), status: $('#ib_status').val(),
      unread_only: $('#ib_unread').is(':checked') ? 1 : 0, search: $('#ib_search').val(),
      start: listStart, length: 20
    }, function (r) {
      if (r.status !== '1') return;
      var html = '';
      r.rows.forEach(function (c) {
        var icon = PLAT_ICON[c.platform] || 'fas fa-comment';
        var active = current && current.id === c.id ? ' active-conv' : '';
        html += '<div class="ib-row p-2 border-bottom' + active + '" data-id="' + c.id + '" style="cursor:pointer' + (active ? ';background:#eef3ff' : '') + '">' +
          '<div class="d-flex justify-content-between"><b><i class="' + icon + ' mr-1"></i>' + esc(c.customer_name) + '</b>' +
          (c.unread_count > 0 ? '<span class="badge badge-primary">' + c.unread_count + '</span>' : '') + '</div>' +
          '<div class="text-muted small text-truncate">' + esc(c.last_message_preview || '') + '</div>' +
          '<span class="badge badge-light">' + esc(c.handoff_label) + '</span></div>';
      });
      if (reset) $('#ib_list').html(html || '<p class="text-muted p-3">No conversations.</p>');
      else $('#ib_list').append(html);
      $('#ib_more').toggle(r.rows.length === 20);
    });
  }

  $('#ib_more').on('click', function () { listStart += 20; loadList(false); });
  $('#ib_search, #ib_platform, #ib_status').on('change keyup', function () { loadList(true); });
  $('#ib_unread').on('change', function () { loadList(true); });

  $(document).on('click', '.ib-row', function () { openConversation($(this).data('id')); });

  function openConversation(id) {
    threadBeforeId = 0;
    post('conversation_thread', {conversation_id: id, mark_read: 1}, function (r) {
      if (r.status !== '1') return note(r.message, false);
      current = r.conversation;
      $('#ib_thread_header').html('<b><i class="' + (PLAT_ICON[current.platform] || '') + ' mr-1"></i>' +
        esc(current.external_thread_id) + '</b> <span class="badge badge-light">' + esc(r.handoff_label) + '</span>');
      renderMessages(r.messages, true);
      renderNotes(r.notes);
      $('#ib_right').show();
      $('#ib_c_name').text(current.customer_id ? 'Customer #' + current.customer_id : current.external_thread_id);
      $('#ib_c_phone').text(current.phone || '—'); $('#ib_c_email').text(current.email || '—');
      $('#ib_c_channel').text(PLAT_NAME[current.platform] || current.platform);
      $('#ib_handoff_badge').text(r.handoff_label);
      $('#ib_assign').val(current.assigned_user_id || 0);
      $('#ib_composer, #ib_send').prop('disabled', false);
      $('#ib_deny').hide();
      loadSummary(false);
      loadList(true); // refresh unread badges
    });
  }

  function renderSummary(text, generatedAt) {
    if (!text) { $('#ib_summary_body').html('<small class="text-muted">No summary yet.</small>'); return; }
    $('#ib_summary_body').html('<div style="white-space:pre-wrap">' + esc(text) + '</div>' +
      (generatedAt ? '<small class="text-muted d-block mt-1">Generated ' + esc(generatedAt) + '</small>' : ''));
  }

  function loadSummary(refresh) {
    if (!current) return;
    $('#ib_summary_body').html('<small class="text-muted"><i class="fas fa-spinner fa-spin"></i> ' + (refresh ? 'Regenerating…' : 'Loading…') + '</small>');
    post('summarize', {conversation_id: current.id, refresh: refresh ? 1 : 0}, function (r) {
      if (!current) return;
      if (r.status === '1') renderSummary(r.summary, r.generated_at);
      else $('#ib_summary_body').html('<small class="text-danger">' + esc(r.message || 'Could not generate a summary.') + '</small>');
    });
  }

  $('#ib_summary_refresh').on('click', function () { loadSummary(true); });

  function renderMessages(messages, replace) {
    var html = messages.map(function (m) {
      var mine = m.sender === 'bot';
      var who = m.sender === 'system' ? 'System' : (mine ? (m.agent_name || 'Bot') : 'Customer');
      return '<div class="mb-2 text-' + (mine ? 'right' : 'left') + '">' +
        '<div class="d-inline-block p-2 rounded" style="max-width:80%;background:' + (mine ? '#4b7bec;color:#fff' : '#fff') + ';border:1px solid #e3e3e3">' +
        esc(m.message_content) + '</div><br><small class="text-muted">' + esc(who) + ' · ' + esc(m.conversation_time) + '</small></div>';
    }).join('');
    if (replace) $('#ib_thread').html(html); else $('#ib_thread').prepend(html);
    if (replace) $('#ib_thread').scrollTop($('#ib_thread')[0].scrollHeight);
  }

  function renderNotes(notes) {
    if (!notes || !notes.length) { $('#ib_notes').html('<small class="text-muted">No notes yet.</small>'); return; }
    $('#ib_notes').html(notes.map(function (n) {
      return '<div class="mb-2 pb-2 border-bottom"><small class="text-muted">' + esc(n.author_name) + ' · ' + esc(n.created_at) + '</small><br>' + esc(n.note) + '</div>';
    }).join(''));
  }

  $('#ib_send').on('click', function () {
    if (!current) return;
    var text = $('#ib_composer').val().trim();
    if (!text) return;
    if ($('#ib_note_mode').is(':checked')) return saveNote(text);
    post('reply', {conversation_id: current.id, message: text}, function (r) {
      if (r.status === '1') {
        $('#ib_composer').val(''); $('#ib_deny').hide();
        openConversation(current.id);
      } else if (r.denied) {
        $('#ib_deny_text').text(r.explain); $('#ib_deny').show();
      } else {
        note(r.message || 'Could not send.', false);
      }
    });
  });

  $('#ib_save_note_instead').on('click', function () {
    var text = $('#ib_composer').val().trim();
    if (text) saveNote(text);
  });

  function saveNote(text) {
    post('note_save', {conversation_id: current.id, note: text}, function (r) {
      note(r.message, r.status === '1');
      if (r.status === '1') { $('#ib_composer').val(''); $('#ib_deny').hide(); openConversation(current.id); }
    });
  }

  $('#ib_assign').on('change', function () {
    if (!current) return;
    post('assign', {conversation_id: current.id, team_member_id: $(this).val()}, function (r) { note(r.message, r.status === '1'); });
  });

  function doAction(path) {
    if (!current) return;
    post(path, {conversation_id: current.id}, function (r) {
      note(r.message, r.status === '1');
      if (r.status === '1') { $('#ib_handoff_badge').text(r.handoff_label); openConversation(current.id); }
    });
  }
  $('#ib_takeover').on('click', function () { doAction('takeover'); });
  $('#ib_return_bot').on('click', function () { doAction('return_to_bot'); });
  $('#ib_resolve').on('click', function () { post('set_status', {conversation_id: current.id, action: 'resolve'}, function (r) { note(r.message, r.status === '1'); if (r.status === '1') openConversation(current.id); }); });
  $('#ib_reopen').on('click', function () { post('set_status', {conversation_id: current.id, action: 'reopen'}, function (r) { note(r.message, r.status === '1'); if (r.status === '1') openConversation(current.id); }); });

  post('canned_responses', {}, function (r) {
    if (r.status !== '1' || !r.rows.length) return;
    $('#ib_canned_menu').html(r.rows.map(function (c) {
      return '<a class="dropdown-item ib-canned" href="#" data-msg="' + esc(c.message) + '">' + esc(c.name) + '</a>';
    }).join(''));
  });
  $(document).on('click', '.ib-canned', function (e) { e.preventDefault(); $('#ib_composer').val($(this).data('msg')); });

  loadList(true);
})();
</script>
