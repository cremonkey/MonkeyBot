<?php
/** Admin-only. Read-only report of the two live probes run() just made. */
?>
<section class="section">
  <div class="section-header"><h1><i class="fas fa-vial"></i> <?php echo $page_title; ?></h1></div>
  <div class="section-body">
    <div class="card">
      <div class="card-header">
        <h4>
          <?php echo html_escape($page['name']); ?> (<?php echo html_escape($page['id']); ?>)
          &mdash; group <code><?php echo html_escape($group); ?></code>
        </h4>
      </div>
      <div class="card-body">
        <p>
          Legacy <span class="badge badge-secondary"><?php echo html_escape($legacy_version); ?></span>
          vs candidate <span class="badge badge-primary"><?php echo html_escape($candidate_version); ?></span>.
          To flip this group after reviewing the diff below, set
          <code>$config['<?php echo html_escape($config_key); ?>'] = '<?php echo html_escape($candidate_version); ?>';</code>
          in <code>application/config/config.php</code> (and the config volume).
        </p>

        <?php foreach ($results as $label => $r): ?>
          <div class="card mb-3">
            <div class="card-header"><h5><?php echo html_escape($label); ?> &mdash; <code><?php echo html_escape($r['path_redacted']); ?></code></h5></div>
            <div class="card-body">
              <?php if (empty($r['diff'])): ?>
                <div class="alert alert-success mb-2">No difference between legacy and candidate.</div>
              <?php else: ?>
                <div class="alert alert-warning mb-2">
                  <?php echo count($r['diff']); ?> field(s) differ:
                  <ul class="mb-0">
                    <?php foreach ($r['diff'] as $field => $vals): ?>
                      <li><code><?php echo html_escape($field); ?></code>:
                        legacy=<code><?php echo html_escape(json_encode($vals['legacy'])); ?></code>,
                        candidate=<code><?php echo html_escape(json_encode($vals['candidate'])); ?></code></li>
                    <?php endforeach; ?>
                  </ul>
                </div>
              <?php endif; ?>
              <div class="row">
                <div class="col-md-6">
                  <strong>Legacy raw response</strong> (HTTP <?php echo (int) $r['legacy']['status']; ?>)
                  <pre><?php echo html_escape(json_encode($r['legacy']['body'], JSON_PRETTY_PRINT)); ?></pre>
                </div>
                <div class="col-md-6">
                  <strong>Candidate raw response</strong> (HTTP <?php echo (int) $r['candidate']['status']; ?>)
                  <pre><?php echo html_escape(json_encode($r['candidate']['body'], JSON_PRETTY_PRINT)); ?></pre>
                </div>
              </div>
            </div>
          </div>
        <?php endforeach; ?>

        <a href="<?php echo base_url('graph_diagnostics'); ?>" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Run another</a>
      </div>
    </div>
  </div>
</section>
