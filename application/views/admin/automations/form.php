<section class="section">
  <div class="section-header">
    <h1><i class="fas fa-bolt"></i> <?php echo $page_title; ?></h1>
    <div class="section-header-breadcrumb">
      <a href="<?php echo base_url('automations'); ?>" class="btn btn-outline-secondary">Back to automations</a>
    </div>
  </div>

  <div class="section-body">
    <div class="row">
      <div class="col-lg-8">
        <div class="card">
          <div class="card-body">
            <div class="form-group">
              <label>Name this automation</label>
              <input id="au_name" class="form-control" placeholder="e.g. Hot lead goes to the sales team"
                     value="<?php echo $rule ? htmlspecialchars($rule['name']) : ''; ?>">
            </div>

            <h6 class="mt-4">1. Start it when&hellip;</h6>
            <div class="form-group">
              <select id="au_trigger" class="form-control">
                <option value="">Choose what starts this automation</option>
                <?php foreach ($triggers as $key => $label): ?>
                  <option value="<?php echo htmlspecialchars($key); ?>" <?php echo ($rule && $rule['trigger_event'] === $key) ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($label); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <h6 class="mt-4">2. Only if&hellip; <small class="text-muted">(all of these must be true — leave empty for "always")</small></h6>
            <table class="table table-sm">
              <thead><tr><th>Information</th><th style="width:180px">Compare</th><th>Value</th><th style="width:40px"></th></tr></thead>
              <tbody id="au_conditions"></tbody>
            </table>
            <button type="button" class="btn btn-sm btn-outline-primary" id="au_add_cond"><i class="fas fa-plus"></i> Add a condition</button>

            <h6 class="mt-4">3. Then do&hellip;</h6>
            <div id="au_actions"></div>
            <div class="form-inline mt-2">
              <select id="au_new_action" class="form-control form-control-sm mr-2">
                <?php foreach ($actions as $key => $label): ?>
                  <option value="<?php echo htmlspecialchars($key); ?>"><?php echo htmlspecialchars($label); ?></option>
                <?php endforeach; ?>
              </select>
              <button type="button" class="btn btn-sm btn-outline-primary" id="au_add_action"><i class="fas fa-plus"></i> Add this step</button>
            </div>

            <hr>
            <div class="form-inline">
              <label class="mr-2">When saved:</label>
              <select id="au_status" class="form-control mr-3">
                <?php $st = $rule ? $rule['status'] : 'draft'; ?>
                <option value="draft" <?php echo $st === 'draft' ? 'selected' : ''; ?>>Keep it off for now</option>
                <option value="active" <?php echo $st === 'active' ? 'selected' : ''; ?>>Turn it on</option>
                <option value="paused" <?php echo $st === 'paused' ? 'selected' : ''; ?>>Paused</option>
              </select>
              <button type="button" class="btn btn-primary" id="au_save"><i class="fas fa-save"></i> Save</button>
            </div>
            <div id="au_errors" class="mt-3"></div>
          </div>
        </div>
      </div>

      <div class="col-lg-4">
        <div class="card">
          <div class="card-header"><h4>Try it out</h4></div>
          <div class="card-body">
            <p class="text-muted small">See what this automation would do — without actually doing it.
              No message is sent, no deal is created, nothing is changed.</p>
            <div class="form-group">
              <label>Use a real example from your account</label>
              <select id="au_example" class="form-control">
                <option value="">Or type your own below</option>
                <?php foreach ($recent_events as $e): ?>
                  <option value="<?php echo (int) $e['id']; ?>" data-type="<?php echo htmlspecialchars($e['event_type']); ?>">
                    #<?php echo (int) $e['id']; ?> — <?php echo htmlspecialchars(event_label($e['event_type'])); ?> (<?php echo htmlspecialchars($e['created_at']); ?>)
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group">
              <label>Example details <small class="text-muted">(advanced)</small></label>
              <textarea id="au_payload" class="form-control" rows="6" dir="ltr">{"lead_score": 75, "platform": "fb", "full_name": "Test Customer"}</textarea>
            </div>
            <button type="button" class="btn btn-outline-primary btn-block" id="au_test"><i class="fas fa-vial"></i> Test without running it</button>
            <div id="au_test_result" class="mt-3"></div>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<script>
(function () {
  var TOKEN = "<?php echo $this->session->userdata('csrf_token_session'); ?>", BASE = "<?php echo base_url(); ?>";
  var RULE_ID = <?php echo $rule ? (int) $rule['id'] : 0; ?>;
  var OPS = <?php echo json_encode($operators, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP); ?>;
  var ACTION_LABELS = <?php echo json_encode($actions, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP); ?>;
  var conditions = <?php echo $rule ? ($rule['conditions_json'] ?: '[]') : '[]'; ?>;
  var actions = <?php echo $rule ? ($rule['actions_json'] ?: '[]') : '[]'; ?>;
  if (!Array.isArray(conditions)) conditions = [];
  if (!Array.isArray(actions)) actions = [];

  var esc = function (s) { return $('<div>').text(s == null ? '' : String(s)).html(); };

  // The information a condition can look at. Plain names on the left, the field the engine
  // reads on the right; see docs/automations/engine.md for the full list.
  var FIELDS = [
    ['lead_score', 'Lead score'],
    ['platform', 'Channel (fb, ig, wa, tg, web)'],
    ['full_name', 'Customer name'],
    ['email', 'Customer email'],
    ['phone', 'Customer phone'],
    ['text', 'Message text'],
    ['value', 'Deal or order value'],
    ['stage', 'Deal stage'],
    ['source', 'Where it came from'],
    ['subscriber.lead_score', 'Lead score (from the customer record)'],
    ['deal.status', 'Deal status (open / won / lost)']
  ];

  function fieldSelect(val) {
    var found = false, html = '';
    FIELDS.forEach(function (f) {
      var sel = f[0] === val ? (found = true, ' selected') : '';
      html += '<option value="' + esc(f[0]) + '"' + sel + '>' + esc(f[1]) + '</option>';
    });
    html += '<option value="__custom"' + (found || !val ? '' : ' selected') + '>Something else&hellip;</option>';
    return '<select class="form-control form-control-sm c_field">' + html + '</select>' +
      '<input class="form-control form-control-sm mt-1 c_custom" dir="ltr" placeholder="field name" value="' +
      (found || !val ? '' : esc(val)) + '"' + (found || !val ? ' style="display:none"' : '') + '>';
  }

  function renderConditions() {
    $('#au_conditions').html(conditions.map(function (c, i) {
      var ops = Object.keys(OPS).map(function (k) {
        return '<option value="' + k + '"' + (c.op === k ? ' selected' : '') + '>' + esc(OPS[k]) + '</option>';
      }).join('');
      return '<tr data-i="' + i + '"><td>' + fieldSelect(c.field) + '</td>' +
        '<td><select class="form-control form-control-sm c_op">' + ops + '</select></td>' +
        '<td><input class="form-control form-control-sm c_value" value="' + esc(c.value) + '"></td>' +
        '<td><button type="button" class="btn btn-sm btn-link text-danger c_del">&times;</button></td></tr>';
    }).join(''));
  }

  // Each action type shows only the fields it needs (brief 46: no empty boxes to guess at).
  function actionFields(a) {
    var f = '';
    function txt(key, label, ph, type) {
      return '<div class="form-group col-md-6 mb-2"><label class="small mb-1">' + label + '</label>' +
        '<input type="' + (type || 'text') + '" class="form-control form-control-sm a_' + key + '" placeholder="' + (ph || '') + '" value="' + esc(a[key]) + '"></div>';
    }
    function area(key, label, ph) {
      return '<div class="form-group col-md-12 mb-2"><label class="small mb-1">' + label + '</label>' +
        '<textarea class="form-control form-control-sm a_' + key + '" rows="2" placeholder="' + (ph || '') + '">' + esc(a[key]) + '</textarea></div>';
    }
    switch (a.type) {
      case 'add_label': f = txt('label', 'Tag to add', 'e.g. Hot lead'); break;
      case 'update_lead_score': f = txt('delta', 'Add to the score (use a minus to subtract)', '10', 'number'); break;
      case 'create_crm_deal':
        f = txt('title', 'Deal title', '{{full_name}} — new lead') + txt('value', 'Value', '0', 'number') + txt('currency', 'Currency', 'EGP');
        break;
      case 'move_crm_stage': f = txt('stage', 'Move the deal to this stage', 'e.g. Interested'); break;
      case 'notify_team':
        f = '<div class="form-group col-md-6 mb-2"><label class="small mb-1">How</label>' +
          '<select class="form-control form-control-sm a_channel">' +
          '<option value="whatsapp"' + (a.channel !== 'email' ? ' selected' : '') + '>WhatsApp</option>' +
          '<option value="email"' + (a.channel === 'email' ? ' selected' : '') + '>Email</option></select></div>' +
          txt('to', 'Send it to', 'phone number or email') + area('text', 'What to say', 'New hot lead: {{full_name}}');
        break;
      case 'send_message': f = area('text', 'Message to the customer', 'Thanks {{full_name}}! Someone from our team will call you shortly.'); break;
      case 'call_webhook': f = txt('url', 'Web address (must start with https://)', 'https://example.com/hook'); break;
      case 'write_google_sheet': f = txt('note', 'Note to write in the row', 'from automation'); break;
    }
    return f;
  }

  function renderActions() {
    $('#au_actions').html(actions.map(function (a, i) {
      return '<div class="card mb-2" data-i="' + i + '"><div class="card-body py-2">' +
        '<div class="d-flex justify-content-between align-items-center mb-2">' +
        '<strong>' + (i + 1) + '. ' + esc(ACTION_LABELS[a.type] || a.type) + '</strong>' +
        '<button type="button" class="btn btn-sm btn-link text-danger a_del">Remove</button></div>' +
        '<div class="form-row">' + actionFields(a) + '</div>' +
        (a.type === 'send_message' ? '<small class="text-muted">The messaging rules of the channel are always checked first. If the channel does not allow an automatic message at that moment, nothing is sent and you will see why in the activity log.</small>' : '') +
        '</div></div>';
    }).join(''));
  }

  function collect() {
    conditions = $('#au_conditions tr').map(function () {
      var tr = $(this), field = tr.find('.c_field').val();
      if (field === '__custom') field = tr.find('.c_custom').val();
      return {field: field, op: tr.find('.c_op').val(), value: tr.find('.c_value').val()};
    }).get().filter(function (c) { return (c.field || '').trim() !== ''; });

    actions = $('#au_actions > .card').map(function (idx) {
      var el = $(this), a = {type: actions[el.data('i')].type};
      el.find('input, textarea, select').each(function () {
        var cls = ($(this).attr('class') || '').split(/\s+/).filter(function (c) { return c.indexOf('a_') === 0; })[0];
        if (cls) a[cls.substring(2)] = $(this).val();
      });
      return a;
    }).get();
  }

  function showErrors(list) {
    if (!list || !list.length) { $('#au_errors').html(''); return; }
    $('#au_errors').html('<div class="alert alert-danger mb-0"><ul class="mb-0">' +
      list.map(function (e) { return '<li>' + esc(e) + '</li>'; }).join('') + '</ul></div>');
  }

  $(document).on('change', '.c_field', function () {
    $(this).closest('td').find('.c_custom').toggle($(this).val() === '__custom');
  });
  $(document).on('click', '.c_del', function () { collect(); conditions.splice($(this).closest('tr').data('i'), 1); renderConditions(); });
  $(document).on('click', '.a_del', function () { collect(); actions.splice($(this).closest('.card').data('i'), 1); renderActions(); });
  $('#au_add_cond').on('click', function () { collect(); conditions.push({field: 'lead_score', op: 'gte', value: ''}); renderConditions(); });
  $('#au_add_action').on('click', function () { collect(); actions.push({type: $('#au_new_action').val()}); renderActions(); });

  $('#au_save').on('click', function () {
    collect();
    $.post(BASE + 'automations/save', {
      csrf_token: TOKEN, id: RULE_ID, name: $('#au_name').val(), trigger_event: $('#au_trigger').val(),
      status: $('#au_status').val(), conditions_json: JSON.stringify(conditions), actions_json: JSON.stringify(actions)
    }, function (r) {
      r = typeof r === 'string' ? JSON.parse(r) : r;
      if (r.status === '1') { showErrors(null); location.href = BASE + 'automations'; }
      else showErrors(r.errors || [r.message || 'Could not save.']);
    });
  });

  $('#au_example').on('change', function () {
    var t = $(this).find(':selected').data('type');
    if (t) $('#au_trigger').val(t);
  });

  $('#au_test').on('click', function () {
    collect();
    $('#au_test_result').html('<div class="text-muted">Checking&hellip;</div>');
    $.post(BASE + 'automations/test', {
      csrf_token: TOKEN, trigger_event: $('#au_trigger').val(), event_id: $('#au_example').val() || 0,
      payload_json: $('#au_payload').val(), subject_type: '', subject_id: '',
      conditions_json: JSON.stringify(conditions), actions_json: JSON.stringify(actions)
    }, function (r) {
      r = typeof r === 'string' ? JSON.parse(r) : r;
      if (r.status !== '1') { $('#au_test_result').html('<div class="alert alert-warning mb-0">' + esc(r.message) + '</div>'); return; }
      var h = r.matched
        ? '<div class="alert alert-success py-2 mb-2"><strong>It would start.</strong></div>'
        : '<div class="alert alert-secondary py-2 mb-2"><strong>It would not start.</strong><ul class="mb-0 mt-1 small">' +
            (r.explain || []).map(function (e) { return '<li>' + esc(e) + '</li>'; }).join('') + '</ul></div>';
      h += '<ul class="list-unstyled mb-0">' + (r.actions || []).map(function (a) {
        var bad = a.would.indexOf('not') === 0 || a.would.indexOf('fail') === 0;
        return '<li class="mb-2"><i class="fas fa-' + (bad ? 'ban text-muted' : 'check text-success') + '"></i> ' +
          esc(a.summary) + '<br><small class="text-muted">Would ' + esc(a.would) +
          (a.policy && a.policy.explain ? ' — ' + esc(a.policy.explain) : '') + '</small></li>';
      }).join('') + '</ul>';
      h += '<p class="small text-muted mt-2 mb-0">This was only a test. Nothing was sent or changed.</p>';
      $('#au_test_result').html(h);
    });
  });

  renderConditions();
  renderActions();
})();
</script>
