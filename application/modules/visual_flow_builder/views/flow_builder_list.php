<?php $this->load->view('admin/theme/message'); ?>

<style type="text/css">
    .button{
        margin-top: 10px;
    }
    .datagrid-body
    {
      overflow: hidden !important; 
    }

    .emojionearea, .emojionearea.form-control
    {
        height: 150px !important;
    }


</style>

<div id="dynamic_field_modal" class="modal fade" role="dialog" data-backdrop="static" data-keyboard="false">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post" id="page_name">
                <div class="modal-body" style="padding-bottom:0">
                    <div class="row">
                          <div class="col-12"> 
                            <?php if(addon_exist($module_id=320,$addon_unique_name="instagram_bot")) : ?>
                            <div class="form-group">
                              <label><?php echo $this->lang->line("Please select a page"); ?></label>
                                <select class="form-control select2" id="page_table_id" name="page_table_id" style="width:100%;">
                                  <?php 
                                    echo "<option value=''>".$this->lang->line('Choose a Page')."</option>";
                                    foreach ($group_page_list as $key => $value) 
                                    {
                                      echo '<optgroup label="'.$value['media_name'].'">';
                                      foreach ($value['page_list'] as $key2 => $value2) 
                                      {
                                        echo "<option value='".$key2."' >".$value2."</option>";
                                      }
                                      echo '</optgroup>';
                                    } 
                                  ?>
                                </select>
                            </div>
                            <!-- <div class="form-group">
                              <label class="d-block"><?php echo $this->lang->line('Media'); ?></label>
                              <div class="row">
                                <div class="col-12 col-md-6">
                                  <div class="custom-control custom-radio custom-control-inline">
                                    <input type="radio" id="media_type_fb" name="media_type" value="fb" class="custom-control-input">
                                    <label class="custom-control-label" for="media_type_fb"><?php echo $this->lang->line('Facebook'); ?></label>
                                  </div>
                                </div>
                                <div class="col-12 col-md-6">
                                  <div class="custom-control custom-radio custom-control-inline">
                                    <input type="radio" id="media_type_ig" name="media_type" value="ig" class="custom-control-input">
                                    <label class="custom-control-label" for="media_type_ig"><?php echo $this->lang->line('Instagram'); ?></label>
                                  </div>
                                </div>
                              </div>
                            </div> -->
                            <?php else : ?>
                              <div class="form-group">
                                <label><?php echo $this->lang->line("Please select a page"); ?></label>
                                <?php 
                                  $page_list[''] = $this->lang->line("Choose a Page");
                                  echo form_dropdown('page_table_id',$page_list,'','id="page_table_id" class="form-control select2" style="width:100%;"'); 
                                ?>
                              </div>       
                            <?php endif; ?>
                          </div>
                    </div>
                </div>
                <div class="modal-footer" style="margin-top: 10px;">
                    <button class="btn btn-lg btn-secondary" data-dismiss="modal"><i class="fas fa-times"></i> <?php echo $this->lang->line("Cancel"); ?></button>
                    <button id="submit" class="btn btn-primary btn-lg"><i class="fas fa-check"></i> <?php echo $this->lang->line('Ok'); ?></button>
                    
                </div>
            </form>
        </div>
    </div>

</div>

<!-- Prompt-to-Flow (brief §24/§46): a plain-language draft generator. It only ever creates a
     DRAFT row in visual_flow_builder_campaign — nothing goes live until the owner opens it in
     this same editor and clicks Save there, exactly like a hand-drawn flow. -->
<div id="prompt_to_flow_modal" class="modal fade" role="dialog" data-backdrop="static" data-keyboard="false">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title"><?php echo $this->lang->line("Generate from a description"); ?></h3>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label><?php echo $this->lang->line("Please select a page"); ?></label>
                    <?php if(addon_exist($module_id=320,$addon_unique_name="instagram_bot")) : ?>
                        <select class="form-control select2" id="prompt_to_flow_page_table_id" style="width:100%;">
                            <option value=''><?php echo $this->lang->line('Choose a Page'); ?></option>
                            <?php foreach ($group_page_list as $key => $value): ?>
                                <optgroup label="<?php echo $value['media_name']; ?>">
                                    <?php foreach ($value['page_list'] as $key2 => $value2): ?>
                                        <option value='<?php echo $key2; ?>'><?php echo $value2; ?></option>
                                    <?php endforeach; ?>
                                </optgroup>
                            <?php endforeach; ?>
                        </select>
                    <?php else: ?>
                        <?php $pt_page_list = $page_list; $pt_page_list[''] = $this->lang->line("Choose a Page"); ?>
                        <?php echo form_dropdown('prompt_to_flow_page_table_id_plain', $pt_page_list, '', 'id="prompt_to_flow_page_table_id" class="form-control select2" style="width:100%;"'); ?>
                    <?php endif; ?>
                </div>
                <div class="form-group">
                    <label><?php echo $this->lang->line("Describe what you want the bot to do"); ?></label>
                    <textarea id="prompt_to_flow_instruction" class="form-control" rows="4" maxlength="4000"
                        placeholder="<?php echo $this->lang->line('e.g. When someone asks about hotel booking, ask for arrival date, departure date and guest count, then ask for confirmation and save the lead.'); ?>"></textarea>
                    <small class="form-text text-muted"><?php echo $this->lang->line("We'll create a draft flow — nothing goes live until you review and save it."); ?></small>
                </div>
                <div id="prompt_to_flow_error" class="alert alert-danger" style="display:none;"></div>
                <div id="prompt_to_flow_substitutions" class="alert alert-warning" style="display:none;"></div>
                <div id="prompt_to_flow_success" class="alert alert-success" style="display:none;"></div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" data-dismiss="modal"><i class="fas fa-times"></i> <?php echo $this->lang->line("Cancel"); ?></button>
                <button id="prompt_to_flow_submit" class="btn btn-primary"><i class="fas fa-magic"></i> <?php echo $this->lang->line("Generate"); ?></button>
            </div>
        </div>
    </div>
</div>

<div class="alert alert-secondary alert-dismissible show fade">
    <div class="alert-body">
        <button class="close" data-dismiss="alert">
            <span>×</span>
        </button>
        <?php echo $this->lang->line('Action button like Get Started, No Match etc are available in Action Button Settings Tab.'); ?>
    </div>
</div>

<div class="table-responsive data-card">
  <table class="table table-bordered table-sm table-striped" id="mytable">
    <thead>
      <tr>
        <th>#</th>      
        <th><?php echo $this->lang->line("Template ID"); ?></th>      
        <th><?php echo $this->lang->line("Reference Name"); ?></th>
        <th><?php echo $this->lang->line("Page Name"); ?></th>
        <th><?php echo $this->lang->line("Media Type"); ?></th>
        <th style="min-width: 300px"><?php echo $this->lang->line("Actions"); ?></th>
      </tr>
    </thead>
    <tbody>
    </tbody>
  </table>
</div>  

<div class="modal fade" data-backdrop="static" data-keyboard="false" id="BotcopyModal">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h3 class="modal-title"><?php echo $this->lang->line('Page Selections'); ?></h3>
        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>
      <div class="modal-body">
      <input type="hidden" id="pageselcetion-update-id" value="0">
      <input type="hidden" id="pageselcetion-update-mediatype" value="fb">

          <div class="form-group">
            <label><?php echo $this->lang->line("Please select a page"); ?></label>
            <?php 
            $page_list[''] = $this->lang->line("Choose a Page");
            echo form_dropdown('page_list_option',$page_list,'','id="page_list_option" class="form-control select2" style="width:100%;"'); 
          ?>
          </div>  
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-dismiss="modal"><?php echo $this->lang->line('Close'); ?></button>
        <button type="button" class="btn btn-primary" id="page-change-save"><?php echo $this->lang->line('Save changes'); ?></button>
      </div>
    </div>
  </div>
</div>



<?php 
$areyousure=$this->lang->line("are you sure");
$builder_load_url = base_url("visual_flow_builder/load_builder/{$page_auto_id}/1/{$media_type}");
$drop_menu = '<a href="'.$builder_load_url.'" class="float-right btn btn-primary" type="button" target="_BLANK"><i class="fas fa-plus-circle"></i> '.$this->lang->line("Create New Flow").'</a>';
$drop_menu .= ' <a href="#" id="prompt_to_flow_open" class="float-right btn btn-outline-primary" type="button"><i class="fas fa-magic"></i> '.$this->lang->line("Generate from a description").'</a>';
?>

<script>
$(document).ready(function(){

    var PROMPT_TO_FLOW_CSRF = "<?php echo $this->session->userdata('csrf_token_session'); ?>";

    var drop_menu = '<?php echo $drop_menu;?>';
    setTimeout(function(){
      $("#mytable_filter").append(drop_menu);
    }, 1000);

    $(document).on('click', '#prompt_to_flow_open', function(e){
        e.preventDefault();
        $('#prompt_to_flow_error, #prompt_to_flow_substitutions, #prompt_to_flow_success').hide().empty();
        $('#prompt_to_flow_instruction').val('');
        $('#prompt_to_flow_submit').prop('disabled', false).html('<i class="fas fa-magic"></i> <?php echo $this->lang->line("Generate"); ?>');
        $('#prompt_to_flow_modal').modal('show');
    });

    $(document).on('click', '#prompt_to_flow_submit', function(e){
        e.preventDefault();
        var instruction = $.trim($('#prompt_to_flow_instruction').val());
        var page_id_media = $('#prompt_to_flow_page_table_id').val();
        $('#prompt_to_flow_error, #prompt_to_flow_substitutions, #prompt_to_flow_success').hide().empty();

        if (!instruction) {
            $('#prompt_to_flow_error').text('<?php echo $this->lang->line("Please describe what you want the bot to do."); ?>').show();
            return;
        }
        if (!page_id_media) {
            $('#prompt_to_flow_error').text('<?php echo $this->lang->line("You have to select a page"); ?>').show();
            return;
        }

        var page_id_media_array = page_id_media.split('-');
        var page_table_id = page_id_media_array[0];
        var instagram_bot_addon = (page_id_media_array[1] === 'ig') ? 1 : 0;

        var $btn = $(this);
        $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> <?php echo $this->lang->line("Generating..."); ?>');

        $.ajax({
            url: base_url + 'flow_prompt/generate',
            type: 'POST',
            dataType: 'json',
            data: {
                csrf_token: PROMPT_TO_FLOW_CSRF,
                instruction: instruction,
                page_table_id: page_table_id,
                instagram_bot_addon: instagram_bot_addon
            },
            success: function(response){
                $btn.prop('disabled', false).html('<i class="fas fa-magic"></i> <?php echo $this->lang->line("Generate"); ?>');
                if (response.status == 1 || response.status == '1') {
                    if (response.substitutions && response.substitutions.length) {
                        var msg = '<?php echo $this->lang->line("A few things were substituted for safety — please review:"); ?><ul>';
                        $.each(response.substitutions, function(i, s){ msg += '<li>' + s + '</li>'; });
                        msg += '</ul>';
                        $('#prompt_to_flow_substitutions').html(msg).show();
                    }
                    $('#prompt_to_flow_success').text(response.message || '<?php echo $this->lang->line("Draft ready — nothing is live yet."); ?>').show();
                    if (response.edit_url) {
                        window.open(base_url + response.edit_url, '_blank');
                    }
                } else {
                    $('#prompt_to_flow_error').text(response.message || '<?php echo $this->lang->line("Something went wrong."); ?>').show();
                }
            },
            error: function(){
                $btn.prop('disabled', false).html('<i class="fas fa-magic"></i> <?php echo $this->lang->line("Generate"); ?>');
                $('#prompt_to_flow_error').text('<?php echo $this->lang->line("Something went wrong."); ?>').show();
            }
        });
    });

    var base_url="<?php echo base_url(); ?>";
    var page_auto_id = "<?php echo $page_auto_id; ?>";
    if(page_auto_id != 0)
      var data_url = base_url+'visual_flow_builder/visual_flow_builder_data/'+page_auto_id+'?media_type='+selected_global_media_type;
    else
      var data_url = base_url+'visual_flow_builder/visual_flow_builder_data'+'?media_type='+selected_global_media_type;;

    // datatable section started
    var table = $("#mytable").DataTable({
        serverSide: true,
        processing:true,
        bFilter: true,
        order: [[ 1, "desc" ]],
        pageLength: 10,
        ajax: 
        {
            "url": data_url,
            "type": 'POST',
            "dataSrc": function ( json ) 
            {
              $(".table-responsive").niceScroll();
              return json.data;
            } 
        },
        language: 
        {
          url: "<?php echo base_url('assets/modules/datatables/language/'.$this->language.'.json'); ?>"
        },
        dom: '<"top"f>rt<"bottom"lip><"clear">',
        columnDefs: [
            {
              targets: [1,3,4],
              visible: false
            },
            {
              targets: [0,5],
              className: 'text-center',
              sortable: false
            },
            {
                targets: [4],
                "render": function ( data, type, row, meta ) 
                {
                    var media_type = row[4];
                    var str = '';
                    if(media_type == 'ig')
                        str = 'Instagram';
                    else
                        str = 'Facebook';
                    return str;
                }
            },
            {
                targets: [5],
                "render": function ( data, type, row, meta ) 
                {
                    var id = row[1];
                    var media_type = row[4];
                    var copy_str="<?php echo $this->lang->line('Copy to other Page');?>";
                    var duplicate_str="<?php echo $this->lang->line('Duplicate');?>";
                    var export_str="<?php echo $this->lang->line('Export flow data');?>";
                    var edit_str="<?php echo $this->lang->line('Edit');?>";
                    var delete_str="<?php echo $this->lang->line('Delete');?>";
                    var str="";   
                    var duplicate_url = base_url + "visual_flow_builder/duplicate_builder_data/" + id + "/1/" + media_type;
                    var export_url = base_url + "visual_flow_builder/export_builder_data/" + id + "/1/" + media_type;
                    var edit_url = base_url + "visual_flow_builder/edit_builder_data/" + id + "/1/" + media_type;
                    str="&nbsp;<a target='_blank' class='text-center btn btn-circle btn-outline-primary' href='"+duplicate_url+"' title='"+duplicate_str+"'>"+'<i class="fa fa-clone"></i>'+"</a>";
                    str=str+"&nbsp;<a target='_blank' class='text-center btn btn-circle btn-outline-info botcopy' href='#' title='"+copy_str+"' value='"+id+"' media_type='"+media_type+"'>"+'<i class="fa fa-copy"></i>'+"</a>";
                    str=str+"&nbsp;<a target='_blank' class='text-center btn btn-circle btn-outline-success' href='"+export_url+"' title='"+export_str+"'>"+'<i class="fas fa-file-export"></i>'+"</a>";
                    str=str+"&nbsp;<a target='_blank' class='text-center btn btn-circle btn-outline-warning' href='"+edit_url+"' title='"+edit_str+"'>"+'<i class="fa fa-edit"></i>'+"</a>";
                    
                    str=str+"&nbsp;<a name='delete' href='#' class='text-center delete_data btn btn-circle btn-outline-danger ' title='"+delete_str+"' table_id="+id+" '>"+'<i class="fa fa-trash"></i>'+"</a>";
                  
                    return str;
                }
            }
        ]
    });
    // End of datatable section

    $('#add').click(function(e){
        e.preventDefault();
        $('#dynamic_field_modal').modal('show');
    });

    $(document).on('click','.botcopy',function(e){
        e.preventDefault();
        var id = $(this).attr('value');
        var media_type = $(this).attr('media_type');
        var modal_id = '#BotcopyModal';
        $('#pageselcetion-update-id').val(id);
        $('#pageselcetion-update-mediatype').val(media_type);
        $(modal_id).modal('show');
    });

    $('#submit').click(function(e) {
       e.preventDefault();
       var page_id_media = $('#page_table_id').val();
       var page_id_media_array = page_id_media.split("-");

       var page_table_id = page_id_media_array[0];
       var media_type = 'fb';
       if (typeof page_id_media_array[1] !== 'undefined') {
         media_type = page_id_media_array[1];
       }

       if(page_table_id == '')
       {
          swal('<?php echo $this->lang->line("Warning"); ?>', '<?php echo $this->lang->line("You have to select a page"); ?>', 'warning');
          return false;
       }
       else
       {
          var link = base_url + "visual_flow_builder/load_builder/" + page_table_id + "/1/" + media_type;
          window.location.replace(link);
       }

    });

    $(document).on('click', '.edit_reply_info', function(event) {
      event.preventDefault();
      var table_id = $(this).attr('table_id');
      var media_type = $(this).attr('media_type');
      var link = base_url + "visual_flow_builder/edit_builder_data/" + table_id + "/1/" + media_type;
      window.location.replace(link);
    });

    $(document).on('click','#page-change-save',function(e){
        e.preventDefault();
        var id =$('#pageselcetion-update-id').val();
        var media_type =$('#pageselcetion-update-mediatype').val();
        var selectElement = document.getElementById('page_list_option');
        var page_id = selectElement.value;
        var modal_id = '#BotcopyModal';

        $.ajax({
            url: base_url+'visual_flow_builder/copy_builder_data',
            type: "POST",
            data: {id,page_id,media_type},
            dataType: 'json',
            success:function(response)
            {
                $(modal_id).modal('hide');

                if(response.status == "1") {
                    var id = response.id;
                    var media_type = response.media_type;

                    var final_url = base_url + 'visual_flow_builder/edit_builder_data/' + id + '/1/' + media_type;
                    window.open(final_url, '_blank');
                }
                else{
                    swal('<?php echo $this->lang->line("Error"); ?>', response.message, 'error');
                }
            },

        });

    });

    $(document).on('click', '.delete_data', function(event) {
        event.preventDefault();
        swal({
            title: '<?php echo $this->lang->line("Warning"); ?>',
            text: '<?php echo $this->lang->line("Are you sure you want to delete this campaign"); ?>',
            icon: 'warning',
            buttons: true,
            dangerMode: true,
        })
        .then((willreset) => {
            if (willreset) 
            {
                $(this).addClass('btn-progress');
                var table_id = $(this).attr('table_id');

                $.ajax({
                    context: this,
                    type:'POST',
                    url: base_url + "visual_flow_builder/delete_flowbuilder_data",
                    dataType: 'json',
                    data: {table_id},
                    success:function(response){ 
                    $(this).removeClass('btn-progress');
                    if(response.status == 1)
                    {
                        
                        swal('<?php echo $this->lang->line("Success"); ?>', response.message, 'success').then((value) => {
                          table.draw();
                      });
                    }
                    else
                    {
                        swal('<?php echo $this->lang->line("Error"); ?>', response.message, 'error');
                    }
                },
                error:function(response){
                    var span = document.createElement("span");
                    span.innerHTML = response.responseText;
                    swal({ title:'<?php echo $this->lang->line("Error!"); ?>', content:span,icon:'error'});
                }
            });
            } 
        });
    });
     

});
</script>