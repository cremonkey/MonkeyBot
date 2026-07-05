<section class="section">
  <div class="section-header"><h1><i class="fas fa-calendar-check"></i> <?php echo $page_title; ?></h1></div>
  <?php $this->load->view('admin/theme/message'); ?>
  <div class="section-body">
    <div class="card"><div class="card-body">
      <b>Public booking link:</b> <a href="<?php echo $booking_url; ?>" target="_blank"><?php echo $booking_url; ?></a>
    </div></div>
    <div class="row">
      <div class="col-lg-4">
        <div class="card"><div class="card-header"><h4>Add Service</h4></div>
          <form action="<?php echo base_url('appointment_booking/save_service'); ?>" method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo $this->session->userdata('csrf_token_session'); ?>">
            <div class="card-body">
              <div class="form-group"><label>Name</label><input name="name" class="form-control" required></div>
              <div class="form-group"><label>Duration (min)</label><input name="duration_min" type="number" class="form-control" value="30"></div>
              <div class="row"><div class="col-8 form-group"><label>Price</label><input name="price" type="number" step="0.01" class="form-control" value="0"></div><div class="col-4 form-group"><label>Cur</label><input name="currency" class="form-control" value="USD"></div></div>
            </div>
            <div class="card-footer"><button class="btn btn-primary btn-block">Add Service</button></div>
          </form>
        </div>
        <div class="card"><div class="card-header"><h4>Weekly Availability</h4></div>
          <form action="<?php echo base_url('appointment_booking/save_availability'); ?>" method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo $this->session->userdata('csrf_token_session'); ?>">
            <div class="card-body">
              <?php $days=['Sun','Mon','Tue','Wed','Thu','Fri','Sat']; $av=array(); foreach($availability as $a){$av[$a['weekday']]=$a;}
              foreach($days as $wd=>$dn): $row=$av[$wd]??null; ?>
                <div class="form-row align-items-center mb-1">
                  <div class="col-3"><input type="checkbox" name="weekday[]" value="<?php echo $wd; ?>" <?php echo $row?'checked':''; ?>> <?php echo $dn; ?></div>
                  <div class="col"><input type="time" name="start_time[]" class="form-control form-control-sm" value="<?php echo $row['start_time']??'09:00'; ?>"></div>
                  <div class="col"><input type="time" name="end_time[]" class="form-control form-control-sm" value="<?php echo $row['end_time']??'17:00'; ?>"></div>
                </div>
              <?php endforeach; ?>
            </div>
            <div class="card-footer"><button class="btn btn-primary btn-block">Save Availability</button></div>
          </form>
        </div>
      </div>
      <div class="col-lg-8">
        <div class="card"><div class="card-header"><h4>Services</h4></div><div class="card-body">
          <table class="table table-sm"><thead><tr><th>Name</th><th>Duration</th><th>Price</th><th></th></tr></thead><tbody>
          <?php foreach($services as $s): ?><tr><td><?php echo htmlspecialchars($s['name']); ?></td><td><?php echo $s['duration_min']; ?>m</td><td><?php echo number_format($s['price'],2).' '.htmlspecialchars($s['currency']); ?></td><td><a href="<?php echo base_url('appointment_booking/delete_service/'.$s['id'].'?t='.$this->session->userdata('csrf_token_session')); ?>" class="btn btn-sm btn-danger" onclick="return confirm('Delete service?')">×</a></td></tr><?php endforeach; ?>
          </tbody></table>
        </div></div>
        <div class="card"><div class="card-header"><h4>Appointments</h4></div><div class="card-body">
          <table class="table table-sm"><thead><tr><th>When</th><th>Customer</th><th>Status</th><th>Actions</th></tr></thead><tbody>
          <?php foreach($appointments as $a): ?>
            <tr><td><?php echo $a['starts_at']; ?></td><td><?php echo htmlspecialchars($a['customer_name']); ?><br><small><?php echo htmlspecialchars($a['customer_phone']); ?></small></td>
            <td><span class="badge badge-<?php echo $a['status']=='confirmed'?'success':($a['status']=='cancelled'?'danger':($a['status']=='done'?'info':'warning')); ?>"><?php echo $a['status']; ?></span></td>
            <td>
<?php $tk=$this->session->userdata('csrf_token_session'); ?>
              <a href="<?php echo base_url('appointment_booking/set_status/'.$a['id'].'/confirmed?t='.$tk); ?>" class="btn btn-sm btn-outline-success">Confirm</a>
              <a href="<?php echo base_url('appointment_booking/set_status/'.$a['id'].'/done?t='.$tk); ?>" class="btn btn-sm btn-outline-info">Done</a>
              <a href="<?php echo base_url('appointment_booking/set_status/'.$a['id'].'/cancelled?t='.$tk); ?>" class="btn btn-sm btn-outline-danger">Cancel</a>
            </td></tr>
          <?php endforeach; ?>
          </tbody></table>
        </div></div>
      </div>
    </div>
  </div>
</section>
