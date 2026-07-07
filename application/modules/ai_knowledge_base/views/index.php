<?php $this->load->view('admin/theme/message'); ?>
<style>
  #search_source_name { max-width: 300px; }
  .source-type-badge { text-transform: uppercase; font-size: 0.75rem; }
  @media (max-width: 575.98px) {
    #search_source_name { max-width: 100%; }
  }
</style>

<section class="section section_custom">
  <div class="section-header">
    <h1><i class="fas fa-book"></i> <?php echo $page_title; ?></h1>
    <div class="section-header-button">
      <a class="btn btn-primary" href="javascript:void(0)" data-toggle="modal" data-target="#add_source_modal">
        <i class="fas fa-plus-circle"></i> <?php echo $this->lang->line("Add Source"); ?>
      </a>
    </div>
    <div class="section-header-breadcrumb">
      <div class="breadcrumb-item"><a href="<?php echo base_url('home'); ?>"><?php echo $this->lang->line("Dashboard"); ?></a></div>
      <div class="breadcrumb-item"><?php echo $page_title; ?></div>
    </div>
  </div>

  <div class="section-body">
    <div class="row">
      <div class="col-12">
        <div class="card">
          <div class="card-body data-card">
            <div class="row">
              <div class="col-md-9 col-12">
                <div class="input-group mb-3 float-left" id="searchbox">
                  <?php if(!empty($page_info)) { ?>
                  <div class="input-group-prepend">
                    <select id="search_page_id" name="search_page_id" class="form-control" style="min-width:160px;">
                      <option value=""><?php echo $this->lang->line('All Pages'); ?></option>
                      <?php foreach($page_info as $page) { ?>
                        <option value="<?php echo $page['id']; ?>"><?php echo htmlspecialchars($page['page_name']); ?></option>
                      <?php } ?>
                    </select>
                  </div>
                  <?php } ?>
                  <input type="text" class="form-control" id="search_source_name" name="search_source_name" autofocus placeholder="<?php echo $this->lang->line('Search...'); ?>" aria-label="" aria-describedby="basic-addon2">
                  <div class="input-group-append">
                    <button class="btn btn-primary" id="search_submit" title="<?php echo $this->lang->line('Search'); ?>" type="button"><i class="fas fa-search"></i> <span class="d-none d-sm-inline"><?php echo $this->lang->line('Search'); ?></span></button>
                  </div>
                </div>
              </div>
            </div>
            <div class="table-responsive2">
              <table class="table table-bordered" id="mytable">
                <thead>
                  <tr>
                    <th>#</th>
                    <th><?php echo $this->lang->line("ID"); ?></th>
                    <th><?php echo $this->lang->line("Name"); ?></th>
                    <th><?php echo $this->lang->line("Type"); ?></th>
                    <th><?php echo $this->lang->line("Source"); ?></th>
                    <th><?php echo $this->lang->line("Page"); ?></th>
                    <th><?php echo $this->lang->line("Chunks"); ?></th>
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

<?php $this->load->view('ai_knowledge_base/add_modal'); ?>

<div class="modal fade" role="dialog" id="preview_modal">
  <div class="modal-dialog modal-lg" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fas fa-eye"></i> <?php echo $this->lang->line('Preview Chunks'); ?></h5>
        <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
      </div>
      <div class="modal-body">
        <div id="preview_content" class="text-muted"><?php echo $this->lang->line('Loading...'); ?></div>
      </div>
      <div class="modal-footer bg-whitesmoke">
        <button type="button" class="btn btn-secondary" data-dismiss="modal"><i class="fas fa-times"></i> <?php echo $this->lang->line('Close'); ?></button>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" role="dialog" id="test_search_modal">
  <div class="modal-dialog modal-lg" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fas fa-search"></i> <?php echo $this->lang->line('Test Search'); ?></h5>
        <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
      </div>
      <div class="modal-body">
        <div class="form-group">
          <label><?php echo $this->lang->line('Test Question'); ?></label>
          <input type="text" id="test_query" class="form-control" placeholder="<?php echo $this->lang->line('e.g. What are your prices?'); ?>">
          <input type="hidden" id="test_page_id" value="0">
        </div>
        <button type="button" id="run_test_search" class="btn btn-primary"><i class="fas fa-search"></i> <?php echo $this->lang->line('Search'); ?></button>
        <hr>
        <div id="test_search_content" class="text-muted"><?php echo $this->lang->line('Results will appear here.'); ?></div>
      </div>
      <div class="modal-footer bg-whitesmoke">
        <button type="button" class="btn btn-secondary" data-dismiss="modal"><i class="fas fa-times"></i> <?php echo $this->lang->line('Close'); ?></button>
      </div>
    </div>
  </div>
</div>

<script>
$(document).ready(function($) {
  var base_url = '<?php echo base_url(); ?>';

  var perscroll;
  var table = $("#mytable").DataTable({
      serverSide: true,
      processing: true,
      bFilter: false,
      order: [[ 1, "desc" ]],
      pageLength: 10,
      ajax: {
        "url": base_url + 'ai_knowledge_base/source_data',
        "type": 'POST',
        data: function (d) {
            d.search_source_name = $('#search_source_name').val();
            d.search_page_id = $('#search_page_id').val();
        }
      },
      columns: [
          { data: null, render: function(data, type, row, meta) { return meta.row + meta.settings._iDisplayStart + 1; } },
          { data: 'id' },
          { data: 'source_name' },
          { data: 'source_type', render: function(data) { return '<span class="badge badge-light source-type-badge">' + data + '</span>'; } },
          { data: 'source_url', render: function(data) { return data ? '<small>' + $('<div>').text(data).html() + '</small>' : '-'; } },
          { data: 'page_name', render: function(data) { return data ? data : '<span class="text-muted"><?php echo $this->lang->line("All Pages"); ?></span>'; } },
          { data: 'total_chunks' },
          { data: 'status', render: function(data, type, row) {
              var checked = data === 'active' ? 'checked' : '';
              return '<div class="custom-control custom-switch"><input type="checkbox" class="custom-control-input toggle_status" id="status_' + row.id + '" data-id="' + row.id + '" ' + checked + '><label class="custom-control-label" for="status_' + row.id + '"></label></div>';
          }},
          { data: 'created_at' },
          { data: null, render: function(data, type, row) {
              var btns = '';
              btns += '<button class="btn btn-sm btn-info preview_source mr-1" data-id="' + row.id + '" title="<?php echo $this->lang->line("Preview Chunks"); ?>"><i class="fas fa-eye"></i></button>';
              btns += '<button class="btn btn-sm btn-warning test_source mr-1" data-id="' + row.id + '" data-page-id="' + (row.page_id ? row.page_id : '0') + '" title="<?php echo $this->lang->line("Test Search"); ?>"><i class="fas fa-search"></i></button>';
              btns += '<button class="btn btn-sm btn-danger delete_source" data-id="' + row.id + '" title="<?php echo $this->lang->line("Delete"); ?>"><i class="fas fa-trash"></i></button>';
              return btns;
          }}
      ],
      language: {
        url: "<?php echo base_url('assets/modules/datatables/language/'.$this->language.'.json'); ?>"
      },
      dom: '<"top"f>rt<"bottom"lip><"clear">',
      columnDefs: [
          { targets: [1], visible: false },
          { targets: [0, 3, 4, 5, 6, 7, 9], sortable: false },
          { targets: [0, 1, 3, 5, 6, 7, 9], className: "text-center" }
      ],
      fnInitComplete: function() {
        if (typeof perscroll === 'undefined') {
          perscroll = new PerfectScrollbar('#mytable_wrapper .dataTables_scrollBody');
        }
      },
      scrollX: true,
      fnDrawCallback: function(oSettings) {
        if (typeof perscroll !== 'undefined') perscroll.update();
      }
  });

  $(document).on('click', '#search_submit', function(event) {
    event.preventDefault();
    table.draw();
  });

  $(document).on('keypress', '#search_source_name', function(e) {
    if (e.which === 13) {
      table.draw();
    }
  });

  $(document).on('change', '#source_type', function() {
    var type = $(this).val();
    $('#url_input_group, #pdf_input_group, #md_input_group, #text_input_group').addClass('d-none');
    if (type === 'pdf') {
      $('#pdf_input_group').removeClass('d-none');
    } else if (type === 'md') {
      $('#md_input_group').removeClass('d-none');
    } else if (type === 'text') {
      $('#text_input_group').removeClass('d-none');
    } else {
      $('#url_input_group').removeClass('d-none');
    }
  });

  $(document).on('submit', '#add_source_form', function(e) {
    e.preventDefault();
    var form = $(this)[0];
    var formData = new FormData(form);

    $.ajax({
      url: base_url + 'ai_knowledge_base/add_source_action',
      type: 'POST',
      data: formData,
      processData: false,
      contentType: false,
      dataType: 'json',
      beforeSend: function() {
        $('#save_source_btn').addClass('btn-progress');
      },
      success: function(response) {
        $('#save_source_btn').removeClass('btn-progress');
        if (response.status === '1') {
          $('#add_source_modal').modal('hide');
          form.reset();
          swal('<?php echo $this->lang->line("Success"); ?>', response.message, 'success');
          table.draw(false);
        } else {
          swal('<?php echo $this->lang->line("Error"); ?>', response.message, 'error');
        }
      },
      error: function() {
        $('#save_source_btn').removeClass('btn-progress');
        swal('<?php echo $this->lang->line("Error"); ?>', '<?php echo $this->lang->line("An unexpected error occurred."); ?>', 'error');
      }
    });
  });

  $(document).on('click', '.preview_source', function() {
    var id = $(this).data('id');
    $('#preview_content').html('<?php echo $this->lang->line("Loading..."); ?>');
    $('#preview_modal').modal('show');
    $.ajax({
      url: base_url + 'ai_knowledge_base/preview_chunks_action',
      type: 'POST',
      data: { id: id },
      dataType: 'json',
      success: function(response) {
        if (response.status === '1' && response.chunks.length > 0) {
          var html = '<h6>' + response.source_name + '</h6>';
          response.chunks.forEach(function(chunk, idx) {
            html += '<div class="card mb-2"><div class="card-body"><h6 class="card-subtitle mb-2 text-muted"><?php echo $this->lang->line("Chunk"); ?> ' + (idx + 1) + '</h6><p class="card-text">' + $('<div>').text(chunk.chunk_text).html() + '</p></div></div>';
          });
          $('#preview_content').html(html);
        } else {
          $('#preview_content').html('<div class="alert alert-warning"><?php echo $this->lang->line("No chunks found."); ?></div>');
        }
      }
    });
  });

  $(document).on('click', '.test_source', function() {
    $('#test_query').val('');
    $('#test_page_id').val($(this).data('page-id'));
    $('#test_search_content').html('<?php echo $this->lang->line("Results will appear here."); ?>');
    $('#test_search_modal').modal('show');
  });

  $(document).on('click', '#run_test_search', function() {
    var query = $('#test_query').val().trim();
    var page_id = $('#test_page_id').val();
    if (!query) return;
    $('#test_search_content').html('<?php echo $this->lang->line("Loading..."); ?>');
    $.ajax({
      url: base_url + 'ai_knowledge_base/test_search_action',
      type: 'POST',
      data: { query: query, page_id: page_id },
      dataType: 'json',
      success: function(response) {
        if (response.status === '1') {
          if (response.context) {
            $('#test_search_content').html('<pre style="white-space:pre-wrap;">' + $('<div>').text(response.context).html() + '</pre>');
          } else {
            $('#test_search_content').html('<div class="alert alert-warning"><?php echo $this->lang->line("No relevant excerpts found."); ?></div>');
          }
        } else {
          $('#test_search_content').html('<div class="alert alert-danger">' + response.message + '</div>');
        }
      }
    });
  });

  $(document).on('click', '.delete_source', function() {
    var id = $(this).data('id');
    swal({
      title: '<?php echo $this->lang->line("Are you sure?"); ?>',
      text: '<?php echo $this->lang->line("This will delete the source and all its chunks."); ?>',
      icon: 'warning',
      buttons: true,
      dangerMode: true,
    }).then((willDelete) => {
      if (willDelete) {
        $.ajax({
          url: base_url + 'ai_knowledge_base/delete_source_action',
          type: 'POST',
          data: { id: id },
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

  $(document).on('change', '.toggle_status', function() {
    var id = $(this).data('id');
    var status = $(this).is(':checked') ? 'active' : 'inactive';
    $.ajax({
      url: base_url + 'ai_knowledge_base/toggle_status_action',
      type: 'POST',
      data: { id: id, status: status },
      dataType: 'json',
      success: function(response) {
        if (response.status !== '1') {
          swal('<?php echo $this->lang->line("Error"); ?>', response.message, 'error');
        }
      }
    });
  });
});
</script>
