<section class="section an-page">
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
          <div class="card card-statistic-1 stat-card">
            <div class="card-icon bg-<?php echo $c[3]; ?>"><i class="<?php echo $c[2]; ?>"></i></div>
            <div class="card-wrap"><div class="card-header"><h4><?php echo $c[0]; ?></h4></div>
              <div class="card-body"><?php echo number_format($c[1]); ?></div></div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
    <div class="row">
      <?php $k = $sales_kpis;
        $rt = $k['median_response_s'];
        $rt_txt = $rt === null ? '—' : ($rt < 60 ? $rt.'s' : round($rt/60,1).'m'); ?>
      <div class="col-lg-3 col-6"><div class="card card-statistic-1 stat-card"><div class="card-icon bg-info"><i class="fas fa-comments"></i></div><div class="card-wrap"><div class="card-header"><h4>Chat &rarr; Lead</h4></div><div class="card-body"><?php echo $k['chat_to_lead']; ?>%</div></div></div></div>
      <div class="col-lg-3 col-6"><div class="card card-statistic-1 stat-card"><div class="card-icon bg-success"><i class="fas fa-trophy"></i></div><div class="card-wrap"><div class="card-header"><h4>Lead &rarr; Won</h4></div><div class="card-body"><?php echo $k['lead_to_won']; ?>%</div></div></div></div>
      <div class="col-lg-3 col-6"><div class="card card-statistic-1 stat-card"><div class="card-icon bg-primary"><i class="fas fa-hand-holding-usd"></i></div><div class="card-wrap"><div class="card-header"><h4>Won Value (30d)</h4></div><div class="card-body"><?php echo number_format($k['won_value']); ?></div></div></div></div>
      <div class="col-lg-3 col-6"><div class="card card-statistic-1 stat-card"><div class="card-icon bg-warning"><i class="fas fa-stopwatch"></i></div><div class="card-wrap"><div class="card-header"><h4>Median Reply</h4></div><div class="card-body"><?php echo $rt_txt; ?></div></div></div></div>
    </div>

    <div class="row">
      <div class="col-12">
        <div class="card chart-card">
          <div class="card-header"><h4><i class="fas fa-bullseye"></i> Business KPIs (7d)</h4></div>
          <div class="card-body">
            <?php if (!empty($business_kpis['collecting'])): ?>
              <p class="text-muted mb-0">
                Collecting data — <span lang="ar" dir="rtl">بنجمع البيانات</span>. These KPIs fill in once the daily
                aggregate has run for a few days.
              </p>
            <?php else: $bk = $business_kpis['kpis'];
              $fmt = function ($v, $suffix = '%') { return $v === null ? '—' : $v . $suffix; };
              $rows = array(
                array('Qualified lead rate', $fmt($bk['qualified_lead_rate']), 'fas fa-user-check'),
                array('Booking rate', $fmt($bk['booking_rate']), 'fas fa-calendar-check'),
                array('Human takeover rate', $fmt($bk['human_takeover_rate']), 'fas fa-headset'),
                array('AI resolution rate', $fmt($bk['ai_resolution_rate']), 'fas fa-robot'),
                array('Avg resolution time', $fmt($bk['avg_resolution_minutes'], 'm'), 'fas fa-hourglass-half'),
                array('Human first response', $fmt($bk['human_first_response_secs'], 's'), 'fas fa-stopwatch'),
                array('Follow-up success', $fmt($bk['followup_success_rate']), 'fas fa-paper-plane'),
                array('Re-engagement success', $fmt($bk['reengage_success_rate']), 'fas fa-redo'),
                array('Unanswered-question rate', $fmt($bk['unanswered_rate']), 'fas fa-question-circle'),
                array('Cost / conversation', $fmt($bk['cost_per_conversation'], ' USD'), 'fas fa-dollar-sign'),
                array('Cost / qualified lead', $fmt($bk['cost_per_qualified_lead'], ' USD'), 'fas fa-coins'),
              ); ?>
              <div class="row">
                <?php foreach ($rows as $r): ?>
                <div class="col-lg-4 col-md-6 col-12 mb-2">
                  <div class="d-flex align-items-center">
                    <i class="<?php echo $r[2]; ?> mr-2 text-muted"></i>
                    <span class="mr-auto"><?php echo $r[0]; ?></span>
                    <strong><?php echo $r[1]; ?></strong>
                  </div>
                </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>

    <?php if (!empty($insights)): ?>
    <div class="row">
      <div class="col-12">
        <div class="card chart-card">
          <div class="card-header"><h4><i class="fas fa-lightbulb"></i> Insights</h4></div>
          <div class="card-body">
            <ul class="list-unstyled mb-0">
              <?php foreach ($insights as $ins):
                $cls = ($ins['severity'] ?? 'info') === 'warn' ? 'text-warning' : 'text-info'; ?>
              <li class="mb-2">
                <i class="fas fa-circle <?php echo $cls; ?>" style="font-size:.5em;vertical-align:middle"></i>
                <?php echo htmlspecialchars($ins['en']); ?>
                <div class="text-muted" lang="ar" dir="rtl"><?php echo htmlspecialchars($ins['ar']); ?></div>
              </li>
              <?php endforeach; ?>
            </ul>
          </div>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <div class="row">
      <div class="col-12">
        <div class="card">
          <div class="card-header"><h4><i class="fas fa-filter"></i> Sales Funnel (30d): Conversations &rarr; Leads &rarr; Won</h4></div>
          <div class="card-body">
            <?php
              $ft = $funnel['totals'];
              $lead_rate = $ft['conversations'] > 0 ? round($ft['leads'] / $ft['conversations'] * 100, 1) : 0;
              $won_rate  = $ft['leads'] > 0 ? round($ft['won'] / $ft['leads'] * 100, 1) : 0;
            ?>
            <div class="row text-center mb-3">
              <div class="col-4"><h3 class="mb-0"><?php echo number_format($ft['conversations']); ?></h3><small class="text-muted">Conversations</small></div>
              <div class="col-4"><h3 class="mb-0"><?php echo number_format($ft['leads']); ?> <small class="text-success" style="font-size:.55em"><?php echo $lead_rate; ?>%</small></h3><small class="text-muted">Leads captured</small></div>
              <div class="col-4"><h3 class="mb-0"><?php echo number_format($ft['won']); ?> <small class="text-success" style="font-size:.55em"><?php echo $won_rate; ?>%</small></h3><small class="text-muted">Deals won</small></div>
            </div>
            <?php if(!empty($funnel['by_platform'])): ?>
            <div class="table-responsive"><table class="table table-sm mb-0">
              <thead><tr><th>Platform</th><th>Conversations</th><th>Leads</th><th>Conv&rarr;Lead</th><th>Won</th><th>Lead&rarr;Won</th></tr></thead>
              <tbody>
              <?php foreach($funnel['by_platform'] as $plat => $f):
                $lr = $f['conversations'] > 0 ? round($f['leads'] / $f['conversations'] * 100, 1) : 0;
                $wr = $f['leads'] > 0 ? round($f['won'] / $f['leads'] * 100, 1) : 0; ?>
                <tr>
                  <td><span class="badge badge-primary"><?php echo htmlspecialchars($plat); ?></span></td>
                  <td><?php echo $f['conversations']; ?></td>
                  <td><?php echo $f['leads']; ?></td>
                  <td><div class="progress" style="height:16px;min-width:90px"><div class="progress-bar bg-info" style="width:<?php echo min(100,$lr); ?>%"><?php echo $lr; ?>%</div></div></td>
                  <td><?php echo $f['won']; ?></td>
                  <td><div class="progress" style="height:16px;min-width:90px"><div class="progress-bar bg-success" style="width:<?php echo min(100,$wr); ?>%"><?php echo $wr; ?>%</div></div></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table></div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
    <div class="row">
      <div class="col-lg-8"><div class="card chart-card"><div class="card-header"><h4>New Subscribers (30d)</h4></div><div class="card-body"><canvas id="chSubs" height="90"></canvas></div></div></div>
      <div class="col-lg-4"><div class="card chart-card"><div class="card-header"><h4>Lead Bands</h4></div><div class="card-body"><canvas id="chLeads" height="180"></canvas></div></div></div>
    </div>
    <div class="row">
      <div class="col-lg-6"><div class="card chart-card"><div class="card-header"><h4>Conversations by Sender (30d)</h4></div><div class="card-body"><canvas id="chMsg" height="120"></canvas></div></div></div>
      <div class="col-lg-6"><div class="card chart-card"><div class="card-header"><h4>Orders &amp; Revenue (30d)</h4></div><div class="card-body"><canvas id="chOrders" height="120"></canvas></div></div></div>
    </div>
    <div class="row">
      <div class="col-lg-8"><div class="card chart-card"><div class="card-header"><h4>AI Replies per Day (30d)</h4></div><div class="card-body"><canvas id="chAi" height="90"></canvas></div></div></div>
      <div class="col-lg-4"><div class="card"><div class="card-header"><h4>Estimated AI Cost (30d)</h4></div><div class="card-body text-center"><h2 class="text-primary">$<?php echo number_format($ai_cost,2); ?></h2><small class="text-muted">rough estimate from token usage</small></div></div></div>
    </div>

    <div class="row">
      <div class="col-lg-4">
        <div class="card">
          <div class="card-header"><h4><i class="fas fa-hand-paper"></i> Deflection Rate (30d)</h4></div>
          <div class="card-body text-center">
            <?php $df = $deflection; $rate=(float)$df['rate'];
              $cls = $rate < 10 ? 'text-success' : ($rate < 25 ? 'text-warning' : 'text-danger'); ?>
            <h1 class="<?php echo $cls; ?>" style="font-size:2.6rem;"><?php echo $rate; ?>%</h1>
            <p class="text-muted mb-1">of <?php echo number_format($df['replies']); ?> replies couldn't give a real answer</p>
            <small class="text-muted"><?php echo (int)$df['missed']; ?> gaps flagged · <?php echo (int)$df['blocked']; ?> price blocks</small>
            <div class="mt-2"><a href="<?php echo base_url('missed_questions'); ?>" class="btn btn-sm btn-outline-primary">Fill the gaps &rarr;</a></div>
          </div>
        </div>
      </div>
      <div class="col-lg-8">
        <div class="card">
          <div class="card-header"><h4><i class="fas fa-question-circle"></i> Top Unanswered Questions (30d)</h4>
            <div class="card-header-action"><a href="<?php echo base_url('missed_questions'); ?>" class="btn btn-sm btn-primary">Answer them</a></div>
          </div>
          <div class="card-body p-0">
            <?php if(empty($top_missed)): ?>
              <p class="text-muted p-3 mb-0">No unanswered questions — the bot handled everything. 🎉</p>
            <?php else: ?>
            <table class="table table-sm table-striped mb-0">
              <thead><tr><th style="width:60px">#</th><th>Question</th><th style="width:70px">Channel</th></tr></thead>
              <tbody>
                <?php foreach($top_missed as $m): ?>
                <tr>
                  <td><span class="badge badge-<?php echo $m['c']>1?'danger':'secondary'; ?>"><?php echo (int)$m['c']; ?>×</span></td>
                  <td style="white-space:pre-line"><?php echo htmlspecialchars(mb_substr((string)$m['q'],0,90)); ?></td>
                  <td><small class="text-muted"><?php echo htmlspecialchars((string)$m['social_media']); ?></small></td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
            <?php endif; ?>
          </div>
        </div>
      </div>
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
