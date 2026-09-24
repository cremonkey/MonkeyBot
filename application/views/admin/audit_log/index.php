<section class="section">
  <div class="section-header"><h1><i class="fas fa-history"></i> <?php echo $page_title; ?></h1></div>
  <div class="section-body">

    <div class="card">
      <div class="card-header"><h4>Filter</h4></div>
      <div class="card-body">
        <form method="get" class="form-inline">
          <input type="text" name="entity_type" class="form-control mr-2 mb-2" placeholder="Entity type" value="<?php echo htmlspecialchars($filters['entity_type']); ?>">
          <input type="text" name="action" class="form-control mr-2 mb-2" placeholder="Action" value="<?php echo htmlspecialchars($filters['action']); ?>">
          <input type="date" name="date_from" class="form-control mr-2 mb-2" value="<?php echo htmlspecialchars($filters['date_from']); ?>">
          <input type="date" name="date_to" class="form-control mr-2 mb-2" value="<?php echo htmlspecialchars($filters['date_to']); ?>">
          <button type="submit" class="btn btn-primary mb-2">Filter</button>
        </form>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><h4>Events<?php echo $is_admin_view ? ' — all tenants' : ''; ?></h4></div>
      <div class="card-body p-0">
        <table class="table table-sm mb-0">
          <thead>
            <tr>
              <th>When</th>
              <?php if ($is_admin_view): ?><th>Tenant</th><?php endif; ?>
              <th>Actor</th>
              <th>Action</th>
              <th>Entity</th>
              <th>IP</th>
            </tr>
          </thead>
          <tbody>
          <?php if (empty($rows)): ?>
            <tr><td colspan="<?php echo $is_admin_view ? 6 : 5; ?>" class="text-center text-muted">No audit events.</td></tr>
          <?php else: foreach ($rows as $r): ?>
            <tr>
              <td><?php echo htmlspecialchars($r['created_at']); ?></td>
              <?php if ($is_admin_view): ?><td><?php echo (int)$r['user_id']; ?></td><?php endif; ?>
              <td><?php echo htmlspecialchars($r['actor_type']); ?> #<?php echo (int)$r['actor_id']; ?></td>
              <td><?php echo htmlspecialchars($r['action']); ?></td>
              <td><?php echo htmlspecialchars($r['entity_type']); ?><?php echo $r['entity_id'] !== '' ? ' #' . htmlspecialchars($r['entity_id']) : ''; ?></td>
              <td><?php echo htmlspecialchars((string)$r['ip']); ?></td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>

  </div>
</section>
