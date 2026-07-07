<div class="modal fade" role="dialog" id="campaign_modal" data-backdrop="static" data-keyboard="false">
  <div class="modal-dialog modal-lg" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fas fa-robot"></i> <?php echo $this->lang->line('Auto-Reply Campaign'); ?></h5>
        <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
      </div>
      <form id="campaign_form">
        <div class="modal-body">
          <input type="hidden" name="csrf_token" value="<?php echo $this->session->userdata('csrf_token_session'); ?>">
          <input type="hidden" name="id" id="campaign_id" value="0">
          <div class="row">
            <div class="col-md-6">
              <div class="form-group">
                <label><?php echo $this->lang->line('TikTok Account'); ?> <span class="text-danger">*</span></label>
                <select name="account_id" id="account_id" class="form-control" required>
                  <option value=""><?php echo $this->lang->line('Select Account'); ?></option>
                  <?php foreach($accounts as $acc) { ?>
                    <option value="<?php echo $acc['id']; ?>"><?php echo htmlspecialchars($acc['display_name']); ?></option>
                  <?php } ?>
                </select>
              </div>
            </div>
            <div class="col-md-3">
              <div class="form-group">
                <label><?php echo $this->lang->line('Campaign Type'); ?></label>
                <select name="campaign_type" id="campaign_type" class="form-control">
                  <option value="comment"><?php echo $this->lang->line('Comment Reply'); ?></option>
                  <option value="dm"><?php echo $this->lang->line('DM Reply'); ?></option>
                </select>
              </div>
            </div>
            <div class="col-md-3">
              <div class="form-group">
                <label><?php echo $this->lang->line('Reply Type'); ?></label>
                <select name="reply_type" id="reply_type" class="form-control">
                  <option value="text"><?php echo $this->lang->line('Fixed Text'); ?></option>
                  <option value="ai"><?php echo $this->lang->line('AI Reply'); ?></option>
                </select>
              </div>
            </div>
          </div>

          <div class="row">
            <div class="col-md-4">
              <div class="form-group">
                <label><?php echo $this->lang->line('Status'); ?></label>
                <select name="status" id="campaign_status" class="form-control">
                  <option value="active"><?php echo $this->lang->line('Active'); ?></option>
                  <option value="inactive"><?php echo $this->lang->line('Inactive'); ?></option>
                </select>
              </div>
            </div>
          </div>

          <div id="text_field_group">
            <div class="form-group">
              <label><?php echo $this->lang->line('Auto Reply Text'); ?></label>
              <textarea name="auto_reply_text" id="auto_reply_text" class="form-control" rows="3"></textarea>
            </div>
          </div>

          <div id="ai_field_group" class="d-none">
            <div class="form-group">
              <label><?php echo $this->lang->line('AI Training Instructions'); ?></label>
              <textarea name="ai_training_data" id="ai_training_data" class="form-control" rows="4" placeholder="<?php echo $this->lang->line('e.g. You are a friendly sales assistant for Creative Monkey...'); ?>"></textarea>
              <small class="form-text text-muted"><?php echo $this->lang->line('The AI will use these instructions plus the account knowledge base to reply.'); ?></small>
            </div>
          </div>
        </div>
        <div class="modal-footer bg-whitesmoke">
          <button type="button" class="btn btn-secondary" data-dismiss="modal"><i class="fas fa-times"></i> <?php echo $this->lang->line('Close'); ?></button>
          <button type="submit" id="save_campaign_btn" class="btn btn-primary"><i class="fas fa-save"></i> <?php echo $this->lang->line('Save'); ?></button>
        </div>
      </form>
    </div>
  </div>
</div>
