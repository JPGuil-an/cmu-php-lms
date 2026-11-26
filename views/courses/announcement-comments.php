<?php
use CmuLms\View;

$discussionAnnouncements = array_values(array_filter($announcements ?? [], function ($announcement) {
    return !empty($announcement['comments_enabled']);
}));
if ($discussionAnnouncements):
?>
<section class="card"><h2>Announcement discussions</h2>
<?php foreach ($discussionAnnouncements as $announcement): $comments = \CmuLms\Announcement::comments((int) $announcement['id']); ?>
  <article class="card"><h3><?=View::e($announcement['title'])?></h3>
    <?php foreach ($comments as $comment): ?>
      <p><strong><?=View::e($comment['user_name'])?></strong> <small><?=View::e($comment['created_at'])?></small><br><?=nl2br(View::e($comment['body']))?>
      <?php if ((int) $comment['user_id'] === (int) $user['id'] || in_array(($user['role'] ?? ''), ['admin', 'teacher'], true)): ?>
        <form method="post" action="?route=announcement-comment-delete" style="display:inline" onsubmit="return confirm('Delete this comment?')"><?=View::csrfField()?><input type="hidden" name="comment_id" value="<?=View::e($comment['id'])?>"><button>Delete</button></form>
      <?php endif; ?></p>
    <?php endforeach; ?>
    <form method="post" action="?route=announcement-comment"><?=View::csrfField()?><input type="hidden" name="announcement_id" value="<?=View::e($announcement['id'])?>"><textarea name="body" placeholder="Write a comment" required></textarea><button>Post comment</button></form>
  </article>
<?php endforeach; ?></section>
<?php endif; ?>
