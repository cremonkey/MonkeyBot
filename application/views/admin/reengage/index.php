<section class="section">
  <div class="section-header">
    <h1><i class="fas fa-rotate-left"></i> <?php echo $page_title; ?></h1>
  </div>

  <div class="section-body">

    <div class="alert alert-info">
      <strong>How reach works.</strong> Facebook and Instagram only allow a message
      to a customer within <b>24 hours</b> of their last message to you. Contacts
      outside that window are queued and sent automatically the moment they message
      you again. Contacts between 1 and 7 days old can only be answered by a human,
      from Livechat — this tool will never message them for you.
    </div>

    <div class="row">
      <div class="col-lg-7">
        <div class="card">
          <div class="card-header"><h4>New campaign</h4></div>
          <div class="card-body">

            <div class="form-group">
              <label>Campaign name</label>
              <input type="text" id="name" class="form-control" placeholder="July win-back">
            </div>

            <div class="row">
              <div class="col-md-6 form-group">
                <label>Channel</label>
                <select id="social_media" class="form-control filter">
                  <option value="fb">Facebook</option>
                  <option value="ig">Instagram</option>
                </select>
              </div>
              <div class="col-md-6 form-group">
                <label>Page</label>
                <select id="page_table_id" class="form-control filter">
                  <?php foreach ($pages as $p): ?>
                    <option value="<?php echo (int) $p['id']; ?>"><?php echo htmlspecialchars($p['page_name']); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>

            <hr><h6 class="text-muted">Who to target</h6>

            <div class="row">
              <div class="col-md-6 form-group">
                <label>Silent for at least (days)</label>
                <input type="number" id="quiet_for_days" class="form-control filter" min="0" placeholder="any">
              </div>
              <div class="col-md-6 form-group">
                <label>Active within (days)</label>
                <input type="number" id="active_within_days" class="form-control filter" min="0" placeholder="any">
              </div>
            </div>

            <div class="row">
              <div class="col-md-6 form-group">
                <label>CRM</label>
                <select id="crm_mode" class="form-control filter">
                  <option value="any">Anyone</option>
                  <option value="has_open_deal">Has an open deal</option>
                  <option value="no_deal">Never became a deal</option>
                </select>
              </div>
              <div class="col-md-3 form-group">
                <label>Lead score min</label>
                <input type="number" id="lead_score_min" class="form-control filter" placeholder="any">
              </div>
              <div class="col-md-3 form-group">
                <label>Lead score max</label>
                <input type="number" id="lead_score_max" class="form-control filter" placeholder="any">
              </div>
            </div>

            <hr><h6 class="text-muted">Message</h6>

            <div class="form-group">
              <label>Text <small class="text-muted">— <code>{{first_name}}</code> is replaced per customer</small></label>
              <textarea id="message_text" class="form-control" rows="3" placeholder="أهلاً {{first_name}}، عندنا عروض جديدة..."></textarea>
              <small class="text-muted">An opt-out line is appended automatically to every message.</small>
            </div>

            <div class="form-group">
              <label>Variant B <small class="text-muted">— optional, splits the audience for an A/B test</small></label>
              <textarea id="variant_b_text" class="form-control" rows="2"></textarea>
            </div>

            <div class="form-group">
              <label>Buttons <small class="text-muted">— JSON, max 3, e.g. <code>[{"title":"شوف العروض","url":"https://..."}]</code></small></label>
              <input type="text" id="buttons" class="form-control" placeholder="[]">
            </div>

            <hr><h6 class="text-muted">Pacing &amp; schedule</h6>

            <div class="row">
              <div class="col-md-4 form-group">
                <label>Messages per hour</label>
                <input type="number" id="messages_per_hour" class="form-control" value="60" min="1">
              </div>
              <div class="col-md-4 form-group">
                <label>Daily cap</label>
                <input type="number" id="daily_cap" class="form-control" value="500" min="0">
              </div>
              <div class="col-md-4 form-group">
                <label>Pause between sends (sec)</label>
                <div class="input-group">
                  <input type="number" id="jitter_min_sec" class="form-control" value="2" min="0">
                  <input type="number" id="jitter_max_sec" class="form-control" value="8" min="0">
                </div>
              </div>
            </div>

            <div class="row">
              <div class="col-md-3 form-group">
                <label>Quiet from</label>
                <input type="time" id="quiet_start" class="form-control" value="22:00">
              </div>
              <div class="col-md-3 form-group">
                <label>Quiet until</label>
                <input type="time" id="quiet_end" class="form-control" value="08:00">
              </div>
              <div class="col-md-6 form-group">
                <label>Timezone</label>
                <input type="text" id="timezone" class="form-control" value="Africa/Cairo">
              </div>
            </div>

            <div class="row">
              <div class="col-md-4 form-group">
                <label>Start at <small class="text-muted">(blank = now)</small></label>
                <input type="datetime-local" id="schedule_time" class="form-control">
              </div>
              <div class="col-md-4 form-group">
                <label>Queue expires after (days)</label>
                <input type="number" id="queue_ttl_days" class="form-control" value="30" min="1">
              </div>
              <div class="col-md-4 form-group">
                <label>Wait for chat idle (min)</label>
                <input type="number" id="reentry_idle_minutes" class="form-control" value="30" min="0">
              </div>
            </div>

            <button class="btn btn-secondary" id="btn_save">Save draft</button>
            <button class="btn btn-primary" id="btn_build" disabled>Calculate audience</button>
            <button class="btn btn-success" id="btn_start" disabled>Start sending</button>

          </div>
        </div>
      </div>

      <div class="col-lg-5">
        <div class="card">
          <div class="card-header"><h4>Reach</h4></div>
          <div class="card-body">
            <h2 id="c_matched" class="mb-0">—</h2>
            <p class="text-muted">contacts match your filters</p>

            <table class="table table-sm">
              <tr><td><span class="badge badge-success">will send now</span></td>
                  <td class="text-right"><b id="c_in_window">—</b></td></tr>
              <tr><td><span class="badge badge-warning">needs a human reply</span></td>
                  <td class="text-right"><b id="c_human_agent">—</b></td></tr>
              <tr><td><span class="badge badge-secondary">queued until they reply</span></td>
                  <td class="text-right"><b id="c_out_of_window">—</b></td></tr>
              <tr><td><span class="badge badge-danger">excluded</span></td>
                  <td class="text-right"><b id="c_excluded">—</b></td></tr>
            </table>

            <div id="excl_detail" class="small text-muted"></div>

            <div id="zero_warn" class="alert alert-warning mt-3" style="display:none">
              Nobody is reachable right now. The queued contacts will receive this
              message automatically as soon as they write to you again.
            </div>
          </div>
        </div>

        <div class="card">
          <div class="card-header"><h4>Import past contacts</h4></div>
          <div class="card-body">
            <p class="text-muted small">
              Pulls everyone who ever messaged this page from Facebook, so they can be
              targeted. Safe to re-run; it resumes where it left off.
            </p>
            <button class="btn btn-outline-primary btn-block" id="btn_import">Import from this page</button>
            <div id="import_result" class="small mt-2"></div>
          </div>
        </div>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><h4>Campaigns</h4></div>
      <div class="card-body p-0">
        <?php if (!empty($campaigns)): ?>
        <div class="table-responsive"><table class="table table-striped mb-0">
          <thead><tr><th>Name</th><th>Channel</th><th>Status</th><th>Sent</th><th>Queued</th><th>Total</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($campaigns as $c): ?>
            <tr>
              <td><?php echo htmlspecialchars($c['name']); ?></td>
              <td><?php echo strtoupper($c['social_media']); ?></td>
              <td>
                <span class="badge badge-<?php
                  echo $c['status'] === 'running' ? 'success'
                     : ($c['status'] === 'halted' ? 'danger'
                     : ($c['status'] === 'done' ? 'info' : 'secondary')); ?>">
                  <?php echo $c['status']; ?>
                </span>
                <?php if ($c['status'] === 'halted'): ?>
                  <div class="small text-danger"><?php echo htmlspecialchars((string) $c['halt_reason']); ?></div>
                <?php endif; ?>
              </td>
              <td><?php echo (int) $c['sent']; ?></td>
              <td><?php echo (int) $c['queued']; ?></td>
              <td><?php echo (int) $c['total']; ?></td>
              <td class="text-right">
                <a href="<?php echo base_url('reengage/report/' . (int) $c['id']); ?>" class="btn btn-sm btn-outline-info">Report</a>
                <?php if ($c['status'] === 'running'): ?>
                  <button class="btn btn-sm btn-outline-danger btn_pause" data-id="<?php echo (int) $c['id']; ?>">Pause</button>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table></div>
        <?php else: ?>
          <div class="p-4 text-muted">No campaigns yet.</div>
        <?php endif; ?>
      </div>
    </div>

  </div>
</section>

<script>
var RE_TOKEN = "<?php echo $this->session->userdata('csrf_token_session'); ?>",
    RE_BASE  = "<?php echo base_url(); ?>",
    RE_ID    = 0;

function reFilters() {
  return {
    csrf_token: RE_TOKEN,
    social_media: $('#social_media').val(),
    page_table_id: $('#page_table_id').val(),
    crm_mode: $('#crm_mode').val(),
    quiet_for_days: $('#quiet_for_days').val(),
    active_within_days: $('#active_within_days').val(),
    lead_score_min: $('#lead_score_min').val(),
    lead_score_max: $('#lead_score_max').val()
  };
}

function reRender(c) {
  $('#c_matched').text(c.matched);
  $('#c_in_window').text(c.in_window);
  $('#c_human_agent').text(c.human_agent);
  $('#c_out_of_window').text(c.out_of_window);
  $('#c_excluded').text(c.excluded);

  var b = c.excluded_breakdown || {}, bits = [];
  if (b.opted_out)     bits.push(b.opted_out + ' opted out');
  if (b.unsubscribed)  bits.push(b.unsubscribed + ' unsubscribed');
  if (b.unavailable)   bits.push(b.unavailable + ' unavailable');
  if (b.human_handoff) bits.push(b.human_handoff + ' with a human agent');
  $('#excl_detail').text(bits.join(' · '));

  $('#zero_warn').toggle(c.in_window === 0 && c.matched > 0);
}

// The counter is the safety rail: never let anyone start a campaign without
// having seen how many people it can actually reach.
function rePreview() {
  $.post(RE_BASE + 'reengage/preview', reFilters(), function (r) {
    if (r.status === 'ok') reRender(r.counts);
  }, 'json');
}

$(document).on('change keyup', '.filter', rePreview);
$(rePreview);

$('#btn_save').click(function () {
  var d = reFilters();
  d.id = RE_ID;
  d.name = $('#name').val();
  d.message_text = $('#message_text').val();
  d.variant_b_text = $('#variant_b_text').val();
  d.buttons = $('#buttons').val();
  ['messages_per_hour','daily_cap','jitter_min_sec','jitter_max_sec','quiet_start',
   'quiet_end','timezone','schedule_time','queue_ttl_days','reentry_idle_minutes']
    .forEach(function (k) { d[k] = $('#' + k).val(); });

  $.post(RE_BASE + 'reengage/save', d, function (r) {
    if (r.status !== 'ok') { alert(r.message); return; }
    RE_ID = r.id;
    $('#btn_build').prop('disabled', false);
    alert('Draft saved. Nothing has been sent.');
  }, 'json');
});

$('#btn_build').click(function () {
  $.post(RE_BASE + 'reengage/build_audience', { csrf_token: RE_TOKEN, id: RE_ID }, function (r) {
    if (r.status !== 'ok') { alert(r.message); return; }
    reRender(r.counts);
    $('#btn_start').prop('disabled', false);
    alert('Audience built — ' + r.counts.inserted + ' contacts queued. Still nothing sent.');
  }, 'json');
});

$('#btn_start').click(function () {
  var n = $('#c_in_window').text();
  if (!confirm('Start sending? ' + n + ' contacts will be messaged now; the rest wait until they reply.')) return;
  $.post(RE_BASE + 'reengage/start', { csrf_token: RE_TOKEN, id: RE_ID }, function (r) {
    if (r.status !== 'ok') { alert(r.message); return; }
    location.reload();
  }, 'json');
});

$('.btn_pause').click(function () {
  $.post(RE_BASE + 'reengage/pause', { csrf_token: RE_TOKEN, id: $(this).data('id') }, function () {
    location.reload();
  }, 'json');
});

$('#btn_import').click(function () {
  var b = $(this).prop('disabled', true).text('Importing…');
  $.post(RE_BASE + 'reengage/import',
    { csrf_token: RE_TOKEN, page_table_id: $('#page_table_id').val(), social_media: $('#social_media').val() },
    function (r) {
      b.prop('disabled', false).text('Import from this page');
      if (r.status !== 'ok') { $('#import_result').html('<span class="text-danger">' + r.result.error + '</span>'); return; }
      $('#import_result').text(r.result.threads + ' threads · ' + r.result.imported + ' new · ' + r.result.updated + ' updated');
      rePreview();
    }, 'json');
});
</script>
