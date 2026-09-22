<?php
/** Shared footer for the Setup Guide: closes the section and gives every step the same
 *  tiny AJAX helper (ob.post) so the steps stay declarative. */
?>
  </div>
</section>
<script>
var OB = {
  TOKEN: "<?php echo $this->session->userdata('csrf_token_session'); ?>",
  BASE: "<?php echo base_url(); ?>",
  STEP: <?php echo (int) $step; ?>,
  TOTAL: <?php echo (int) $steps_total; ?>,
  msg: function (type, text) {
    $('#ob_msg').html('<div class="alert alert-' + type + '">' + text + '</div>');
    $('html,body').animate({scrollTop: 0}, 150);
  },
  post: function (path, payload, onOk) {
    payload = payload || {};
    payload.csrf_token = OB.TOKEN;
    var $b = $('.ob-save').prop('disabled', true);
    $.ajax({
      url: OB.BASE + 'onboarding/' + path, type: 'POST', data: payload, dataType: 'json',
      headers: {'X-Requested-With': 'XMLHttpRequest'}
    }).done(function (r) {
      $b.prop('disabled', false);
      if (r && r.status === '1') { if (onOk) onOk(r); }
      else {
        var t = (r && r.message) ? r.message : 'Could not save.';
        if (r && r.errors && r.errors.length) t += '<ul><li>' + r.errors.join('</li><li>') + '</li></ul>';
        OB.msg('danger', t);
      }
    }).fail(function () { $b.prop('disabled', false); OB.msg('danger', 'Network error, nothing was saved.'); });
  },
  next: function () { window.location = OB.BASE + 'onboarding/step/' + Math.min(OB.TOTAL, OB.STEP + 1); }
};
</script>
