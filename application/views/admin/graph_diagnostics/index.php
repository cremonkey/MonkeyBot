<?php
/** Admin-only. Picking a page + group here makes no network call -- run() does, only on submit. */
?>
<section class="section">
  <div class="section-header"><h1><i class="fas fa-vial"></i> <?php echo $page_title; ?></h1></div>
  <div class="section-body">
    <div class="card">
      <div class="card-header"><h4>Compare legacy vs. candidate Graph API version</h4></div>
      <div class="card-body">
        <p>
          Legacy (today's pinned version): <span class="badge badge-secondary"><?php echo html_escape($legacy_version); ?></span>
          &nbsp; Candidate (migration target): <span class="badge badge-primary"><?php echo html_escape($candidate_version); ?></span>
        </p>
        <p class="text-muted">
          Runs two read-only, side-effect-free calls (<code>/{page_id}?fields=id,name</code> and
          <code>/me/picture?redirect=false</code>) against BOTH versions above, for the page you pick below, and
          shows the two raw responses plus a diff. Nothing is sent, changed, or deleted. Nothing runs until you
          submit this form.
        </p>

        <?php if (empty($pages)): ?>
          <div class="alert alert-warning">No connected Facebook/Instagram pages on this account yet.</div>
        <?php else: ?>
          <form method="post" action="<?php echo base_url('graph_diagnostics/run'); ?>">
            <div class="form-group">
              <label>Page</label>
              <select name="page_row_id" class="form-control" required>
                <option value="">-- choose a connected page --</option>
                <?php foreach ($pages as $p): ?>
                  <option value="<?php echo (int) $p['id']; ?>"><?php echo html_escape($p['page_name']) . ' (' . html_escape($p['page_id']) . ')'; ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group">
              <label>Group being previewed for the v26.0 flip</label>
              <select name="group" class="form-control" required>
                <?php foreach ($groups as $g): ?>
                  <option value="<?php echo html_escape($g); ?>"><?php echo html_escape($g); ?> (config key: fb_graph_group_<?php echo html_escape($g); ?>_version)</option>
                <?php endforeach; ?>
              </select>
            </div>
            <button type="submit" class="btn btn-primary"><i class="fas fa-play"></i> Run diagnostics for this page</button>
          </form>
        <?php endif; ?>
      </div>
    </div>
  </div>
</section>
