<?php $this->load->view('admin/onboarding/_shell'); ?>
<?php
  $manage = array('fb' => 'social_accounts', 'wa' => 'whatsapp_bot', 'openwa' => 'openwa_bot', 'tg' => 'telegram_bot', 'web' => 'webchat');
  $biz = trim((string) ($state['data']['business_name'] ?? ''));
?>
<p class="text-muted">Tick only the pages and numbers that belong to <strong><?php echo $biz !== '' ? htmlspecialchars($biz) : 'this business'; ?></strong>.
  The AI employee, answers and working hours you set in the next steps apply to the ticked ones only — nothing else on your account is touched.
  <br><span dir="rtl">علّم على الصفحات والأرقام اللي تخص النشاط ده بس. الموظف والإجابات ومواعيد الشغل اللي هتظبطها في الخطوات الجاية هتتطبّق عليها هي بس، ومفيش أي حاجة تانية في حسابك هتتأثر.</span></p>

<div class="row">
  <div class="col-lg-8">
    <div class="card"><div class="card-header"><h4>Pages for this business <small class="text-muted" dir="rtl">صفحات النشاط ده</small></h4></div>
      <div class="card-body">
        <?php if (empty($channels)): ?>
          <p class="text-muted mb-0">Nothing connected yet. Connect a page or number using the buttons on the right, then press Refresh.
            <br><span dir="rtl">لسه مفيش حاجة متوصّلة. وصّل صفحة أو رقم من الأزرار اللي على الجنب وبعدين دوس تحديث.</span></p>
        <?php else: ?>
          <table class="table table-sm mb-0">
            <tbody>
            <?php foreach ($channels as $k => $c):
                $akey = ($c['kind'] === 'openwa' ? null : $c['channel'] . ':' . $c['target']);
                $other = ($akey !== null && isset($assigned_to[$akey])) ? $assigned_to[$akey] : null; ?>
              <tr>
                <td class="ob-col-check">
                  <input type="checkbox" class="ob-target" id="t_<?php echo md5($k); ?>" value="<?php echo htmlspecialchars($k); ?>" <?php echo isset($selected[$k]) ? 'checked' : ''; ?>>
                </td>
                <td class="ob-col-icon"><i class="<?php echo htmlspecialchars($c['icon']); ?>"></i></td>
                <td>
                  <label for="t_<?php echo md5($k); ?>" class="mb-0 ob-clickable"><?php echo htmlspecialchars($c['label']); ?></label>
                  <br><small class="text-muted"><?php echo htmlspecialchars($c['note']); ?></small>
                  <?php if ($other !== null): ?>
                    <br><small class="text-warning">Already answered by “<?php echo htmlspecialchars($other); ?>” — that stays as it is.
                      <span dir="rtl">عليها موظف تاني بالفعل وهيفضل زي ما هو.</span></small>
                  <?php endif; ?>
                </td>
                <td class="text-right ob-col-nowrap">
                  <a href="<?php echo base_url($manage[$c['kind']] ?? 'social_accounts'); ?>" target="_blank" class="btn btn-sm btn-outline-secondary" title="Disconnect or change this channel on its own page">
                    <i class="fas fa-cog"></i> Manage <span dir="rtl">إدارة</span></a>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
          <small class="text-muted d-block mt-2">To disconnect a page or number, use <em>Manage</em> — it opens that channel's own settings.
            <span dir="rtl">لو عايز تفصل صفحة أو رقم، دوس "إدارة" وهتفتحلك إعدادات القناة نفسها.</span></small>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <div class="col-lg-4">
    <div class="card"><div class="card-header"><h4>Connect another <small class="text-muted" dir="rtl">وصّل قناة جديدة</small></h4></div>
      <div class="card-body">
        <?php foreach ($connect_links as $l): ?>
          <a class="btn btn-outline-primary btn-block text-left mb-2" href="<?php echo base_url($l['url']); ?>" target="_blank">
            <i class="<?php echo htmlspecialchars($l['icon']); ?>"></i> <?php echo htmlspecialchars($l['label']); ?>
            <small class="text-muted float-right" dir="rtl"><?php echo htmlspecialchars($l['ar']); ?></small>
          </a>
        <?php endforeach; ?>
        <small class="text-muted d-block mt-2">Opens in a new tab. Come back here and press Refresh when you are done.</small>
      </div>
    </div>
  </div>
</div>

<button class="btn btn-outline-secondary" onclick="window.location.reload()"><i class="fas fa-sync"></i> Refresh</button>
<button class="btn btn-primary ob-save" id="ob_go" disabled>Save and continue</button>
<small class="text-muted ml-2" id="ob_go_hint">Tick at least one page for this business. <span dir="rtl">علّم على صفحة واحدة على الأقل.</span></small>
<?php $this->load->view('admin/onboarding/_foot'); ?>
<script>
$(function () {
  function picked() { return $('.ob-target:checked').map(function () { return this.value; }).get(); }
  function paint() { var n = picked().length; $('#ob_go').prop('disabled', n === 0); $('#ob_go_hint').toggle(n === 0); }
  $('.ob-target').on('change', paint);
  $('#ob_go').on('click', function () { OB.post('save_channels', {targets: picked()}, function () { OB.next(); }); });
  paint();
});
</script>
