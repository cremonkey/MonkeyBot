<?php $this->load->view('admin/theme/message'); ?>

<section class="section section_custom">
  <div class="section-header">
    <h1><i class="fab fa-tiktok"></i> <?php echo $page_title; ?></h1>
    <div class="section-header-button">
      <a class="btn btn-primary" href="javascript:void(0)" data-toggle="modal" data-target="#campaign_modal">
        <i class="fas fa-plus-circle"></i> <?php echo $this->lang->line("Add Campaign"); ?>
      </a>
    </div>
    <div class="section-header-breadcrumb">
      <div class="breadcrumb-item"><a href="<?php echo base_url('tiktok_bot/accounts'); ?>"><?php echo $this->lang->line("TikTok Accounts"); ?></a></div>
      <div class="breadcrumb-item"><?php echo $page_title; ?></div>
    </div>
  </div>

  <input type="hidden" name="csrf_token" id="csrf_token" value="<?php echo $this->session->userdata('csrf_token_session'); ?>">

  <div class="section-body">
    <div class="row">
      <div class="col-12">
        <div class="card">
          <div class="card-body data-card">
            <div class="table-responsive2">
              <table class="table table-bordered" id="campaigns_table">
                <thead>
                  <tr>
                    <th>#</th>
                    <th><?php echo $this->lang->line("ID"); ?></th>
                    <th><?php echo $this->lang->line("Account"); ?></th>
                    <th><?php echo $this->lang->line("Type"); ?></th>
                    <th><?php echo $this->lang->line("Reply Type"); ?></th>
                    <th><?php echo $this->lang->line("Status"); ?></th>
                    <th><?php echo $this->lang->line("Created"); ?></th>
                    <th><?php echo $this->lang->line("Actions"); ?></th>
                  </tr>
                </thead>
                <tbody></tbody>
              </table>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<?php $this->load->view('tiktok_bot/campaign_modal'); ?>

<script>
$(document).ready(function($) {
  var base_url = '<?php echo base_url(); ?>';
  var csrf_token = $('#csrf_token').val();

  var table = $("#campaigns_table").DataTable({
      serverSide: true,
      processing: true,
      bFilter: false,
      order: [[ 1, "desc" ]],
      pageLength: 10,
      ajax: {
        "url": base_url + 'tiktok_bot/campaign_data',
        "type": 'POST',
        "data": function (d) {
            d.csrf_token = csrf_token;
        }
      },
      language: {
        url: "<?php echo base_url('assets/modules/datatables/language/'.$this->language.'.json'); ?>"
      },
      dom: '<"top"f>rt<"bottom"lip><"clear">',
      columns: [
          { data: null, render: function(data, type, row, meta) { return meta.row + meta.settings._iDisplayStart + 1; } },
          { data: 'id' },
          { data: 'account_name', render: function(data) { return data ? data : '<span class="text-muted">-</span>'; } },
          { data: 'campaign_type' },
          { data: 'reply_type' },
          { data: 'status' },
          { data: 'created_at' },
          { data: null, render: function(data, type, row) {
              return '<button class="btn btn-sm btn-primary edit_campaign mr-1" data-id="' + row.id + '" data-account="' + row.account_id + '" data-type="' + row.campaign_type + '" data-reply="' + row.reply_type + '" data-text="' + btoa(unescape(encodeURIComponent(row.auto_reply_text || ''))) + '" data-ai="' + btoa(unescape(encodeURIComponent(row.ai_training_data || ''))) + '" data-status="' + row.status + '"><i class="fas fa-edit"></i></button>' +
                     '<button class="btn btn-sm btn-danger delete_campaign" data-id="' + row.id + '"><i class="fas fa-trash"></i></button>';
          }}
      ],
      columnDefs: [
          { targets: [1], visible: false },
          { targets: [0, 3, 4, 5, 7], className: "text-center" }
      ]
  });

  $(document).on('click', '.edit_campaign', function() {
    var btn = $(this);
    $('#campaign_id').val(btn.data('id'));
    $('#account_id').val(btn.data('account'));
    $('#campaign_type').val(btn.data('type'));
    $('#reply_type').val(btn.data('reply'));
    $('#auto_reply_text').val(decodeURIComponent(escape(atob(btn.data('text')))));
    $('#ai_training_data').val(decodeURIComponent(escape(atob(btn.data('ai')))));
    $('#campaign_status').val(btn.data('status'));
    toggleReplyFields();
    $('#campaign_modal').modal('show');
  });

  $(document).on('click', '#add_campaign_btn', function() {
    $('#campaign_form')[0].reset();
    $('#campaign_id').val('0');
    toggleReplyFields();
    $('#campaign_modal').modal('show');
  });

  $(document).on('change', '#reply_type', toggleReplyFields);

  function toggleReplyFields() {
    if ($('#reply_type').val() === 'ai') {
      $('#ai_field_group').removeClass('d-none');
      $('#text_field_group').addClass('d-none');
    } else {
      $('#ai_field_group').addClass('d-none');
      $('#text_field_group').removeClass('d-none');
    }
  }

  $(document).on('submit', '#campaign_form', function(e) {
    e.preventDefault();
    var formData = $(this).serialize();
    formData += '&csrf_token=' + encodeURIComponent(csrf_token);
    $.ajax({
      url: base_url + 'tiktok_bot/save_campaign_action',
      type: 'POST',
      data: formData,
      dataType: 'json',
      beforeSend: function() { $('#save_campaign_btn').addClass('btn-progress'); },
      success: function(response) {
        $('#save_campaign_btn').removeClass('btn-progress');
        if (response.status === '1') {
          $('#campaign_modal').modal('hide');
          swal('<?php echo $this->lang->line("Success"); ?>', response.message, 'success');
          table.draw(false);
        } else {
          swal('<?php echo $this->lang->line("Error"); ?>', response.message, 'error');
        }
      }
    });
  });

  $(document).on('click', '.delete_campaign', function() {
    var id = $(this).data('id');
    swal({
      title: '<?php echo $this->lang->line("Are you sure?"); ?>',
      icon: 'warning',
      buttons: true,
      dangerMode: true,
    }).then((willDelete) => {
      if (willDelete) {
        $.ajax({
          url: base_url + 'tiktok_bot/delete_campaign_action',
          type: 'POST',
          data: { id: id, csrf_token: csrf_token },
          dataType: 'json',
          success: function(response) {
            if (response.status === '1') {
              swal('<?php echo $this->lang->line("Success"); ?>', response.message, 'success');
              table.draw(false);
            } else {
              swal('<?php echo $this->lang->line("Error"); ?>', response.message, 'error');
            }
          }
        });
      }
    });
  });
});
</script>
