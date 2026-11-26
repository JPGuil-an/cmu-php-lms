<?php
use CmuLms\View;

$visibleClassmates = array_values(array_filter($students, static fn($classmate) => empty($classmate['disabled_at'])));
?>
<section class="card">
  <h2>Classmates</h2>
  <p>Students enrolled in this course section.</p>
  <div class="grid">
    <?php foreach ($visibleClassmates as $classmate):
      $parts = preg_split('/\s+/', trim($classmate['name'] ?? ''));
      $first = $parts[0] ?? '';
      $last = count($parts) > 1 ? array_pop($parts) : $first;
    ?>
      <article class="card">
        <strong><?=View::e($last.', '.strtoupper(substr($first, 0, 1)).'.')?></strong>
        <p class="muted">Classmate</p>
      </article>
    <?php endforeach; ?>
    <?php if (!$visibleClassmates): ?><p>No classmates are currently enrolled.</p><?php endif; ?>
  </div>
</section>
