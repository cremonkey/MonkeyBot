<?php
/* Instagram story-reply rules. Data: $page (facebook_rx_fb_page_info row), $rules, $stories.
   Every dynamic value is escaped with $e. */
$e = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
$L = function ($k) { return htmlspecialchars((string) $this->lang->line($k), ENT_QUOTES, 'UTF-8'); };
?>
<section class="section section_custom">
  <div class="section-header">
    <h1><i class="fas fa-circle-notch"></i> <?php echo $L('Story reply rules'); ?> — @<?php echo $e($page['insta_username']); ?></h1>
    <div class="section-header-breadcrumb">
      <div class="breadcrumb-item"><a href="<?php echo base_url('instagram_reply/get_account_lists'); ?>"><?php echo $L('Instagram'); ?></a></div>
      <div class="breadcrumb-item"><?php echo $L('Story reply rules'); ?></div>
    </div>
  </div>

  <div class="section-body">
    <p class="text-muted"><?php echo $L('When someone replies to your story, a rule for that exact story runs first, then keyword rules. If nothing matches, your general story reply bot runs as before.'); ?></p>
    <div class="row">
      <div class="col-12 col-lg-5">
        <div class="card"><div class="card-header"><h4 id="igsr_form_title"><?php echo $L('New rule'); ?></h4></div>
          <div class="card-body">
            <form id="igsr_form" dir="auto">
              <input type="hidden" name="id" value="0">
              <input type="hidden" name="page_table_id" value="<?php echo (int) $page['id']; ?>">
              <div class="form-group"><label><?php echo $L('Rule name'); ?></label><input class="form-control" name="name" maxlength="100"></div>
              <div class="form-group"><label><?php echo $L('Story'); ?></label>
                <select class="form-control" name="story_id">
                  <option value=""><?php echo $L('Any story (match by keyword)'); ?></option>
                  <?php foreach ($stories as $s): ?>
                    <option value="<?php echo $e($s['story_id']); ?>"><?php echo $e(date('d M H:i', strtotime($s['first_seen'])) . ' — ' . $s['replies'] . ' ' . $this->lang->line('replies') . ($s['last_text'] !== '' ? ' — "' . mb_substr($s['last_text'], 0, 40, 'UTF-8') . '"' : '')); ?></option>
                  <?php endforeach; ?>
                </select>
                <small class="text-muted"><?php echo $L('Stories appear here after their first reply.'); ?></small>
              </div>
              <div class="form-group"><label><?php echo $L('Keywords (comma separated, optional for a specific story)'); ?></label><input class="form-control" name="keywords" maxlength="500" placeholder="السعر, price"></div>
              <div class="form-group"><label><?php echo $L('Reply'); ?></label><textarea class="form-control" name="reply_text" rows="4" maxlength="640" required></textarea></div>
              <div class="form-row">
                <div class="form-group col-5"><label><?php echo $L('Button title'); ?></label><input class="form-control" name="button_title" maxlength="20"></div>
                <div class="form-group col-7"><label><?php echo $L('Button link'); ?></label><input class="form-control" name="button_url" maxlength="1000" placeholder="https://"></div>
              </div>
              <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> <?php echo $L('Save'); ?></button>
              <button type="button" class="btn btn-light" id="igsr_reset"><?php echo $L('Clear'); ?></button>
            </form>
          </div>
        </div>
      </div>
      <div class="col-12 col-lg-7">
        <div class="card"><div class="card-header"><h4><?php echo $L('Rules'); ?></h4></div>
          <div class="card-body p-0"><div class="table-responsive">
            <table class="table table-striped mb-0">
              <thead><tr><th><?php echo $L('Rule'); ?></th><th><?php echo $L('Trigger'); ?></th><th><?php echo $L('Sent'); ?></th><th></th></tr></thead>
              <tbody>
              <?php if (empty($rules)): ?>
                <tr><td colspan="4" class="text-center text-muted"><?php echo $L('No rules yet.'); ?></td></tr>
              <?php endif; ?>
              <?php foreach ($rules as $r): ?>
                <tr data-rule="<?php echo $e(json_encode($r, JSON_UNESCAPED_UNICODE)); ?>">
                  <td><b><?php echo $e($r['name'] !== '' ? $r['name'] : '#' . $r['id']); ?></b><br><small class="text-muted"><?php echo $e(mb_substr($r['reply_text'], 0, 60, 'UTF-8')); ?></small></td>
                  <td><?php echo $r['story_id'] !== '' && $r['story_id'] !== null ? '<span class="badge badge-info">' . $L('One story') . '</span> ' : ''; ?><?php echo $e($r['keywords']); ?></td>
                  <td><?php echo (int) $r['sent_count']; ?></td>
                  <td class="text-nowrap">
                    <a href="#" class="btn btn-sm <?php echo $r['is_active'] ? 'btn-outline-secondary' : 'btn-outline-success'; ?> igsr_toggle" title="<?php echo $r['is_active'] ? $L('Pause') : $L('Start'); ?>"><i class="fas fa-<?php echo $r['is_active'] ? 'pause' : 'play'; ?>"></i></a>
                    <a href="#" class="btn btn-sm btn-outline-warning igsr_edit"><i class="fas fa-edit"></i></a>
                    <a href="#" class="btn btn-sm btn-outline-danger igsr_delete"><i class="fas fa-trash"></i></a>
                  </td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div></div>
        </div>
      </div>
    </div>
  </div>
</section>
<script>
$(function(){
  var base = "<?php echo base_url('instagram_reply/'); ?>";
  var f = $('#igsr_form');
  function done(r){ if (r && r.ok == 1) location.reload(); else swal('<?php echo $L('Error'); ?>', (r && r.error) ? r.error : '', 'error'); }
  f.on('submit', function(e){ e.preventDefault(); $.post(base + 'story_rules_save', f.serialize(), done, 'json'); });
  $('#igsr_reset').on('click', function(){ f[0].reset(); f.find('[name=id]').val(0); $('#igsr_form_title').text('<?php echo $L('New rule'); ?>'); });
  $(document).on('click', '.igsr_edit', function(e){
    e.preventDefault(); var r = $(this).closest('tr').data('rule');
    ['id','name','keywords','reply_text','button_title','button_url'].forEach(function(k){ f.find('[name='+k+']').val(r[k] || ''); });
    var sel = f.find('[name=story_id]');
    if (r.story_id && !sel.find('option[value="'+r.story_id+'"]').length) sel.append($('<option>').val(r.story_id).text(r.story_id));
    sel.val(r.story_id || ''); $('#igsr_form_title').text('<?php echo $L('Edit rule'); ?>'); $('html,body').animate({scrollTop: f.offset().top - 120});
  });
  $(document).on('click', '.igsr_toggle', function(e){ e.preventDefault(); $.post(base + 'story_rules_toggle', {id: $(this).closest('tr').data('rule').id}, done, 'json'); });
  $(document).on('click', '.igsr_delete', function(e){
    e.preventDefault(); var id = $(this).closest('tr').data('rule').id;
    swal({title: '<?php echo $L('Are you sure?'); ?>', icon: 'warning', buttons: true, dangerMode: true}).then(function(ok){ if (ok) $.post(base + 'story_rules_delete', {id: id}, done, 'json'); });
  });
});
</script>
