<section class="section section_custom">
  <div class="section-header">
    <h1><i class="far fa-credit-card"></i> <?php echo $page_title; ?></h1>
    <div class="section-header-breadcrumb">
      <div class="breadcrumb-item"><a href="<?php echo base_url('integration'); ?>"><?php echo $this->lang->line("Integration"); ?></a></div>
      <div class="breadcrumb-item"><?php echo $page_title; ?></div>
    </div>
  </div>

  <?php $this->load->view('admin/theme/message'); ?>
  <?php 

    if(isset($xvalue['instruction_to_ai']) && !empty($xvalue['instruction_to_ai'])){
      $xvalue['instruction_to_ai'] = $xvalue['instruction_to_ai'];
    }
    else{
      $xvalue['instruction_to_ai'] = $this->lang->line('The following is a conversation with an AI assistant. The assistant is helpful, creative, clever, and very friendly.');
    }

    $sales_mode_enabled = isset($xvalue['sales_mode_enabled']) && $xvalue['sales_mode_enabled'] == '1' ? true : false;
    $max_history_messages = isset($xvalue['max_history_messages']) && $xvalue['max_history_messages'] != '' ? (int)$xvalue['max_history_messages'] : 6;
    $temperature = isset($xvalue['temperature']) && $xvalue['temperature'] != '' ? (float)$xvalue['temperature'] : 0.70;
    $memory_ttl_hours = isset($xvalue['memory_ttl_hours']) && $xvalue['memory_ttl_hours'] != '' ? (int)$xvalue['memory_ttl_hours'] : 24;

    if(isset($xvalue['sales_system_prompt']) && !empty($xvalue['sales_system_prompt'])){
      $sales_system_prompt = $xvalue['sales_system_prompt'];
    }
    else{
      $sales_system_prompt = "You are a professional sales assistant. Your goals:\n"
        . "1. Greet warmly and ask how you can help.\n"
        . "2. Qualify the customer: dates, number of guests, product preference.\n"
        . "3. Answer questions about products, services, and pricing.\n"
        . "4. Encourage a booking or purchase and offer to transfer to a human agent.\n"
        . "5. Keep replies concise, friendly, and under 3 sentences when possible.\n"
        . "6. Reply in the same language the customer uses.\n"
        . "Never make up prices or availability. If unsure, ask the customer to contact support.";
    }

    $text_completions =[
        'text-davinci-003',
        'text-davinci-002',
        'text-curie-001',
        'text-babbage-001',
        'text-ada-001',
        'davinci',
        'curie',
        'babbage',
        'ada'];

    $chat_completions = [
      'gpt-4o',
      'gpt-4o-mini',
      'gpt-4.1',
      'gpt-4.1-mini',
      'gpt-3.5-turbo'
    ];

    // SPEC-02: provider + Anthropic Claude
    $ai_provider = isset($xvalue['ai_provider']) && $xvalue['ai_provider'] === 'anthropic' ? 'anthropic' : 'openai';
    $anthropic_models = ['claude-sonnet-4-5', 'claude-haiku-4-5'];
    $anthropic_model = isset($xvalue['anthropic_model']) && $xvalue['anthropic_model'] != '' ? $xvalue['anthropic_model'] : 'claude-haiku-4-5';
    $this->load->helper('secret');
    $openai_hint = (isset($xvalue['open_ai_secret_key']) && $xvalue['open_ai_secret_key'] !== '') ? secret_mask(secret_decrypt($xvalue['open_ai_secret_key'])) : '';
    $anthropic_hint = (isset($xvalue['anthropic_secret_key']) && $xvalue['anthropic_secret_key'] !== '') ? secret_mask(secret_decrypt($xvalue['anthropic_secret_key'])) : '';

   ?>
  <div class="section-body">
    <div class="row">
      <div class="col-12">
        <form action="<?php echo base_url("integration/open_ai_api_credentials_action"); ?>" method="POST">
          <input type="hidden" name="csrf_token" id="csrf_token" value="<?php echo $this->session->userdata('csrf_token_session'); ?>">
          <div class="card">
            <div class="card-body">

                <div class="row">
                  <div class="col-12 col-md-12">
                    <div class="form-group">
                      <label for="ai_provider"><i class="fas fa-microchip"></i> <?php echo $this->lang->line("AI Provider") ?: 'AI Provider'; ?></label>
                      <select class="form-control" name="ai_provider" id="ai_provider" onchange="toggleAiProvider()">
                        <option value="openai" <?php echo $ai_provider=='openai'?'selected':''; ?>>OpenAI (GPT)</option>
                        <option value="anthropic" <?php echo $ai_provider=='anthropic'?'selected':''; ?>>Anthropic (Claude)</option>
                      </select>
                    </div>
                  </div>
                </div>

                <div class="row provider-openai">
                  <div class="col-12 col-md-12">
                    <div class="form-group">
                      <label for=""><i class="fas fa-key"></i>  <?php echo $this->lang->line("Open AI secret key");?></label>
                      <input name="open_ai_secret_key" value="" autocomplete="off" placeholder="<?php echo $openai_hint !== '' ? 'Saved ('.$openai_hint.') — leave blank to keep' : 'sk-...'; ?>" class="form-control" type="password">
                      <small class="form-text text-muted">Leave blank to keep the existing key.</small>
                      <span class="red"><?php echo form_error('open_ai_secret_key'); ?></span>
                    </div>
                  </div>
                </div>

                <div class="row provider-anthropic">
                  <div class="col-12 col-md-12">
                    <div class="form-group">
                      <label for=""><i class="fas fa-key"></i> Anthropic API key</label>
                      <input name="anthropic_secret_key" value="" autocomplete="off" placeholder="<?php echo $anthropic_hint !== '' ? 'Saved ('.$anthropic_hint.') — leave blank to keep' : 'sk-ant-...'; ?>" class="form-control" type="password">
                      <small class="form-text text-muted">Leave blank to keep the existing key.</small>
                      <span class="red"><?php echo form_error('anthropic_secret_key'); ?></span>
                    </div>
                  </div>
                  <div class="col-12 col-md-12">
                    <div class="form-group">
                      <label for="anthropic_model"><i class="fas fa-paper-plane"></i> Claude Model</label>
                      <select class="form-control" name="anthropic_model">
                        <?php foreach ($anthropic_models as $am): ?>
                          <option value="<?php echo $am ?>" <?php echo $am==$anthropic_model?'selected':''; ?>><?php echo $am ?></option>
                        <?php endforeach ?>
                      </select>
                    </div>
                  </div>
                </div>
                <div class="row">
                  <div class="col-12 col-md-12">
                    <div class="form-group">
                      <label for=""><i class="fas fa-quote-right"></i>  <?php echo $this->lang->line("Instruction To AI ");?></label>
                      <textarea class="form-control" name="instruction_to_ai"><?php echo $xvalue['instruction_to_ai'] ?></textarea>
                      <span class="red"><?php echo form_error('instruction_to_ai'); ?></span>
                    </div>
                  </div>
                </div>  
                <div class="row provider-openai">
                    <div class="col-12 col-md-12">
                      <div class="form-group">
                        <label for="models"><i class="fas fa-paper-plane"></i>  <?php echo $this->lang->line("Models");?></label>
                        <select class="select2 w-100" name="models">
                          <option value=""><?php echo $this->lang->line("Select Models"); ?></option>
                          <optgroup label="Chat Completions">
                          <?php foreach ($chat_completions as  $value): ?>
                            <option value="<?php echo $value ?>" <?php if(isset($xvalue['models']) && $value == $xvalue['models']) echo 'selected'; ?> ><?php echo $value?></option>
                          <?php endforeach ?>
                          </optgroup>
                        </select>
                        <span class="red"><?php echo form_error('models'); ?></span>
                     </div>
                    </div>
                </div>
               <div class="row">
                  <div class="col-12 col-md-12">
                    <div class="form-group">
                      <label for=""><i class="fas fa-solid fa-route"></i>  <?php echo $this->lang->line("Maximum Token");?></label>
                      <input name="maximum_token" value="<?php echo isset($xvalue['maximum_token']) ? $xvalue['maximum_token'] :"1500"; ?>" class="form-control" type="text">  
                      <span class="red"><?php echo form_error('maximum_token'); ?></span>
                    </div>
                  </div>
              </div>

              <hr>
              <h5 class="mb-3"><i class="fas fa-robot"></i> Sales Bot Mode</h5>

              <div class="row">
                <div class="col-12 col-md-12">
                  <div class="form-group">
                    <label class="custom-switch mt-2">
                      <input type="checkbox" name="sales_mode_enabled" value="1" class="custom-switch-input" <?php echo $sales_mode_enabled ? 'checked' : ''; ?>>
                      <span class="custom-switch-indicator"></span>
                      <span class="custom-switch-description">Enable Sales Bot Mode</span>
                    </label>
                    <small class="form-text text-muted">When enabled, the AI will use the sales prompt below and remember the conversation history for each customer.</small>
                  </div>
                </div>
              </div>

              <div class="row">
                <div class="col-12 col-md-12">
                  <div class="form-group">
                    <label for=""><i class="fas fa-bullseye"></i> Sales System Prompt</label>
                    <textarea class="form-control" name="sales_system_prompt" rows="8"><?php echo $sales_system_prompt; ?></textarea>
                    <span class="red"><?php echo form_error('sales_system_prompt'); ?></span>
                  </div>
                </div>
              </div>

              <div class="row">
                <div class="col-12 col-md-4">
                  <div class="form-group">
                    <label for=""><i class="fas fa-history"></i> Max History Messages</label>
                    <input name="max_history_messages" value="<?php echo $max_history_messages; ?>" class="form-control" type="number" min="1" max="20">
                    <small class="form-text text-muted">How many previous messages to send as context (1-20).</small>
                  </div>
                </div>
                <div class="col-12 col-md-4">
                  <div class="form-group">
                    <label for=""><i class="fas fa-thermometer-half"></i> Temperature</label>
                    <input name="temperature" value="<?php echo $temperature; ?>" class="form-control" type="number" step="0.1" min="0" max="2">
                    <small class="form-text text-muted">0 = deterministic, 1 = creative.</small>
                  </div>
                </div>
                <div class="col-12 col-md-4">
                  <div class="form-group">
                    <label for=""><i class="fas fa-clock"></i> Memory TTL (hours)</label>
                    <input name="memory_ttl_hours" value="<?php echo $memory_ttl_hours; ?>" class="form-control" type="number" min="1">
                    <small class="form-text text-muted">Delete conversation history older than this.</small>
                  </div>
                </div>
              </div>

            <div class="card-footer bg-whitesmoke">
              <button class="btn btn-primary btn-lg" id="save-btn" type="submit"><i class="fas fa-save"></i> <?php echo $this->lang->line("Save");?></button>
            </div>
          </div>
        </form>
      </div>
    </div>
  </div>
</section>
<script>
function toggleAiProvider(){
  var p = document.getElementById('ai_provider').value;
  document.querySelectorAll('.provider-openai').forEach(function(el){ el.style.display = (p==='openai')?'':'none'; });
  document.querySelectorAll('.provider-anthropic').forEach(function(el){ el.style.display = (p==='anthropic')?'':'none'; });
}
document.addEventListener('DOMContentLoaded', toggleAiProvider);
</script>
