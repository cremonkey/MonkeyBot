<?php
$labels = array(
    'sent'            => array('success',   'Sent'),
    'pending'         => array('primary',   'Waiting for the next send window'),
    'sending'         => array('primary',   'Sending now'),
    'reentered'       => array('info',      'Came back — sending once the chat goes idle'),
    'waiting_reentry' => array('secondary', 'Queued until they message you'),
    'skipped'         => array('warning',   'Skipped'),
    'failed'          => array('danger',    'Failed'),
    'expired'         => array('dark',      'Expired before they returned'),
    'fulfilled'       => array('info',      'Fulfilled'),
);
?>
<section class="section">
  <div class="section-header">
    <h1><i class="fas fa-chart-line"></i> <?php echo htmlspecialchars($campaign['name']); ?></h1>
    <div class="section-header-breadcrumb">
      <a href="<?php echo base_url('reengage'); ?>" class="btn btn-outline-primary">All campaigns</a>
    </div>
  </div>

  <div class="section-body">

    <?php if ($campaign['status'] === 'halted'): ?>
      <div class="alert alert-danger">
        <strong>This campaign was halted automatically.</strong><br>
        <?php echo htmlspecialchars((string) $campaign['halt_reason']); ?>
        <div class="small mt-1">
          Sending stops on the fifth consecutive error, or immediately on a permission
          or payload error, so a misunderstanding costs a handful of messages rather
          than the page.
        </div>
      </div>
    <?php endif; ?>

    <div class="row">
      <div class="col-lg-6">
        <div class="card">
          <div class="card-header"><h4>Delivery</h4></div>
          <div class="card-body p-0">
            <table class="table mb-0">
              <?php foreach ($buckets as $b):
                    $meta = isset($labels[$b['state']]) ? $labels[$b['state']] : array('secondary', $b['state']); ?>
                <tr>
                  <td><span class="badge badge-<?php echo $meta[0]; ?>"><?php echo $b['state']; ?></span>
                      <small class="text-muted"><?php echo $meta[1]; ?></small></td>
                  <td class="text-right"><b><?php echo (int) $b['c']; ?></b></td>
                </tr>
              <?php endforeach; ?>
            </table>
          </div>
        </div>

        <?php if (count($variants) > 1): ?>
        <div class="card">
          <div class="card-header"><h4>A / B</h4></div>
          <div class="card-body p-0">
            <table class="table mb-0">
              <thead><tr><th>Variant</th><th>Audience</th><th>Sent</th></tr></thead>
              <tbody>
              <?php foreach ($variants as $v): ?>
                <tr>
                  <td><b><?php echo htmlspecialchars((string) $v['ab_variant']); ?></b></td>
                  <td><?php echo (int) $v['total']; ?></td>
                  <td><?php echo (int) $v['sent']; ?></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
        <?php endif; ?>
      </div>

      <div class="col-lg-6">
        <div class="card">
          <div class="card-header"><h4>Needs a human reply</h4></div>
          <div class="card-body">
            <p class="text-muted small">
              These customers last wrote between 1 and 7 days ago. Meta allows a reply
              only from a person, not from automation, so this tool will never message
              them. Open Livechat and answer them yourself.
            </p>
            <?php if (!empty($needs_human)): ?>
              <div class="table-responsive" style="max-height:280px;overflow:auto">
                <table class="table table-sm mb-0">
                  <thead><tr><th>Customer</th><th>Last wrote</th></tr></thead>
                  <tbody>
                  <?php foreach ($needs_human as $h): ?>
                    <tr>
                      <td><?php echo htmlspecialchars(trim((string) $h['name']) ?: $h['subscribe_id']); ?></td>
                      <td class="small text-muted"><?php echo htmlspecialchars((string) $h['last_inbound']); ?></td>
                    </tr>
                  <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php else: ?>
              <div class="text-muted">Nobody.</div>
            <?php endif; ?>
          </div>
        </div>

        <div class="card">
          <div class="card-header"><h4>Errors from Meta</h4></div>
          <div class="card-body p-0">
            <?php if (!empty($errors)): ?>
              <table class="table table-sm mb-0">
                <thead><tr><th>Code</th><th>Message</th><th>Count</th></tr></thead>
                <tbody>
                <?php foreach ($errors as $e): ?>
                  <tr>
                    <td><code><?php echo htmlspecialchars((string) $e['error_code']); ?></code></td>
                    <td class="small"><?php echo htmlspecialchars((string) $e['error_message']); ?></td>
                    <td><?php echo (int) $e['c']; ?></td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            <?php else: ?>
              <div class="p-4 text-muted">None.</div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>

  </div>
</section>
