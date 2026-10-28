<?php
/**
 * Page layout (header / sidebar / footer) shared by every panel.
 * No inline JavaScript anywhere - the Content-Security-Policy only allows
 * scripts from our own /assets folder.
 */
declare(strict_types=1);

const NAV = [
    'student' => [
        ['dashboard.php', 'Dashboard', 'speedometer2'],
        ['apply.php', 'Apply / Renew Pass', 'file-earmark-plus'],
        ['applications.php', 'My Applications & Payments', 'receipt'],
        ['pass.php', 'My Bus Pass', 'person-badge'],
        ['track-bus.php', 'Track Bus', 'geo-alt'],
        ['route.php', 'My Route & Stops', 'signpost-split'],
        ['notifications.php', 'Notifications', 'bell'],
        ['profile.php', 'Profile', 'person-circle'],
    ],
    'driver' => [
        ['dashboard.php', 'Dashboard', 'speedometer2'],
        ['live-tracking.php', 'Live Tracking / Trip', 'broadcast'],
        ['route.php', 'Route & Stops', 'signpost-split'],
        ['passengers.php', 'Authorized Passengers', 'people'],
        ['notifications.php', 'Notifications', 'bell'],
        ['profile.php', 'Profile', 'person-circle'],
    ],
    'admin' => [
        ['dashboard.php', 'Dashboard', 'speedometer2'],
        ['applications.php', 'Applications', 'file-earmark-text'],
        ['payments.php', 'Payment Verification', 'cash-coin'],
        ['passes.php', 'Bus Passes', 'person-badge'],
        ['students.php', 'Students', 'mortarboard'],
        ['drivers.php', 'Drivers', 'person-vcard'],
        ['buses.php', 'Buses', 'bus-front'],
        ['routes.php', 'Routes', 'signpost-split'],
        ['stops.php', 'Bus Stops', 'pin-map'],
        ['fees.php', 'Pass Fees', 'currency-rupee'],
        ['trips.php', 'Trips', 'clock-history'],
        ['live-map.php', 'Live Buses', 'broadcast'],
        ['reports.php', 'Reports', 'bar-chart'],
        ['audit-logs.php', 'Audit Logs', 'shield-check'],
        ['settings.php', 'Settings & Payment QR', 'gear'],
    ],
];

function render_head(string $title, array $styles = []): void
{
    ?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<meta name="base-url" content="<?= e(base_path()) ?>">
<title><?= e($title) ?> · <?= e(college_name_safe()) ?> Bus Pass</title>
<link rel="icon" href="<?= e(asset('images/favicon.svg')) ?>" type="image/svg+xml">
<link rel="stylesheet" href="<?= e(asset('vendor/bootstrap/bootstrap.min.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('vendor/bootstrap-icons/bootstrap-icons.min.css')) ?>">
<?php foreach ($styles as $s): ?>
<link rel="stylesheet" href="<?= e(asset($s)) ?>">
<?php endforeach; ?>
<link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<?php
}

/** college_name() needs the DB; the error page must work without it. */
function college_name_safe(): string
{
    try {
        return college_name();
    } catch (Throwable) {
        return config('app.college');
    }
}

function render_flashes(): void
{
    foreach (take_flashes() as $f) {
        $type = in_array($f['type'], ['success', 'danger', 'warning', 'info'], true) ? $f['type'] : 'info';
        echo '<div class="alert alert-' . $type . ' alert-dismissible fade show" role="alert">' . e($f['message'])
            . '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div>';
    }
}

/**
 * Panel page header. $active = file name of the current page.
 * $styles = extra CSS files under /assets (e.g. Leaflet).
 */
function page_header(string $title, string $active = '', array $styles = []): void
{
    $user = current_user();
    $role = $user['role'] ?? null;
    render_head($title, $styles);
    $unread = $user ? unread_notifications((int) $user['id']) : 0;
    ?>
<body class="panel role-<?= e($role ?? 'guest') ?>">
<nav class="navbar navbar-dark topbar sticky-top">
  <div class="container-fluid">
    <div class="d-flex align-items-center gap-2">
      <?php if ($role): ?>
      <button class="btn btn-sm btn-outline-light d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#sidebar" aria-controls="sidebar" aria-label="Menu"><i class="bi bi-list"></i></button>
      <?php endif; ?>
      <a class="navbar-brand d-flex align-items-center gap-2" href="<?= e($role ? dashboard_url($role) : url('index.php')) ?>">
        <span class="brand-mark"><i class="bi bi-bus-front-fill"></i></span>
        <span><strong><?= e(college_name()) ?></strong> <span class="d-none d-sm-inline fw-light">Bus Pass</span></span>
      </a>
    </div>
    <?php if ($user): ?>
    <div class="d-flex align-items-center gap-2">
      <a class="btn btn-sm btn-outline-light position-relative" href="<?= e(url($role . '/notifications.php')) ?>" aria-label="Notifications">
        <i class="bi bi-bell"></i>
        <?php if ($unread > 0): ?><span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger"><?= $unread ?></span><?php endif; ?>
      </a>
      <span class="text-white-50 small d-none d-md-inline"><?= e($user['full_name']) ?> · <?= e(ucfirst($role)) ?></span>
      <form method="post" action="<?= e(url('logout.php')) ?>" class="m-0">
        <?= csrf_field() ?>
        <button class="btn btn-sm btn-light" type="submit"><i class="bi bi-box-arrow-right"></i><span class="d-none d-sm-inline"> Logout</span></button>
      </form>
    </div>
    <?php endif; ?>
  </div>
</nav>
<div class="d-flex">
  <?php if ($role): ?>
  <aside class="sidebar offcanvas-lg offcanvas-start" tabindex="-1" id="sidebar" aria-labelledby="sidebarLabel">
    <div class="offcanvas-header d-lg-none">
      <h5 class="offcanvas-title" id="sidebarLabel"><?= e(ucfirst($role)) ?> menu</h5>
      <button type="button" class="btn-close" data-bs-dismiss="offcanvas" data-bs-target="#sidebar" aria-label="Close"></button>
    </div>
    <div class="offcanvas-body flex-column p-0">
      <div class="sidebar-role"><?= e(ucfirst($role)) ?> Panel</div>
      <ul class="nav flex-column">
        <?php foreach (NAV[$role] as [$file, $label, $icon]): ?>
        <li class="nav-item"><a class="nav-link<?= $file === $active ? ' active' : '' ?>" href="<?= e(url($role . '/' . $file)) ?>"><i class="bi bi-<?= e($icon) ?>"></i> <?= e($label) ?></a></li>
        <?php endforeach; ?>
      </ul>
    </div>
  </aside>
  <?php endif; ?>
  <main class="content flex-grow-1">
    <div class="container-fluid py-4 px-3 px-md-4">
      <h1 class="page-title"><?= e($title) ?></h1>
      <?php render_flashes(); ?>
<?php
}

/** $scripts = extra JS files under /assets, loaded after Bootstrap. */
function page_footer(array $scripts = []): void
{
    ?>
    </div>
    <footer class="app-footer">© <?= date('Y') ?> <?= e(college_name()) ?> · Bus Pass &amp; Route Management System</footer>
  </main>
</div>
<script src="<?= e(asset('vendor/bootstrap/bootstrap.bundle.min.js')) ?>"></script>
<script src="<?= e(asset('js/app.js')) ?>"></script>
<?php foreach ($scripts as $s): ?>
<script src="<?= e(asset($s)) ?>"></script>
<?php endforeach; ?>
</body>
</html>
<?php
}

/** Minimal standalone layout for login/register/verify (no sidebar). */
function guest_header(string $title, array $styles = []): void
{
    render_head($title, $styles);
    echo '<body class="guest"><main class="guest-main">';
}

function guest_footer(array $scripts = []): void
{
    ?>
</main>
<script src="<?= e(asset('vendor/bootstrap/bootstrap.bundle.min.js')) ?>"></script>
<script src="<?= e(asset('js/app.js')) ?>"></script>
<?php foreach ($scripts as $s): ?>
<script src="<?= e(asset($s)) ?>"></script>
<?php endforeach; ?>
</body>
</html>
<?php
}

function render_error_page(int $code, string $title, string $message): never
{
    if (!headers_sent()) {
        http_response_code($code);
    }
    render_head($title);
    ?>
<body class="guest"><main class="guest-main">
  <div class="card shadow-sm guest-card text-center">
    <div class="card-body p-4">
      <div class="display-5 fw-bold text-primary"><?= $code ?></div>
      <h1 class="h4 mt-2"><?= e($title) ?></h1>
      <p class="text-muted"><?= e($message) ?></p>
      <a class="btn btn-primary" href="<?= e(url('index.php')) ?>">Go to home page</a>
    </div>
  </div>
</main></body></html>
<?php
    exit;
}

/** Card used on dashboards. */
function stat_card(string $label, string|int $value, string $icon, string $color = 'primary', ?string $href = null): string
{
    $inner = '<div class="stat-card border-' . e($color) . '"><div class="stat-icon text-' . e($color) . '"><i class="bi bi-'
        . e($icon) . '"></i></div><div><div class="stat-value">' . e((string) $value) . '</div><div class="stat-label">'
        . e($label) . '</div></div></div>';
    return $href ? '<a class="text-decoration-none text-reset" href="' . e($href) . '">' . $inner . '</a>' : $inner;
}
