<?php $this->load->view('admin/theme/message'); ?>

<section class="section section_custom">
  <div class="section-header">
    <h1><i class="fab fa-tiktok"></i> <?php echo $page_title; ?></h1>
    <div class="section-header-breadcrumb">
      <div class="breadcrumb-item"><a href="<?php echo base_url('tiktok_bot'); ?>"><?php echo $this->lang->line("Campaigns"); ?></a></div>
      <div class="breadcrumb-item"><?php echo $this->lang->line("Reports"); ?></div>
    </div>
  </div>

  <input type="hidden" name="csrf_token" id="csrf_token" value="<?php echo $this->session->userdata('csrf_token_session'); ?>">

  <div class="section-body">
    <div class="row">
      <div class="col-12">
        <div class="card">
          <div class="card-body data-card">
            <div class="table-responsive2">
              <table class="table table-bordered" id="reports_table">
                <thead>
                  <tr>
                    <th>#</th>
                    <th><?php echo $this->lang->line("ID"); ?></th>
                    <th><?php echo $this->lang->line("Campaign"); ?></th>
                    <th><?php echo $this->lang->line("Trigger"); ?></th>
                    <th><?php echo $this->lang->line("Reply"); ?></th>
                    <th><?php echo $this->lang->line("Status"); ?></th>
                    <th><?php echo $this->lang->line("Created"); ?></th>
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

<script>
$(document).ready(function($) {
  var base_url = '<?php echo base_url(); ?>';
  var csrf_token = $('#csrf_token').val();
  $("#reports_table").DataTable({
      serverSide: true,
      processing: true,
      bFilter: true,
      order: [[ 1, "desc" ]],
      pageLength: 25,
      ajax: {
        "url": base_url + 'tiktok_bot/report_data',
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
          { data: 'campaign_name' },
          { data: 'trigger_text', render: function(data) { return data ? data : '<span class="text-muted">-</span>'; } },
          { data: 'reply_text', render: function(data) { return data ? data : '<span class="text-muted">-</span>'; } },
          { data: 'status' },
          { data: 'created_at' }
      ],
      columnDefs: [
          { targets: [1], visible: false },
          { targets: [0, 5], className: "text-center" }
      ]
  });
});
</script>
