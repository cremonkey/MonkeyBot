<div class="modal fade" role="dialog" id="add_source_modal" data-backdrop="static" data-keyboard="false">
  <div class="modal-dialog modal-lg" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fas fa-plus-circle"></i> <?php echo $this->lang->line('Add Knowledge Source'); ?></h5>
        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>
      <form id="add_source_form" enctype="multipart/form-data">
        <div class="modal-body">
          <div class="row">
            <div class="col-12">
              <div class="form-group">
                <label for="source_type"><?php echo $this->lang->line('Source Type'); ?></label>
                <select name="source_type" id="source_type" class="form-control">
                  <option value="url"><?php echo $this->lang->line('Website URL'); ?></option>
                  <option value="pdf"><?php echo $this->lang->line('PDF File'); ?></option>
                  <option value="md"><?php echo $this->lang->line('Markdown / Text File (.md, .txt)'); ?></option>
                  <option value="text"><?php echo $this->lang->line('Written Prompt / Plain Text'); ?></option>
                </select>
              </div>
            </div>

            <?php if(!empty($page_info)) { ?>
            <div class="col-12">
              <div class="form-group">
                <label for="page_id"><?php echo $this->lang->line('Scope'); ?></label>
                <select name="page_id" id="page_id" class="form-control">
                  <option value="0"><?php echo $this->lang->line('All Pages (account-wide)'); ?></option>
                  <?php foreach($page_info as $page) { ?>
                    <option value="<?php echo $page['id']; ?>"><?php echo htmlspecialchars($page['page_name']); ?></option>
                  <?php } ?>
                </select>
                <small class="form-text text-muted"><?php echo $this->lang->line('Page-scoped sources are searched first when the bot replies on that page.'); ?></small>
              </div>
            </div>
            <?php } ?>

            <div class="col-12">
              <div class="form-group">
                <label for="source_name"><?php echo $this->lang->line('Source Name'); ?> <span class="text-danger">*</span></label>
                <input type="text" name="source_name" id="source_name" class="form-control" placeholder="<?php echo $this->lang->line('e.g. Pricing page or Product manual'); ?>" required>
              </div>
            </div>

            <div class="col-12" id="url_input_group">
              <div class="form-group">
                <label for="source_url"><?php echo $this->lang->line('Website URL'); ?> <span class="text-danger">*</span></label>
                <input type="url" name="source_url" id="source_url" class="form-control" placeholder="https://example.com/page">
              </div>
            </div>

            <div class="col-12 d-none" id="pdf_input_group">
              <div class="form-group">
                <label for="source_file"><?php echo $this->lang->line('PDF File'); ?> <span class="text-danger">*</span></label>
                <input type="file" name="source_file" id="source_file" class="form-control" accept="application/pdf">
                <small class="form-text text-muted"><?php echo $this->lang->line('Maximum file size: 10 MB.'); ?></small>
              </div>
            </div>

            <div class="col-12 d-none" id="md_input_group">
              <div class="form-group">
                <label for="source_file_md"><?php echo $this->lang->line('Markdown / Text File'); ?> <span class="text-danger">*</span></label>
                <input type="file" name="source_file_md" id="source_file_md" class="form-control" accept=".md,.markdown,.txt,text/markdown,text/plain">
                <small class="form-text text-muted"><?php echo $this->lang->line('Accepted: .md, .markdown, .txt — maximum file size: 5 MB.'); ?></small>
              </div>
            </div>

            <div class="col-12 d-none" id="text_input_group">
              <div class="form-group">
                <label for="source_text"><?php echo $this->lang->line('Knowledge Text / Prompt'); ?> <span class="text-danger">*</span></label>
                <textarea name="source_text" id="source_text" class="form-control" rows="10" placeholder="<?php echo $this->lang->line('Write or paste the knowledge your bot should use: products, prices, policies, FAQs...'); ?>"></textarea>
                <small class="form-text text-muted"><?php echo $this->lang->line('Minimum 20 characters. The text will be chunked and searched automatically.'); ?></small>
              </div>
            </div>
          </div>
        </div>
        <div class="modal-footer bg-whitesmoke">
          <button type="button" class="btn btn-secondary" data-dismiss="modal"><i class="fas fa-times"></i> <?php echo $this->lang->line('Close'); ?></button>
          <button type="submit" id="save_source_btn" class="btn btn-primary"><i class="fas fa-save"></i> <?php echo $this->lang->line('Save'); ?></button>
        </div>
      </form>
    </div>
  </div>
</div>
