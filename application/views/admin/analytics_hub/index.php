<section class="section">
  <div class="section-header"><h1><i class="fas fa-chart-line"></i> <?php echo $page_title; ?></h1></div>
  <div class="section-body">
    <div class="row">
      <?php
        $cards = array(
          array('Subscribers', $cards['subscribers'], 'fas fa-users', 'primary'),
          array('Orders', $cards['orders'], 'fas fa-shopping-cart', 'success'),
          array('AI Replies (30d)', $cards['ai_replies_30d'], 'fas fa-robot', 'info'),
          array('Hot Leads', $cards['hot_leads'], 'fas fa-fire', 'danger'),
        );
        foreach ($cards as $c): ?>
        <div class="col-lg-3 col-md-6 col-6">
          <div class="card card-statistic-1">
            <div class="card-icon bg-<?php echo $c[3]; ?>"><i class="<?php echo $c[2]; ?>"></i></div>
            <div class="card-wrap"><div class="card-header"><h4><?php echo $c[0]; ?></h4></div>
              <div class="card-body"><?php echo number_format($c[1]); ?></div></div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
    <div class="row">
      <div class="col-lg-8"><div class="card"><div class="card-header"><h4>New Subscribers (30d)</h4></div><div class="card-body"><canvas id="chSubs" height="90"></canvas></div></div></div>
      <div class="col-lg-4"><div class="card"><div class="card-header"><h4>Lead Bands</h4></div><div class="card-body"><canvas id="chLeads" height="180"></canvas></div></div></div>
    </div>
    <div class="row">
      <div class="col-lg-6"><div class="card"><div class="card-header"><h4>Conversations by Sender (30d)</h4></div><div class="card-body"><canvas id="chMsg" height="120"></canvas></div></div></div>
      <div class="col-lg-6"><div class="card"><div class="card-header"><h4>Orders &amp; Revenue (30d)</h4></div><div class="card-body"><canvas id="chOrders" height="120"></canvas></div></div></div>
    </div>
    <div class="row">
      <div class="col-lg-8"><div class="card"><div class="card-header"><h4>AI Replies per Day (30d)</h4></div><div class="card-body"><canvas id="chAi" height="90"></canvas></div></div></div>
      <div class="col-lg-4"><div class="card"><div class="card-header"><h4>Estimated AI Cost (30d)</h4></div><div class="card-body text-center"><h2 class="text-primary">$<?php echo number_format($ai_cost,2); ?></h2><small class="text-muted">rough estimate from token usage</small></div></div></div>
    </div>
  </div>
</section>
<script src="<?php echo base_url('assets/modules/chart.min.js'); ?>"></script>
<script>
var AH = {
  subs: <?php echo json_encode($subs_series); ?>,
  msg: <?php echo json_encode($msg_series); ?>,
  ai: <?php echo json_encode($ai_series); ?>,
  orders: <?php echo json_encode($orders_series); ?>,
  leads: <?php echo json_encode($lead_bands); ?>
};
function line(id,labels,datasets){ new Chart(document.getElementById(id),{type:'line',data:{labels:labels,datasets:datasets},options:{responsive:true,maintainAspectRatio:true}}); }
document.addEventListener('DOMContentLoaded',function(){
  line('chSubs',AH.subs.labels,[{label:'New Subscribers',data:AH.subs.values,borderColor:'#6777ef',backgroundColor:'rgba(103,119,239,.1)',fill:true}]);
  line('chMsg',AH.msg.labels,[
    {label:'Customer',data:AH.msg.user,borderColor:'#63ed7a',fill:false},
    {label:'Bot',data:AH.msg.bot,borderColor:'#6777ef',fill:false},
    {label:'Agent',data:AH.msg.agent,borderColor:'#ffa426',fill:false}]);
  line('chAi',AH.ai.labels,[{label:'AI Replies',data:AH.ai.values,borderColor:'#3abaf4',backgroundColor:'rgba(58,186,244,.1)',fill:true}]);
  new Chart(document.getElementById('chOrders'),{type:'bar',data:{labels:AH.orders.labels,datasets:[
    {label:'Orders',data:AH.orders.count,backgroundColor:'#63ed7a',yAxisID:'y'},
    {type:'line',label:'Revenue',data:AH.orders.value,borderColor:'#fc544b',fill:false,yAxisID:'y1'}]},
    options:{scales:{y:{position:'left'},y1:{position:'right',grid:{drawOnChartArea:false}}}}});
  new Chart(document.getElementById('chLeads'),{type:'doughnut',data:{labels:['Hot','Warm','Cold'],datasets:[{data:[AH.leads.hot,AH.leads.warm,AH.leads.cold],backgroundColor:['#fc544b','#ffa426','#cdd3d8']}]}});
});
</script>
