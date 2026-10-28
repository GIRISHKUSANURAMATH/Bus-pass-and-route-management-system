<?php
/**
 * Shared account helpers: password change + notifications page.
 */
declare(strict_types=1);

/** @return string|null error message, null on success */
function change_password(int $userId, string $current, string $new, string $confirm): ?string
{
    $hash = (string) q_val('SELECT password_hash FROM users WHERE id = ?', [$userId]);
    if (!password_verify($current, $hash)) {
        audit_log('password_change_failed', 'user', $userId);
        return 'Current password is incorrect.';
    }
    if ($p = password_problem($new)) {
        return $p;
    }
    if ($new !== $confirm) {
        return 'New passwords do not match.';
    }
    if (password_verify($new, $hash)) {
        return 'New password must be different from the current one.';
    }
    q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), $userId]);
    session_regenerate_id(true);
    audit_log('password_changed', 'user', $userId);
    return null;
}

function render_password_card(): void
{
    ?>
<div class="card"><div class="card-header">Change password</div><div class="card-body">
  <form method="post" class="needs-validation" novalidate>
    <?= csrf_field() ?><input type="hidden" name="action" value="password">
    <div class="mb-2"><label class="form-label" for="current_password">Current password</label>
      <input class="form-control" type="password" id="current_password" name="current_password" required autocomplete="current-password"></div>
    <div class="mb-2"><label class="form-label" for="new_password">New password</label>
      <input class="form-control" type="password" id="new_password" name="new_password" required minlength="8" maxlength="72" autocomplete="new-password"></div>
    <div class="mb-3"><label class="form-label" for="confirm_new">Confirm new password</label>
      <input class="form-control" type="password" id="confirm_new" name="confirm_new" required data-match="new_password" autocomplete="new-password"></div>
    <button class="btn btn-outline-primary">Update password</button>
  </form>
</div></div>
<?php
}

/** Notifications page body for any role. Handles "mark read" POSTs. */
function notifications_page(string $role): void
{
    $user = require_role($role);
    if (is_post()) {
        require_csrf();
        if (input('action') === 'read_all') {
            q('UPDATE notifications SET is_read = 1 WHERE user_id = ?', [$user['id']]);
        } elseif ($id = input_int('id')) {
            q('UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?', [$id, $user['id']]);
        }
        redirect($role . '/notifications.php');
    }
    $total = (int) q_val('SELECT COUNT(*) FROM notifications WHERE user_id = ?', [$user['id']]);
    $p = paginate($total, 20);
    $rows = q_all('SELECT * FROM notifications WHERE user_id = ? ORDER BY id DESC LIMIT ' . $p['limit'] . ' OFFSET ' . $p['offset'], [$user['id']]);

    page_header('Notifications', 'notifications.php');
    ?>
<form method="post" class="mb-3"><?= csrf_field() ?><input type="hidden" name="action" value="read_all">
  <button class="btn btn-sm btn-outline-secondary"><i class="bi bi-check2-all"></i> Mark all as read</button></form>
<div class="list-group mb-3">
  <?php if (!$rows): ?><div class="list-group-item text-muted">No notifications.</div><?php endif; ?>
  <?php foreach ($rows as $n): ?>
  <div class="list-group-item<?= $n['is_read'] ? '' : ' list-group-item-primary' ?>">
    <div class="d-flex justify-content-between gap-2">
      <strong><?= e($n['title']) ?></strong><small class="text-muted text-nowrap"><?= e(time_ago($n['created_at'])) ?></small>
    </div>
    <div class="small"><?= e($n['message']) ?></div>
    <div class="d-flex gap-2 mt-1">
      <?php if ($n['link'] && preg_match('#^(student|driver|admin)/[a-z\-]+\.php$#', $n['link'])): ?>
        <a class="small" href="<?= e(url($n['link'])) ?>">Open</a>
      <?php endif; ?>
      <?php if (!$n['is_read']): ?>
      <form method="post" class="m-0"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $n['id'] ?>"><button class="btn btn-link btn-sm p-0 small">Mark read</button></form>
      <?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?= pagination_links($p) ?>
<?php
    page_footer();
}
