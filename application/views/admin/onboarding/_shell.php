<?php
/**
 * Shared header + stepper for the Setup Guide. Included at the top of every step view.
 * Owner language only (§46): no module ids, no internal feature names.
 */
$titles = $step_titles;
$flags  = $progress['flags'];
?>
<section class="section ob-page">
  <div class="section-header">
    <h1><i class="fas fa-rocket"></i> Setup Guide <small class="text-muted" dir="rtl">دليل التجهيز</small></h1>
    <div class="section-header-breadcrumb">
      <span class="badge badge-<?php echo $progress['done'] >= $progress['total'] ? 'success' : 'primary'; ?>">
        <?php echo (int) $progress['done']; ?> / <?php echo (int) $progress['total']; ?> done
      </span>
    </div>
  </div>

  <div class="section-body">
    <?php if (!empty($state['completed_at'])): ?>
      <div class="alert alert-success">
        <strong>You are live.</strong> <span dir="rtl">المساعد شغال. تقدر ترجع لأي خطوة هنا وتعدّلها في أي وقت.</span>
      </div>
    <?php endif; ?>

    <div class="card mb-3"><div class="card-body py-2 ob-stepper-scroll">
      <ul class="nav nav-pills flex-nowrap ob-stepper-nowrap">
        <?php foreach ($titles as $i => $t):
          $reachable = ($i == 1) || !empty($tpl);
          $cls = $i == $step ? 'active' : (!empty($flags[$i]) ? 'text-success' : 'text-muted');
        ?>
          <li class="nav-item">
            <?php if ($reachable): ?>
              <a class="nav-link px-2 <?php echo $cls; ?>" href="<?php echo base_url('onboarding/step/' . $i); ?>">
                <?php echo !empty($flags[$i]) ? '<i class="fas fa-check-circle"></i>' : ('<span class="badge badge-light">' . $i . '</span>'); ?>
                <?php echo htmlspecialchars($t['en']); ?>
              </a>
            <?php else: ?>
              <span class="nav-link px-2 text-muted"><span class="badge badge-light"><?php echo $i; ?></span> <?php echo htmlspecialchars($t['en']); ?></span>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
    </div></div>

    <h2 class="section-title mb-0">Step <?php echo (int) $step; ?> — <?php echo htmlspecialchars($titles[$step]['en']); ?></h2>
    <p class="section-lead" dir="rtl"><?php echo htmlspecialchars($titles[$step]['ar']); ?></p>
    <div id="ob_msg"></div>
