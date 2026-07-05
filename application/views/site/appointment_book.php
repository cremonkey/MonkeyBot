<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Book an Appointment</title>
<style>
body{font-family:sans-serif;background:#f4f6f9;margin:0;padding:20px}
.box{max-width:480px;margin:0 auto;background:#fff;border-radius:12px;box-shadow:0 4px 20px rgba(0,0,0,.08);padding:24px}
h2{margin-top:0}label{display:block;margin:12px 0 4px;font-weight:600;font-size:14px}
select,input{width:100%;padding:10px;border:1px solid #ddd;border-radius:8px;box-sizing:border-box}
.slots{display:flex;flex-wrap:wrap;gap:8px;margin-top:8px}
.slot{padding:8px 12px;border:1px solid #0084ff;color:#0084ff;border-radius:8px;cursor:pointer}
.slot.sel{background:#0084ff;color:#fff}
button{margin-top:16px;width:100%;padding:12px;background:#0084ff;color:#fff;border:0;border-radius:8px;font-size:16px;cursor:pointer}
.msg{margin-top:12px;padding:10px;border-radius:8px}
</style></head><body>
<div class="box">
  <h2>Book an Appointment</h2>
  <label>Service</label>
  <select id="svc"><option value="">Select...</option>
    <?php foreach($services as $s): ?><option value="<?php echo (int)$s['id']; ?>" data-dur="<?php echo (int)$s['duration_min']; ?>"><?php echo htmlspecialchars($s['name']).' ('.(int)$s['duration_min'].'m'.($s['price']>0?(' · '.number_format($s['price'],2).' '.htmlspecialchars($s['currency'])):'').')'; ?></option><?php endforeach; ?>
  </select>
  <label>Date</label><input type="date" id="date" min="<?php echo date('Y-m-d'); ?>">
  <label>Available times</label><div class="slots" id="slots"><small style="color:#999">Pick a service and date.</small></div>
  <label>Your name</label><input id="name">
  <label>Phone</label><input id="phone">
  <label>Email</label><input id="email">
  <button id="bookBtn">Confirm Booking</button>
  <div id="msg"></div>
</div>
<script>
var H="<?php echo preg_replace('/[^a-f0-9]/','',$uid_hash); ?>", BASE="<?php echo base_url(); ?>", sel='';
function loadSlots(){
  var sid=document.getElementById('svc').value, d=document.getElementById('date').value;
  var box=document.getElementById('slots'); sel='';
  if(!sid||!d){box.innerHTML='<small style="color:#999">Pick a service and date.</small>';return;}
  box.innerHTML='Loading...';
  fetch(BASE+'appointment_booking/slots?h='+H+'&service_id='+sid+'&date='+d).then(r=>r.json()).then(function(r){
    if(!r.slots.length){box.innerHTML='<small style="color:#999">No slots available.</small>';return;}
    box.innerHTML='';
    r.slots.forEach(function(t){var el=document.createElement('div');el.className='slot';el.textContent=t;el.onclick=function(){document.querySelectorAll('.slot').forEach(s=>s.classList.remove('sel'));el.classList.add('sel');sel=t;};box.appendChild(el);});
  });
}
document.getElementById('svc').onchange=loadSlots;
document.getElementById('date').onchange=loadSlots;
document.getElementById('bookBtn').onclick=function(){
  var m=document.getElementById('msg');
  if(!sel){m.className='msg';m.style.background='#ffe0e0';m.textContent='Please pick a time.';return;}
  var fd=new FormData();fd.append('h',H);fd.append('service_id',document.getElementById('svc').value);fd.append('date',document.getElementById('date').value);fd.append('time',sel);fd.append('name',document.getElementById('name').value);fd.append('phone',document.getElementById('phone').value);fd.append('email',document.getElementById('email').value);
  fetch(BASE+'appointment_booking/submit_booking',{method:'POST',body:fd}).then(r=>r.json()).then(function(r){
    m.className='msg';m.style.background=r.status=='1'?'#e0ffe0':'#ffe0e0';m.textContent=r.message;
    if(r.status=='1'){document.getElementById('bookBtn').disabled=true;loadSlots();}
  });
};
</script>
</body></html>
