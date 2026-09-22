<section class="section">
  <div class="section-header">
    <h1><i class="fas fa-stream"></i> <?php echo $page_title; ?></h1>
    <div class="section-header-breadcrumb">
      <a href="<?php echo base_url('sequences'); ?>" class="btn btn-outline-secondary">Back to sequences</a>
    </div>
  </div>

  <div class="section-body">
    <div class="row">
      <div class="col-lg-8">
        <div class="card">
          <div class="card-body">
            <div class="form-group">
              <label>Name this sequence</label>
              <input id="sq_name" class="form-control" placeholder="e.g. Hotel Lead Follow-up"
                     value="<?php echo $sequence ? htmlspecialchars($sequence['name']) : ''; ?>">
            </div>

            <h6 class="mt-4">1. Enroll someone when&hellip;</h6>
            <div class="form-group">
              <select id="sq_trigger" class="form-control">
                <?php foreach ($triggers as $key => $label): ?>
                  <option value="<?php echo htmlspecialchars($key); ?>" <?php echo ($sequence && $sequence['trigger_event'] === $key) ? 'selected' : (!$sequence && $key === 'manual' ? 'selected' : ''); ?>>
                    <?php echo htmlspecialchars($label); ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <small class="text-muted">"Only when I add someone myself" means nobody joins automatically — use the "Enroll a customer by hand" box on the sequence's enrollment page, or the "Start a sequence" step in an Automation.</small>
            </div>

            <div id="sq_cond_wrap" style="display:none">
              <h6 class="mt-4">Only if&hellip; <small class="text-muted">(all must be true — leave empty for "always")</small></h6>
              <table class="table table-sm">
                <thead><tr><th>Information</th><th style="width:180px">Compare</th><th>Value</th><th style="width:40px"></th></tr></thead>
                <tbody id="sq_conditions"></tbody>
              </table>
              <button type="button" class="btn btn-sm btn-outline-primary" id="sq_add_cond"><i class="fas fa-plus"></i> Add a condition</button>
            </div>

            <h6 class="mt-4">2. Then, over time&hellip;</h6>
            <div id="sq_steps"></div>
            <button type="button" class="btn btn-sm btn-outline-primary" id="sq_add_step"><i class="fas fa-plus"></i> Add a step</button>

            <hr>
            <div class="form-inline">
              <label class="mr-2">When saved:</label>
              <select id="sq_status" class="form-control mr-3">
                <?php $st = $sequence ? $sequence['status'] : 'draft'; ?>
                <option value="draft" <?php echo $st === 'draft' ? 'selected' : ''; ?>>Keep it off for now</option>
                <option value="active" <?php echo $st === 'active' ? 'selected' : ''; ?>>Turn it on</option>
                <option value="paused" <?php echo $st === 'paused' ? 'selected' : ''; ?>>Paused</option>
              </select>
              <button type="button" class="btn btn-primary" id="sq_save"><i class="fas fa-save"></i> Save</button>
            </div>
            <div id="sq_errors" class="mt-3"></div>
          </div>
        </div>
      </div>

      <div class="col-lg-4">
        <div class="card">
          <div class="card-header"><h4>Try it out</h4></div>
          <div class="card-body">
            <p class="text-muted small">See the schedule this sequence would produce — without enrolling anyone or sending anything.</p>
            <div class="form-group">
              <label>Check against a real customer <small class="text-muted">(optional)</small></label>
              <input id="sq_sample" class="form-control" placeholder="customer id, e.g. 1234" dir="ltr">
            </div>
            <button type="button" class="btn btn-outline-primary btn-block" id="sq_test"><i class="fas fa-vial"></i> Show the schedule</button>
            <div id="sq_test_result" class="mt-3"></div>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<script>
(function () {
  var TOKEN = "<?php echo $this->session->userdata('csrf_token_session'); ?>", BASE = "<?php echo base_url(); ?>";
  var SEQ_ID = <?php echo $sequence ? (int) $sequence['id'] : 0; ?>;
  var OPS = <?php echo json_encode($operators, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP); ?>;
  var ACTION_LABELS = <?php echo json_encode($action_types, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP); ?>;
  var conditions = <?php echo $sequence && $sequence['enroll_conditions_json'] ? $sequence['enroll_conditions_json'] : '[]'; ?>;
  var steps = <?php
    $init_steps = array();
    foreach ($steps as $s) {
        $init_steps[] = array(
            'delay_seconds' => (int) $s['delay_seconds'],
            'action_type' => $s['action_type'],
            'action_params' => json_decode($s['action_params_json'] ?: '{}', true) ?: array(),
            'stop_condition' => $s['stop_condition_json'] ? json_decode($s['stop_condition_json'], true) : null,
        );
    }
    echo json_encode($init_steps, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
  ?>;
  if (!Array.isArray(conditions)) conditions = [];
  if (!Array.isArray(steps)) steps = [];

  var esc = function (s) { return $('<div>').text(s == null ? '' : String(s)).html(); };

  var FIELDS = [
    ['lead_score', 'Lead score'], ['platform', 'Channel (fb, ig, wa, tg, web)'],
    ['full_name', 'Customer name'], ['email', 'Customer email'], ['phone', 'Customer phone'],
    ['value', 'Deal or order value'], ['source', 'Where it came from']
  ];
  function fieldSelect(cls, val) {
    var found = false, html = '';
    FIELDS.forEach(function (f) {
      var sel = f[0] === val ? (found = true, ' selected') : '';
      html += '<option value="' + esc(f[0]) + '"' + sel + '>' + esc(f[1]) + '</option>';
    });
    html += '<option value="__custom"' + (found || !val ? '' : ' selected') + '>Something else&hellip;</option>';
    return '<select class="form-control form-control-sm ' + cls + '_field">' + html + '</select>' +
      '<input class="form-control form-control-sm mt-1 ' + cls + '_custom" dir="ltr" placeholder="field name" value="' +
      (found || !val ? '' : esc(val)) + '"' + (found || !val ? ' style="display:none"' : '') + '>';
  }
  function opsSelect(cls, val) {
    return '<select class="form-control form-control-sm ' + cls + '_op">' + Object.keys(OPS).map(function (k) {
      return '<option value="' + k + '"' + (val === k ? ' selected' : '') + '>' + esc(OPS[k]) + '</option>';
    }).join('') + '</select>';
  }

  // ---- enroll conditions (only meaningful for an event trigger, not "manual") ----
  function renderConditions() {
    $('#sq_conditions').html(conditions.map(function (c, i) {
      return '<tr data-i="' + i + '"><td>' + fieldSelect('c', c.field) + '</td>' +
        '<td>' + opsSelect('c', c.op) + '</td>' +
        '<td><input class="form-control form-control-sm c_value" value="' + esc(c.value) + '"></td>' +
        '<td><button type="button" class="btn btn-sm btn-link text-danger c_del">&times;</button></td></tr>';
    }).join(''));
  }
  function syncCondWrap() { $('#sq_cond_wrap').toggle($('#sq_trigger').val() !== 'manual'); }
  $('#sq_trigger').on('change', syncCondWrap);

  // ---- steps ----
  function actionFields(a) {
    var p = a.action_params || {};
    function txt(key, label, ph, type) {
      return '<div class="form-group col-md-6 mb-2"><label class="small mb-1">' + label + '</label>' +
        '<input type="' + (type || 'text') + '" class="form-control form-control-sm p_' + key + '" placeholder="' + (ph || '') + '" value="' + esc(p[key]) + '"></div>';
    }
    function area(key, label, ph) {
      return '<div class="form-group col-md-12 mb-2"><label class="small mb-1">' + label + '</label>' +
        '<textarea class="form-control form-control-sm p_' + key + '" rows="2" placeholder="' + (ph || '') + '">' + esc(p[key]) + '</textarea></div>';
    }
    var f = '';
    switch (a.action_type) {
      case 'send_message': f = area('text', 'Message to the customer', 'Thanks {{full_name}}! Here is what you need to know&hellip;'); break;
      case 'add_label': f = txt('label', 'Tag to add', 'e.g. Warm lead'); break;
      case 'update_lead_score': f = txt('delta', 'Add to the score (use a minus to subtract)', '10', 'number'); break;
      case 'create_crm_deal':
        f = txt('title', 'Deal title', '{{full_name}} — new lead') + txt('value', 'Value', '0', 'number') + txt('currency', 'Currency', 'EGP');
        break;
      case 'move_crm_stage': f = txt('stage', 'Move the deal to this stage', 'e.g. Interested'); break;
      case 'notify_team':
        f = '<div class="form-group col-md-6 mb-2"><label class="small mb-1">How</label>' +
          '<select class="form-control form-control-sm p_channel"><option value="whatsapp"' + (p.channel !== 'email' ? ' selected' : '') + '>WhatsApp</option>' +
          '<option value="email"' + (p.channel === 'email' ? ' selected' : '') + '>Email</option></select></div>' +
          txt('to', 'Send it to', 'phone number or email') + area('text', 'What to say', 'Follow up needed: {{full_name}}');
        break;
      case 'call_webhook': f = txt('url', 'Web address (must start with https://)', 'https://example.com/hook'); break;
      case 'write_google_sheet': f = txt('note', 'Note to write in the row', 'from sequence'); break;
      case 'create_task':
        f = txt('subject', 'Task title', 'Call {{full_name}} back') + txt('due_in_minutes', 'Due in (minutes from now)', '60', 'number') + area('description', 'Notes for your team', '');
        break;
      case 'start_sequence': f = txt('sequence_id', 'Sequence id to start', 'e.g. 3', 'number'); break;
    }
    return f;
  }

  function stopConditionBlock(s, i) {
    var enabled = !!s.stop_condition, sc = s.stop_condition || {field: '', op: 'eq', value: ''};
    return '<div class="mt-2 pt-2 border-top">' +
      '<div class="custom-control custom-checkbox"><input type="checkbox" class="custom-control-input sc_enable" id="sc_en_' + i + '"' + (enabled ? ' checked' : '') + '>' +
      '<label class="custom-control-label small" for="sc_en_' + i + '">Stop the whole sequence here if&hellip;</label></div>' +
      '<div class="sc_row form-row mt-1" style="' + (enabled ? '' : 'display:none') + '">' +
      '<div class="col-md-4">' + fieldSelect('sc', sc.field) + '</div>' +
      '<div class="col-md-3">' + opsSelect('sc', sc.op) + '</div>' +
      '<div class="col-md-4"><input class="form-control form-control-sm sc_value" value="' + esc(sc.value) + '"></div></div></div>';
  }

  function renderSteps() {
    $('#sq_steps').html(steps.map(function (s, i) {
      var opts = Object.keys(ACTION_LABELS).map(function (k) {
        return '<option value="' + k + '"' + (s.action_type === k ? ' selected' : '') + '>' + esc(ACTION_LABELS[k]) + '</option>';
      }).join('');
      return '<div class="card mb-2" data-i="' + i + '"><div class="card-body py-2">' +
        '<div class="d-flex justify-content-between align-items-center mb-2">' +
        '<strong>Step ' + (i + 1) + '</strong>' +
        '<button type="button" class="btn btn-sm btn-link text-danger st_del">Remove</button></div>' +
        '<div class="form-row">' +
        '<div class="form-group col-md-4 mb-2"><label class="small mb-1">When (after enrollment starts)</label>' +
        '<div class="input-group input-group-sm"><input type="number" min="0" class="form-control st_delay_n" value="' + delayNumber(s.delay_seconds) + '">' +
        '<div class="input-group-append"><select class="form-control st_delay_u">' + delayUnitOptions(s.delay_seconds) + '</select></div></div></div>' +
        '<div class="form-group col-md-8 mb-2"><label class="small mb-1">Do this</label>' +
        '<select class="form-control form-control-sm st_action">' + opts + '</select></div>' +
        '</div><div class="form-row st_params">' + actionFields(s) + '</div>' +
        stopConditionBlock(s, i) +
        '</div></div>';
    }).join(''));
  }

  function delayUnits() { return [['1', 'seconds'], ['60', 'minutes'], ['3600', 'hours'], ['86400', 'days']]; }
  function bestUnit(seconds) {
    var units = delayUnits().slice().reverse();
    for (var i = 0; i < units.length; i++) if (seconds > 0 && seconds % parseInt(units[i][0], 10) === 0) return units[i][0];
    return '60';
  }
  function delayNumber(seconds) { var u = bestUnit(seconds); return seconds > 0 ? Math.round(seconds / parseInt(u, 10)) : 0; }
  function delayUnitOptions(seconds) {
    var u = bestUnit(seconds);
    return delayUnits().map(function (d) { return '<option value="' + d[0] + '"' + (d[0] === u ? ' selected' : '') + '>' + d[1] + '</option>'; }).join('');
  }

  function collect() {
    conditions = $('#sq_conditions tr').map(function () {
      var tr = $(this), field = tr.find('.c_field').val();
      if (field === '__custom') field = tr.find('.c_custom').val();
      return {field: field, op: tr.find('.c_op').val(), value: tr.find('.c_value').val()};
    }).get().filter(function (c) { return (c.field || '').trim() !== ''; });

    steps = $('#sq_steps > .card').map(function () {
      var el = $(this);
      var n = parseFloat(el.find('.st_delay_n').val()) || 0, u = parseInt(el.find('.st_delay_u').val(), 10) || 60;
      var params = {};
      el.find('.st_params [class*="p_"]').each(function () {
        var cls = ($(this).attr('class') || '').split(/\s+/).filter(function (c) { return c.indexOf('p_') === 0; })[0];
        if (cls) params[cls.substring(2)] = $(this).val();
      });
      var stop = null;
      if (el.find('.sc_enable').is(':checked')) {
        var field = el.find('.sc_field').val();
        if (field === '__custom') field = el.find('.sc_custom').val();
        if ((field || '').trim() !== '') stop = {field: field, op: el.find('.sc_op').val(), value: el.find('.sc_value').val()};
      }
      return {delay_seconds: Math.round(n * u), action_type: el.find('.st_action').val(), action_params: params, stop_condition: stop};
    }).get();
  }

  $(document).on('change', '.c_field, .sc_field', function () {
    var wrap = $(this).closest('td, .col-md-4'), custom = wrap.find('[class*="_custom"]');
    custom.toggle($(this).val() === '__custom');
  });
  $('#sq_add_cond').on('click', function () { conditions.push({field: '', op: 'eq', value: ''}); renderConditions(); });
  $(document).on('click', '.c_del', function () { collect(); conditions.splice($(this).closest('tr').data('i'), 1); renderConditions(); });

  $('#sq_add_step').on('click', function () {
    collect();
    steps.push({delay_seconds: 0, action_type: Object.keys(ACTION_LABELS)[0], action_params: {}, stop_condition: null});
    renderSteps();
  });
  $(document).on('click', '.st_del', function () { collect(); steps.splice($(this).closest('.card').data('i'), 1); renderSteps(); });
  $(document).on('change', '.st_action', function () { collect(); renderSteps(); });
  $(document).on('change', '.sc_enable', function () { $(this).closest('.border-top').find('.sc_row').toggle(this.checked); });

  $('#sq_save').on('click', function () {
    collect();
    var payload = {
      id: SEQ_ID, name: $('#sq_name').val(), trigger_event: $('#sq_trigger').val(),
      enroll_conditions_json: JSON.stringify(conditions), steps_json: JSON.stringify(steps),
      status: $('#sq_status').val(), csrf_token: TOKEN
    };
    $.post(BASE + 'sequences/save', payload, function (r) {
      r = typeof r === 'string' ? JSON.parse(r) : r;
      if (r.status === '1') { window.location = BASE + 'sequences/form/' + r.id; }
      else $('#sq_errors').html('<div class="alert alert-danger">' + (r.errors || []).map(esc).join('<br>') + '</div>');
    });
  });

  $('#sq_test').on('click', function () {
    collect();
    $.post(BASE + 'sequences/test', {steps_json: JSON.stringify(steps), sample_subscriber_id: $('#sq_sample').val(), csrf_token: TOKEN}, function (r) {
      r = typeof r === 'string' ? JSON.parse(r) : r;
      if (r.status !== '1') { $('#sq_test_result').html('<div class="text-danger">Could not compute the schedule.</div>'); return; }
      var html = '<table class="table table-sm"><tbody>' + r.schedule.map(function (row) {
        var pol = row.policy ? '<br><small class="text-muted">' + esc(row.policy.explain || '') + '</small>' : '';
        return '<tr><td><strong>' + esc(row.when) + '</strong></td><td>' + esc(row.action_label) + pol + '</td></tr>';
      }).join('') + '</tbody></table>';
      $('#sq_test_result').html(html);
    });
  });

  renderConditions(); renderSteps(); syncCondWrap();
})();
</script>
