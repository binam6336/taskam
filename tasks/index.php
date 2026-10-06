<?php
ob_start();

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../database/Database.php';
require_once __DIR__ . '/../includes/quick_access.php';
require_once __DIR__ . '/../includes/impersonate_banner.php';
require_once __DIR__ . '/../includes/chat_notifications.php';

if (!Auth::check()) {
    header('Location: ../auth/login.php');
    exit;
}

date_default_timezone_set('Asia/Tehran');

$db = Database::getInstance();

try {
    $db->exec("SET NAMES 'utf8mb4'");
    $db->exec("SET time_zone = '+03:30'");
} catch (PDOException $e) {
    error_log('DB encoding/timezone error: ' . $e->getMessage());
}

// ================== Persian Date Helper ==================
function gregorianToJalali($gy, $gm, $gd) {
    $g_d_m = [0,31,59,90,120,151,181,212,243,273,304,334];
    $gy2 = ($gm > 2) ? ($gy + 1) : $gy;
    $days = 355666 + (365 * $gy) + ((int)(($gy2 + 3) / 4)) - ((int)(($gy2 + 99) / 100))
          + ((int)(($gy2 + 399) / 400)) + $gd + $g_d_m[$gm - 1];
    $jy = -1595 + (33 * ((int)($days / 12053)));
    $days %= 12053;
    $jy += 4 * ((int)($days / 1461));
    $days %= 1461;
    if ($days > 365) {
        $jy += (int)(($days - 1) / 365);
        $days = ($days - 1) % 365;
    }
    if ($days < 186) {
        $jm = 1 + (int)($days / 31);
        $jd = 1 + ($days % 31);
    } else {
        $jm = 7 + (int)(($days - 186) / 30);
        $jd = 1 + (($days - 186) % 30);
    }
    return [$jy, $jm, $jd];
}

function formatPersianDate($datetime, $includeWeekday = true) {
    if (empty($datetime)) return '';
    $ts = strtotime($datetime);
    if (!$ts) return $datetime;

    $gy = (int)date('Y', $ts);
    $gm = (int)date('n', $ts);
    $gd = (int)date('j', $ts);
    list($jy, $jm, $jd) = gregorianToJalali($gy, $gm, $gd);

    $months   = ['فروردین','اردیبهشت','خرداد','تیر','مرداد','شهریور','مهر','آبان','آذر','دی','بهمن','اسفند'];
    $weekdays = ['شنبه','یکشنبه','دوشنبه','سه‌شنبه','چهارشنبه','پنجشنبه','جمعه'];
    $wd = (int)date('w', $ts);
    $persianWd = ($wd + 1) % 7;

    $time = date('H:i', $ts);
    $result = ($includeWeekday ? $weekdays[$persianWd] . ' ' : '')
            . $jd . ' ' . $months[$jm - 1] . ' ' . $jy . ' - ' . $time;
    return $result;
}

/* فقط تاریخ شمسی بدون ساعت — برای فیلدهای DATE و نمایش فشرده */
function formatPersianDateOnly($datetime) {
    if (empty($datetime)) return '';
    $ts = strtotime($datetime);
    if (!$ts) return $datetime;
    $gy = (int)date('Y', $ts);
    $gm = (int)date('n', $ts);
    $gd = (int)date('j', $ts);
    list($jy, $jm, $jd) = gregorianToJalali($gy, $gm, $gd);
    $months = ['فروردین','اردیبهشت','خرداد','تیر','مرداد','شهریور','مهر','آبان','آذر','دی','بهمن','اسفند'];
    return $jd . ' ' . $months[$jm - 1] . ' ' . $jy;
}

/* زمان کوتاه شمسی (تاریخ + ساعت بدون نام روز) — برای created_at */
function formatPersianDateShort($datetime) {
    if (empty($datetime)) return '';
    $ts = strtotime($datetime);
    if (!$ts) return $datetime;
    $gy = (int)date('Y', $ts);
    $gm = (int)date('n', $ts);
    $gd = (int)date('j', $ts);
    list($jy, $jm, $jd) = gregorianToJalali($gy, $gm, $gd);
    $months = ['فروردین','اردیبهشت','خرداد','تیر','مرداد','شهریور','مهر','آبان','آذر','دی','بهمن','اسفند'];
    $time = date('H:i', $ts);
    return $jd . ' ' . $months[$jm - 1] . ' ' . $jy . ' - ' . $time;
}

// ================== CSRF Token ==================
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

function verifyCsrf(): bool {
    if (!isset($_POST['csrf_token'], $_SESSION['csrf_token'])) return false;
    return hash_equals($_SESSION['csrf_token'], (string)$_POST['csrf_token']);
}

function redirectSelf(string $msg = '', string $type = 'success'): void {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    if ($msg !== '') {
        $_SESSION['flash_msg'] = $msg;
        $_SESSION['flash_type'] = $type;
    }
    $page = $_GET['page'] ?? 'dashboard';
    header('Location: index.php?page=' . urlencode($page));
    exit;
}

function jsonResponse(array $data): void {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function formToken(string $key): string {
    $sessionKey = 'form_token_' . $key;
    if (empty($_SESSION[$sessionKey])) {
        $_SESSION[$sessionKey] = bin2hex(random_bytes(16));
    }
    return $_SESSION[$sessionKey];
}

function verifyFormToken(string $key): bool {
    $sessionKey = 'form_token_' . $key;
    $submitted = (string)($_POST['form_token'] ?? '');
    if (empty($_SESSION[$sessionKey]) || $submitted === '' || !hash_equals($_SESSION[$sessionKey], $submitted)) {
        return false;
    }
    unset($_SESSION[$sessionKey]);
    return true;
}

function safeJsonEncode($data): string {
    $json = json_encode(
        $data,
        JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
    );
    if ($json === false) return 'null';
    $json = str_replace(["\xe2\x80\xa8", "\xe2\x80\xa9"], ['\u2028', '\u2029'], $json);
    return $json;
}

// ================== Share Token Helpers ==================
function ensureShareToken(PDO $db, int $taskId): string {
    if ($taskId <= 0) return '';
    try {
        $stmt = $db->prepare("SELECT share_token FROM tasks WHERE id = ? LIMIT 1");
        $stmt->execute([$taskId]);
        $existing = $stmt->fetchColumn();
        if (!empty($existing)) return (string)$existing;
    } catch (PDOException $e) {
        return '';
    }
    $token = bin2hex(random_bytes(16));
    try {
        $db->prepare("UPDATE tasks SET share_token = ? WHERE id = ?")->execute([$token, $taskId]);
        return $token;
    } catch (PDOException $e) {
        error_log('share_token generate error: ' . $e->getMessage());
        return '';
    }
}

function getTaskamBotId(PDO $db): int {
    static $cachedId = null;
    if ($cachedId !== null && $cachedId > 0) return $cachedId;

    try {
        $stmt = $db->query("SELECT id FROM users WHERE mobile = '00000000000' LIMIT 1");
        $existing = (int)$stmt->fetchColumn();
        if ($existing > 0) { $cachedId = $existing; return $cachedId; }

        $stmt = $db->prepare("INSERT INTO users (mobile, first_name, last_name, password, status, role) VALUES ('00000000000', ?, '', ?, 'active', 'user')");
        $stmt->execute(['تسکام', password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT)]);
        $cachedId = (int)$db->lastInsertId();

        if ($cachedId <= 0) {
            $stmt = $db->query("SELECT id FROM users WHERE mobile = '00000000000' LIMIT 1");
            $cachedId = (int)$stmt->fetchColumn();
        }
        if ($cachedId > 0) return $cachedId;
    } catch (PDOException $e) {
        error_log('taskam bot error: ' . $e->getMessage());
    }
    return 0;
}

function notifyTaskamNewTask(PDO $db, int $taskId, int $assigneeId, int $creatorId,
                             string $title, string $desc, ?string $dueDate,
                             string $priority, ?int $projectId, array $userProjectsById): bool {
    if ($taskId <= 0 || $assigneeId <= 0 || $assigneeId === $creatorId) return false;

    try {
        $taskamId = getTaskamBotId($db);
        if ($taskamId <= 0) {
            error_log("[notifyTaskamNewTask] taskamId=0 for task {$taskId}");
            return false;
        }

        $tokenStmt = $db->prepare("SELECT share_token FROM tasks WHERE id = ? LIMIT 1");
        $tokenStmt->execute([$taskId]);
        $shareToken = (string)$tokenStmt->fetchColumn();
        if ($shareToken === '') {
            $shareToken = ensureShareToken($db, $taskId);
        }
        if ($shareToken === '') {
            error_log("[notifyTaskamNewTask] empty share_token for task {$taskId}");
            return false;
        }

        $creatorName = 'کاربر';
        $cs = $db->prepare("SELECT first_name, last_name, mobile FROM users WHERE id = ? LIMIT 1");
        $cs->execute([$creatorId]);
        if ($c = $cs->fetch(PDO::FETCH_ASSOC)) {
            $creatorName = trim(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? ''));
            if ($creatorName === '') $creatorName = $c['mobile'] ?? 'کاربر';
        }

        $projectTitle = '';
        if (!empty($projectId) && isset($userProjectsById[$projectId])) {
            $projectTitle = (string)($userProjectsById[$projectId]['title'] ?? '');
        }

        $priorityLabels = ['low' => 'کم', 'medium' => 'متوسط', 'high' => 'زیاد'];
        $priorityLabel = $priorityLabels[$priority] ?? $priority;

        $dueDateStr = '';
        if (!empty($dueDate)) {
            $dueDateStr = formatPersianDateOnly($dueDate . ' 00:00:00');
        }

        $lines = [];
        $lines[] = "📌 وظیفه جدیدی برای شما ایجاد شد";
        $lines[] = "";
        $lines[] = "عنوان: {$title}";
        $lines[] = "ایجاد کننده: {$creatorName}";
        if ($projectTitle !== '') $lines[] = "پروژه: {$projectTitle}";
        $lines[] = "اولویت: {$priorityLabel}";
        if ($dueDateStr !== '') $lines[] = "مهلت: {$dueDateStr}";
        if (!empty($desc)) {
            $descPreview = mb_substr(trim($desc), 0, 200, 'UTF-8');
            $lines[] = "";
            $lines[] = "توضیحات: {$descPreview}";
        }

        $notifyMsg = implode("\n", $lines) . "\n\n[[task:{$shareToken}]]";

        $ins = $db->prepare("INSERT INTO colleague_messages (sender_id, receiver_id, message, is_read) VALUES (?, ?, ?, 0)");
        $ins->execute([$taskamId, $assigneeId, $notifyMsg]);
        return true;
    } catch (PDOException $e) {
        error_log('notifyTaskamNewTask error: ' . $e->getMessage());
        return false;
    }
}

// ================== File Attachments Helper ==================
function getAllowedAttachmentRules(): array {
    return [
        'ext' => [
            'pdf','xls','xlsx','csv','doc','docx','txt','rtf',
            'jpg','jpeg','png','gif','webp','bmp',
            'zip','rar','7z'
        ],
        'mime' => [
            'application/pdf',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'text/csv',
            'text/plain',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/rtf',
            'text/rtf',
            'image/jpeg',
            'image/png',
            'image/gif',
            'image/webp',
            'image/bmp',
            'application/zip',
            'application/x-zip-compressed',
            'application/x-rar-compressed',
            'application/vnd.rar',
            'application/x-7z-compressed',
            'application/octet-stream',
        ],
        'max_size' => 10 * 1024 * 1024,
    ];
}

function humanFileSize($bytes): string {
    $bytes = (int)$bytes;
    if ($bytes < 1024) return $bytes . ' بایت';
    if ($bytes < 1024 * 1024) return round($bytes / 1024, 1) . ' کیلوبایت';
    return round($bytes / (1024 * 1024), 2) . ' مگابایت';
}

function attachmentIconClass(string $ext): string {
    $ext = strtolower($ext);
    if ($ext === 'pdf') return 'fa-file-pdf';
    if (in_array($ext, ['xls','xlsx','csv'], true)) return 'fa-file-excel';
    if (in_array($ext, ['doc','docx','rtf','txt'], true)) return 'fa-file-word';
    if (in_array($ext, ['jpg','jpeg','png','gif','webp','bmp'], true)) return 'fa-file-image';
    if (in_array($ext, ['zip','rar','7z'], true)) return 'fa-file-zipper';
    return 'fa-file';
}

function saveTaskAttachments(PDO $db, int $taskId, int $userId, array $files): array {
    $rules = getAllowedAttachmentRules();
    $allowedExt  = $rules['ext'];
    $allowedMime = $rules['mime'];
    $maxSize     = $rules['max_size'];

    $uploadDir = __DIR__ . '/../uploads/task_attachments/';
    if (!is_dir($uploadDir)) {
        @mkdir($uploadDir, 0755, true);
    }

    $uploaded = [];
    $errors   = [];

    if (empty($files) || empty($files['name']) || !is_array($files['name'])) {
        return [$uploaded, $errors];
    }

    $count = count($files['name']);

    for ($i = 0; $i < $count; $i++) {
        if ((int)$files['error'][$i] === UPLOAD_ERR_NO_FILE) continue;

        if ((int)$files['error'][$i] !== UPLOAD_ERR_OK) {
            $errors[] = $files['name'][$i] . ' (خطای آپلود)';
            continue;
        }

        if ((int)$files['size'][$i] <= 0 || (int)$files['size'][$i] > $maxSize) {
            $errors[] = $files['name'][$i] . ' (حجم غیرمجاز)';
            continue;
        }

        $origName = (string)$files['name'][$i];
        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

        if (!in_array($ext, $allowedExt, true)) {
            $errors[] = $origName . ' (فرمت غیرمجاز)';
            continue;
        }

        $mime = '';
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $mime = (string)finfo_file($finfo, $files['tmp_name'][$i]);
                finfo_close($finfo);
            }
        }
        if ($mime === '') {
            $mime = (string)($files['type'][$i] ?? 'application/octet-stream');
        }

        if (!in_array($mime, $allowedMime, true)) {
            $errors[] = $origName . ' (نوع فایل مجاز نیست)';
            continue;
        }

        $newName = 'task_' . $taskId . '_' . time() . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
        $dest = $uploadDir . $newName;

        if (!@move_uploaded_file($files['tmp_name'][$i], $dest)) {
            $errors[] = $origName . ' (ذخیره‌سازی ناموفق)';
            continue;
        }

        try {
            $stmt = $db->prepare("
                INSERT INTO task_attachments
                    (task_id, user_id, file_name, file_path, file_size, file_ext, file_mime)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $taskId,
                $userId,
                mb_substr($origName, 0, 255, 'UTF-8'),
                $newName,
                (int)$files['size'][$i],
                $ext,
                $mime,
            ]);
            $uploaded[] = [
                'id'    => (int)$db->lastInsertId(),
                'name'  => $origName,
                'path'  => $newName,
            ];
        } catch (PDOException $e) {
            error_log('attachment insert error: ' . $e->getMessage());
            @unlink($dest);
            $errors[] = $origName . ' (خطای دیتابیس)';
        }
    }

    return [$uploaded, $errors];
}

function saveNoteAttachments(PDO $db, int $noteId, int $userId, array $files): array {
    $rules = getAllowedAttachmentRules();
    $allowedExt  = $rules['ext'];
    $allowedMime = $rules['mime'];
    $maxSize     = $rules['max_size'];

    $uploadDir = __DIR__ . '/../uploads/note_attachments/';
    if (!is_dir($uploadDir)) {
        @mkdir($uploadDir, 0755, true);
    }

    $uploaded = [];
    $errors   = [];

    if (empty($files) || empty($files['name']) || !is_array($files['name'])) {
        return [$uploaded, $errors];
    }

    $count = count($files['name']);

    for ($i = 0; $i < $count; $i++) {
        if ((int)$files['error'][$i] === UPLOAD_ERR_NO_FILE) continue;
        if ((int)$files['error'][$i] !== UPLOAD_ERR_OK) { $errors[] = $files['name'][$i] . ' (خطای آپلود)'; continue; }
        if ((int)$files['size'][$i] <= 0 || (int)$files['size'][$i] > $maxSize) { $errors[] = $files['name'][$i] . ' (حجم غیرمجاز)'; continue; }

        $origName = (string)$files['name'][$i];
        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

        if (!in_array($ext, $allowedExt, true)) { $errors[] = $origName . ' (فرمت غیرمجاز)'; continue; }

        $mime = '';
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) { $mime = (string)finfo_file($finfo, $files['tmp_name'][$i]); finfo_close($finfo); }
        }
        if ($mime === '') $mime = (string)($files['type'][$i] ?? 'application/octet-stream');
        if (!in_array($mime, $allowedMime, true)) { $errors[] = $origName . ' (نوع فایل مجاز نیست)'; continue; }

        $newName = 'note_' . $noteId . '_' . time() . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
        $dest = $uploadDir . $newName;

        if (!@move_uploaded_file($files['tmp_name'][$i], $dest)) { $errors[] = $origName . ' (ذخیره‌سازی ناموفق)'; continue; }

        try {
            $stmt = $db->prepare("
                INSERT INTO note_attachments
                    (note_id, user_id, file_name, file_path, file_size, file_ext, file_mime)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $noteId, $userId,
                mb_substr($origName, 0, 255, 'UTF-8'),
                $newName, (int)$files['size'][$i], $ext, $mime,
            ]);
            $uploaded[] = ['id' => (int)$db->lastInsertId(), 'name' => $origName, 'path' => $newName];
        } catch (PDOException $e) {
            error_log('note attachment insert error: ' . $e->getMessage());
            @unlink($dest);
            $errors[] = $origName . ' (خطای دیتابیس)';
        }
    }

    return [$uploaded, $errors];
}

// چک وضعیت کاربر
try {
    $statusStmt = $db->prepare("SELECT status, role FROM users WHERE id = ? LIMIT 1");
    $statusStmt->execute([(int)$_SESSION['user_id']]);
    $statusRow = $statusStmt->fetch(PDO::FETCH_ASSOC);

    if ($statusRow) {
        $uRole = $statusRow['role'] ?? 'user';
        if ($uRole === 'admin') {
            header('Location: ../admin/index.php');
            exit;
        }
        if (($statusRow['status'] ?? 'pending') !== 'active') {
            header('Location: ../pending/index.php');
            exit;
        }
    }
} catch (PDOException $e) {
    error_log('Status check error: ' . $e->getMessage());
}

$userId = (int)$_SESSION['user_id'];
$page = $_GET['page'] ?? 'dashboard';

$sharedTaskParam = trim((string)($_GET['task'] ?? ''));
if ($sharedTaskParam !== '') {
    $page = 'list';
}

// ================== تنظیمات صفحه‌بندی وظایف ==================
$TASKS_PER_PAGE = 10;

$mt = $_GET['mt'] ?? 'mine';
if (!in_array($mt, ['mine', 'others'], true)) $mt = 'mine';
$st = $_GET['st'] ?? 'uncompleted';
if (!in_array($st, ['uncompleted', 'completed'], true)) $st = 'uncompleted';

function getPageParam(string $key): int {
    $p = isset($_GET[$key]) ? (int)$_GET[$key] : 1;
    return $p > 0 ? $p : 1;
}

function paginateArray(array $items, int $page, int $perPage): array {
    $total = count($items);
    $totalPages = $total > 0 ? (int)ceil($total / $perPage) : 1;
    if ($page > $totalPages) $page = $totalPages;
    if ($page < 1) $page = 1;
    $offset = ($page - 1) * $perPage;
    $slice = array_slice($items, $offset, $perPage);
    return [$slice, $page, $totalPages, $total];
}

function renderPagination(int $page, int $totalPages, int $totalCount, string $paramKey, string $mt, string $st): string {
    if ($totalPages <= 1) return '';
    $html = '<div class="pagination">';

    $buildUrl = function(int $p) use ($paramKey, $mt, $st) {
        return 'index.php?page=list&mt=' . urlencode($mt) . '&st=' . urlencode($st) . '&' . $paramKey . '=' . $p;
    };

    if ($page > 1) {
        $html .= '<a class="page-btn" href="' . htmlspecialchars($buildUrl($page - 1), ENT_QUOTES, 'UTF-8') . '" title="صفحه قبلی"><i class="fas fa-chevron-right"></i></a>';
    } else {
        $html .= '<span class="page-btn disabled"><i class="fas fa-chevron-right"></i></span>';
    }

    $pages = [1];
    for ($i = $page - 1; $i <= $page + 1; $i++) {
        if ($i > 1 && $i < $totalPages) $pages[] = $i;
    }
    if ($totalPages > 1) $pages[] = $totalPages;
    $pages = array_values(array_unique($pages));
    sort($pages);

    $prev = 0;
    foreach ($pages as $p) {
        if ($p > $prev + 1) {
            $html .= '<span class="page-dots">…</span>';
        }
        if ($p === $page) {
            $html .= '<span class="page-btn active">' . $p . '</span>';
        } else {
            $html .= '<a class="page-btn" href="' . htmlspecialchars($buildUrl($p), ENT_QUOTES, 'UTF-8') . '">' . $p . '</a>';
        }
        $prev = $p;
    }

    if ($page < $totalPages) {
        $html .= '<a class="page-btn" href="' . htmlspecialchars($buildUrl($page + 1), ENT_QUOTES, 'UTF-8') . '" title="صفحه بعدی"><i class="fas fa-chevron-left"></i></a>';
    } else {
        $html .= '<span class="page-btn disabled"><i class="fas fa-chevron-left"></i></span>';
    }

    $html .= '<span class="page-info">صفحه ' . $page . ' از ' . $totalPages . ' (کل: ' . $totalCount . ')</span>';
    $html .= '</div>';
    return $html;
}

$msg = '';
$msgType = 'success';
if (!empty($_SESSION['flash_msg'])) {
    $msg = (string)$_SESSION['flash_msg'];
    $msgType = (string)($_SESSION['flash_type'] ?? 'success');
    unset($_SESSION['flash_msg'], $_SESSION['flash_type']);
}

if (empty($_SESSION['checked_project_col'])) {
    try {
        $colCheck = $db->query("SHOW COLUMNS FROM tasks LIKE 'project_id'");
        if ($colCheck && $colCheck->rowCount() === 0) {
            $db->exec("ALTER TABLE tasks ADD COLUMN project_id INT DEFAULT NULL AFTER subject_id");
            $db->exec("ALTER TABLE tasks ADD INDEX idx_tasks_project_id (project_id)");
        }
        $_SESSION['checked_project_col'] = 1;
    } catch (PDOException $e) {
        error_log('project_id check error: ' . $e->getMessage());
    }
}

if (empty($_SESSION['checked_share_token_col'])) {
    try {
        $colCheck = $db->query("SHOW COLUMNS FROM tasks LIKE 'share_token'");
        if ($colCheck && $colCheck->rowCount() === 0) {
            $db->exec("ALTER TABLE tasks ADD COLUMN share_token VARCHAR(64) DEFAULT NULL");
            try { $db->exec("ALTER TABLE tasks ADD UNIQUE INDEX idx_tasks_share_token (share_token)"); } catch (PDOException $e2) {}
        }
        $_SESSION['checked_share_token_col'] = 1;
    } catch (PDOException $e) {
        error_log('share_token check error: ' . $e->getMessage());
    }
}

if (empty($_SESSION['checked_notes_table'])) {
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS task_notes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            task_id INT NOT NULL,
            user_id INT NOT NULL,
            note TEXT NOT NULL,
            updated_at TIMESTAMP NULL DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;");
        $_SESSION['checked_notes_table'] = 1;
    } catch (PDOException $e) {
        error_log('notes table check error: ' . $e->getMessage());
    }
}

if (empty($_SESSION['checked_attachments_table'])) {
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS task_attachments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            task_id INT NOT NULL,
            user_id INT NOT NULL,
            file_name VARCHAR(255) NOT NULL,
            file_path VARCHAR(255) NOT NULL,
            file_size INT UNSIGNED DEFAULT NULL,
            file_ext VARCHAR(20) DEFAULT NULL,
            file_mime VARCHAR(120) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_task_attachments_task_id (task_id),
            INDEX idx_task_attachments_user_id (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;");
        $_SESSION['checked_attachments_table'] = 1;
    } catch (PDOException $e) {
        error_log('attachments table check error: ' . $e->getMessage());
    }
}

if (empty($_SESSION['checked_note_attachments_table'])) {
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS note_attachments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            note_id INT NOT NULL,
            user_id INT NOT NULL,
            file_name VARCHAR(255) NOT NULL,
            file_path VARCHAR(255) NOT NULL,
            file_size INT UNSIGNED DEFAULT NULL,
            file_ext VARCHAR(20) DEFAULT NULL,
            file_mime VARCHAR(120) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_note_attachments_note_id (note_id),
            INDEX idx_note_attachments_user_id (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;");
        $_SESSION['checked_note_attachments_table'] = 1;
    } catch (PDOException $e) {
        error_log('note attachments table check error: ' . $e->getMessage());
    }
}

// ================== همکاران ==================
$colleaguesList = [];
$colleaguesById = [];
try {
    $colleaguesListStmt = $db->prepare("
        SELECT u.id, u.first_name, u.last_name, u.mobile, u.avatar
        FROM colleagues c
        INNER JOIN users u ON u.id = c.colleague_user_id
        WHERE c.user_id = ?
        ORDER BY u.first_name ASC, u.last_name ASC
    ");
    $colleaguesListStmt->execute([$userId]);
    $colleaguesList = $colleaguesListStmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($colleaguesList as $c) {
        $colleaguesById[(int)$c['id']] = $c;
    }
} catch (PDOException $e) {
    error_log('colleagues load error: ' . $e->getMessage());
}

// ================== پروژه‌ها ==================
$userProjects = [];
try {
    $pStmt = $db->prepare("
        SELECT p.id, p.title, p.profile_image,
               (p.user_id = ?) AS is_creator
        FROM projects p
        WHERE p.user_id = ?
           OR EXISTS (
               SELECT 1 FROM project_members pm
               WHERE pm.project_id = p.id AND pm.user_id = ?
           )
        ORDER BY is_creator DESC, p.title ASC
    ");
    $pStmt->execute([$userId, $userId, $userId]);
    $userProjects = $pStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('projects load error: ' . $e->getMessage());
}

$userProjectsById = [];
foreach ($userProjects as $p) {
    $userProjectsById[(int)$p['id']] = $p;
}

// ================== اعضای پروژه‌ها ==================
$projectsMembersMap = [];
$allProjectUserIds = [];

try {
    if (!empty($userProjects)) {
        $projectIds = array_map(fn($p) => (int)$p['id'], $userProjects);
        $placeholders = implode(',', array_fill(0, count($projectIds), '?'));

        $pmStmt = $db->prepare("
            SELECT 
                pm.project_id,
                pm.user_id,
                u.first_name,
                u.last_name,
                u.mobile,
                u.avatar,
                p.user_id AS project_creator_id
            FROM project_members pm
            INNER JOIN users u ON u.id = pm.user_id
            INNER JOIN projects p ON p.id = pm.project_id
            WHERE pm.project_id IN ($placeholders)
              AND u.status = 'active'
            ORDER BY pm.added_at ASC
        ");
        $pmStmt->execute($projectIds);
        $pmRows = $pmStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($pmRows as $pm) {
            $pid = (int)$pm['project_id'];
            $uid = (int)$pm['user_id'];
            $allProjectUserIds[$uid] = true;

            $fullName = trim(($pm['first_name'] ?? '') . ' ' . ($pm['last_name'] ?? ''));
            if ($fullName === '') $fullName = $pm['mobile'];

            $isSelf    = ($uid === $userId);
            $isCreator = ($uid === (int)$pm['project_creator_id']);

            $avatarUrl = null;
            if (!empty($pm['avatar'])) {
                $avBase = basename($pm['avatar']);
                if (preg_match('/^[A-Za-z0-9_.\-]+$/', $avBase) && file_exists(__DIR__ . '/../uploads/avatars/' . $avBase)) {
                    $avatarUrl = '../uploads/avatars/' . rawurlencode($avBase);
                }
            }

            $projectsMembersMap[$pid][] = [
                'id'         => $uid,
                'name'       => $isSelf ? 'خودم' : $fullName,
                'mobile'     => $pm['mobile'],
                'initial'    => mb_substr(trim($pm['first_name'] ?: $pm['mobile']), 0, 1, 'UTF-8'),
                'avatar_url' => $avatarUrl,
                'is_self'    => $isSelf,
                'is_creator' => $isCreator,
            ];
        }
    }
} catch (PDOException $e) {
    error_log('project members load error: ' . $e->getMessage());
}

// ================== کاربر جاری ==================
$currentUserAvatar = null;
$currentUserFirstName = 'خودم';
try {
    $curStmt = $db->prepare("SELECT first_name, avatar FROM users WHERE id = ? LIMIT 1");
    $curStmt->execute([$userId]);
    $curRow = $curStmt->fetch(PDO::FETCH_ASSOC);
    if ($curRow) {
        if (!empty($curRow['first_name'])) $currentUserFirstName = $curRow['first_name'];
        if (!empty($curRow['avatar'])) {
            $avBase = basename($curRow['avatar']);
            if (preg_match('/^[A-Za-z0-9_.\-]+$/', $avBase) && file_exists(__DIR__ . '/../uploads/avatars/' . $avBase)) {
                $currentUserAvatar = '../uploads/avatars/' . $avBase;
            }
        }
    }
} catch (PDOException $e) {
    error_log('current user load error: ' . $e->getMessage());
}

// ================== بررسی دسترسی تسک ==================
function checkTaskPermission($db, $taskId, $userId, $action) {
    static $cache = [];
    $cacheKey = $taskId . '_' . $userId;

    if (!isset($cache[$cacheKey])) {
        $taskStmt = $db->prepare("SELECT * FROM tasks WHERE id = ? AND (user_id = ? OR assignee_id = ?) LIMIT 1");
        $taskStmt->execute([$taskId, $userId, $userId]);
        $cache[$cacheKey] = $taskStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    $task = $cache[$cacheKey];
    if (!$task) {
        return ['allowed' => false, 'reason' => 'not_found', 'task' => null, 'is_owner' => false];
    }

    if ((int)$task['user_id'] === $userId) {
        return ['allowed' => true, 'reason' => 'owner', 'task' => $task, 'is_owner' => true];
    }

    $permStmt = $db->prepare("SELECT permissions FROM colleagues WHERE user_id = ? AND colleague_user_id = ? LIMIT 1");
    $permStmt->execute([(int)$task['user_id'], $userId]);
    $permRow = $permStmt->fetch(PDO::FETCH_ASSOC);

    if (!$permRow) {
        return ['allowed' => false, 'reason' => 'not_a_colleague', 'task' => $task, 'is_owner' => false];
    }

    $perms = json_decode($permRow['permissions'] ?? '[]', true);
    if (!is_array($perms)) $perms = [];

    if (in_array($action, $perms, true)) {
        return ['allowed' => true, 'reason' => 'permitted', 'task' => $task, 'is_owner' => false];
    }

    return ['allowed' => false, 'reason' => 'no_permission', 'task' => $task, 'is_owner' => false];
}

// ================== Endpoint: دریافت لینک اشتراکی تسک ==================
if (isset($_GET['get_task_token'])) {
    $taskId = (int)$_GET['get_task_token'];
    if ($taskId <= 0) jsonResponse(['status' => 'error', 'message' => 'شناسه نامعتبر']);

    $perm = checkTaskPermission($db, $taskId, $userId, 'edit');
    if (!$perm['task']) {
        $chk = $db->prepare("SELECT id FROM tasks WHERE id = ? AND assignee_id = ? LIMIT 1");
        $chk->execute([$taskId, $userId]);
        if (!$chk->fetchColumn()) {
            jsonResponse(['status' => 'error', 'message' => 'دسترسی ندارید.']);
        }
    }

    $token = ensureShareToken($db, $taskId);
    if ($token === '') jsonResponse(['status' => 'error', 'message' => 'خطا در ساخت لینک']);

    jsonResponse([
        'status' => 'success',
        'token' => $token,
        'url' => 'index.php?task=' . $token,
    ]);
}

// ================== مدیریت موضوعات ==================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['add_subject']) || isset($_POST['update_subject']))) {
    if (!verifyCsrf()) redirectSelf('درخواست نامعتبر است.', 'danger');
    if (!verifyFormToken('subject')) redirectSelf('فرم قبلاً ارسال شده است.', 'warning');

    $title = trim((string)($_POST['subject_title'] ?? ''));
    if (mb_strlen($title) > 100) redirectSelf('عنوان موضوع بسیار طولانی است.', 'danger');

    if (isset($_POST['update_subject']) && !empty($_POST['subject_id'])) {
        $subId = (int)$_POST['subject_id'];
        if ($title !== '') {
            $stmt = $db->prepare("UPDATE subjects SET title = ? WHERE id = ? AND user_id = ?");
            $stmt->execute([$title, $subId, $userId]);
            redirectSelf('موضوع با موفقیت ویرایش شد.');
        }
        redirectSelf('عنوان موضوع الزامی است.', 'danger');
    } else if ($title !== '') {
        $stmt = $db->prepare("INSERT INTO subjects (user_id, title) VALUES (?, ?)");
        $stmt->execute([$userId, $title]);
        redirectSelf('موضوع جدید با موفقیت ثبت شد.');
    }
    redirectSelf('عنوان موضوع الزامی است.', 'danger');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_subject'])) {
    if (!verifyCsrf()) redirectSelf('درخواست نامعتبر است.', 'danger');
    $subId = (int)$_POST['delete_subject'];
    $db->prepare("UPDATE tasks SET subject_id = NULL WHERE subject_id = ? AND user_id = ?")->execute([$subId, $userId]);
    $db->prepare("DELETE FROM subjects WHERE id = ? AND user_id = ?")->execute([$subId, $userId]);
    redirectSelf('موضوع حذف شد.');
}

// ================== ثبت/ویرایش تسک ==================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['add_task']) || isset($_POST['update_task']))) {
    if (!verifyCsrf()) redirectSelf('درخواست نامعتبر است.', 'danger');
    if (!verifyFormToken('task')) redirectSelf('فرم قبلاً ارسال شده است.', 'warning');

    $title = trim((string)($_POST['task_title'] ?? ''));
    $desc = trim((string)($_POST['task_desc'] ?? ''));
    if (mb_strlen($title) > 255) redirectSelf('عنوان وظیفه بسیار طولانی است.', 'danger');
    if (mb_strlen($desc) > 5000) redirectSelf('توضیحات بسیار طولانی است.', 'danger');

    $subjectId = !empty($_POST['subject_id']) ? (int)$_POST['subject_id'] : null;
    $priority = (string)($_POST['priority'] ?? 'medium');
    $dueDate = !empty($_POST['due_date']) ? (string)$_POST['due_date'] : null;

    if ($dueDate !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueDate)) {
        $dueDate = null;
    }

    $projectId = !empty($_POST['project_id']) ? (int)$_POST['project_id'] : null;
    if ($projectId !== null && !isset($userProjectsById[$projectId])) {
        $projectId = null;
    }

    $assigneeId = !empty($_POST['assignee_id']) ? (int)$_POST['assignee_id'] : $userId;

    $allowedAssigneeIds = [$userId => true];
    foreach ($colleaguesById as $cid => $c) {
        $allowedAssigneeIds[$cid] = true;
    }
    if ($projectId !== null && isset($projectsMembersMap[$projectId])) {
        foreach ($projectsMembersMap[$projectId] as $pm) {
            $allowedAssigneeIds[(int)$pm['id']] = true;
        }
    }
    if (!isset($allowedAssigneeIds[$assigneeId])) {
        $assigneeId = $userId;
    }

    if (!in_array($priority, ['low', 'medium', 'high'], true)) {
        $priority = 'medium';
    }

    $uploadedFiles = $_FILES['task_attachments'] ?? null;

    if (isset($_POST['update_task']) && !empty($_POST['task_id'])) {
        $taskId = (int)$_POST['task_id'];
        $perm = checkTaskPermission($db, $taskId, $userId, 'edit');

        if (!$perm['task']) redirectSelf('دسترسی ندارید.', 'danger');

        $isOwner = $perm['is_owner'];
        $hasEditPerm = $perm['allowed'];

        $oldAssignee = (int)$perm['task']['assignee_id'];
        $oldProjectId = (int)($perm['task']['project_id'] ?? 0);
        $oldTitle     = $perm['task']['title'];
        $oldDesc      = $perm['task']['description'];
        $oldPriority  = $perm['task']['priority'];
        $oldDueDate   = $perm['task']['due_date'];
        $oldSubjectId = $perm['task']['subject_id'];

        if (!$isOwner && !$hasEditPerm) {
            $title     = $oldTitle;
            $desc      = $oldDesc;
            $priority  = $oldPriority;
            $dueDate   = $oldDueDate;
            $subjectId = $oldSubjectId;
        }

        $warnings = [];

        if (!$isOwner && $assigneeId !== $oldAssignee) {
            $reassignPerm = checkTaskPermission($db, $taskId, $userId, 'reassign');
            if (!$reassignPerm['allowed']) {
                $assigneeId = $oldAssignee;
                $warnings[] = 'دسترسی تغییر مسئول ندارید';
            }
        }

        $newProjectId = $projectId !== null ? (int)$projectId : 0;
        if (!$isOwner && $newProjectId !== $oldProjectId) {
            $projectPerm = checkTaskPermission($db, $taskId, $userId, 'change_project');
            if (!$projectPerm['allowed']) {
                $projectId = $oldProjectId > 0 ? $oldProjectId : null;
                $warnings[] = 'دسترسی تغییر پروژه ندارید';
            }
        }

        $stmt = $db->prepare("UPDATE tasks SET subject_id = ?, project_id = ?, assignee_id = ?, title = ?, description = ?, priority = ?, due_date = ? WHERE id = ?");
        $stmt->execute([$subjectId, $projectId, $assigneeId, $title, $desc, $priority, $dueDate, $taskId]);

        ensureShareToken($db, $taskId);

        if ($assigneeId !== $oldAssignee && $assigneeId !== $userId) {
            notifyTaskamNewTask($db, $taskId, $assigneeId, $userId,
                               $title, $desc, $dueDate, $priority, $projectId, $userProjectsById);
        }

        if (!empty($uploadedFiles)) {
            list($saved, $uploadErrors) = saveTaskAttachments($db, $taskId, $userId, $uploadedFiles);
            if (!empty($uploadErrors)) {
                $warnings[] = 'برخی فایل‌ها آپلود نشد: ' . implode('، ', $uploadErrors);
            }
        }

        if (!empty($warnings)) {
            redirectSelf('وظیفه ذخیره شد، اما: ' . implode(' — ', $warnings), 'warning');
        }
        redirectSelf('وظیفه با موفقیت ویرایش شد.');
    } else if ($title !== '') {
        $stmt = $db->prepare("INSERT INTO tasks (user_id, assignee_id, subject_id, project_id, title, description, priority, due_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$userId, $assigneeId, $subjectId, $projectId, $title, $desc, $priority, $dueDate]);
        $newTaskId = (int)$db->lastInsertId();

        if ($newTaskId > 0) {
            ensureShareToken($db, $newTaskId);
            notifyTaskamNewTask($db, $newTaskId, $assigneeId, $userId,
                               $title, $desc, $dueDate, $priority, $projectId, $userProjectsById);
        }

        if (!empty($uploadedFiles) && $newTaskId > 0) {
            list($saved, $uploadErrors) = saveTaskAttachments($db, $newTaskId, $userId, $uploadedFiles);
            if (!empty($uploadErrors)) {
                redirectSelf('وظیفه ثبت شد، اما برخی فایل‌ها آپلود نشد: ' . implode('، ', $uploadErrors), 'warning');
            }
        }

        redirectSelf('وظیفه جدید با موفقیت ثبت شد.');
    }
    redirectSelf('عنوان وظیفه الزامی است.', 'danger');
}

// ================== ثبت یادداشت + منشن ==================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_task_note'])) {
    if (!verifyCsrf()) {
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) jsonResponse(['status' => 'error', 'message' => 'درخواست نامعتبر است.']);
        redirectSelf('درخواست نامعتبر است.', 'danger');
    }

    $taskId   = (int)($_POST['note_task_id'] ?? 0);
    $noteText = trim((string)($_POST['note_text'] ?? ''));

    if (mb_strlen($noteText) > 5000) {
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) jsonResponse(['status' => 'error', 'message' => 'متن گزارش بسیار طولانی است.']);
        redirectSelf('متن گزارش بسیار طولانی است.', 'danger');
    }

    $perm = checkTaskPermission($db, $taskId, $userId, 'notes');
    if (!$perm['allowed'] && $perm['task'] && (int)$perm['task']['assignee_id'] === $userId) {
        $perm['allowed'] = true;
    }

    $hasFiles = false;
    if (!empty($_FILES['note_attachments']['name']) && is_array($_FILES['note_attachments']['name'])) {
        foreach ($_FILES['note_attachments']['name'] as $n) {
            if ($n !== '') { $hasFiles = true; break; }
        }
    }

    if ($taskId > 0 && ($noteText !== '' || $hasFiles) && $perm['allowed']) {
        $storeText = $noteText !== '' ? $noteText : '📎 فایل پیوست';
        $stmt = $db->prepare("INSERT INTO task_notes (task_id, user_id, note) VALUES (?, ?, ?)");
        $stmt->execute([$taskId, $userId, $storeText]);
        $newNoteId = (int)$db->lastInsertId();

        if ($hasFiles) {
            list($saved, $attErrors) = saveNoteAttachments($db, $newNoteId, $userId, $_FILES['note_attachments']);
        }

        $mentionIds = [];
        if (!empty($_POST['mention_ids']) && is_array($_POST['mention_ids'])) {
            foreach ($_POST['mention_ids'] as $mid) {
                $midInt = (int)$mid;
                if ($midInt > 0 && $midInt !== $userId) $mentionIds[$midInt] = true;
            }
        }
        $mentionIds = array_keys($mentionIds);

        if (!empty($mentionIds)) {
            try {
                $taskamId = getTaskamBotId($db);
                if ($taskamId > 0) {
                    $taskInfoStmt = $db->prepare("SELECT id, title, share_token, project_id FROM tasks WHERE id = ? LIMIT 1");
                    $taskInfoStmt->execute([$taskId]);
                    $taskInfo = $taskInfoStmt->fetch(PDO::FETCH_ASSOC);

                    if ($taskInfo) {
                        $taskToken = !empty($taskInfo['share_token']) ? $taskInfo['share_token'] : ensureShareToken($db, (int)$taskInfo['id']);

                        $authorName = 'کاربر';
                        $auStmt = $db->prepare("SELECT first_name, last_name, mobile FROM users WHERE id = ? LIMIT 1");
                        $auStmt->execute([$userId]);
                        $auRow = $auStmt->fetch(PDO::FETCH_ASSOC);
                        if ($auRow) {
                            $authorName = trim(($auRow['first_name'] ?? '') . ' ' . ($auRow['last_name'] ?? ''));
                            if ($authorName === '') $authorName = $auRow['mobile'];
                        }

                        $taskTitle = $taskInfo['title'];
                        $noteExcerpt = mb_substr(trim($storeText), 0, 220, 'UTF-8');

                        $notifyMsg = "📝 یادداشت جدید در وظیفه «{$taskTitle}»\n\nاز طرف: {$authorName}\n\n{$noteExcerpt}\n\n[[task:{$taskToken}]]";

                        $insNotify = $db->prepare("INSERT INTO colleague_messages (sender_id, receiver_id, message, is_read) VALUES (?, ?, ?, 0)");

                        foreach ($mentionIds as $mid) {
                            $isAllowed = false;
                            $chkCol = $db->prepare("SELECT 1 FROM colleagues WHERE (user_id = ? AND colleague_user_id = ?) OR (user_id = ? AND colleague_user_id = ?) LIMIT 1");
                            $chkCol->execute([$userId, $mid, $mid, $userId]);
                            if ($chkCol->fetchColumn()) $isAllowed = true;

                            if (!$isAllowed && !empty($taskInfo['project_id'])) {
                                $chkPm = $db->prepare("SELECT 1 FROM project_members WHERE project_id = ? AND user_id = ? LIMIT 1");
                                $chkPm->execute([(int)$taskInfo['project_id'], $mid]);
                                if ($chkPm->fetchColumn()) $isAllowed = true;
                            }

                            if ($isAllowed) {
                                try { $insNotify->execute([$taskamId, $mid, $notifyMsg]); } catch (PDOException $e) { error_log('notify insert: ' . $e->getMessage()); }
                            }
                        }
                    }
                }
            } catch (PDOException $e) {
                error_log('mention notify error: ' . $e->getMessage());
            }
        }

        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
            $countStmt = $db->prepare("SELECT COUNT(*) FROM task_notes WHERE task_id = ?");
            $countStmt->execute([$taskId]);
            jsonResponse(['status' => 'success', 'new_count' => (int)$countStmt->fetchColumn()]);
        }
        redirectSelf('گزارش وظیفه با موفقیت ثبت شد.');
    } else {
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) jsonResponse(['status' => 'error', 'message' => 'دسترسی ندارید یا متن خالی است.']);
        redirectSelf('شما دسترسی افزودن یادداشت ندارید.', 'danger');
    }
}

// ================== ویرایش یادداشت ==================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_note'], $_POST['note_id'])) {
    if (!verifyCsrf()) jsonResponse(['status' => 'error', 'message' => 'درخواست نامعتبر است.']);

    $noteId   = (int)$_POST['note_id'];
    $newText  = trim((string)($_POST['note_text'] ?? ''));

    if ($noteId <= 0 || $newText === '') {
        jsonResponse(['status' => 'error', 'message' => 'متن گزارش نامعتبر است.']);
    }
    if (mb_strlen($newText) > 5000) {
        jsonResponse(['status' => 'error', 'message' => 'متن گزارش بسیار طولانی است.']);
    }

    $findStmt = $db->prepare("
        SELECT tn.id, tn.user_id, tn.task_id
        FROM task_notes tn
        INNER JOIN tasks t ON t.id = tn.task_id
        WHERE tn.id = ? AND (t.user_id = ? OR t.assignee_id = ?)
        LIMIT 1
    ");
    $findStmt->execute([$noteId, $userId, $userId]);
    $noteRow = $findStmt->fetch(PDO::FETCH_ASSOC);

    if (!$noteRow) jsonResponse(['status' => 'error', 'message' => 'یادداشت یافت نشد.']);

    if ((int)$noteRow['user_id'] !== $userId) {
        jsonResponse(['status' => 'error', 'message' => 'شما فقط می‌توانید یادداشت‌های خودتان را ویرایش کنید.']);
    }

    $db->prepare("UPDATE task_notes SET note = ? WHERE id = ?")->execute([$newText, $noteId]);
    jsonResponse(['status' => 'success']);
}

// ================== دریافت یادداشت‌ها ==================
if (isset($_GET['get_notes']) && isset($_GET['task_id'])) {
    $taskId = (int)$_GET['task_id'];

    $accessChk = $db->prepare("SELECT id FROM tasks WHERE id = ? AND (user_id = ? OR assignee_id = ?)");
    $accessChk->execute([$taskId, $userId, $userId]);
    if (!$accessChk->fetchColumn()) jsonResponse(['status' => 'error', 'message' => 'دسترسی ندارید.']);

    $stmt = $db->prepare("
        SELECT tn.id, tn.task_id, tn.user_id, tn.note, tn.created_at,
               u.first_name, u.last_name, u.mobile, u.avatar
        FROM task_notes tn
        LEFT JOIN users u ON u.id = tn.user_id
        WHERE tn.task_id = ?
        ORDER BY tn.created_at DESC
    ");
    $stmt->execute([$taskId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $notesAttachmentsMap = [];
    $noteIds = array_column($rows, 'id');
    if (!empty($noteIds)) {
        try {
            $placeholders = implode(',', array_fill(0, count($noteIds), '?'));
            $attStmt = $db->prepare("
                SELECT id, note_id, user_id, file_name, file_path, file_size, file_ext, file_mime, created_at
                FROM note_attachments
                WHERE note_id IN ($placeholders)
                ORDER BY created_at ASC
            ");
            $attStmt->execute($noteIds);
            $attRows = $attStmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($attRows as $a) {
                $nid = (int)$a['note_id'];
                $a['id']        = (int)$a['id'];
                $a['file_size'] = (int)$a['file_size'];
                $a['file_size_human'] = humanFileSize($a['file_size']);
                $a['icon']      = attachmentIconClass($a['file_ext'] ?? '');
                $a['url']       = '../uploads/note_attachments/' . rawurlencode($a['file_path']);
                $notesAttachmentsMap[$nid][] = $a;
            }
        } catch (PDOException $e) {
            error_log('note attachments load error: ' . $e->getMessage());
        }
    }

    foreach ($rows as &$row) {
        $row['persian_date'] = formatPersianDate($row['created_at']);
        $row['is_mine'] = ((int)$row['user_id'] === $userId);
        $row['attachments'] = $notesAttachmentsMap[(int)$row['id']] ?? [];
    }
    unset($row);

    jsonResponse(['status' => 'success', 'notes' => $rows]);
}

// ================== حذف یادداشت ==================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_note'], $_POST['note_id'])) {
    if (!verifyCsrf()) jsonResponse(['status' => 'error', 'message' => 'درخواست نامعتبر است.']);

    $noteId = (int)$_POST['note_id'];

    $findTask = $db->prepare("
        SELECT tn.task_id, tn.user_id, t.user_id AS task_owner
        FROM task_notes tn
        INNER JOIN tasks t ON t.id = tn.task_id
        WHERE tn.id = ?
    ");
    $findTask->execute([$noteId]);
    $noteRow = $findTask->fetch(PDO::FETCH_ASSOC);

    if (!$noteRow) jsonResponse(['status' => 'error', 'message' => 'یادداشت یافت نشد.']);

    $taskId = (int)$noteRow['task_id'];
    $canDelete = ((int)$noteRow['user_id'] === $userId) || ((int)$noteRow['task_owner'] === $userId);

    if (!$canDelete) {
        $perm = checkTaskPermission($db, $taskId, $userId, 'notes');
        if ($perm['allowed']) $canDelete = true;
    }

    if (!$canDelete) jsonResponse(['status' => 'error', 'message' => 'دسترسی ندارید.']);

    try {
        $attStmt = $db->prepare("SELECT file_path FROM note_attachments WHERE note_id = ?");
        $attStmt->execute([$noteId]);
        $attFiles = $attStmt->fetchAll(PDO::FETCH_COLUMN);
        foreach ($attFiles as $fp) {
            $full = __DIR__ . '/../uploads/note_attachments/' . basename($fp);
            if (is_file($full)) @unlink($full);
        }
        $db->prepare("DELETE FROM note_attachments WHERE note_id = ?")->execute([$noteId]);
    } catch (PDOException $e) {
        error_log('delete note attachments error: ' . $e->getMessage());
    }

    $db->prepare("DELETE FROM task_notes WHERE id = ?")->execute([$noteId]);
    $countStmt = $db->prepare("SELECT COUNT(*) FROM task_notes WHERE task_id = ?");
    $countStmt->execute([$taskId]);

    jsonResponse(['status' => 'success', 'task_id' => $taskId, 'new_count' => (int)$countStmt->fetchColumn()]);
}

// ================== حذف پیوست یادداشت ==================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_note_attachment'], $_POST['attachment_id'])) {
    if (!verifyCsrf()) jsonResponse(['status' => 'error', 'message' => 'درخواست نامعتبر است.']);

    $attId = (int)$_POST['attachment_id'];

    $findStmt = $db->prepare("
        SELECT na.id, na.note_id, na.user_id, na.file_path,
               tn.user_id AS note_owner, tn.task_id
        FROM note_attachments na
        INNER JOIN task_notes tn ON tn.id = na.note_id
        WHERE na.id = ?
        LIMIT 1
    ");
    $findStmt->execute([$attId]);
    $attRow = $findStmt->fetch(PDO::FETCH_ASSOC);

    if (!$attRow) jsonResponse(['status' => 'error', 'message' => 'فایل یافت نشد.']);

    $canDelete = ((int)$attRow['user_id'] === $userId) || ((int)$attRow['note_owner'] === $userId);

    if (!$canDelete) {
        $perm = checkTaskPermission($db, (int)$attRow['task_id'], $userId, 'notes');
        if ($perm['allowed']) $canDelete = true;
    }

    if (!$canDelete) jsonResponse(['status' => 'error', 'message' => 'دسترسی ندارید.']);

    $filePath = __DIR__ . '/../uploads/note_attachments/' . basename($attRow['file_path']);
    if (is_file($filePath)) @unlink($filePath);

    $db->prepare("DELETE FROM note_attachments WHERE id = ?")->execute([$attId]);

    jsonResponse(['status' => 'success']);
}

// ================== پیوست‌های تسک ==================
if (isset($_GET['get_attachments']) && isset($_GET['task_id'])) {
    $taskId = (int)$_GET['task_id'];

    $accessChk = $db->prepare("SELECT id FROM tasks WHERE id = ? AND (user_id = ? OR assignee_id = ?)");
    $accessChk->execute([$taskId, $userId, $userId]);
    if (!$accessChk->fetchColumn()) jsonResponse(['status' => 'error', 'message' => 'دسترسی ندارید.']);

    $stmt = $db->prepare("
        SELECT id, task_id, user_id, file_name, file_path, file_size, file_ext, file_mime, created_at
        FROM task_attachments
        WHERE task_id = ?
        ORDER BY created_at ASC
    ");
    $stmt->execute([$taskId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as &$row) {
        $row['id']        = (int)$row['id'];
        $row['task_id']   = (int)$row['task_id'];
        $row['user_id']   = (int)$row['user_id'];
        $row['file_size'] = (int)$row['file_size'];
        $row['file_size_human'] = humanFileSize($row['file_size']);
        $row['is_mine']   = ((int)$row['user_id'] === $userId);
        $row['icon']      = attachmentIconClass($row['file_ext'] ?? '');
        $row['url']       = '../uploads/task_attachments/' . rawurlencode($row['file_path']);
        $row['persian_date'] = formatPersianDate($row['created_at']);
    }
    unset($row);

    jsonResponse(['status' => 'success', 'attachments' => $rows]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_attachment'], $_POST['attachment_id'])) {
    if (!verifyCsrf()) jsonResponse(['status' => 'error', 'message' => 'درخواست نامعتبر است.']);

    $attId = (int)$_POST['attachment_id'];

    $findStmt = $db->prepare("
        SELECT ta.id, ta.task_id, ta.user_id, ta.file_path, t.user_id AS task_owner
        FROM task_attachments ta
        INNER JOIN tasks t ON t.id = ta.task_id
        WHERE ta.id = ?
        LIMIT 1
    ");
    $findStmt->execute([$attId]);
    $attRow = $findStmt->fetch(PDO::FETCH_ASSOC);

    if (!$attRow) jsonResponse(['status' => 'error', 'message' => 'فایل یافت نشد.']);

    $taskId = (int)$attRow['task_id'];
    $canDelete = ((int)$attRow['user_id'] === $userId) || ((int)$attRow['task_owner'] === $userId);

    if (!$canDelete) {
        $perm = checkTaskPermission($db, $taskId, $userId, 'notes');
        if ($perm['allowed']) $canDelete = true;
    }

    if (!$canDelete) jsonResponse(['status' => 'error', 'message' => 'دسترسی ندارید.']);

    $filePath = __DIR__ . '/../uploads/task_attachments/' . basename($attRow['file_path']);
    if (is_file($filePath)) {
        @unlink($filePath);
    }

    $db->prepare("DELETE FROM task_attachments WHERE id = ?")->execute([$attId]);

    $countStmt = $db->prepare("SELECT COUNT(*) FROM task_attachments WHERE task_id = ?");
    $countStmt->execute([$taskId]);

    jsonResponse(['status' => 'success', 'task_id' => $taskId, 'new_count' => (int)$countStmt->fetchColumn()]);
}

// ================== toggle ==================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle'])) {
    $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']);

    if (!verifyCsrf()) {
        if ($isAjax) jsonResponse(['status' => 'error', 'message' => 'درخواست نامعتبر است.']);
        redirectSelf('درخواست نامعتبر است.', 'danger');
    }

    $taskId = (int)$_POST['toggle'];
    $perm = checkTaskPermission($db, $taskId, $userId, 'complete');

    if (!$perm['allowed'] && $perm['task'] && (int)$perm['task']['assignee_id'] === $userId) {
        $perm['allowed'] = true;
    }

    if ($perm['allowed']) {
        $current = (int)$perm['task']['is_completed'];
        if ($current === 1) {
            $db->prepare("UPDATE tasks SET is_completed = 0, completed_at = NULL WHERE id = ?")->execute([$taskId]);
        } else {
            $db->prepare("UPDATE tasks SET is_completed = 1, completed_at = NOW() WHERE id = ?")->execute([$taskId]);
        }
    }

    if ($isAjax) {
        jsonResponse(['status' => $perm['allowed'] ? 'success' : 'error', 'message' => $perm['allowed'] ? '' : 'دسترسی ندارید.']);
    }
    redirectSelf();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete'])) {
    if (!verifyCsrf()) redirectSelf('درخواست نامعتبر است.', 'danger');

    $taskId = (int)$_POST['delete'];
    $perm = checkTaskPermission($db, $taskId, $userId, 'delete');

    if ($perm['allowed']) {
        try {
            $attStmt = $db->prepare("SELECT file_path FROM task_attachments WHERE task_id = ?");
            $attStmt->execute([$taskId]);
            $attFiles = $attStmt->fetchAll(PDO::FETCH_COLUMN);
            foreach ($attFiles as $fp) {
                $full = __DIR__ . '/../uploads/task_attachments/' . basename($fp);
                if (is_file($full)) @unlink($full);
            }
        } catch (PDOException $e) {
            error_log('delete attachments error: ' . $e->getMessage());
        }

        try {
            $naStmt = $db->prepare("
                SELECT na.file_path
                FROM note_attachments na
                INNER JOIN task_notes tn ON tn.id = na.note_id
                WHERE tn.task_id = ?
            ");
            $naStmt->execute([$taskId]);
            $naFiles = $naStmt->fetchAll(PDO::FETCH_COLUMN);
            foreach ($naFiles as $fp) {
                $full = __DIR__ . '/../uploads/note_attachments/' . basename($fp);
                if (is_file($full)) @unlink($full);
            }
        } catch (PDOException $e) {
            error_log('delete note attachments error: ' . $e->getMessage());
        }

        $db->prepare("DELETE FROM tasks WHERE id = ?")->execute([$taskId]);
        $db->prepare("DELETE FROM task_notes WHERE task_id = ?")->execute([$taskId]);
        try { $db->prepare("DELETE FROM task_attachments WHERE task_id = ?")->execute([$taskId]); } catch (PDOException $e) {}
        redirectSelf('وظیفه حذف شد.');
    }
    redirectSelf('دسترسی ندارید.', 'danger');
}

// ================== آمار ==================
$stats = ['total' => 0, 'completed' => 0, 'pending' => 0, 'high' => 0, 'medium' => 0, 'low' => 0];
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
    if ($row) {
        $stats = [
            'total' => (int)$row['total'],
            'completed' => (int)$row['completed'],
            'pending' => (int)$row['pending'],
            'high' => (int)$row['high'],
            'medium' => (int)$row['medium'],
            'low' => (int)$row['low'],
        ];
    }
} catch (PDOException $e) {
    error_log('stats error: ' . $e->getMessage());
}

$cTotal = $stats['total'];
$cComp = $stats['completed'];
$cPend = $stats['pending'];
$cHigh = $stats['high'];
$cMed = $stats['medium'];
$cLow = $stats['low'];
$ratio = $cTotal > 0 ? round(($cComp / $cTotal) * 100) : 0;

$cToday = 0;
try {
    $stmt = $db->prepare("SELECT COUNT(*) FROM tasks WHERE assignee_id = ? AND DATE(created_at) = CURDATE()");
    $stmt->execute([$userId]);
    $cToday = (int)$stmt->fetchColumn();
} catch (PDOException $e) {}

$cAssignedByMe = 0;
$cAssignedToMe = 0;
try {
    $stmt = $db->prepare("
        SELECT 
            COALESCE(SUM(CASE WHEN user_id = ? AND assignee_id != ? THEN 1 ELSE 0 END), 0) AS by_me,
            COALESCE(SUM(CASE WHEN assignee_id = ? AND user_id != ? THEN 1 ELSE 0 END), 0) AS to_me
        FROM tasks
        WHERE user_id = ? OR assignee_id = ?
    ");
    $stmt->execute([$userId, $userId, $userId, $userId, $userId, $userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $cAssignedByMe = (int)$row['by_me'];
        $cAssignedToMe = (int)$row['to_me'];
    }
} catch (PDOException $e) {}

$cSub = 0;
try {
    $stmt = $db->prepare("SELECT COUNT(*) FROM subjects WHERE user_id = ?");
    $stmt->execute([$userId]);
    $cSub = (int)$stmt->fetchColumn();
} catch (PDOException $e) {}

$topSubName = 'بدون موضوع';
try {
    $stmt = $db->prepare("
        SELECT s.title, COUNT(t.id) AS cnt
        FROM subjects s
        LEFT JOIN tasks t ON s.id = t.subject_id
        WHERE s.user_id = ?
        GROUP BY s.id
        ORDER BY cnt DESC
        LIMIT 1
    ");
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) $topSubName = $row['title'];
} catch (PDOException $e) {}

$cTkTotal = $cTkDone = $cTkPending = $cTkVIP = $cTkHigh = $cTkToday = 0;
try {
    $stmt = $db->query("
        SELECT 
            COUNT(*) AS total,
            COALESCE(SUM(CASE WHEN status = 1 THEN 1 ELSE 0 END), 0) AS done,
            COALESCE(SUM(CASE WHEN status = 0 THEN 1 ELSE 0 END), 0) AS pending,
            COALESCE(SUM(CASE WHEN is_vip = 1 THEN 1 ELSE 0 END), 0) AS vip,
            COALESCE(SUM(CASE WHEN priority = 'very_high' THEN 1 ELSE 0 END), 0) AS high,
            COALESCE(SUM(CASE WHEN DATE(created_at) = CURDATE() THEN 1 ELSE 0 END), 0) AS today
        FROM tickets
    ");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $cTkTotal = (int)$row['total'];
        $cTkDone = (int)$row['done'];
        $cTkPending = (int)$row['pending'];
        $cTkVIP = (int)$row['vip'];
        $cTkHigh = (int)$row['high'];
        $cTkToday = (int)$row['today'];
    }
} catch (PDOException $e) {
    error_log('tickets stats error: ' . $e->getMessage());
}

$cTxTotal = 0;
try {
    $stmt = $db->prepare("SELECT COUNT(*) FROM texts WHERE user_id = ?");
    $stmt->execute([$userId]);
    $cTxTotal = (int)$stmt->fetchColumn();
} catch (PDOException $e) {}

$cCrTotal = $cCrNew = $cCrDone = 0;
try {
    $stmt = $db->prepare("
        SELECT 
            COUNT(*) AS total,
            COALESCE(SUM(CASE WHEN status = 0 THEN 1 ELSE 0 END), 0) AS new,
            COALESCE(SUM(CASE WHEN status = 1 THEN 1 ELSE 0 END), 0) AS done
        FROM call_requests
        WHERE user_id = ? OR assignee_id = ?
    ");
    $stmt->execute([$userId, $userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $cCrTotal = (int)$row['total'];
        $cCrNew = (int)$row['new'];
        $cCrDone = (int)$row['done'];
    }
} catch (PDOException $e) {}

$trendDays = [];
$trendCounts = [];
$tkTrendCounts = [];

try {
    $stmt = $db->prepare("
        SELECT DATE(created_at) AS d, COUNT(*) AS c
        FROM tasks
        WHERE assignee_id = ? AND created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
        GROUP BY DATE(created_at)
    ");
    $stmt->execute([$userId]);
    $taskDaily = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $taskDaily[$row['d']] = (int)$row['c'];
    }

    $stmt = $db->query("
        SELECT DATE(created_at) AS d, COUNT(*) AS c
        FROM tickets
        WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
        GROUP BY DATE(created_at)
    ");
    $ticketDaily = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $ticketDaily[$row['d']] = (int)$row['c'];
    }

    for ($i = 6; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-$i days"));
        $trendDays[] = date('m/d', strtotime("-$i days"));
        $trendCounts[] = $taskDaily[$date] ?? 0;
        $tkTrendCounts[] = $ticketDaily[$date] ?? 0;
    }
} catch (PDOException $e) {
    error_log('trend error: ' . $e->getMessage());
    for ($i = 6; $i >= 0; $i--) {
        $trendDays[] = date('m/d', strtotime("-$i days"));
        $trendCounts[] = 0;
        $tkTrendCounts[] = 0;
    }
}

$taskAvgHours = $taskMinHours = $taskMaxHours = 0;
$taskDoneCount = 0;
try {
    $stmt = $db->prepare("
        SELECT 
            AVG(TIMESTAMPDIFF(MINUTE, created_at, completed_at)) / 60 AS avg_hours,
            MIN(TIMESTAMPDIFF(MINUTE, created_at, completed_at)) / 60 AS min_hours,
            MAX(TIMESTAMPDIFF(MINUTE, created_at, completed_at)) / 60 AS max_hours,
            COUNT(*) AS done_count
        FROM tasks 
        WHERE (user_id = ? OR assignee_id = ?)
          AND is_completed = 1 
          AND completed_at IS NOT NULL
          AND completed_at > created_at
    ");
    $stmt->execute([$userId, $userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $taskAvgHours = round((float)($row['avg_hours'] ?? 0), 4);
        $taskMinHours = round((float)($row['min_hours'] ?? 0), 4);
        $taskMaxHours = round((float)($row['max_hours'] ?? 0), 4);
        $taskDoneCount = (int)($row['done_count'] ?? 0);
    }
} catch (PDOException $e) {
    error_log('task avg time error: ' . $e->getMessage());
}

$callAvgHours = $callMinHours = $callMaxHours = 0;
$callDoneCount = 0;
try {
    $stmt = $db->prepare("
        SELECT 
            AVG(TIMESTAMPDIFF(MINUTE, created_at, completed_at)) / 60 AS avg_hours,
            MIN(TIMESTAMPDIFF(MINUTE, created_at, completed_at)) / 60 AS min_hours,
            MAX(TIMESTAMPDIFF(MINUTE, created_at, completed_at)) / 60 AS max_hours,
            COUNT(*) AS done_count
        FROM call_requests 
        WHERE (user_id = ? OR assignee_id = ?) 
          AND status = 1 
          AND completed_at IS NOT NULL
          AND completed_at > created_at
    ");
    $stmt->execute([$userId, $userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $callAvgHours = round((float)($row['avg_hours'] ?? 0), 4);
        $callMinHours = round((float)($row['min_hours'] ?? 0), 4);
        $callMaxHours = round((float)($row['max_hours'] ?? 0), 4);
        $callDoneCount = (int)($row['done_count'] ?? 0);
    }
} catch (PDOException $e) {
    error_log('call avg time error: ' . $e->getMessage());
}

$ticketAvgHours = $ticketMinHours = $ticketMaxHours = 0;
$ticketDoneCount = 0;
try {
    $stmt = $db->query("
        SELECT 
            AVG(TIMESTAMPDIFF(MINUTE, created_at, completed_at)) / 60 AS avg_hours,
            MIN(TIMESTAMPDIFF(MINUTE, created_at, completed_at)) / 60 AS min_hours,
            MAX(TIMESTAMPDIFF(MINUTE, created_at, completed_at)) / 60 AS max_hours,
            COUNT(*) AS done_count
        FROM tickets 
        WHERE status = 1 
          AND completed_at IS NOT NULL
          AND completed_at > created_at
    ");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $ticketAvgHours = round((float)($row['avg_hours'] ?? 0), 4);
        $ticketMinHours = round((float)($row['min_hours'] ?? 0), 4);
        $ticketMaxHours = round((float)($row['max_hours'] ?? 0), 4);
        $ticketDoneCount = (int)($row['done_count'] ?? 0);
    }
} catch (PDOException $e) {
    error_log('ticket avg time error: ' . $e->getMessage());
}

function formatDuration($hours) {
    $hours = (float)$hours;
    if ($hours <= 0) return '—';

    if ($hours < 1) {
        $minutes = round($hours * 60);
        return $minutes < 1 ? 'کمتر از ۱ دقیقه' : $minutes . ' دقیقه';
    }
    if ($hours < 24) {
        $h = floor($hours);
        $m = round(($hours - $h) * 60);
        return $m == 0 ? $h . ' ساعت' : $h . ' ساعت و ' . $m . ' دقیقه';
    }
    $days = floor($hours / 24);
    $remH = $hours - ($days * 24);
    $h = floor($remH);
    $m = round(($remH - $h) * 60);
    $result = $days . ' روز';
    if ($h > 0) $result .= ' و ' . $h . ' ساعت';
    if ($m > 0 && $h == 0) $result .= ' و ' . $m . ' دقیقه';
    return $result;
}

// ================== لیست‌ها ==================
$subjects = [];
try {
    $stmt = $db->prepare("SELECT * FROM subjects WHERE user_id = ? ORDER BY title ASC");
    $stmt->execute([$userId]);
    $subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}

$tasks = [];
try {
    $stmt = $db->prepare("
        SELECT
            t.*,
            s.title AS subject_title,
            p.title AS project_title,
            p.profile_image AS project_image,
            ua.first_name AS assignee_first_name,
            ua.last_name AS assignee_last_name,
            ua.mobile AS assignee_mobile,
            uc.first_name AS creator_first_name,
            uc.last_name AS creator_last_name,
            uc.mobile AS creator_mobile,
            cc.permissions AS my_task_permissions,
            (SELECT COUNT(*) FROM task_notes tn WHERE tn.task_id = t.id) AS notes_count,
            (SELECT COUNT(*) FROM task_attachments ta WHERE ta.task_id = t.id) AS attachments_count
        FROM tasks t
        LEFT JOIN subjects s ON t.subject_id = s.id
        LEFT JOIN projects p ON t.project_id = p.id
        LEFT JOIN users ua ON ua.id = t.assignee_id
        LEFT JOIN users uc ON uc.id = t.user_id
        LEFT JOIN colleagues cc ON cc.user_id = t.user_id AND cc.colleague_user_id = ?
        WHERE t.user_id = ? OR t.assignee_id = ?
        ORDER BY t.created_at DESC
    ");
    $stmt->execute([$userId, $userId, $userId]);
    $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('tasks load error: ' . $e->getMessage());
}

$myTasksAll = array_values(array_filter($tasks, fn($t) => (int)($t['assignee_id'] ?? 0) === $userId));
$othersTasksAll = array_values(array_filter($tasks, fn($t) => (int)$t['user_id'] === $userId && (int)($t['assignee_id'] ?? 0) !== $userId));

$myTasksUncompleted      = array_values(array_filter($myTasksAll,     fn($t) => !$t['is_completed']));
$myTasksCompleted        = array_values(array_filter($myTasksAll,     fn($t) => (bool)$t['is_completed']));
$othersTasksUncompleted  = array_values(array_filter($othersTasksAll, fn($t) => !$t['is_completed']));
$othersTasksCompleted    = array_values(array_filter($othersTasksAll, fn($t) => (bool)$t['is_completed']));

// ================== اعمال صفحه‌بندی ==================
$pageMineUncompleted     = getPageParam('p_mu');
$pageMineCompleted       = getPageParam('p_mc');
$pageOthersUncompleted   = getPageParam('p_ou');
$pageOthersCompleted     = getPageParam('p_oc');

list($myTasksUncompletedPaged,     $pageMineUncompleted,   $totalPagesMineUncompleted,   $totalCountMineUncompleted)   = paginateArray($myTasksUncompleted,   $pageMineUncompleted,   $TASKS_PER_PAGE);
list($myTasksCompletedPaged,       $pageMineCompleted,     $totalPagesMineCompleted,     $totalCountMineCompleted)     = paginateArray($myTasksCompleted,     $pageMineCompleted,     $TASKS_PER_PAGE);
list($othersTasksUncompletedPaged, $pageOthersUncompleted, $totalPagesOthersUncompleted, $totalCountOthersUncompleted) = paginateArray($othersTasksUncompleted, $pageOthersUncompleted, $TASKS_PER_PAGE);
list($othersTasksCompletedPaged,   $pageOthersCompleted,   $totalPagesOthersCompleted,   $totalCountOthersCompleted)   = paginateArray($othersTasksCompleted,   $pageOthersCompleted,   $TASKS_PER_PAGE);

$displayUser = $_SESSION['user_mobile'] ?? $_SESSION['mobile'] ?? $_SESSION['username'] ?? 'کاربر';

$makeAvatarUrl = function($file) {
    if (empty($file)) return null;
    $base = basename($file);
    if (!preg_match('/^[A-Za-z0-9_.\-]+$/', $base)) return null;
    if (file_exists(__DIR__ . '/../uploads/avatars/' . $base)) {
        return '../uploads/avatars/' . rawurlencode($base);
    }
    return null;
};
$makeProjectImageUrl = function($file) {
    if (empty($file)) return null;
    $base = basename($file);
    if (!preg_match('/^[A-Za-z0-9_.\-]+$/', $base)) return null;
    if (file_exists(__DIR__ . '/../uploads/projects/' . $base)) {
        return '../uploads/projects/' . rawurlencode($base);
    }
    return null;
};

// ================== پردازش تسک اشتراکی (لینک) ==================
$sharedTaskData = null;
$sharedCanEdit = false;
$sharedCanReassign = false;
$sharedCanChangeProject = false;
$sharedCanComplete = false;

if ($sharedTaskParam !== '' && preg_match('/^[a-f0-9]{16,64}$/i', $sharedTaskParam)) {
    try {
        $sharedStmt = $db->prepare("
            SELECT t.*,
                   s.title AS subject_title,
                   p.title AS project_title,
                   p.profile_image AS project_image,
                   ua.first_name AS assignee_first_name,
                   ua.last_name AS assignee_last_name,
                   ua.mobile AS assignee_mobile,
                   uc.first_name AS creator_first_name,
                   uc.last_name AS creator_last_name,
                   uc.mobile AS creator_mobile,
                   (SELECT COUNT(*) FROM task_attachments ta WHERE ta.task_id = t.id) AS attachments_count
            FROM tasks t
            LEFT JOIN subjects s ON t.subject_id = s.id
            LEFT JOIN projects p ON t.project_id = p.id
            LEFT JOIN users ua ON ua.id = t.assignee_id
            LEFT JOIN users uc ON uc.id = t.user_id
            WHERE t.share_token = ?
            LIMIT 1
        ");
        $sharedStmt->execute([$sharedTaskParam]);
        $sharedTask = $sharedStmt->fetch(PDO::FETCH_ASSOC);

        if ($sharedTask) {
            $hasAccess = false;

            if ((int)$sharedTask['user_id'] === $userId) {
                $hasAccess = true;
            } else if (!empty($sharedTask['project_id'])) {
                $chk = $db->prepare("SELECT 1 FROM project_members WHERE project_id = ? AND user_id = ? LIMIT 1");
                $chk->execute([(int)$sharedTask['project_id'], $userId]);
                if ($chk->fetchColumn()) $hasAccess = true;
            }

            if ($hasAccess) {
                $hasProject = !empty($sharedTask['project_id']) && !empty($sharedTask['project_title']);
                $projectInitial = $hasProject ? mb_substr(trim($sharedTask['project_title']), 0, 1, 'UTF-8') : '';
                $projectImgUrl = $hasProject ? $makeProjectImageUrl($sharedTask['project_image'] ?? null) : null;

                $assigneeName = trim(($sharedTask['assignee_first_name'] ?? '') . ' ' . ($sharedTask['assignee_last_name'] ?? ''));
                $creatorName  = trim(($sharedTask['creator_first_name'] ?? '') . ' ' . ($sharedTask['creator_last_name'] ?? ''));
                $assigneeMobile = $sharedTask['assignee_mobile'] ?? '';
                $creatorMobile  = $sharedTask['creator_mobile'] ?? '';
                if ($assigneeName === '') $assigneeName = $assigneeMobile;
                if ($creatorName === '')  $creatorName  = $creatorMobile ?: 'کاربر';

                $priorityLabels = ['low' => 'کم', 'medium' => 'متوسط', 'high' => 'زیاد'];
                $priorityLabel = $priorityLabels[$sharedTask['priority']] ?? $sharedTask['priority'];

                $sharedTaskData = [
                    'id' => (int)$sharedTask['id'],
                    'title' => (string)$sharedTask['title'],
                    'subject_id' => $sharedTask['subject_id'],
                    'subject_title' => (string)($sharedTask['subject_title'] ?? ''),
                    'project_id' => $sharedTask['project_id'],
                    'project_title' => (string)($sharedTask['project_title'] ?? ''),
                    'project_image_url' => $projectImgUrl,
                    'project_initial' => $projectInitial,
                    'assignee_id' => (int)$sharedTask['assignee_id'],
                    'assignee_name' => $assigneeName,
                    'creator_name' => $creatorName,
                    'priority' => (string)$sharedTask['priority'],
                    'priority_label' => $priorityLabel,
                    'due_date' => $sharedTask['due_date'] ? formatPersianDateOnly($sharedTask['due_date'] . ' 00:00:00') : '',
                    'due_date_raw' => $sharedTask['due_date'],
                    'description' => (string)($sharedTask['description'] ?? ''),
                    'attachments_count' => (int)($sharedTask['attachments_count'] ?? 0),
                ];

                if ((int)$sharedTask['user_id'] === $userId) {
                    $sharedCanEdit = $sharedCanReassign = $sharedCanChangeProject = $sharedCanComplete = true;
                } else {
                    $permStmt = $db->prepare("SELECT permissions FROM colleagues WHERE user_id = ? AND colleague_user_id = ? LIMIT 1");
                    $permStmt->execute([(int)$sharedTask['user_id'], $userId]);
                    $permRow = $permStmt->fetch(PDO::FETCH_ASSOC);
                    if ($permRow) {
                        $perms = json_decode($permRow['permissions'] ?? '[]', true);
                        if (is_array($perms)) {
                            $sharedCanEdit = in_array('edit', $perms, true);
                            $sharedCanReassign = in_array('reassign', $perms, true);
                            $sharedCanChangeProject = in_array('change_project', $perms, true);
                            $sharedCanComplete = in_array('complete', $perms, true);
                        }
                    }
                }
            } else {
                $_SESSION['flash_msg'] = 'شما به این وظیفه دسترسی ندارید. برای دسترسی، باید عضو پروژه این وظیفه باشید.';
                $_SESSION['flash_type'] = 'danger';
                header('Location: index.php?page=list');
                exit;
            }
        } else {
            $_SESSION['flash_msg'] = 'وظیفه یافت نشد یا لینک نامعتبر است.';
            $_SESSION['flash_type'] = 'danger';
            header('Location: index.php?page=list');
            exit;
        }
    } catch (PDOException $e) {
        error_log('shared task load error: ' . $e->getMessage());
    }
}

$assigneeOptionsJson = safeJsonEncode([
    'self' => [
        'id' => $userId,
        'name' => 'خودم',
        'mobile' => $displayUser,
        'initial' => mb_substr($currentUserFirstName, 0, 1, 'UTF-8'),
        'avatar_url' => $currentUserAvatar,
        'is_self' => true,
    ],
    'colleagues' => array_map(function($c) use ($makeAvatarUrl) {
        $fullName = trim(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? ''));
        if ($fullName === '') $fullName = $c['mobile'];
        return [
            'id' => (int)$c['id'],
            'name' => $fullName,
            'mobile' => $c['mobile'],
            'initial' => mb_substr(trim($c['first_name'] ?: $c['mobile']), 0, 1, 'UTF-8'),
            'avatar_url' => $makeAvatarUrl($c['avatar'] ?? null),
            'is_self' => false,
        ];
    }, $colleaguesList),
]);

$projectOptionsJson = safeJsonEncode(array_map(function($p) use ($makeProjectImageUrl) {
    $title = $p['title'] ?? '';
    return [
        'id' => (int)$p['id'],
        'title' => $title,
        'initial' => mb_substr(trim($title), 0, 1, 'UTF-8'),
        'image_url' => $makeProjectImageUrl($p['profile_image'] ?? null),
        'is_creator' => (bool)$p['is_creator'],
    ];
}, $userProjects));

$projectsMembersJson = safeJsonEncode($projectsMembersMap);

$mentionOptions = [];
$seenMentionIds = [];
foreach ($colleaguesList as $c) {
    $cid = (int)$c['id'];
    if ($cid === $userId || isset($seenMentionIds[$cid])) continue;
    $fullName = trim(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? ''));
    if ($fullName === '') $fullName = $c['mobile'];
    $mentionOptions[] = [
        'id' => $cid,
        'name' => $fullName,
        'initial' => mb_substr(trim($c['first_name'] ?: $c['mobile']), 0, 1, 'UTF-8'),
        'avatar_url' => $makeAvatarUrl($c['avatar'] ?? null),
    ];
    $seenMentionIds[$cid] = true;
}
foreach ($projectsMembersMap as $pid => $members) {
    foreach ($members as $m) {
        $mid = (int)$m['id'];
        if ($mid === $userId || isset($seenMentionIds[$mid])) continue;
        $mentionOptions[] = [
            'id' => $mid,
            'name' => $m['name'],
            'initial' => $m['initial'],
            'avatar_url' => $m['avatar_url'],
        ];
        $seenMentionIds[$mid] = true;
    }
}
$mentionOptionsJson = safeJsonEncode($mentionOptions);

$formTokenTask    = formToken('task');
$formTokenSubject = formToken('subject');

$allowedExtsForUi = getAllowedAttachmentRules()['ext'];
$allowedExtsAttr = implode(',', array_map(fn($e) => '.' . $e, $allowedExtsForUi));

if (ob_get_level() > 0) {
    ob_end_flush();
}

require_once __DIR__ . '/../includes/sidebar.php';
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <title>تسکام</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="icon" href="https://img.icons8.com/color/48/dashboard-layout.png" type="image/png">

    <!-- ⭐ تقویم شمسی -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/persian-datepicker@1.2.0/dist/css/persian-datepicker.min.css">

    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/persian-date@1.1.0/dist/persian-date.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/persian-datepicker@1.2.0/dist/js/persian-datepicker.min.js"></script>
<style>
    :root {
        --primary: #2b3452;
        --accent: #4c8bf5;
        --accent-hover: #2f6bdc;
        --bg: #f4f6fb;
        --card: #ffffff;
        --text: #2b3452;
        --text-soft: #8a94ad;
        --border: #eef1f8;
        --success: #2ebc8a;
        --warning: #ff8a3d;
        --danger: #f54e7a;
        --radius: 18px;
        --shadow: 0 8px 24px rgba(76, 108, 200, 0.06);
    }
    * { box-sizing: border-box; }
    html, body { max-width: 100%; overflow-x: hidden; }
    body {
        background: var(--bg);
        color: var(--text);
        font-family: 'Segoe UI', Tahoma, sans-serif;
        margin: 0;
        display: flex;
        height: 100vh;
        overflow: hidden;
        -webkit-font-smoothing: antialiased;
    }

    .sidebar {
        background: linear-gradient(180deg, #4c8bf5 0%, #2f6bdc 100%) !important;
        color: #fff !important;
        box-shadow: -6px 0 28px rgba(76, 139, 245, 0.18);
    }
    .sidebar-brand { border-bottom: 1px solid rgba(255,255,255,0.15) !important; }
    .sidebar .menu-item { color: rgba(255,255,255,0.85) !important; }
    .sidebar .menu-item:hover,
    .sidebar .menu-item.active {
        background: rgba(255,255,255,0.18) !important;
        color: #fff !important;
    }
    .sidebar .submenu a { color: rgba(255,255,255,0.75) !important; }
    .sidebar .submenu a:hover,
    .sidebar .submenu a.active {
        color: #fff !important;
        background: rgba(255,255,255,0.12) !important;
    }
    .menu-parent { color: rgba(255,255,255,0.82) !important; }
    .menu-parent:hover,
    .menu-parent.is-open,
    .menu-parent.is-current {
        background: rgba(255,255,255,0.14) !important;
        color: #fff !important;
    }
    .submenu-inner a { color: rgba(255,255,255,0.78) !important; }
    .submenu-inner a:hover,
    .submenu-inner a.active {
        color: #fff !important;
        background: rgba(255,255,255,0.14) !important;
    }

    .main-wrapper { flex: 1; display: flex; flex-direction: column; overflow: hidden; min-width: 0; }

    .topbar {
        background: transparent;
        padding: 22px 32px 8px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 12px;
    }
    .topbar h1 {
        margin: 0;
        font-size: 1.2rem;
        font-weight: 700;
        color: var(--text);
        line-height: 1.4;
    }
    .topbar h1 small {
        display: block;
        font-size: 0.78rem;
        color: var(--text-soft);
        font-weight: 400;
        margin-top: 4px;
    }
    .topbar-user {
        display: flex;
        align-items: center;
        gap: 10px;
        background: #fff;
        padding: 6px 14px 6px 6px;
        border-radius: 30px;
        box-shadow: var(--shadow);
        font-size: 0.85rem;
        font-weight: 600;
        color: var(--text);
    }
    .topbar-user .avatar {
        width: 34px; height: 34px;
        border-radius: 50%;
        background: linear-gradient(135deg, #4c8bf5, #2f6bdc);
        color: #fff;
        display: flex; align-items: center; justify-content: center;
        font-weight: 700;
        font-size: 0.85rem;
        overflow: hidden;
        flex-shrink: 0;
    }
    .topbar-user .avatar img { width: 100%; height: 100%; object-fit: cover; }

    .content-area {
        flex: 1;
        padding: 18px 32px 32px;
        overflow-y: auto;
        overflow-x: hidden;
        -webkit-overflow-scrolling: touch;
        overscroll-behavior: contain;
        transform: translateZ(0);
        will-change: scroll-position;
    }

    .flash {
        padding: 12px 16px;
        border-radius: 12px;
        margin-bottom: 18px;
        font-weight: 600;
        font-size: 0.88rem;
    }
    .flash-success { background: #e3f8ef; color: #0e7a55; border: 1px solid #a7f3d0; }
    .flash-warning { background: #fff3e0; color: #92400e; border: 1px solid #fde68a; }
    .flash-danger  { background: #ffe4ec; color: #991b1b; border: 1px solid #fecaca; }

    /* ⭐ Main Tabs (وظایف من / وظایف دیگران) */
    .main-tabs-header {
        display: flex;
        gap: 8px;
        margin-bottom: 22px;
        background: #fff;
        padding: 6px;
        border-radius: 16px;
        box-shadow: var(--shadow);
        border: 1px solid var(--border);
        width: fit-content;
        max-width: 100%;
    }
    .main-tab-btn {
        background: transparent;
        border: none;
        padding: 12px 26px;
        font-weight: 700;
        font-size: 0.92rem;
        cursor: pointer;
        color: var(--text-soft);
        border-radius: 12px;
        transition: all 0.2s ease;
        font-family: inherit;
        display: inline-flex;
        align-items: center;
        gap: 9px;
        white-space: nowrap;
    }
    .main-tab-btn:hover:not(.active) {
        background: #f1f4fb;
        color: var(--text);
    }
    .main-tab-btn.active {
        background: linear-gradient(135deg, #4c8bf5 0%, #2f6bdc 100%);
        color: #fff;
        box-shadow: 0 6px 16px rgba(76, 139, 245, 0.3);
    }
    .main-tab-btn i { font-size: 0.85rem; }
    .main-tab-badge {
        background: #f1f4fb;
        color: var(--text-soft);
        padding: 2px 9px;
        border-radius: 8px;
        font-size: 0.7rem;
        font-weight: 700;
        min-width: 22px;
        text-align: center;
    }
    .main-tab-btn.active .main-tab-badge {
        background: rgba(255,255,255,0.25);
        color: #fff;
    }
    .main-tab-content { display: none; }
    .main-tab-content.active { display: block; }

    /* ⭐ Pagination */
    .pagination {
        display: flex;
        gap: 6px;
        justify-content: center;
        align-items: center;
        margin-top: 18px;
        margin-bottom: 8px;
        flex-wrap: wrap;
        padding: 8px 0;
    }
    .page-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 38px;
        height: 38px;
        padding: 0 10px;
        border-radius: 10px;
        background: #f1f4fb;
        color: var(--text);
        font-weight: 700;
        font-size: 0.85rem;
        text-decoration: none;
        transition: all 0.15s ease;
        border: 1px solid var(--border);
        font-family: inherit;
        cursor: pointer;
    }
    .page-btn:hover {
        background: #e7f0ff;
        color: #2f6bdc;
        border-color: #c7d7ff;
    }
    .page-btn.active {
        background: linear-gradient(135deg, #4c8bf5 0%, #2f6bdc 100%);
        color: #fff;
        border-color: #4c8bf5;
        box-shadow: 0 4px 12px rgba(76, 139, 245, 0.25);
    }
    .page-btn.disabled {
        opacity: 0.4;
        cursor: not-allowed;
        pointer-events: none;
    }
    .page-dots {
        color: var(--text-soft);
        padding: 0 6px;
        font-weight: 700;
        user-select: none;
    }
    .page-info {
        color: var(--text-soft);
        font-size: 0.75rem;
        font-weight: 600;
        padding: 0 8px;
    }

    .hero-stats {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 18px;
        margin-bottom: 22px;
    }
    .hero-card {
        border-radius: 20px;
        padding: 22px;
        color: #fff;
        position: relative;
        overflow: hidden;
        min-height: 150px;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
        box-shadow: 0 12px 28px rgba(0,0,0,0.08);
        transition: box-shadow 0.25s ease;
        will-change: box-shadow;
        cursor: pointer;
    }
    .hero-card:hover { box-shadow: 0 18px 36px rgba(0,0,0,0.15); }
    .hero-card::before {
        content: '';
        position: absolute;
        top: -40px; left: -40px;
        width: 140px; height: 140px;
        background: rgba(255,255,255,0.14);
        border-radius: 50%;
    }
    .hero-card::after {
        content: '';
        position: absolute;
        bottom: -50px; right: -50px;
        width: 160px; height: 160px;
        background: rgba(255,255,255,0.08);
        border-radius: 50%;
    }
    .hero-card__top {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        position: relative;
        z-index: 2;
    }
    .hero-card__icon {
        width: 44px; height: 44px;
        border-radius: 13px;
        background: rgba(255,255,255,0.24);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1rem;
    }
    .hero-card__dots {
        color: rgba(255,255,255,0.75);
        font-size: 1rem;
        cursor: pointer;
    }
    .hero-card__bottom { position: relative; z-index: 2; }
    .hero-card__value {
        font-size: 2.1rem;
        font-weight: 800;
        line-height: 1;
        letter-spacing: -1px;
    }
    .hero-card__label {
        font-size: 0.8rem;
        opacity: 0.92;
        margin-top: 6px;
        font-weight: 500;
    }

    .card-red    { background: linear-gradient(135deg, #ff6b8b 0%, #f54e7a 100%); }
    .card-orange { background: linear-gradient(135deg, #ffa751 0%, #ff8a3d 100%); }
    .card-purple { background: linear-gradient(135deg, #a26bfa 0%, #7f4cf0 100%); }
    .card-green  { background: linear-gradient(135deg, #4ed4a3 0%, #2ebc8a 100%); }

    .charts-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 18px;
        margin-bottom: 22px;
    }
    .chart-card {
        background: var(--card);
        border-radius: 20px;
        padding: 22px 24px;
        box-shadow: var(--shadow);
        border: 1px solid var(--border);
    }
    .chart-head {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 18px;
    }
    .chart-head h3 {
        font-size: 0.95rem;
        font-weight: 700;
        color: var(--text);
        display: flex;
        align-items: center;
        gap: 10px;
        margin: 0;
    }
    .chart-head h3 i {
        width: 32px; height: 32px;
        border-radius: 10px;
        display: flex; align-items: center; justify-content: center;
        font-size: 0.8rem;
    }
    .chart-head h3 i.blue  { background: #e7f0ff; color: #4c8bf5; }
    .chart-head h3 i.green { background: #e3f8ef; color: #2ebc8a; }
    .chart-head h3 i.purple { background: #f0e9ff; color: #7f4cf0; }
    .chart-head h3 i.orange { background: #fff3e0; color: #ff8a3d; }
    .chart-head .legend {
        font-size: 0.72rem;
        color: var(--text-soft);
        display: flex;
        align-items: center;
        gap: 6px;
        font-weight: 500;
    }
    .chart-head .legend .dot {
        width: 8px; height: 8px;
        border-radius: 50%;
        background: #4c8bf5;
    }
    .chart-head .legend .dot.green { background: #2ebc8a; }
    .chart-body { height: 240px; position: relative; }

    .section-label {
        margin: 26px 0 12px;
        font-size: 0.88rem;
        font-weight: 700;
        color: var(--text);
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .section-label:first-of-type { margin-top: 0; }
    .section-label .section-hint {
        font-size: 0.7rem;
        color: var(--text-soft);
        font-weight: 400;
        margin-right: 6px;
    }

    .soft-stats {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 14px;
        margin-bottom: 6px;
    }
    .soft-card {
        background: #fff;
        border-radius: 16px;
        padding: 18px;
        box-shadow: 0 4px 16px rgba(76, 108, 200, 0.05);
        border: 1px solid var(--border);
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        transition: box-shadow 0.2s ease;
    }
    .soft-card:hover { box-shadow: 0 10px 22px rgba(76, 108, 200, 0.09); }
    .soft-card__info { min-width: 0; }
    .soft-card__label {
        font-size: 0.75rem;
        color: var(--text-soft);
        margin-bottom: 4px;
        font-weight: 500;
    }
    .soft-card__value {
        font-size: 1.35rem;
        font-weight: 700;
        color: var(--text);
        line-height: 1.1;
    }
    .soft-card__icon {
        width: 42px; height: 42px;
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 0.95rem;
        flex-shrink: 0;
    }
    .soft-card__icon.blue   { background: #e7f0ff; color: #4c8bf5; }
    .soft-card__icon.green  { background: #e3f8ef; color: #2ebc8a; }
    .soft-card__icon.orange { background: #fff3e0; color: #ff8a3d; }
    .soft-card__icon.purple { background: #f0e9ff; color: #7f4cf0; }
    .soft-card__icon.pink   { background: #ffe4ec; color: #f54e7a; }
    .soft-card__icon.yellow { background: #fff7d6; color: #ca8a04; }

    .time-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
        gap: 18px;
        margin-bottom: 22px;
    }
    .time-card {
        background: #fff;
        border: 1px solid var(--border);
        border-radius: 18px;
        padding: 22px;
        display: flex;
        flex-direction: column;
        gap: 14px;
        box-shadow: var(--shadow);
    }
    .time-card-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 8px;
    }
    .time-card-title {
        display: flex;
        align-items: center;
        gap: 10px;
        font-weight: 700;
        color: var(--text);
        font-size: 0.92rem;
    }
    .time-card-count {
        font-size: 0.72rem;
        color: var(--text-soft);
        background: #f4f6fb;
        padding: 4px 10px;
        border-radius: 8px;
        font-weight: 700;
        white-space: nowrap;
    }
    .time-card-main {
        text-align: center;
        padding: 14px 0;
        border-top: 1px dashed #e6eaf3;
        border-bottom: 1px dashed #e6eaf3;
    }
    .time-card-main-label { font-size: 0.75rem; color: var(--text-soft); margin-bottom: 8px; }
    .time-card-main-value { font-size: 1.5rem; font-weight: 800; line-height: 1.2; }
    .time-card-footer { display: flex; justify-content: space-between; font-size: 0.8rem; }
    .time-card-footer > div { text-align: center; flex: 1; }
    .time-card-footer .label { color: var(--text-soft); margin-bottom: 4px; font-size: 0.72rem; }
    .time-card-footer .value { font-weight: 700; font-size: 0.88rem; }
    .time-card-divider { width: 1px; background: #eef1f8; }

    .card {
        background: var(--card);
        border: 1px solid var(--border);
        border-radius: 20px;
        padding: 24px;
        margin-bottom: 20px;
        box-shadow: var(--shadow);
    }
    .card h3 {
        margin-top: 0;
        font-size: 1rem;
        color: var(--text);
        display: flex;
        align-items: center;
        gap: 8px;
        margin-bottom: 18px;
    }

    .progress-item { margin-bottom: 18px; }
    .progress-item:last-child { margin-bottom: 0; }
    .progress-header {
        display: flex;
        justify-content: space-between;
        margin-bottom: 8px;
        font-size: 0.85rem;
        font-weight: 600;
    }
    .progress-track {
        background: #f1f4fb;
        height: 10px;
        border-radius: 10px;
        overflow: hidden;
    }
    .progress-fill {
        height: 100%;
        border-radius: 10px;
        transition: width 0.6s ease;
    }

    .summary-item {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 10px 12px;
        background: #fafbfe;
        border-radius: 12px;
        margin-bottom: 8px;
        border: 1px solid var(--border);
    }
    .summary-item:last-child { margin-bottom: 0; }
    .summary-icon {
        width: 34px; height: 34px;
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
        font-size: 0.85rem;
    }
    .summary-label { font-size: 0.85rem; font-weight: 600; color: var(--text); }
    .summary-value { font-weight: 700; font-size: 1rem; }

    .form-group { margin-bottom: 18px; }
    label {
        display: block;
        margin-bottom: 8px;
        font-weight: 600;
        font-size: 0.88rem;
        color: var(--text);
    }
    input[type="text"], input[type="date"], select, textarea {
        width: 100%;
        padding: 12px 16px;
        background: #fafbfe;
        border: 2px solid var(--border);
        border-radius: 14px;
        font-size: 0.92rem;
        font-family: inherit;
        color: var(--text);
        transition: border-color 0.2s ease, box-shadow 0.2s ease, background-color 0.2s ease;
    }
    input[type="text"]:focus, input[type="date"]:focus, select:focus, textarea:focus {
        outline: none;
        border-color: var(--accent);
        background: #fff;
        box-shadow: 0 0 0 4px rgba(76, 139, 245, 0.12);
    }

    button.btn {
        background: linear-gradient(135deg, #4c8bf5 0%, #2f6bdc 100%);
        color: #fff;
        border: none;
        padding: 11px 22px;
        border-radius: 12px;
        font-weight: 700;
        cursor: pointer;
        font-family: inherit;
        font-size: 0.9rem;
        transition: transform 0.15s ease, box-shadow 0.2s ease;
        box-shadow: 0 6px 16px rgba(76, 139, 245, 0.25);
    }
    button.btn:hover { transform: translateY(-1px); box-shadow: 0 8px 20px rgba(76, 139, 245, 0.35); }
    button.btn:active { transform: scale(0.98); }

    .tabs-header {
        display: flex;
        gap: 10px;
        margin-bottom: 20px;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
    }
    .tab-btn {
        background: #f1f4fb;
        border: none;
        padding: 9px 18px;
        font-weight: 700;
        font-size: 0.88rem;
        cursor: pointer;
        color: var(--text-soft);
        border-radius: 12px;
        transition: all 0.15s ease;
        font-family: inherit;
    }
    .tab-btn.active {
        background: linear-gradient(135deg, #4c8bf5 0%, #2f6bdc 100%);
        color: #fff;
        box-shadow: 0 4px 12px rgba(76, 139, 245, 0.25);
    }
    .tab-content { display: none; }
    .tab-content.active { display: block; }

    .task-row {
        position: relative;
        display: flex;
        align-items: center;
        justify-content: space-between;
        background: #fff;
        border: 1px solid var(--border);
        padding: 16px 20px;
        border-radius: 16px;
        margin-bottom: 12px;
        transition: border-color 0.15s ease, box-shadow 0.15s ease;
        cursor: pointer;
        box-shadow: 0 2px 8px rgba(76, 108, 200, 0.03);
        contain: layout style paint;
    }
    .task-row:hover {
        border-color: #d8e0f0;
        box-shadow: 0 4px 12px rgba(76, 108, 200, 0.06);
    }
    .task-row.animating { opacity: 0.4; }
    .task-row.no-edit { cursor: default; }
    .task-row.received-task { background: #fffdf5; }
    .task-row.assigned-task { background: #fafaff; }
    .task-row.received-task,
    .task-row.assigned-task { padding-right: 26px; }

    /* ⭐ تسک عقب‌افتاده (Overdue) */
    .task-row.overdue-task {
        background: #fff8fa !important;
        border-color: #fecdd3 !important;
        box-shadow: 0 4px 14px rgba(245, 78, 122, 0.08) !important;
    }
    .task-row.overdue-task .task-title {
        color: #881337;
    }
    .task-row.overdue-task:hover {
        border-color: #fda4af !important;
        box-shadow: 0 6px 18px rgba(245, 78, 122, 0.12) !important;
    }

    .task-info { display: flex; align-items: flex-start; gap: 16px; min-width: 0; }
    .custom-checkbox {
        width: 22px; height: 22px;
        cursor: pointer;
        accent-color: var(--success);
        flex-shrink: 0;
        margin-top: 2px;
    }
    .custom-checkbox:disabled { opacity: 0.35; cursor: not-allowed; }
    .task-title { font-weight: 700; font-size: 0.95rem; color: var(--text); }
    .task-row.completed-task .task-title { text-decoration: line-through; color: #b0b8cd; }
    .task-desc-clamp {
        font-size: 0.8rem;
        color: var(--text-soft);
        margin-top: 4px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        max-width: 100%;
        cursor: help;
    }

    /* ⭐ Meta row: مهلت + تاریخ ثبت + تاریخ انجام */
    .task-meta-row {
        display: flex;
        flex-wrap: wrap;
        gap: 6px;
        margin-top: 7px;
        align-items: center;
    }
    .task-meta {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        padding: 3px 10px;
        background: #f4f6fb;
        border-radius: 8px;
        font-size: 0.72rem;
        font-weight: 600;
        color: var(--text-soft);
        white-space: nowrap;
        line-height: 1.6;
    }
    .task-meta i { font-size: 0.68rem; opacity: 0.85; }
    .task-meta.task-meta-created {
        background: #f4f6fb;
        color: #64748b;
    }
    .task-meta.task-meta-created i { color: #94a3b8; }
    .task-meta.task-meta-due {
        background: #e7f0ff;
        color: #2f6bdc;
    }
    .task-meta.task-meta-due i { color: #4c8bf5; }
    .task-meta.task-meta-due.overdue {
        background: #ffe4ec;
        color: #c81e4a;
        font-weight: 700;
    }
    .task-meta.task-meta-due.overdue i { color: #f54e7a; }
    .task-meta.task-meta-completed {
        background: #e3f8ef;
        color: #0e7a55;
    }
    .task-meta.task-meta-completed i { color: #2ebc8a; }

    .overdue-badge {
        display: inline-flex;
        align-items: center;
        gap: 3px;
        background: linear-gradient(135deg, #ff6b8b, #f54e7a);
        color: #fff;
        padding: 1px 7px;
        border-radius: 6px;
        font-size: 0.65rem;
        font-weight: 700;
        margin-right: 3px;
        box-shadow: 0 2px 6px rgba(245, 78, 122, 0.35);
    }
    .overdue-badge i { font-size: 0.6rem; }

    .task-badges { display: inline-flex; align-items: center; gap: 6px; margin-right: 8px; vertical-align: middle; }
    .task-badge {
        display: inline-flex; align-items: center; gap: 4px;
        font-size: 0.7rem; font-weight: 700;
        padding: 3px 8px; border-radius: 8px;
        background: #f1f4fb; color: var(--text-soft);
    }
    .task-badge.badge-notes { background: #f0e9ff; color: #7f4cf0; }
    .task-badge.badge-files { background: #e0f5fb; color: #0891b2; }

    .task-info-btn {
        background: #e7f0ff;
        color: #2f6bdc;
        border: 1px solid #c7d7ff;
        width: 34px; height: 34px;
        border-radius: 10px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        font-size: 0.9rem;
        transition: all 0.2s ease;
        font-family: inherit;
        padding: 0;
        flex-shrink: 0;
    }
    .task-info-btn:hover {
        background: #2f6bdc;
        color: #fff;
        border-color: #2f6bdc;
        transform: translateY(-1px);
        box-shadow: 0 4px 12px rgba(76, 139, 245, 0.3);
    }

    .action-link-delete {
        background: #ffe4ec;
        color: #c81e4a;
        font-size: 0.85rem;
        font-weight: 700;
        padding: 7px 10px;
        border-radius: 10px;
        border: 1px solid #ffc9d8;
        transition: all 0.2s ease;
        white-space: nowrap;
        cursor: pointer;
        font-family: inherit;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 34px;
        height: 34px;
    }
    .action-link-delete:hover { background: #c81e4a; color: #fff; border-color: #c81e4a; }

    .subject-edit-btn {
        background: #e7f0ff;
        color: #2f6bdc;
        font-size: 0.85rem;
        font-weight: 700;
        padding: 7px 10px;
        border-radius: 10px;
        border: 1px solid #c7d7ff;
        transition: all 0.2s ease;
        white-space: nowrap;
        cursor: pointer;
        font-family: inherit;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 34px;
        height: 34px;
    }
    .subject-edit-btn:hover {
        background: #2f6bdc;
        color: #fff;
        border-color: #2f6bdc;
    }

    .task-color-bar {
        position: absolute;
        top: 14px; bottom: 14px;
        right: 0;
        width: 5px;
        border-radius: 6px 0 0 6px;
        z-index: 3;
        transition: width 0.15s ease;
    }
    .task-color-bar.bar-received { background: linear-gradient(180deg, #ffa751, #ff8a3d); }
    .task-color-bar.bar-sent { background: linear-gradient(180deg, #a26bfa, #7f4cf0); }
    .task-color-bar:hover { width: 7px; }

    .task-tooltip {
        position: absolute;
        top: 50%;
        right: 22px;
        transform: translateY(-50%) translateX(8px);
        background: #2b3452;
        color: #fff;
        padding: 10px 14px;
        border-radius: 12px;
        font-size: 0.78rem;
        white-space: nowrap;
        opacity: 0;
        pointer-events: none;
        transition: opacity 0.15s ease, transform 0.15s ease;
        z-index: 100;
        display: flex;
        align-items: center;
        gap: 10px;
        line-height: 1.5;
    }
    .task-tooltip::after {
        content: '';
        position: absolute;
        top: 50%; left: 100%;
        transform: translateY(-50%);
        border: 7px solid transparent;
        border-left-color: #2b3452;
    }
    .task-color-bar:hover ~ .task-tooltip {
        opacity: 1;
        transform: translateY(-50%) translateX(0);
    }
    .task-tooltip .tip-icon {
        width: 26px; height: 26px;
        border-radius: 8px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 0.72rem;
        flex-shrink: 0;
    }
    .task-tooltip.tip-received .tip-icon { background: rgba(255, 167, 81, 0.25); color: #ffa751; }
    .task-tooltip.tip-sent .tip-icon { background: rgba(162, 107, 250, 0.25); color: #b78cff; }
    .task-tooltip .tip-label { color: #b0b8cd; font-size: 0.7rem; margin-bottom: 2px; }
    .task-tooltip .tip-name { color: #fff; font-weight: 700; }
    .task-tooltip .tip-mobile { color: #b0b8cd; font-size: 0.7rem; direction: ltr; display: inline-block; margin-right: 5px; }

    .modal-overlay {
        position: fixed;
        inset: 0;
        background: rgba(20, 30, 60, 0.6);
        display: none;
        align-items: center;
        justify-content: center;
        z-index: 1000;
        padding: 15px;
        transform: translateZ(0);
        will-change: opacity;
    }
    .modal-overlay.active { display: flex; }

    .modal-box {
        background: #fff;
        width: 100%;
        max-width: 520px;
        border-radius: 22px;
        padding: 28px;
        max-height: 90vh;
        overflow-y: auto;
        box-shadow: 0 24px 60px rgba(20, 30, 60, 0.25);
        animation: modalIn 0.25s ease;
        -webkit-overflow-scrolling: touch;
        overscroll-behavior: contain;
    }
    @keyframes modalIn {
        from { transform: translateY(20px); opacity: 0; }
        to { transform: translateY(0); opacity: 1; }
    }

    body.modal-open .content-area * {
        transition: none !important;
        animation: none !important;
    }
    body.modal-open .hero-card:hover,
    body.modal-open .soft-card:hover,
    body.modal-open .task-row:hover {
        box-shadow: 0 2px 8px rgba(76, 108, 200, 0.03) !important;
    }

    .task-modal-box {
        max-width: 580px;
        padding: 0;
        display: flex;
        flex-direction: column;
        max-height: 92vh;
        overflow: hidden;
    }

    .task-modal-header {
        padding: 18px 24px;
        border-bottom: 1px solid var(--border);
        display: flex;
        justify-content: space-between;
        align-items: center;
        background: #fff;
        flex-shrink: 0;
        gap: 10px;
    }

    .task-modal-header h3 {
        margin: 0;
        font-size: 1.02rem;
        font-weight: 700;
        color: var(--text);
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .task-modal-header h3 .tm-header-icon {
        width: 34px; height: 34px;
        border-radius: 10px;
        background: linear-gradient(135deg, #4c8bf5, #2f6bdc);
        color: #fff;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 0.85rem;
        flex-shrink: 0;
    }

    .task-modal-header h3 .tm-header-icon.tm-edit-icon {
        background: linear-gradient(135deg, #a26bfa, #7f4cf0);
    }

    .task-modal-header h3 .tm-id-badge {
        background: #f1f4fb;
        color: var(--text-soft);
        font-size: 0.72rem;
        padding: 3px 9px;
        border-radius: 8px;
        font-weight: 700;
        margin-right: 4px;
    }

    .tm-header-actions {
        display: flex;
        align-items: center;
        gap: 6px;
        margin-right: auto;
    }

    .tm-header-share {
        width: 34px; height: 34px;
        border-radius: 10px;
        background: #e7f0ff;
        border: 1px solid #c7d7ff;
        color: #2f6bdc;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 0.85rem;
        transition: all 0.2s ease;
        font-family: inherit;
        padding: 0;
        flex-shrink: 0;
    }
    .tm-header-share:hover {
        background: #2f6bdc;
        color: #fff;
        border-color: #2f6bdc;
        transform: translateY(-1px);
        box-shadow: 0 4px 12px rgba(76, 139, 245, 0.35);
    }
    .tm-header-share:active { transform: translateY(0) scale(0.96); }
    .tm-header-share.copied {
        background: #e3f8ef;
        color: #0e7a55;
        border-color: #a7f3d0;
    }

    .task-modal-form {
        display: flex;
        flex-direction: column;
        flex: 1;
        min-height: 0;
    }

    .tm-body {
        padding: 20px 24px 4px;
        overflow-y: auto;
        flex: 1;
        -webkit-overflow-scrolling: touch;
        overscroll-behavior: contain;
    }

    .tm-field { margin-bottom: 16px; }

    .tm-label {
        display: flex;
        align-items: center;
        gap: 7px;
        font-size: 0.78rem;
        font-weight: 700;
        color: var(--text-soft);
        margin-bottom: 8px;
        letter-spacing: -0.2px;
    }

    .tm-label-icon {
        font-size: 0.72rem;
        color: var(--accent);
        width: 14px;
        text-align: center;
    }

    .tm-input {
        width: 100%;
        padding: 12px 16px;
        background: #fafbfe;
        border: 2px solid var(--border);
        border-radius: 12px;
        font-size: 0.9rem;
        font-family: inherit;
        color: var(--text);
        transition: border-color 0.2s ease, box-shadow 0.2s ease, background 0.2s ease;
    }

    .tm-input:focus {
        outline: none;
        border-color: var(--accent);
        background: #fff;
        box-shadow: 0 0 0 4px rgba(76, 139, 245, 0.12);
    }

    .tm-input::placeholder {
        color: #b8c0d0;
        font-size: 0.85rem;
    }

    .tm-textarea {
        resize: vertical;
        min-height: 110px;
        line-height: 1.7;
    }

    .tm-hint {
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 8px 12px;
        background: #e7f0ff;
        color: #2f6bdc;
        border-radius: 10px;
        font-size: 0.75rem;
        font-weight: 600;
        margin-bottom: 14px;
        border: 1px solid #c7d7ff;
    }

    .tm-row {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 14px;
    }

    .tm-divider {
        height: 1px;
        background: var(--border);
        margin: 6px 0 16px;
    }

    .tm-priority-group {
        display: flex;
        gap: 6px;
    }

    .tm-priority-opt {
        flex: 1;
        position: relative;
        cursor: pointer;
        margin: 0;
    }

    .tm-priority-opt input {
        position: absolute;
        opacity: 0;
        pointer-events: none;
    }

    .tm-priority-opt span {
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 10px 6px;
        border-radius: 11px;
        background: #fafbfe;
        border: 2px solid var(--border);
        font-size: 0.8rem;
        font-weight: 700;
        color: var(--text-soft);
        transition: all 0.2s ease;
        font-family: inherit;
    }

    .tm-priority-opt input:checked + span {
        background: linear-gradient(135deg, #4c8bf5 0%, #2f6bdc 100%);
        border-color: var(--accent);
        color: #fff;
        box-shadow: 0 4px 10px rgba(76, 139, 245, 0.25);
    }

    .tm-priority-opt[data-priority="low"] input:checked + span {
        background: linear-gradient(135deg, #4ed4a3 0%, #2ebc8a 100%);
        border-color: var(--success);
        box-shadow: 0 4px 10px rgba(46, 188, 138, 0.25);
    }

    .tm-priority-opt[data-priority="high"] input:checked + span {
        background: linear-gradient(135deg, #ff6b8b 0%, #f54e7a 100%);
        border-color: var(--danger);
        box-shadow: 0 4px 10px rgba(245, 78, 122, 0.25);
    }

    .tm-priority-group.field-locked {
        opacity: 0.65;
        pointer-events: none;
    }

    /* ⭐ Persian Date Picker Wrapper */
    .date-picker-wrap {
        position: relative;
        width: 100%;
    }
    .date-display-input {
        width: 100% !important;
        padding: 12px 16px 12px 52px !important;
        background: #fafbfe !important;
        border: 2px solid var(--border) !important;
        border-radius: 12px !important;
        font-size: 0.9rem !important;
        font-family: inherit !important;
        color: var(--text) !important;
        cursor: pointer;
        transition: all 0.2s ease;
        text-align: right;
        direction: rtl;
    }
    .date-display-input:focus {
        outline: none !important;
        border-color: var(--accent) !important;
        background: #fff !important;
        box-shadow: 0 0 0 4px rgba(76, 139, 245, 0.12) !important;
    }
    .date-display-input::placeholder {
        color: #b8c0d0;
        font-size: 0.85rem;
    }
    .date-picker-icon {
        position: absolute;
        left: 12px;
        top: 50%;
        transform: translateY(-50%);
        color: #fff;
        font-size: 0.82rem;
        pointer-events: none;
        width: 30px;
        height: 30px;
        background: linear-gradient(135deg, #4c8bf5, #2f6bdc);
        border-radius: 9px;
        display: flex;
        align-items: center;
        justify-content: center;
        box-shadow: 0 4px 10px rgba(76, 139, 245, 0.25);
        transition: transform 0.2s ease;
        z-index: 2;
    }
    .date-display-input:focus ~ .date-picker-icon {
        transform: translateY(-50%) scale(1.08);
    }
    .date-clear-btn {
        position: absolute;
        left: 50px;
        top: 50%;
        transform: translateY(-50%);
        width: 26px;
        height: 26px;
        border-radius: 7px;
        background: #ffe4ec;
        color: #c81e4a;
        border: none;
        cursor: pointer;
        display: none;
        align-items: center;
        justify-content: center;
        font-size: 0.62rem;
        transition: all 0.15s ease;
        font-family: inherit;
        padding: 0;
        z-index: 3;
    }
    .date-picker-wrap.has-value .date-clear-btn {
        display: inline-flex;
    }
    .date-clear-btn:hover {
        background: #c81e4a;
        color: #fff;
        transform: translateY(-50%) scale(1.1);
    }

    /* ⭐ Custom style for persian-datepicker */
    .datepicker-plot-area {
        z-index: 2147483647 !important;
        font-family: 'Segoe UI', Tahoma, sans-serif !important;
        border-radius: 16px !important;
        box-shadow: 0 24px 60px rgba(20, 30, 60, 0.22) !important;
        border: 1px solid var(--border) !important;
        background: #fff !important;
        padding: 8px !important;
        direction: rtl;
        min-width: 260px;
        margin-top: 6px;
    }
    .datepicker-plot-area .datepicker-header {
        background: #fff;
        border-bottom: 1px solid var(--border);
        padding: 8px 4px 12px;
        margin-bottom: 8px;
    }
    .datepicker-plot-area .datepicker-navigator .pwt-btn {
        background: #e7f0ff !important;
        color: #2f6bdc !important;
        border-radius: 10px !important;
        transition: all 0.2s ease;
        font-weight: 700;
        height: 30px;
        line-height: 30px;
        padding: 0 10px;
        border: none !important;
    }
    .datepicker-plot-area .datepicker-navigator .pwt-btn:hover {
        background: linear-gradient(135deg, #4c8bf5, #2f6bdc) !important;
        color: #fff !important;
        transform: translateY(-1px);
    }
    .datepicker-plot-area .datepicker-navigator .pwt-btn-switch {
        background: linear-gradient(135deg, #4c8bf5, #2f6bdc) !important;
        color: #fff !important;
        box-shadow: 0 4px 10px rgba(76, 139, 245, 0.25);
    }
    .datepicker-plot-area .table-days {
        border-spacing: 2px;
        border-collapse: separate;
    }
    .datepicker-plot-area .table-days td {
        padding: 2px;
        text-align: center;
    }
    .datepicker-plot-area .table-days td span {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 32px;
        height: 32px;
        border-radius: 9px;
        font-size: 0.82rem;
        color: var(--text);
        font-weight: 600;
        transition: all 0.15s ease;
        cursor: pointer;
        background: transparent;
    }
    .datepicker-plot-area .table-days td span:hover {
        background: #e7f0ff !important;
        color: #2f6bdc !important;
    }
    .datepicker-plot-area .table-days td.selected span,
    .datepicker-plot-area .table-days td.selected span:hover {
        background: linear-gradient(135deg, #4c8bf5, #2f6bdc) !important;
        color: #fff !important;
        box-shadow: 0 4px 10px rgba(76, 139, 245, 0.35);
        font-weight: 700;
    }
    .datepicker-plot-area .table-days td.today span {
        border: 2px solid #4c8bf5 !important;
        color: #2f6bdc !important;
        font-weight: 800;
        background: #f0f6ff;
    }
    .datepicker-plot-area .table-days td.today.selected span {
        border-color: #fff !important;
        color: #fff !important;
        background: linear-gradient(135deg, #4c8bf5, #2f6bdc) !important;
    }
    .datepicker-plot-area .table-days td.disabled span {
        color: #cbd5e1 !important;
        cursor: not-allowed;
        background: transparent !important;
    }
    .datepicker-plot-area .datepicker-day-view .table-days td {
        /* hint: keep spacing */
    }
    .datepicker-plot-area .datepicker-title {
        display: none;
    }
    .datepicker-plot-area .datepicker-day-view,
    .datepicker-plot-area .datepicker-month-view,
    .datepicker-plot-area .datepicker-year-view {
        padding: 4px 8px 8px;
    }
    .datepicker-plot-area .datepicker-month,
    .datepicker-plot-area .datepicker-year {
        border-radius: 10px;
        padding: 6px;
        font-weight: 700;
        cursor: pointer;
        transition: all 0.15s ease;
    }
    .datepicker-plot-area .datepicker-month:hover,
    .datepicker-plot-area .datepicker-year:hover {
        background: #e7f0ff;
        color: #2f6bdc;
    }
    .datepicker-plot-area .datepicker-month.selected,
    .datepicker-plot-area .datepicker-year.selected {
        background: linear-gradient(135deg, #4c8bf5, #2f6bdc);
        color: #fff;
    }
    .datepicker-plot-area .toolbox {
        padding-top: 8px;
        border-top: 1px solid var(--border);
        display: flex;
        gap: 6px;
        justify-content: center;
    }
    .datepicker-plot-area .toolbox .pwt-btn-today,
    .datepicker-plot-area .toolbox .pwt-btn-close,
    .datepicker-plot-area .toolbox .pwt-btn {
        background: #e3f8ef !important;
        color: #0e7a55 !important;
        border-radius: 10px !important;
        padding: 6px 14px !important;
        font-weight: 700;
        font-size: 0.78rem;
        border: none !important;
        height: auto !important;
        line-height: 1.8 !important;
        cursor: pointer;
    }
    .datepicker-plot-area .toolbox .pwt-btn-close {
        background: #ffe4ec !important;
        color: #c81e4a !important;
    }
    .datepicker-plot-area .toolbox .pwt-btn-today:hover {
        background: #2ebc8a !important;
        color: #fff !important;
    }
    .datepicker-plot-area .toolbox .pwt-btn-close:hover {
        background: #c81e4a !important;
        color: #fff !important;
    }

    /* ⭐ File Upload etc (unchanged) */
    .file-upload-zone {
        position: relative;
        border: 2px dashed #c7d7ff;
        background: #f7faff;
        border-radius: 12px;
        padding: 18px 16px;
        text-align: center;
        cursor: pointer;
        transition: all 0.2s ease;
    }
    .file-upload-zone:hover,
    .file-upload-zone.dragover {
        border-color: var(--accent);
        background: #eef4ff;
    }
    .file-upload-zone input[type="file"] {
        position: absolute;
        inset: 0;
        width: 100%;
        height: 100%;
        opacity: 0;
        cursor: pointer;
    }
    .file-upload-zone__icon {
        width: 44px; height: 44px;
        border-radius: 12px;
        background: linear-gradient(135deg, #4c8bf5, #2f6bdc);
        color: #fff;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 1rem;
        margin-bottom: 8px;
        box-shadow: 0 6px 14px rgba(76, 139, 245, 0.25);
    }
    .file-upload-zone__title {
        font-size: 0.86rem;
        font-weight: 700;
        color: var(--text);
        margin-bottom: 4px;
    }
    .file-upload-zone__hint {
        font-size: 0.72rem;
        color: var(--text-soft);
        line-height: 1.7;
    }
    .file-upload-zone__hint code {
        background: #e7f0ff;
        color: #2f6bdc;
        padding: 1px 5px;
        border-radius: 5px;
        font-size: 0.7rem;
        font-family: inherit;
        direction: ltr;
        display: inline-block;
    }

    .file-preview-list {
        list-style: none;
        padding: 0;
        margin: 10px 0 0;
        display: flex;
        flex-direction: column;
        gap: 6px;
    }
    .file-preview-item {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 8px 10px;
        background: #fafbfe;
        border: 1px solid var(--border);
        border-radius: 10px;
        font-size: 0.78rem;
    }
    .file-preview-item__icon {
        width: 30px; height: 30px;
        border-radius: 8px;
        background: #e7f0ff;
        color: #2f6bdc;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 0.8rem;
        flex-shrink: 0;
    }
    .file-preview-item__icon.is-pdf { background: #ffe4ec; color: #c81e4a; }
    .file-preview-item__icon.is-excel { background: #e3f8ef; color: #0e7a55; }
    .file-preview-item__icon.is-word { background: #e7f0ff; color: #2f6bdc; }
    .file-preview-item__icon.is-image { background: #fff3e0; color: #b45309; }
    .file-preview-item__icon.is-zip { background: #f0e9ff; color: #7f4cf0; }
    .file-preview-item__info { flex: 1; min-width: 0; }
    .file-preview-item__name {
        font-weight: 700;
        color: var(--text);
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .file-preview-item__meta {
        font-size: 0.68rem;
        color: var(--text-soft);
        margin-top: 2px;
    }
    .file-preview-item__remove {
        background: transparent;
        border: none;
        color: var(--text-soft);
        cursor: pointer;
        width: 26px; height: 26px;
        border-radius: 7px;
        transition: all 0.15s ease;
        font-family: inherit;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
    }
    .file-preview-item__remove:hover {
        background: #ffe4ec;
        color: #c81e4a;
    }

    .modal-attachments-section {
        margin-top: 6px;
        margin-bottom: 8px;
        padding: 14px;
        background: #f7faff;
        border: 1px solid #dbe7ff;
        border-radius: 14px;
    }
    .modal-attachments-header {
        display: flex;
        align-items: center;
        gap: 10px;
        margin-bottom: 12px;
        font-size: 0.85rem;
        font-weight: 700;
        color: var(--text);
    }
    .modal-attachments-header .ma-icon {
        width: 30px; height: 30px;
        border-radius: 9px;
        background: linear-gradient(135deg, #4c8bf5, #2f6bdc);
        color: #fff;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 0.78rem;
        flex-shrink: 0;
    }
    .modal-attachments-header .ma-count {
        margin-right: auto;
        background: #e7f0ff;
        color: #2f6bdc;
        font-size: 0.7rem;
        padding: 3px 10px;
        border-radius: 8px;
        font-weight: 700;
    }
    .modal-attachments-list {
        display: flex;
        flex-direction: column;
        gap: 6px;
        margin-bottom: 12px;
    }
    .modal-attachment-item {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 9px 11px;
        background: #fff;
        border: 1px solid var(--border);
        border-radius: 10px;
        transition: box-shadow 0.15s ease, border-color 0.15s ease;
    }
    .modal-attachment-item:hover {
        box-shadow: 0 4px 10px rgba(76, 108, 200, 0.07);
        border-color: #d8e0f0;
    }
    .modal-attachment-item__icon {
        width: 34px; height: 34px;
        border-radius: 9px;
        background: #e7f0ff;
        color: #2f6bdc;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 0.85rem;
        flex-shrink: 0;
    }
    .modal-attachment-item__icon.is-pdf { background: #ffe4ec; color: #c81e4a; }
    .modal-attachment-item__icon.is-excel { background: #e3f8ef; color: #0e7a55; }
    .modal-attachment-item__icon.is-word { background: #e7f0ff; color: #2f6bdc; }
    .modal-attachment-item__icon.is-image { background: #fff3e0; color: #b45309; }
    .modal-attachment-item__icon.is-zip { background: #f0e9ff; color: #7f4cf0; }
    .modal-attachment-item__body { flex: 1; min-width: 0; }
    .modal-attachment-item__name {
        font-size: 0.82rem;
        font-weight: 700;
        color: var(--text);
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .modal-attachment-item__name a {
        color: var(--text);
        text-decoration: none;
    }
    .modal-attachment-item__name a:hover {
        color: var(--accent);
        text-decoration: underline;
    }
    .modal-attachment-item__meta {
        font-size: 0.68rem;
        color: var(--text-soft);
        margin-top: 3px;
        display: flex;
        align-items: center;
        gap: 8px;
        flex-wrap: wrap;
    }
    .modal-attachment-item__actions {
        display: flex;
        gap: 4px;
        flex-shrink: 0;
    }
    .modal-attachment-item__actions button,
    .modal-attachment-item__actions a {
        background: transparent;
        border: none;
        color: var(--text-soft);
        cursor: pointer;
        width: 28px; height: 28px;
        border-radius: 8px;
        transition: all 0.15s ease;
        font-family: inherit;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        text-decoration: none;
    }
    .modal-attachment-item__actions .att-download:hover {
        background: #e7f0ff;
        color: #2f6bdc;
    }
    .modal-attachment-item__actions .att-delete:hover {
        background: #ffe4ec;
        color: #c81e4a;
    }
    .modal-attachments-empty {
        text-align: center;
        font-size: 0.78rem;
        color: var(--text-soft);
        padding: 12px;
        background: #fff;
        border: 1px dashed var(--border);
        border-radius: 10px;
    }
    .modal-attachments-loading {
        text-align: center;
        font-size: 0.78rem;
        color: var(--text-soft);
        padding: 12px;
    }

    .tm-footer {
        padding: 16px 24px;
        border-top: 1px solid var(--border);
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 12px;
        flex-shrink: 0;
        background: #fff;
    }

    .tm-btn-cancel {
        background: #f1f4fb;
        border: none;
        color: var(--text);
        padding: 11px 22px;
        border-radius: 12px;
        font-weight: 700;
        cursor: pointer;
        font-family: inherit;
        font-size: 0.88rem;
        transition: background 0.2s ease;
    }
    .tm-btn-cancel:hover { background: #e4e9f5; }

    .tm-btn-save {
        background: linear-gradient(135deg, #4c8bf5 0%, #2f6bdc 100%);
        border: none;
        color: #fff;
        padding: 11px 24px;
        border-radius: 12px;
        font-weight: 700;
        cursor: pointer;
        font-family: inherit;
        font-size: 0.88rem;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        box-shadow: 0 6px 16px rgba(76, 139, 245, 0.25);
        transition: transform 0.15s ease, box-shadow 0.2s ease;
    }
    .tm-btn-save:hover {
        transform: translateY(-1px);
        box-shadow: 0 8px 20px rgba(76, 139, 245, 0.35);
    }

    .modal-notes-section {
        margin-top: 22px;
        border-top: 1px solid var(--border);
        padding-top: 18px;
        padding-bottom: 8px;
    }
    .modal-notes-header {
        display: flex;
        align-items: center;
        gap: 10px;
        font-size: 0.92rem;
        font-weight: 700;
        color: var(--text);
        margin-bottom: 14px;
    }
    .modal-notes-header .modal-notes-header-icon {
        width: 32px; height: 32px;
        border-radius: 10px;
        background: linear-gradient(135deg, #a26bfa, #7f4cf0);
        color: #fff;
        display: flex; align-items: center; justify-content: center;
        font-size: 0.8rem;
    }
    .modal-notes-header .modal-notes-header-count {
        margin-right: auto;
        background: #f0e9ff;
        color: #7f4cf0;
        font-size: 0.72rem;
        padding: 3px 10px;
        border-radius: 8px;
        font-weight: 700;
    }
    .modal-notes-list {
        max-height: 320px;
        overflow-y: auto;
        padding: 4px 2px;
        margin-bottom: 12px;
        display: flex;
        flex-direction: column;
        gap: 10px;
        -webkit-overflow-scrolling: touch;
        overscroll-behavior: contain;
    }
    .modal-notes-empty {
        text-align: center;
        color: var(--text-soft);
        font-size: 0.8rem;
        padding: 22px 12px;
        background: #fafbfe;
        border: 1px dashed var(--border);
        border-radius: 12px;
    }
    .modal-note-item {
        display: flex;
        gap: 10px;
        padding: 10px 12px;
        background: #fafbfe;
        border: 1px solid var(--border);
        border-radius: 12px;
        transition: box-shadow 0.15s ease, border-color 0.15s ease;
    }
    .modal-note-item:hover {
        box-shadow: 0 4px 12px rgba(76, 108, 200, 0.06);
        border-color: #d8e0f0;
    }
    .modal-note-avatar {
        width: 34px; height: 34px;
        border-radius: 50%;
        background: linear-gradient(135deg, #a26bfa, #7f4cf0);
        color: #fff;
        display: flex; align-items: center; justify-content: center;
        font-size: 0.8rem;
        font-weight: 700;
        flex-shrink: 0;
        overflow: hidden;
    }
    .modal-note-avatar.is-mine {
        background: linear-gradient(135deg, #4c8bf5, #2f6bdc);
    }
    .modal-note-avatar img { width: 100%; height: 100%; object-fit: cover; }
    .modal-note-body {
        flex: 1;
        min-width: 0;
    }
    .modal-note-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 8px;
        margin-bottom: 5px;
        flex-wrap: wrap;
    }
    .modal-note-author {
        font-size: 0.76rem;
        font-weight: 700;
        color: #7f4cf0;
    }
    .modal-note-author.is-mine {
        color: #4c8bf5;
    }
    .modal-note-date {
        font-size: 0.68rem;
        color: var(--text-soft);
    }
    .modal-note-text {
        font-size: 0.83rem;
        color: var(--text);
        line-height: 1.7;
        white-space: pre-wrap;
        word-wrap: break-word;
    }
    .modal-note-text .note-mention {
        display: inline-block;
        background: #e7f0ff;
        color: #2f6bdc;
        padding: 1px 8px;
        border-radius: 8px;
        font-weight: 700;
        font-size: 0.78rem;
    }
    .modal-note-actions {
        display: flex;
        justify-content: flex-end;
        gap: 6px;
        margin-top: 6px;
    }
    .modal-note-actions button {
        background: transparent;
        border: none;
        color: var(--text-soft);
        cursor: pointer;
        padding: 4px 7px;
        border-radius: 6px;
        font-size: 0.72rem;
        transition: all 0.15s ease;
        font-family: inherit;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 26px;
        height: 26px;
    }
    .modal-note-actions .modal-note-edit:hover {
        background: #e7f0ff;
        color: #2f6bdc;
    }
    .modal-note-actions .modal-note-delete:hover {
        background: #ffe4ec;
        color: #c81e4a;
    }
    .modal-note-actions .modal-note-save {
        background: #e3f8ef !important;
        color: #0e7a55 !important;
    }
    .modal-note-actions .modal-note-save:hover {
        background: #2ebc8a !important;
        color: #fff !important;
    }
    .modal-note-actions .modal-note-cancel {
        background: #f1f4fb !important;
        color: var(--text-soft) !important;
    }
    .modal-note-actions .modal-note-cancel:hover {
        background: #e4e9f5 !important;
        color: var(--text) !important;
    }
    .modal-note-edit-textarea {
        width: 100%;
        min-height: 70px;
        padding: 8px 10px;
        border: 2px solid var(--accent);
        border-radius: 10px;
        background: #fff;
        font-family: inherit;
        font-size: 0.82rem;
        color: var(--text);
        resize: vertical;
        outline: none;
        box-shadow: 0 0 0 4px rgba(76, 139, 245, 0.12);
    }

    .modal-note-input-wrap {
        display: flex;
        flex-direction: column;
        gap: 8px;
        position: relative;
    }
    .modal-note-input-row {
        display: flex;
        gap: 8px;
        align-items: flex-end;
    }
    .modal-note-input-row textarea {
        flex: 1;
        min-height: 48px;
        max-height: 120px;
        padding: 10px 12px;
        border: 2px solid var(--border);
        border-radius: 12px;
        background: #fafbfe;
        font-family: inherit;
        font-size: 0.85rem;
        color: var(--text);
        resize: vertical;
        transition: all 0.2s ease;
    }
    .modal-note-input-row textarea:focus {
        outline: none;
        border-color: var(--accent);
        background: #fff;
        box-shadow: 0 0 0 4px rgba(76, 139, 245, 0.12);
    }
    .modal-note-submit {
        background: linear-gradient(135deg, #4c8bf5 0%, #2f6bdc 100%);
        color: #fff;
        border: none;
        width: 46px; height: 46px;
        border-radius: 12px;
        cursor: pointer;
        display: flex; align-items: center; justify-content: center;
        font-size: 0.95rem;
        transition: transform 0.15s ease, box-shadow 0.2s ease;
        box-shadow: 0 4px 12px rgba(76, 139, 245, 0.25);
        flex-shrink: 0;
    }
    .modal-note-submit:hover {
        transform: translateY(-1px);
        box-shadow: 0 6px 16px rgba(76, 139, 245, 0.35);
    }
    .modal-note-submit:disabled {
        opacity: 0.5;
        cursor: not-allowed;
        transform: none;
    }
    .modal-note-attach-btn {
        width: 46px; height: 46px;
        border-radius: 12px;
        background: #f1f4fb;
        color: var(--text-soft);
        display: inline-flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        font-size: 0.95rem;
        transition: all 0.2s ease;
        flex-shrink: 0;
        border: 2px solid var(--border);
        font-family: inherit;
        padding: 0;
    }
    .modal-note-attach-btn:hover {
        background: #e7f0ff;
        color: #2f6bdc;
        border-color: #c7d7ff;
    }
    .modal-note-attach-btn.has-files {
        background: #e7f0ff;
        color: #2f6bdc;
        border-color: #c7d7ff;
    }
    .modal-note-attach-btn.has-mentions {
        background: #f0e9ff;
        color: #7f4cf0;
        border-color: #d8c8ff;
    }

    .modal-note-files-preview {
        display: flex;
        flex-direction: column;
        gap: 6px;
        max-height: 150px;
        overflow-y: auto;
    }
    .modal-note-files-preview:empty { display: none; }
    .modal-note-file-item {
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 7px 10px;
        background: #f7faff;
        border: 1px solid #dbe7ff;
        border-radius: 10px;
        font-size: 0.76rem;
    }
    .modal-note-file-item__icon {
        width: 26px; height: 26px;
        border-radius: 7px;
        background: #e7f0ff;
        color: #2f6bdc;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 0.72rem;
        flex-shrink: 0;
    }
    .modal-note-file-item__icon.is-pdf { background: #ffe4ec; color: #c81e4a; }
    .modal-note-file-item__icon.is-excel { background: #e3f8ef; color: #0e7a55; }
    .modal-note-file-item__icon.is-word { background: #e7f0ff; color: #2f6bdc; }
    .modal-note-file-item__icon.is-image { background: #fff3e0; color: #b45309; }
    .modal-note-file-item__icon.is-zip { background: #f0e9ff; color: #7f4cf0; }
    .modal-note-file-item__info { flex: 1; min-width: 0; }
    .modal-note-file-item__name {
        font-weight: 700;
        color: var(--text);
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .modal-note-file-item__meta {
        font-size: 0.66rem;
        color: var(--text-soft);
        margin-top: 2px;
    }
    .modal-note-file-item__remove {
        background: transparent;
        border: none;
        color: var(--text-soft);
        cursor: pointer;
        width: 24px; height: 24px;
        border-radius: 6px;
        transition: all 0.15s ease;
        font-family: inherit;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
    }
    .modal-note-file-item__remove:hover {
        background: #ffe4ec;
        color: #c81e4a;
    }

    .modal-note-mentions-preview {
        display: flex;
        flex-wrap: wrap;
        gap: 6px;
    }
    .modal-note-mentions-preview:empty { display: none; }
    .mention-chip {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        padding: 4px 10px 4px 6px;
        background: #e7f0ff;
        border: 1px solid #c7d7ff;
        border-radius: 20px;
        font-size: 0.72rem;
        font-weight: 700;
        color: #2f6bdc;
    }
    .mention-chip__avatar {
        width: 20px; height: 20px;
        border-radius: 50%;
        background: linear-gradient(135deg, #4c8bf5, #2f6bdc);
        color: #fff;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 0.6rem;
        overflow: hidden;
        flex-shrink: 0;
    }
    .mention-chip__avatar img { width: 100%; height: 100%; object-fit: cover; }
    .mention-chip__remove {
        background: transparent;
        border: none;
        color: #2f6bdc;
        cursor: pointer;
        width: 16px; height: 16px;
        border-radius: 50%;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 0.6rem;
        font-family: inherit;
        padding: 0;
    }
    .mention-chip__remove:hover {
        background: #2f6bdc;
        color: #fff;
    }

    .modal-note-mention-picker {
        position: absolute;
        bottom: calc(100% + 6px);
        right: 0;
        width: 260px;
        max-height: 260px;
        background: #fff;
        border: 1px solid var(--border);
        border-radius: 14px;
        box-shadow: 0 12px 32px rgba(20, 30, 60, 0.18);
        z-index: 1300;
        overflow: hidden;
        flex-direction: column;
    }
    .mention-picker__header {
        padding: 10px 14px;
        font-size: 0.72rem;
        font-weight: 700;
        color: var(--text-soft);
        border-bottom: 1px solid var(--border);
        background: #fafbfe;
    }
    .mention-picker__list {
        overflow-y: auto;
        padding: 4px;
        max-height: 220px;
    }
    .mention-picker__item {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 8px 10px;
        border-radius: 10px;
        cursor: pointer;
        transition: background 0.15s ease;
    }
    .mention-picker__item:hover { background: #f1f4fb; }
    .mention-picker__item.selected {
        background: #e7f0ff;
    }
    .mention-picker__avatar {
        width: 32px; height: 32px;
        border-radius: 50%;
        background: linear-gradient(135deg, #4c8bf5, #2f6bdc);
        color: #fff;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 0.75rem;
        font-weight: 700;
        flex-shrink: 0;
        overflow: hidden;
    }
    .mention-picker__avatar img { width: 100%; height: 100%; object-fit: cover; }
    .mention-picker__name {
        flex: 1;
        font-size: 0.82rem;
        font-weight: 600;
        color: var(--text);
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .mention-picker__check {
        color: #2f6bdc;
        font-size: 0.78rem;
        flex-shrink: 0;
    }
    .mention-picker__empty {
        padding: 24px 16px;
        text-align: center;
        font-size: 0.78rem;
        color: var(--text-soft);
    }

    .modal-note-attachments {
        display: flex;
        flex-direction: column;
        gap: 5px;
        margin-top: 8px;
    }
    .modal-note-attachment {
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 6px 9px;
        background: #f7faff;
        border: 1px solid #dbe7ff;
        border-radius: 9px;
        font-size: 0.74rem;
        transition: all 0.15s ease;
    }
    .modal-note-attachment:hover {
        background: #eef4ff;
        border-color: #c7d7ff;
    }
    .modal-note-attachment__icon {
        width: 24px; height: 24px;
        border-radius: 6px;
        background: #e7f0ff;
        color: #2f6bdc;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 0.7rem;
        flex-shrink: 0;
    }
    .modal-note-attachment__icon.is-pdf { background: #ffe4ec; color: #c81e4a; }
    .modal-note-attachment__icon.is-excel { background: #e3f8ef; color: #0e7a55; }
    .modal-note-attachment__icon.is-word { background: #e7f0ff; color: #2f6bdc; }
    .modal-note-attachment__icon.is-image { background: #fff3e0; color: #b45309; }
    .modal-note-attachment__icon.is-zip { background: #f0e9ff; color: #7f4cf0; }
    .modal-note-attachment__body {
        flex: 1;
        min-width: 0;
        color: var(--text);
        text-decoration: none;
    }
    .modal-note-attachment__body:hover .modal-note-attachment__name {
        color: var(--accent);
    }
    .modal-note-attachment__name {
        font-weight: 700;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .modal-note-attachment__meta {
        font-size: 0.64rem;
        color: var(--text-soft);
        margin-top: 1px;
    }
    .modal-note-attachment__actions {
        display: flex;
        gap: 3px;
        flex-shrink: 0;
    }
    .modal-note-attachment__actions button,
    .modal-note-attachment__actions a {
        background: transparent;
        border: none;
        color: var(--text-soft);
        cursor: pointer;
        width: 22px; height: 22px;
        border-radius: 6px;
        font-family: inherit;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        transition: all 0.15s ease;
        font-size: 0.7rem;
        text-decoration: none;
    }
    .modal-note-attachment__actions .att-download:hover {
        background: #e7f0ff;
        color: #2f6bdc;
    }
    .modal-note-attachment__actions button.att-del:hover {
        background: #ffe4ec;
        color: #c81e4a;
    }

    .modal-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 20px;
        border-bottom: 1px solid var(--border);
        padding-bottom: 14px;
    }
    .modal-header h3 { margin: 0; font-size: 1.1rem; color: var(--text); font-weight: 700; }
    .modal-close {
        background: #f1f4fb;
        border: none;
        width: 34px; height: 34px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.2rem;
        cursor: pointer;
        color: var(--text-soft);
        font-weight: 700;
        transition: all 0.15s ease;
        flex-shrink: 0;
    }
    .modal-close:hover { background: #ffe4ec; color: #c81e4a; }

    .info-modal-box {
        max-width: 470px;
    }
    .info-modal-header-icon {
        width: 38px; height: 38px;
        border-radius: 12px;
        background: linear-gradient(135deg, #4c8bf5, #2f6bdc);
        color: #fff;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 0.95rem;
        margin-left: 10px;
    }
    .info-task-title {
        background: #fafbfe;
        border: 1px solid var(--border);
        border-radius: 12px;
        padding: 12px 14px;
        margin-bottom: 14px;
        font-size: 0.9rem;
        font-weight: 700;
        color: var(--text);
        line-height: 1.6;
    }
    .info-grid {
        display: flex;
        flex-direction: column;
        gap: 8px;
    }
    .info-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 12px;
        padding: 11px 14px;
        background: #fafbfe;
        border: 1px solid var(--border);
        border-radius: 12px;
    }
    .info-row__label {
        font-size: 0.8rem;
        color: var(--text-soft);
        font-weight: 600;
        display: flex;
        align-items: center;
        gap: 8px;
        flex-shrink: 0;
    }
    .info-row__label i {
        width: 22px; height: 22px;
        border-radius: 7px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 0.68rem;
    }
    .info-row__label i.blue   { background: #e7f0ff; color: #4c8bf5; }
    .info-row__label i.purple { background: #f0e9ff; color: #7f4cf0; }
    .info-row__label i.green  { background: #e3f8ef; color: #2ebc8a; }
    .info-row__label i.orange { background: #fff3e0; color: #ff8a3d; }
    .info-row__label i.pink   { background: #ffe4ec; color: #f54e7a; }
    .info-row__label i.teal   { background: #e0f5fb; color: #0891b2; }
    .info-row__value {
        font-size: 0.85rem;
        color: var(--text);
        font-weight: 700;
        text-align: left;
        word-break: break-word;
        direction: ltr;
    }
    .info-row__value.rtl {
        direction: rtl;
        text-align: right;
    }
    .info-priority-badge {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        padding: 3px 10px;
        border-radius: 8px;
        font-size: 0.75rem;
        font-weight: 700;
    }
    .info-priority-badge.low    { background: #e3f8ef; color: #0e7a55; }
    .info-priority-badge.medium { background: #fff3e0; color: #b45309; }
    .info-priority-badge.high   { background: #ffe4ec; color: #c81e4a; }

    .info-project-cell {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        direction: rtl;
    }
    .info-project-thumb {
        width: 30px;
        height: 30px;
        border-radius: 9px;
        background: linear-gradient(135deg, #a26bfa, #7f4cf0);
        color: #fff;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 0.75rem;
        font-weight: 700;
        overflow: hidden;
        flex-shrink: 0;
        box-shadow: 0 2px 6px rgba(127, 76, 240, 0.25);
    }
    .info-project-thumb img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }
    .info-project-thumb.is-empty {
        background: linear-gradient(135deg, #94a3b8, #64748b);
        box-shadow: none;
    }

    .info-subject-badge {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        padding: 3px 10px;
        border-radius: 8px;
        font-size: 0.75rem;
        font-weight: 700;
        background: #e7f0ff;
        color: #2f6bdc;
        direction: rtl;
    }

    .assignee-picker, .project-picker { position: relative; width: 100%; user-select: none; }
    .assignee-picker__trigger, .project-picker__trigger {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 10px 14px;
        background: #fafbfe;
        border: 2px solid var(--border);
        border-radius: 12px;
        cursor: pointer;
        transition: all 0.2s ease;
        min-height: 56px;
    }
    .assignee-picker__trigger:hover, .project-picker__trigger:hover { border-color: #d8e0f0; }
    .assignee-picker.open .assignee-picker__trigger,
    .project-picker.open .project-picker__trigger {
        border-color: var(--accent);
        background: #fff;
        box-shadow: 0 0 0 4px rgba(76, 139, 245, 0.12);
    }

    .assignee-picker__avatar, .project-picker__avatar {
        width: 38px; height: 38px;
        border-radius: 50%;
        background: linear-gradient(135deg, #4c8bf5, #2f6bdc);
        color: #fff;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 700;
        font-size: 0.9rem;
        flex-shrink: 0;
        overflow: hidden;
    }
    .assignee-picker__avatar.is-self { background: linear-gradient(135deg, #4ed4a3, #2ebc8a); }
    .assignee-picker__avatar img, .project-picker__avatar img { width: 100%; height: 100%; object-fit: cover; }
    .project-picker__avatar {
        border-radius: 11px;
        background: linear-gradient(135deg, #a26bfa, #7f4cf0);
    }
    .project-picker__avatar.is-empty { background: linear-gradient(135deg, #94a3b8, #64748b); }

    .assignee-picker__info, .project-picker__info { flex: 1; min-width: 0; }
    .assignee-picker__name, .project-picker__name {
        font-weight: 700;
        font-size: 0.87rem;
        color: var(--text);
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .assignee-picker__mobile, .project-picker__mobile {
        font-size: 0.73rem;
        color: var(--text-soft);
        margin-top: 2px;
    }
    .assignee-picker__mobile { direction: ltr; text-align: right; }
    .project-picker__mobile { text-align: right; }

    .assignee-picker__chevron, .project-picker__chevron {
        color: var(--text-soft);
        font-size: 0.85rem;
        transition: transform 0.2s ease;
        flex-shrink: 0;
    }
    .assignee-picker.open .assignee-picker__chevron,
    .project-picker.open .project-picker__chevron {
        transform: rotate(180deg);
        color: var(--accent);
    }

    .assignee-picker__dropdown, .project-picker__dropdown {
        position: absolute;
        top: calc(100% + 8px);
        right: 0; left: 0;
        background: #fff;
        border: 1px solid var(--border);
        border-radius: 16px;
        box-shadow: 0 20px 40px -12px rgba(20, 30, 60, 0.18);
        padding: 8px;
        max-height: 300px;
        overflow-y: auto;
        z-index: 1200;
        opacity: 0;
        visibility: hidden;
        transform: translateY(-8px);
        transition: all 0.15s ease;
    }
    .assignee-picker.open .assignee-picker__dropdown,
    .project-picker.open .project-picker__dropdown {
        opacity: 1;
        visibility: visible;
        transform: translateY(0);
    }

    .assignee-picker__section-label, .project-picker__section-label {
        font-size: 0.7rem;
        font-weight: 700;
        color: var(--text-soft);
        padding: 8px 12px 6px;
        display: flex;
        align-items: center;
    }
    .assignee-picker__section-label:not(:first-child),
    .project-picker__section-label:not(:first-child) {
        border-top: 1px solid var(--border);
        margin-top: 4px;
        padding-top: 12px;
    }

    .assignee-picker__option, .project-picker__option {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 10px 12px;
        border-radius: 12px;
        cursor: pointer;
        transition: background-color 0.15s ease;
        position: relative;
    }
    .assignee-picker__option:hover, .project-picker__option:hover { background: #f1f4fb; }
    .assignee-picker__option.selected, .project-picker__option.selected {
        background: linear-gradient(135deg, rgba(76, 139, 245, 0.1), rgba(76, 139, 245, 0.05));
    }
    .assignee-picker__option.selected::after,
    .project-picker__option.selected::after {
        content: '\f00c';
        font-family: 'Font Awesome 6 Free';
        font-weight: 900;
        position: absolute;
        left: 14px;
        color: var(--accent);
        font-size: 0.85rem;
    }

    .assignee-picker__option-avatar, .project-picker__option-avatar {
        width: 36px; height: 36px;
        border-radius: 50%;
        background: linear-gradient(135deg, #4c8bf5, #2f6bdc);
        color: #fff;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 700;
        font-size: 0.85rem;
        flex-shrink: 0;
        overflow: hidden;
    }
    .assignee-picker__option-avatar.is-self { background: linear-gradient(135deg, #4ed4a3, #2ebc8a); }
    .assignee-picker__option-avatar.is-creator { box-shadow: 0 0 0 2px #ffa751; }
    .assignee-picker__option-avatar img, .project-picker__option-avatar img { width: 100%; height: 100%; object-fit: cover; }
    .project-picker__option-avatar {
        border-radius: 11px;
        background: linear-gradient(135deg, #a26bfa, #7f4cf0);
    }
    .project-picker__option-avatar.is-empty { background: linear-gradient(135deg, #94a3b8, #64748b); }

    .assignee-picker__option-info, .project-picker__option-info { flex: 1; min-width: 0; }
    .assignee-picker__option-name, .project-picker__option-name {
        font-weight: 600;
        font-size: 0.85rem;
        color: var(--text);
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        display: flex;
        align-items: center;
        gap: 5px;
    }
    .assignee-picker__option-name .creator-crown { color: #ffa751; font-size: 0.75rem; }
    .assignee-picker__option-mobile, .project-picker__option-mobile {
        font-size: 0.7rem;
        color: var(--text-soft);
        margin-top: 2px;
    }
    .assignee-picker__option-mobile { direction: ltr; text-align: right; }
    .project-picker__option-mobile { text-align: right; }

    .assignee-picker__empty, .project-picker__empty {
        padding: 20px 16px;
        text-align: center;
        font-size: 0.82rem;
        color: var(--text-soft);
    }
    .assignee-picker__empty a, .project-picker__empty a {
        color: var(--accent);
        text-decoration: none;
        font-weight: 700;
        display: inline-flex;
        align-items: center;
        gap: 5px;
        margin-top: 8px;
    }

    .assignee-picker.is-disabled, .project-picker.is-disabled { pointer-events: none; opacity: 0.65; }
    .assignee-picker.is-disabled .assignee-picker__trigger,
    .project-picker.is-disabled .project-picker__trigger {
        cursor: not-allowed;
        background: #fafbfe;
        border-style: dashed;
    }

    input.field-locked, textarea.field-locked, select.field-locked {
        background: #fafbfe !important;
        color: var(--text-soft) !important;
        cursor: not-allowed !important;
        border-style: dashed !important;
        opacity: 0.85;
    }

    .hamburger-btn {
        display: none;
        width: 44px; height: 44px;
        border: none;
        background: linear-gradient(135deg, #4c8bf5 0%, #2f6bdc 100%);
        border-radius: 13px;
        cursor: pointer;
        flex-shrink: 0;
        align-items: center;
        justify-content: center;
        padding: 0;
        transition: transform 0.15s ease;
        box-shadow: 0 6px 16px rgba(76, 139, 245, 0.25);
    }
    .hamburger-btn:active { transform: scale(0.95); }
    .hamburger-btn .hamburger-lines {
        width: 22px; height: 16px;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
    }
    .hamburger-btn .hamburger-lines span {
        display: block;
        height: 2.5px;
        width: 100%;
        background: #fff;
        border-radius: 3px;
        transition: transform 0.3s, opacity 0.2s;
        transform-origin: center;
    }
    .hamburger-btn .hamburger-lines span:nth-child(2) { width: 70%; }
    .hamburger-btn.active .hamburger-lines span:nth-child(1) { transform: translateY(6.75px) rotate(45deg); }
    .hamburger-btn.active .hamburger-lines span:nth-child(2) { opacity: 0; transform: translateX(-10px); }
    .hamburger-btn.active .hamburger-lines span:nth-child(3) { transform: translateY(-6.75px) rotate(-45deg); }

    .sidebar-overlay {
        display: none;
        position: fixed;
        inset: 0;
        background: rgba(20, 30, 60, 0.5);
        z-index: 998;
        opacity: 0;
        transition: opacity 0.25s ease;
    }
    .sidebar-overlay.active { display: block; opacity: 1; }

    .subject-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
        gap: 14px;
    }
    .subject-item {
        background: #fafbfe;
        border: 1px solid var(--border);
        padding: 14px 16px;
        border-radius: 16px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        transition: box-shadow 0.2s ease;
    }
    .subject-item:hover {
        box-shadow: 0 8px 20px rgba(76, 108, 200, 0.08);
        border-color: #d8e0f0;
    }

    .app-toast {
        position: fixed;
        bottom: 30px;
        left: 50%;
        transform: translateX(-50%) translateY(80px);
        background: linear-gradient(135deg, #2b3452, #1e293b);
        color: #fff;
        padding: 12px 22px;
        border-radius: 30px;
        font-size: 0.85rem;
        font-weight: 700;
        display: flex;
        align-items: center;
        gap: 10px;
        box-shadow: 0 12px 32px rgba(20, 30, 60, 0.4);
        z-index: 9999;
        opacity: 0;
        transition: all 0.35s cubic-bezier(0.34, 1.56, 0.64, 1);
        pointer-events: none;
        max-width: 90vw;
    }
    .app-toast.active {
        opacity: 1;
        transform: translateX(-50%) translateY(0);
    }
    .app-toast i { font-size: 1rem; }
    .app-toast.toast-success i { color: #4ed4a3; }
    .app-toast.toast-error i { color: #ff6b8b; }
    .app-toast.toast-info i { color: #4c8bf5; }

    @media (max-width: 1100px) {
        .hero-stats { grid-template-columns: repeat(2, 1fr); }
        .charts-grid { grid-template-columns: 1fr; }
    }

    @media (max-width: 900px) {
        .hamburger-btn { display: flex; }
        .sidebar {
            position: fixed !important;
            top: 0; right: 0; bottom: 0;
            width: 280px;
            max-width: 85vw;
            transform: translateX(105%);
            transition: transform 0.35s cubic-bezier(0.65, 0, 0.35, 1);
            z-index: 999 !important;
            height: 100vh;
        }
        .sidebar.open { transform: translateX(0); }
        .main-wrapper { width: 100%; }
        .topbar { padding: 14px 18px 6px; gap: 12px; }
        .topbar h1 { font-size: 1rem; flex: 1; }
        .content-area { padding: 12px 18px 24px; }
        .hero-stats { grid-template-columns: repeat(2, 1fr); gap: 12px; }
        .hero-card { padding: 18px; min-height: 130px; }
        .hero-card__value { font-size: 1.7rem; }
        .hero-card__label { font-size: 0.75rem; }
        .hero-card__icon { width: 38px; height: 38px; font-size: 0.9rem; }
        .chart-card { padding: 18px; }
        .chart-body { height: 200px; }
        .task-row { flex-direction: column; align-items: stretch; gap: 12px; padding: 14px 16px; padding-right: 22px; }
        .task-row.received-task,
        .task-row.assigned-task { padding-right: 24px; }
        .task-info { max-width: 100%; width: 100%; }
        .task-row > div:last-child {
            display: flex !important;
            flex-wrap: wrap;
            gap: 8px !important;
            justify-content: flex-end;
            align-items: center;
        }
        .modal-box { max-width: 92vw; padding: 22px; }
        .task-modal-box { max-width: 94vw; padding: 0; }
        .tabs-header { flex-direction: column; align-items: stretch; gap: 12px; }
        .tabs-header > div:first-child { display: flex; gap: 8px; width: 100%; }
        .tabs-header .tab-btn { flex: 1; padding: 9px 10px; font-size: 0.82rem; }
        .tabs-header > button.btn { width: 100%; }
        div[style*="grid-template-columns: 350px 1fr"] { grid-template-columns: 1fr !important; gap: 16px !important; }
        .tm-row { grid-template-columns: 1fr; }
        .main-tabs-header { width: 100%; }
        .main-tab-btn { flex: 1; padding: 11px 12px; font-size: 0.85rem; justify-content: center; }
    }

    @media (max-width: 600px) {
        .content-area { padding: 10px 14px 20px; }
        .topbar { padding: 12px 14px 4px; }
        .topbar h1 { font-size: 0.9rem; }
        .topbar-user span:not(.avatar) { display: none; }
        .topbar-user { padding: 4px; }

        .hero-stats { grid-template-columns: 1fr; gap: 10px; }
        .hero-card {
            flex-direction: column;
            align-items: stretch;
            justify-content: space-between;
            min-height: 115px;
            padding: 14px 16px;
            gap: 10px;
        }
        .hero-card__top {
            position: static;
            display: flex;
            justify-content: space-between;
            align-items: center;
            width: 100%;
            gap: 8px;
        }
        .hero-card__icon { width: 36px; height: 36px; border-radius: 11px; font-size: 0.85rem; flex-shrink: 0; }
        .hero-card__dots { font-size: 1rem; opacity: 0.7; flex-shrink: 0; line-height: 1; padding: 4px; }
        .hero-card__bottom { text-align: right; direction: rtl; width: 100%; }
        .hero-card__value { font-size: 1.7rem; line-height: 1; letter-spacing: -0.5px; }
        .hero-card__label { font-size: 0.78rem; margin-top: 4px; }
        .hero-card::before { top: -60px; left: -60px; }
        .hero-card::after { display: none; }

        .soft-stats { grid-template-columns: 1fr; }
        .soft-card { padding: 14px; }
        .soft-card__value { font-size: 1.15rem; }
        .soft-card__icon { width: 38px; height: 38px; font-size: 0.85rem; }
        .time-grid { grid-template-columns: 1fr; }
        .time-card { padding: 18px; }
        .time-card-main-value { font-size: 1.25rem; }
        .card { padding: 18px; }
        .chart-card { padding: 16px; }
        .chart-body { height: 180px; }
        .modal-box { max-width: 96vw; padding: 18px; }
        .task-modal-box { max-width: 96vw; padding: 0; }
        .task-modal-header { padding: 14px 18px; }
        .task-modal-header h3 { font-size: 0.92rem; }
        .task-modal-header h3 .tm-header-icon { width: 30px; height: 30px; font-size: 0.78rem; }
        .tm-body { padding: 16px 18px 4px; }
        .tm-footer { padding: 14px 18px; }
        .tm-footer button { padding: 10px 18px; font-size: 0.85rem; }
        input[type="text"], input[type="date"], select, textarea,
        .tm-input { padding: 10px 14px; font-size: 0.88rem; }
        .assignee-picker__trigger, .project-picker__trigger { min-height: 52px; padding: 9px 12px; }
        .assignee-picker__avatar, .project-picker__avatar { width: 34px; height: 34px; font-size: 0.85rem; }
        .main-tab-btn { padding: 10px 8px; font-size: 0.78rem; gap: 5px; }
        .main-tab-badge { padding: 1px 6px; font-size: 0.62rem; }

        .page-btn { min-width: 34px; height: 34px; font-size: 0.78rem; padding: 0 8px; }
        .page-info { font-size: 0.7rem; }
    }

    @media (max-width: 400px) {
        .hero-stats { grid-template-columns: 1fr; }
        .tabs-header .tab-btn { font-size: 0.75rem; padding: 8px 6px; }
        .main-tab-btn { font-size: 0.72rem; padding: 9px 6px; }
        .main-tab-btn i { display: none; }
    }

    @media (prefers-reduced-motion: reduce) {
        * { transition: none !important; animation: none !important; }
    }
</style>
</head>
<body>

<?php sidebar(); ?>

<div class="main-wrapper">
    <div class="topbar">
        <button class="hamburger-btn" id="hamburgerBtn" onclick="toggleSidebar()" aria-label="باز کردن منو">
            <div class="hamburger-lines">
                <span></span><span></span><span></span>
            </div>
        </button>
        <h1>
            <?= $page === 'dashboard' ? 'داشبورد مدیریت' : ($page === 'subjects' ? 'مدیریت موضوعات' : 'لیست وظایف') ?>
            <small><?= $page === 'dashboard' ? 'خلاصه‌ای از وضعیت امروز شما' : ($page === 'subjects' ? 'دسته‌بندی وظایف' : 'همه وظایف شما') ?></small>
        </h1>
        <div class="topbar-user">
            <div class="avatar">
                <?php if ($currentUserAvatar): ?>
                    <img src="<?= htmlspecialchars($currentUserAvatar, ENT_QUOTES, 'UTF-8') ?>" alt="">
                <?php else: ?>
                    <?= htmlspecialchars(mb_substr($currentUserFirstName, 0, 1, 'UTF-8'), ENT_QUOTES, 'UTF-8') ?>
                <?php endif; ?>
            </div>
            <span><?= htmlspecialchars($displayUser, ENT_QUOTES, 'UTF-8') ?></span>
        </div>
    </div>

    <div class="content-area">
        <?php if (!empty($msg)): ?>
            <div class="flash flash-<?= htmlspecialchars($msgType, ENT_QUOTES, 'UTF-8') ?>">
                <?= htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <?php if ($page === 'dashboard'): ?>

            <div class="hero-stats">
                <div class="hero-card card-red">
                    <div class="hero-card__top">
                        <div class="hero-card__icon"><i class="fas fa-list-check"></i></div>
                        <i class="fas fa-ellipsis-vertical hero-card__dots"></i>
                    </div>
                    <div class="hero-card__bottom">
                        <div class="hero-card__value"><?= $cTotal ?></div>
                        <div class="hero-card__label">کل وظایف من</div>
                    </div>
                </div>

                <div class="hero-card card-orange">
                    <div class="hero-card__top">
                        <div class="hero-card__icon"><i class="fas fa-clock"></i></div>
                        <i class="fas fa-ellipsis-vertical hero-card__dots"></i>
                    </div>
                    <div class="hero-card__bottom">
                        <div class="hero-card__value"><?= $cPend ?></div>
                        <div class="hero-card__label">در انتظار انجام</div>
                    </div>
                </div>

                <div class="hero-card card-purple">
                    <div class="hero-card__top">
                        <div class="hero-card__icon"><i class="fas fa-check-double"></i></div>
                        <i class="fas fa-ellipsis-vertical hero-card__dots"></i>
                    </div>
                    <div class="hero-card__bottom">
                        <div class="hero-card__value"><?= $cComp ?></div>
                        <div class="hero-card__label">تکمیل شده</div>
                    </div>
                </div>

                <div class="hero-card card-green">
                    <div class="hero-card__top">
                        <div class="hero-card__icon"><i class="fas fa-chart-line"></i></div>
                        <i class="fas fa-ellipsis-vertical hero-card__dots"></i>
                    </div>
                    <div class="hero-card__bottom">
                        <div class="hero-card__value"><?= $ratio ?>%</div>
                        <div class="hero-card__label">نرخ پیشرفت</div>
                    </div>
                </div>
            </div>

            <div class="charts-grid">
                <div class="chart-card">
                    <div class="chart-head">
                        <h3><i class="fas fa-chart-line blue"></i> روند ۷ روز اخیر</h3>
                        <div class="legend"><span class="dot"></span> وظایف من</div>
                    </div>
                    <div class="chart-body"><canvas id="trendChart"></canvas></div>
                </div>

                <div class="chart-card">
                    <div class="chart-head">
                        <h3><i class="fas fa-chart-column green"></i> توزیع اولویت وظایف</h3>
                        <div class="legend"><span class="dot green"></span> تعداد</div>
                    </div>
                    <div class="chart-body"><canvas id="priorityChart"></canvas></div>
                </div>
            </div>

            <div class="section-label">
                <i class="fas fa-ticket-alt" style="color: #7f4cf0;"></i>
                آمار تیکت‌ها
                <span class="section-hint">(سراسری — کل سیستم)</span>
            </div>
            <div class="soft-stats">
                <div class="soft-card">
                    <div class="soft-card__info">
                        <div class="soft-card__label">کل تیکت‌ها</div>
                        <div class="soft-card__value"><?= $cTkTotal ?></div>
                    </div>
                    <div class="soft-card__icon purple"><i class="fas fa-ticket"></i></div>
                </div>
                <div class="soft-card">
                    <div class="soft-card__info">
                        <div class="soft-card__label">انجام شده</div>
                        <div class="soft-card__value" style="color: var(--success);"><?= $cTkDone ?></div>
                    </div>
                    <div class="soft-card__icon green"><i class="fas fa-check-double"></i></div>
                </div>
                <div class="soft-card">
                    <div class="soft-card__info">
                        <div class="soft-card__label">در انتظار</div>
                        <div class="soft-card__value" style="color: var(--warning);"><?= $cTkPending ?></div>
                    </div>
                    <div class="soft-card__icon orange"><i class="fas fa-hourglass-half"></i></div>
                </div>
                <div class="soft-card">
                    <div class="soft-card__info">
                        <div class="soft-card__label">اولویت خیلی زیاد</div>
                        <div class="soft-card__value" style="color: var(--danger);"><?= $cTkHigh ?></div>
                    </div>
                    <div class="soft-card__icon pink"><i class="fas fa-exclamation-circle"></i></div>
                </div>
                <div class="soft-card">
                    <div class="soft-card__info">
                        <div class="soft-card__label">ثبت امروز</div>
                        <div class="soft-card__value"><?= $cTkToday ?></div>
                    </div>
                    <div class="soft-card__icon blue"><i class="fas fa-calendar-day"></i></div>
                </div>
                <div class="soft-card">
                    <div class="soft-card__info">
                        <div class="soft-card__label">تیکت‌های VIP</div>
                        <div class="soft-card__value" style="color: #ca8a04;"><?= $cTkVIP ?></div>
                    </div>
                    <div class="soft-card__icon yellow"><i class="fas fa-crown"></i></div>
                </div>
            </div>

            <div class="section-label">
                <i class="fas fa-chart-pie" style="color: #2ebc8a;"></i>
                آمار سایر بخش‌ها
            </div>
            <div class="soft-stats">
                <div class="soft-card">
                    <div class="soft-card__info">
                        <div class="soft-card__label">کل متون آماده</div>
                        <div class="soft-card__value"><?= $cTxTotal ?></div>
                    </div>
                    <div class="soft-card__icon blue"><i class="fas fa-pen-fancy"></i></div>
                </div>
                <div class="soft-card">
                    <div class="soft-card__info">
                        <div class="soft-card__label">کل درخواست‌های تماس</div>
                        <div class="soft-card__value"><?= $cCrTotal ?></div>
                    </div>
                    <div class="soft-card__icon purple"><i class="fas fa-phone-volume"></i></div>
                </div>
                <div class="soft-card">
                    <div class="soft-card__info">
                        <div class="soft-card__label">درخواست‌های جدید</div>
                        <div class="soft-card__value" style="color: var(--warning);"><?= $cCrNew ?></div>
                    </div>
                    <div class="soft-card__icon orange"><i class="fas fa-bell"></i></div>
                </div>
                <div class="soft-card">
                    <div class="soft-card__info">
                        <div class="soft-card__label">درخواست‌های انجام شده</div>
                        <div class="soft-card__value" style="color: var(--success);"><?= $cCrDone ?></div>
                    </div>
                    <div class="soft-card__icon green"><i class="fas fa-phone-square"></i></div>
                </div>
                <div class="soft-card">
                    <div class="soft-card__info">
                        <div class="soft-card__label">محبوب‌ترین موضوع</div>
                        <div class="soft-card__value" style="font-size: 1rem;"><?= htmlspecialchars($topSubName, ENT_QUOTES, 'UTF-8') ?></div>
                    </div>
                    <div class="soft-card__icon yellow"><i class="fas fa-star"></i></div>
                </div>
            </div>

            <div class="charts-grid" style="margin-top: 22px;">
                <div class="chart-card">
                    <div class="chart-head">
                        <h3><i class="fas fa-chart-pie purple"></i> وضعیت وظایف من</h3>
                    </div>
                    <div class="chart-body"><canvas id="tasksChart"></canvas></div>
                </div>
                <div class="chart-card">
                    <div class="chart-head">
                        <h3><i class="fas fa-stopwatch orange"></i> مقایسه میانگین زمان (ساعت)</h3>
                    </div>
                    <div class="chart-body"><canvas id="avgTimeChart"></canvas></div>
                </div>
            </div>

            <div class="section-label" style="margin-top: 26px;">
                <i class="fas fa-stopwatch" style="color: #ff8a3d;"></i>
                تحلیل میانگین زمان انجام
            </div>

            <div class="time-grid">
                <div class="time-card">
                    <div class="time-card-header">
                        <div class="time-card-title">
                            <div class="soft-card__icon blue" style="width: 40px; height: 40px;">
                                <i class="fas fa-tasks"></i>
                            </div>
                            وظایف من
                        </div>
                        <div class="time-card-count"><?= $taskDoneCount ?> تکمیل‌شده</div>
                    </div>
                    <div class="time-card-main">
                        <div class="time-card-main-label">میانگین زمان</div>
                        <div class="time-card-main-value" style="color: #4c8bf5;"><?= formatDuration($taskAvgHours) ?></div>
                    </div>
                    <div class="time-card-footer">
                        <div><div class="label">سریع‌ترین</div><div class="value" style="color: var(--success);"><?= formatDuration($taskMinHours) ?></div></div>
                        <div class="time-card-divider"></div>
                        <div><div class="label">کندترین</div><div class="value" style="color: var(--danger);"><?= formatDuration($taskMaxHours) ?></div></div>
                    </div>
                </div>

                <div class="time-card">
                    <div class="time-card-header">
                        <div class="time-card-title">
                            <div class="soft-card__icon purple" style="width: 40px; height: 40px;">
                                <i class="fas fa-phone-volume"></i>
                            </div>
                            درخواست‌های تماس
                        </div>
                        <div class="time-card-count"><?= $callDoneCount ?> تکمیل‌شده</div>
                    </div>
                    <div class="time-card-main">
                        <div class="time-card-main-label">میانگین زمان</div>
                        <div class="time-card-main-value" style="color: #2ebc8a;"><?= formatDuration($callAvgHours) ?></div>
                    </div>
                    <div class="time-card-footer">
                        <div><div class="label">سریع‌ترین</div><div class="value" style="color: var(--success);"><?= formatDuration($callMinHours) ?></div></div>
                        <div class="time-card-divider"></div>
                        <div><div class="label">کندترین</div><div class="value" style="color: var(--danger);"><?= formatDuration($callMaxHours) ?></div></div>
                    </div>
                </div>

                <div class="time-card">
                    <div class="time-card-header">
                        <div class="time-card-title">
                            <div class="soft-card__icon orange" style="width: 40px; height: 40px;">
                                <i class="fas fa-ticket-alt"></i>
                            </div>
                            تیکت‌ها (سراسری)
                        </div>
                        <div class="time-card-count"><?= $ticketDoneCount ?> تکمیل‌شده</div>
                    </div>
                    <div class="time-card-main">
                        <div class="time-card-main-label">میانگین زمان</div>
                        <div class="time-card-main-value" style="color: #7f4cf0;"><?= formatDuration($ticketAvgHours) ?></div>
                    </div>
                    <div class="time-card-footer">
                        <div><div class="label">سریع‌ترین</div><div class="value" style="color: var(--success);"><?= formatDuration($ticketMinHours) ?></div></div>
                        <div class="time-card-divider"></div>
                        <div><div class="label">کندترین</div><div class="value" style="color: var(--danger);"><?= formatDuration($ticketMaxHours) ?></div></div>
                    </div>
                </div>
            </div>

            <div class="charts-grid">
                <div class="card" style="margin-bottom: 0;">
                    <h3><i class="fas fa-percentage" style="color: #4c8bf5;"></i> پیشرفت کلی</h3>
                    <?php
                        $tkRatio = $cTkTotal > 0 ? round(($cTkDone / $cTkTotal) * 100) : 0;
                        $crRatio = $cCrTotal > 0 ? round(($cCrDone / $cCrTotal) * 100) : 0;
                    ?>
                    <div class="progress-item">
                        <div class="progress-header"><span>وظایف انجام شده</span><span style="color: #4c8bf5;"><?= $ratio ?>%</span></div>
                        <div class="progress-track"><div class="progress-fill" style="width: <?= $ratio ?>%; background: linear-gradient(90deg, #4c8bf5, #2f6bdc);"></div></div>
                    </div>
                    <div class="progress-item">
                        <div class="progress-header"><span>تیکت‌های انجام شده</span><span style="color: #7f4cf0;"><?= $tkRatio ?>%</span></div>
                        <div class="progress-track"><div class="progress-fill" style="width: <?= $tkRatio ?>%; background: linear-gradient(90deg, #a26bfa, #7f4cf0);"></div></div>
                    </div>
                    <div class="progress-item">
                        <div class="progress-header"><span>درخواست‌های تماس انجام شده</span><span style="color: #2ebc8a;"><?= $crRatio ?>%</span></div>
                        <div class="progress-track"><div class="progress-fill" style="width: <?= $crRatio ?>%; background: linear-gradient(90deg, #4ed4a3, #2ebc8a);"></div></div>
                    </div>
                </div>

                <div class="card" style="margin-bottom: 0;">
                    <h3><i class="fas fa-clipboard-check" style="color: #4c8bf5;"></i> خلاصه وضعیت</h3>
                    <?php
                        $items = [
                            ['label' => 'وظایف در انتظار من', 'value' => $cPend, 'icon' => 'fa-clock', 'color' => '#ff8a3d', 'bg' => '#fff3e0'],
                            ['label' => 'واگذارشده به من', 'value' => $cAssignedToMe, 'icon' => 'fa-inbox', 'color' => '#ff8a3d', 'bg' => '#fff3e0'],
                            ['label' => 'واگذارشده توسط من', 'value' => $cAssignedByMe, 'icon' => 'fa-paper-plane', 'color' => '#7f4cf0', 'bg' => '#f0e9ff'],
                            ['label' => 'تیکت‌های در انتظار (سیستم)', 'value' => $cTkPending, 'icon' => 'fa-hourglass-half', 'color' => '#7f4cf0', 'bg' => '#f0e9ff'],
                            ['label' => 'درخواست‌های تماس جدید', 'value' => $cCrNew, 'icon' => 'fa-bell', 'color' => '#f54e7a', 'bg' => '#ffe4ec'],
                            ['label' => 'تیکت‌های VIP (سیستم)', 'value' => $cTkVIP, 'icon' => 'fa-crown', 'color' => '#ca8a04', 'bg' => '#fff7d6'],
                        ];
                        foreach ($items as $item):
                    ?>
                    <div class="summary-item">
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <div class="summary-icon" style="background: <?= $item['bg'] ?>; color: <?= $item['color'] ?>;">
                                <i class="fas <?= $item['icon'] ?>"></i>
                            </div>
                            <span class="summary-label"><?= $item['label'] ?></span>
                        </div>
                        <span class="summary-value" style="color: <?= $item['color'] ?>;"><?= $item['value'] ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <script>
            window.DASHBOARD_DATA = {
                tasksCompleted: <?= (int)$cComp ?>,
                tasksPending: <?= (int)$cPend ?>,
                priorityLow: <?= (int)$cLow ?>,
                priorityMedium: <?= (int)$cMed ?>,
                priorityHigh: <?= (int)$cHigh ?>,
                trendDays: <?= safeJsonEncode($trendDays) ?>,
                trendCounts: <?= safeJsonEncode($trendCounts) ?>,
                tkTrendCounts: <?= safeJsonEncode($tkTrendCounts) ?>,
                taskAvgHours: <?= (float)$taskAvgHours ?>,
                callAvgHours: <?= (float)$callAvgHours ?>,
                ticketAvgHours: <?= (float)$ticketAvgHours ?>
            };
            </script>

        <?php elseif ($page === 'subjects'): ?>
            <div style="display: grid; grid-template-columns: 350px 1fr; gap: 22px; align-items: start;">
                <div class="card" style="margin-bottom: 0;">
                    <h3><i class="fas fa-folder-plus" style="color: #4c8bf5;"></i> افزودن موضوع جدید</h3>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="form_token" value="<?= htmlspecialchars($formTokenSubject, ENT_QUOTES, 'UTF-8') ?>">
                        <div class="form-group">
                            <label>عنوان موضوع</label>
                            <input type="text" name="subject_title" placeholder="مثلاً: برنامه‌نویسی، شخصی..." required maxlength="100">
                        </div>
                        <button type="submit" name="add_subject" class="btn" style="width: 100%;">ثبت موضوع</button>
                    </form>
                </div>

                <div class="card" style="margin-bottom: 0;">
                    <h3><i class="fas fa-tags" style="color: #4c8bf5;"></i> دسته‌بندی‌های ثبت‌شده</h3>
                    <?php if (empty($subjects)): ?>
                        <p style="text-align: center; color: var(--text-soft); padding: 30px; font-size: 0.9rem;">هنوز هیچ موضوعی تعریف نشده است.</p>
                    <?php else: ?>
                        <div class="subject-grid">
                            <?php foreach ($subjects as $sub): ?>
                                <div class="subject-item">
                                    <div style="display: flex; align-items: center; gap: 10px; overflow: hidden;">
                                        <div class="soft-card__icon blue" style="width: 36px; height: 36px; border-radius: 10px;">
                                            <i class="fas fa-tag"></i>
                                        </div>
                                        <div style="font-weight: 700; font-size: 0.88rem; color: var(--text); white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                                            <?= htmlspecialchars($sub['title'], ENT_QUOTES, 'UTF-8') ?>
                                        </div>
                                    </div>
                                    <div style="display: flex; align-items: center; gap: 6px;">
                                        <button type="button" class="subject-edit-btn"
                                                data-subject-id="<?= (int)$sub['id'] ?>"
                                                data-subject-title="<?= htmlspecialchars($sub['title'], ENT_QUOTES, 'UTF-8') ?>"
                                                onclick="openEditSubjectModalFromBtn(this)" title="ویرایش">
                                            <i class="fas fa-pen"></i>
                                        </button>
                                        <form method="POST" style="display:inline;" onsubmit="return confirm('با حذف این موضوع، دسته‌بندی تسک‌های مرتبط به حالت بدون موضوع تغییر می‌کند. مطمئن هستید؟');">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="delete_subject" value="<?= (int)$sub['id'] ?>">
                                            <button type="submit" class="action-link-delete" title="حذف">
                                                <i class="fas fa-trash-alt"></i>
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php else: ?>
            <div class="card">
                <?php
                $renderTaskRow = function($t, $userId, $csrfToken, $makeProjectImageUrl) {
                    $isAssignedByMe = ((int)$t['user_id'] === $userId) && ((int)$t['assignee_id'] !== $userId);
                    $isAssignedToMe = ((int)$t['user_id'] !== $userId) && ((int)$t['assignee_id'] === $userId);
                    $assigneeName = trim(($t['assignee_first_name'] ?? '') . ' ' . ($t['assignee_last_name'] ?? ''));
                    $creatorName  = trim(($t['creator_first_name'] ?? '') . ' ' . ($t['creator_last_name'] ?? ''));
                    $assigneeMobile = $t['assignee_mobile'] ?? '';
                    $creatorMobile  = $t['creator_mobile'] ?? '';
                    if ($assigneeName === '') $assigneeName = $assigneeMobile;
                    if ($creatorName === '')  $creatorName  = $creatorMobile ?: 'کاربر';

                    $taskPerms = ['edit' => false, 'delete' => false, 'complete' => false, 'reassign' => false, 'notes' => false, 'change_project' => false];
                    $canOpenEditModal = false;

                    if ((int)$t['user_id'] === $userId) {
                        $taskPerms = ['edit' => true, 'delete' => true, 'complete' => true, 'reassign' => true, 'notes' => true, 'change_project' => true];
                        $canOpenEditModal = true;
                    } else {
                        $myPerms = json_decode($t['my_task_permissions'] ?? '[]', true);
                        if (!is_array($myPerms)) $myPerms = [];
                        foreach ($myPerms as $p) {
                            if (isset($taskPerms[$p])) $taskPerms[$p] = true;
                        }
                        if ((int)$t['assignee_id'] === $userId) {
                            $taskPerms['notes'] = true;
                            $taskPerms['complete'] = true;
                        }
                        $canOpenEditModal = !empty(array_filter($taskPerms, fn($v) => $v === true));
                    }

                    $hasProject = !empty($t['project_id']) && !empty($t['project_title']);
                    $projectInitial = $hasProject ? mb_substr(trim($t['project_title']), 0, 1, 'UTF-8') : '';
                    $projectImgUrl = $hasProject ? $makeProjectImageUrl($t['project_image'] ?? null) : null;

                    $priorityLabels = ['low' => 'کم', 'medium' => 'متوسط', 'high' => 'زیاد'];
                    $priorityLabel = $priorityLabels[$t['priority']] ?? $t['priority'];

                    $notesCount = (int)($t['notes_count'] ?? 0);
                    $attCount   = (int)($t['attachments_count'] ?? 0);
                    $isCompleted = !empty($t['is_completed']);

                    /* ⭐ محاسبه وضعیت عقب‌افتادگی */
                    $today = date('Y-m-d');
                    $isOverdue = !$isCompleted && !empty($t['due_date']) && $t['due_date'] < $today;

                    /* ⭐ نمایش تاریخ‌ها به شمسی */
                    $dueDateDisplay = $t['due_date'] ? formatPersianDateOnly($t['due_date'] . ' 00:00:00') : '';
                    $dueDateRaw = $t['due_date'] ?? '';
                    $createdDateDisplay = !empty($t['created_at']) ? formatPersianDateShort($t['created_at']) : '';
                    $completedDateDisplay = (!empty($t['completed_at']) && $isCompleted) ? formatPersianDateShort($t['completed_at']) : '';

                    $taskDataForJs = safeJsonEncode([
                        'id' => (int)$t['id'],
                        'title' => (string)$t['title'],
                        'subject_id' => $t['subject_id'],
                        'subject_title' => (string)($t['subject_title'] ?? ''),
                        'project_id' => $t['project_id'],
                        'project_title' => (string)($t['project_title'] ?? ''),
                        'project_image_url' => $projectImgUrl,
                        'project_initial' => $projectInitial,
                        'assignee_id' => (int)$t['assignee_id'],
                        'assignee_name' => $assigneeName,
                        'creator_name' => $creatorName,
                        'priority' => (string)$t['priority'],
                        'priority_label' => $priorityLabel,
                        'due_date' => $dueDateDisplay,
                        'due_date_raw' => $dueDateRaw,
                        'description' => (string)($t['description'] ?? ''),
                        'attachments_count' => $attCount,
                        'is_overdue' => $isOverdue,
                    ]);

                    $completedClass = $isCompleted ? 'completed-task' : '';
                    $overdueClass = $isOverdue ? 'overdue-task' : '';
                    $checked = $isCompleted ? 'checked' : '';
                    ?>
                    <div class="task-row <?= $completedClass ?> <?= $overdueClass ?> <?= $isAssignedToMe ? 'received-task' : '' ?> <?= $isAssignedByMe ? 'assigned-task' : '' ?> <?= $canOpenEditModal ? '' : 'no-edit' ?>"
                         id="task-row-<?= (int)$t['id'] ?>"
                         data-task='<?= $taskDataForJs ?>'
                         data-can-edit="<?= $taskPerms['edit'] ? '1' : '0' ?>"
                         data-can-reassign="<?= $taskPerms['reassign'] ? '1' : '0' ?>"
                         data-can-change-project="<?= $taskPerms['change_project'] ? '1' : '0' ?>"
                         data-can-complete="<?= $taskPerms['complete'] ? '1' : '0' ?>"
                         data-can-open="<?= $canOpenEditModal ? '1' : '0' ?>"
                         data-can-notes="<?= $taskPerms['notes'] ? '1' : '0' ?>"
                         onclick="handleTaskRowClick(this)">

                        <?php if ($isAssignedToMe): ?>
                            <div class="task-color-bar bar-received"></div>
                            <div class="task-tooltip tip-received">
                                <div class="tip-icon"><i class="fas fa-inbox"></i></div>
                                <div>
                                    <div class="tip-label">واگذارشده از طرف</div>
                                    <div><span class="tip-name"><?= htmlspecialchars($creatorName, ENT_QUOTES, 'UTF-8') ?></span>
                                        <?php if ($creatorMobile): ?><span class="tip-mobile"><?= htmlspecialchars($creatorMobile, ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php elseif ($isAssignedByMe): ?>
                            <div class="task-color-bar bar-sent"></div>
                            <div class="task-tooltip tip-sent">
                                <div class="tip-icon"><i class="fas fa-paper-plane"></i></div>
                                <div>
                                    <div class="tip-label">واگذارشده به</div>
                                    <div><span class="tip-name"><?= htmlspecialchars($assigneeName, ENT_QUOTES, 'UTF-8') ?></span>
                                        <?php if ($assigneeMobile): ?><span class="tip-mobile"><?= htmlspecialchars($assigneeMobile, ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>

                        <div class="task-info">
                            <?php if ($taskPerms['complete']): ?>
                                <input type="checkbox" class="custom-checkbox" <?= $checked ?> onclick="event.stopPropagation();" onchange="toggleTask(<?= (int)$t['id'] ?>)">
                            <?php else: ?>
                                <input type="checkbox" class="custom-checkbox" <?= $checked ?> disabled title="شما دسترسی تغییر وضعیت این تسک را ندارید">
                            <?php endif; ?>
                            <div style="min-width: 0; max-width: 700px;">
                                <div class="task-title">
                                    <?= htmlspecialchars($t['title'], ENT_QUOTES, 'UTF-8') ?>
                                    <span class="task-badges">
                                        <?php if ($notesCount > 0): ?>
                                            <span class="task-badge badge-notes"><i class="fas fa-comments"></i> <?= $notesCount ?></span>
                                        <?php endif; ?>
                                        <?php if ($attCount > 0): ?>
                                            <span class="task-badge badge-files"><i class="fas fa-paperclip"></i> <?= $attCount ?></span>
                                        <?php endif; ?>
                                    </span>
                                </div>

                                <?php if (!empty($t['description'])): ?>
                                    <div class="task-desc-clamp" title="<?= htmlspecialchars($t['description'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($t['description'], ENT_QUOTES, 'UTF-8') ?></div>
                                <?php endif; ?>

                                <!-- ⭐ Meta row: مهلت / تاریخ ثبت / تاریخ انجام -->
                                <?php if ($dueDateDisplay || $createdDateDisplay || $completedDateDisplay): ?>
                                <div class="task-meta-row">
                                    <?php if ($dueDateDisplay): ?>
                                        <span class="task-meta task-meta-due <?= $isOverdue ? 'overdue' : '' ?>">
                                            <i class="fas fa-calendar-day"></i>
                                            مهلت: <strong><?= htmlspecialchars($dueDateDisplay, ENT_QUOTES, 'UTF-8') ?></strong>
                                            <?php if ($isOverdue): ?>
                                                <span class="overdue-badge"><i class="fas fa-exclamation-triangle"></i> تاخیر</span>
                                            <?php endif; ?>
                                        </span>
                                    <?php endif; ?>

                                    <?php if ($createdDateDisplay): ?>
                                        <span class="task-meta task-meta-created">
                                            <i class="fas fa-pen-to-square"></i>
                                            ثبت: <?= htmlspecialchars($createdDateDisplay, ENT_QUOTES, 'UTF-8') ?>
                                        </span>
                                    <?php endif; ?>

                                    <?php if ($completedDateDisplay): ?>
                                        <span class="task-meta task-meta-completed">
                                            <i class="fas fa-circle-check"></i>
                                            انجام: <?= htmlspecialchars($completedDateDisplay, ENT_QUOTES, 'UTF-8') ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div style="display: flex; align-items: center; gap: 4px;" onclick="event.stopPropagation();">
                            <button type="button" class="task-info-btn" onclick="openInfoModalFromRow(<?= (int)$t['id'] ?>);" title="جزئیات وظیفه" style="margin: 0;">
                                <i class="fas fa-info-circle"></i>
                            </button>

                            <?php if ($taskPerms['delete']): ?>
                                <form method="POST" style="display:inline; margin: 0; padding: 0;" onsubmit="return confirm('حذف شود؟');">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="delete" value="<?= (int)$t['id'] ?>">
                                    <button type="submit" class="action-link-delete" title="حذف" style="margin: 0;">
                                        <i class="fas fa-trash-alt"></i>
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php
                };
                ?>

                <div class="main-tabs-header">
                    <button type="button" class="main-tab-btn <?= $mt === 'mine' ? 'active' : '' ?>" onclick="switchMainTab('mine', this)">
                        <i class="fas fa-user-check"></i>
                        وظایف من
                        <span class="main-tab-badge"><?= count($myTasksAll) ?></span>
                    </button>
                    <button type="button" class="main-tab-btn <?= $mt === 'others' ? 'active' : '' ?>" onclick="switchMainTab('others', this)">
                        <i class="fas fa-share"></i>
                        وظایف دیگران
                        <span class="main-tab-badge"><?= count($othersTasksAll) ?></span>
                    </button>
                </div>

                <div id="main-tab-mine" class="main-tab-content <?= $mt === 'mine' ? 'active' : '' ?>">
                    <div class="tabs-header">
                        <div style="display: flex; gap: 10px;">
                            <button type="button" class="tab-btn <?= ($mt === 'mine' && $st === 'uncompleted') ? 'active' : '' ?>" onclick="switchTab('mine-uncompleted', this)">انجام نشده (<?= count($myTasksUncompleted) ?>)</button>
                            <button type="button" class="tab-btn <?= ($mt === 'mine' && $st === 'completed') ? 'active' : '' ?>" onclick="switchTab('mine-completed', this)">انجام شده (<?= count($myTasksCompleted) ?>)</button>
                        </div>
                        <button type="button" class="btn" onclick="openModal('createTaskModal')"><i class="fas fa-plus" style="margin-left: 5px;"></i> ایجاد وظیفه جدید</button>
                    </div>

                    <div id="tab-mine-uncompleted" class="tab-content <?= ($mt === 'mine' && $st === 'uncompleted') ? 'active' : '' ?>">
                        <?php if (empty($myTasksUncompletedPaged)): ?>
                            <p style="text-align: center; color: var(--text-soft); padding: 20px;">هیچ وظیفه انجام‌نشده‌ای برای شما وجود ندارد.</p>
                        <?php else: ?>
                            <?php foreach ($myTasksUncompletedPaged as $t) $renderTaskRow($t, $userId, $csrfToken, $makeProjectImageUrl); ?>
                            <?= renderPagination($pageMineUncompleted, $totalPagesMineUncompleted, $totalCountMineUncompleted, 'p_mu', 'mine', 'uncompleted') ?>
                        <?php endif; ?>
                    </div>

                    <div id="tab-mine-completed" class="tab-content <?= ($mt === 'mine' && $st === 'completed') ? 'active' : '' ?>">
                        <?php if (empty($myTasksCompletedPaged)): ?>
                            <p style="text-align: center; color: var(--text-soft); padding: 20px;">هیچ وظیفه انجام‌شده‌ای برای شما وجود ندارد.</p>
                        <?php else: ?>
                            <?php foreach ($myTasksCompletedPaged as $t) $renderTaskRow($t, $userId, $csrfToken, $makeProjectImageUrl); ?>
                            <?= renderPagination($pageMineCompleted, $totalPagesMineCompleted, $totalCountMineCompleted, 'p_mc', 'mine', 'completed') ?>
                        <?php endif; ?>
                    </div>
                </div>

                <div id="main-tab-others" class="main-tab-content <?= $mt === 'others' ? 'active' : '' ?>">
                    <div class="tabs-header">
                        <div style="display: flex; gap: 10px;">
                            <button type="button" class="tab-btn <?= ($mt === 'others' && $st === 'uncompleted') ? 'active' : '' ?>" onclick="switchTab('others-uncompleted', this)">انجام نشده (<?= count($othersTasksUncompleted) ?>)</button>
                            <button type="button" class="tab-btn <?= ($mt === 'others' && $st === 'completed') ? 'active' : '' ?>" onclick="switchTab('others-completed', this)">انجام شده (<?= count($othersTasksCompleted) ?>)</button>
                        </div>
                        <button type="button" class="btn" onclick="openModal('createTaskModal')"><i class="fas fa-plus" style="margin-left: 5px;"></i> ایجاد وظیفه جدید</button>
                    </div>

                    <div id="tab-others-uncompleted" class="tab-content <?= ($mt === 'others' && $st === 'uncompleted') ? 'active' : '' ?>">
                        <?php if (empty($othersTasksUncompletedPaged)): ?>
                            <p style="text-align: center; color: var(--text-soft); padding: 20px;">هنوز برای دیگران وظیفه‌ای ایجاد نکرده‌اید (انجام نشده).</p>
                        <?php else: ?>
                            <?php foreach ($othersTasksUncompletedPaged as $t) $renderTaskRow($t, $userId, $csrfToken, $makeProjectImageUrl); ?>
                            <?= renderPagination($pageOthersUncompleted, $totalPagesOthersUncompleted, $totalCountOthersUncompleted, 'p_ou', 'others', 'uncompleted') ?>
                        <?php endif; ?>
                    </div>

                    <div id="tab-others-completed" class="tab-content <?= ($mt === 'others' && $st === 'completed') ? 'active' : '' ?>">
                        <?php if (empty($othersTasksCompletedPaged)): ?>
                            <p style="text-align: center; color: var(--text-soft); padding: 20px;">هنوز برای دیگران وظیفه‌ای ایجاد نکرده‌اید (انجام شده).</p>
                        <?php else: ?>
                            <?php foreach ($othersTasksCompletedPaged as $t) $renderTaskRow($t, $userId, $csrfToken, $makeProjectImageUrl); ?>
                            <?= renderPagination($pageOthersCompleted, $totalPagesOthersCompleted, $totalCountOthersCompleted, 'p_oc', 'others', 'completed') ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- ============================================================
     مودال ایجاد تسک
============================================================ -->
<div id="createTaskModal" class="modal-overlay">
    <div class="modal-box task-modal-box">
        <div class="task-modal-header">
            <h3>
                <span class="tm-header-icon"><i class="fas fa-plus"></i></span>
                مشخصات وظیفه
                <span class="tm-id-badge">جدید</span>
            </h3>
            <button type="button" class="modal-close" onclick="closeModal('createTaskModal')">&times;</button>
        </div>

        <form method="POST" class="task-modal-form" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="form_token" value="<?= htmlspecialchars($formTokenTask, ENT_QUOTES, 'UTF-8') ?>">

            <div class="tm-body">

                <div class="tm-hint">
                    <i class="fas fa-circle-info"></i>
                    <span>برای ذخیره CTRL + S را در کیبورد خود فشار دهید</span>
                </div>

                <div class="tm-field">
                    <label class="tm-label">
                        <i class="fas fa-pen tm-label-icon"></i>
                        عنوان وظیفه
                    </label>
                    <input type="text" name="task_title" class="tm-input" required maxlength="255" placeholder="عنوان را وارد کنید...">
                </div>

                <div class="tm-field">
                    <label class="tm-label">
                        <i class="fas fa-align-right tm-label-icon"></i>
                        توضیحات
                    </label>
                    <textarea name="task_desc" class="tm-input tm-textarea" rows="4" maxlength="5000" placeholder="شرح مشکل یا درخواست..."></textarea>
                </div>

                <div class="tm-divider"></div>

                <div class="tm-row">
                    <div class="tm-field" style="margin-bottom: 12px;">
                        <label class="tm-label">
                            <i class="fas fa-tag tm-label-icon"></i>
                            موضوع
                        </label>
                        <select name="subject_id" class="tm-input">
                            <option value="">بدون موضوع</option>
                            <?php foreach ($subjects as $sub): ?>
                                <option value="<?= (int)$sub['id'] ?>"><?= htmlspecialchars($sub['title'], ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="tm-field" style="margin-bottom: 12px;">
                        <label class="tm-label">
                            <i class="fas fa-flag tm-label-icon"></i>
                            اولویت
                        </label>
                        <div class="tm-priority-group">
                            <label class="tm-priority-opt" data-priority="low">
                                <input type="radio" name="priority" value="low">
                                <span>کم</span>
                            </label>
                            <label class="tm-priority-opt" data-priority="medium">
                                <input type="radio" name="priority" value="medium" checked>
                                <span>متوسط</span>
                            </label>
                            <label class="tm-priority-opt" data-priority="high">
                                <input type="radio" name="priority" value="high">
                                <span>زیاد</span>
                            </label>
                        </div>
                    </div>
                </div>

                <div class="tm-field">
                    <label class="tm-label">
                        <i class="fas fa-folder tm-label-icon"></i>
                        پروژه
                    </label>
                    <div class="project-picker" id="createProjectPicker">
                        <input type="hidden" name="project_id" value="" id="createProjectInput">
                        <div class="project-picker__trigger" onclick="toggleProjectPicker('createProjectPicker')">
                            <div class="project-picker__avatar is-empty" id="createProjectAvatar">
                                <i class="fas fa-folder"></i>
                            </div>
                            <div class="project-picker__info">
                                <div class="project-picker__name" id="createProjectName">بدون پروژه</div>
                                <div class="project-picker__mobile" id="createProjectMeta">—</div>
                            </div>
                            <i class="fas fa-chevron-down project-picker__chevron"></i>
                        </div>
                        <div class="project-picker__dropdown" id="createProjectDropdown"></div>
                    </div>
                </div>

                <div class="tm-field">
                    <label class="tm-label">
                        <i class="fas fa-user tm-label-icon"></i>
                        مسئول وظیفه
                    </label>
                    <div class="assignee-picker" id="createAssigneePicker">
                        <input type="hidden" name="assignee_id" value="<?= (int)$userId ?>" id="createAssigneeInput">
                        <div class="assignee-picker__trigger" onclick="toggleAssigneePicker('createAssigneePicker')">
                            <div class="assignee-picker__avatar is-self" id="createAssigneeAvatar">
                                <?php if ($currentUserAvatar): ?>
                                    <img src="<?= htmlspecialchars($currentUserAvatar, ENT_QUOTES, 'UTF-8') ?>" alt="من">
                                <?php else: ?>
                                    <?= htmlspecialchars(mb_substr($currentUserFirstName, 0, 1, 'UTF-8'), ENT_QUOTES, 'UTF-8') ?>
                                <?php endif; ?>
                            </div>
                            <div class="assignee-picker__info">
                                <div class="assignee-picker__name" id="createAssigneeName">خودم</div>
                                <div class="assignee-picker__mobile" id="createAssigneeMobile"><?= htmlspecialchars($displayUser, ENT_QUOTES, 'UTF-8') ?></div>
                            </div>
                            <i class="fas fa-chevron-down assignee-picker__chevron"></i>
                        </div>
                        <div class="assignee-picker__dropdown" id="createAssigneeDropdown"></div>
                    </div>
                </div>

                <!-- ⭐ مهلت انجام با تقویم شمسی -->
                <div class="tm-field">
                    <label class="tm-label">
                        <i class="fas fa-calendar tm-label-icon"></i>
                        مهلت انجام
                    </label>
                    <div class="date-picker-wrap" id="create_due_date_wrap">
                        <input type="text"
                               id="create_due_date_display"
                               class="tm-input date-display-input"
                               placeholder="انتخاب تاریخ..."
                               autocomplete="off"
                               readonly>
                        <span class="date-picker-icon"><i class="fas fa-calendar-alt"></i></span>
                        <button type="button" class="date-clear-btn"
                                onclick="clearPersianDate('create')" title="پاک کردن">
                            <i class="fas fa-times"></i>
                        </button>
                        <input type="hidden" name="due_date" id="create_due_date" value="">
                    </div>
                </div>

                <div class="tm-field">
                    <label class="tm-label">
                        <i class="fas fa-paperclip tm-label-icon"></i>
                        فایل‌های پیوست
                    </label>

                    <div class="file-upload-zone" id="createFileDropZone">
                        <input type="file"
                               name="task_attachments[]"
                               id="createFileInput"
                               multiple
                               accept="<?= htmlspecialchars($allowedExtsAttr, ENT_QUOTES, 'UTF-8') ?>">
                        <div class="file-upload-zone__icon"><i class="fas fa-cloud-upload-alt"></i></div>
                        <div class="file-upload-zone__title">برای انتخاب فایل کلیک کنید یا فایل‌ها را اینجا رها کنید</div>
                        <div class="file-upload-zone__hint">
                            فرمت‌های مجاز:
                            <code>PDF</code> <code>Excel</code> <code>Word</code> <code>CSV</code>
                            <code>JPG</code> <code>PNG</code> <code>GIF</code> <code>ZIP</code> <code>RAR</code>
                            — حداکثر <code>10MB</code> برای هر فایل
                        </div>
                    </div>

                    <ul class="file-preview-list" id="createFilePreviewList"></ul>
                </div>

            </div>

            <div class="tm-footer">
                <button type="button" class="tm-btn-cancel" onclick="closeModal('createTaskModal')">
                    <i class="fas fa-times" style="margin-left: 6px;"></i>
                    بستن
                </button>
                <button type="submit" name="add_task" class="tm-btn-save">
                    <i class="fas fa-save"></i>
                    ثبت وظیفه
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ============================================================
     مودال ویرایش تسک
============================================================ -->
<div id="editTaskModal" class="modal-overlay" onclick="if(event.target === this) requestCloseEditModal()">
    <div class="modal-box task-modal-box" onclick="event.stopPropagation()">
        <div class="task-modal-header">
            <h3>
                <span class="tm-header-icon tm-edit-icon"><i class="fas fa-pen"></i></span>
                ویرایش وظیفه
                <span class="tm-id-badge" id="edit_id_badge">—</span>
            </h3>
            <div class="tm-header-actions">
                <button type="button" class="tm-header-share" id="edit_share_btn" onclick="copyTaskLink()" title="کپی لینک اشتراکی وظیفه">
                    <i class="fas fa-link"></i>
                </button>
                <button type="button" class="modal-close" onclick="requestCloseEditModal()">&times;</button>
            </div>
        </div>

        <form method="POST" class="task-modal-form" enctype="multipart/form-data" id="editTaskForm">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="form_token" value="<?= htmlspecialchars($formTokenTask, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="task_id" id="edit_task_id">

            <div class="tm-body">
                <div class="tm-hint">
                    <i class="fas fa-circle-info"></i>
                    <span>برای ذخیره CTRL + S را در کیبورد خود فشار دهید</span>
                </div>

                <div class="tm-field">
                    <label class="tm-label">
                        <i class="fas fa-pen tm-label-icon"></i>
                        عنوان وظیفه
                    </label>
                    <input type="text" name="task_title" id="edit_task_title" class="tm-input" required maxlength="255">
                </div>

                <div class="tm-field">
                    <label class="tm-label">
                        <i class="fas fa-align-right tm-label-icon"></i>
                        توضیحات
                    </label>
                    <textarea name="task_desc" id="edit_task_desc" class="tm-input tm-textarea" rows="4" maxlength="5000"></textarea>
                </div>

                <div class="tm-divider"></div>

                <div class="tm-row">
                    <div class="tm-field" style="margin-bottom: 12px;">
                        <label class="tm-label">
                            <i class="fas fa-tag tm-label-icon"></i>
                            موضوع
                        </label>
                        <select name="subject_id" id="edit_subject_id" class="tm-input">
                            <option value="">بدون موضوع</option>
                            <?php foreach ($subjects as $sub): ?>
                                <option value="<?= (int)$sub['id'] ?>"><?= htmlspecialchars($sub['title'], ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="tm-field" style="margin-bottom: 12px;">
                        <label class="tm-label">
                            <i class="fas fa-flag tm-label-icon"></i>
                            اولویت
                        </label>
                        <div class="tm-priority-group" id="edit_priority_group">
                            <label class="tm-priority-opt" data-priority="low">
                                <input type="radio" name="priority" value="low">
                                <span>کم</span>
                            </label>
                            <label class="tm-priority-opt" data-priority="medium">
                                <input type="radio" name="priority" value="medium">
                                <span>متوسط</span>
                            </label>
                            <label class="tm-priority-opt" data-priority="high">
                                <input type="radio" name="priority" value="high">
                                <span>زیاد</span>
                            </label>
                        </div>
                    </div>
                </div>

                <div class="tm-field">
                    <label class="tm-label">
                        <i class="fas fa-folder tm-label-icon"></i>
                        پروژه
                    </label>
                    <div class="project-picker" id="editProjectPicker">
                        <input type="hidden" name="project_id" value="" id="editProjectInput">
                        <div class="project-picker__trigger" onclick="toggleProjectPicker('editProjectPicker')">
                            <div class="project-picker__avatar is-empty" id="editProjectAvatar">
                                <i class="fas fa-folder"></i>
                            </div>
                            <div class="project-picker__info">
                                <div class="project-picker__name" id="editProjectName">بدون پروژه</div>
                                <div class="project-picker__mobile" id="editProjectMeta">—</div>
                            </div>
                            <i class="fas fa-chevron-down project-picker__chevron"></i>
                        </div>
                        <div class="project-picker__dropdown" id="editProjectDropdown"></div>
                    </div>
                </div>

                <div class="tm-field">
                    <label class="tm-label">
                        <i class="fas fa-user tm-label-icon"></i>
                        مسئول وظیفه
                    </label>
                    <div class="assignee-picker" id="editAssigneePicker">
                        <input type="hidden" name="assignee_id" value="<?= (int)$userId ?>" id="editAssigneeInput">
                        <div class="assignee-picker__trigger" onclick="toggleAssigneePicker('editAssigneePicker')">
                            <div class="assignee-picker__avatar is-self" id="editAssigneeAvatar">
                                <?php if ($currentUserAvatar): ?>
                                    <img src="<?= htmlspecialchars($currentUserAvatar, ENT_QUOTES, 'UTF-8') ?>" alt="من">
                                <?php else: ?>
                                    <?= htmlspecialchars(mb_substr($currentUserFirstName, 0, 1, 'UTF-8'), ENT_QUOTES, 'UTF-8') ?>
                                <?php endif; ?>
                            </div>
                            <div class="assignee-picker__info">
                                <div class="assignee-picker__name" id="editAssigneeName">خودم</div>
                                <div class="assignee-picker__mobile" id="editAssigneeMobile"><?= htmlspecialchars($displayUser, ENT_QUOTES, 'UTF-8') ?></div>
                            </div>
                            <i class="fas fa-chevron-down assignee-picker__chevron"></i>
                        </div>
                        <div class="assignee-picker__dropdown" id="editAssigneeDropdown"></div>
                    </div>
                </div>

                <!-- ⭐ مهلت انجام با تقویم شمسی (ویرایش) -->
                <div class="tm-field">
                    <label class="tm-label">
                        <i class="fas fa-calendar tm-label-icon"></i>
                        مهلت انجام
                    </label>
                    <div class="date-picker-wrap" id="edit_due_date_wrap">
                        <input type="text"
                               id="edit_due_date_display"
                               class="tm-input date-display-input"
                               placeholder="انتخاب تاریخ..."
                               autocomplete="off"
                               readonly>
                        <span class="date-picker-icon"><i class="fas fa-calendar-alt"></i></span>
                        <button type="button" class="date-clear-btn"
                                onclick="clearPersianDate('edit')" title="پاک کردن">
                            <i class="fas fa-times"></i>
                        </button>
                        <input type="hidden" name="due_date" id="edit_due_date" value="">
                    </div>
                </div>

                <div class="modal-attachments-section">
                    <div class="modal-attachments-header">
                        <span class="ma-icon"><i class="fas fa-paperclip"></i></span>
                        فایل‌های پیوست
                        <span class="ma-count" id="modalAttachmentsCount">۰</span>
                    </div>
                    <div class="modal-attachments-list" id="modalAttachmentsList">
                        <div class="modal-attachments-loading">در حال بارگذاری...</div>
                    </div>
                </div>

                <div class="tm-field">
                    <label class="tm-label">
                        <i class="fas fa-plus-circle tm-label-icon"></i>
                        افزودن فایل جدید
                    </label>
                    <div class="file-upload-zone" id="editFileDropZone">
                        <input type="file"
                               name="task_attachments[]"
                               id="editFileInput"
                               multiple
                               accept="<?= htmlspecialchars($allowedExtsAttr, ENT_QUOTES, 'UTF-8') ?>">
                        <div class="file-upload-zone__icon"><i class="fas fa-cloud-upload-alt"></i></div>
                        <div class="file-upload-zone__title">برای انتخاب فایل کلیک کنید یا فایل‌ها را اینجا رها کنید</div>
                        <div class="file-upload-zone__hint">
                            فرمت‌های مجاز:
                            <code>PDF</code> <code>Excel</code> <code>Word</code> <code>CSV</code>
                            <code>JPG</code> <code>PNG</code> <code>GIF</code> <code>ZIP</code> <code>RAR</code>
                            — حداکثر <code>10MB</code> برای هر فایل
                        </div>
                    </div>
                    <ul class="file-preview-list" id="editFilePreviewList"></ul>
                </div>

                <div class="modal-notes-section" id="modalNotesSection">
                    <div class="modal-notes-header">
                        <div class="modal-notes-header-icon"><i class="fas fa-comments"></i></div>
                        گزارش‌ها و یادداشت‌ها
                        <span class="modal-notes-header-count" id="modalNotesCount">۰</span>
                    </div>
                    <div class="modal-notes-list" id="modalNotesList">
                        <p class="modal-notes-empty">در حال بارگذاری...</p>
                    </div>
                    <div class="modal-note-input-wrap">
                        <div class="modal-note-mentions-preview" id="modalNoteMentionsPreview"></div>
                        <div class="modal-note-files-preview" id="modalNoteFilesPreview"></div>
                        <div class="modal-note-input-row">
                            <label class="modal-note-attach-btn" title="افزودن فایل پیوست">
                                <i class="fas fa-paperclip"></i>
                                <input type="file" id="modalNoteFileInput" multiple style="display:none"
                                       accept=".pdf,.xls,.xlsx,.csv,.doc,.docx,.txt,.rtf,.jpg,.jpeg,.png,.gif,.webp,.bmp,.zip,.rar,.7z">
                            </label>
                            <button type="button" class="modal-note-attach-btn" id="modalNoteMentionBtn" onclick="toggleMentionPicker(event)" title="منشن کردن همکار">
                                <i class="fas fa-at"></i>
                            </button>
                            <textarea id="modalNoteText"
                                      placeholder="متن گزارش را بنویسید... (Enter برای ثبت، Shift+Enter برای خط جدید)"
                                      maxlength="5000"></textarea>
                            <button type="button" class="modal-note-submit" id="modalNoteSubmitBtn" onclick="submitModalNote()" title="ثبت گزارش">
                                <i class="fas fa-paper-plane"></i>
                            </button>
                        </div>
                        <div class="modal-note-mention-picker" id="modalNoteMentionPicker" style="display:none;">
                            <div class="mention-picker__header">
                                <i class="fas fa-at"></i>
                                انتخاب همکار برای منشن
                            </div>
                            <div class="mention-picker__list" id="mentionPickerList"></div>
                        </div>
                    </div>
                </div>

            </div>

            <div class="tm-footer">
                <button type="button" class="tm-btn-cancel" onclick="requestCloseEditModal()">
                    <i class="fas fa-times" style="margin-left: 6px;"></i>
                    بستن
                </button>
                <button type="submit" name="update_task" class="tm-btn-save" id="edit_submit_btn">
                    <i class="fas fa-save"></i>
                    ذخیره تغییرات
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ============================================================
     مودال تایید بستن بدون ذخیره
============================================================ -->
<div id="confirmCloseModal" class="modal-overlay" onclick="if(event.target === this) closeModal('confirmCloseModal')">
    <div class="modal-box" style="max-width: 440px;" onclick="event.stopPropagation()">
        <div class="modal-header">
            <h3>
                <span style="width: 38px; height: 38px; border-radius: 12px; background: linear-gradient(135deg, #ffa751, #ff8a3d); color: #fff; display: inline-flex; align-items: center; justify-content: center; font-size: 0.95rem; margin-left: 10px;">
                    <i class="fas fa-exclamation-triangle"></i>
                </span>
                تغییرات ذخیره نشده
            </h3>
            <button class="modal-close" onclick="closeModal('confirmCloseModal')">&times;</button>
        </div>
        <p style="line-height: 1.9; color: var(--text); font-size: 0.9rem; margin-bottom: 20px;">
            شما تغییراتی در این وظیفه اعمال کرده‌اید که هنوز ذخیره نشده است. آیا مطمئن هستید که می‌خواهید بدون ذخیره ببندید؟
        </p>
        <div style="display: flex; gap: 10px; justify-content: flex-end; flex-wrap: wrap;">
            <button type="button" class="tm-btn-cancel" onclick="closeModal('confirmCloseModal')">
                <i class="fas fa-arrow-right" style="margin-left: 6px;"></i>
                بازگشت
            </button>
            <button type="button" class="tm-btn-cancel" id="confirmCloseDiscard" onclick="forceCloseEditModal()" style="background: #ffe4ec; color: #c81e4a;">
                <i class="fas fa-times" style="margin-left: 6px;"></i>
                بستن بدون ذخیره
            </button>
            <button type="button" class="tm-btn-save" id="confirmCloseSave" onclick="saveAndCloseEditModal()">
                <i class="fas fa-save"></i>
                ذخیره تغییرات
            </button>
        </div>
    </div>
</div>

<!-- ============================================================
     مودال جزئیات وظیفه (i)
============================================================ -->
<div id="taskInfoModal" class="modal-overlay">
    <div class="modal-box info-modal-box">
        <div class="modal-header">
            <h3>
                <span class="info-modal-header-icon"><i class="fas fa-info-circle"></i></span>
                جزئیات وظیفه
            </h3>
            <button class="modal-close" onclick="closeModal('taskInfoModal')">&times;</button>
        </div>

        <div class="info-task-title" id="info_task_title">—</div>

        <div class="info-grid">
            <div class="info-row">
                <span class="info-row__label"><i class="fas fa-folder purple"></i> پروژه</span>
                <span class="info-row__value" id="info_project">—</span>
            </div>
            <div class="info-row">
                <span class="info-row__label"><i class="fas fa-tag teal"></i> موضوع</span>
                <span class="info-row__value" id="info_subject">—</span>
            </div>
            <div class="info-row">
                <span class="info-row__label"><i class="fas fa-flag orange"></i> اولویت</span>
                <span class="info-row__value" id="info_priority">—</span>
            </div>
            <div class="info-row">
                <span class="info-row__label"><i class="fas fa-user-check blue"></i> مسئول وظیفه</span>
                <span class="info-row__value rtl" id="info_assignee">—</span>
            </div>
            <div class="info-row">
                <span class="info-row__label"><i class="fas fa-user-pen green"></i> ایجاد کننده</span>
                <span class="info-row__value rtl" id="info_creator">—</span>
            </div>
            <div class="info-row">
                <span class="info-row__label"><i class="fas fa-calendar-day pink"></i> مهلت انجام</span>
                <span class="info-row__value rtl" id="info_due">—</span>
            </div>
            <div class="info-row">
                <span class="info-row__label"><i class="fas fa-paperclip teal"></i> فایل‌های پیوست</span>
                <span class="info-row__value" id="info_attachments">—</span>
            </div>
        </div>
    </div>
</div>

<!-- پاپ‌آپ ویرایش موضوع -->
<div id="editSubjectModal" class="modal-overlay">
    <div class="modal-box" style="max-width: 400px;">
        <div class="modal-header">
            <h3>ویرایش موضوع</h3>
            <button class="modal-close" onclick="closeModal('editSubjectModal')">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="form_token" value="<?= htmlspecialchars($formTokenSubject, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="subject_id" id="edit_subject_id_field">
            <div class="form-group">
                <label>عنوان موضوع</label>
                <input type="text" name="subject_title" id="edit_subject_title_field" required maxlength="100">
            </div>
            <button type="submit" name="update_subject" class="btn" style="width: 100%; margin-top: 5px;">ذخیره تغییرات</button>
        </form>
    </div>
</div>

<!-- ============================================================
     Toast
============================================================ -->
<div class="app-toast" id="appToast">
    <i class="fas fa-check-circle"></i>
    <span id="appToastText"></span>
</div>

<script>
    const CURRENT_USER_ID = <?= (int)$userId ?>;
    const CSRF_TOKEN = <?= safeJsonEncode($csrfToken) ?>;
    const ASSIGNEE_OPTIONS = <?= $assigneeOptionsJson ?>;
    const PROJECT_OPTIONS = <?= $projectOptionsJson ?>;
    const PROJECTS_MEMBERS = <?= $projectsMembersJson ?>;
    const MENTION_OPTIONS = <?= $mentionOptionsJson ?>;
    <?php if ($sharedTaskData): ?>
    var SHARED_TASK_DATA = <?= safeJsonEncode($sharedTaskData) ?>;
    var SHARED_TASK_PERMS = {
        edit: <?= $sharedCanEdit ? 'true' : 'false' ?>,
        reassign: <?= $sharedCanReassign ? 'true' : 'false' ?>,
        change_project: <?= $sharedCanChangeProject ? 'true' : 'false' ?>,
        complete: <?= $sharedCanComplete ? 'true' : 'false' ?>
    };
    <?php endif; ?>

/* ============================================================
   🔒 قفل راست‌کلیک + کپی/برش/درگ
============================================================ */
(function() {
    function isEditable(el) {
        if (!el || !el.tagName) return false;
        const tag = el.tagName.toLowerCase();
        return tag === 'input' || tag === 'textarea' || tag === 'select' || el.isContentEditable;
    }

    function isContentArea(el) {
        if (!el || !el.closest) return false;
        return !!(
            el.closest('.task-title') ||
            el.closest('.task-desc-clamp') ||
            el.closest('.task-meta-row') ||
            el.closest('.modal-note-text') ||
            el.closest('.task-tooltip') ||
            el.closest('.info-task-title') ||
            el.closest('.file-preview-item__name') ||
            el.closest('.modal-note-file-item__name') ||
            el.closest('.modal-note-attachment__name')
        );
    }

    document.addEventListener('contextmenu', function(e) {
        if (isEditable(e.target) || isContentArea(e.target)) return true;
        e.preventDefault();
        return false;
    }, true);

    document.addEventListener('selectstart', function(e) {
        if (isEditable(e.target) || isContentArea(e.target)) return true;
        e.preventDefault();
        return false;
    }, true);

    document.addEventListener('copy', function(e) {
        if (isEditable(e.target) || isContentArea(e.target)) return true;
        e.preventDefault();
        return false;
    }, true);

    document.addEventListener('cut', function(e) {
        if (isEditable(e.target)) return true;
        e.preventDefault();
        return false;
    }, true);

    document.addEventListener('dragstart', function(e) {
        if (isEditable(e.target) || isContentArea(e.target)) return true;
        e.preventDefault();
        return false;
    }, true);

    document.addEventListener('mousedown', function(e) {
        if (e.detail > 1 && !isEditable(e.target) && !isContentArea(e.target)) {
            e.preventDefault();
            return false;
        }
    }, true);
})();

/* ============================================================
   Toast
============================================================ */
let appToastTimer = null;
function showAppToast(text, type = 'success') {
    const toast = document.getElementById('appToast');
    if (!toast) return;
    document.getElementById('appToastText').textContent = text;
    const icon = toast.querySelector('i');
    toast.classList.remove('toast-success', 'toast-error', 'toast-info');
    if (type === 'success') { icon.className = 'fas fa-check-circle'; toast.classList.add('toast-success'); }
    else if (type === 'error') { icon.className = 'fas fa-exclamation-circle'; toast.classList.add('toast-error'); }
    else { icon.className = 'fas fa-info-circle'; toast.classList.add('toast-info'); }
    toast.classList.add('active');
    clearTimeout(appToastTimer);
    appToastTimer = setTimeout(() => toast.classList.remove('active'), 2500);
}

/* ============================================================
   ⭐ Persian Date Picker Helpers
============================================================ */
function setPersianDateInput(displayId, hiddenId, gregorian, wrapId) {
    const display = document.getElementById(displayId);
    const hidden = document.getElementById(hiddenId);
    const wrap = document.getElementById(wrapId);
    if (!display || !hidden) return;

    if (!gregorian) {
        display.value = '';
        hidden.value = '';
        if (wrap) wrap.classList.remove('has-value');
        return;
    }

    try {
        const parts = gregorian.split('-');
        if (parts.length !== 3) throw new Error('bad format');
        const d = new Date(parseInt(parts[0], 10), parseInt(parts[1], 10) - 1, parseInt(parts[2], 10), 12, 0, 0);
        const pd = new persianDate(d);
        display.value = pd.format('YYYY/MM/DD');
        hidden.value = gregorian;
        if (wrap) wrap.classList.add('has-value');
        if (window.jQuery) {
            try { window.jQuery(display).trigger('change'); } catch (e) {}
        }
    } catch (e) {
        console.error('setPersianDateInput error:', e);
        display.value = '';
        hidden.value = '';
        if (wrap) wrap.classList.remove('has-value');
    }
}

function clearPersianDate(prefix) {
    setPersianDateInput(
        prefix + '_due_date_display',
        prefix + '_due_date',
        '',
        prefix + '_due_date_wrap'
    );
}

function initPersianDatePickers() {
    if (!window.jQuery || !window.jQuery.fn || !window.jQuery.fn.persianDatepicker) {
        console.warn('persian-datepicker not loaded');
        return;
    }
    if (!window.persianDate) {
        console.warn('persian-date not loaded');
        return;
    }

    const baseOptions = {
        format: 'YYYY/MM/DD',
        initialValue: false,
        autoClose: true,
        observer: true,
        persianDigit: false,
        toolbox: {
            calendarSwitch: {
                enabled: false
            },
            todayButton: {
                enabled: true,
                text: { fa: 'امروز' }
            },
            closeButton: {
                enabled: true,
                text: { fa: 'بستن' }
            }
        },
        navigator: {
            scroll: { enabled: false }
        },
        timePicker: { enabled: false },
        onSelect: function(unix) {
            try {
                const d = new Date(unix);
                const y = d.getFullYear();
                const m = String(d.getMonth() + 1).padStart(2, '0');
                const day = String(d.getDate()).padStart(2, '0');
                const greg = y + '-' + m + '-' + day;
                const $input = window.jQuery(this.$el || []);
                // برای اطمینان، مقادیر را دوباره ست می‌کنیم
                const displayEl = document.getElementById(this.inputElement ? this.inputElement.id : '');
                // persian-datepicker خودش altField را پر می‌کند
            } catch (e) { console.error('onSelect err:', e); }
        }
    };

    // پیکر ایجاد
    window.jQuery('#create_due_date_display').persianDatepicker(
        Object.assign({}, baseOptions, {
            altField: '#create_due_date',
            altFieldFormatter: function(unixDate) {
                const d = new Date(unixDate);
                const y = d.getFullYear();
                const m = String(d.getMonth() + 1).padStart(2, '0');
                const day = String(d.getDate()).padStart(2, '0');
                return y + '-' + m + '-' + day;
            },
            onSelect: function(unixDate) {
                setTimeout(function() {
                    const displayEl = document.getElementById('create_due_date_display');
                    const wrapEl = document.getElementById('create_due_date_wrap');
                    if (displayEl && displayEl.value) {
                        if (wrapEl) wrapEl.classList.add('has-value');
                    }
                }, 20);
            },
            onClear: function() {
                const hidden = document.getElementById('create_due_date');
                if (hidden) hidden.value = '';
                const wrapEl = document.getElementById('create_due_date_wrap');
                if (wrapEl) wrapEl.classList.remove('has-value');
            }
        })
    );

    // پیکر ویرایش
    window.jQuery('#edit_due_date_display').persianDatepicker(
        Object.assign({}, baseOptions, {
            altField: '#edit_due_date',
            altFieldFormatter: function(unixDate) {
                const d = new Date(unixDate);
                const y = d.getFullYear();
                const m = String(d.getMonth() + 1).padStart(2, '0');
                const day = String(d.getDate()).padStart(2, '0');
                return y + '-' + m + '-' + day;
            },
            onSelect: function(unixDate) {
                setTimeout(function() {
                    const displayEl = document.getElementById('edit_due_date_display');
                    const wrapEl = document.getElementById('edit_due_date_wrap');
                    if (displayEl && displayEl.value) {
                        if (wrapEl) wrapEl.classList.add('has-value');
                    }
                }, 20);
            },
            onClear: function() {
                const hidden = document.getElementById('edit_due_date');
                if (hidden) hidden.value = '';
                const wrapEl = document.getElementById('edit_due_date_wrap');
                if (wrapEl) wrapEl.classList.remove('has-value');
            }
        })
    );
}

/* ============================================================
   Assignee Picker
============================================================ */
function getLinkedProjectId(pickerId) {
    if (pickerId === 'createAssigneePicker') {
        const el = document.getElementById('createProjectInput');
        return el ? (el.value || '') : '';
    }
    if (pickerId === 'editAssigneePicker') {
        const el = document.getElementById('editProjectInput');
        return el ? (el.value || '') : '';
    }
    return '';
}

function getAssigneeOptionsForProject(projectId) {
    const pidStr = String(projectId || '');
    if (pidStr !== '' && PROJECTS_MEMBERS && PROJECTS_MEMBERS[pidStr] && PROJECTS_MEMBERS[pidStr].length > 0) {
        const list = PROJECTS_MEMBERS[pidStr];
        const projectTitle = (PROJECT_OPTIONS || []).find(p => String(p.id) === pidStr)?.title || '';
        return {
            mode: 'project',
            self: list.find(p => p.is_self) || null,
            others: list.filter(p => !p.is_self),
            projectTitle: projectTitle
        };
    }
    return {
        mode: 'colleagues',
        self: ASSIGNEE_OPTIONS.self,
        others: ASSIGNEE_OPTIONS.colleagues || [],
        projectTitle: ''
    };
}

function renderAssigneeDropdown(pickerId, selectedId) {
    const picker = document.getElementById(pickerId);
    if (!picker) return;
    const dropdown = picker.querySelector('.assignee-picker__dropdown');
    const projectId = getLinkedProjectId(pickerId);
    const options = getAssigneeOptionsForProject(projectId);

    let html = '';
    if (options.mode === 'project') {
        const titleText = options.projectTitle ? ` «${escapeHtml(options.projectTitle)}»` : '';
        html += `<div class="assignee-picker__section-label">
            <i class="fas fa-diagram-project" style="margin-left: 4px; color: #4c8bf5;"></i>
            اعضای پروژه${titleText}
        </div>`;
        if (options.self) html += renderAssigneeOption(options.self, selectedId);
        options.others.forEach(c => { html += renderAssigneeOption(c, selectedId); });
        if (!options.self && options.others.length === 0) {
            html += `<div class="assignee-picker__empty">این پروژه هیچ عضوی ندارد.</div>`;
        }
    } else {
        html += `<div class="assignee-picker__section-label">خودم</div>`;
        html += renderAssigneeOption(options.self, selectedId);
        if (options.others.length > 0) {
            html += `<div class="assignee-picker__section-label">همکاران</div>`;
            options.others.forEach(c => { html += renderAssigneeOption(c, selectedId); });
        } else {
            html += `<div class="assignee-picker__section-label">همکاران</div>`;
            html += `<div class="assignee-picker__empty">هنوز همکاری اضافه نکرده‌اید.<br><a href="../colleagues/index.php"><i class="fas fa-user-plus"></i> افزودن همکار</a></div>`;
        }
    }
    dropdown.innerHTML = html;
    dropdown.querySelectorAll('.assignee-picker__option').forEach(opt => {
        opt.addEventListener('click', function(e) {
            e.stopPropagation();
            selectAssignee(pickerId, this.getAttribute('data-id'));
        });
    });
}

function renderAssigneeOption(person, selectedId) {
    const isSelected = String(person.id) === String(selectedId);
    const avatarClass = (person.is_self ? 'is-self' : '') + (person.is_creator ? ' is-creator' : '');
    const selectedClass = isSelected ? 'selected' : '';
    let avatarContent = person.avatar_url
        ? `<img src="${escapeHtml(person.avatar_url)}" alt="">`
        : escapeHtml(person.initial || '?');
    const crown = person.is_creator ? '<i class="fas fa-crown creator-crown" title="سازنده پروژه"></i>' : '';
    return `
        <div class="assignee-picker__option ${selectedClass}" data-id="${person.id}">
            <div class="assignee-picker__option-avatar ${avatarClass}">${avatarContent}</div>
            <div class="assignee-picker__option-info">
                <div class="assignee-picker__option-name">${escapeHtml(person.name)}${crown}</div>
                ${person.mobile ? `<div class="assignee-picker__option-mobile">${escapeHtml(person.mobile)}</div>` : ''}
            </div>
        </div>
    `;
}

function selectAssignee(pickerId, id) {
    const picker = document.getElementById(pickerId);
    if (!picker) return;
    const input = picker.querySelector('input[type="hidden"]');
    const avatarEl = picker.querySelector('.assignee-picker__avatar');
    const nameEl = picker.querySelector('.assignee-picker__name');
    const mobileEl = picker.querySelector('.assignee-picker__mobile');

    let person = null;
    if (String(ASSIGNEE_OPTIONS.self.id) === String(id)) person = ASSIGNEE_OPTIONS.self;
    if (!person) person = (ASSIGNEE_OPTIONS.colleagues || []).find(c => String(c.id) === String(id));
    if (!person && PROJECTS_MEMBERS) {
        for (const pid in PROJECTS_MEMBERS) {
            const found = PROJECTS_MEMBERS[pid].find(m => String(m.id) === String(id));
            if (found) {
                person = {
                    id: found.id,
                    name: found.is_self ? 'خودم' : found.name,
                    mobile: found.mobile,
                    initial: found.initial,
                    avatar_url: found.avatar_url,
                    is_self: found.is_self,
                    is_creator: found.is_creator,
                };
                break;
            }
        }
    }
    if (!person) return;
    input.value = person.id;
    if (person.avatar_url) {
        avatarEl.innerHTML = `<img src="${escapeHtml(person.avatar_url)}" alt="">`;
    } else {
        avatarEl.textContent = person.initial || '?';
    }
    avatarEl.className = 'assignee-picker__avatar' + (person.is_self ? ' is-self' : '');
    nameEl.textContent = person.name;
    mobileEl.textContent = person.mobile || '';
    picker.classList.remove('open');
    renderAssigneeDropdown(pickerId, person.id);
}

function toggleAssigneePicker(pickerId) {
    const picker = document.getElementById(pickerId);
    if (!picker) return;
    if (picker.classList.contains('is-disabled')) return;
    const wasOpen = picker.classList.contains('open');
    document.querySelectorAll('.assignee-picker.open, .project-picker.open').forEach(p => p.classList.remove('open'));
    if (!wasOpen) {
        const currentVal = picker.querySelector('input[type="hidden"]').value;
        renderAssigneeDropdown(pickerId, currentVal);
        picker.classList.add('open');
    }
}

/* ============================================================
   Project Picker
============================================================ */
function renderProjectDropdown(pickerId, selectedId) {
    const picker = document.getElementById(pickerId);
    if (!picker) return;
    const dropdown = picker.querySelector('.project-picker__dropdown');
    const projects = PROJECT_OPTIONS || [];
    let html = `<div class="project-picker__section-label">بدون پروژه</div>`;
    html += renderProjectOption({ id: '', title: 'بدون پروژه', initial: '', image_url: null, is_empty: true }, selectedId, true);
    if (projects.length > 0) {
        html += `<div class="project-picker__section-label">پروژه‌های من</div>`;
        projects.forEach(p => { html += renderProjectOption(p, selectedId, false); });
    } else {
        html += `<div class="project-picker__section-label">پروژه‌های من</div>`;
        html += `<div class="project-picker__empty">هنوز پروژه‌ای نساخته‌اید.<br><a href="../project/index.php"><i class="fas fa-plus"></i> ساخت پروژه جدید</a></div>`;
    }
    dropdown.innerHTML = html;
    dropdown.querySelectorAll('.project-picker__option').forEach(opt => {
        opt.addEventListener('click', function(e) {
            e.stopPropagation();
            selectProject(pickerId, this.getAttribute('data-id'));
        });
    });
}

function renderProjectOption(project, selectedId, isEmpty) {
    const currentId = (selectedId === null || selectedId === undefined || selectedId === '') ? '' : String(selectedId);
    const isSelected = (isEmpty ? '' : String(project.id)) === currentId;
    const selectedClass = isSelected ? 'selected' : '';
    let avatarContent, avatarClass = '';
    if (isEmpty) {
        avatarContent = '<i class="fas fa-folder-open"></i>';
        avatarClass = 'is-empty';
    } else if (project.image_url) {
        avatarContent = `<img src="${escapeHtml(project.image_url)}" alt="">`;
    } else {
        avatarContent = escapeHtml(project.initial || '?');
    }
    return `
        <div class="project-picker__option ${selectedClass}" data-id="${isEmpty ? '' : project.id}">
            <div class="project-picker__option-avatar ${avatarClass}">${avatarContent}</div>
            <div class="project-picker__option-info">
                <div class="project-picker__option-name">${escapeHtml(project.title)}</div>
                ${!isEmpty && project.is_creator ? `<div class="project-picker__option-mobile">سازنده</div>` : ''}
            </div>
        </div>
    `;
}

function selectProject(pickerId, id, options = {}) {
    const { skipAssigneeReset = false } = options;
    const picker = document.getElementById(pickerId);
    if (!picker) return;
    const input = picker.querySelector('input[type="hidden"]');
    const avatarEl = picker.querySelector('.project-picker__avatar');
    const nameEl = picker.querySelector('.project-picker__name');
    const metaEl = picker.querySelector('.project-picker__mobile');

    if (id === '' || id === null) {
        input.value = '';
        avatarEl.innerHTML = '<i class="fas fa-folder-open"></i>';
        avatarEl.className = 'project-picker__avatar is-empty';
        nameEl.textContent = 'بدون پروژه';
        metaEl.textContent = '—';
        picker.classList.remove('open');
        renderProjectDropdown(pickerId, '');
        if (!skipAssigneeReset) {
            if (pickerId === 'createProjectPicker') resetAssigneeForProject('createAssigneePicker');
            else if (pickerId === 'editProjectPicker') resetAssigneeForProject('editAssigneePicker');
        }
        return;
    }
    const project = (PROJECT_OPTIONS || []).find(p => String(p.id) === String(id));
    if (!project) return;
    input.value = project.id;
    if (project.image_url) {
        avatarEl.innerHTML = `<img src="${escapeHtml(project.image_url)}" alt="">`;
    } else {
        avatarEl.textContent = project.initial || '?';
    }
    avatarEl.className = 'project-picker__avatar';
    nameEl.textContent = project.title;
    metaEl.textContent = project.is_creator ? 'سازنده پروژه' : 'عضو پروژه';
    picker.classList.remove('open');
    renderProjectDropdown(pickerId, project.id);
    if (!skipAssigneeReset) {
        if (pickerId === 'createProjectPicker') resetAssigneeForProject('createAssigneePicker');
        else if (pickerId === 'editProjectPicker') resetAssigneeForProject('editAssigneePicker');
    }
}

function resetAssigneeForProject(assigneePickerId) {
    const projectId = getLinkedProjectId(assigneePickerId);
    const options = getAssigneeOptionsForProject(projectId);
    const picker = document.getElementById(assigneePickerId);
    if (!picker) return;
    let newAssigneeId = null;
    if (options.self && String(options.self.id) === String(CURRENT_USER_ID)) {
        newAssigneeId = CURRENT_USER_ID;
    } else if (options.self) {
        newAssigneeId = options.self.id;
    } else if (options.others.length > 0) {
        newAssigneeId = options.others[0].id;
    } else {
        newAssigneeId = CURRENT_USER_ID;
    }
    selectAssignee(assigneePickerId, newAssigneeId);
}

function toggleProjectPicker(pickerId) {
    const picker = document.getElementById(pickerId);
    if (!picker) return;
    if (picker.classList.contains('is-disabled')) return;
    const wasOpen = picker.classList.contains('open');
    document.querySelectorAll('.assignee-picker.open, .project-picker.open').forEach(p => p.classList.remove('open'));
    if (!wasOpen) {
        const currentVal = picker.querySelector('input[type="hidden"]').value;
        renderProjectDropdown(pickerId, currentVal);
        picker.classList.add('open');
    }
}

document.addEventListener('click', function(e) {
    if (!e.target.closest('.assignee-picker')) {
        document.querySelectorAll('.assignee-picker.open').forEach(p => p.classList.remove('open'));
    }
    if (!e.target.closest('.project-picker')) {
        document.querySelectorAll('.project-picker.open').forEach(p => p.classList.remove('open'));
    }
    const mp = document.getElementById('modalNoteMentionPicker');
    if (mp && mp.style.display !== 'none') {
        if (!e.target.closest('#modalNoteMentionPicker') && !e.target.closest('#modalNoteMentionBtn')) {
            mp.style.display = 'none';
        }
    }
});

/* ============================================================
   Main Functions
============================================================ */

function switchMainTab(tabName, btn) {
    document.querySelectorAll('.main-tab-content').forEach(el => el.classList.remove('active'));
    document.querySelectorAll('.main-tab-btn').forEach(el => el.classList.remove('active'));
    const target = document.getElementById('main-tab-' + tabName);
    if (target) target.classList.add('active');
    btn.classList.add('active');
}

function switchTab(tabName, btn) {
    const mainContent = btn.closest('.main-tab-content');
    if (mainContent) {
        mainContent.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
        mainContent.querySelectorAll('.tab-btn').forEach(el => el.classList.remove('active'));
        const target = mainContent.querySelector('#tab-' + tabName);
        if (target) target.classList.add('active');
    } else {
        document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
        document.querySelectorAll('.tab-btn').forEach(el => el.classList.remove('active'));
        const target = document.getElementById('tab-' + tabName);
        if (target) target.classList.add('active');
    }
    btn.classList.add('active');
}

function toggleTask(id) {
    const row = document.getElementById('task-row-' + id);
    if (row) row.classList.add('animating');
    const formData = new FormData();
    formData.append('toggle', id);
    formData.append('csrf_token', CSRF_TOKEN);
    fetch('index.php?page=list', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: formData
    })
    .then(response => response.text())
    .then(text => {
        try { return JSON.parse(text); }
        catch (e) { console.error('❌ toggle raw:', text); throw new Error('پاسخ نامعتبر'); }
    })
    .then(data => {
        if (data.status === 'error') {
            alert(data.message || 'شما دسترسی تکمیل این تسک را ندارید.');
            if (row) row.classList.remove('animating');
            return;
        }
        setTimeout(() => { window.location.reload(); }, 200);
    })
    .catch(() => { if (row) row.classList.remove('animating'); });
}

function handleTaskRowClick(row) {
    if (row.getAttribute('data-can-open') !== '1') return;
    try {
        const task = JSON.parse(row.getAttribute('data-task'));
        const canEdit = row.getAttribute('data-can-edit') === '1';
        const canReassign = row.getAttribute('data-can-reassign') === '1';
        const canChangeProject = row.getAttribute('data-can-change-project') === '1';
        const canComplete = row.getAttribute('data-can-complete') === '1';
        openEditModal(task, canEdit, canReassign, canChangeProject, canComplete);
    } catch (e) {
        console.error('خطا در parse تسک:', e);
    }
}

function openInfoModalFromRow(taskId) {
    const row = document.getElementById('task-row-' + taskId);
    if (!row) return;
    try {
        const task = JSON.parse(row.getAttribute('data-task'));
        openInfoModal(task);
    } catch (e) {
        console.error('خطا در parse اطلاعات تسک:', e);
    }
}

function openInfoModal(task) {
    document.getElementById('info_task_title').textContent = task.title || '—';

    const projectCell = document.getElementById('info_project');
    if (task.project_title) {
        let thumbContent;
        if (task.project_image_url) {
            thumbContent = `<img src="${escapeHtml(task.project_image_url)}" alt="">`;
        } else {
            thumbContent = escapeHtml(task.project_initial || '?');
        }
        projectCell.innerHTML = `
            <span class="info-project-cell">
                <span class="info-project-thumb">${thumbContent}</span>
                <span>${escapeHtml(task.project_title)}</span>
            </span>
        `;
    } else {
        projectCell.innerHTML = `
            <span class="info-project-cell">
                <span class="info-project-thumb is-empty"><i class="fas fa-folder-open"></i></span>
                <span style="color: var(--text-soft); font-weight: 500;">بدون پروژه</span>
            </span>
        `;
    }

    const subjectCell = document.getElementById('info_subject');
    if (task.subject_title) {
        subjectCell.innerHTML = `<span class="info-subject-badge"><i class="fas fa-tag"></i>${escapeHtml(task.subject_title)}</span>`;
    } else {
        subjectCell.innerHTML = `<span style="color: var(--text-soft); font-weight: 500;">بدون موضوع</span>`;
    }

    const priorityLabel = task.priority_label || task.priority || '—';
    const priorityKey = task.priority || 'medium';
    document.getElementById('info_priority').innerHTML =
        `<span class="info-priority-badge ${escapeHtml(priorityKey)}">${escapeHtml(priorityLabel)}</span>`;

    document.getElementById('info_assignee').textContent = task.assignee_name || '—';
    document.getElementById('info_creator').textContent = task.creator_name || '—';

    const dueEl = document.getElementById('info_due');
    if (task.due_date) {
        if (task.is_overdue) {
            dueEl.innerHTML = `<span style="color: #c81e4a; font-weight: 700;">${escapeHtml(task.due_date)} <span class="overdue-badge"><i class="fas fa-exclamation-triangle"></i> تاخیر</span></span>`;
        } else {
            dueEl.textContent = task.due_date;
        }
    } else {
        dueEl.textContent = 'بدون مهلت';
    }

    const attCount = parseInt(task.attachments_count || 0, 10);
    const attEl = document.getElementById('info_attachments');
    if (attEl) {
        attEl.innerHTML = attCount > 0
            ? `<span class="info-subject-badge"><i class="fas fa-paperclip"></i> ${toPersianDigits(attCount)} فایل</span>`
            : `<span style="color: var(--text-soft); font-weight: 500;">بدون فایل</span>`;
    }

    openModal('taskInfoModal');
}

function openModal(id) {
    document.getElementById(id).classList.add('active');
    document.body.classList.add('modal-open');
    document.body.style.overflow = 'hidden';

    if (id === 'createTaskModal') {
        const input = document.getElementById('createAssigneeInput');
        input.value = CURRENT_USER_ID;
        const createAvatarEl = document.getElementById('createAssigneeAvatar');
        if (ASSIGNEE_OPTIONS.self.avatar_url) {
            createAvatarEl.innerHTML = `<img src="${escapeHtml(ASSIGNEE_OPTIONS.self.avatar_url)}" alt="من">`;
        } else {
            createAvatarEl.textContent = ASSIGNEE_OPTIONS.self.initial || '?';
        }
        createAvatarEl.className = 'assignee-picker__avatar is-self';
        document.getElementById('createAssigneeName').textContent = 'خودم';
        document.getElementById('createAssigneeMobile').textContent = ASSIGNEE_OPTIONS.self.mobile || '';
        document.getElementById('createProjectInput').value = '';
        const createProjectAvatar = document.getElementById('createProjectAvatar');
        createProjectAvatar.innerHTML = '<i class="fas fa-folder-open"></i>';
        createProjectAvatar.className = 'project-picker__avatar is-empty';
        document.getElementById('createProjectName').textContent = 'بدون پروژه';
        document.getElementById('createProjectMeta').textContent = '—';
        document.getElementById('createProjectPicker').classList.remove('is-disabled');
        document.getElementById('createAssigneePicker').classList.remove('is-disabled');
        renderAssigneeDropdown('createAssigneePicker', CURRENT_USER_ID);

        // ⭐ ریست تاریخ
        setPersianDateInput('create_due_date_display', 'create_due_date', '', 'create_due_date_wrap');

        const cf = document.getElementById('createFileInput');
        if (cf) cf.value = '';
        renderFilePreview('createFileInput', 'createFilePreviewList');
    }
}

function closeModal(id) {
    const el = document.getElementById(id);
    if (!el) return;
    el.classList.remove('active');
    document.body.classList.remove('modal-open');
    document.body.style.overflow = '';
}

let editFormInitialState = null;

function captureEditFormState() {
    const state = {};
    state.title = (document.getElementById('edit_task_title') || {}).value || '';
    state.desc = (document.getElementById('edit_task_desc') || {}).value || '';
    state.subject = (document.getElementById('edit_subject_id') || {}).value || '';
    const pr = document.querySelector('#editTaskModal input[name="priority"]:checked');
    state.priority = pr ? pr.value : '';
    state.project = (document.getElementById('editProjectInput') || {}).value || '';
    state.assignee = (document.getElementById('editAssigneeInput') || {}).value || '';
    state.due = (document.getElementById('edit_due_date') || {}).value || '';
    return JSON.stringify(state);
}

function hasEditFormChanged() {
    if (!editFormInitialState) return false;
    return captureEditFormState() !== editFormInitialState;
}

function requestCloseEditModal() {
    if (hasEditFormChanged()) {
        const saveBtn = document.getElementById('edit_submit_btn');
        const showSave = saveBtn && saveBtn.style.display !== 'none';
        const saveBtnInConfirm = document.getElementById('confirmCloseSave');
        if (saveBtnInConfirm) saveBtnInConfirm.style.display = showSave ? '' : 'none';
        openModal('confirmCloseModal');
    } else {
        closeModal('editTaskModal');
    }
}

function forceCloseEditModal() {
    closeModal('confirmCloseModal');
    closeModal('editTaskModal');
    editFormInitialState = null;
}

function saveAndCloseEditModal() {
    closeModal('confirmCloseModal');
    const form = document.getElementById('editTaskForm');
    const submitBtn = document.getElementById('edit_submit_btn');
    if (submitBtn && submitBtn.style.display !== 'none') {
        submitBtn.click();
    } else {
        closeModal('editTaskModal');
        editFormInitialState = null;
    }
}

function openEditModal(task, canEdit, canReassign, canChangeProject, canComplete) {
    document.getElementById('edit_task_id').value = task.id;
    document.getElementById('edit_id_badge').textContent = '#' + task.id;
    document.getElementById('edit_task_title').value = task.title;
    document.getElementById('edit_subject_id').value = task.subject_id || '';
    document.getElementById('edit_task_desc').value = task.description || '';

    // ⭐ تنظیم تاریخ شمسی در پیکر
    setPersianDateInput(
        'edit_due_date_display',
        'edit_due_date',
        task.due_date_raw || '',
        'edit_due_date_wrap'
    );

    document.querySelectorAll('#editTaskModal input[name="priority"]').forEach(r => {
        r.checked = (r.value === task.priority);
    });

    const editFields = ['edit_task_title', 'edit_subject_id', 'edit_task_desc'];
    editFields.forEach(id => {
        const el = document.getElementById(id);
        if (!el) return;
        if (canEdit) {
            el.disabled = false;
            el.classList.remove('field-locked');
            el.removeAttribute('title');
        } else {
            el.disabled = true;
            el.classList.add('field-locked');
            el.setAttribute('title', 'دسترسی ویرایش این فیلد را ندارید');
        }
    });

    // ⭐ قفل کردن فیلد تقویم در حالت عدم دسترسی ویرایش
    const dueWrap = document.getElementById('edit_due_date_wrap');
    const dueDisplay = document.getElementById('edit_due_date_display');
    if (dueDisplay) {
        if (canEdit) {
            dueDisplay.classList.remove('field-locked');
            dueDisplay.removeAttribute('title');
            if (dueWrap) dueWrap.style.pointerEvents = '';
        } else {
            dueDisplay.classList.add('field-locked');
            dueDisplay.setAttribute('title', 'دسترسی ویرایش این فیلد را ندارید');
            if (dueWrap) dueWrap.style.pointerEvents = 'none';
        }
    }

    const priorityGroup = document.getElementById('edit_priority_group');
    document.querySelectorAll('#editTaskModal input[name="priority"]').forEach(r => {
        r.disabled = !canEdit;
    });
    if (canEdit) {
        priorityGroup.classList.remove('field-locked');
    } else {
        priorityGroup.classList.add('field-locked');
    }

    const projectId = task.project_id ? String(task.project_id) : '';
    selectProject('editProjectPicker', projectId, { skipAssigneeReset: true });
    const targetId = task.assignee_id ? String(task.assignee_id) : String(CURRENT_USER_ID);
    selectAssignee('editAssigneePicker', targetId);

    const editAssigneePicker = document.getElementById('editAssigneePicker');
    if (canReassign) editAssigneePicker.classList.remove('is-disabled');
    else editAssigneePicker.classList.add('is-disabled');

    const editProjectPicker = document.getElementById('editProjectPicker');
    if (canChangeProject) editProjectPicker.classList.remove('is-disabled');
    else editProjectPicker.classList.add('is-disabled');

    const submitBtn = document.getElementById('edit_submit_btn');
    const hasAnyChangeAbility = canEdit || canReassign || canChangeProject;
    if (submitBtn) submitBtn.style.display = hasAnyChangeAbility ? '' : 'none';

    const ef = document.getElementById('editFileInput');
    if (ef) ef.value = '';
    renderFilePreview('editFileInput', 'editFilePreviewList');

    const noteFileInput = document.getElementById('modalNoteFileInput');
    if (noteFileInput) noteFileInput.value = '';
    const noteTxt = document.getElementById('modalNoteText');
    if (noteTxt) noteTxt.value = '';
    renderNoteFilePreview();

    currentMentions = [];
    renderMentionsPreview();
    const mp = document.getElementById('modalNoteMentionPicker');
    if (mp) mp.style.display = 'none';

    loadTaskAttachments(task.id);
    loadModalNotes(task.id);

    openModal('editTaskModal');

    setTimeout(() => {
        editFormInitialState = captureEditFormState();
    }, 80);
}

function copyTaskLink() {
    const taskId = document.getElementById('edit_task_id').value;
    if (!taskId) return;
    const btn = document.getElementById('edit_share_btn');
    if (btn) btn.classList.add('copied');

    fetch('index.php?get_task_token=' + encodeURIComponent(taskId), {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(r => r.text())
    .then(text => {
        let data;
        try { data = JSON.parse(text); }
        catch (e) { console.error('❌ copyTaskLink raw:', text); throw new Error('invalid'); }
        if (data.status !== 'success') {
            showAppToast(data.message || 'خطا در ساخت لینک', 'error');
            if (btn) btn.classList.remove('copied');
            return;
        }
        const fullUrl = new URL(data.url, window.location.href).href;
        const done = () => {
            showAppToast('لینک اشتراکی وظیفه کپی شد', 'success');
            setTimeout(() => { if (btn) btn.classList.remove('copied'); }, 1500);
        };
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(fullUrl).then(done).catch(() => {
                fallbackCopyText(fullUrl);
                done();
            });
        } else {
            fallbackCopyText(fullUrl);
            done();
        }
    })
    .catch(err => {
        console.error('copyTaskLink error:', err);
        showAppToast('خطا در ساخت لینک', 'error');
        if (btn) btn.classList.remove('copied');
    });
}

function fallbackCopyText(text) {
    try {
        const ta = document.createElement('textarea');
        ta.value = text;
        ta.style.cssText = 'position:fixed;top:0;left:0;opacity:0;';
        document.body.appendChild(ta);
        ta.focus();
        ta.select();
        document.execCommand('copy');
        document.body.removeChild(ta);
    } catch (e) { console.error('fallback copy error', e); }
}

function fileIconClass(name) {
    const ext = (name.split('.').pop() || '').toLowerCase();
    if (ext === 'pdf') return { cls: 'is-pdf', icon: 'fa-file-pdf' };
    if (['xls','xlsx','csv'].includes(ext)) return { cls: 'is-excel', icon: 'fa-file-excel' };
    if (['doc','docx','rtf','txt'].includes(ext)) return { cls: 'is-word', icon: 'fa-file-word' };
    if (['jpg','jpeg','png','gif','webp','bmp'].includes(ext)) return { cls: 'is-image', icon: 'fa-file-image' };
    if (['zip','rar','7z'].includes(ext)) return { cls: 'is-zip', icon: 'fa-file-zipper' };
    return { cls: '', icon: 'fa-file' };
}

function humanFileSizeJS(bytes) {
    bytes = parseInt(bytes, 10) || 0;
    if (bytes < 1024) return bytes + ' بایت';
    if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' کیلوبایت';
    return (bytes / (1024 * 1024)).toFixed(2) + ' مگابایت';
}

function renderFilePreview(inputId, listId) {
    const input = document.getElementById(inputId);
    const list = document.getElementById(listId);
    if (!input || !list) return;
    list.innerHTML = '';

    const files = Array.from(input.files || []);
    files.forEach((file, idx) => {
        const { cls, icon } = fileIconClass(file.name);
        const li = document.createElement('li');
        li.className = 'file-preview-item';
        li.innerHTML = `
            <div class="file-preview-item__icon ${cls}"><i class="fas ${icon}"></i></div>
            <div class="file-preview-item__info">
                <div class="file-preview-item__name" title="${escapeHtml(file.name)}">${escapeHtml(file.name)}</div>
                <div class="file-preview-item__meta">${humanFileSizeJS(file.size)}</div>
            </div>
            <button type="button" class="file-preview-item__remove" title="حذف" data-idx="${idx}">
                <i class="fas fa-times"></i>
            </button>
        `;
        list.appendChild(li);
    });

    list.querySelectorAll('.file-preview-item__remove').forEach(btn => {
        btn.addEventListener('click', function() {
            const idx = parseInt(this.getAttribute('data-idx'), 10);
            removeFileFromInput(inputId, idx);
        });
    });
}

function removeFileFromInput(inputId, idxToRemove) {
    const input = document.getElementById(inputId);
    if (!input) return;
    const dt = new DataTransfer();
    const files = Array.from(input.files || []);
    files.forEach((f, i) => {
        if (i !== idxToRemove) dt.items.add(f);
    });
    input.files = dt.files;
    const listId = (inputId === 'createFileInput') ? 'createFilePreviewList' : 'editFilePreviewList';
    renderFilePreview(inputId, listId);
}

document.addEventListener('DOMContentLoaded', function() {
    // ⭐ راه‌اندازی تقویم‌های شمسی
    initPersianDatePickers();

    const cfi = document.getElementById('createFileInput');
    if (cfi) cfi.addEventListener('change', () => renderFilePreview('createFileInput', 'createFilePreviewList'));
    const efi = document.getElementById('editFileInput');
    if (efi) efi.addEventListener('change', () => renderFilePreview('editFileInput', 'editFilePreviewList'));

    setupDropZone('createFileDropZone', 'createFileInput', 'createFilePreviewList');
    setupDropZone('editFileDropZone', 'editFileInput', 'editFilePreviewList');

    const noteFileInput = document.getElementById('modalNoteFileInput');
    if (noteFileInput) noteFileInput.addEventListener('change', renderNoteFilePreview);

    const noteTextarea = document.getElementById('modalNoteText');
    if (noteTextarea) {
        noteTextarea.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                e.stopPropagation();
                submitModalNote();
            }
        });
    }

    <?php if ($sharedTaskData): ?>
    (function tryOpenSharedTask() {
        let attempts = 0;
        const maxAttempts = 30;
        function attempt() {
            attempts++;
            const editModal = document.getElementById('editTaskModal');
            const editTaskId = document.getElementById('edit_task_id');
            if (editModal && editTaskId && typeof openEditModal === 'function') {
                try {
                    openEditModal(
                        SHARED_TASK_DATA,
                        SHARED_TASK_PERMS.edit,
                        SHARED_TASK_PERMS.reassign,
                        SHARED_TASK_PERMS.change_project,
                        SHARED_TASK_PERMS.complete
                    );
                } catch (e) {
                    console.error('auto-open shared task error:', e);
                }
                return;
            }
            if (attempts < maxAttempts) setTimeout(attempt, 100);
        }
        setTimeout(attempt, 300);
    })();
    <?php endif; ?>
});

function setupDropZone(zoneId, inputId, listId) {
    const zone = document.getElementById(zoneId);
    const input = document.getElementById(inputId);
    if (!zone || !input) return;

    ['dragenter','dragover'].forEach(ev => {
        zone.addEventListener(ev, e => {
            e.preventDefault();
            e.stopPropagation();
            zone.classList.add('dragover');
        });
    });
    ['dragleave','drop'].forEach(ev => {
        zone.addEventListener(ev, e => {
            e.preventDefault();
            e.stopPropagation();
            zone.classList.remove('dragover');
        });
    });
    zone.addEventListener('drop', e => {
        const dt = e.dataTransfer;
        if (!dt || !dt.files || !dt.files.length) return;
        const newDt = new DataTransfer();
        Array.from(input.files || []).forEach(f => newDt.items.add(f));
        Array.from(dt.files).forEach(f => newDt.items.add(f));
        input.files = newDt.files;
        renderFilePreview(inputId, listId);
    });
}

function loadTaskAttachments(taskId) {
    const container = document.getElementById('modalAttachmentsList');
    const countEl = document.getElementById('modalAttachmentsCount');
    if (!container) return;

    container.innerHTML = '<div class="modal-attachments-loading">در حال بارگذاری فایل‌ها...</div>';

    fetch('index.php?get_attachments=1&task_id=' + encodeURIComponent(taskId), {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(r => r.text())
    .then(text => {
        let data;
        try { data = JSON.parse(text); }
        catch (e) { console.error('❌ loadTaskAttachments raw:', text); throw new Error('invalid'); }
        if (data.status !== 'success') {
            container.innerHTML = `<div class="modal-attachments-empty" style="color: var(--danger);">${escapeHtml(data.message || 'خطا در بارگذاری')}</div>`;
            return;
        }
        const atts = data.attachments || [];
        if (countEl) countEl.textContent = toPersianDigits(atts.length);

        if (atts.length === 0) {
            container.innerHTML = '<div class="modal-attachments-empty">هنوز فایلی برای این وظیفه بارگذاری نشده است.</div>';
            return;
        }

        let html = '';
        atts.forEach(att => {
            const iconClass = att.icon || 'fa-file';
            let cssCls = '';
            if (iconClass.includes('pdf')) cssCls = 'is-pdf';
            else if (iconClass.includes('excel')) cssCls = 'is-excel';
            else if (iconClass.includes('word')) cssCls = 'is-word';
            else if (iconClass.includes('image')) cssCls = 'is-image';
            else if (iconClass.includes('zipper')) cssCls = 'is-zip';

            html += `
                <div class="modal-attachment-item" data-att-id="${att.id}">
                    <div class="modal-attachment-item__icon ${cssCls}">
                        <i class="fas ${escapeHtml(iconClass)}"></i>
                    </div>
                    <div class="modal-attachment-item__body">
                        <div class="modal-attachment-item__name" title="${escapeHtml(att.file_name)}">
                            <a href="${escapeHtml(att.url)}" target="_blank" rel="noopener" download>${escapeHtml(att.file_name)}</a>
                        </div>
                        <div class="modal-attachment-item__meta">
                            <span><i class="fas fa-hdd"></i> ${escapeHtml(att.file_size_human || '')}</span>
                            <span><i class="fas fa-clock"></i> ${escapeHtml(att.persian_date || '')}</span>
                        </div>
                    </div>
                    <div class="modal-attachment-item__actions">
                        <a class="att-download" href="${escapeHtml(att.url)}" target="_blank" rel="noopener" download title="دانلود">
                            <i class="fas fa-download"></i>
                        </a>
                        <button type="button" class="att-delete" title="حذف" onclick="deleteTaskAttachment(${att.id}, ${taskId})">
                            <i class="fas fa-trash-alt"></i>
                        </button>
                    </div>
                </div>
            `;
        });
        container.innerHTML = html;
    })
    .catch(err => {
        console.error('loadTaskAttachments error:', err);
        container.innerHTML = '<div class="modal-attachments-empty" style="color: var(--danger);">خطا در بارگذاری فایل‌ها</div>';
    });
}

function deleteTaskAttachment(attId, taskId) {
    if (!confirm('این فایل برای همیشه حذف شود؟')) return;

    const formData = new FormData();
    formData.append('delete_attachment', '1');
    formData.append('attachment_id', attId);
    formData.append('csrf_token', CSRF_TOKEN);

    fetch('index.php', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: formData
    })
    .then(r => r.text())
    .then(text => {
        let data;
        try { data = JSON.parse(text); }
        catch (e) { console.error('❌ deleteTaskAttachment raw:', text); throw new Error('invalid'); }
        if (data.status === 'success') {
            loadTaskAttachments(taskId);
        } else {
            alert(data.message || 'خطا در حذف فایل');
        }
    })
    .catch(err => {
        console.error('deleteTaskAttachment error:', err);
        alert('خطا در حذف فایل');
    });
}

function renderNoteFilePreview() {
    const input = document.getElementById('modalNoteFileInput');
    const preview = document.getElementById('modalNoteFilesPreview');
    const attachBtn = document.querySelector('label.modal-note-attach-btn');
    if (!input || !preview) return;

    preview.innerHTML = '';
    const files = Array.from(input.files || []);

    if (attachBtn) {
        if (files.length > 0) attachBtn.classList.add('has-files');
        else attachBtn.classList.remove('has-files');
    }

    files.forEach((file, idx) => {
        const { cls, icon } = fileIconClass(file.name);
        const item = document.createElement('div');
        item.className = 'modal-note-file-item';
        item.innerHTML = `
            <div class="modal-note-file-item__icon ${cls}"><i class="fas ${icon}"></i></div>
            <div class="modal-note-file-item__info">
                <div class="modal-note-file-item__name" title="${escapeHtml(file.name)}">${escapeHtml(file.name)}</div>
                <div class="modal-note-file-item__meta">${humanFileSizeJS(file.size)}</div>
            </div>
            <button type="button" class="modal-note-file-item__remove" data-idx="${idx}" title="حذف">
                <i class="fas fa-times"></i>
            </button>
        `;
        preview.appendChild(item);
    });

    preview.querySelectorAll('.modal-note-file-item__remove').forEach(btn => {
        btn.addEventListener('click', function() {
            const idx = parseInt(this.getAttribute('data-idx'), 10);
            const dt = new DataTransfer();
            Array.from(input.files || []).forEach((f, i) => {
                if (i !== idx) dt.items.add(f);
            });
            input.files = dt.files;
            renderNoteFilePreview();
        });
    });
}

let currentMentions = [];

function toggleMentionPicker(e) {
    if (e) { e.stopPropagation(); e.preventDefault(); }
    const picker = document.getElementById('modalNoteMentionPicker');
    if (!picker) return;
    if (picker.style.display === 'none' || !picker.style.display) {
        renderMentionPicker();
        picker.style.display = 'flex';
    } else {
        picker.style.display = 'none';
    }
}

function renderMentionPicker() {
    const list = document.getElementById('mentionPickerList');
    if (!list) return;
    const options = MENTION_OPTIONS || [];
    if (options.length === 0) {
        list.innerHTML = '<div class="mention-picker__empty">هیچ همکاری برای منشن وجود ندارد</div>';
        return;
    }
    let html = '';
    options.forEach(o => {
        const selected = currentMentions.some(m => m.id === o.id);
        const avatar = o.avatar_url ? `<img src="${escapeHtml(o.avatar_url)}" alt="">` : escapeHtml(o.initial || '?');
        html += `
            <div class="mention-picker__item ${selected ? 'selected' : ''}" data-id="${o.id}" data-name="${escapeHtml(o.name)}">
                <div class="mention-picker__avatar">${avatar}</div>
                <div class="mention-picker__name">${escapeHtml(o.name)}</div>
                ${selected ? '<i class="fas fa-check mention-picker__check"></i>' : ''}
            </div>
        `;
    });
    list.innerHTML = html;

    list.querySelectorAll('.mention-picker__item').forEach(el => {
        el.addEventListener('click', function(ev) {
            ev.stopPropagation();
            const id = parseInt(this.getAttribute('data-id'), 10);
            const name = this.getAttribute('data-name');
            toggleMention(id, name);
        });
    });
}

function toggleMention(id, name) {
    const idx = currentMentions.findIndex(m => m.id === id);
    const ta = document.getElementById('modalNoteText');
    if (idx >= 0) {
        currentMentions.splice(idx, 1);
        if (ta) {
            const regex = new RegExp('@' + name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '\\s?', 'g');
            ta.value = ta.value.replace(regex, '');
        }
    } else {
        currentMentions.push({ id, name });
        if (ta) {
            const pos = ta.selectionStart || ta.value.length;
            const before = ta.value.substring(0, pos);
            const after = ta.value.substring(pos);
            const prefix = (before && !before.endsWith(' ') && !before.endsWith('\n')) ? ' ' : '';
            const insert = prefix + '@' + name + ' ';
            ta.value = before + insert + after;
            const newPos = before.length + insert.length;
            try { ta.setSelectionRange(newPos, newPos); } catch (e) {}
            ta.focus();
        }
    }
    renderMentionsPreview();
    renderMentionPicker();
}

function renderMentionsPreview() {
    const preview = document.getElementById('modalNoteMentionsPreview');
    const btn = document.getElementById('modalNoteMentionBtn');
    if (!preview) return;
    preview.innerHTML = '';
    if (currentMentions.length === 0) {
        if (btn) btn.classList.remove('has-mentions');
        return;
    }
    if (btn) btn.classList.add('has-mentions');
    currentMentions.forEach(m => {
        const opt = (MENTION_OPTIONS || []).find(o => o.id === m.id);
        const avatarContent = (opt && opt.avatar_url)
            ? `<img src="${escapeHtml(opt.avatar_url)}" alt="">`
            : escapeHtml((opt && opt.initial) || m.name.charAt(0) || '?');
        const chip = document.createElement('div');
        chip.className = 'mention-chip';
        chip.innerHTML = `
            <span class="mention-chip__avatar">${avatarContent}</span>
            <span>${escapeHtml(m.name)}</span>
            <button type="button" class="mention-chip__remove" title="حذف منشن">
                <i class="fas fa-times"></i>
            </button>
        `;
        chip.querySelector('.mention-chip__remove').addEventListener('click', function(e) {
            e.stopPropagation();
            toggleMention(m.id, m.name);
        });
        preview.appendChild(chip);
    });
}

let notesAddInProgress = false;

function loadModalNotes(taskId) {
    const container = document.getElementById('modalNotesList');
    if (!container) return;
    container.innerHTML = '<p class="modal-notes-empty">در حال بارگذاری گزارش‌ها...</p>';

    fetch('index.php?get_notes=1&task_id=' + encodeURIComponent(taskId), {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(r => r.text())
    .then(text => {
        let data;
        try { data = JSON.parse(text); }
        catch (e) { console.error('❌ loadModalNotes raw:', text); throw new Error('invalid'); }
        if (data.status !== 'success') {
            container.innerHTML = `<p class="modal-notes-empty" style="color: var(--danger);">${escapeHtml(data.message || 'خطا در بارگذاری')}</p>`;
            return;
        }
        const notes = data.notes || [];
        const countEl = document.getElementById('modalNotesCount');
        if (countEl) countEl.textContent = toPersianDigits(notes.length);
        if (notes.length === 0) {
            container.innerHTML = '<p class="modal-notes-empty">هنوز گزارشی برای این وظیفه ثبت نشده است.</p>';
            return;
        }
        let html = '';
        notes.forEach(note => { html += renderModalNote(note); });
        container.innerHTML = html;
    })
    .catch(err => {
        console.error('loadModalNotes error:', err);
        container.innerHTML = '<p class="modal-notes-empty" style="color: var(--danger);">خطا در بارگذاری گزارش‌ها</p>';
    });
}

function renderModalNote(note) {
    const isMine = !!note.is_mine || Number(note.user_id) === CURRENT_USER_ID;
    const authorName = ((note.first_name || '') + ' ' + (note.last_name || '')).trim() || note.mobile || 'کاربر';
    const authorLabel = isMine ? 'من' : authorName;
    const initial = (note.first_name || note.mobile || '?').trim().charAt(0);
    const dateStr = note.persian_date || note.created_at || '';

    let attHtml = '';
    const atts = note.attachments || [];
    if (atts.length > 0) {
        let items = '';
        atts.forEach(a => {
            const iconClass = a.icon || 'fa-file';
            let cssCls = '';
            if (iconClass.includes('pdf')) cssCls = 'is-pdf';
            else if (iconClass.includes('excel')) cssCls = 'is-excel';
            else if (iconClass.includes('word')) cssCls = 'is-word';
            else if (iconClass.includes('image')) cssCls = 'is-image';
            else if (iconClass.includes('zipper')) cssCls = 'is-zip';

            items += `
                <div class="modal-note-attachment">
                    <div class="modal-note-attachment__icon ${cssCls}">
                        <i class="fas ${escapeHtml(iconClass)}"></i>
                    </div>
                    <a class="modal-note-attachment__body" href="${escapeHtml(a.url)}" target="_blank" rel="noopener" download>
                        <div class="modal-note-attachment__name">${escapeHtml(a.file_name)}</div>
                        <div class="modal-note-attachment__meta">${escapeHtml(a.file_size_human || '')}</div>
                    </a>
                    <div class="modal-note-attachment__actions">
                        <a class="att-download" href="${escapeHtml(a.url)}" target="_blank" rel="noopener" download title="دانلود">
                            <i class="fas fa-download"></i>
                        </a>
                        <button type="button" class="att-del" onclick="deleteNoteAttachment(${a.id}, ${note.id})" title="حذف">
                            <i class="fas fa-trash-alt"></i>
                        </button>
                    </div>
                </div>
            `;
        });
        attHtml = `<div class="modal-note-attachments">${items}</div>`;
    }

    let noteTextHtml = escapeHtml(note.note);
    if (MENTION_OPTIONS && MENTION_OPTIONS.length) {
        MENTION_OPTIONS.forEach(opt => {
            const name = opt.name;
            if (!name) return;
            const safe = name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
            const re = new RegExp('@' + safe + '(?=\\s|$)', 'g');
            noteTextHtml = noteTextHtml.replace(re, '<span class="note-mention">@' + escapeHtml(name) + '</span>');
        });
    }

    return `
        <div class="modal-note-item" data-note-id="${note.id}">
            <div class="modal-note-avatar ${isMine ? 'is-mine' : ''}">
                ${escapeHtml(initial)}
            </div>
            <div class="modal-note-body">
                <div class="modal-note-header">
                    <span class="modal-note-author ${isMine ? 'is-mine' : ''}">${escapeHtml(authorLabel)}</span>
                    <span class="modal-note-date">${escapeHtml(dateStr)}</span>
                </div>
                <div class="modal-note-text">${noteTextHtml}</div>
                ${attHtml}
                <div class="modal-note-actions">
                    ${isMine ? `<button type="button" class="modal-note-edit" onclick="startEditNote(${note.id})" title="ویرایش"><i class="fas fa-pen"></i></button>` : ''}
                    <button type="button" class="modal-note-delete" onclick="deleteModalNote(${note.id})" title="حذف"><i class="fas fa-trash-alt"></i></button>
                </div>
            </div>
        </div>
    `;
}

function deleteNoteAttachment(attId, noteId) {
    if (!confirm('این فایل حذف شود؟')) return;

    const formData = new FormData();
    formData.append('delete_note_attachment', '1');
    formData.append('attachment_id', attId);
    formData.append('csrf_token', CSRF_TOKEN);

    fetch('index.php', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: formData
    })
    .then(r => r.text())
    .then(text => {
        let data;
        try { data = JSON.parse(text); }
        catch (e) { console.error('❌ deleteNoteAttachment raw:', text); throw new Error('invalid'); }
        if (data.status === 'success') {
            const taskId = document.getElementById('edit_task_id').value;
            if (taskId) loadModalNotes(taskId);
        } else {
            alert(data.message || 'خطا در حذف فایل');
        }
    })
    .catch(err => {
        console.error('deleteNoteAttachment error:', err);
        alert('خطا در حذف فایل');
    });
}

function submitModalNote() {
    if (notesAddInProgress) return;
    const taskId = document.getElementById('edit_task_id').value;
    const textarea = document.getElementById('modalNoteText');
    const fileInput = document.getElementById('modalNoteFileInput');
    const noteText = (textarea.value || '').trim();
    const hasFiles = fileInput && fileInput.files && fileInput.files.length > 0;
    const hasMentions = currentMentions.length > 0;

    if (!taskId || taskId === '0') {
        alert('ابتدا وظیفه ذخیره شود.');
        return;
    }
    if (!noteText && !hasFiles) {
        alert('متن گزارش یا حداقل یک فایل را وارد کنید.');
        textarea.focus();
        return;
    }
    if (hasMentions && !noteText) {
        alert('برای منشن، متن گزارش را بنویسید.');
        textarea.focus();
        return;
    }

    notesAddInProgress = true;
    const btn = document.getElementById('modalNoteSubmitBtn');
    if (btn) btn.disabled = true;

    const formData = new FormData();
    formData.append('add_task_note', '1');
    formData.append('note_task_id', taskId);
    formData.append('note_text', noteText);
    formData.append('csrf_token', CSRF_TOKEN);

    currentMentions.forEach(m => formData.append('mention_ids[]', m.id));

    if (hasFiles) {
        Array.from(fileInput.files).forEach(f => formData.append('note_attachments[]', f));
    }

    fetch('index.php', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: formData
    })
    .then(r => r.text())
    .then(text => {
        let data;
        try { data = JSON.parse(text); }
        catch (e) { console.error('❌ submitModalNote raw:', text); throw new Error('invalid'); }
        if (data.status === 'success' || data.status === 'warning') {
            textarea.value = '';
            if (fileInput) fileInput.value = '';
            renderNoteFilePreview();
            currentMentions = [];
            renderMentionsPreview();
            const mp = document.getElementById('modalNoteMentionPicker');
            if (mp) mp.style.display = 'none';
            loadModalNotes(taskId);
            showAppToast('گزارش ثبت شد' + (hasMentions ? ' و منشن‌ها اطلاع‌رسانی شدند' : ''), 'success');
            if (data.status === 'warning' && data.message) {
                setTimeout(() => alert(data.message), 300);
            }
        } else {
            alert(data.message || 'خطا در ثبت گزارش');
        }
    })
    .catch(err => {
        console.error('submitModalNote error:', err);
        alert('خطا در ثبت گزارش');
    })
    .finally(() => {
        notesAddInProgress = false;
        if (btn) btn.disabled = false;
    });
}

function startEditNote(noteId) {
    const item = document.querySelector(`.modal-note-item[data-note-id="${noteId}"]`);
    if (!item) return;
    const textEl = item.querySelector('.modal-note-text');
    const actionsEl = item.querySelector('.modal-note-actions');
    if (!textEl || !actionsEl) return;

    const originalText = textEl.textContent || '';

    const textarea = document.createElement('textarea');
    textarea.className = 'modal-note-edit-textarea';
    textarea.value = originalText;
    textEl.replaceWith(textarea);
    textarea.focus();

    actionsEl.innerHTML = `
        <button type="button" class="modal-note-save" onclick="saveEditNote(${noteId})" title="ذخیره"><i class="fas fa-check"></i></button>
        <button type="button" class="modal-note-cancel" onclick="cancelEditNote()" title="لغو"><i class="fas fa-times"></i></button>
    `;
}

function cancelEditNote() {
    const taskId = document.getElementById('edit_task_id').value;
    if (taskId) loadModalNotes(taskId);
}

function saveEditNote(noteId) {
    const item = document.querySelector(`.modal-note-item[data-note-id="${noteId}"]`);
    if (!item) return;
    const textarea = item.querySelector('.modal-note-edit-textarea');
    if (!textarea) return;
    const newText = (textarea.value || '').trim();
    if (!newText) {
        alert('متن گزارش نمی‌تواند خالی باشد.');
        textarea.focus();
        return;
    }

    const formData = new FormData();
    formData.append('edit_note', '1');
    formData.append('note_id', noteId);
    formData.append('note_text', newText);
    formData.append('csrf_token', CSRF_TOKEN);

    fetch('index.php', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: formData
    })
    .then(r => r.text())
    .then(text => {
        let data;
        try { data = JSON.parse(text); }
        catch (e) { console.error('❌ saveEditNote raw:', text); throw new Error('invalid'); }
        if (data.status === 'success') {
            const taskId = document.getElementById('edit_task_id').value;
            if (taskId) loadModalNotes(taskId);
        } else {
            alert(data.message || 'خطا در ویرایش گزارش');
        }
    })
    .catch(err => {
        console.error('saveEditNote error:', err);
        alert('خطا در ویرایش گزارش');
    });
}

function deleteModalNote(noteId) {
    if (!confirm('آیا از حذف این گزارش مطمئن هستید؟')) return;

    const formData = new FormData();
    formData.append('delete_note', '1');
    formData.append('note_id', noteId);
    formData.append('csrf_token', CSRF_TOKEN);

    fetch('index.php', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: formData
    })
    .then(r => r.text())
    .then(text => {
        let data;
        try { data = JSON.parse(text); }
        catch (e) { console.error('❌ deleteModalNote raw:', text); throw new Error('invalid'); }
        if (data.status === 'success') {
            const taskId = document.getElementById('edit_task_id').value;
            if (taskId) loadModalNotes(taskId);
        } else {
            alert(data.message || 'خطا در حذف گزارش');
        }
    })
    .catch(err => {
        console.error('deleteModalNote error:', err);
        alert('خطا در حذف گزارش');
    });
}

function toPersianDigits(n) {
    const fa = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];
    return String(n).replace(/\d/g, d => fa[parseInt(d, 10)]);
}

function escapeHtml(text) {
    if (text === null || text === undefined) return '';
    const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
    return String(text).replace(/[&<>"']/g, function(m) { return map[m]; });
}

function openEditSubjectModalFromBtn(btn) {
    const id = btn.getAttribute('data-subject-id');
    const title = btn.getAttribute('data-subject-title') || '';
    document.getElementById('edit_subject_id_field').value = id;
    document.getElementById('edit_subject_title_field').value = title;
    openModal('editSubjectModal');
}

function toggleSidebar() {
    const sidebar = document.getElementById('appSidebar');
    const overlay = document.getElementById('sidebarOverlay');
    const btn = document.getElementById('hamburgerBtn');
    if (sidebar.classList.contains('open')) {
        closeSidebar();
    } else {
        sidebar.classList.add('open');
        overlay.classList.add('active');
        btn.classList.add('active');
        document.body.style.overflow = 'hidden';
    }
}

function closeSidebar() {
    const sidebar = document.getElementById('appSidebar');
    const overlay = document.getElementById('sidebarOverlay');
    const btn = document.getElementById('hamburgerBtn');
    if (!sidebar || !overlay || !btn) return;
    sidebar.classList.remove('open');
    overlay.classList.remove('active');
    btn.classList.remove('active');
    document.body.style.overflow = '';
}

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        const editModal = document.getElementById('editTaskModal');
        if (editModal && editModal.classList.contains('active')) {
            requestCloseEditModal();
            return;
        }
        const confirmModal = document.getElementById('confirmCloseModal');
        if (confirmModal && confirmModal.classList.contains('active')) {
            closeModal('confirmCloseModal');
            return;
        }
        closeSidebar();
    }
});

let resizeTimer;
window.addEventListener('resize', function() {
    clearTimeout(resizeTimer);
    resizeTimer = setTimeout(function() {
        if (window.innerWidth > 900) closeSidebar();
    }, 150);
});

function initCharts() {
    if (typeof Chart === 'undefined') {
        console.error('Chart.js لود نشده است!');
        return;
    }
    const D = window.DASHBOARD_DATA;
    if (!D) return;

    Chart.defaults.font.family = 'Segoe UI, Tahoma, sans-serif';
    Chart.defaults.font.size = 12;
    Chart.defaults.color = '#8a94ad';
    Chart.defaults.animation = false;

    const trendCtx = document.getElementById('trendChart');
    if (trendCtx) {
        const g = trendCtx.getContext('2d').createLinearGradient(0, 0, 0, 240);
        g.addColorStop(0, 'rgba(76, 139, 245, 0.35)');
        g.addColorStop(1, 'rgba(76, 139, 245, 0.02)');

        new Chart(trendCtx, {
            type: 'line',
            data: {
                labels: D.trendDays,
                datasets: [{
                    label: 'وظایف من',
                    data: D.trendCounts,
                    borderColor: '#4c8bf5',
                    backgroundColor: g,
                    borderWidth: 3,
                    fill: true,
                    tension: 0.45,
                    pointRadius: 0,
                    pointHoverRadius: 7,
                    pointHoverBackgroundColor: '#4c8bf5',
                    pointHoverBorderColor: '#fff',
                    pointHoverBorderWidth: 3,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                animation: false,
                plugins: { legend: { display: false } },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: { stepSize: 1, precision: 0, font: { size: 11 } },
                        grid: { color: '#f1f4fb', drawBorder: false },
                        border: { display: false }
                    },
                    x: {
                        grid: { display: false },
                        border: { display: false },
                        ticks: { font: { size: 11 } }
                    }
                }
            }
        });
    }

    const priorityCtx = document.getElementById('priorityChart');
    if (priorityCtx) {
        const gBar = priorityCtx.getContext('2d').createLinearGradient(0, 0, 0, 240);
        gBar.addColorStop(0, '#4ed4a3');
        gBar.addColorStop(1, '#2ebc8a');

        new Chart(priorityCtx, {
            type: 'bar',
            data: {
                labels: ['کم', 'متوسط', 'زیاد'],
                datasets: [{
                    label: 'تعداد',
                    data: [D.priorityLow, D.priorityMedium, D.priorityHigh],
                    backgroundColor: gBar,
                    borderRadius: 10,
                    borderSkipped: false,
                    barThickness: 42,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                animation: false,
                plugins: { legend: { display: false } },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: { stepSize: 1, precision: 0, font: { size: 11 } },
                        grid: { color: '#f1f4fb', drawBorder: false },
                        border: { display: false }
                    },
                    x: {
                        grid: { display: false },
                        border: { display: false },
                        ticks: { font: { size: 12, weight: 'bold' } }
                    }
                }
            }
        });
    }

    const tasksCtx = document.getElementById('tasksChart');
    if (tasksCtx) {
        new Chart(tasksCtx, {
            type: 'doughnut',
            data: {
                labels: ['انجام شده', 'انجام نشده'],
                datasets: [{
                    data: [D.tasksCompleted, D.tasksPending],
                    backgroundColor: ['#4ed4a3', '#ffa751'],
                    borderWidth: 0,
                    hoverOffset: 8
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                animation: false,
                cutout: '68%',
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            padding: 18,
                            font: { size: 13, weight: 'bold' },
                            usePointStyle: true,
                            pointStyle: 'circle'
                        }
                    }
                }
            }
        });
    }

    const avgTimeCtx = document.getElementById('avgTimeChart');
    if (avgTimeCtx) {
        new Chart(avgTimeCtx, {
            type: 'bar',
            data: {
                labels: ['وظایف من', 'درخواست‌های تماس', 'تیکت‌ها (سیستم)'],
                datasets: [{
                    label: 'میانگین زمان (ساعت)',
                    data: [D.taskAvgHours, D.callAvgHours, D.ticketAvgHours],
                    backgroundColor: ['rgba(76, 139, 245, 0.85)', 'rgba(46, 188, 138, 0.85)', 'rgba(127, 76, 240, 0.85)'],
                    borderRadius: 10,
                    borderSkipped: false,
                    barThickness: 55,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                animation: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                const hours = context.parsed.y;
                                if (hours <= 0) return 'بدون داده';
                                if (hours < 1) return Math.round(hours * 60) + ' دقیقه';
                                if (hours < 24) {
                                    const h = Math.floor(hours);
                                    const m = Math.round((hours - h) * 60);
                                    return m === 0 ? h + ' ساعت' : h + ' ساعت و ' + m + ' دقیقه';
                                }
                                return Math.floor(hours / 24) + ' روز';
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: { color: '#f1f4fb', drawBorder: false },
                        border: { display: false }
                    },
                    x: {
                        grid: { display: false },
                        border: { display: false },
                        ticks: { font: { size: 12, weight: 'bold' } }
                    }
                }
            }
        });
    }
}

document.addEventListener('DOMContentLoaded', function() {
    if (typeof Chart === 'undefined') {
        const script = document.createElement('script');
        script.src = 'https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js';
        script.onload = initCharts;
        document.head.appendChild(script);
        return;
    }
    initCharts();
});

document.addEventListener('keydown', function(e) {
    const isSaveCombo = (e.ctrlKey || e.metaKey) && !e.shiftKey && !e.altKey &&
                        (e.key === 's' || e.key === 'S' || e.keyCode === 83);

    if (!isSaveCombo) return;

    const createModal = document.getElementById('createTaskModal');
    const editModal   = document.getElementById('editTaskModal');

    if (createModal && createModal.classList.contains('active')) {
        e.preventDefault();
        e.stopPropagation();
        const submitBtn = createModal.querySelector('button[name="add_task"]');
        if (submitBtn) submitBtn.click();
        return;
    }

    if (editModal && editModal.classList.contains('active')) {
        e.preventDefault();
        e.stopPropagation();
        const submitBtn = editModal.querySelector('button[name="update_task"]');
        if (submitBtn && submitBtn.style.display !== 'none') submitBtn.click();
        return;
    }
}, true);

document.addEventListener('keydown', function(e) {
    const editModal = document.getElementById('editTaskModal');
    if (!editModal || !editModal.classList.contains('active')) return;

    const noteTextarea = document.getElementById('modalNoteText');
    if (!noteTextarea || document.activeElement !== noteTextarea) return;

    if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
        e.preventDefault();
        submitModalNote();
    }
}, true);

</script>
</body>
</html>