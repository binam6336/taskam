<?php
/**
 * =============================================================
 * API v1 — Bootstrap
 * =============================================================
 * هسته مشترک همه endpoint ها:
 *   - اتصال دیتابیس
 *   - احراز هویت (توکن یا session)
 *   - توابع پاسخ JSON
 *   - توابع کمکی
 * =============================================================
 */

while (ob_get_level() > 0) { @ob_end_clean(); }

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, X-API-Key, Content-Type, Accept');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// مسیر روت پروژه (سه سطح بالاتر از /api/v1/tasks/xxx/)
$ROOT_PATH = dirname(__DIR__, 2);

// ================== توابع پاسخ ==================
function apiResponse(array $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function apiError(string $message, int $code = 400, string $errorCode = ''): void {
    $res = [
        'success' => false,
        'status'  => 'error',
        'message' => $message,
    ];
    if ($errorCode !== '') $res['error_code'] = $errorCode;
    apiResponse($res, $code);
}

function apiSuccess($data = null, string $message = ''): void {
    $res = ['success' => true, 'status' => 'success'];
    if ($message !== '') $res['message'] = $message;
    if ($data !== null) $res['data'] = $data;
    apiResponse($res, 200);
}

// ================== بدنه درخواست ==================
function getRequestBody(): array {
    static $cached = null;
    if ($cached !== null) return $cached;
    $cached = [];

    $ct = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
    if (stripos($ct, 'application/json') !== false) {
        $raw = file_get_contents('php://input');
        if ($raw !== false && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) { $cached = $decoded; return $cached; }
        }
    }
    if (!empty($_POST)) { $cached = $_POST; return $cached; }

    $raw = @file_get_contents('php://input');
    if ($raw !== false && $raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) { $cached = $decoded; return $cached; }
        parse_str($raw, $parsed);
        if (is_array($parsed) && !empty($parsed)) { $cached = $parsed; return $cached; }
    }
    return $cached;
}

// ================== هدر Authorization ==================
function getAuthorizationHeader(): ?string {
    if (!empty($_SERVER['HTTP_AUTHORIZATION'])) return trim($_SERVER['HTTP_AUTHORIZATION']);
    if (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) return trim($_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
    if (function_exists('getallheaders')) {
        foreach (getallheaders() as $k => $v) {
            if (strcasecmp($k, 'Authorization') === 0) return trim((string)$v);
        }
    }
    if (function_exists('apache_request_headers')) {
        foreach (apache_request_headers() as $k => $v) {
            if (strcasecmp($k, 'Authorization') === 0) return trim((string)$v);
        }
    }
    return null;
}

// ================== بارگذاری سیستمی ==================
$configPath   = $ROOT_PATH . '/config/config.php';
$databasePath = $ROOT_PATH . '/database/Database.php';
$authPath     = $ROOT_PATH . '/core/Auth.php';

if (!file_exists($configPath))   apiError('فایل config یافت نشد.', 500, 'CONFIG_MISSING');
if (!file_exists($databasePath)) apiError('فایل Database یافت نشد.', 500, 'DATABASE_FILE_MISSING');

try {
    require_once $configPath;
    require_once $databasePath;
    if (file_exists($authPath)) require_once $authPath;
} catch (Throwable $e) {
    error_log('[API] Bootstrap error: ' . $e->getMessage());
    apiError('خطا در بارگذاری فایل‌های سیستمی.', 500, 'BOOTSTRAP_ERROR');
}

if (!class_exists('Database')) apiError('کلاس Database تعریف نشده است.', 500, 'DB_CLASS_MISSING');

try {
    $db = Database::getInstance();
    $db->exec("SET NAMES 'utf8mb4'");
} catch (Throwable $e) {
    error_log('[API] DB error: ' . $e->getMessage());
    apiError('خطا در اتصال به پایگاه داده.', 500, 'DB_CONNECTION_FAILED');
}

// ================== احراز هویت ==================
$userId = null;
$authUser = null;
$authMethod = null;

$token = null;
$authHeader = getAuthorizationHeader();

if (!empty($authHeader)) {
    if (preg_match('/Bearer\s+(.+)/i', $authHeader, $m)) $token = trim($m[1]);
    else $token = trim($authHeader);
}

if (empty($token) && !empty($_SERVER['HTTP_X_API_KEY']))   $token = trim((string)$_SERVER['HTTP_X_API_KEY']);
if (empty($token) && !empty($_GET['token']))               $token = trim((string)$_GET['token']);

$body = getRequestBody();
if (empty($token) && !empty($body['token']))               $token = trim((string)$body['token']);

if (!empty($token)) {
    try {
        $chk = $db->query("SHOW TABLES LIKE 'api_tokens'");
        if ($chk->rowCount() === 0) apiError('جدول توکن‌ها ساخته نشده است.', 500, 'TOKENS_TABLE_MISSING');

        if (strlen($token) < 20 || strlen($token) > 128) {
            apiError('فرمت توکن نامعتبر است.', 401, 'TOKEN_INVALID_FORMAT');
        }

        $stmt = $db->prepare("
            SELECT t.id AS token_id, t.user_id, u.status, u.role, u.mobile, u.first_name, u.last_name, u.email
            FROM api_tokens t
            INNER JOIN users u ON u.id = t.user_id
            WHERE t.token = ? AND t.is_active = 1
            LIMIT 1
        ");
        $stmt->execute([$token]);
        $authUser = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($authUser) {
            if (($authUser['status'] ?? '') !== 'active') apiError('حساب کاربری فعال نیست.', 403, 'ACCOUNT_INACTIVE');
            $userId = (int)$authUser['user_id'];
            $authMethod = 'token';

            try {
                $ip = $_SERVER['REMOTE_ADDR'] ?? null;
                $db->prepare("UPDATE api_tokens SET last_used_at = NOW(), last_ip = ? WHERE id = ?")
                   ->execute([$ip, (int)$authUser['token_id']]);
            } catch (Throwable $e) {}
        } else {
            apiError('توکن نامعتبر یا غیرفعال است.', 401, 'TOKEN_INVALID');
        }
    } catch (Throwable $e) {
        error_log('[API] token error: ' . $e->getMessage());
        apiError('خطا در بررسی توکن.', 500);
    }
}

// fallback: session
if ($userId === null) {
    if (class_exists('Auth') && method_exists('Auth', 'check') && Auth::check()) {
        $userId = (int)$_SESSION['user_id'];
        $authMethod = 'session';
        $authUser = [
            'id' => $userId,
            'mobile' => $_SESSION['user_mobile'] ?? null,
            'first_name' => null,
            'last_name' => null,
            'email' => null,
            'role' => $_SESSION['user_role'] ?? 'user',
        ];
    }
}

if ($userId === null) {
    apiError('دسترسی غیرمجاز. توکن ارسال کنید یا وارد شوید.', 401, 'UNAUTHORIZED');
}

// ================== توابع کمکی ==================
function getTaskPermission(PDO $db, int $taskId, int $userId, string $action): array {
    try {
        $stmt = $db->prepare("SELECT * FROM tasks WHERE id = ? AND (user_id = ? OR assignee_id = ?) LIMIT 1");
        $stmt->execute([$taskId, $userId, $userId]);
        $task = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$task) return ['allowed' => false, 'task' => null, 'is_owner' => false];
        if ((int)$task['user_id'] === $userId) return ['allowed' => true, 'task' => $task, 'is_owner' => true];

        $p = $db->prepare("SELECT permissions FROM colleagues WHERE user_id = ? AND colleague_user_id = ? LIMIT 1");
        $p->execute([(int)$task['user_id'], $userId]);
        $pr = $p->fetch(PDO::FETCH_ASSOC);
        if (!$pr) return ['allowed' => false, 'task' => $task, 'is_owner' => false];

        $perms = json_decode($pr['permissions'] ?? '[]', true);
        if (!is_array($perms)) $perms = [];

        return ['allowed' => in_array($action, $perms, true), 'task' => $task, 'is_owner' => false];
    } catch (Throwable $e) {
        return ['allowed' => false, 'task' => null, 'is_owner' => false];
    }
}

function isAssigneeAllowed(PDO $db, int $userId, int $assigneeId, ?int $projectId): bool {
    if ($assigneeId === $userId) return true;
    try {
        $c = $db->prepare("SELECT 1 FROM colleagues WHERE user_id = ? AND colleague_user_id = ? LIMIT 1");
        $c->execute([$userId, $assigneeId]);
        if ($c->fetchColumn()) return true;

        if ($projectId !== null) {
            $c = $db->prepare("SELECT 1 FROM project_members WHERE project_id = ? AND user_id = ? LIMIT 1");
            $c->execute([$projectId, $assigneeId]);
            if ($c->fetchColumn()) return true;
        }
    } catch (Throwable $e) {}
    return false;
}

function requireMethod(string $method): void {
    $current = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if ($current !== strtoupper($method)) {
        apiError("این Endpoint فقط با متد $method قابل استفاده است.", 405, 'METHOD_NOT_ALLOWED');
    }
}