<?php
/**
 * =============================================================
 * API — Tasks Endpoint
 * =============================================================
 * 
 * این فایل هم با Session کار می‌کنه، هم با Bearer Token
 * 
 * احراز هویت (به ترتیب اولویت):
 *   ۱. هدر Authorization: Bearer tk_xxxxx      (برای Postman/External)
 *   ۲. هدر X-API-Key: tk_xxxxx
 *   ۳. Query: ?token=tk_xxxxx
 *   ۴. Session (Cookie)                        (برای مرورگر/UI داخلی)
 * 
 * Endpoints:
 *   GET  ?action=me                    اطلاعات کاربر جاری
 *   GET  ?action=list                  لیست تسک‌ها
 *   GET  ?action=show&id=5             جزئیات یه تسک
 *   GET  ?action=stats                 آمار تسک‌ها
 *   GET  (بدون action)                 لیست تسک‌ها (سازگاری با کد قدیمی)
 *   POST ?action=create                ایجاد تسک
 *   POST ?action=update&id=5           ویرایش تسک
 *   POST ?action=delete&id=5           حذف تسک
 *   POST ?action=toggle&id=5           تغییر وضعیت
 *   POST {action: toggle, task_id: 5}  سازگاری با کد قدیمی (JSON body)
 * =============================================================
 */

// ================== تنظیمات اولیه ==================
if (ob_get_level()) {
    @ob_end_clean();
}

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, X-API-Key, Content-Type, Accept');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ================== توابع پاسخ ==================
function apiResponse(array $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function apiError(string $message, int $code = 400, string $errorCode = ''): void {
    $res = ['success' => false, 'message' => $message];
    if ($errorCode !== '') $res['error_code'] = $errorCode;
    // برای سازگاری، هم `success` هم `status` می‌فرستیم
    $res['status'] = 'error';
    apiResponse($res, $code);
}

function apiSuccess($data = null, string $message = ''): void {
    $res = ['success' => true, 'status' => 'success'];
    if ($message !== '') $res['message'] = $message;
    if ($data !== null) $res['data'] = $data;
    apiResponse($res, 200);
}

// ================== خواندن بدنه درخواست ==================
function getRequestBody(): array {
    static $cached = null;
    if ($cached !== null) return $cached;
    $cached = [];

    $contentType = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
    if (stripos($contentType, 'application/json') !== false) {
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

// ================== گرفتن هدر Authorization ==================
function getAuthorizationHeader(): ?string {
    if (!empty($_SERVER['HTTP_AUTHORIZATION'])) return trim($_SERVER['HTTP_AUTHORIZATION']);
    if (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) return trim($_SERVER['REDIRECT_HTTP_AUTHORIZATION']);

    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        if (is_array($headers)) {
            foreach ($headers as $key => $value) {
                if (strcasecmp($key, 'Authorization') === 0) return trim((string)$value);
            }
        }
    }
    if (function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
        if (is_array($headers)) {
            foreach ($headers as $key => $value) {
                if (strcasecmp($key, 'Authorization') === 0) return trim((string)$value);
            }
        }
    }
    return null;
}

// ================== بارگذاری فایل‌ها ==================
$configPath = __DIR__ . '/../config/config.php';
$databasePath = __DIR__ . '/../database/Database.php';
$authPath = __DIR__ . '/../core/Auth.php';

if (!file_exists($configPath)) apiError('فایل config یافت نشد.', 500, 'CONFIG_MISSING');
if (!file_exists($databasePath)) apiError('فایل Database یافت نشد.', 500, 'DATABASE_FILE_MISSING');

try {
    require_once $configPath;
    require_once $databasePath;
    if (file_exists($authPath)) require_once $authPath;
} catch (Throwable $e) {
    error_log('[API] Bootstrap error: ' . $e->getMessage());
    apiError('خطا در بارگذاری فایل‌های سیستمی.', 500, 'BOOTSTRAP_ERROR');
}

// ================== دیتابیس ==================
if (!class_exists('Database')) apiError('کلاس Database تعریف نشده است.', 500, 'DB_CLASS_MISSING');

try {
    $db = Database::getInstance();
    $db->exec("SET NAMES 'utf8mb4'");
} catch (Throwable $e) {
    error_log('[API] DB connection error: ' . $e->getMessage());
    apiError('خطا در اتصال به پایگاه داده.', 500, 'DB_CONNECTION_FAILED');
}

// ================== احراز هویت ==================
$userId = null;
$authUser = null;
$authMethod = null; // 'token' یا 'session'

// -------- روش ۱: توکن از هدر Authorization --------
$token = null;
$authHeader = getAuthorizationHeader();

if ($authHeader !== null && $authHeader !== '') {
    if (preg_match('/Bearer\s+(.+)/i', $authHeader, $m)) {
        $token = trim($m[1]);
    } else {
        $token = $authHeader;
    }
}

// -------- روش ۲: توکن از X-API-Key --------
if (empty($token) && !empty($_SERVER['HTTP_X_API_KEY'])) {
    $token = trim((string)$_SERVER['HTTP_X_API_KEY']);
}

// -------- روش ۳: توکن از query --------
if (empty($token) && !empty($_GET['token'])) {
    $token = trim((string)$_GET['token']);
}

// -------- روش ۴: توکن از body --------
$body = getRequestBody();
if (empty($token) && !empty($body['token'])) {
    $token = trim((string)$body['token']);
}

// اگه توکن داشتیم، از دیتابیس چک کن
if (!empty($token)) {
    // چک کن جدول api_tokens وجود داره یا نه
    try {
        $checkTable = $db->query("SHOW TABLES LIKE 'api_tokens'");
        if ($checkTable->rowCount() > 0) {
            if (strlen($token) >= 20 && strlen($token) <= 128) {
                $stmt = $db->prepare("
                    SELECT 
                        t.id AS token_id,
                        t.user_id,
                        u.status,
                        u.role,
                        u.mobile,
                        u.first_name,
                        u.last_name,
                        u.email
                    FROM api_tokens t
                    INNER JOIN users u ON u.id = t.user_id
                    WHERE t.token = ? AND t.is_active = 1
                    LIMIT 1
                ");
                $stmt->execute([$token]);
                $authUser = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($authUser) {
                    if ($authUser['status'] !== 'active') {
                        apiError('حساب کاربری فعال نیست.', 403, 'ACCOUNT_INACTIVE');
                    }
                    $userId = (int)$authUser['user_id'];
                    $authMethod = 'token';

                    // به‌روزرسانی last_used_at
                    try {
                        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
                        $db->prepare("UPDATE api_tokens SET last_used_at = NOW(), last_ip = ? WHERE id = ?")
                           ->execute([$ip, (int)$authUser['token_id']]);
                    } catch (Throwable $e) {}
                }
            }
        }
    } catch (Throwable $e) {
        error_log('[API] token lookup error: ' . $e->getMessage());
    }

    // اگه توکن نامعتبر بود، رد کن
    if ($userId === null) {
        apiError('توکن نامعتبر یا غیرفعال است.', 401, 'TOKEN_INVALID');
    }
}

// -------- روش ۵: Session (سازگاری با کد قدیمی و UI داخلی) --------
if ($userId === null) {
    // چک کن Auth class موجوده و کاربر لاگین هست
    if (class_exists('Auth') && method_exists('Auth', 'check') && Auth::check()) {
        $userId = (int)$_SESSION['user_id'];
        $authMethod = 'session';
    }
}

// اگه هیچ روشی جواب نداد، خطا
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

        $permStmt = $db->prepare("SELECT permissions FROM colleagues WHERE user_id = ? AND colleague_user_id = ? LIMIT 1");
        $permStmt->execute([(int)$task['user_id'], $userId]);
        $permRow = $permStmt->fetch(PDO::FETCH_ASSOC);

        if (!$permRow) return ['allowed' => false, 'task' => $task, 'is_owner' => false];

        $perms = json_decode($permRow['permissions'] ?? '[]', true);
        if (!is_array($perms)) $perms = [];

        return ['allowed' => in_array($action, $perms, true), 'task' => $task, 'is_owner' => false];
    } catch (Throwable $e) {
        return ['allowed' => false, 'task' => null, 'is_owner' => false];
    }
}

function isAssigneeAllowed(PDO $db, int $userId, int $assigneeId, ?int $projectId): bool {
    if ($assigneeId === $userId) return true;
    try {
        $chk = $db->prepare("SELECT 1 FROM colleagues WHERE user_id = ? AND colleague_user_id = ? LIMIT 1");
        $chk->execute([$userId, $assigneeId]);
        if ($chk->fetchColumn()) return true;

        if ($projectId !== null) {
            $chk = $db->prepare("SELECT 1 FROM project_members WHERE project_id = ? AND user_id = ? LIMIT 1");
            $chk->execute([$projectId, $assigneeId]);
            if ($chk->fetchColumn()) return true;
        }
    } catch (Throwable $e) {}
    return false;
}

// ================== مسیریابی ==================
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$action = $_GET['action'] ?? $_POST['action'] ?? ($body['action'] ?? '');
$action = trim((string)$action);

// سازگاری با کد قدیمی: اگه GET بدون action بود، برو به list
if ($action === '' && $method === 'GET') {
    $action = 'list';
}

if ($action === '') {
    apiError('پارامتر action ارسال نشده است.', 400, 'ACTION_MISSING');
}

// ============================================================
// ================== GET endpoints ===========================
// ============================================================

if ($action === 'me') {
    apiSuccess([
        'id'         => $userId,
        'mobile'     => $authUser['mobile'] ?? ($_SESSION['user_mobile'] ?? null),
        'first_name' => $authUser['first_name'] ?? null,
        'last_name'  => $authUser['last_name'] ?? null,
        'email'      => $authUser['email'] ?? null,
        'role'       => $authUser['role'] ?? ($_SESSION['user_role'] ?? 'user'),
        'auth_method' => $authMethod,
    ]);
}

if ($action === 'list') {
    $status = $_GET['status'] ?? 'all';
    $limit = min(200, max(1, (int)($_GET['limit'] ?? 100)));
    $offset = max(0, (int)($_GET['offset'] ?? 0));

    if (!in_array($status, ['all', 'pending', 'completed'], true)) $status = 'all';

    // چک کن ستون project_id وجود داره یا نه
    $hasProjectCol = true;
    try {
        $colCheck = $db->query("SHOW COLUMNS FROM tasks LIKE 'project_id'");
        if ($colCheck->rowCount() === 0) $hasProjectCol = false;
    } catch (Throwable $e) { $hasProjectCol = false; }

    $projectSelect = $hasProjectCol ? "t.project_id, p.title AS project_title," : "NULL AS project_id, NULL AS project_title,";
    $projectJoin = $hasProjectCol ? "LEFT JOIN projects p ON t.project_id = p.id" : "";

    $sql = "
        SELECT 
            t.id, t.title, t.description, t.priority, t.is_completed,
            t.due_date, t.created_at, t.completed_at,
            t.user_id, t.assignee_id, t.subject_id,
            $projectSelect
            s.title AS subject_title,
            ua.first_name AS assignee_first_name,
            ua.last_name AS assignee_last_name,
            ua.mobile AS assignee_mobile,
            uc.first_name AS creator_first_name,
            uc.last_name AS creator_last_name,
            (SELECT COUNT(*) FROM task_notes tn WHERE tn.task_id = t.id) AS notes_count
        FROM tasks t
        LEFT JOIN subjects s ON t.subject_id = s.id
        $projectJoin
        LEFT JOIN users ua ON ua.id = t.assignee_id
        LEFT JOIN users uc ON uc.id = t.user_id
        WHERE (t.user_id = ? OR t.assignee_id = ?)
    ";

    $params = [$userId, $userId];
    if ($status === 'pending') $sql .= " AND t.is_completed = 0";
    elseif ($status === 'completed') $sql .= " AND t.is_completed = 1";

    $sql .= " ORDER BY t.created_at DESC LIMIT " . (int)$limit . " OFFSET " . (int)$offset;

    try {
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($tasks as &$t) {
            $t['id'] = (int)$t['id'];
            $t['user_id'] = (int)$t['user_id'];
            $t['assignee_id'] = $t['assignee_id'] !== null ? (int)$t['assignee_id'] : null;
            $t['subject_id'] = $t['subject_id'] !== null ? (int)$t['subject_id'] : null;
            $t['project_id'] = $t['project_id'] !== null ? (int)$t['project_id'] : null;
            $t['is_completed'] = (bool)$t['is_completed'];
            $t['notes_count'] = (int)$t['notes_count'];
        }
        unset($t);

        // سازگاری با کد قدیمی: هم success/data می‌فرستیم هم status
        apiSuccess([
            'count' => count($tasks),
            'limit' => $limit,
            'offset' => $offset,
            'status' => $status,
            'tasks' => $tasks,
        ]);
    } catch (Throwable $e) {
        error_log('[API] list error: ' . $e->getMessage());
        apiError('خطا در واکشی تسک‌ها: ' . $e->getMessage(), 500);
    }
}

if ($action === 'show') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) apiError('شناسه تسک نامعتبر است.', 400, 'INVALID_ID');

    try {
        $stmt = $db->prepare("
            SELECT 
                t.*,
                s.title AS subject_title,
                p.title AS project_title,
                ua.first_name AS assignee_first_name,
                ua.last_name AS assignee_last_name,
                ua.mobile AS assignee_mobile,
                uc.first_name AS creator_first_name,
                uc.last_name AS creator_last_name,
                uc.mobile AS creator_mobile
            FROM tasks t
            LEFT JOIN subjects s ON t.subject_id = s.id
            LEFT JOIN projects p ON t.project_id = p.id
            LEFT JOIN users ua ON ua.id = t.assignee_id
            LEFT JOIN users uc ON uc.id = t.user_id
            WHERE t.id = ? AND (t.user_id = ? OR t.assignee_id = ?)
            LIMIT 1
        ");
        $stmt->execute([$id, $userId, $userId]);
        $task = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$task) apiError('تسک یافت نشد.', 404, 'TASK_NOT_FOUND');

        $task['id'] = (int)$task['id'];
        $task['user_id'] = (int)$task['user_id'];
        $task['assignee_id'] = $task['assignee_id'] !== null ? (int)$task['assignee_id'] : null;
        $task['subject_id'] = $task['subject_id'] !== null ? (int)$task['subject_id'] : null;
        $task['project_id'] = $task['project_id'] !== null ? (int)$task['project_id'] : null;
        $task['is_completed'] = (bool)$task['is_completed'];

        $nStmt = $db->prepare("
            SELECT tn.id, tn.user_id, tn.note, tn.created_at, u.first_name, u.last_name, u.mobile
            FROM task_notes tn
            LEFT JOIN users u ON u.id = tn.user_id
            WHERE tn.task_id = ?
            ORDER BY tn.created_at DESC
        ");
        $nStmt->execute([$id]);
        $task['notes'] = $nStmt->fetchAll(PDO::FETCH_ASSOC);

        apiSuccess($task);
    } catch (Throwable $e) {
        error_log('[API] show error: ' . $e->getMessage());
        apiError('خطا در واکشی تسک.', 500);
    }
}

if ($action === 'stats') {
    try {
        $stmt = $db->prepare("
            SELECT 
                COUNT(*) AS total,
                COALESCE(SUM(CASE WHEN is_completed = 1 THEN 1 ELSE 0 END), 0) AS completed,
                COALESCE(SUM(CASE WHEN is_completed = 0 THEN 1 ELSE 0 END), 0) AS pending,
                COALESCE(SUM(CASE WHEN priority = 'high' THEN 1 ELSE 0 END), 0) AS high,
                COALESCE(SUM(CASE WHEN priority = 'medium' THEN 1 ELSE 0 END), 0) AS medium,
                COALESCE(SUM(CASE WHEN priority = 'low' THEN 1 ELSE 0 END), 0) AS low
            FROM tasks
            WHERE assignee_id = ?
        ");
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        apiSuccess([
            'total' => (int)($row['total'] ?? 0),
            'completed' => (int)($row['completed'] ?? 0),
            'pending' => (int)($row['pending'] ?? 0),
            'high' => (int)($row['high'] ?? 0),
            'medium' => (int)($row['medium'] ?? 0),
            'low' => (int)($row['low'] ?? 0),
        ]);
    } catch (Throwable $e) {
        error_log('[API] stats error: ' . $e->getMessage());
        apiError('خطا در محاسبه آمار.', 500);
    }
}

// ============================================================
// ================== POST endpoints ==========================
// ============================================================

if ($method !== 'POST') {
    apiError('این اکشن فقط با POST قابل استفاده است.', 405, 'METHOD_NOT_ALLOWED');
}

$input = $body;

if ($action === 'create') {
    $title = trim((string)($input['title'] ?? ''));
    if ($title === '') apiError('عنوان وظیفه الزامی است.', 400, 'TITLE_REQUIRED');
    if (mb_strlen($title) > 255) apiError('عنوان وظیفه بسیار طولانی است.', 400, 'TITLE_TOO_LONG');

    $desc = trim((string)($input['description'] ?? ''));
    if (mb_strlen($desc) > 5000) apiError('توضیحات بسیار طولانی است.', 400, 'DESC_TOO_LONG');

    $priority = (string)($input['priority'] ?? 'medium');
    if (!in_array($priority, ['low', 'medium', 'high'], true)) $priority = 'medium';

    $subjectId = !empty($input['subject_id']) ? (int)$input['subject_id'] : null;
    $projectId = !empty($input['project_id']) ? (int)$input['project_id'] : null;
    $assigneeId = !empty($input['assignee_id']) ? (int)$input['assignee_id'] : $userId;

    $dueDate = null;
    if (!empty($input['due_date'])) {
        $d = (string)$input['due_date'];
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) $dueDate = $d;
    }

    if ($subjectId !== null) {
        try {
            $chk = $db->prepare("SELECT id FROM subjects WHERE id = ? AND user_id = ? LIMIT 1");
            $chk->execute([$subjectId, $userId]);
            if (!$chk->fetchColumn()) $subjectId = null;
        } catch (Throwable $e) { $subjectId = null; }
    }

    if ($projectId !== null) {
        try {
            $chk = $db->prepare("
                SELECT p.id FROM projects p
                WHERE p.id = ? AND (
                    p.user_id = ?
                    OR EXISTS (SELECT 1 FROM project_members pm WHERE pm.project_id = p.id AND pm.user_id = ?)
                ) LIMIT 1
            ");
            $chk->execute([$projectId, $userId, $userId]);
            if (!$chk->fetchColumn()) $projectId = null;
        } catch (Throwable $e) { $projectId = null; }
    }

    if (!isAssigneeAllowed($db, $userId, $assigneeId, $projectId)) {
        apiError('شما اجازه واگذاری تسک به این کاربر را ندارید.', 403, 'ASSIGNEE_NOT_ALLOWED');
    }

    try {
        $stmt = $db->prepare("
            INSERT INTO tasks (user_id, assignee_id, subject_id, project_id, title, description, priority, due_date)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$userId, $assigneeId, $subjectId, $projectId, $title, $desc, $priority, $dueDate]);
        $newId = (int)$db->lastInsertId();

        apiSuccess([
            'id' => $newId,
            'title' => $title,
            'priority' => $priority,
            'assignee_id' => $assigneeId,
            'project_id' => $projectId,
            'subject_id' => $subjectId,
            'due_date' => $dueDate,
            'created_at' => date('Y-m-d H:i:s'),
        ], 'وظیفه با موفقیت ایجاد شد.');
    } catch (Throwable $e) {
        error_log('[API] create error: ' . $e->getMessage());
        apiError('خطا در ایجاد تسک.', 500);
    }
}

if ($action === 'update') {
    $id = (int)($_GET['id'] ?? $input['id'] ?? 0);
    if ($id <= 0) apiError('شناسه تسک نامعتبر است.', 400, 'INVALID_ID');

    $perm = getTaskPermission($db, $id, $userId, 'edit');
    if (!$perm['task']) apiError('تسک یافت نشد.', 404, 'TASK_NOT_FOUND');
    if (!$perm['allowed'] && !$perm['is_owner']) apiError('دسترسی ندارید.', 403, 'FORBIDDEN');

    $title = trim((string)($input['title'] ?? $perm['task']['title']));
    if ($title === '') apiError('عنوان وظیفه الزامی است.', 400);

    $desc = trim((string)($input['description'] ?? $perm['task']['description'] ?? ''));
    $priority = (string)($input['priority'] ?? $perm['task']['priority'] ?? 'medium');
    if (!in_array($priority, ['low', 'medium', 'high'], true)) $priority = 'medium';

    $subjectId = array_key_exists('subject_id', $input)
        ? (!empty($input['subject_id']) ? (int)$input['subject_id'] : null)
        : ($perm['task']['subject_id'] !== null ? (int)$perm['task']['subject_id'] : null);

    $projectId = array_key_exists('project_id', $input)
        ? (!empty($input['project_id']) ? (int)$input['project_id'] : null)
        : ($perm['task']['project_id'] !== null ? (int)$perm['task']['project_id'] : null);

    $assigneeId = !empty($input['assignee_id'])
        ? (int)$input['assignee_id']
        : (int)($perm['task']['assignee_id'] ?? $userId);

    $dueDate = array_key_exists('due_date', $input)
        ? (!empty($input['due_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$input['due_date']) ? (string)$input['due_date'] : null)
        : ($perm['task']['due_date'] ?? null);

    if (!isAssigneeAllowed($db, $userId, $assigneeId, $projectId)) {
        apiError('شما اجازه واگذاری تسک به این کاربر را ندارید.', 403, 'ASSIGNEE_NOT_ALLOWED');
    }

    try {
        $stmt = $db->prepare("
            UPDATE tasks 
            SET subject_id = ?, project_id = ?, assignee_id = ?, title = ?, description = ?, priority = ?, due_date = ?
            WHERE id = ?
        ");
        $stmt->execute([$subjectId, $projectId, $assigneeId, $title, $desc, $priority, $dueDate, $id]);

        apiSuccess(['id' => $id], 'وظیفه با موفقیت ویرایش شد.');
    } catch (Throwable $e) {
        error_log('[API] update error: ' . $e->getMessage());
        apiError('خطا در ویرایش تسک.', 500);
    }
}

if ($action === 'delete') {
    $id = (int)($_GET['id'] ?? $input['id'] ?? 0);
    if ($id <= 0) apiError('شناسه تسک نامعتبر است.', 400, 'INVALID_ID');

    $perm = getTaskPermission($db, $id, $userId, 'delete');
    if (!$perm['task']) apiError('تسک یافت نشد.', 404, 'TASK_NOT_FOUND');
    if (!$perm['allowed'] && !$perm['is_owner']) apiError('دسترسی ندارید.', 403, 'FORBIDDEN');

    try {
        $db->prepare("DELETE FROM task_notes WHERE task_id = ?")->execute([$id]);
        $db->prepare("DELETE FROM tasks WHERE id = ?")->execute([$id]);
        apiSuccess(['id' => $id], 'وظیفه حذف شد.');
    } catch (Throwable $e) {
        error_log('[API] delete error: ' . $e->getMessage());
        apiError('خطا در حذف تسک.', 500);
    }
}

if ($action === 'toggle') {
    // سازگاری: هم می‌تونه از query/input['id'] بیاد هم از input['task_id']
    $id = (int)($_GET['id'] ?? $input['id'] ?? $input['task_id'] ?? 0);
    if ($id <= 0) apiError('شناسه تسک نامعتبر است.', 400, 'INVALID_ID');

    $perm = getTaskPermission($db, $id, $userId, 'complete');
    if (!$perm['task']) apiError('تسک یافت نشد.', 404, 'TASK_NOT_FOUND');
    if (!$perm['allowed'] && !$perm['is_owner']) apiError('دسترسی ندارید.', 403, 'FORBIDDEN');

    $current = (int)$perm['task']['is_completed'];
    try {
        if ($current === 1) {
            $db->prepare("UPDATE tasks SET is_completed = 0, completed_at = NULL WHERE id = ?")->execute([$id]);
            $newState = false;
        } else {
            $db->prepare("UPDATE tasks SET is_completed = 1, completed_at = NOW() WHERE id = ?")->execute([$id]);
            $newState = true;
        }
        apiSuccess(['id' => $id, 'is_completed' => $newState], 'وضعیت تسک تغییر کرد.');
    } catch (Throwable $e) {
        error_log('[API] toggle error: ' . $e->getMessage());
        apiError('خطا در تغییر وضعیت.', 500);
    }
}

apiError(
    'اکشن "' . htmlspecialchars($action, ENT_QUOTES, 'UTF-8') . '" پشتیبانی نمی‌شود.',
    400,
    'UNKNOWN_ACTION'
);