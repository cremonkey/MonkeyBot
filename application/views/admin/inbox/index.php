<?php
$plat_icon = array('fb' => 'fab fa-facebook-messenger', 'ig' => 'fab fa-instagram', 'wa' => 'fab fa-whatsapp', 'tg' => 'fab fa-telegram', 'web' => 'fas fa-globe');
$plat_name = array('fb' => 'Messenger', 'ig' => 'Instagram', 'wa' => 'WhatsApp', 'tg' => 'Telegram', 'web' => 'Website');
?>
<section class="section ib-page">
  <div class="section-header"><h1><i class="fas fa-inbox"></i> <?php echo $page_title; ?></h1></div>
  <div class="section-body">
    <div class="row ib-row-wrap">

      <!-- LEFT: conversation list -->
      <div class="col-lg-4 col-xl-3 mb-3">
        <div class="card ib-panel">
          <div class="card-header p-2 ib-panel-header">
            <input id="ib_search" class="form-control form-control-sm mb-2" placeholder="Search name, phone or message…">
            <div class="d-flex flex-wrap ib-filter-bar">
              <select id="ib_platform" class="form-control form-control-sm ib-filter-select">
                <option value="">All channels</option>
                <?php foreach ($platforms as $p): ?><option value="<?php echo $p; ?>"><?php echo htmlspecialchars($plat_name[$p] ?? $p); ?></option><?php endforeach; ?>
              </select>
              <select id="ib_status" class="form-control form-control-sm ib-filter-select">
                <option value="">Open &amp; closed</option><option value="open">Open</option><option value="resolved">Closed</option>
              </select>
              <select id="ib_label" class="form-control form-control-sm ib-filter-select">
                <option value="">All labels</option>
              </select>
              <label class="form-check form-check-sm ml-1 mt-1"><input type="checkbox" id="ib_unread" class="form-check-input"> <span class="form-check-label">Unread only</span></label>
              <button type="button" id="ib_labels_manage" class="btn btn-sm btn-link p-0 ml-1" title="Create, rename or delete labels"><i class="fas fa-tags"></i> Labels</button>
            </div>
          </div>
          <div id="ib_list" class="card-body p-0 ib-scroll-list"></div>
          <div class="card-footer p-1 text-center"><button id="ib_more" class="btn btn-sm btn-link" style="display:none">Load more</button></div>
        </div>
      </div>

      <!-- MIDDLE: thread -->
      <div class="col-lg-5 col-xl-6 mb-3">
        <div class="card ib-panel">
          <div id="ib_thread_header" class="card-header p-2"><span class="text-muted">Select a conversation</span></div>
          <div id="ib_thread" class="card-body ib-thread-body"></div>
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
              <span>
                <input type="file" id="ib_image_input" accept="image/jpeg,image/png" style="display:none">
                <button id="ib_attach" class="btn btn-outline-secondary" style="display:none" title="Send an image (JPEG or PNG, up to 5 MB)"><i class="fas fa-image"></i></button>
                <button id="ib_send" class="btn btn-primary" disabled><i class="fas fa-paper-plane"></i> Send</button>
              </span>
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

          <!-- Customer 360 (Task 16 / brief §16/§13): compact panel from Inbox::customer_panel -->
          <div class="card mb-2" id="ib_cu_panel" style="display:none"><div class="card-body">
            <p class="mb-1"><small class="text-muted">Lead score</small><br><span id="ib_cu_score">—</span></p>
            <p class="mb-1"><small class="text-muted">Other channels</small><br><span id="ib_cu_channels">—</span></p>
            <p class="mb-1"><small class="text-muted">Pipeline stage</small><br><span id="ib_cu_stage">—</span></p>
            <p class="mb-1"><small class="text-muted">Other conversations</small><br><span id="ib_cu_other_convs">—</span></p>
            <p class="mb-1"><small class="text-muted">Orders</small><br><span id="ib_cu_orders">—</span></p>
            <a id="ib_cu_profile_link" href="#" class="btn btn-sm btn-outline-primary btn-block">Open full profile</a>
          </div></div>

          <div class="card mb-2"><div class="card-body">
            <p class="mb-1"><b id="ib_handoff_badge" class="badge badge-secondary">—</b></p>
            <div class="btn-group-vertical btn-block ib-actions-vertical">
              <button class="btn btn-sm btn-outline-primary" id="ib_takeover">Take over from AI</button>
              <button class="btn btn-sm btn-outline-secondary" id="ib_return_bot">Return to AI</button>
              <button class="btn btn-sm btn-outline-success" id="ib_resolve">Mark resolved</button>
              <button class="btn btn-sm btn-outline-warning" id="ib_reopen">Reopen</button>
            </div>
          </div></div>

          <div class="card mb-2"><div class="card-body">
            <label class="mb-1"><small class="text-muted">Labels</small></label>
            <div id="ib_conv_labels" class="mb-1"><small class="text-muted">No labels.</small></div>
            <select id="ib_label_add" class="form-control form-control-sm"><option value="">+ Add a label…</option></select>
          </div></div>

          <div class="card mb-2"><div class="card-body">
            <label class="mb-1"><small class="text-muted">Assigned to</small></label>
            <select id="ib_assign" class="form-control form-control-sm">
              <option value="0">Unassigned</option>
              <?php foreach ($team_members as $tm): ?><option value="<?php echo (int) $tm['id']; ?>"><?php echo htmlspecialchars($tm['name']); ?></option><?php endforeach; ?>
            </select>
          </div></div>

          <div class="card mb-2" id="ib_ai_draft_card" style="display:none"><div class="card-header p-2 d-flex justify-content-between align-items-center">
            <b><i class="fas fa-magic"></i> AI suggested reply</b>
            <button class="btn btn-sm btn-outline-primary" id="ib_ai_draft_use" title="Copy into the reply box. Nothing is sent until you press Send.">Use</button>
          </div>
            <div class="card-body p-2">
              <div id="ib_ai_draft_text" class="ib-ai-draft-text"></div>
              <small class="text-muted d-block mt-1" id="ib_ai_draft_meta"></small>
            </div>
            <div class="card-footer p-2">
              <small class="text-muted"><i class="fas fa-exclamation-circle"></i> The AI's latest reply in this conversation, read-only. Check it before sending.</small>
            </div>
          </div>

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
            <div id="ib_notes" class="card-body p-2 ib-notes-scroll"><small class="text-muted">No notes yet.</small></div>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- Canned responses: which channels each one is offered on -->
<div class="modal fade ib-page" id="ib_canned_modal" tabindex="-1" role="dialog">
  <div class="modal-dialog modal-lg" role="document"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title"><i class="fas fa-comment-dots"></i> Canned responses</h5>
      <button type="button" class="close" data-dismiss="modal">&times;</button></div>
    <div class="modal-body">
      <div id="ib_canned_table" class="mb-3 ib-canned-scroll"></div>
      <input type="hidden" id="ib_canned_id" value="0">
      <input id="ib_canned_name" class="form-control form-control-sm mb-1" maxlength="255" placeholder="Name">
      <textarea id="ib_canned_text" class="form-control form-control-sm mb-1" rows="3" placeholder="Message"></textarea>
      <div class="mb-2" id="ib_canned_channels">
        <small class="text-muted mr-2">Offer on:</small>
        <label class="mr-2 mb-0"><input type="checkbox" class="ib-canned-all" checked> All channels</label>
        <?php foreach ($platforms as $p): ?>
          <label class="mr-2 mb-0"><input type="checkbox" class="ib-canned-ch" value="<?php echo htmlspecialchars($p); ?>"> <?php echo htmlspecialchars($plat_name[$p] ?? $p); ?></label>
        <?php endforeach; ?>
      </div>
      <button type="button" id="ib_canned_save" class="btn btn-sm btn-primary">Save</button>
      <button type="button" id="ib_canned_reset" class="btn btn-sm btn-link">New</button>
    </div>
  </div></div>
</div>

<!-- Label manager: create / rename / recolour / delete this account's labels -->
<div class="modal fade ib-page" id="ib_labels_modal" tabindex="-1" role="dialog">
  <div class="modal-dialog" role="document"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title"><i class="fas fa-tags"></i> Labels</h5>
      <button type="button" class="close" data-dismiss="modal">&times;</button></div>
    <div class="modal-body">
      <p class="text-muted small mb-2">Labels work on every channel. Deleting a label removes it from every conversation.</p>
      <div id="ib_labels_table" class="mb-3"></div>
      <div class="input-group input-group-sm">
        <input id="ib_label_new_name" class="form-control" maxlength="60" placeholder="New label name">
        <input id="ib_label_new_color" type="color" class="form-control ib-color-swatch" value="#6777ef">
        <div class="input-group-append"><button type="button" id="ib_label_create" class="btn btn-primary">Add</button></div>
      </div>
    </div>
  </div></div>
</div>

<script>
(function () {
  var TOKEN = "<?php echo $this->session->userdata('csrf_token_session'); ?>", BASE = "<?php echo base_url(); ?>";
  var PLAT_ICON = <?php echo json_encode($plat_icon); ?>, PLAT_NAME = <?php echo json_encode($plat_name); ?>;
  var current = null, listStart = 0, threadBeforeId = 0, LABELS = [];
  var esc = function (s) { return $('<div>').text(s == null ? '' : String(s)).html(); };
  var note = function (m, ok) { if (typeof iziToast !== 'undefined') iziToast[ok ? 'success' : 'error']({message: m, position: 'topRight'}); else alert(m); };
  var post = function (path, data, cb) { data.csrf_token = TOKEN; return $.post(BASE + 'inbox/' + path, data, cb, 'json'); };

  function labelBadge(l, removable) {
    var bg = l.color || '#6c757d';
    return '<span class="badge mr-1 mb-1 ib-label-badge" style="background:' + esc(bg) + '">' + esc(l.name) +
      (removable ? ' <a href="#" class="ib-label-detach text-white" data-id="' + (+l.id) + '" title="Remove">&times;</a>' : '') + '</span>';
  }

  function loadLabels() {
    post('labels_list', {}, function (r) {
      if (r.status !== '1') return;
      LABELS = r.rows || [];
      var keep = $('#ib_label').val();
      $('#ib_label').html('<option value="">All labels</option>' + LABELS.map(function (l) {
        return '<option value="' + (+l.id) + '">' + esc(l.name) + ' (' + (+l.conversations) + ')</option>';
      }).join('')).val(keep);
      if ($('#ib_label').val() !== keep) { $('#ib_label').val(''); }
      $('#ib_labels_manage, #ib_label').toggle(r.ready !== false);
      renderLabelTable();
      if (current) renderConvLabels(current.labels || []);
    });
  }

  function renderConvLabels(labels) {
    current.labels = labels;
    $('#ib_conv_labels').html(labels.length ? labels.map(function (l) { return labelBadge(l, true); }).join('') : '<small class="text-muted">No labels.</small>');
    var on = labels.map(function (l) { return +l.id; });
    $('#ib_label_add').html('<option value="">+ Add a label…</option>' + LABELS.filter(function (l) { return on.indexOf(+l.id) < 0; }).map(function (l) {
      return '<option value="' + (+l.id) + '">' + esc(l.name) + '</option>';
    }).join(''));
  }

  function renderLabelTable() {
    $('#ib_labels_table').html(LABELS.length ? LABELS.map(function (l) {
      return '<div class="d-flex align-items-center mb-1" data-id="' + (+l.id) + '">' +
        '<input class="form-control form-control-sm ib-label-name" maxlength="60" value="' + esc(l.name) + '">' +
        '<input type="color" class="form-control form-control-sm ib-label-color ml-1 ib-color-swatch-sm" value="' + esc(l.color || '#6c757d') + '">' +
        '<small class="text-muted mx-2 text-nowrap">' + (+l.conversations) + ' conv.</small>' +
        '<button type="button" class="btn btn-sm btn-outline-primary ib-label-rename">Save</button>' +
        '<button type="button" class="btn btn-sm btn-outline-danger ml-1 ib-label-delete"><i class="fas fa-trash"></i></button></div>';
    }).join('') : '<small class="text-muted">No labels yet.</small>');
  }

  $('#ib_labels_manage').on('click', function () { renderLabelTable(); $('#ib_labels_modal').modal('show'); });
  $('#ib_label_create').on('click', function () {
    post('label_save', {name: $('#ib_label_new_name').val(), color: $('#ib_label_new_color').val()}, function (r) {
      note(r.message, r.status === '1');
      if (r.status === '1') { $('#ib_label_new_name').val(''); loadLabels(); }
    });
  });
  $(document).on('click', '.ib-label-rename', function () {
    var row = $(this).closest('[data-id]');
    post('label_save', {label_id: row.data('id'), name: row.find('.ib-label-name').val(), color: row.find('.ib-label-color').val()}, function (r) {
      note(r.message, r.status === '1');
      if (r.status === '1') { loadLabels(); loadList(true); }
    });
  });
  $(document).on('click', '.ib-label-delete', function () {
    var row = $(this).closest('[data-id]');
    if (!confirm('Delete this label? It will be removed from every conversation.')) return;
    post('label_delete', {label_id: row.data('id')}, function (r) {
      note(r.message, r.status === '1');
      if (r.status === '1') { loadLabels(); loadList(true); }
    });
  });
  $('#ib_label_add').on('change', function () {
    var id = $(this).val();
    if (!current || !id) return;
    post('label_attach', {conversation_id: current.id, label_id: id}, function (r) {
      if (r.status !== '1') return note(r.message, false);
      renderConvLabels(r.labels); loadLabels(); loadList(true);
    });
  });
  $(document).on('click', '.ib-label-detach', function (e) {
    e.preventDefault();
    if (!current) return;
    post('label_detach', {conversation_id: current.id, label_id: $(this).data('id')}, function (r) {
      if (r.status !== '1') return note(r.message, false);
      renderConvLabels(r.labels); loadLabels(); loadList(true);
    });
  });

  function loadList(reset) {
    if (reset) listStart = 0;
    post('conversations_data', {
      label_id: $('#ib_label').val(),
      platform: $('#ib_platform').val(), status: $('#ib_status').val(),
      unread_only: $('#ib_unread').is(':checked') ? 1 : 0, search: $('#ib_search').val(),
      start: listStart, length: 20
    }, function (r) {
      if (r.status !== '1') return;
      var html = '';
      r.rows.forEach(function (c) {
        var icon = PLAT_ICON[c.platform] || 'fas fa-comment';
        var active = current && current.id === c.id ? ' active-conv' : '';
        html += '<div class="ib-row p-2 border-bottom' + active + '" data-id="' + c.id + '">' +
          '<div class="d-flex justify-content-between"><b><i class="' + icon + ' mr-1"></i>' + esc(c.customer_name) + '</b>' +
          (c.unread_count > 0 ? '<span class="badge badge-primary">' + c.unread_count + '</span>' : '') + '</div>' +
          '<div class="text-muted small text-truncate">' + esc(c.last_message_preview || '') + '</div>' +
          '<span class="badge badge-light mr-1">' + esc(c.handoff_label) + '</span>' +
          (c.labels || []).map(function (l) { return labelBadge(l, false); }).join('') + '</div>';
      });
      if (reset) $('#ib_list').html(html || '<p class="text-muted p-3">No conversations.</p>');
      else $('#ib_list').append(html);
      $('#ib_more').toggle(r.rows.length === 20);
    });
  }

  $('#ib_more').on('click', function () { listStart += 20; loadList(false); });
  $('#ib_search, #ib_platform, #ib_status, #ib_label').on('change keyup', function () { loadList(true); });
  $('#ib_unread').on('change', function () { loadList(true); });

  $(document).on('click', '.ib-row', function () { openConversation($(this).data('id')); });

  function openConversation(id) {
    threadBeforeId = 0;
    post('conversation_thread', {conversation_id: id, mark_read: 1}, function (r) {
      if (r.status !== '1') return note(r.message, false);
      // a draft (typed, canned or the AI suggestion) never follows the agent into another conversation
      if (!current || String(current.id) !== String(r.conversation.id)) $('#ib_composer').val('');
      current = r.conversation;
      $('#ib_thread_header').html('<b><i class="' + (PLAT_ICON[current.platform] || '') + ' mr-1"></i>' +
        esc(current.external_thread_id) + '</b> <span class="badge badge-light">' + esc(r.handoff_label) + '</span>');
      renderMessages(r.messages, true);
      lastMsgId = r.messages.length ? +r.messages[r.messages.length - 1].id : 0;
      renderNotes(r.notes);
      renderConvLabels(r.labels || []);
      $('#ib_right').show();
      $('#ib_c_name').text(current.customer_id ? 'Customer #' + current.customer_id : current.external_thread_id);
      $('#ib_c_phone').text(current.phone || '—'); $('#ib_c_email').text(current.email || '—');
      $('#ib_c_channel').text(PLAT_NAME[current.platform] || current.platform);
      $('#ib_handoff_badge').text(r.handoff_label);
      $('#ib_assign').val(current.assigned_user_id || 0);
      $('#ib_composer, #ib_send').prop('disabled', false);
      $('#ib_attach').toggle(r.can_send_image === true);
      renderAiDraft(r.ai_draft);
      $('#ib_deny').hide();
      loadSummary(false);
      loadCanned();
      loadList(true); // refresh unread badges
      loadCustomerPanel(current.id);
    });
  }

  /** Task 16 / brief §16/§13: compact Customer 360 panel. Hidden entirely when the
   *  conversation has no linked customer row (customer_id NULL), same as an unresolved
   *  legacy thread — nothing to show, no error either. */
  function loadCustomerPanel(conversationId) {
    $('#ib_cu_panel').hide();
    post('customer_panel', {conversation_id: conversationId}, function (r) {
      if (r.status !== '1') return; // no linked customer — leave the panel hidden
      $('#ib_cu_score').text(r.customer.lead_score);
      $('#ib_cu_channels').text((r.channels || []).map(function (c) { return PLAT_NAME[c] || c; }).join(', ') || '—');
      var stage = r.lead_stage || {};
      $('#ib_cu_stage').text(stage.open_deal ? stage.open_deal.title : ('Open: ' + (stage.open_count || 0) + ' · Won: ' + (stage.won_count || 0)));
      $('#ib_cu_other_convs').text((r.other_conversations || []).length ? (r.other_conversations.length + ' other conversation(s)') : 'None');
      $('#ib_cu_orders').text((r.orders || []).length ? (r.orders.length + ' order(s)') : 'None');
      $('#ib_cu_profile_link').attr('href', r.profile_url || '#');
      $('#ib_cu_panel').show();
    });
  }

  var AI_DRAFT = '';
  function renderAiDraft(d) {
    AI_DRAFT = d && d.reply ? d.reply : '';
    if (!AI_DRAFT) { $('#ib_ai_draft_card').hide(); return; }
    $('#ib_ai_draft_text').text(AI_DRAFT);
    $('#ib_ai_draft_meta').text((d.answering ? 'Answering: "' + d.answering + '" · ' : '') + (d.created_at || ''));
    $('#ib_ai_draft_card').show();
  }
  $('#ib_ai_draft_use').on('click', function () {
    if (!AI_DRAFT) return;
    $('#ib_note_mode').prop('checked', false);
    $('#ib_composer').val(AI_DRAFT).focus();
  });

  function renderSummary(text, generatedAt) {
    if (!text) { $('#ib_summary_body').html('<small class="text-muted">No summary yet.</small>'); return; }
    $('#ib_summary_body').html('<div class="ib-pre-wrap">' + esc(text) + '</div>' +
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

  // Media URLs come from the server already restricted to http(s) (inbox_message_render) and
  // are escaped again here; nothing from a message is ever inserted as raw HTML.
  function renderAttachment(a) {
    var u = esc(a.url);
    if (a.type === 'image') return '<a href="' + u + '" target="_blank" rel="noopener noreferrer"><img src="' + u + '" alt="image" class="ib-attachment-image" loading="lazy"></a>';
    if (a.type === 'video') return '<video src="' + u + '" controls preload="none" class="ib-attachment-video"></video>';
    if (a.type === 'audio') return '<audio src="' + u + '" controls preload="none"></audio>';
    return '<a href="' + u + '" target="_blank" rel="noopener noreferrer"><i class="fas fa-file"></i> Download file</a>';
  }

  function renderMessages(messages, replace) {
    var html = messages.map(function (m) {
      var mine = m.sender === 'bot';
      var who = m.sender === 'system' ? 'System' : (mine ? (m.agent_name || 'Bot') : 'Customer');
      var r = m.render || {text: m.message_content, attachments: [], media_unavailable: false};
      var body = '';
      if (r.text) body += '<div class="ib-pre-wrap">' + esc(r.text) + '</div>';
      (r.attachments || []).forEach(function (a) { body += '<div class="mt-1">' + renderAttachment(a) + '</div>'; });
      if (r.media_unavailable) body += '<em class="small">Media message — the file was not stored, so it cannot be shown here.</em>';
      return '<div class="mb-2 text-' + (mine ? 'right' : 'left') + '">' +
        '<div class="d-inline-block p-2 rounded text-left ib-bubble ' + (mine ? 'ib-bubble-mine' : 'ib-bubble-theirs') + '">' +
        body + '</div><br><small class="text-muted">' + esc(who) + ' · ' + esc(m.conversation_time) + '</small></div>';
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

  $('#ib_attach').on('click', function (e) { e.preventDefault(); if (current) $('#ib_image_input').val('').trigger('click'); });
  $('#ib_image_input').on('change', function () {
    var f = this.files && this.files[0];
    if (!current || !f) return;
    if (['image/jpeg', 'image/png'].indexOf(f.type) < 0) return note('Only JPEG and PNG images can be sent.', false);
    if (f.size > 5 * 1024 * 1024) return note('The image is larger than 5 MB.', false);
    var fd = new FormData();
    fd.append('csrf_token', TOKEN); fd.append('conversation_id', current.id); fd.append('image', f);
    $('#ib_attach').prop('disabled', true);
    $.ajax({url: BASE + 'inbox/reply_image', type: 'POST', data: fd, processData: false, contentType: false, dataType: 'json'})
      .done(function (r) {
        if (r.status === '1') { $('#ib_deny').hide(); openConversation(current.id); }
        else if (r.denied) { $('#ib_deny_text').text(r.explain); $('#ib_deny').show(); }
        else note(r.message || 'Could not send the image.', false);
      })
      .fail(function () { note('Could not send the image.', false); })
      .always(function () { $('#ib_attach').prop('disabled', false); });
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

  // Canned responses offered in the composer are the ones for the open conversation's channel
  // (a response with no channel set is offered everywhere, as before).
  var CANNED_ALL = [];
  function loadCanned() {
    post('canned_responses', current ? {conversation_id: current.id} : {}, function (r) {
      if (r.status !== '1') return;
      var items = r.rows.map(function (c) {
        return '<a class="dropdown-item ib-canned" href="#" data-msg="' + esc(c.message) + '">' + esc(c.name) + '</a>';
      }).join('');
      $('#ib_canned_menu').html((items || '<span class="dropdown-item-text text-muted small">None for this channel</span>') +
        '<div class="dropdown-divider"></div><a class="dropdown-item small" href="#" id="ib_canned_manage"><i class="fas fa-cog"></i> Manage…</a>');
      $('#ib_canned_channels').toggle(r.channels_supported !== false);
    });
  }
  function loadCannedAll() {
    post('canned_responses', {}, function (r) {
      if (r.status !== '1') return;
      CANNED_ALL = r.rows;
      $('#ib_canned_table').html(CANNED_ALL.length ? '<table class="table table-sm mb-0"><tbody>' + CANNED_ALL.map(function (c) {
        var ch = c.channel_list.length ? c.channel_list.map(function (p) { return esc(PLAT_NAME[p] || p); }).join(', ') : 'All channels';
        return '<tr data-id="' + (+c.id) + '"><td><b>' + esc(c.name) + '</b><br><small class="text-muted">' + esc(String(c.message).substring(0, 140)) + '</small></td>' +
          '<td class="text-nowrap"><small>' + ch + '</small></td>' +
          '<td class="text-nowrap text-right"><button type="button" class="btn btn-sm btn-outline-primary ib-canned-edit">Edit</button> ' +
          '<button type="button" class="btn btn-sm btn-outline-danger ib-canned-del"><i class="fas fa-trash"></i></button></td></tr>';
      }).join('') + '</tbody></table>' : '<small class="text-muted">No canned responses yet.</small>');
    });
  }
  function cannedForm(c) {
    $('#ib_canned_id').val(c ? c.id : 0); $('#ib_canned_name').val(c ? c.name : ''); $('#ib_canned_text').val(c ? c.message : '');
    var list = c ? c.channel_list : [];
    $('.ib-canned-all').prop('checked', !list.length);
    $('.ib-canned-ch').each(function () { $(this).prop('checked', list.indexOf(this.value) >= 0); });
  }
  $(document).on('click', '.ib-canned', function (e) { e.preventDefault(); $('#ib_composer').val($(this).data('msg')); });
  $(document).on('click', '#ib_canned_manage', function (e) { e.preventDefault(); cannedForm(null); loadCannedAll(); $('#ib_canned_modal').modal('show'); });
  $(document).on('change', '.ib-canned-all', function () { if (this.checked) $('.ib-canned-ch').prop('checked', false); });
  $(document).on('change', '.ib-canned-ch', function () { $('.ib-canned-all').prop('checked', !$('.ib-canned-ch:checked').length); });
  $('#ib_canned_reset').on('click', function () { cannedForm(null); });
  $(document).on('click', '.ib-canned-edit', function () {
    var id = +$(this).closest('tr').data('id');
    cannedForm(CANNED_ALL.filter(function (c) { return +c.id === id; })[0] || null);
  });
  $(document).on('click', '.ib-canned-del', function () {
    if (!confirm('Delete this canned response?')) return;
    post('canned_delete', {canned_id: $(this).closest('tr').data('id')}, function (r) {
      note(r.message, r.status === '1'); if (r.status === '1') { loadCannedAll(); loadCanned(); }
    });
  });
  $('#ib_canned_save').on('click', function () {
    var ch = $('.ib-canned-all').is(':checked') ? [] : $('.ib-canned-ch:checked').map(function () { return this.value; }).get();
    post('canned_save', {canned_id: $('#ib_canned_id').val(), name: $('#ib_canned_name').val(), message: $('#ib_canned_text').val(),
      channels: ch.length ? ch : ['all']}, function (r) {
      note(r.message, r.status === '1'); if (r.status === '1') { cannedForm(null); loadCannedAll(); loadCanned(); }
    });
  });
  loadCanned();

  // ---- live updates: Pusher (tenant's private channel) when configured, polling always as fallback.
  // Events carry ids only; everything shown is re-fetched through the tenant-scoped endpoints above.
  var RT = <?php echo json_encode(isset($realtime) ? $realtime : null); ?>;
  var POLL = <?php echo json_encode(isset($poll_seconds) ? $poll_seconds : null); ?>;
  var rtConnected = false, pollTimer = null, refreshTimer = null, lastMsgId = 0, busy = false, again = false, hitCurrent = false;

  function refreshCurrent() {
    if (!current) return;
    if (busy) { again = true; return; } // a refresh is in flight: run once more when it lands
    var id = current.id; busy = true; again = false;
    post('conversation_thread', {conversation_id: id, mark_read: document.hidden ? 0 : 1}, function (r) {
      busy = false;
      if (again) setTimeout(refreshCurrent, 0);
      if (r.status !== '1' || !current || String(current.id) !== String(id)) return;
      var newest = r.messages.length ? +r.messages[r.messages.length - 1].id : 0;
      if (newest !== lastMsgId) { lastMsgId = newest; renderMessages(r.messages, true); }
      renderNotes(r.notes);
      renderAiDraft(r.ai_draft);
      $('#ib_handoff_badge').text(r.handoff_label);
    }).fail(function () { busy = false; });
  }
  function refreshAll() {
    if (listStart === 0) loadList(true); // never throw away "Load more" pages the agent opened
    refreshCurrent();
  }
  function schedulePoll() {
    clearInterval(pollTimer);
    if (!POLL) return;
    pollTimer = setInterval(function () { if (!document.hidden) refreshAll(); }, 1000 * (rtConnected ? POLL[1] : POLL[0]));
  }
  if (POLL || RT) document.addEventListener('visibilitychange', function () { if (!document.hidden) refreshAll(); });
  schedulePoll();

  function startRealtime() {
    if (!RT || typeof Pusher === 'undefined') return;
    try {
      var client = new Pusher(RT.key, {
        cluster: RT.cluster || undefined,
        authEndpoint: BASE + 'inbox/realtime_auth',
        auth: {params: {csrf_token: TOKEN}}
      });
      var ch = client.subscribe(RT.channel);
      ch.bind('pusher:subscription_succeeded', function () { rtConnected = true; schedulePoll(); });
      ch.bind('pusher:subscription_error', function () { rtConnected = false; schedulePoll(); });
      client.connection.bind('state_change', function (s) {
        var up = s.current === 'connected';
        if (!up && rtConnected) { rtConnected = false; schedulePoll(); }
      });
      ch.bind('message', function (ev) {
        if (current && ev && String(ev.conversation_id) === String(current.id)) hitCurrent = true;
        clearTimeout(refreshTimer); // coalesce bursts (inbound + bot reply) into one refresh
        refreshTimer = setTimeout(function () {
          if (listStart === 0) loadList(true);
          if (hitCurrent) { hitCurrent = false; refreshCurrent(); }
        }, 400);
      });
    } catch (e) { rtConnected = false; schedulePoll(); }
  }
  if (RT) {
    if (typeof Pusher !== 'undefined') startRealtime();
    else $(window).on('load', function () {
      if (typeof Pusher !== 'undefined') return startRealtime();
      $.ajax({url: 'https://js.pusher.com/7.2/pusher.min.js', dataType: 'script', cache: true}).done(startRealtime);
    });
  }

  loadLabels();
  loadList(true);
})();
</script>
