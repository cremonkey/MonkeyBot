<section class="section">
  <div class="section-header"><h1><i class="fas fa-funnel-dollar"></i> <?php echo $page_title; ?></h1>
    <div class="section-header-breadcrumb">
      <a href="<?php echo base_url('crm/pipeline'); ?>" class="btn btn-primary"><i class="fas fa-columns"></i> Pipeline</a>
      <a href="<?php echo base_url('crm/contacts'); ?>" class="btn btn-outline-primary"><i class="fas fa-users"></i> Contacts</a>
    </div>
  </div>
  <div class="section-body">
    <div class="row">
      <div class="col-lg-3 col-6"><div class="card card-statistic-1"><div class="card-icon bg-primary"><i class="fas fa-dollar-sign"></i></div><div class="card-wrap"><div class="card-header"><h4>Open Value</h4></div><div class="card-body"><?php echo number_format($open_value,2); ?></div></div></div></div>
      <div class="col-lg-3 col-6"><div class="card card-statistic-1"><div class="card-icon bg-success"><i class="fas fa-trophy"></i></div><div class="card-wrap"><div class="card-header"><h4>Won (month)</h4></div><div class="card-body"><?php echo $won_month; ?></div></div></div></div>
      <div class="col-lg-3 col-6"><div class="card card-statistic-1"><div class="card-icon bg-warning"><i class="fas fa-tasks"></i></div><div class="card-wrap"><div class="card-header"><h4>Tasks Due</h4></div><div class="card-body"><?php echo $tasks_today; ?></div></div></div></div>
      <div class="col-lg-3 col-6"><div class="card card-statistic-1"><div class="card-icon bg-danger"><i class="fas fa-fire"></i></div><div class="card-wrap"><div class="card-header"><h4>Hot Leads</h4></div><div class="card-body"><?php echo $hot_leads; ?></div></div></div></div>
    </div>
    <div class="row">
      <div class="col-lg-6"><div class="card"><div class="card-header"><h4>Deals by Source</h4></div><div class="card-body"><canvas id="chSource" height="150"></canvas></div></div></div>
      <div class="col-lg-6"><div class="card"><div class="card-header"><h4>Won vs Lost (6mo)</h4></div><div class="card-body"><canvas id="chWL" height="150"></canvas></div></div></div>
    </div>
  </div>
</section>
<script src="<?php echo base_url('assets/modules/chart.min.js'); ?>"></script>
<script>
var BY_SOURCE=<?php echo json_encode($by_source); ?>, RECENT=<?php echo json_encode($recent); ?>;
document.addEventListener('DOMContentLoaded',function(){
  new Chart(document.getElementById('chSource'),{type:'doughnut',data:{labels:BY_SOURCE.map(x=>x.source),datasets:[{data:BY_SOURCE.map(x=>+x.c),backgroundColor:['#6777ef','#3abaf4','#63ed7a','#ffa426','#fc544b','#cdd3d8']}]}});
  new Chart(document.getElementById('chWL'),{type:'bar',data:{labels:RECENT.map(x=>x.m),datasets:[{label:'Won',data:RECENT.map(x=>+x.won),backgroundColor:'#63ed7a'},{label:'Lost',data:RECENT.map(x=>+x.lost),backgroundColor:'#fc544b'}]}});
});
</script>
