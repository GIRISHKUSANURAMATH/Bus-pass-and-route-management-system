<?php
/**
 * General helpers: escaping, URLs, redirects, flash messages, CSRF,
 * JSON responses, validation and formatting.
 */
declare(strict_types=1);

/** HTML-escape for output (XSS protection). Use for EVERY dynamic value. */
function e(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * URL path of the /public folder, auto-detected, e.g.
 *   /SKSVMACET-Bus-Pass-Management/public   (XAMPP htdocs)
 *   ''                                      (document root = public/)
 */
function base_path(): string
{
    static $base = null;
    if ($base !== null) {
        return $base;
    }
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $pos = strpos($script, '/public/');
    if ($pos !== false) {
        $base = substr($script, 0, $pos + 7);
    } else {
        // Document root is the public folder itself: SCRIPT_FILENAME tells us how deep we are.
        $file = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_FILENAME'] ?? ''));
        $pub  = str_replace('\\', '/', PUBLIC_PATH);
        $rel  = str_starts_with($file, $pub) ? substr($file, strlen($pub)) : $script;
        $base = rtrim(substr($script, 0, strlen($script) - strlen($rel)), '/');
    }
    return $base;
}

function url(string $path = ''): string
{
    return base_path() . '/' . ltrim($path, '/');
}

/** Absolute URL (for QR codes). Uses APP_URL when configured. */
function absolute_url(string $path = ''): string
{
    $base = config('app.url');
    if ($base === '') {
        $host = preg_replace('/[^A-Za-z0-9.\-:\[\]]/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
        $base = (is_https() ? 'https://' : 'http://') . $host . base_path();
    }
    return $base . '/' . ltrim($path, '/');
}

function asset(string $path): string
{
    $file = PUBLIC_PATH . '/assets/' . ltrim($path, '/');
    $v = is_file($file) ? '?v=' . filemtime($file) : '';
    return url('assets/' . ltrim($path, '/')) . $v;
}

function redirect(string $path): never
{
    $target = preg_match('#^https?://#', $path) ? $path : (str_starts_with($path, '/') ? $path : url($path));
    header('Location: ' . $target, true, 302);
    exit;
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function client_ip(): string
{
    // REMOTE_ADDR only - X-Forwarded-For is attacker-controlled unless behind a trusted proxy.
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
}

// ---------------------------------------------------------------------
// Flash messages + old input
// ---------------------------------------------------------------------
function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function take_flashes(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

function remember_input(array $data): void
{
    unset($data['password'], $data['confirm_password'], $data['current_password'], $data['csrf_token']);
    $_SESSION['old'] = $data;
}

function old(string $key, string $default = ''): string
{
    return (string) ($_SESSION['old'][$key] ?? $default);
}

function forget_input(): void
{
    unset($_SESSION['old']);
}

// ---------------------------------------------------------------------
// CSRF
// ---------------------------------------------------------------------
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function csrf_valid(): bool
{
    $sent = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    return is_string($sent) && $sent !== '' && hash_equals(csrf_token(), $sent);
}

/** Call at the top of every POST handler. */
function require_csrf(): void
{
    if (!csrf_valid()) {
        audit_log('csrf_rejected', null, null, ['path' => $_SERVER['SCRIPT_NAME'] ?? '']);
        if (defined('API_REQUEST')) {
            json_response(['ok' => false, 'error' => 'Security token expired. Reload the page.'], 419);
        }
        http_response_code(419);
        render_error_page(419, 'Page expired', 'Your security token expired. Go back, reload the page and try again.');
    }
}

// ---------------------------------------------------------------------
// JSON
// ---------------------------------------------------------------------
function json_response(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** Read a JSON request body (APIs). */
function json_input(): array
{
    $raw = file_get_contents('php://input', false, null, 0, 8192);
    $data = json_decode((string) $raw, true);
    return is_array($data) ? $data : [];
}

/** Safe JSON for embedding in <script type="application/json"> blocks. */
function json_for_html(mixed $data): string
{
    return json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
}

// ---------------------------------------------------------------------
// Input + validation
// ---------------------------------------------------------------------
function input(string $key, string $default = ''): string
{
    $v = $_POST[$key] ?? $_GET[$key] ?? $default;
    return is_string($v) ? trim($v) : $default;
}

function input_int(string $key): ?int
{
    $v = filter_var($_POST[$key] ?? $_GET[$key] ?? null, FILTER_VALIDATE_INT);
    return $v === false || $v === null ? null : (int) $v;
}

function valid_email(string $v): bool
{
    return strlen($v) <= 150 && filter_var($v, FILTER_VALIDATE_EMAIL) !== false;
}

function valid_phone(string $v): bool
{
    return (bool) preg_match('/^[6-9]\d{9}$/', $v);       // Indian mobile number
}

/**
 * USN: accepts the VTU pattern (e.g. 2KA24CS001) AND other college formats.
 * Rule: 6-20 chars, letters/digits only, must contain both a letter and a digit.
 */
function normalize_usn(string $v): string
{
    return strtoupper(preg_replace('/\s+/', '', $v));
}

function valid_usn(string $v): bool
{
    return (bool) preg_match('/^(?=.*[A-Z])(?=.*\d)[A-Z0-9]{6,20}$/', $v);
}

function is_vtu_usn(string $v): bool
{
    return (bool) preg_match('/^[1-4][A-Z]{2}\d{2}[A-Z]{2,3}\d{3}$/', $v);
}

function valid_academic_year(string $v): bool
{
    if (!preg_match('/^(\d{4})-(\d{4})$/', $v, $m)) {
        return false;
    }
    return (int) $m[2] === (int) $m[1] + 1 && (int) $m[1] >= 2020 && (int) $m[1] <= 2100;
}

/** Password policy: 8-72 chars (bcrypt limit), letter + digit. */
function password_problem(string $p): ?string
{
    if (strlen($p) < 8) {
        return 'Password must be at least 8 characters.';
    }
    if (strlen($p) > 72) {
        return 'Password must be at most 72 characters.';
    }
    if (!preg_match('/[A-Za-z]/', $p) || !preg_match('/\d/', $p)) {
        return 'Password must contain at least one letter and one number.';
    }
    return null;
}

function valid_date(string $v): bool
{
    $d = DateTime::createFromFormat('Y-m-d', $v);
    return $d !== false && $d->format('Y-m-d') === $v;
}

function valid_lat(mixed $v): bool
{
    return is_numeric($v) && (float) $v >= -90 && (float) $v <= 90;
}

function valid_lng(mixed $v): bool
{
    return is_numeric($v) && (float) $v >= -180 && (float) $v <= 180;
}

// ---------------------------------------------------------------------
// Formatting
// ---------------------------------------------------------------------
function money(float|string|null $v): string
{
    return '₹' . number_format((float) $v, 2);
}

function fmt_date(?string $v, string $format = 'd-m-Y'): string
{
    return $v ? date($format, strtotime($v)) : '—';
}

function fmt_datetime(?string $v): string
{
    return $v ? date('d-m-Y h:i A', strtotime($v)) : '—';
}

function time_ago(?string $datetime): string
{
    if (!$datetime) {
        return 'never';
    }
    $s = max(0, time() - strtotime($datetime));
    return match (true) {
        $s < 60    => $s . ' seconds ago',
        $s < 3600  => intdiv($s, 60) . ' minute(s) ago',
        $s < 86400 => intdiv($s, 3600) . ' hour(s) ago',
        default    => intdiv($s, 86400) . ' day(s) ago',
    };
}

const STATUS_LABELS = [
    // applications
    'payment_pending'   => ['Payment Pending', 'warning'],
    'payment_submitted' => ['Payment Verification Pending', 'info'],
    'pending_approval'  => ['Pending Approval', 'primary'],
    'approved'          => ['Approved', 'success'],
    'rejected'          => ['Rejected', 'danger'],
    'cancelled'         => ['Cancelled', 'secondary'],
    // payments
    'pending'           => ['Pending', 'warning'],
    'submitted'         => ['Submitted – Pending Verification', 'info'],
    'verified'          => ['Verified', 'success'],
    // passes
    'active'            => ['Active', 'success'],
    'expired'           => ['Expired', 'secondary'],
    'revoked'           => ['Revoked', 'danger'],
    // misc
    'inactive'          => ['Inactive', 'secondary'],
    'maintenance'       => ['Maintenance', 'warning'],
    'completed'         => ['Completed', 'secondary'],
    'scheduled'         => ['Scheduled', 'info'],
];

function status_badge(?string $status): string
{
    [$label, $color] = STATUS_LABELS[$status ?? ''] ?? [ucwords(str_replace('_', ' ', (string) $status)), 'secondary'];
    return '<span class="badge text-bg-' . $color . '">' . e($label) . '</span>';
}

function duration_label(string $d): string
{
    return match ($d) {
        'monthly' => '1 Month',
        'annual'  => 'Annual (12 months)',
        default   => 'Semester (6 months)',
    };
}

function year_label(int $y): string
{
    return [1 => '1st Year', 2 => '2nd Year', 3 => '3rd Year', 4 => '4th Year'][$y] ?? $y . ' Year';
}

const BRANCHES = ['CSE', 'ISE', 'ECE', 'EEE', 'Mechanical', 'Civil', 'AI/ML', 'Other'];

/** Academic years offered in drop-downs: previous, current, next. */
function academic_year_options(): array
{
    $y = (int) date('Y');
    $startNow = (int) date('n') >= 7 ? $y : $y - 1;   // Indian academic year starts ~July/Aug
    $out = [];
    for ($s = $startNow - 1; $s <= $startNow + 1; $s++) {
        $out[] = $s . '-' . ($s + 1);
    }
    return $out;
}

function current_academic_year(): string
{
    return academic_year_options()[1];
}

/** Simple pagination helper. */
function paginate(int $total, int $perPage = 20): array
{
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $pages = max(1, (int) ceil($total / $perPage));
    $page = min($page, $pages);
    return ['page' => $page, 'pages' => $pages, 'limit' => $perPage, 'offset' => ($page - 1) * $perPage, 'total' => $total];
}

function pagination_links(array $p): string
{
    if ($p['pages'] <= 1) {
        return '';
    }
    $q = $_GET;
    $html = '<nav><ul class="pagination pagination-sm">';
    for ($i = 1; $i <= $p['pages']; $i++) {
        $q['page'] = $i;
        $html .= '<li class="page-item' . ($i === $p['page'] ? ' active' : '') . '"><a class="page-link" href="?'
            . e(http_build_query($q)) . '">' . $i . '</a></li>';
    }
    return $html . '</ul></nav>';
}
