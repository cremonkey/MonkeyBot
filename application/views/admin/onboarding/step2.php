<?php $this->load->view('admin/onboarding/_shell'); ?>
<p class="text-muted">Connect the places your customers message you. Each one opens its own setup page — this guide only shows you what is connected.
  <br><span dir="rtl">وصّل الأماكن اللي عملاؤك بيكلموك منها. كل واحدة ليها صفحة توصيل خاصة بيها، والدليل ده بيوريك المتوصّل إيه بس.</span></p>

<div class="row">
  <div class="col-lg-7">
    <div class="card"><div class="card-header"><h4>Connected now <small class="text-muted" dir="rtl">المتوصّل دلوقتي</small></h4></div>
      <div class="card-body">
        <?php if (empty($channels)): ?>
          <p class="text-muted mb-0">Nothing connected yet. Use the buttons on the right — you need at least one.
            <br><span dir="rtl">لسه مفيش حاجة متوصّلة. استخدم الأزرار اللي على الجنب، محتاج واحدة على الأقل.</span></p>
        <?php else: ?>
          <table class="table table-sm mb-0">
            <tbody>
            <?php foreach ($channels as $c): ?>
              <tr>
                <td style="width:32px"><i class="<?php echo htmlspecialchars($c['icon']); ?>"></i></td>
                <td><?php echo htmlspecialchars($c['label']); ?><br><small class="text-muted"><?php echo htmlspecialchars($c['note']); ?></small></td>
                <td class="text-right"><span class="badge badge-success">Connected</span></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <div class="col-lg-5">
    <div class="card"><div class="card-header"><h4>Add a channel <small class="text-muted" dir="rtl">ضيف قناة</small></h4></div>
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

<button class="btn btn-outline-secondary" onclick="window.location.reload()"><i class="fas fa-sync"></i> Refresh status</button>
<button class="btn btn-primary ob-save" id="ob_go">Continue</button>
<?php $this->load->view('admin/onboarding/_foot'); ?>
<script>
$(function () {
  $('#ob_go').on('click', function () { OB.post('save_channels', {}, function () { OB.next(); }); });
});
</script>
