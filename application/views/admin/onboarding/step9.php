<?php $this->load->view('admin/onboarding/_shell'); ?>
<p class="text-muted">Here is everything the guide set up for you. Anything not ticked, you can still come back to.
  <br><span dir="rtl">ده كل اللي الدليل ظبطه لك. أي حاجة مش متعلّمة تقدر ترجعلها في أي وقت.</span></p>

<div class="card"><div class="card-body">
  <table class="table mb-0">
    <tbody>
    <?php foreach ($checklist as $c): ?>
      <tr>
        <td class="ob-col-num">
          <?php if (!empty($c['done'])): ?><i class="fas fa-check-circle text-success"></i>
          <?php elseif (!empty($c['required'])): ?><i class="fas fa-times-circle text-danger"></i>
          <?php else: ?><i class="far fa-circle text-muted"></i><?php endif; ?>
        </td>
        <td>
          <strong><?php echo htmlspecialchars($c['label_en']); ?></strong>
          <?php if (!empty($c['required']) && empty($c['done'])): ?><span class="badge badge-danger ml-1">needed</span><?php endif; ?>
          <br><small class="text-muted" dir="rtl"><?php echo htmlspecialchars($c['label_ar']); ?></small>
          <?php if (!empty($c['detail'])): ?><br><small class="text-muted"><?php echo htmlspecialchars($c['detail']); ?></small><?php endif; ?>
        </td>
        <td class="text-right">
          <?php if (!empty($c['link'])): ?>
            <a class="btn btn-sm btn-outline-secondary" href="<?php echo base_url($c['link']); ?>"><?php echo empty($c['done']) ? 'Set up' : 'Open'; ?></a>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div></div>

<?php if (!empty($state['completed_at'])): ?>
  <div class="alert alert-success">Live since <?php echo htmlspecialchars($state['completed_at']); ?>. You can re-run any step above at any time — nothing you have saved is ever deleted.</div>
<?php endif; ?>

<button class="btn btn-success btn-lg ob-save" id="ob_go"><i class="fas fa-rocket"></i> <?php echo empty($state['completed_at']) ? 'Go live' : 'Re-check and confirm'; ?></button>
<?php $this->load->view('admin/onboarding/_foot'); ?>
<script>
$(function () {
  $('#ob_go').on('click', function () {
    OB.post('go_live', {}, function () {
      OB.msg('success', 'You are live. Your AI employee is answering customers now.');
      setTimeout(function () { window.location.reload(); }, 1200);
    });
  });
});
</script>
