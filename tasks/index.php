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
}

function gregorianToJalali($gy, $gm, $gd)
{
    $m = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
    $g = ($gm > 2) ? $gy + 1 : $gy;
    $d = 355666 + (365 * $gy) + ((int)(($g + 3) / 4)) - ((int)(($g + 99) / 100)) + ((int)(($g + 399) / 400)) + $gd + $m[$gm - 1];
    $jy = -1595 + (33 * ((int)($d / 12053)));
    $d %= 12053;
    $jy += 4 * ((int)($d / 1461));
    $d %= 1461;
    if ($d > 365) {
        $jy += (int)(($d - 1) / 365);
        $d = ($d - 1) % 365;
    }
    if ($d < 186) {
        $jm = 1 + (int)($d / 31);
        $jd = 1 + ($d % 31);
    } else {
        $jm = 7 + (int)(($d - 186) / 30);
        $jd = 1 + (($d - 186) % 30);
    }
    return [$jy, $jm, $jd];
}
function formatPersianDate($dt, $w = true)
{
    if (empty($dt)) return '';
    $ts = strtotime($dt);
    if (!$ts) return $dt;
    list($jy, $jm, $jd) = gregorianToJalali((int)date('Y', $ts), (int)date('n', $ts), (int)date('j', $ts));
    $mo = ['فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];
    $wd = ['شنبه', 'یکشنبه', 'دوشنبه', 'سه‌شنبه', 'چهارشنبه', 'پنجشنبه', 'جمعه'];
    $p = ((int)date('w', $ts) + 1) % 7;
    return ($w ? $wd[$p] . ' ' : '') . $jd . ' ' . $mo[$jm - 1] . ' ' . $jy . ' - ' . date('H:i', $ts);
}
function formatPersianDateOnly($dt)
{
    if (empty($dt)) return '';
    $ts = strtotime($dt);
    if (!$ts) return $dt;
    list($jy, $jm, $jd) = gregorianToJalali((int)date('Y', $ts), (int)date('n', $ts), (int)date('j', $ts));
    $mo = ['فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];
    return $jd . ' ' . $mo[$jm - 1] . ' ' . $jy;
}
function formatPersianDateShort($dt)
{
    if (empty($dt)) return '';
    $ts = strtotime($dt);
    if (!$ts) return $dt;
    list($jy, $jm, $jd) = gregorianToJalali((int)date('Y', $ts), (int)date('n', $ts), (int)date('j', $ts));
    $mo = ['فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];
    return $jd . ' ' . $mo[$jm - 1] . ' ' . $jy . ' - ' . date('H:i', $ts);
}

if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrfToken = $_SESSION['csrf_token'];
function verifyCsrf()
{
    if (!isset($_POST['csrf_token'], $_SESSION['csrf_token'])) return false;
    return hash_equals($_SESSION['csrf_token'], (string)$_POST['csrf_token']);
}
function formToken($k)
{
    $s = 'form_token_' . $k;
    if (empty($_SESSION[$s])) $_SESSION[$s] = bin2hex(random_bytes(16));
    return $_SESSION[$s];
}
function verifyFormToken($k)
{
    $s = 'form_token_' . $k;
    $sub = (string)($_POST['form_token'] ?? '');
    if (empty($_SESSION[$s]) || $sub === '' || !hash_equals($_SESSION[$s], $sub)) return false;
    return true;
}
function isAjaxRequest()
{
    return !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
}
function redirectSelf($m = '', $t = 'success')
{
    while (ob_get_level() > 0) ob_end_clean();
    if (isAjaxRequest()) {
        $s = ($t === 'danger' || $t === 'error') ? 'error' : 'success';
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => $s, 'type' => $t, 'message' => $m], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($m !== '') {
        $_SESSION['flash_msg'] = $m;
        $_SESSION['flash_type'] = $t;
    }
    $p = $_GET['page'] ?? 'dashboard';
    header('Location: index.php?page=' . urlencode($p));
    exit;
}
function jsonResponse($d)
{
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function safeJsonEncode($d)
{
    $j = json_encode($d, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
    if ($j === false) return 'null';
    return str_replace(["\xe2\x80\xa8", "\xe2\x80\xa9"], ['\u2028', '\u2029'], $j);
}

function ensureShareToken(PDO $db, $tid)
{
    if ($tid <= 0) return '';
    try {
        $s = $db->prepare("SELECT share_token FROM tasks WHERE id=? LIMIT 1");
        $s->execute([$tid]);
        $e = $s->fetchColumn();
        if (!empty($e)) return (string)$e;
    } catch (PDOException $e) {
        return '';
    }
    $tk = bin2hex(random_bytes(16));
    try {
        $db->prepare("UPDATE tasks SET share_token=? WHERE id=?")->execute([$tk, $tid]);
        return $tk;
    } catch (PDOException $e) {
        return '';
    }
}
function getTaskamBotId(PDO $db)
{
    static $c = null;
    if ($c !== null && $c > 0) return $c;
    try {
        $s = $db->query("SELECT id FROM users WHERE mobile='00000000000' LIMIT 1");
        $e = (int)$s->fetchColumn();
        if ($e > 0) {
            $c = $e;
            return $c;
        }
        $s = $db->prepare("INSERT INTO users (mobile,first_name,last_name,password,status,role) VALUES ('00000000000',?,'',?,'active','user')");
        $s->execute(['تسکام', password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT)]);
        $c = (int)$db->lastInsertId();
        if ($c <= 0) {
            $s = $db->query("SELECT id FROM users WHERE mobile='00000000000' LIMIT 1");
            $c = (int)$s->fetchColumn();
        }
        return $c;
    } catch (PDOException $e) {
        return 0;
    }
}
function notifyTaskamNewTask(PDO $db, $tid, $aid, $cid, $title, $desc, $dd, $dt, $pr, $pid, $up)
{
    if ($tid <= 0 || $aid <= 0 || $aid === $cid) return false;
    try {
        $t = getTaskamBotId($db);
        if ($t <= 0) return false;
        $s = $db->prepare("SELECT share_token FROM tasks WHERE id=? LIMIT 1");
        $s->execute([$tid]);
        $st = (string)$s->fetchColumn();
        if ($st === '') $st = ensureShareToken($db, $tid);
        if ($st === '') return false;
        $cn = 'کاربر';
        $s = $db->prepare("SELECT first_name,last_name,mobile FROM users WHERE id=? LIMIT 1");
        $s->execute([$cid]);
        if ($c = $s->fetch(PDO::FETCH_ASSOC)) {
            $cn = trim(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? ''));
            if ($cn === '') $cn = $c['mobile'] ?? 'کاربر';
        }
        $pt = '';
        if (!empty($pid) && isset($up[$pid])) $pt = (string)($up[$pid]['title'] ?? '');
        $pl = ['low' => 'کم', 'medium' => 'متوسط', 'high' => 'زیاد'];
        $plb = $pl[$pr] ?? $pr;
        $ds = '';
        if (!empty($dd)) {
            $ds = formatPersianDateOnly($dd . ' 00:00:00');
            if (!empty($dt)) $ds .= ' - ' . substr($dt, 0, 5);
        }
        $l = ["📌 وظیفه جدیدی برای شما ایجاد شد", "", "عنوان: {$title}", "ایجاد کننده: {$cn}"];
        if ($pt !== '') $l[] = "پروژه: {$pt}";
        $l[] = "اولویت: {$plb}";
        if ($ds !== '') $l[] = "مهلت: {$ds}";
        if (!empty($desc)) {
            $l[] = "";
            $l[] = "توضیحات: " . mb_substr(trim($desc), 0, 200, 'UTF-8');
        }
        $msg = implode("\n", $l) . "\n\n[[task:{$st}]]";
        $i = $db->prepare("INSERT INTO colleague_messages (sender_id,receiver_id,message,is_read) VALUES (?,?,?,0)");
        $i->execute([$t, $aid, $msg]);
        return true;
    } catch (PDOException $e) {
        return false;
    }
}

function getAllowedAttachmentRules()
{
    return ['ext' => ['pdf', 'xls', 'xlsx', 'csv', 'doc', 'docx', 'txt', 'rtf', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'zip', 'rar', '7z'], 'mime' => ['application/pdf', 'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'text/csv', 'text/plain', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/rtf', 'text/rtf', 'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/bmp', 'application/zip', 'application/x-zip-compressed', 'application/x-rar-compressed', 'application/vnd.rar', 'application/x-7z-compressed', 'application/octet-stream'], 'max_size' => 10485760];
}
function humanFileSize($b)
{
    $b = (int)$b;
    if ($b < 1024) return $b . ' بایت';
    if ($b < 1048576) return round($b / 1024, 1) . ' کیلوبایت';
    return round($b / 1048576, 2) . ' مگابایت';
}
function attachmentIconClass($e)
{
    $e = strtolower($e);
    if ($e === 'pdf') return 'fa-file-pdf';
    if (in_array($e, ['xls', 'xlsx', 'csv'], true)) return 'fa-file-excel';
    if (in_array($e, ['doc', 'docx', 'rtf', 'txt'], true)) return 'fa-file-word';
    if (in_array($e, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'], true)) return 'fa-file-image';
    if (in_array($e, ['zip', 'rar', '7z'], true)) return 'fa-file-zipper';
    return 'fa-file';
}
function saveTaskAttachments(PDO $db, $tid, $uid, $f)
{
    $r = getAllowedAttachmentRules();
    $ae = $r['ext'];
    $am = $r['mime'];
    $ms = $r['max_size'];
    $dir = __DIR__ . '/../uploads/task_attachments/';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $u = [];
    $er = [];
    if (empty($f) || empty($f['name']) || !is_array($f['name'])) return [$u, $er];
    for ($i = 0; $i < count($f['name']); $i++) {
        if ((int)$f['error'][$i] === UPLOAD_ERR_NO_FILE) continue;
        if ((int)$f['error'][$i] !== UPLOAD_ERR_OK) {
            $er[] = $f['name'][$i] . ' (خطا)';
            continue;
        }
        if ((int)$f['size'][$i] <= 0 || (int)$f['size'][$i] > $ms) {
            $er[] = $f['name'][$i] . ' (حجم)';
            continue;
        }
        $n = (string)$f['name'][$i];
        $e = strtolower(pathinfo($n, PATHINFO_EXTENSION));
        if (!in_array($e, $ae, true)) {
            $er[] = $n . ' (فرمت)';
            continue;
        }
        $m = '';
        if (function_exists('finfo_open')) {
            $fi = finfo_open(FILEINFO_MIME_TYPE);
            if ($fi) {
                $m = (string)finfo_file($fi, $f['tmp_name'][$i]);
                finfo_close($fi);
            }
        }
        if ($m === '') $m = (string)($f['type'][$i] ?? 'application/octet-stream');
        if (!in_array($m, $am, true)) {
            $er[] = $n . ' (نوع)';
            continue;
        }
        $nn = 'task_' . $tid . '_' . time() . '_' . bin2hex(random_bytes(6)) . '.' . $e;
        $d = $dir . $nn;
        if (!@move_uploaded_file($f['tmp_name'][$i], $d)) {
            $er[] = $n . ' (ذخیره)';
            continue;
        }
        try {
            $s = $db->prepare("INSERT INTO task_attachments (task_id,user_id,file_name,file_path,file_size,file_ext,file_mime) VALUES (?,?,?,?,?,?,?)");
            $s->execute([$tid, $uid, mb_substr($n, 0, 255, 'UTF-8'), $nn, (int)$f['size'][$i], $e, $m]);
            $u[] = ['id' => (int)$db->lastInsertId(), 'name' => $n, 'path' => $nn];
        } catch (PDOException $e) {
            @unlink($d);
            $er[] = $n . ' (DB)';
        }
    }
    return [$u, $er];
}
function saveNoteAttachments(PDO $db, $nid, $uid, $f)
{
    $r = getAllowedAttachmentRules();
    $ae = $r['ext'];
    $am = $r['mime'];
    $ms = $r['max_size'];
    $dir = __DIR__ . '/../uploads/note_attachments/';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $u = [];
    $er = [];
    if (empty($f) || empty($f['name']) || !is_array($f['name'])) return [$u, $er];
    for ($i = 0; $i < count($f['name']); $i++) {
        if ((int)$f['error'][$i] === UPLOAD_ERR_NO_FILE) continue;
        if ((int)$f['error'][$i] !== UPLOAD_ERR_OK) {
            $er[] = $f['name'][$i] . ' (خطا)';
            continue;
        }
        if ((int)$f['size'][$i] <= 0 || (int)$f['size'][$i] > $ms) {
            $er[] = $f['name'][$i] . ' (حجم)';
            continue;
        }
        $n = (string)$f['name'][$i];
        $e = strtolower(pathinfo($n, PATHINFO_EXTENSION));
        if (!in_array($e, $ae, true)) {
            $er[] = $n . ' (فرمت)';
            continue;
        }
        $m = '';
        if (function_exists('finfo_open')) {
            $fi = finfo_open(FILEINFO_MIME_TYPE);
            if ($fi) {
                $m = (string)finfo_file($fi, $f['tmp_name'][$i]);
                finfo_close($fi);
            }
        }
        if ($m === '') $m = (string)($f['type'][$i] ?? 'application/octet-stream');
        if (!in_array($m, $am, true)) {
            $er[] = $n . ' (نوع)';
            continue;
        }
        $nn = 'note_' . $nid . '_' . time() . '_' . bin2hex(random_bytes(6)) . '.' . $e;
        $d = $dir . $nn;
        if (!@move_uploaded_file($f['tmp_name'][$i], $d)) {
            $er[] = $n . ' (ذخیره)';
            continue;
        }
        try {
            $s = $db->prepare("INSERT INTO note_attachments (note_id,user_id,file_name,file_path,file_size,file_ext,file_mime) VALUES (?,?,?,?,?,?,?)");
            $s->execute([$nid, $uid, mb_substr($n, 0, 255, 'UTF-8'), $nn, (int)$f['size'][$i], $e, $m]);
            $u[] = ['id' => (int)$db->lastInsertId(), 'name' => $n, 'path' => $nn];
        } catch (PDOException $e) {
            @unlink($d);
            $er[] = $n . ' (DB)';
        }
    }
    return [$u, $er];
}

try {
    $s = $db->prepare("SELECT status,role FROM users WHERE id=? LIMIT 1");
    $s->execute([(int)$_SESSION['user_id']]);
    $r = $s->fetch(PDO::FETCH_ASSOC);
    if ($r) {
        if (($r['role'] ?? 'user') === 'admin') {
            header('Location: ../admin/index.php');
            exit;
        }
        if (($r['status'] ?? 'pending') !== 'active') {
            header('Location: ../pending/index.php');
            exit;
        }
    }
} catch (PDOException $e) {
}

$userId = (int)$_SESSION['user_id'];
$page = $_GET['page'] ?? 'dashboard';
$sharedTaskParam = trim((string)($_GET['task'] ?? ''));
if ($sharedTaskParam !== '') $page = 'list';
$TASKS_PER_PAGE = 10;
$mt = $_GET['mt'] ?? 'mine';
if (!in_array($mt, ['mine', 'others'], true)) $mt = 'mine';
$st = $_GET['st'] ?? 'uncompleted';
if (!in_array($st, ['uncompleted', 'completed'], true)) $st = 'uncompleted';
function getPageParam($k)
{
    $p = isset($_GET[$k]) ? (int)$_GET[$k] : 1;
    return $p > 0 ? $p : 1;
}
function paginateArray($it, $p, $pp)
{
    $t = count($it);
    $tp = $t > 0 ? (int)ceil($t / $pp) : 1;
    if ($p > $tp) $p = $tp;
    if ($p < 1) $p = 1;
    return [array_slice($it, ($p - 1) * $pp, $pp), $p, $tp, $t];
}
function renderPagination($p, $tp, $tc, $k, $mt, $st)
{
    if ($tp <= 1) return '';
    $h = '<div class="pagination">';
    $b = function ($x) use ($k, $mt, $st) {
        return 'index.php?page=list&mt=' . urlencode($mt) . '&st=' . urlencode($st) . '&' . $k . '=' . $x;
    };
    $h .= ($p > 1) ? '<a class="page-btn" href="' . htmlspecialchars($b($p - 1), ENT_QUOTES, 'UTF-8') . '"><i class="fas fa-chevron-right"></i></a>' : '<span class="page-btn disabled"><i class="fas fa-chevron-right"></i></span>';
    $pages = [1];
    for ($i = $p - 1; $i <= $p + 1; $i++) if ($i > 1 && $i < $tp) $pages[] = $i;
    if ($tp > 1) $pages[] = $tp;
    $pages = array_values(array_unique($pages));
    sort($pages);
    $pv = 0;
    foreach ($pages as $x) {
        if ($x > $pv + 1) $h .= '<span class="page-dots">…</span>';
        $h .= ($x === $p) ? '<span class="page-btn active">' . $x . '</span>' : '<a class="page-btn" href="' . htmlspecialchars($b($x), ENT_QUOTES, 'UTF-8') . '">' . $x . '</a>';
        $pv = $x;
    }
    $h .= ($p < $tp) ? '<a class="page-btn" href="' . htmlspecialchars($b($p + 1), ENT_QUOTES, 'UTF-8') . '"><i class="fas fa-chevron-left"></i></a>' : '<span class="page-btn disabled"><i class="fas fa-chevron-left"></i></span>';
    $h .= '<span class="page-info">صفحه ' . $p . ' از ' . $tp . ' (کل: ' . $tc . ')</span></div>';
    return $h;
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
        $c = $db->query("SHOW COLUMNS FROM tasks LIKE 'project_id'");
        if ($c && $c->rowCount() === 0) {
            $db->exec("ALTER TABLE tasks ADD COLUMN project_id INT DEFAULT NULL AFTER subject_id");
            $db->exec("ALTER TABLE tasks ADD INDEX idx_tasks_project_id (project_id)");
        }
        $_SESSION['checked_project_col'] = 1;
    } catch (PDOException $e) {
    }
}
if (empty($_SESSION['checked_due_time_col'])) {
    try {
        $c = $db->query("SHOW COLUMNS FROM tasks LIKE 'due_time'");
        if ($c && $c->rowCount() === 0) $db->exec("ALTER TABLE tasks ADD COLUMN due_time TIME DEFAULT NULL AFTER due_date");
        $_SESSION['checked_due_time_col'] = 1;
    } catch (PDOException $e) {
    }
}
if (empty($_SESSION['checked_notified_col'])) {
    try {
        $c = $db->query("SHOW COLUMNS FROM tasks LIKE 'notified_at'");
        if ($c && $c->rowCount() === 0) $db->exec("ALTER TABLE tasks ADD COLUMN notified_at DATETIME DEFAULT NULL AFTER due_time");
        $_SESSION['checked_notified_col'] = 1;
    } catch (PDOException $e) {
    }
}
if (empty($_SESSION['checked_share_token_col'])) {
    try {
        $c = $db->query("SHOW COLUMNS FROM tasks LIKE 'share_token'");
        if ($c && $c->rowCount() === 0) {
            $db->exec("ALTER TABLE tasks ADD COLUMN share_token VARCHAR(64) DEFAULT NULL");
            try {
                $db->exec("ALTER TABLE tasks ADD UNIQUE INDEX idx_tasks_share_token (share_token)");
            } catch (PDOException $e) {
            }
        }
        $_SESSION['checked_share_token_col'] = 1;
    } catch (PDOException $e) {
    }
}
if (empty($_SESSION['checked_notes_table'])) {
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS task_notes (id INT AUTO_INCREMENT PRIMARY KEY,task_id INT NOT NULL,user_id INT NOT NULL,note TEXT NOT NULL,updated_at TIMESTAMP NULL DEFAULT NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;");
        $_SESSION['checked_notes_table'] = 1;
    } catch (PDOException $e) {
    }
}
if (empty($_SESSION['checked_attachments_table'])) {
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS task_attachments (id INT AUTO_INCREMENT PRIMARY KEY,task_id INT NOT NULL,user_id INT NOT NULL,file_name VARCHAR(255) NOT NULL,file_path VARCHAR(255) NOT NULL,file_size INT UNSIGNED DEFAULT NULL,file_ext VARCHAR(20) DEFAULT NULL,file_mime VARCHAR(120) DEFAULT NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,INDEX idx_task_attachments_task_id (task_id),INDEX idx_task_attachments_user_id (user_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;");
        $_SESSION['checked_attachments_table'] = 1;
    } catch (PDOException $e) {
    }
}
if (empty($_SESSION['checked_note_attachments_table'])) {
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS note_attachments (id INT AUTO_INCREMENT PRIMARY KEY,note_id INT NOT NULL,user_id INT NOT NULL,file_name VARCHAR(255) NOT NULL,file_path VARCHAR(255) NOT NULL,file_size INT UNSIGNED DEFAULT NULL,file_ext VARCHAR(20) DEFAULT NULL,file_mime VARCHAR(120) DEFAULT NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,INDEX idx_note_attachments_note_id (note_id),INDEX idx_note_attachments_user_id (user_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;");
        $_SESSION['checked_note_attachments_table'] = 1;
    } catch (PDOException $e) {
    }
}

$colleaguesList = [];
$colleaguesById = [];
try {
    $s = $db->prepare("SELECT u.id,u.first_name,u.last_name,u.mobile,u.avatar FROM colleagues c INNER JOIN users u ON u.id=c.colleague_user_id WHERE c.user_id=? ORDER BY u.first_name ASC,u.last_name ASC");
    $s->execute([$userId]);
    $colleaguesList = $s->fetchAll(PDO::FETCH_ASSOC);
    foreach ($colleaguesList as $c) $colleaguesById[(int)$c['id']] = $c;
} catch (PDOException $e) {
}

$userProjects = [];
try {
    $s = $db->prepare("SELECT p.id,p.title,p.profile_image,(p.user_id=?) AS is_creator FROM projects p WHERE p.user_id=? OR EXISTS (SELECT 1 FROM project_members pm WHERE pm.project_id=p.id AND pm.user_id=?) ORDER BY is_creator DESC,p.title ASC");
    $s->execute([$userId, $userId, $userId]);
    $userProjects = $s->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
}
$userProjectsById = [];
foreach ($userProjects as $p) $userProjectsById[(int)$p['id']] = $p;

$projectsMembersMap = [];
try {
    if (!empty($userProjects)) {
        $ids = array_map(fn($p) => (int)$p['id'], $userProjects);
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $s = $db->prepare("SELECT pm.project_id,pm.user_id,u.first_name,u.last_name,u.mobile,u.avatar,p.user_id AS project_creator_id FROM project_members pm INNER JOIN users u ON u.id=pm.user_id INNER JOIN projects p ON p.id=pm.project_id WHERE pm.project_id IN ($ph) AND u.status='active' ORDER BY pm.added_at ASC");
        $s->execute($ids);
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $pm) {
            $pid = (int)$pm['project_id'];
            $uid = (int)$pm['user_id'];
            $fn = trim(($pm['first_name'] ?? '') . ' ' . ($pm['last_name'] ?? ''));
            if ($fn === '') $fn = $pm['mobile'];
            $isSelf = ($uid === $userId);
            $isCreator = ($uid === (int)$pm['project_creator_id']);
            $av = null;
            if (!empty($pm['avatar'])) {
                $b = basename($pm['avatar']);
                if (preg_match('/^[A-Za-z0-9_.\-]+$/', $b) && file_exists(__DIR__ . '/../uploads/avatars/' . $b)) $av = '../uploads/avatars/' . rawurlencode($b);
            }
            $projectsMembersMap[$pid][] = ['id' => $uid, 'name' => $isSelf ? 'خودم' : $fn, 'mobile' => $pm['mobile'], 'initial' => mb_substr(trim($pm['first_name'] ?: $pm['mobile']), 0, 1, 'UTF-8'), 'avatar_url' => $av, 'is_self' => $isSelf, 'is_creator' => $isCreator];
        }
    }
} catch (PDOException $e) {
}

$currentUserAvatar = null;
$currentUserFirstName = 'خودم';
try {
    $s = $db->prepare("SELECT first_name,avatar FROM users WHERE id=? LIMIT 1");
    $s->execute([$userId]);
    $r = $s->fetch(PDO::FETCH_ASSOC);
    if ($r) {
        if (!empty($r['first_name'])) $currentUserFirstName = $r['first_name'];
        if (!empty($r['avatar'])) {
            $b = basename($r['avatar']);
            if (preg_match('/^[A-Za-z0-9_.\-]+$/', $b) && file_exists(__DIR__ . '/../uploads/avatars/' . $b)) $currentUserAvatar = '../uploads/avatars/' . $b;
        }
    }
} catch (PDOException $e) {
}

function checkTaskPermission($db, $tid, $uid, $a)
{
    static $c = [];
    $k = $tid . '_' . $uid;
    if (!isset($c[$k])) {
        $s = $db->prepare("SELECT * FROM tasks WHERE id=? AND (user_id=? OR assignee_id=?) LIMIT 1");
        $s->execute([$tid, $uid, $uid]);
        $c[$k] = $s->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    $t = $c[$k];
    if (!$t) return ['allowed' => false, 'reason' => 'not_found', 'task' => null, 'is_owner' => false];
    if ((int)$t['user_id'] === $uid) return ['allowed' => true, 'reason' => 'owner', 'task' => $t, 'is_owner' => true];
    $s = $db->prepare("SELECT permissions FROM colleagues WHERE user_id=? AND colleague_user_id=? LIMIT 1");
    $s->execute([(int)$t['user_id'], $uid]);
    $r = $s->fetch(PDO::FETCH_ASSOC);
    if (!$r) return ['allowed' => false, 'reason' => 'not_colleague', 'task' => $t, 'is_owner' => false];
    $p = json_decode($r['permissions'] ?? '[]', true);
    if (!is_array($p)) $p = [];
    if (in_array($a, $p, true)) return ['allowed' => true, 'reason' => 'permitted', 'task' => $t, 'is_owner' => false];
    return ['allowed' => false, 'reason' => 'no_permission', 'task' => $t, 'is_owner' => false];
}

if (isset($_GET['check_due_notifications'])) {
    $found = [];
    try {
        $s = $db->prepare("SELECT t.id,t.title,t.description,t.due_date,t.due_time,p.title AS project_title FROM tasks t LEFT JOIN projects p ON t.project_id=p.id WHERE t.assignee_id=? AND t.is_completed=0 AND t.notified_at IS NULL AND t.due_date IS NOT NULL AND t.due_time IS NOT NULL AND STR_TO_DATE(CONCAT(t.due_date,' ',t.due_time),'%Y-%m-%d %H:%i:%s')<=NOW() AND STR_TO_DATE(CONCAT(t.due_date,' ',t.due_time),'%Y-%m-%d %H:%i:%s')>=DATE_SUB(NOW(),INTERVAL 10 MINUTE)");
        $s->execute([$userId]);
        $rows = $s->fetchAll(PDO::FETCH_ASSOC);
        if (!empty($rows)) {
            $u = $db->prepare("UPDATE tasks SET notified_at=NOW() WHERE id=?");
            foreach ($rows as $r) {
                $u->execute([(int)$r['id']]);
                $found[] = ['id' => (int)$r['id'], 'title' => (string)$r['title'], 'description' => (string)($r['description'] ?? ''), 'project_title' => (string)($r['project_title'] ?? '')];
            }
        }
    } catch (PDOException $e) {
    }
    jsonResponse(['status' => 'success', 'tasks' => $found]);
}

if (isset($_GET['get_task_token'])) {
    $tid = (int)$_GET['get_task_token'];
    if ($tid <= 0) jsonResponse(['status' => 'error', 'message' => 'شناسه نامعتبر']);
    $p = checkTaskPermission($db, $tid, $userId, 'edit');
    if (!$p['task']) {
        $c = $db->prepare("SELECT id FROM tasks WHERE id=? AND assignee_id=? LIMIT 1");
        $c->execute([$tid, $userId]);
        if (!$c->fetchColumn()) jsonResponse(['status' => 'error', 'message' => 'دسترسی ندارید']);
    }
    $tk = ensureShareToken($db, $tid);
    if ($tk === '') jsonResponse(['status' => 'error', 'message' => 'خطا']);
    jsonResponse(['status' => 'success', 'token' => $tk, 'url' => 'index.php?task=' . $tk]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['add_subject']) || isset($_POST['update_subject']))) {
    if (!verifyCsrf()) redirectSelf('درخواست نامعتبر', 'danger');
    if (!verifyFormToken('subject')) redirectSelf('فرم قبلا ارسال شده', 'warning');
    $t = trim((string)($_POST['subject_title'] ?? ''));
    if (mb_strlen($t) > 100) redirectSelf('عنوان طولانی', 'danger');
    if (isset($_POST['update_subject']) && !empty($_POST['subject_id'])) {
        if ($t !== '') {
            $db->prepare("UPDATE subjects SET title=? WHERE id=? AND user_id=?")->execute([$t, (int)$_POST['subject_id'], $userId]);
            redirectSelf('ویرایش شد');
        }
        redirectSelf('عنوان الزامی', 'danger');
    } else if ($t !== '') {
        $db->prepare("INSERT INTO subjects (user_id,title) VALUES (?,?)")->execute([$userId, $t]);
        redirectSelf('ثبت شد');
    }
    redirectSelf('عنوان الزامی', 'danger');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_subject'])) {
    if (!verifyCsrf()) redirectSelf('درخواست نامعتبر', 'danger');
    $id = (int)$_POST['delete_subject'];
    $db->prepare("UPDATE tasks SET subject_id=NULL WHERE subject_id=? AND user_id=?")->execute([$id, $userId]);
    $db->prepare("DELETE FROM subjects WHERE id=? AND user_id=?")->execute([$id, $userId]);
    redirectSelf('حذف شد');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['add_task']) || isset($_POST['update_task']))) {
    if (!verifyCsrf()) redirectSelf('درخواست نامعتبر', 'danger');
    if (!verifyFormToken('task')) redirectSelf('فرم قبلا ارسال شده', 'warning');
    $title = trim((string)($_POST['task_title'] ?? ''));
    $desc = trim((string)($_POST['task_desc'] ?? ''));
    if (mb_strlen($title) > 255) redirectSelf('عنوان طولانی', 'danger');
    if (mb_strlen($desc) > 5000) redirectSelf('توضیحات طولانی', 'danger');
    $sid = !empty($_POST['subject_id']) ? (int)$_POST['subject_id'] : null;
    $pr = (string)($_POST['priority'] ?? 'medium');
    $dd = !empty($_POST['due_date']) ? (string)$_POST['due_date'] : null;
    if ($dd !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dd)) $dd = null;
    $dt = !empty($_POST['due_time']) ? trim((string)$_POST['due_time']) : null;
    if ($dt !== null) {
        if (preg_match('/^\d{1,2}:\d{2}$/', $dt)) $dt .= ':00';
        if (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $dt)) $dt = null;
    }
    if ($dd === null) $dt = null;
    $pid = !empty($_POST['project_id']) ? (int)$_POST['project_id'] : null;
    if ($pid !== null && !isset($userProjectsById[$pid])) $pid = null;
    $aid = !empty($_POST['assignee_id']) ? (int)$_POST['assignee_id'] : $userId;
    $aa = [$userId => true];
    foreach ($colleaguesById as $cid => $c) $aa[$cid] = true;
    if ($pid !== null && isset($projectsMembersMap[$pid])) foreach ($projectsMembersMap[$pid] as $pm) $aa[(int)$pm['id']] = true;
    if (!isset($aa[$aid])) $aid = $userId;
    if (!in_array($pr, ['low', 'medium', 'high'], true)) $pr = 'medium';
    $uf = $_FILES['task_attachments'] ?? null;
    if (isset($_POST['update_task']) && !empty($_POST['task_id'])) {
        $tid = (int)$_POST['task_id'];
        $p = checkTaskPermission($db, $tid, $userId, 'edit');
        if (!$p['task']) redirectSelf('دسترسی ندارید', 'danger');
        $io = $p['is_owner'];
        $he = $p['allowed'];
        $oa = (int)$p['task']['assignee_id'];
        $op = (int)($p['task']['project_id'] ?? 0);
        $ot = $p['task']['title'];
        $od = $p['task']['description'];
        $opr = $p['task']['priority'];
        $odd = $p['task']['due_date'];
        $odt = $p['task']['due_time'] ?? null;
        $os = $p['task']['subject_id'];
        if (!$io && !$he) {
            $title = $ot;
            $desc = $od;
            $pr = $opr;
            $dd = $odd;
            $dt = $odt;
            $sid = $os;
        }
        $w = [];
        if (!$io && $aid !== $oa) {
            $p2 = checkTaskPermission($db, $tid, $userId, 'reassign');
            if (!$p2['allowed']) {
                $aid = $oa;
                $w[] = 'دسترسی تغییر مسئول ندارید';
            }
        }
        $np = $pid !== null ? (int)$pid : 0;
        if (!$io && $np !== $op) {
            $p2 = checkTaskPermission($db, $tid, $userId, 'change_project');
            if (!$p2['allowed']) {
                $pid = $op > 0 ? $op : null;
                $w[] = 'دسترسی تغییر پروژه ندارید';
            }
        }
        $db->prepare("UPDATE tasks SET subject_id=?,project_id=?,assignee_id=?,title=?,description=?,priority=?,due_date=?,due_time=?,notified_at=NULL WHERE id=?")->execute([$sid, $pid, $aid, $title, $desc, $pr, $dd, $dt, $tid]);
        ensureShareToken($db, $tid);
        if ($aid !== $oa && $aid !== $userId) notifyTaskamNewTask($db, $tid, $aid, $userId, $title, $desc, $dd, $dt, $pr, $pid, $userProjectsById);
        if (!empty($uf)) {
            list($s, $uerr) = saveTaskAttachments($db, $tid, $userId, $uf);
            if (!empty($uerr)) $w[] = 'برخی فایل‌ها آپلود نشد: ' . implode('، ', $uerr);
        }
        if (!empty($w)) redirectSelf('ذخیره شد اما: ' . implode(' — ', $w), 'warning');
        redirectSelf('ویرایش شد');
    } else if ($title !== '') {
        $db->prepare("INSERT INTO tasks (user_id,assignee_id,subject_id,project_id,title,description,priority,due_date,due_time) VALUES (?,?,?,?,?,?,?,?,?)")->execute([$userId, $aid, $sid, $pid, $title, $desc, $pr, $dd, $dt]);
        $nt = (int)$db->lastInsertId();
        if ($nt > 0) {
            ensureShareToken($db, $nt);
            notifyTaskamNewTask($db, $nt, $aid, $userId, $title, $desc, $dd, $dt, $pr, $pid, $userProjectsById);
        }
        if (!empty($uf) && $nt > 0) {
            list($s, $uerr) = saveTaskAttachments($db, $nt, $userId, $uf);
            if (!empty($uerr)) redirectSelf('ثبت شد اما: ' . implode('، ', $uerr), 'warning');
        }
        redirectSelf('ثبت شد');
    }
    redirectSelf('عنوان الزامی', 'danger');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_task_note'])) {
    if (!verifyCsrf()) {
        if (isAjaxRequest()) jsonResponse(['status' => 'error', 'message' => 'درخواست نامعتبر']);
        redirectSelf('درخواست نامعتبر', 'danger');
    }
    $tid = (int)($_POST['note_task_id'] ?? 0);
    $nt = trim((string)($_POST['note_text'] ?? ''));
    if (mb_strlen($nt) > 5000) {
        if (isAjaxRequest()) jsonResponse(['status' => 'error', 'message' => 'طولانی']);
        redirectSelf('طولانی', 'danger');
    }
    $p = checkTaskPermission($db, $tid, $userId, 'notes');
    if (!$p['allowed'] && $p['task'] && (int)$p['task']['assignee_id'] === $userId) $p['allowed'] = true;
    $hf = false;
    if (!empty($_FILES['note_attachments']['name']) && is_array($_FILES['note_attachments']['name'])) foreach ($_FILES['note_attachments']['name'] as $n) if ($n !== '') {
        $hf = true;
        break;
    }
    if ($tid > 0 && ($nt !== '' || $hf) && $p['allowed']) {
        $stx = $nt !== '' ? $nt : '📎 فایل پیوست';
        $db->prepare("INSERT INTO task_notes (task_id,user_id,note) VALUES (?,?,?)")->execute([$tid, $userId, $stx]);
        $nnid = (int)$db->lastInsertId();
        if ($hf) saveNoteAttachments($db, $nnid, $userId, $_FILES['note_attachments']);
        $mids = [];
        if (!empty($_POST['mention_ids']) && is_array($_POST['mention_ids'])) foreach ($_POST['mention_ids'] as $mid) {
            $m = (int)$mid;
            if ($m > 0 && $m !== $userId) $mids[$m] = true;
        }
        $mids = array_keys($mids);
        if (!empty($mids)) {
            try {
                $tb = getTaskamBotId($db);
                if ($tb > 0) {
                    $s = $db->prepare("SELECT id,title,share_token,project_id FROM tasks WHERE id=? LIMIT 1");
                    $s->execute([$tid]);
                    $ti = $s->fetch(PDO::FETCH_ASSOC);
                    if ($ti) {
                        $tt = !empty($ti['share_token']) ? $ti['share_token'] : ensureShareToken($db, (int)$ti['id']);
                        $an = 'کاربر';
                        $s = $db->prepare("SELECT first_name,last_name,mobile FROM users WHERE id=? LIMIT 1");
                        $s->execute([$userId]);
                        $au = $s->fetch(PDO::FETCH_ASSOC);
                        if ($au) {
                            $an = trim(($au['first_name'] ?? '') . ' ' . ($au['last_name'] ?? ''));
                            if ($an === '') $an = $au['mobile'];
                        }
                        $msg = "📝 یادداشت جدید در وظیفه «{$ti['title']}»\n\nاز طرف: {$an}\n\n" . mb_substr(trim($stx), 0, 220, 'UTF-8') . "\n\n[[task:{$tt}]]";
                        $in = $db->prepare("INSERT INTO colleague_messages (sender_id,receiver_id,message,is_read) VALUES (?,?,?,0)");
                        foreach ($mids as $mid) {
                            $ia = false;
                            $s = $db->prepare("SELECT 1 FROM colleagues WHERE (user_id=? AND colleague_user_id=?) OR (user_id=? AND colleague_user_id=?) LIMIT 1");
                            $s->execute([$userId, $mid, $mid, $userId]);
                            if ($s->fetchColumn()) $ia = true;
                            if (!$ia && !empty($ti['project_id'])) {
                                $s = $db->prepare("SELECT 1 FROM project_members WHERE project_id=? AND user_id=? LIMIT 1");
                                $s->execute([(int)$ti['project_id'], $mid]);
                                if ($s->fetchColumn()) $ia = true;
                            }
                            if ($ia) {
                                try {
                                    $in->execute([$tb, $mid, $msg]);
                                } catch (PDOException $e) {
                                }
                            }
                        }
                    }
                }
            } catch (PDOException $e) {
            }
        }
        if (isAjaxRequest()) {
            $s = $db->prepare("SELECT COUNT(*) FROM task_notes WHERE task_id=?");
            $s->execute([$tid]);
            jsonResponse(['status' => 'success', 'new_count' => (int)$s->fetchColumn()]);
        }
        redirectSelf('ثبت شد');
    }
    if (isAjaxRequest()) jsonResponse(['status' => 'error', 'message' => 'دسترسی ندارید']);
    redirectSelf('دسترسی ندارید', 'danger');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_note'], $_POST['note_id'])) {
    if (!verifyCsrf()) jsonResponse(['status' => 'error', 'message' => 'نامعتبر']);
    $nid = (int)$_POST['note_id'];
    $tx = trim((string)($_POST['note_text'] ?? ''));
    if ($nid <= 0 || $tx === '') jsonResponse(['status' => 'error', 'message' => 'نامعتبر']);
    if (mb_strlen($tx) > 5000) jsonResponse(['status' => 'error', 'message' => 'طولانی']);
    $s = $db->prepare("SELECT tn.id,tn.user_id,tn.task_id FROM task_notes tn INNER JOIN tasks t ON t.id=tn.task_id WHERE tn.id=? AND (t.user_id=? OR t.assignee_id=?) LIMIT 1");
    $s->execute([$nid, $userId, $userId]);
    $r = $s->fetch(PDO::FETCH_ASSOC);
    if (!$r) jsonResponse(['status' => 'error', 'message' => 'یافت نشد']);
    if ((int)$r['user_id'] !== $userId) jsonResponse(['status' => 'error', 'message' => 'فقط خودتان']);
    $db->prepare("UPDATE task_notes SET note=? WHERE id=?")->execute([$tx, $nid]);
    jsonResponse(['status' => 'success']);
}

if (isset($_GET['get_notes']) && isset($_GET['task_id'])) {
    $tid = (int)$_GET['task_id'];
    $s = $db->prepare("SELECT id FROM tasks WHERE id=? AND (user_id=? OR assignee_id=?)");
    $s->execute([$tid, $userId, $userId]);
    if (!$s->fetchColumn()) jsonResponse(['status' => 'error', 'message' => 'دسترسی ندارید']);
    $s = $db->prepare("SELECT tn.id,tn.task_id,tn.user_id,tn.note,tn.created_at,u.first_name,u.last_name,u.mobile,u.avatar FROM task_notes tn LEFT JOIN users u ON u.id=tn.user_id WHERE tn.task_id=? ORDER BY tn.created_at DESC");
    $s->execute([$tid]);
    $rows = $s->fetchAll(PDO::FETCH_ASSOC);
    $nm = [];
    $nids = array_column($rows, 'id');
    if (!empty($nids)) {
        try {
            $ph = implode(',', array_fill(0, count($nids), '?'));
            $s = $db->prepare("SELECT id,note_id,user_id,file_name,file_path,file_size,file_ext,file_mime,created_at FROM note_attachments WHERE note_id IN ($ph) ORDER BY created_at ASC");
            $s->execute($nids);
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $a) {
                $nid = (int)$a['note_id'];
                $a['id'] = (int)$a['id'];
                $a['file_size'] = (int)$a['file_size'];
                $a['file_size_human'] = humanFileSize($a['file_size']);
                $a['icon'] = attachmentIconClass($a['file_ext'] ?? '');
                $a['url'] = '../uploads/note_attachments/' . rawurlencode($a['file_path']);
                $nm[$nid][] = $a;
            }
        } catch (PDOException $e) {
        }
    }
    foreach ($rows as &$row) {
        $row['persian_date'] = formatPersianDate($row['created_at']);
        $row['is_mine'] = ((int)$row['user_id'] === $userId);
        $row['attachments'] = $nm[(int)$row['id']] ?? [];
    }
    unset($row);
    jsonResponse(['status' => 'success', 'notes' => $rows]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_note'], $_POST['note_id'])) {
    if (!verifyCsrf()) jsonResponse(['status' => 'error', 'message' => 'نامعتبر']);
    $nid = (int)$_POST['note_id'];
    $s = $db->prepare("SELECT tn.task_id,tn.user_id,t.user_id AS task_owner FROM task_notes tn INNER JOIN tasks t ON t.id=tn.task_id WHERE tn.id=?");
    $s->execute([$nid]);
    $r = $s->fetch(PDO::FETCH_ASSOC);
    if (!$r) jsonResponse(['status' => 'error', 'message' => 'یافت نشد']);
    $tid = (int)$r['task_id'];
    $cd = ((int)$r['user_id'] === $userId) || ((int)$r['task_owner'] === $userId);
    if (!$cd) {
        $p = checkTaskPermission($db, $tid, $userId, 'notes');
        if ($p['allowed']) $cd = true;
    }
    if (!$cd) jsonResponse(['status' => 'error', 'message' => 'دسترسی ندارید']);
    try {
        $s = $db->prepare("SELECT file_path FROM note_attachments WHERE note_id=?");
        $s->execute([$nid]);
        foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $fp) {
            $f = __DIR__ . '/../uploads/note_attachments/' . basename($fp);
            if (is_file($f)) @unlink($f);
        }
        $db->prepare("DELETE FROM note_attachments WHERE note_id=?")->execute([$nid]);
    } catch (PDOException $e) {
    }
    $db->prepare("DELETE FROM task_notes WHERE id=?")->execute([$nid]);
    $s = $db->prepare("SELECT COUNT(*) FROM task_notes WHERE task_id=?");
    $s->execute([$tid]);
    jsonResponse(['status' => 'success', 'task_id' => $tid, 'new_count' => (int)$s->fetchColumn()]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_note_attachment'], $_POST['attachment_id'])) {
    if (!verifyCsrf()) jsonResponse(['status' => 'error', 'message' => 'نامعتبر']);
    $aid = (int)$_POST['attachment_id'];
    $s = $db->prepare("SELECT na.id,na.note_id,na.user_id,na.file_path,tn.user_id AS note_owner,tn.task_id FROM note_attachments na INNER JOIN task_notes tn ON tn.id=na.note_id WHERE na.id=? LIMIT 1");
    $s->execute([$aid]);
    $r = $s->fetch(PDO::FETCH_ASSOC);
    if (!$r) jsonResponse(['status' => 'error', 'message' => 'یافت نشد']);
    $cd = ((int)$r['user_id'] === $userId) || ((int)$r['note_owner'] === $userId);
    if (!$cd) {
        $p = checkTaskPermission($db, (int)$r['task_id'], $userId, 'notes');
        if ($p['allowed']) $cd = true;
    }
    if (!$cd) jsonResponse(['status' => 'error', 'message' => 'دسترسی ندارید']);
    $fp = __DIR__ . '/../uploads/note_attachments/' . basename($r['file_path']);
    if (is_file($fp)) @unlink($fp);
    $db->prepare("DELETE FROM note_attachments WHERE id=?")->execute([$aid]);
    jsonResponse(['status' => 'success']);
}

if (isset($_GET['get_attachments']) && isset($_GET['task_id'])) {
    $tid = (int)$_GET['task_id'];
    $s = $db->prepare("SELECT id FROM tasks WHERE id=? AND (user_id=? OR assignee_id=?)");
    $s->execute([$tid, $userId, $userId]);
    if (!$s->fetchColumn()) jsonResponse(['status' => 'error', 'message' => 'دسترسی ندارید']);
    $s = $db->prepare("SELECT id,task_id,user_id,file_name,file_path,file_size,file_ext,file_mime,created_at FROM task_attachments WHERE task_id=? ORDER BY created_at ASC");
    $s->execute([$tid]);
    $rows = $s->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$row) {
        $row['id'] = (int)$row['id'];
        $row['task_id'] = (int)$row['task_id'];
        $row['user_id'] = (int)$row['user_id'];
        $row['file_size'] = (int)$row['file_size'];
        $row['file_size_human'] = humanFileSize($row['file_size']);
        $row['is_mine'] = ((int)$row['user_id'] === $userId);
        $row['icon'] = attachmentIconClass($row['file_ext'] ?? '');
        $row['url'] = '../uploads/task_attachments/' . rawurlencode($row['file_path']);
        $row['persian_date'] = formatPersianDate($row['created_at']);
    }
    unset($row);
    jsonResponse(['status' => 'success', 'attachments' => $rows]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_attachment'], $_POST['attachment_id'])) {
    if (!verifyCsrf()) jsonResponse(['status' => 'error', 'message' => 'نامعتبر']);
    $aid = (int)$_POST['attachment_id'];
    $s = $db->prepare("SELECT ta.id,ta.task_id,ta.user_id,ta.file_path,t.user_id AS task_owner FROM task_attachments ta INNER JOIN tasks t ON t.id=ta.task_id WHERE ta.id=? LIMIT 1");
    $s->execute([$aid]);
    $r = $s->fetch(PDO::FETCH_ASSOC);
    if (!$r) jsonResponse(['status' => 'error', 'message' => 'یافت نشد']);
    $tid = (int)$r['task_id'];
    $cd = ((int)$r['user_id'] === $userId) || ((int)$r['task_owner'] === $userId);
    if (!$cd) {
        $p = checkTaskPermission($db, $tid, $userId, 'notes');
        if ($p['allowed']) $cd = true;
    }
    if (!$cd) jsonResponse(['status' => 'error', 'message' => 'دسترسی ندارید']);
    $fp = __DIR__ . '/../uploads/task_attachments/' . basename($r['file_path']);
    if (is_file($fp)) @unlink($fp);
    $db->prepare("DELETE FROM task_attachments WHERE id=?")->execute([$aid]);
    $s = $db->prepare("SELECT COUNT(*) FROM task_attachments WHERE task_id=?");
    $s->execute([$tid]);
    jsonResponse(['status' => 'success', 'task_id' => $tid, 'new_count' => (int)$s->fetchColumn()]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle'])) {
    $ia = isAjaxRequest();
    if (!verifyCsrf()) {
        if ($ia) jsonResponse(['status' => 'error', 'message' => 'نامعتبر']);
        redirectSelf('درخواست نامعتبر', 'danger');
    }
    $tid = (int)$_POST['toggle'];
    $p = checkTaskPermission($db, $tid, $userId, 'complete');
    if (!$p['allowed'] && $p['task'] && (int)$p['task']['assignee_id'] === $userId) $p['allowed'] = true;
    if ($p['allowed']) {
        $c = (int)$p['task']['is_completed'];
        if ($c === 1) $db->prepare("UPDATE tasks SET is_completed=0,completed_at=NULL WHERE id=?")->execute([$tid]);
        else $db->prepare("UPDATE tasks SET is_completed=1,completed_at=NOW() WHERE id=?")->execute([$tid]);
    }
    if ($ia) jsonResponse(['status' => $p['allowed'] ? 'success' : 'error', 'message' => $p['allowed'] ? '' : 'دسترسی ندارید']);
    redirectSelf();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete'])) {
    if (!verifyCsrf()) redirectSelf('درخواست نامعتبر', 'danger');
    $tid = (int)$_POST['delete'];
    $p = checkTaskPermission($db, $tid, $userId, 'delete');
    if ($p['allowed']) {
        try {
            $s = $db->prepare("SELECT file_path FROM task_attachments WHERE task_id=?");
            $s->execute([$tid]);
            foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $fp) {
                $f = __DIR__ . '/../uploads/task_attachments/' . basename($fp);
                if (is_file($f)) @unlink($f);
            }
        } catch (PDOException $e) {
        }
        try {
            $s = $db->prepare("SELECT na.file_path FROM note_attachments na INNER JOIN task_notes tn ON tn.id=na.note_id WHERE tn.task_id=?");
            $s->execute([$tid]);
            foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $fp) {
                $f = __DIR__ . '/../uploads/note_attachments/' . basename($fp);
                if (is_file($f)) @unlink($f);
            }
        } catch (PDOException $e) {
        }
        $db->prepare("DELETE FROM tasks WHERE id=?")->execute([$tid]);
        $db->prepare("DELETE FROM task_notes WHERE task_id=?")->execute([$tid]);
        try {
            $db->prepare("DELETE FROM task_attachments WHERE task_id=?")->execute([$tid]);
        } catch (PDOException $e) {
        }
        redirectSelf('حذف شد');
    }
    redirectSelf('دسترسی ندارید', 'danger');
}

/* Statistics */
$stats = ['total' => 0, 'completed' => 0, 'pending' => 0, 'high' => 0, 'medium' => 0, 'low' => 0];
try {
    $s = $db->prepare("SELECT COUNT(*) AS total,COALESCE(SUM(CASE WHEN is_completed=1 THEN 1 ELSE 0 END),0) AS completed,COALESCE(SUM(CASE WHEN is_completed=0 THEN 1 ELSE 0 END),0) AS pending,COALESCE(SUM(CASE WHEN priority='high' THEN 1 ELSE 0 END),0) AS high,COALESCE(SUM(CASE WHEN priority='medium' THEN 1 ELSE 0 END),0) AS medium,COALESCE(SUM(CASE WHEN priority='low' THEN 1 ELSE 0 END),0) AS low FROM tasks WHERE assignee_id=?");
    $s->execute([$userId]);
    $r = $s->fetch(PDO::FETCH_ASSOC);
    if ($r) $stats = ['total' => (int)$r['total'], 'completed' => (int)$r['completed'], 'pending' => (int)$r['pending'], 'high' => (int)$r['high'], 'medium' => (int)$r['medium'], 'low' => (int)$r['low']];
} catch (PDOException $e) {
}
$cTotal = $stats['total'];
$cComp = $stats['completed'];
$cPend = $stats['pending'];
$cHigh = $stats['high'];
$cMed = $stats['medium'];
$cLow = $stats['low'];
$ratio = $cTotal > 0 ? round(($cComp / $cTotal) * 100) : 0;
$cAssignedByMe = 0;
$cAssignedToMe = 0;
try {
    $s = $db->prepare("SELECT COALESCE(SUM(CASE WHEN user_id=? AND assignee_id!=? THEN 1 ELSE 0 END),0) AS by_me,COALESCE(SUM(CASE WHEN assignee_id=? AND user_id!=? THEN 1 ELSE 0 END),0) AS to_me FROM tasks WHERE user_id=? OR assignee_id=?");
    $s->execute([$userId, $userId, $userId, $userId, $userId, $userId]);
    $r = $s->fetch(PDO::FETCH_ASSOC);
    if ($r) {
        $cAssignedByMe = (int)$r['by_me'];
        $cAssignedToMe = (int)$r['to_me'];
    }
} catch (PDOException $e) {
}
$topSubName = 'بدون موضوع';
try {
    $s = $db->prepare("SELECT s.title,COUNT(t.id) AS cnt FROM subjects s LEFT JOIN tasks t ON s.id=t.subject_id WHERE s.user_id=? GROUP BY s.id ORDER BY cnt DESC LIMIT 1");
    $s->execute([$userId]);
    $r = $s->fetch(PDO::FETCH_ASSOC);
    if ($r) $topSubName = $r['title'];
} catch (PDOException $e) {
}
$cTkTotal = $cTkDone = $cTkPending = $cTkVIP = $cTkHigh = $cTkToday = 0;
try {
    $s = $db->query("SELECT COUNT(*) AS total,COALESCE(SUM(CASE WHEN status=1 THEN 1 ELSE 0 END),0) AS done,COALESCE(SUM(CASE WHEN status=0 THEN 1 ELSE 0 END),0) AS pending,COALESCE(SUM(CASE WHEN is_vip=1 THEN 1 ELSE 0 END),0) AS vip,COALESCE(SUM(CASE WHEN priority='very_high' THEN 1 ELSE 0 END),0) AS high,COALESCE(SUM(CASE WHEN DATE(created_at)=CURDATE() THEN 1 ELSE 0 END),0) AS today FROM tickets");
    $r = $s->fetch(PDO::FETCH_ASSOC);
    if ($r) {
        $cTkTotal = (int)$r['total'];
        $cTkDone = (int)$r['done'];
        $cTkPending = (int)$r['pending'];
        $cTkVIP = (int)$r['vip'];
        $cTkHigh = (int)$r['high'];
        $cTkToday = (int)$r['today'];
    }
} catch (PDOException $e) {
}
$cTxTotal = 0;
try {
    $s = $db->prepare("SELECT COUNT(*) FROM texts WHERE user_id=?");
    $s->execute([$userId]);
    $cTxTotal = (int)$s->fetchColumn();
} catch (PDOException $e) {
}
$cCrTotal = $cCrNew = $cCrDone = 0;
try {
    $s = $db->prepare("SELECT COUNT(*) AS total,COALESCE(SUM(CASE WHEN status=0 THEN 1 ELSE 0 END),0) AS new,COALESCE(SUM(CASE WHEN status=1 THEN 1 ELSE 0 END),0) AS done FROM call_requests WHERE user_id=? OR assignee_id=?");
    $s->execute([$userId, $userId]);
    $r = $s->fetch(PDO::FETCH_ASSOC);
    if ($r) {
        $cCrTotal = (int)$r['total'];
        $cCrNew = (int)$r['new'];
        $cCrDone = (int)$r['done'];
    }
} catch (PDOException $e) {
}
$trendDays = [];
$trendCounts = [];
$tkTrendCounts = [];
try {
    $s = $db->prepare("SELECT DATE(created_at) AS d,COUNT(*) AS c FROM tasks WHERE assignee_id=? AND created_at>=DATE_SUB(CURDATE(),INTERVAL 6 DAY) GROUP BY DATE(created_at)");
    $s->execute([$userId]);
    $td = [];
    while ($r = $s->fetch(PDO::FETCH_ASSOC)) $td[$r['d']] = (int)$r['c'];
    $s = $db->query("SELECT DATE(created_at) AS d,COUNT(*) AS c FROM tickets WHERE created_at>=DATE_SUB(CURDATE(),INTERVAL 6 DAY) GROUP BY DATE(created_at)");
    $tkd = [];
    while ($r = $s->fetch(PDO::FETCH_ASSOC)) $tkd[$r['d']] = (int)$r['c'];
    for ($i = 6; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-$i days"));
        $trendDays[] = date('m/d', strtotime("-$i days"));
        $trendCounts[] = $td[$d] ?? 0;
        $tkTrendCounts[] = $tkd[$d] ?? 0;
    }
} catch (PDOException $e) {
    for ($i = 6; $i >= 0; $i--) {
        $trendDays[] = date('m/d', strtotime("-$i days"));
        $trendCounts[] = 0;
        $tkTrendCounts[] = 0;
    }
}
$taskAvgHours = $taskMinHours = $taskMaxHours = 0;
$taskDoneCount = 0;
try {
    $s = $db->prepare("SELECT AVG(TIMESTAMPDIFF(MINUTE,created_at,completed_at))/60 AS a,MIN(TIMESTAMPDIFF(MINUTE,created_at,completed_at))/60 AS mi,MAX(TIMESTAMPDIFF(MINUTE,created_at,completed_at))/60 AS ma,COUNT(*) AS c FROM tasks WHERE (user_id=? OR assignee_id=?) AND is_completed=1 AND completed_at IS NOT NULL AND completed_at>created_at");
    $s->execute([$userId, $userId]);
    $r = $s->fetch(PDO::FETCH_ASSOC);
    if ($r) {
        $taskAvgHours = round((float)($r['a'] ?? 0), 4);
        $taskMinHours = round((float)($r['mi'] ?? 0), 4);
        $taskMaxHours = round((float)($r['ma'] ?? 0), 4);
        $taskDoneCount = (int)($r['c'] ?? 0);
    }
} catch (PDOException $e) {
}
$callAvgHours = $callMinHours = $callMaxHours = 0;
$callDoneCount = 0;
try {
    $s = $db->prepare("SELECT AVG(TIMESTAMPDIFF(MINUTE,created_at,completed_at))/60 AS a,MIN(TIMESTAMPDIFF(MINUTE,created_at,completed_at))/60 AS mi,MAX(TIMESTAMPDIFF(MINUTE,created_at,completed_at))/60 AS ma,COUNT(*) AS c FROM call_requests WHERE (user_id=? OR assignee_id=?) AND status=1 AND completed_at IS NOT NULL AND completed_at>created_at");
    $s->execute([$userId, $userId]);
    $r = $s->fetch(PDO::FETCH_ASSOC);
    if ($r) {
        $callAvgHours = round((float)($r['a'] ?? 0), 4);
        $callMinHours = round((float)($r['mi'] ?? 0), 4);
        $callMaxHours = round((float)($r['ma'] ?? 0), 4);
        $callDoneCount = (int)($r['c'] ?? 0);
    }
} catch (PDOException $e) {
}
$ticketAvgHours = $ticketMinHours = $ticketMaxHours = 0;
$ticketDoneCount = 0;
try {
    $s = $db->query("SELECT AVG(TIMESTAMPDIFF(MINUTE,created_at,completed_at))/60 AS a,MIN(TIMESTAMPDIFF(MINUTE,created_at,completed_at))/60 AS mi,MAX(TIMESTAMPDIFF(MINUTE,created_at,completed_at))/60 AS ma,COUNT(*) AS c FROM tickets WHERE status=1 AND completed_at IS NOT NULL AND completed_at>created_at");
    $r = $s->fetch(PDO::FETCH_ASSOC);
    if ($r) {
        $ticketAvgHours = round((float)($r['a'] ?? 0), 4);
        $ticketMinHours = round((float)($r['mi'] ?? 0), 4);
        $ticketMaxHours = round((float)($r['ma'] ?? 0), 4);
        $ticketDoneCount = (int)($r['c'] ?? 0);
    }
} catch (PDOException $e) {
}
function formatDuration($h)
{
    $h = (float)$h;
    if ($h <= 0) return '—';
    if ($h < 1) {
        $m = round($h * 60);
        return $m < 1 ? 'کمتر از ۱ دقیقه' : $m . ' دقیقه';
    }
    if ($h < 24) {
        $hh = floor($h);
        $mm = round(($h - $hh) * 60);
        return $mm == 0 ? $hh . ' ساعت' : $hh . ' ساعت و ' . $mm . ' دقیقه';
    }
    $d = floor($h / 24);
    $rh = $h - ($d * 24);
    $hh = floor($rh);
    $mm = round(($rh - $hh) * 60);
    $r = $d . ' روز';
    if ($hh > 0) $r .= ' و ' . $hh . ' ساعت';
    if ($mm > 0 && $hh == 0) $r .= ' و ' . $mm . ' دقیقه';
    return $r;
}

$subjects = [];
try {
    $s = $db->prepare("SELECT * FROM subjects WHERE user_id=? ORDER BY title ASC");
    $s->execute([$userId]);
    $subjects = $s->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
}
$tasks = [];
try {
    $s = $db->prepare("SELECT t.*,s.title AS subject_title,p.title AS project_title,p.profile_image AS project_image,ua.first_name AS assignee_first_name,ua.last_name AS assignee_last_name,ua.mobile AS assignee_mobile,uc.first_name AS creator_first_name,uc.last_name AS creator_last_name,uc.mobile AS creator_mobile,cc.permissions AS my_task_permissions,(SELECT COUNT(*) FROM task_notes tn WHERE tn.task_id=t.id) AS notes_count,(SELECT COUNT(*) FROM task_attachments ta WHERE ta.task_id=t.id) AS attachments_count FROM tasks t LEFT JOIN subjects s ON t.subject_id=s.id LEFT JOIN projects p ON t.project_id=p.id LEFT JOIN users ua ON ua.id=t.assignee_id LEFT JOIN users uc ON uc.id=t.user_id LEFT JOIN colleagues cc ON cc.user_id=t.user_id AND cc.colleague_user_id=? WHERE t.user_id=? OR t.assignee_id=? ORDER BY t.created_at DESC");
    $s->execute([$userId, $userId, $userId]);
    $tasks = $s->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
}
$myTasksAll = array_values(array_filter($tasks, fn($t) => (int)($t['assignee_id'] ?? 0) === $userId));
$othersTasksAll = array_values(array_filter($tasks, fn($t) => (int)$t['user_id'] === $userId && (int)($t['assignee_id'] ?? 0) !== $userId));
$myTasksUncompleted = array_values(array_filter($myTasksAll, fn($t) => !$t['is_completed']));
$myTasksCompleted = array_values(array_filter($myTasksAll, fn($t) => (bool)$t['is_completed']));
$othersTasksUncompleted = array_values(array_filter($othersTasksAll, fn($t) => !$t['is_completed']));
$othersTasksCompleted = array_values(array_filter($othersTasksAll, fn($t) => (bool)$t['is_completed']));
list($myTasksUncompletedPaged, $pageMineUncompleted, $totalPagesMineUncompleted, $totalCountMineUncompleted) = paginateArray($myTasksUncompleted, getPageParam('p_mu'), $TASKS_PER_PAGE);
list($myTasksCompletedPaged, $pageMineCompleted, $totalPagesMineCompleted, $totalCountMineCompleted) = paginateArray($myTasksCompleted, getPageParam('p_mc'), $TASKS_PER_PAGE);
list($othersTasksUncompletedPaged, $pageOthersUncompleted, $totalPagesOthersUncompleted, $totalCountOthersUncompleted) = paginateArray($othersTasksUncompleted, getPageParam('p_ou'), $TASKS_PER_PAGE);
list($othersTasksCompletedPaged, $pageOthersCompleted, $totalPagesOthersCompleted, $totalCountOthersCompleted) = paginateArray($othersTasksCompleted, getPageParam('p_oc'), $TASKS_PER_PAGE);
$displayUser = $_SESSION['user_mobile'] ?? $_SESSION['mobile'] ?? $_SESSION['username'] ?? 'کاربر';
$makeAvatarUrl = function ($f) {
    if (empty($f)) return null;
    $b = basename($f);
    if (!preg_match('/^[A-Za-z0-9_.\-]+$/', $b)) return null;
    if (file_exists(__DIR__ . '/../uploads/avatars/' . $b)) return '../uploads/avatars/' . rawurlencode($b);
    return null;
};
$makeProjectImageUrl = function ($f) {
    if (empty($f)) return null;
    $b = basename($f);
    if (!preg_match('/^[A-Za-z0-9_.\-]+$/', $b)) return null;
    if (file_exists(__DIR__ . '/../uploads/projects/' . $b)) return '../uploads/projects/' . rawurlencode($b);
    return null;
};

$sharedTaskData = null;
$sharedCanEdit = $sharedCanReassign = $sharedCanChangeProject = $sharedCanComplete = false;
if ($sharedTaskParam !== '' && preg_match('/^[a-f0-9]{16,64}$/i', $sharedTaskParam)) {
    try {
        $s = $db->prepare("SELECT t.*,s.title AS subject_title,p.title AS project_title,p.profile_image AS project_image,ua.first_name AS assignee_first_name,ua.last_name AS assignee_last_name,ua.mobile AS assignee_mobile,uc.first_name AS creator_first_name,uc.last_name AS creator_last_name,uc.mobile AS creator_mobile,(SELECT COUNT(*) FROM task_attachments ta WHERE ta.task_id=t.id) AS attachments_count FROM tasks t LEFT JOIN subjects s ON t.subject_id=s.id LEFT JOIN projects p ON t.project_id=p.id LEFT JOIN users ua ON ua.id=t.assignee_id LEFT JOIN users uc ON uc.id=t.user_id WHERE t.share_token=? LIMIT 1");
        $s->execute([$sharedTaskParam]);
        $st2 = $s->fetch(PDO::FETCH_ASSOC);
        if ($st2) {
            $ha = false;
            if ((int)$st2['user_id'] === $userId) $ha = true;
            else if (!empty($st2['project_id'])) {
                $c = $db->prepare("SELECT 1 FROM project_members WHERE project_id=? AND user_id=? LIMIT 1");
                $c->execute([(int)$st2['project_id'], $userId]);
                if ($c->fetchColumn()) $ha = true;
            }
            if ($ha) {
                $hp = !empty($st2['project_id']) && !empty($st2['project_title']);
                $pi = $hp ? mb_substr(trim($st2['project_title']), 0, 1, 'UTF-8') : '';
                $pim = $hp ? $makeProjectImageUrl($st2['project_image'] ?? null) : null;
                $an = trim(($st2['assignee_first_name'] ?? '') . ' ' . ($st2['assignee_last_name'] ?? ''));
                $cn = trim(($st2['creator_first_name'] ?? '') . ' ' . ($st2['creator_last_name'] ?? ''));
                if ($an === '') $an = $st2['assignee_mobile'] ?? '';
                if ($cn === '') $cn = $st2['creator_mobile'] ?? 'کاربر';
                $pl = ['low' => 'کم', 'medium' => 'متوسط', 'high' => 'زیاد'];
                $sharedTaskData = ['id' => (int)$st2['id'], 'title' => (string)$st2['title'], 'subject_id' => $st2['subject_id'], 'subject_title' => (string)($st2['subject_title'] ?? ''), 'project_id' => $st2['project_id'], 'project_title' => (string)($st2['project_title'] ?? ''), 'project_image_url' => $pim, 'project_initial' => $pi, 'assignee_id' => (int)$st2['assignee_id'], 'assignee_name' => $an, 'creator_name' => $cn, 'priority' => (string)$st2['priority'], 'priority_label' => $pl[$st2['priority']] ?? $st2['priority'], 'due_date' => $st2['due_date'] ? formatPersianDateOnly($st2['due_date'] . ' 00:00:00') : '', 'due_date_raw' => $st2['due_date'], 'due_time_raw' => $st2['due_time'] ?? '', 'description' => (string)($st2['description'] ?? ''), 'attachments_count' => (int)($st2['attachments_count'] ?? 0)];
                if ((int)$st2['user_id'] === $userId) $sharedCanEdit = $sharedCanReassign = $sharedCanChangeProject = $sharedCanComplete = true;
                else {
                    $s = $db->prepare("SELECT permissions FROM colleagues WHERE user_id=? AND colleague_user_id=? LIMIT 1");
                    $s->execute([(int)$st2['user_id'], $userId]);
                    $pr = $s->fetch(PDO::FETCH_ASSOC);
                    if ($pr) {
                        $p = json_decode($pr['permissions'] ?? '[]', true);
                        if (is_array($p)) {
                            $sharedCanEdit = in_array('edit', $p, true);
                            $sharedCanReassign = in_array('reassign', $p, true);
                            $sharedCanChangeProject = in_array('change_project', $p, true);
                            $sharedCanComplete = in_array('complete', $p, true);
                        }
                    }
                }
            } else {
                $_SESSION['flash_msg'] = 'شما به این وظیفه دسترسی ندارید.';
                $_SESSION['flash_type'] = 'danger';
                header('Location: index.php?page=list');
                exit;
            }
        } else {
            $_SESSION['flash_msg'] = 'وظیفه یافت نشد.';
            $_SESSION['flash_type'] = 'danger';
            header('Location: index.php?page=list');
            exit;
        }
    } catch (PDOException $e) {
    }
}

$assigneeOptionsJson = safeJsonEncode(['self' => ['id' => $userId, 'name' => 'خودم', 'mobile' => $displayUser, 'initial' => mb_substr($currentUserFirstName, 0, 1, 'UTF-8'), 'avatar_url' => $currentUserAvatar, 'is_self' => true], 'colleagues' => array_map(function ($c) use ($makeAvatarUrl) {
    $fn = trim(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? ''));
    if ($fn === '') $fn = $c['mobile'];
    return ['id' => (int)$c['id'], 'name' => $fn, 'mobile' => $c['mobile'], 'initial' => mb_substr(trim($c['first_name'] ?: $c['mobile']), 0, 1, 'UTF-8'), 'avatar_url' => $makeAvatarUrl($c['avatar'] ?? null), 'is_self' => false];
}, $colleaguesList)]);
$projectOptionsJson = safeJsonEncode(array_map(function ($p) use ($makeProjectImageUrl) {
    $t = $p['title'] ?? '';
    return ['id' => (int)$p['id'], 'title' => $t, 'initial' => mb_substr(trim($t), 0, 1, 'UTF-8'), 'image_url' => $makeProjectImageUrl($p['profile_image'] ?? null), 'is_creator' => (bool)$p['is_creator']];
}, $userProjects));
$projectsMembersJson = safeJsonEncode($projectsMembersMap);
$mentionOptions = [];
$smi = [];
foreach ($colleaguesList as $c) {
    $cid = (int)$c['id'];
    if ($cid === $userId || isset($smi[$cid])) continue;
    $fn = trim(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? ''));
    if ($fn === '') $fn = $c['mobile'];
    $mentionOptions[] = ['id' => $cid, 'name' => $fn, 'initial' => mb_substr(trim($c['first_name'] ?: $c['mobile']), 0, 1, 'UTF-8'), 'avatar_url' => $makeAvatarUrl($c['avatar'] ?? null)];
    $smi[$cid] = true;
}
foreach ($projectsMembersMap as $pid => $members) foreach ($members as $m) {
    $mid = (int)$m['id'];
    if ($mid === $userId || isset($smi[$mid])) continue;
    $mentionOptions[] = ['id' => $mid, 'name' => $m['name'], 'initial' => $m['initial'], 'avatar_url' => $m['avatar_url']];
    $smi[$mid] = true;
}
$mentionOptionsJson = safeJsonEncode($mentionOptions);
$formTokenTask = formToken('task');
$formTokenSubject = formToken('subject');
$allowedExtsForUi = getAllowedAttachmentRules()['ext'];
$allowedExtsAttr = implode(',', array_map(fn($e) => '.' . $e, $allowedExtsForUi));
if (ob_get_level() > 0) ob_end_flush();
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
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/persian-datepicker@1.2.0/dist/css/persian-datepicker.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/persian-date@1.1.0/dist/persian-date.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/persian-datepicker@1.2.0/dist/js/persian-datepicker.min.js"></script>
    <style>
        :root {
            --accent: #4c8bf5;
            --bg: #f4f6fb;
            --card: #fff;
            --text: #2b3452;
            --text-soft: #8a94ad;
            --border: #eef1f8;
            --success: #2ebc8a;
            --warning: #ff8a3d;
            --danger: #f54e7a;
            --shadow: 0 8px 24px rgba(76, 108, 200, .06);
        }

        * {
            box-sizing: border-box;
        }

        html,
        body {
            max-width: 100%;
            overflow-x: hidden;
        }

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
            background: linear-gradient(180deg, #4c8bf5, #2f6bdc) !important;
            color: #fff !important;
        }

        .main-wrapper {
            flex: 1;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            min-width: 0;
        }

        .topbar {
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
            line-height: 1.4;
        }

        .topbar h1 small {
            display: block;
            font-size: .78rem;
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
            font-size: .85rem;
            font-weight: 600;
        }

        .topbar-user .avatar {
            width: 34px;
            height: 34px;
            border-radius: 50%;
            background: linear-gradient(135deg, #4c8bf5, #2f6bdc);
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: .85rem;
            overflow: hidden;
            flex-shrink: 0;
        }

        .topbar-user .avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .content-area {
            flex: 1;
            padding: 18px 32px 32px;
            overflow-y: auto;
            overflow-x: hidden;
            -webkit-overflow-scrolling: touch;
        }

        .flash {
            padding: 12px 16px;
            border-radius: 12px;
            margin-bottom: 18px;
            font-weight: 600;
            font-size: .88rem;
        }

        .flash-success {
            background: #e3f8ef;
            color: #0e7a55;
            border: 1px solid #a7f3d0;
        }

        .flash-warning {
            background: #fff3e0;
            color: #92400e;
            border: 1px solid #fde68a;
        }

        .flash-danger {
            background: #ffe4ec;
            color: #991b1b;
            border: 1px solid #fecaca;
        }

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
            font-size: .92rem;
            cursor: pointer;
            color: var(--text-soft);
            border-radius: 12px;
            font-family: inherit;
            display: inline-flex;
            align-items: center;
            gap: 9px;
        }

        .main-tab-btn.active {
            background: linear-gradient(135deg, #4c8bf5, #2f6bdc);
            color: #fff;
            box-shadow: 0 6px 16px rgba(76, 139, 245, .3);
        }

        .main-tab-badge {
            background: #f1f4fb;
            color: var(--text-soft);
            padding: 2px 9px;
            border-radius: 8px;
            font-size: .7rem;
            font-weight: 700;
        }

        .main-tab-btn.active .main-tab-badge {
            background: rgba(255, 255, 255, .25);
            color: #fff;
        }

        .main-tab-content {
            display: none;
        }

        .main-tab-content.active {
            display: block;
        }

        .pagination {
            display: flex;
            gap: 6px;
            justify-content: center;
            align-items: center;
            margin: 18px 0 8px;
            flex-wrap: wrap;
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
            font-size: .85rem;
            text-decoration: none;
            border: 1px solid var(--border);
            cursor: pointer;
        }

        .page-btn.active {
            background: linear-gradient(135deg, #4c8bf5, #2f6bdc);
            color: #fff;
            border-color: #4c8bf5;
        }

        .page-btn.disabled {
            opacity: .4;
            pointer-events: none;
        }

        .page-info {
            color: var(--text-soft);
            font-size: .75rem;
            font-weight: 600;
            padding: 0 8px;
        }

        .page-dots {
            color: var(--text-soft);
            padding: 0 6px;
            font-weight: 700;
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
            box-shadow: 0 12px 28px rgba(0, 0, 0, .08);
        }

        .hero-card::before {
            content: '';
            position: absolute;
            top: -40px;
            left: -40px;
            width: 140px;
            height: 140px;
            background: rgba(255, 255, 255, .14);
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
            width: 44px;
            height: 44px;
            border-radius: 13px;
            background: rgba(255, 255, 255, .24);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
        }

        .hero-card__bottom {
            position: relative;
            z-index: 2;
        }

        .hero-card__value {
            font-size: 2.1rem;
            font-weight: 800;
            line-height: 1;
        }

        .hero-card__label {
            font-size: .8rem;
            opacity: .92;
            margin-top: 6px;
        }

        .card-red {
            background: linear-gradient(135deg, #ff6b8b, #f54e7a);
        }

        .card-orange {
            background: linear-gradient(135deg, #ffa751, #ff8a3d);
        }

        .card-purple {
            background: linear-gradient(135deg, #a26bfa, #7f4cf0);
        }

        .card-green {
            background: linear-gradient(135deg, #4ed4a3, #2ebc8a);
        }

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
            font-size: .95rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 10px;
            margin: 0;
        }

        .chart-head h3 i {
            width: 32px;
            height: 32px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: .8rem;
        }

        .chart-head h3 i.blue {
            background: #e7f0ff;
            color: #4c8bf5;
        }

        .chart-head h3 i.green {
            background: #e3f8ef;
            color: #2ebc8a;
        }

        .chart-head h3 i.purple {
            background: #f0e9ff;
            color: #7f4cf0;
        }

        .chart-head h3 i.orange {
            background: #fff3e0;
            color: #ff8a3d;
        }

        .chart-head .legend {
            font-size: .72rem;
            color: var(--text-soft);
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .chart-head .legend .dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #4c8bf5;
        }

        .chart-head .legend .dot.green {
            background: #2ebc8a;
        }

        .chart-body {
            height: 240px;
            position: relative;
        }

        .section-label {
            margin: 26px 0 12px;
            font-size: .88rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .section-label .section-hint {
            font-size: .7rem;
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
            box-shadow: 0 4px 16px rgba(76, 108, 200, .05);
            border: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }

        .soft-card__label {
            font-size: .75rem;
            color: var(--text-soft);
            margin-bottom: 4px;
        }

        .soft-card__value {
            font-size: 1.35rem;
            font-weight: 700;
            line-height: 1.1;
        }

        .soft-card__icon {
            width: 42px;
            height: 42px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: .95rem;
            flex-shrink: 0;
        }

        .soft-card__icon.blue {
            background: #e7f0ff;
            color: #4c8bf5;
        }

        .soft-card__icon.green {
            background: #e3f8ef;
            color: #2ebc8a;
        }

        .soft-card__icon.orange {
            background: #fff3e0;
            color: #ff8a3d;
        }

        .soft-card__icon.purple {
            background: #f0e9ff;
            color: #7f4cf0;
        }

        .soft-card__icon.pink {
            background: #ffe4ec;
            color: #f54e7a;
        }

        .soft-card__icon.yellow {
            background: #fff7d6;
            color: #ca8a04;
        }

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
            font-size: .92rem;
        }

        .time-card-count {
            font-size: .72rem;
            color: var(--text-soft);
            background: #f4f6fb;
            padding: 4px 10px;
            border-radius: 8px;
            font-weight: 700;
        }

        .time-card-main {
            text-align: center;
            padding: 14px 0;
            border-top: 1px dashed #e6eaf3;
            border-bottom: 1px dashed #e6eaf3;
        }

        .time-card-main-label {
            font-size: .75rem;
            color: var(--text-soft);
            margin-bottom: 8px;
        }

        .time-card-main-value {
            font-size: 1.5rem;
            font-weight: 800;
            line-height: 1.2;
        }

        .time-card-footer {
            display: flex;
            justify-content: space-between;
            font-size: .8rem;
        }

        .time-card-footer>div {
            text-align: center;
            flex: 1;
        }

        .time-card-footer .label {
            color: var(--text-soft);
            margin-bottom: 4px;
            font-size: .72rem;
        }

        .time-card-footer .value {
            font-weight: 700;
            font-size: .88rem;
        }

        .time-card-divider {
            width: 1px;
            background: #eef1f8;
        }

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
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 18px;
        }

        .progress-item {
            margin-bottom: 18px;
        }

        .progress-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
            font-size: .85rem;
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

        .summary-icon {
            width: 34px;
            height: 34px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            font-size: .85rem;
        }

        .summary-label {
            font-size: .85rem;
            font-weight: 600;
        }

        .summary-value {
            font-weight: 700;
            font-size: 1rem;
        }

        .form-group {
            margin-bottom: 18px;
        }

        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            font-size: .88rem;
        }

        input[type="text"],
        select,
        textarea {
            width: 100%;
            padding: 12px 16px;
            background: #fafbfe;
            border: 2px solid var(--border);
            border-radius: 14px;
            font-size: .92rem;
            font-family: inherit;
            color: var(--text);
        }

        input[type="text"]:focus,
        select:focus,
        textarea:focus {
            outline: none;
            border-color: var(--accent);
            background: #fff;
            box-shadow: 0 0 0 4px rgba(76, 139, 245, .12);
        }

        button.btn {
            background: linear-gradient(135deg, #4c8bf5, #2f6bdc);
            color: #fff;
            border: none;
            padding: 11px 22px;
            border-radius: 12px;
            font-weight: 700;
            cursor: pointer;
            font-family: inherit;
            font-size: .9rem;
            box-shadow: 0 6px 16px rgba(76, 139, 245, .25);
        }

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
            font-size: .88rem;
            cursor: pointer;
            color: var(--text-soft);
            border-radius: 12px;
            font-family: inherit;
        }

        .tab-btn.active {
            background: linear-gradient(135deg, #4c8bf5, #2f6bdc);
            color: #fff;
            box-shadow: 0 4px 12px rgba(76, 139, 245, .25);
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

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
            cursor: pointer;
            box-shadow: 0 2px 8px rgba(76, 108, 200, .03);
        }

        .task-row:hover {
            border-color: #d8e0f0;
            box-shadow: 0 4px 12px rgba(76, 108, 200, .06);
        }

        .task-row.animating {
            opacity: .4;
        }

        .task-row.no-edit {
            cursor: default;
        }

        .task-row.received-task {
            background: #fffdf5;
        }

        .task-row.assigned-task {
            background: #fafaff;
        }

        .task-row.received-task,
        .task-row.assigned-task {
            padding-right: 26px;
        }

        .task-row.overdue-task {
            background: #fff8fa !important;
            border-color: #fecdd3 !important;
        }

        .task-row.overdue-task .task-title {
            color: #881337;
        }

        .task-info {
            display: flex;
            align-items: flex-start;
            gap: 16px;
            min-width: 0;
        }

        .custom-checkbox {
            width: 22px;
            height: 22px;
            cursor: pointer;
            accent-color: var(--success);
            flex-shrink: 0;
            margin-top: 2px;
        }

        .task-title {
            font-weight: 700;
            font-size: .95rem;
        }

        .task-row.completed-task .task-title {
            text-decoration: line-through;
            color: #b0b8cd;
        }

        .task-desc-clamp {
            font-size: .8rem;
            color: var(--text-soft);
            margin-top: 4px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 100%;
        }

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
            border-radius: 8px;
            font-size: .72rem;
            font-weight: 600;
            white-space: nowrap;
        }

        .task-meta i {
            font-size: .68rem;
        }

        .task-meta.task-meta-created {
            background: #f4f6fb;
            color: #64748b;
        }

        .task-meta.task-meta-due {
            background: #e7f0ff;
            color: #2f6bdc;
        }

        .task-meta.task-meta-due.overdue {
            background: #ffe4ec;
            color: #c81e4a;
            font-weight: 700;
        }

        .task-meta.task-meta-completed {
            background: #e3f8ef;
            color: #0e7a55;
        }

        .overdue-badge {
            display: inline-flex;
            align-items: center;
            gap: 3px;
            background: linear-gradient(135deg, #ff6b8b, #f54e7a);
            color: #fff;
            padding: 1px 7px;
            border-radius: 6px;
            font-size: .65rem;
            font-weight: 700;
            margin-right: 3px;
        }

        .task-badges {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            margin-right: 8px;
            vertical-align: middle;
        }

        .task-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            font-size: .7rem;
            font-weight: 700;
            padding: 3px 8px;
            border-radius: 8px;
        }

        .task-badge.badge-notes {
            background: #f0e9ff;
            color: #7f4cf0;
        }

        .task-badge.badge-files {
            background: #e0f5fb;
            color: #0891b2;
        }

        .task-info-btn {
            background: #e7f0ff;
            color: #2f6bdc;
            border: 1px solid #c7d7ff;
            width: 34px;
            height: 34px;
            border-radius: 10px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            font-family: inherit;
            padding: 0;
        }

        .task-info-btn:hover {
            background: #2f6bdc;
            color: #fff;
        }

        .action-link-delete {
            background: #ffe4ec;
            color: #c81e4a;
            padding: 7px 10px;
            border-radius: 10px;
            border: 1px solid #ffc9d8;
            cursor: pointer;
            font-family: inherit;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 34px;
            height: 34px;
        }

        .action-link-delete:hover {
            background: #c81e4a;
            color: #fff;
        }

        .subject-edit-btn {
            background: #e7f0ff;
            color: #2f6bdc;
            padding: 7px 10px;
            border-radius: 10px;
            border: 1px solid #c7d7ff;
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
        }

        .task-color-bar {
            position: absolute;
            top: 14px;
            bottom: 14px;
            right: 0;
            width: 5px;
            border-radius: 6px 0 0 6px;
            z-index: 3;
        }

        .task-color-bar.bar-received {
            background: linear-gradient(180deg, #ffa751, #ff8a3d);
        }

        .task-color-bar.bar-sent {
            background: linear-gradient(180deg, #a26bfa, #7f4cf0);
        }

        .task-tooltip {
            position: absolute;
            top: 50%;
            right: 22px;
            transform: translateY(-50%);
            background: #2b3452;
            color: #fff;
            padding: 10px 14px;
            border-radius: 12px;
            font-size: .78rem;
            white-space: nowrap;
            opacity: 0;
            pointer-events: none;
            z-index: 100;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .task-tooltip::after {
            content: '';
            position: absolute;
            top: 50%;
            left: 100%;
            transform: translateY(-50%);
            border: 7px solid transparent;
            border-left-color: #2b3452;
        }

        .task-color-bar:hover~.task-tooltip {
            opacity: 1;
        }

        .task-tooltip .tip-icon {
            width: 26px;
            height: 26px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: .72rem;
            flex-shrink: 0;
        }

        .task-tooltip.tip-received .tip-icon {
            background: rgba(255, 167, 81, .25);
            color: #ffa751;
        }

        .task-tooltip.tip-sent .tip-icon {
            background: rgba(162, 107, 250, .25);
            color: #b78cff;
        }

        .task-tooltip .tip-label {
            color: #b0b8cd;
            font-size: .7rem;
            margin-bottom: 2px;
        }

        .task-tooltip .tip-name {
            color: #fff;
            font-weight: 700;
        }

        .task-tooltip .tip-mobile {
            color: #b0b8cd;
            font-size: .7rem;
            direction: ltr;
            display: inline-block;
            margin-right: 5px;
        }

        .modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(20, 30, 60, .6);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 1000;
            padding: 15px;
        }

        .modal-overlay.active {
            display: flex;
        }

        .modal-box {
            background: #fff;
            width: 100%;
            max-width: 520px;
            border-radius: 22px;
            padding: 28px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 24px 60px rgba(20, 30, 60, .25);
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
            flex-shrink: 0;
            gap: 10px;
        }

        .task-modal-header h3 {
            margin: 0;
            font-size: 1.02rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .task-modal-header h3 .tm-header-icon {
            width: 34px;
            height: 34px;
            border-radius: 10px;
            background: linear-gradient(135deg, #4c8bf5, #2f6bdc);
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: .85rem;
            flex-shrink: 0;
        }

        .task-modal-header h3 .tm-header-icon.tm-edit-icon {
            background: linear-gradient(135deg, #a26bfa, #7f4cf0);
        }

        .task-modal-header h3 .tm-id-badge {
            background: #f1f4fb;
            color: var(--text-soft);
            font-size: .72rem;
            padding: 3px 9px;
            border-radius: 8px;
            font-weight: 700;
        }

        .tm-header-actions {
            display: flex;
            align-items: center;
            gap: 6px;
            margin-right: auto;
        }

        .tm-header-share {
            width: 34px;
            height: 34px;
            border-radius: 10px;
            background: #e7f0ff;
            border: 1px solid #c7d7ff;
            color: #2f6bdc;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-family: inherit;
            padding: 0;
        }

        .tm-header-share:hover {
            background: #2f6bdc;
            color: #fff;
        }

        .tm-header-share.copied {
            background: #e3f8ef;
            color: #0e7a55;
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
        }

        .tm-field {
            margin-bottom: 16px;
        }

        .tm-label {
            display: flex;
            align-items: center;
            gap: 7px;
            font-size: .78rem;
            font-weight: 700;
            color: var(--text-soft);
            margin-bottom: 8px;
        }

        .tm-label-icon {
            font-size: .72rem;
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
            font-size: .9rem;
            font-family: inherit;
            color: var(--text);
        }

        .tm-input:focus {
            outline: none;
            border-color: var(--accent);
            background: #fff;
            box-shadow: 0 0 0 4px rgba(76, 139, 245, .12);
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
            font-size: .75rem;
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
            font-size: .8rem;
            font-weight: 700;
            color: var(--text-soft);
        }

        .tm-priority-opt input:checked+span {
            background: linear-gradient(135deg, #4c8bf5, #2f6bdc);
            border-color: var(--accent);
            color: #fff;
        }

        .tm-priority-opt[data-priority="low"] input:checked+span {
            background: linear-gradient(135deg, #4ed4a3, #2ebc8a);
            border-color: var(--success);
        }

        .tm-priority-opt[data-priority="high"] input:checked+span {
            background: linear-gradient(135deg, #ff6b8b, #f54e7a);
            border-color: var(--danger);
        }

        .tm-priority-group.field-locked {
            opacity: .65;
            pointer-events: none;
        }

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
            font-size: .9rem !important;
            font-family: 'Segoe UI', Tahoma, sans-serif !important;
            color: var(--text) !important;
            cursor: pointer;
            text-align: right;
            direction: rtl;
        }

        .date-display-input:focus {
            outline: none !important;
            border-color: var(--accent) !important;
            background: #fff !important;
            box-shadow: 0 0 0 4px rgba(76, 139, 245, .12) !important;
        }

        .date-picker-icon {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: #fff;
            pointer-events: none;
            width: 30px;
            height: 30px;
            background: linear-gradient(135deg, #4c8bf5, #2f6bdc);
            border-radius: 9px;
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 2;
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
            font-size: .62rem;
            padding: 0;
            z-index: 3;
        }

        .date-picker-wrap.has-value .date-clear-btn {
            display: inline-flex;
        }

        .date-clear-btn:hover {
            background: #c81e4a;
            color: #fff;
        }

        .time-input {
            direction: ltr;
            text-align: center;
            font-family: 'Segoe UI', Tahoma, sans-serif !important;
            letter-spacing: 2px;
            font-weight: 700;
            color: var(--text);
            font-variant-numeric: tabular-nums;
        }

        .time-input::-webkit-calendar-picker-indicator {
            cursor: pointer;
            opacity: .65;
        }

        /* ⭐ تقویم — اعداد انگلیسی + اصلاح رنگ امروز */
        .datepicker-plot-area,
        .datepicker-plot-area * {
            font-family: 'Segoe UI', Tahoma, sans-serif !important;
            font-feature-settings: "tnum" 1;
        }

        .datepicker-plot-area .table-days td span,
        .datepicker-plot-area .datepicker-navigator .pwt-btn,
        .datepicker-plot-area .datepicker-month,
        .datepicker-plot-area .datepicker-year {
            font-family: 'Segoe UI', Tahoma, sans-serif !important;
            direction: ltr;
        }

        .datepicker-plot-area {
            z-index: 2147483647 !important;
            border-radius: 16px !important;
            box-shadow: 0 24px 60px rgba(20, 30, 60, .22) !important;
            border: 1px solid var(--border) !important;
            background: #fff !important;
            padding: 8px !important;
            direction: rtl;
            min-width: 260px;
            margin-top: 6px;
        }

        .datepicker-plot-area .datepicker-header {
            border-bottom: 1px solid var(--border);
            padding: 8px 4px 12px;
            margin-bottom: 8px;
        }

        .datepicker-plot-area .datepicker-navigator .pwt-btn {
            background: #e7f0ff !important;
            color: #2f6bdc !important;
            border-radius: 10px !important;
            font-weight: 700;
            height: 30px;
            line-height: 30px;
            padding: 0 10px;
            border: none !important;
        }

        .datepicker-plot-area .datepicker-navigator .pwt-btn:hover {
            background: linear-gradient(135deg, #4c8bf5, #2f6bdc) !important;
            color: #fff !important;
        }

        .datepicker-plot-area .datepicker-navigator .pwt-btn-switch {
            background: linear-gradient(135deg, #4c8bf5, #2f6bdc) !important;
            color: #fff !important;
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
            font-size: .82rem;
            color: var(--text);
            font-weight: 600;
            cursor: pointer;
            background: transparent !important;
            box-shadow: none !important;
        }

        .datepicker-plot-area .table-days td span:hover {
            background: #e7f0ff !important;
            color: #2f6bdc !important;
        }

        /* ⭐ امروز (all states) */
        .datepicker-plot-area .table-days td.today span,
        .datepicker-plot-area .table-days td.today span:hover,
        .datepicker-plot-area .table-days td.today span:focus,
        .datepicker-plot-area .table-days td.today span:active {
            border: 2px solid #4c8bf5 !important;
            color: #2f6bdc !important;
            font-weight: 800 !important;
            background: #f0f6ff !important;
            box-shadow: none !important;
        }

        /* ⭐ روز انتخاب‌شده */
        .datepicker-plot-area .table-days td.selected span,
        .datepicker-plot-area .table-days td.selected span:hover,
        .datepicker-plot-area .table-days td.selected span:focus,
        .datepicker-plot-area .table-days td.selected span:active {
            background: linear-gradient(135deg, #4c8bf5, #2f6bdc) !important;
            color: #fff !important;
            border: 2px solid #4c8bf5 !important;
            box-shadow: 0 4px 10px rgba(76, 139, 245, .35) !important;
            font-weight: 700 !important;
        }

        /* ⭐ امروز + انتخاب‌شده */
        .datepicker-plot-area .table-days td.today.selected span,
        .datepicker-plot-area .table-days td.today.selected span:hover,
        .datepicker-plot-area .table-days td.today.selected span:focus {
            background: linear-gradient(135deg, #4c8bf5, #2f6bdc) !important;
            color: #fff !important;
            border-color: #fff !important;
            box-shadow: 0 4px 10px rgba(76, 139, 245, .4) !important;
        }

        /* ⭐ غیرفعال */
        .datepicker-plot-area .table-days td.disabled span,
        .datepicker-plot-area .table-days td.disabled span:hover {
            color: #cbd5e1 !important;
            cursor: not-allowed !important;
            background: transparent !important;
            border: none !important;
        }

        /* ⭐ حذف خط‌های اضافی از پلاگین */
        .datepicker-plot-area .table-days td.selected,
        .datepicker-plot-area .table-days td.today {
            background: transparent !important;
        }

        .datepicker-plot-area .datepicker-title {
            display: none;
        }

        .datepicker-plot-area .toolbox {
            padding-top: 8px;
            border-top: 1px solid var(--border);
            display: flex;
            gap: 6px;
            justify-content: center;
        }

        .datepicker-plot-area .toolbox .pwt-btn-today,
        .datepicker-plot-area .toolbox .pwt-btn-close {
            background: #e3f8ef !important;
            color: #0e7a55 !important;
            border-radius: 10px !important;
            padding: 6px 14px !important;
            font-weight: 700;
            font-size: .78rem;
            border: none !important;
            cursor: pointer;
        }

        .datepicker-plot-area .toolbox .pwt-btn-close {
            background: #ffe4ec !important;
            color: #c81e4a !important;
        }

        .file-upload-zone {
            position: relative;
            border: 2px dashed #c7d7ff;
            background: #f7faff;
            border-radius: 12px;
            padding: 18px 16px;
            text-align: center;
            cursor: pointer;
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
            width: 44px;
            height: 44px;
            border-radius: 12px;
            background: linear-gradient(135deg, #4c8bf5, #2f6bdc);
            color: #fff;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
            margin-bottom: 8px;
        }

        .file-upload-zone__title {
            font-size: .86rem;
            font-weight: 700;
            margin-bottom: 4px;
        }

        .file-upload-zone__hint {
            font-size: .72rem;
            color: var(--text-soft);
            line-height: 1.7;
        }

        .file-upload-zone__hint code {
            background: #e7f0ff;
            color: #2f6bdc;
            padding: 1px 5px;
            border-radius: 5px;
            font-size: .7rem;
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
            font-size: .78rem;
        }

        .file-preview-item__icon {
            width: 30px;
            height: 30px;
            border-radius: 8px;
            background: #e7f0ff;
            color: #2f6bdc;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: .8rem;
            flex-shrink: 0;
        }

        .file-preview-item__icon.is-pdf {
            background: #ffe4ec;
            color: #c81e4a;
        }

        .file-preview-item__icon.is-excel {
            background: #e3f8ef;
            color: #0e7a55;
        }

        .file-preview-item__icon.is-word {
            background: #e7f0ff;
            color: #2f6bdc;
        }

        .file-preview-item__icon.is-image {
            background: #fff3e0;
            color: #b45309;
        }

        .file-preview-item__icon.is-zip {
            background: #f0e9ff;
            color: #7f4cf0;
        }

        .file-preview-item__info {
            flex: 1;
            min-width: 0;
        }

        .file-preview-item__name {
            font-weight: 700;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .file-preview-item__meta {
            font-size: .68rem;
            color: var(--text-soft);
            margin-top: 2px;
        }

        .file-preview-item__remove {
            background: transparent;
            border: none;
            color: var(--text-soft);
            cursor: pointer;
            width: 26px;
            height: 26px;
            border-radius: 7px;
            font-family: inherit;
            display: inline-flex;
            align-items: center;
            justify-content: center;
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
            font-size: .85rem;
            font-weight: 700;
        }

        .modal-attachments-header .ma-icon {
            width: 30px;
            height: 30px;
            border-radius: 9px;
            background: linear-gradient(135deg, #4c8bf5, #2f6bdc);
            color: #fff;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: .78rem;
            flex-shrink: 0;
        }

        .modal-attachments-header .ma-count {
            margin-right: auto;
            background: #e7f0ff;
            color: #2f6bdc;
            font-size: .7rem;
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
        }

        .modal-attachment-item__icon {
            width: 34px;
            height: 34px;
            border-radius: 9px;
            background: #e7f0ff;
            color: #2f6bdc;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: .85rem;
            flex-shrink: 0;
        }

        .modal-attachment-item__icon.is-pdf {
            background: #ffe4ec;
            color: #c81e4a;
        }

        .modal-attachment-item__icon.is-excel {
            background: #e3f8ef;
            color: #0e7a55;
        }

        .modal-attachment-item__icon.is-word {
            background: #e7f0ff;
            color: #2f6bdc;
        }

        .modal-attachment-item__icon.is-image {
            background: #fff3e0;
            color: #b45309;
        }

        .modal-attachment-item__icon.is-zip {
            background: #f0e9ff;
            color: #7f4cf0;
        }

        .modal-attachment-item__body {
            flex: 1;
            min-width: 0;
        }

        .modal-attachment-item__name {
            font-size: .82rem;
            font-weight: 700;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .modal-attachment-item__name a {
            color: var(--text);
            text-decoration: none;
        }

        .modal-attachment-item__meta {
            font-size: .68rem;
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
            width: 28px;
            height: 28px;
            border-radius: 8px;
            font-family: inherit;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
        }

        .modal-attachment-item__actions .att-delete:hover {
            background: #ffe4ec;
            color: #c81e4a;
        }

        .modal-attachments-empty,
        .modal-attachments-loading {
            text-align: center;
            font-size: .78rem;
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
            font-size: .88rem;
        }

        .tm-btn-save {
            background: linear-gradient(135deg, #4c8bf5, #2f6bdc);
            border: none;
            color: #fff;
            padding: 11px 24px;
            border-radius: 12px;
            font-weight: 700;
            cursor: pointer;
            font-family: inherit;
            font-size: .88rem;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 6px 16px rgba(76, 139, 245, .25);
        }

        .tm-btn-save:disabled {
            opacity: .6;
            cursor: not-allowed;
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
            font-size: .92rem;
            font-weight: 700;
            margin-bottom: 14px;
        }

        .modal-notes-header .modal-notes-header-icon {
            width: 32px;
            height: 32px;
            border-radius: 10px;
            background: linear-gradient(135deg, #a26bfa, #7f4cf0);
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: .8rem;
        }

        .modal-notes-header .modal-notes-header-count {
            margin-right: auto;
            background: #f0e9ff;
            color: #7f4cf0;
            font-size: .72rem;
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
        }

        .modal-notes-empty {
            text-align: center;
            color: var(--text-soft);
            font-size: .8rem;
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
        }

        .modal-note-avatar {
            width: 34px;
            height: 34px;
            border-radius: 50%;
            background: linear-gradient(135deg, #a26bfa, #7f4cf0);
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: .8rem;
            font-weight: 700;
            flex-shrink: 0;
        }

        .modal-note-avatar.is-mine {
            background: linear-gradient(135deg, #4c8bf5, #2f6bdc);
        }

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
            font-size: .76rem;
            font-weight: 700;
            color: #7f4cf0;
        }

        .modal-note-author.is-mine {
            color: #4c8bf5;
        }

        .modal-note-date {
            font-size: .68rem;
            color: var(--text-soft);
        }

        .modal-note-text {
            font-size: .83rem;
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

        .modal-note-edit-textarea {
            width: 100%;
            min-height: 70px;
            padding: 8px 10px;
            border: 2px solid var(--accent);
            border-radius: 10px;
            background: #fff;
            font-family: inherit;
            font-size: .82rem;
            resize: vertical;
            outline: none;
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
            font-size: .85rem;
            resize: vertical;
        }

        .modal-note-submit {
            background: linear-gradient(135deg, #4c8bf5, #2f6bdc);
            color: #fff;
            border: none;
            width: 46px;
            height: 46px;
            border-radius: 12px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .modal-note-attach-btn {
            width: 46px;
            height: 46px;
            border-radius: 12px;
            background: #f1f4fb;
            color: var(--text-soft);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            flex-shrink: 0;
            border: 2px solid var(--border);
            font-family: inherit;
            padding: 0;
        }

        .modal-note-attach-btn:hover,
        .modal-note-attach-btn.has-files,
        .modal-note-attach-btn.has-mentions {
            background: #e7f0ff;
            color: #2f6bdc;
            border-color: #c7d7ff;
        }

        .modal-note-files-preview {
            display: flex;
            flex-direction: column;
            gap: 6px;
            max-height: 150px;
            overflow-y: auto;
        }

        .modal-note-files-preview:empty {
            display: none;
        }

        .modal-note-file-item {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 7px 10px;
            background: #f7faff;
            border: 1px solid #dbe7ff;
            border-radius: 10px;
            font-size: .76rem;
        }

        .modal-note-file-item__icon {
            width: 26px;
            height: 26px;
            border-radius: 7px;
            background: #e7f0ff;
            color: #2f6bdc;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: .72rem;
            flex-shrink: 0;
        }

        .modal-note-file-item__info {
            flex: 1;
            min-width: 0;
        }

        .modal-note-file-item__name {
            font-weight: 700;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .modal-note-file-item__meta {
            font-size: .66rem;
            color: var(--text-soft);
            margin-top: 2px;
        }

        .modal-note-file-item__remove {
            background: transparent;
            border: none;
            color: var(--text-soft);
            cursor: pointer;
            width: 24px;
            height: 24px;
            border-radius: 6px;
            font-family: inherit;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .modal-note-mentions-preview {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
        }

        .modal-note-mentions-preview:empty {
            display: none;
        }

        .mention-chip {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 10px 4px 6px;
            background: #e7f0ff;
            border: 1px solid #c7d7ff;
            border-radius: 20px;
            font-size: .72rem;
            font-weight: 700;
            color: #2f6bdc;
        }

        .mention-chip__avatar {
            width: 20px;
            height: 20px;
            border-radius: 50%;
            background: linear-gradient(135deg, #4c8bf5, #2f6bdc);
            color: #fff;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: .6rem;
            overflow: hidden;
            flex-shrink: 0;
        }

        .mention-chip__avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .mention-chip__remove {
            background: transparent;
            border: none;
            color: #2f6bdc;
            cursor: pointer;
            width: 16px;
            height: 16px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: .6rem;
            font-family: inherit;
            padding: 0;
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
            box-shadow: 0 12px 32px rgba(20, 30, 60, .18);
            z-index: 1300;
            overflow: hidden;
            flex-direction: column;
        }

        .mention-picker__header {
            padding: 10px 14px;
            font-size: .72rem;
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
        }

        .mention-picker__item:hover {
            background: #f1f4fb;
        }

        .mention-picker__item.selected {
            background: #e7f0ff;
        }

        .mention-picker__avatar {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: linear-gradient(135deg, #4c8bf5, #2f6bdc);
            color: #fff;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: .75rem;
            font-weight: 700;
            flex-shrink: 0;
            overflow: hidden;
        }

        .mention-picker__avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .mention-picker__name {
            flex: 1;
            font-size: .82rem;
            font-weight: 600;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .mention-picker__check {
            color: #2f6bdc;
            font-size: .78rem;
            flex-shrink: 0;
        }

        .mention-picker__empty {
            padding: 24px 16px;
            text-align: center;
            font-size: .78rem;
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
            font-size: .74rem;
        }

        .modal-note-attachment__icon {
            width: 24px;
            height: 24px;
            border-radius: 6px;
            background: #e7f0ff;
            color: #2f6bdc;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: .7rem;
            flex-shrink: 0;
        }

        .modal-note-attachment__body {
            flex: 1;
            min-width: 0;
            color: var(--text);
            text-decoration: none;
        }

        .modal-note-attachment__name {
            font-weight: 700;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .modal-note-attachment__meta {
            font-size: .64rem;
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
            width: 22px;
            height: 22px;
            border-radius: 6px;
            font-family: inherit;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: .7rem;
            text-decoration: none;
        }

        .modal-note-attachment__actions .att-del:hover {
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

        .modal-header h3 {
            margin: 0;
            font-size: 1.1rem;
            font-weight: 700;
        }

        .modal-close {
            background: #f1f4fb;
            border: none;
            width: 34px;
            height: 34px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            cursor: pointer;
            color: var(--text-soft);
            font-weight: 700;
            flex-shrink: 0;
        }

        .modal-close:hover {
            background: #ffe4ec;
            color: #c81e4a;
        }

        .info-modal-box {
            max-width: 470px;
        }

        .info-modal-header-icon {
            width: 38px;
            height: 38px;
            border-radius: 12px;
            background: linear-gradient(135deg, #4c8bf5, #2f6bdc);
            color: #fff;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: .95rem;
            margin-left: 10px;
        }

        .info-task-title {
            background: #fafbfe;
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 12px 14px;
            margin-bottom: 14px;
            font-size: .9rem;
            font-weight: 700;
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
            font-size: .8rem;
            color: var(--text-soft);
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
            flex-shrink: 0;
        }

        .info-row__label i {
            width: 22px;
            height: 22px;
            border-radius: 7px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: .68rem;
        }

        .info-row__label i.blue {
            background: #e7f0ff;
            color: #4c8bf5;
        }

        .info-row__label i.purple {
            background: #f0e9ff;
            color: #7f4cf0;
        }

        .info-row__label i.green {
            background: #e3f8ef;
            color: #2ebc8a;
        }

        .info-row__label i.orange {
            background: #fff3e0;
            color: #ff8a3d;
        }

        .info-row__label i.pink {
            background: #ffe4ec;
            color: #f54e7a;
        }

        .info-row__label i.teal {
            background: #e0f5fb;
            color: #0891b2;
        }

        .info-row__value {
            font-size: .85rem;
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
            font-size: .75rem;
            font-weight: 700;
        }

        .info-priority-badge.low {
            background: #e3f8ef;
            color: #0e7a55;
        }

        .info-priority-badge.medium {
            background: #fff3e0;
            color: #b45309;
        }

        .info-priority-badge.high {
            background: #ffe4ec;
            color: #c81e4a;
        }

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
            font-size: .75rem;
            font-weight: 700;
            overflow: hidden;
            flex-shrink: 0;
        }

        .info-project-thumb img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .info-project-thumb.is-empty {
            background: linear-gradient(135deg, #94a3b8, #64748b);
        }

        .info-subject-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 3px 10px;
            border-radius: 8px;
            font-size: .75rem;
            font-weight: 700;
            background: #e7f0ff;
            color: #2f6bdc;
        }

        /* ⭐ Pickers — Avatar trigger */
        .assignee-picker,
        .project-picker {
            position: relative;
            width: 100%;
            user-select: none;
        }

        .assignee-picker__trigger,
        .project-picker__trigger {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 14px;
            background: #fafbfe;
            border: 2px solid var(--border);
            border-radius: 12px;
            cursor: pointer;
            min-height: 56px;
        }

        .assignee-picker.open .assignee-picker__trigger,
        .project-picker.open .project-picker__trigger {
            border-color: var(--accent);
            background: #fff;
            box-shadow: 0 0 0 4px rgba(76, 139, 245, .12);
        }

        .assignee-picker__avatar,
        .project-picker__avatar {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            background: linear-gradient(135deg, #4c8bf5, #2f6bdc);
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: .9rem;
            flex-shrink: 0;
            overflow: hidden;
        }

        .assignee-picker__avatar.is-self {
            background: linear-gradient(135deg, #4ed4a3, #2ebc8a);
        }

        .project-picker__avatar {
            border-radius: 50% !important;
            background: transparent !important;
            overflow: hidden;
        }

        .project-picker__avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 50%;
            display: block;
        }

        .project-picker__avatar:not(:has(img)) {
            background: linear-gradient(135deg, #a26bfa, #7f4cf0) !important;
        }

        .project-picker__avatar.is-empty {
            background: linear-gradient(135deg, #94a3b8, #64748b);
        }

        /* ⭐ FIX: img inside ALL picker avatars (trigger + option) */
        .assignee-picker__avatar img,
        .assignee-picker__option-avatar img,
        .project-picker__avatar img,
        .project-picker__option-avatar img {
            width: 100% !important;
            height: 100% !important;
            object-fit: cover !important;
            display: block !important;
            border-radius: inherit !important;
        }

        .assignee-picker__info,
        .project-picker__info {
            flex: 1;
            min-width: 0;
        }

        .assignee-picker__name,
        .project-picker__name {
            font-weight: 700;
            font-size: .87rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .assignee-picker__mobile,
        .project-picker__mobile {
            font-size: .73rem;
            color: var(--text-soft);
            margin-top: 2px;
        }

        .assignee-picker__mobile {
            direction: ltr;
            text-align: right;
        }

        .assignee-picker__chevron,
        .project-picker__chevron {
            color: var(--text-soft);
            font-size: .85rem;
            flex-shrink: 0;
        }

        .assignee-picker.open .assignee-picker__chevron,
        .project-picker.open .project-picker__chevron {
            transform: rotate(180deg);
            color: var(--accent);
        }

        .assignee-picker__dropdown,
        .project-picker__dropdown {
            position: absolute;
            top: calc(100% + 8px);
            right: 0;
            left: 0;
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 16px;
            box-shadow: 0 20px 40px -12px rgba(20, 30, 60, .18);
            padding: 8px;
            max-height: 300px;
            overflow-y: auto;
            z-index: 1200;
            opacity: 0;
            visibility: hidden;
            transform: translateY(-8px);
        }

        .assignee-picker.open .assignee-picker__dropdown,
        .project-picker.open .project-picker__dropdown {
            opacity: 1;
            visibility: visible;
            transform: translateY(0);
        }

        .assignee-picker__section-label,
        .project-picker__section-label {
            font-size: .7rem;
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

        .assignee-picker__option,
        .project-picker__option {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 12px;
            border-radius: 12px;
            cursor: pointer;
            position: relative;
        }

        .assignee-picker__option:hover,
        .project-picker__option:hover {
            background: #f1f4fb;
        }

        .assignee-picker__option.selected,
        .project-picker__option.selected {
            background: rgba(76, 139, 245, .1);
        }

        .assignee-picker__option.selected::after,
        .project-picker__option.selected::after {
            content: '\f00c';
            font-family: 'Font Awesome 6 Free';
            font-weight: 900;
            position: absolute;
            left: 14px;
            color: var(--accent);
        }

        .assignee-picker__option-avatar,
        .project-picker__option-avatar {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: linear-gradient(135deg, #4c8bf5, #2f6bdc);
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: .85rem;
            flex-shrink: 0;
            overflow: hidden;
        }

        .assignee-picker__option-avatar.is-self {
            background: linear-gradient(135deg, #4ed4a3, #2ebc8a);
        }

        .project-picker__option-avatar {
            border-radius: 50% !important;
            background: transparent !important;
            overflow: hidden;
        }

        .project-picker__option-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 50%;
            display: block;
        }

        .project-picker__option-avatar:not(:has(img)) {
            background: linear-gradient(135deg, #a26bfa, #7f4cf0) !important;
        }

        .project-picker__option-avatar.is-empty {
            background: linear-gradient(135deg, #94a3b8, #64748b);
        }

        .assignee-picker__option-info,
        .project-picker__option-info {
            flex: 1;
            min-width: 0;
        }

        .assignee-picker__option-name,
        .project-picker__option-name {
            font-weight: 600;
            font-size: .85rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .assignee-picker__option-name .creator-crown {
            color: #ffa751;
            font-size: .75rem;
        }

        .assignee-picker__option-mobile,
        .project-picker__option-mobile {
            font-size: .7rem;
            color: var(--text-soft);
            margin-top: 2px;
        }

        .assignee-picker__option-mobile {
            direction: ltr;
            text-align: right;
        }

        .assignee-picker__empty,
        .project-picker__empty {
            padding: 20px 16px;
            text-align: center;
            font-size: .82rem;
            color: var(--text-soft);
        }

        .assignee-picker.is-disabled,
        .project-picker.is-disabled {
            pointer-events: none;
            opacity: .65;
        }

        input.field-locked,
        textarea.field-locked,
        select.field-locked {
            background: #fafbfe !important;
            color: var(--text-soft) !important;
            cursor: not-allowed !important;
            border-style: dashed !important;
        }

        .hamburger-btn {
            display: none;
            width: 44px;
            height: 44px;
            border: none;
            background: linear-gradient(135deg, #4c8bf5, #2f6bdc);
            border-radius: 13px;
            cursor: pointer;
            align-items: center;
            justify-content: center;
            padding: 0;
        }

        .hamburger-btn .hamburger-lines {
            width: 22px;
            height: 16px;
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
        }

        .hamburger-btn .hamburger-lines span:nth-child(2) {
            width: 70%;
        }

        .sidebar-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(20, 30, 60, .5);
            z-index: 998;
        }

        .sidebar-overlay.active {
            display: block;
        }

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
            font-size: .85rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 10px;
            box-shadow: 0 12px 32px rgba(20, 30, 60, .4);
            z-index: 9999;
            opacity: 0;
            transition: all .35s cubic-bezier(.34, 1.56, .64, 1);
            pointer-events: none;
            max-width: 90vw;
        }

        .app-toast.active {
            opacity: 1;
            transform: translateX(-50%) translateY(0);
        }

        .app-toast.toast-success i {
            color: #4ed4a3;
        }

        .app-toast.toast-error i {
            color: #ff6b8b;
        }

        .app-toast.toast-info i {
            color: #4c8bf5;
        }

        @media (max-width:1100px) {
            .hero-stats {
                grid-template-columns: repeat(2, 1fr);
            }

            .charts-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width:900px) {
            .hamburger-btn {
                display: flex;
            }

            .sidebar {
                position: fixed !important;
                top: 0;
                right: 0;
                bottom: 0;
                width: 280px;
                max-width: 85vw;
                transform: translateX(105%);
                z-index: 999 !important;
            }

            .sidebar.open {
                transform: translateX(0);
            }

            .topbar {
                padding: 14px 18px 6px;
            }

            .content-area {
                padding: 12px 18px 24px;
            }

            .task-row {
                flex-direction: column;
                align-items: stretch;
                gap: 12px;
            }

            .modal-box {
                max-width: 92vw;
                padding: 22px;
            }

            .task-modal-box {
                max-width: 94vw;
                padding: 0;
            }

            .tabs-header {
                flex-direction: column;
                align-items: stretch;
                gap: 12px;
            }

            .tabs-header>div:first-child {
                display: flex;
                gap: 8px;
                width: 100%;
            }

            .tabs-header .tab-btn {
                flex: 1;
            }

            div[style*="grid-template-columns: 350px 1fr"] {
                grid-template-columns: 1fr !important;
                gap: 16px !important;
            }

            .tm-row {
                grid-template-columns: 1fr;
            }

            .main-tabs-header {
                width: 100%;
            }

            .main-tab-btn {
                flex: 1;
                justify-content: center;
            }
        }

        @media (max-width:600px) {
            .content-area {
                padding: 10px 14px 20px;
            }

            .hero-stats {
                grid-template-columns: 1fr;
                gap: 10px;
            }

            .soft-stats {
                grid-template-columns: 1fr;
            }

            .time-grid {
                grid-template-columns: 1fr;
            }

            .task-modal-header {
                padding: 14px 18px;
            }

            .tm-body {
                padding: 16px 18px 4px;
            }

            .tm-footer {
                padding: 14px 18px;
            }

            .main-tab-btn {
                padding: 10px 8px;
                font-size: .78rem;
            }
        }
    </style>
</head>

<body>

    <?php sidebar(); ?>

    <div class="main-wrapper">
        <div class="topbar">
            <button class="hamburger-btn" id="hamburgerBtn" onclick="toggleSidebar()">
                <div class="hamburger-lines"><span></span><span></span><span></span></div>
            </button>
            <h1>
                <?= $page === 'dashboard' ? 'داشبورد مدیریت' : ($page === 'subjects' ? 'مدیریت موضوعات' : 'لیست وظایف') ?>
                <small><?= $page === 'dashboard' ? 'خلاصه‌ای از وضعیت امروز شما' : ($page === 'subjects' ? 'دسته‌بندی وظایف' : 'همه وظایف شما') ?></small>
            </h1>
            <div class="topbar-user">
                <div class="avatar">
                    <?php if ($currentUserAvatar): ?><img src="<?= htmlspecialchars($currentUserAvatar, ENT_QUOTES, 'UTF-8') ?>" alt=""><?php else: ?><?= htmlspecialchars(mb_substr($currentUserFirstName, 0, 1, 'UTF-8'), ENT_QUOTES, 'UTF-8') ?><?php endif; ?>
                </div>
                <span><?= htmlspecialchars($displayUser, ENT_QUOTES, 'UTF-8') ?></span>
            </div>
        </div>

        <div class="content-area">
            <?php if (!empty($msg)): ?>
                <div class="flash flash-<?= htmlspecialchars($msgType, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>

            <?php if ($page === 'dashboard'): ?>
                <div class="hero-stats">
                    <div class="hero-card card-red">
                        <div class="hero-card__top">
                            <div class="hero-card__icon"><i class="fas fa-list-check"></i></div>
                        </div>
                        <div class="hero-card__bottom">
                            <div class="hero-card__value"><?= $cTotal ?></div>
                            <div class="hero-card__label">کل وظایف من</div>
                        </div>
                    </div>
                    <div class="hero-card card-orange">
                        <div class="hero-card__top">
                            <div class="hero-card__icon"><i class="fas fa-clock"></i></div>
                        </div>
                        <div class="hero-card__bottom">
                            <div class="hero-card__value"><?= $cPend ?></div>
                            <div class="hero-card__label">در انتظار انجام</div>
                        </div>
                    </div>
                    <div class="hero-card card-purple">
                        <div class="hero-card__top">
                            <div class="hero-card__icon"><i class="fas fa-check-double"></i></div>
                        </div>
                        <div class="hero-card__bottom">
                            <div class="hero-card__value"><?= $cComp ?></div>
                            <div class="hero-card__label">تکمیل شده</div>
                        </div>
                    </div>
                    <div class="hero-card card-green">
                        <div class="hero-card__top">
                            <div class="hero-card__icon"><i class="fas fa-chart-line"></i></div>
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
                <div class="section-label"><i class="fas fa-ticket-alt" style="color: #7f4cf0;"></i> آمار تیکت‌ها <span class="section-hint">(سراسری)</span></div>
                <div class="soft-stats">
                    <div class="soft-card">
                        <div>
                            <div class="soft-card__label">کل تیکت‌ها</div>
                            <div class="soft-card__value"><?= $cTkTotal ?></div>
                        </div>
                        <div class="soft-card__icon purple"><i class="fas fa-ticket"></i></div>
                    </div>
                    <div class="soft-card">
                        <div>
                            <div class="soft-card__label">انجام شده</div>
                            <div class="soft-card__value" style="color: var(--success);"><?= $cTkDone ?></div>
                        </div>
                        <div class="soft-card__icon green"><i class="fas fa-check-double"></i></div>
                    </div>
                    <div class="soft-card">
                        <div>
                            <div class="soft-card__label">در انتظار</div>
                            <div class="soft-card__value" style="color: var(--warning);"><?= $cTkPending ?></div>
                        </div>
                        <div class="soft-card__icon orange"><i class="fas fa-hourglass-half"></i></div>
                    </div>
                    <div class="soft-card">
                        <div>
                            <div class="soft-card__label">اولویت خیلی زیاد</div>
                            <div class="soft-card__value" style="color: var(--danger);"><?= $cTkHigh ?></div>
                        </div>
                        <div class="soft-card__icon pink"><i class="fas fa-exclamation-circle"></i></div>
                    </div>
                    <div class="soft-card">
                        <div>
                            <div class="soft-card__label">ثبت امروز</div>
                            <div class="soft-card__value"><?= $cTkToday ?></div>
                        </div>
                        <div class="soft-card__icon blue"><i class="fas fa-calendar-day"></i></div>
                    </div>
                    <div class="soft-card">
                        <div>
                            <div class="soft-card__label">تیکت‌های VIP</div>
                            <div class="soft-card__value" style="color: #ca8a04;"><?= $cTkVIP ?></div>
                        </div>
                        <div class="soft-card__icon yellow"><i class="fas fa-crown"></i></div>
                    </div>
                </div>
                <div class="section-label"><i class="fas fa-chart-pie" style="color: #2ebc8a;"></i> آمار سایر بخش‌ها</div>
                <div class="soft-stats">
                    <div class="soft-card">
                        <div>
                            <div class="soft-card__label">کل متون آماده</div>
                            <div class="soft-card__value"><?= $cTxTotal ?></div>
                        </div>
                        <div class="soft-card__icon blue"><i class="fas fa-pen-fancy"></i></div>
                    </div>
                    <div class="soft-card">
                        <div>
                            <div class="soft-card__label">کل درخواست‌های تماس</div>
                            <div class="soft-card__value"><?= $cCrTotal ?></div>
                        </div>
                        <div class="soft-card__icon purple"><i class="fas fa-phone-volume"></i></div>
                    </div>
                    <div class="soft-card">
                        <div>
                            <div class="soft-card__label">درخواست‌های جدید</div>
                            <div class="soft-card__value" style="color: var(--warning);"><?= $cCrNew ?></div>
                        </div>
                        <div class="soft-card__icon orange"><i class="fas fa-bell"></i></div>
                    </div>
                    <div class="soft-card">
                        <div>
                            <div class="soft-card__label">درخواست‌های انجام شده</div>
                            <div class="soft-card__value" style="color: var(--success);"><?= $cCrDone ?></div>
                        </div>
                        <div class="soft-card__icon green"><i class="fas fa-phone-square"></i></div>
                    </div>
                    <div class="soft-card">
                        <div>
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
                <div class="section-label" style="margin-top: 26px;"><i class="fas fa-stopwatch" style="color: #ff8a3d;"></i> تحلیل میانگین زمان انجام</div>
                <div class="time-grid">
                    <div class="time-card">
                        <div class="time-card-header">
                            <div class="time-card-title">
                                <div class="soft-card__icon blue" style="width: 40px; height: 40px;"><i class="fas fa-tasks"></i></div>وظایف من
                            </div>
                            <div class="time-card-count"><?= $taskDoneCount ?> تکمیل‌شده</div>
                        </div>
                        <div class="time-card-main">
                            <div class="time-card-main-label">میانگین زمان</div>
                            <div class="time-card-main-value" style="color: #4c8bf5;"><?= formatDuration($taskAvgHours) ?></div>
                        </div>
                        <div class="time-card-footer">
                            <div>
                                <div class="label">سریع‌ترین</div>
                                <div class="value" style="color: var(--success);"><?= formatDuration($taskMinHours) ?></div>
                            </div>
                            <div class="time-card-divider"></div>
                            <div>
                                <div class="label">کندترین</div>
                                <div class="value" style="color: var(--danger);"><?= formatDuration($taskMaxHours) ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="time-card">
                        <div class="time-card-header">
                            <div class="time-card-title">
                                <div class="soft-card__icon purple" style="width: 40px; height: 40px;"><i class="fas fa-phone-volume"></i></div>درخواست‌های تماس
                            </div>
                            <div class="time-card-count"><?= $callDoneCount ?> تکمیل‌شده</div>
                        </div>
                        <div class="time-card-main">
                            <div class="time-card-main-label">میانگین زمان</div>
                            <div class="time-card-main-value" style="color: #2ebc8a;"><?= formatDuration($callAvgHours) ?></div>
                        </div>
                        <div class="time-card-footer">
                            <div>
                                <div class="label">سریع‌ترین</div>
                                <div class="value" style="color: var(--success);"><?= formatDuration($callMinHours) ?></div>
                            </div>
                            <div class="time-card-divider"></div>
                            <div>
                                <div class="label">کندترین</div>
                                <div class="value" style="color: var(--danger);"><?= formatDuration($callMaxHours) ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="time-card">
                        <div class="time-card-header">
                            <div class="time-card-title">
                                <div class="soft-card__icon orange" style="width: 40px; height: 40px;"><i class="fas fa-ticket-alt"></i></div>تیکت‌ها (سراسری)
                            </div>
                            <div class="time-card-count"><?= $ticketDoneCount ?> تکمیل‌شده</div>
                        </div>
                        <div class="time-card-main">
                            <div class="time-card-main-label">میانگین زمان</div>
                            <div class="time-card-main-value" style="color: #7f4cf0;"><?= formatDuration($ticketAvgHours) ?></div>
                        </div>
                        <div class="time-card-footer">
                            <div>
                                <div class="label">سریع‌ترین</div>
                                <div class="value" style="color: var(--success);"><?= formatDuration($ticketMinHours) ?></div>
                            </div>
                            <div class="time-card-divider"></div>
                            <div>
                                <div class="label">کندترین</div>
                                <div class="value" style="color: var(--danger);"><?= formatDuration($ticketMaxHours) ?></div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="charts-grid">
                    <div class="card" style="margin-bottom: 0;">
                        <h3><i class="fas fa-percentage" style="color: #4c8bf5;"></i> پیشرفت کلی</h3>
                        <?php $tkRatio = $cTkTotal > 0 ? round(($cTkDone / $cTkTotal) * 100) : 0;
                        $crRatio = $cCrTotal > 0 ? round(($cCrDone / $cCrTotal) * 100) : 0; ?>
                        <div class="progress-item">
                            <div class="progress-header"><span>وظایف انجام شده</span><span style="color: #4c8bf5;"><?= $ratio ?>%</span></div>
                            <div class="progress-track">
                                <div class="progress-fill" style="width: <?= $ratio ?>%; background: linear-gradient(90deg, #4c8bf5, #2f6bdc);"></div>
                            </div>
                        </div>
                        <div class="progress-item">
                            <div class="progress-header"><span>تیکت‌های انجام شده</span><span style="color: #7f4cf0;"><?= $tkRatio ?>%</span></div>
                            <div class="progress-track">
                                <div class="progress-fill" style="width: <?= $tkRatio ?>%; background: linear-gradient(90deg, #a26bfa, #7f4cf0);"></div>
                            </div>
                        </div>
                        <div class="progress-item">
                            <div class="progress-header"><span>درخواست‌های تماس</span><span style="color: #2ebc8a;"><?= $crRatio ?>%</span></div>
                            <div class="progress-track">
                                <div class="progress-fill" style="width: <?= $crRatio ?>%; background: linear-gradient(90deg, #4ed4a3, #2ebc8a);"></div>
                            </div>
                        </div>
                    </div>
                    <div class="card" style="margin-bottom: 0;">
                        <h3><i class="fas fa-clipboard-check" style="color: #4c8bf5;"></i> خلاصه وضعیت</h3>
                        <?php $items = [
                            ['label' => 'وظایف در انتظار من', 'value' => $cPend, 'icon' => 'fa-clock', 'color' => '#ff8a3d', 'bg' => '#fff3e0'],
                            ['label' => 'واگذارشده به من', 'value' => $cAssignedToMe, 'icon' => 'fa-inbox', 'color' => '#ff8a3d', 'bg' => '#fff3e0'],
                            ['label' => 'واگذارشده توسط من', 'value' => $cAssignedByMe, 'icon' => 'fa-paper-plane', 'color' => '#7f4cf0', 'bg' => '#f0e9ff'],
                            ['label' => 'تیکت‌های در انتظار', 'value' => $cTkPending, 'icon' => 'fa-hourglass-half', 'color' => '#7f4cf0', 'bg' => '#f0e9ff'],
                            ['label' => 'درخواست‌های جدید', 'value' => $cCrNew, 'icon' => 'fa-bell', 'color' => '#f54e7a', 'bg' => '#ffe4ec'],
                            ['label' => 'تیکت‌های VIP', 'value' => $cTkVIP, 'icon' => 'fa-crown', 'color' => '#ca8a04', 'bg' => '#fff7d6'],
                        ];
                        foreach ($items as $item): ?>
                            <div class="summary-item">
                                <div style="display: flex; align-items: center; gap: 10px;">
                                    <div class="summary-icon" style="background: <?= $item['bg'] ?>; color: <?= $item['color'] ?>;"><i class="fas <?= $item['icon'] ?>"></i></div><span class="summary-label"><?= $item['label'] ?></span>
                                </div><span class="summary-value" style="color: <?= $item['color'] ?>;"><?= $item['value'] ?></span>
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
                            <div class="form-group"><label>عنوان موضوع</label><input type="text" name="subject_title" placeholder="مثلاً: برنامه‌نویسی..." required maxlength="100"></div>
                            <button type="submit" name="add_subject" class="btn" style="width: 100%;">ثبت موضوع</button>
                        </form>
                    </div>
                    <div class="card" style="margin-bottom: 0;">
                        <h3><i class="fas fa-tags" style="color: #4c8bf5;"></i> دسته‌بندی‌های ثبت‌شده</h3>
                        <?php if (empty($subjects)): ?>
                            <p style="text-align: center; color: var(--text-soft); padding: 30px;">هنوز هیچ موضوعی تعریف نشده است.</p>
                        <?php else: ?>
                            <div class="subject-grid">
                                <?php foreach ($subjects as $sub): ?>
                                    <div class="subject-item">
                                        <div style="display: flex; align-items: center; gap: 10px; overflow: hidden;">
                                            <div class="soft-card__icon blue" style="width: 36px; height: 36px; border-radius: 10px;"><i class="fas fa-tag"></i></div>
                                            <div style="font-weight: 700; font-size: 0.88rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;"><?= htmlspecialchars($sub['title'], ENT_QUOTES, 'UTF-8') ?></div>
                                        </div>
                                        <div style="display: flex; align-items: center; gap: 6px;">
                                            <button type="button" class="subject-edit-btn" data-subject-id="<?= (int)$sub['id'] ?>" data-subject-title="<?= htmlspecialchars($sub['title'], ENT_QUOTES, 'UTF-8') ?>" onclick="openEditSubjectModalFromBtn(this)"><i class="fas fa-pen"></i></button>
                                            <form method="POST" style="display:inline;" onsubmit="return confirm('حذف شود؟');">
                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                                <input type="hidden" name="delete_subject" value="<?= (int)$sub['id'] ?>">
                                                <button type="submit" class="action-link-delete"><i class="fas fa-trash-alt"></i></button>
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
                    $renderTaskRow = function ($t, $userId, $csrfToken, $makeProjectImageUrl) {
                        $isAssignedByMe = ((int)$t['user_id'] === $userId) && ((int)$t['assignee_id'] !== $userId);
                        $isAssignedToMe = ((int)$t['user_id'] !== $userId) && ((int)$t['assignee_id'] === $userId);
                        $assigneeName = trim(($t['assignee_first_name'] ?? '') . ' ' . ($t['assignee_last_name'] ?? ''));
                        $creatorName = trim(($t['creator_first_name'] ?? '') . ' ' . ($t['creator_last_name'] ?? ''));
                        $assigneeMobile = $t['assignee_mobile'] ?? '';
                        $creatorMobile = $t['creator_mobile'] ?? '';
                        if ($assigneeName === '') $assigneeName = $assigneeMobile;
                        if ($creatorName === '') $creatorName = $creatorMobile ?: 'کاربر';
                        $taskPerms = ['edit' => false, 'delete' => false, 'complete' => false, 'reassign' => false, 'notes' => false, 'change_project' => false];
                        $canOpenEditModal = false;
                        if ((int)$t['user_id'] === $userId) {
                            $taskPerms = ['edit' => true, 'delete' => true, 'complete' => true, 'reassign' => true, 'notes' => true, 'change_project' => true];
                            $canOpenEditModal = true;
                        } else {
                            $myPerms = json_decode($t['my_task_permissions'] ?? '[]', true);
                            if (!is_array($myPerms)) $myPerms = [];
                            foreach ($myPerms as $p) if (isset($taskPerms[$p])) $taskPerms[$p] = true;
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
                        $attCount = (int)($t['attachments_count'] ?? 0);
                        $isCompleted = !empty($t['is_completed']);
                        $isOverdue = false;
                        if (!$isCompleted && !empty($t['due_date'])) {
                            $dtp = !empty($t['due_time']) ? substr($t['due_time'], 0, 5) : '23:59';
                            if (($t['due_date'] . ' ' . $dtp . ':00') < date('Y-m-d H:i:s')) $isOverdue = true;
                        }
                        $dueDateDisplay = $t['due_date'] ? formatPersianDateOnly($t['due_date'] . ' 00:00:00') : '';
                        $dueTimeDisplay = !empty($t['due_time']) ? substr($t['due_time'], 0, 5) : '';
                        $dueDateRaw = $t['due_date'] ?? '';
                        $dueTimeRaw = !empty($t['due_time']) ? substr($t['due_time'], 0, 5) : '';
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
                            'due_time_raw' => $dueTimeRaw,
                            'description' => (string)($t['description'] ?? ''),
                            'attachments_count' => $attCount,
                            'is_overdue' => $isOverdue,
                        ]);
                        $completedClass = $isCompleted ? 'completed-task' : '';
                        $overdueClass = $isOverdue ? 'overdue-task' : '';
                        $checked = $isCompleted ? 'checked' : '';
                    ?>
                        <div class="task-row <?= $completedClass ?> <?= $overdueClass ?> <?= $isAssignedToMe ? 'received-task' : '' ?> <?= $isAssignedByMe ? 'assigned-task' : '' ?> <?= $canOpenEditModal ? '' : 'no-edit' ?>"
                            id="task-row-<?= (int)$t['id'] ?>" data-task='<?= $taskDataForJs ?>'
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
                                        <div><span class="tip-name"><?= htmlspecialchars($creatorName, ENT_QUOTES, 'UTF-8') ?></span><?php if ($creatorMobile): ?><span class="tip-mobile"><?= htmlspecialchars($creatorMobile, ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?></div>
                                    </div>
                                </div>
                            <?php elseif ($isAssignedByMe): ?>
                                <div class="task-color-bar bar-sent"></div>
                                <div class="task-tooltip tip-sent">
                                    <div class="tip-icon"><i class="fas fa-paper-plane"></i></div>
                                    <div>
                                        <div class="tip-label">واگذارشده به</div>
                                        <div><span class="tip-name"><?= htmlspecialchars($assigneeName, ENT_QUOTES, 'UTF-8') ?></span><?php if ($assigneeMobile): ?><span class="tip-mobile"><?= htmlspecialchars($assigneeMobile, ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?></div>
                                    </div>
                                </div>
                            <?php endif; ?>
                            <div class="task-info">
                                <?php if ($taskPerms['complete']): ?>
                                    <input type="checkbox" class="custom-checkbox" <?= $checked ?> onclick="event.stopPropagation();" onchange="toggleTask(<?= (int)$t['id'] ?>)">
                                <?php else: ?>
                                    <input type="checkbox" class="custom-checkbox" <?= $checked ?> disabled>
                                <?php endif; ?>
                                <div style="min-width: 0; max-width: 700px;">
                                    <div class="task-title">
                                        <?= htmlspecialchars($t['title'], ENT_QUOTES, 'UTF-8') ?>
                                        <span class="task-badges">
                                            <?php if ($notesCount > 0): ?><span class="task-badge badge-notes"><i class="fas fa-comments"></i> <?= $notesCount ?></span><?php endif; ?>
                                            <?php if ($attCount > 0): ?><span class="task-badge badge-files"><i class="fas fa-paperclip"></i> <?= $attCount ?></span><?php endif; ?>
                                        </span>
                                    </div>
                                    <?php if (!empty($t['description'])): ?>
                                        <div class="task-desc-clamp" title="<?= htmlspecialchars($t['description'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($t['description'], ENT_QUOTES, 'UTF-8') ?></div>
                                    <?php endif; ?>
                                    <?php if ($dueDateDisplay || $createdDateDisplay || $completedDateDisplay): ?>
                                        <div class="task-meta-row">
                                            <?php if ($dueDateDisplay): ?>
                                                <span class="task-meta task-meta-due <?= $isOverdue ? 'overdue' : '' ?>">
                                                    <i class="fas fa-calendar-day"></i> مهلت: <strong><?= htmlspecialchars($dueDateDisplay, ENT_QUOTES, 'UTF-8') ?><?php if ($dueTimeDisplay): ?> - <?= htmlspecialchars($dueTimeDisplay, ENT_QUOTES, 'UTF-8') ?><?php endif; ?></strong>
                                                    <?php if ($isOverdue): ?><span class="overdue-badge"><i class="fas fa-exclamation-triangle"></i> تاخیر</span><?php endif; ?>
                                                </span>
                                            <?php endif; ?>
                                            <?php if ($createdDateDisplay): ?><span class="task-meta task-meta-created"><i class="fas fa-pen-to-square"></i> ثبت: <?= htmlspecialchars($createdDateDisplay, ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?>
                                            <?php if ($completedDateDisplay): ?><span class="task-meta task-meta-completed"><i class="fas fa-circle-check"></i> انجام: <?= htmlspecialchars($completedDateDisplay, ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div style="display: flex; align-items: center; gap: 4px;" onclick="event.stopPropagation();">
                                <button type="button" class="task-info-btn" onclick="openInfoModalFromRow(<?= (int)$t['id'] ?>);"><i class="fas fa-info-circle"></i></button>
                                <?php if ($taskPerms['delete']): ?>
                                    <form method="POST" style="display:inline; margin: 0;" onsubmit="return confirm('حذف شود؟');">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="delete" value="<?= (int)$t['id'] ?>">
                                        <button type="submit" class="action-link-delete"><i class="fas fa-trash-alt"></i></button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php
                    };
                    ?>
                    <div class="main-tabs-header">
                        <button type="button" class="main-tab-btn <?= $mt === 'mine' ? 'active' : '' ?>" onclick="switchMainTab('mine', this)"><i class="fas fa-user-check"></i> وظایف من <span class="main-tab-badge"><?= count($myTasksAll) ?></span></button>
                        <button type="button" class="main-tab-btn <?= $mt === 'others' ? 'active' : '' ?>" onclick="switchMainTab('others', this)"><i class="fas fa-share"></i> وظایف دیگران <span class="main-tab-badge"><?= count($othersTasksAll) ?></span></button>
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
                                <p style="text-align: center; color: var(--text-soft); padding: 20px;">هیچ وظیفه انجام‌نشده‌ای وجود ندارد.</p>
                            <?php else: ?>
                                <?php foreach ($myTasksUncompletedPaged as $t) $renderTaskRow($t, $userId, $csrfToken, $makeProjectImageUrl); ?>
                                <?= renderPagination($pageMineUncompleted, $totalPagesMineUncompleted, $totalCountMineUncompleted, 'p_mu', 'mine', 'uncompleted') ?>
                            <?php endif; ?>
                        </div>
                        <div id="tab-mine-completed" class="tab-content <?= ($mt === 'mine' && $st === 'completed') ? 'active' : '' ?>">
                            <?php if (empty($myTasksCompletedPaged)): ?>
                                <p style="text-align: center; color: var(--text-soft); padding: 20px;">هیچ وظیفه انجام‌شده‌ای وجود ندارد.</p>
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
                                <p style="text-align: center; color: var(--text-soft); padding: 20px;">هنوز برای دیگران وظیفه‌ای نساخته‌اید.</p>
                            <?php else: ?>
                                <?php foreach ($othersTasksUncompletedPaged as $t) $renderTaskRow($t, $userId, $csrfToken, $makeProjectImageUrl); ?>
                                <?= renderPagination($pageOthersUncompleted, $totalPagesOthersUncompleted, $totalCountOthersUncompleted, 'p_ou', 'others', 'uncompleted') ?>
                            <?php endif; ?>
                        </div>
                        <div id="tab-others-completed" class="tab-content <?= ($mt === 'others' && $st === 'completed') ? 'active' : '' ?>">
                            <?php if (empty($othersTasksCompletedPaged)): ?>
                                <p style="text-align: center; color: var(--text-soft); padding: 20px;">هنوز برای دیگران وظیفه‌ای نساخته‌اید.</p>
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

    <!-- ========== Create Task Modal ========== -->
    <div id="createTaskModal" class="modal-overlay">
        <div class="modal-box task-modal-box">
            <div class="task-modal-header">
                <h3><span class="tm-header-icon"><i class="fas fa-plus"></i></span> مشخصات وظیفه <span class="tm-id-badge">جدید</span></h3>
                <button type="button" class="modal-close" onclick="closeModal('createTaskModal')">&times;</button>
            </div>
            <form method="POST" class="task-modal-form" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="form_token" value="<?= htmlspecialchars($formTokenTask, ENT_QUOTES, 'UTF-8') ?>">
                <div class="tm-body">
                    <div class="tm-hint"><i class="fas fa-circle-info"></i><span>برای ذخیره CTRL + S را بزنید</span></div>
                    <div class="tm-field"><label class="tm-label"><i class="fas fa-pen tm-label-icon"></i> عنوان وظیفه</label><input type="text" name="task_title" class="tm-input" required maxlength="255" placeholder="عنوان..."></div>
                    <div class="tm-field"><label class="tm-label"><i class="fas fa-align-right tm-label-icon"></i> توضیحات</label><textarea name="task_desc" class="tm-input tm-textarea" rows="4" maxlength="5000" placeholder="شرح..."></textarea></div>
                    <div class="tm-divider"></div>
                    <div class="tm-row">
                        <div class="tm-field" style="margin-bottom: 12px;">
                            <label class="tm-label"><i class="fas fa-tag tm-label-icon"></i> موضوع</label>
                            <select name="subject_id" class="tm-input">
                                <option value="">بدون موضوع</option>
                                <?php foreach ($subjects as $sub): ?><option value="<?= (int)$sub['id'] ?>"><?= htmlspecialchars($sub['title'], ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="tm-field" style="margin-bottom: 12px;">
                            <label class="tm-label"><i class="fas fa-flag tm-label-icon"></i> اولویت</label>
                            <div class="tm-priority-group">
                                <label class="tm-priority-opt" data-priority="low"><input type="radio" name="priority" value="low"><span>کم</span></label>
                                <label class="tm-priority-opt" data-priority="medium"><input type="radio" name="priority" value="medium" checked><span>متوسط</span></label>
                                <label class="tm-priority-opt" data-priority="high"><input type="radio" name="priority" value="high"><span>زیاد</span></label>
                            </div>
                        </div>
                    </div>
                    <div class="tm-field">
                        <label class="tm-label"><i class="fas fa-folder tm-label-icon"></i> پروژه</label>
                        <div class="project-picker" id="createProjectPicker">
                            <input type="hidden" name="project_id" value="" id="createProjectInput">
                            <div class="project-picker__trigger" onclick="toggleProjectPicker('createProjectPicker')">
                                <div class="project-picker__avatar is-empty" id="createProjectAvatar"><i class="fas fa-folder"></i></div>
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
                        <label class="tm-label"><i class="fas fa-user tm-label-icon"></i> مسئول وظیفه</label>
                        <div class="assignee-picker" id="createAssigneePicker">
                            <input type="hidden" name="assignee_id" value="<?= (int)$userId ?>" id="createAssigneeInput">
                            <div class="assignee-picker__trigger" onclick="toggleAssigneePicker('createAssigneePicker')">
                                <div class="assignee-picker__avatar is-self" id="createAssigneeAvatar">
                                    <?php if ($currentUserAvatar): ?><img src="<?= htmlspecialchars($currentUserAvatar, ENT_QUOTES, 'UTF-8') ?>" alt=""><?php else: ?><?= htmlspecialchars(mb_substr($currentUserFirstName, 0, 1, 'UTF-8'), ENT_QUOTES, 'UTF-8') ?><?php endif; ?>
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
                    <div class="tm-row">
                        <div class="tm-field" style="margin-bottom: 12px;">
                            <label class="tm-label"><i class="fas fa-calendar tm-label-icon"></i> تاریخ مهلت</label>
                            <div class="date-picker-wrap" id="create_due_date_wrap">
                                <input type="text" id="create_due_date_display" class="tm-input date-display-input" placeholder="انتخاب تاریخ..." autocomplete="off" readonly>
                                <span class="date-picker-icon"><i class="fas fa-calendar-alt"></i></span>
                                <button type="button" class="date-clear-btn" onclick="clearPersianDate('create')"><i class="fas fa-times"></i></button>
                                <input type="hidden" name="due_date" id="create_due_date" value="">
                            </div>
                        </div>
                        <div class="tm-field" style="margin-bottom: 12px;">
                            <label class="tm-label"><i class="fas fa-clock tm-label-icon"></i> ساعت مهلت</label>
                            <input type="time" name="due_time" id="create_due_time" class="tm-input time-input" autocomplete="off">
                        </div>
                    </div>
                    <div class="tm-field">
                        <label class="tm-label"><i class="fas fa-paperclip tm-label-icon"></i> فایل‌های پیوست</label>
                        <div class="file-upload-zone" id="createFileDropZone">
                            <input type="file" name="task_attachments[]" id="createFileInput" multiple accept="<?= htmlspecialchars($allowedExtsAttr, ENT_QUOTES, 'UTF-8') ?>">
                            <div class="file-upload-zone__icon"><i class="fas fa-cloud-upload-alt"></i></div>
                            <div class="file-upload-zone__title">کلیک کنید یا فایل‌ها را رها کنید</div>
                            <div class="file-upload-zone__hint">فرمت مجاز: <code>PDF</code> <code>Word</code> <code>Excel</code> <code>Image</code> <code>ZIP</code> — حداکثر <code>10MB</code></div>
                        </div>
                        <ul class="file-preview-list" id="createFilePreviewList"></ul>
                    </div>
                </div>
                <div class="tm-footer">
                    <button type="button" class="tm-btn-cancel" onclick="closeModal('createTaskModal')"><i class="fas fa-times" style="margin-left: 6px;"></i> بستن</button>
                    <button type="submit" name="add_task" class="tm-btn-save"><i class="fas fa-save"></i> ثبت وظیفه</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ========== Edit Task Modal ========== -->
    <div id="editTaskModal" class="modal-overlay" onclick="if(event.target === this) requestCloseEditModal()">
        <div class="modal-box task-modal-box" onclick="event.stopPropagation()">
            <div class="task-modal-header">
                <h3><span class="tm-header-icon tm-edit-icon"><i class="fas fa-pen"></i></span> ویرایش وظیفه <span class="tm-id-badge" id="edit_id_badge">—</span></h3>
                <div class="tm-header-actions">
                    <button type="button" class="tm-header-share" id="edit_share_btn" onclick="copyTaskLink()"><i class="fas fa-link"></i></button>
                    <button type="button" class="modal-close" onclick="requestCloseEditModal()">&times;</button>
                </div>
            </div>
            <form method="POST" class="task-modal-form" enctype="multipart/form-data" id="editTaskForm">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="form_token" value="<?= htmlspecialchars($formTokenTask, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="task_id" id="edit_task_id">
                <div class="tm-body">
                    <div class="tm-hint"><i class="fas fa-circle-info"></i><span>برای ذخیره CTRL + S را بزنید</span></div>
                    <div class="tm-field"><label class="tm-label"><i class="fas fa-pen tm-label-icon"></i> عنوان وظیفه</label><input type="text" name="task_title" id="edit_task_title" class="tm-input" required maxlength="255"></div>
                    <div class="tm-field"><label class="tm-label"><i class="fas fa-align-right tm-label-icon"></i> توضیحات</label><textarea name="task_desc" id="edit_task_desc" class="tm-input tm-textarea" rows="4" maxlength="5000"></textarea></div>
                    <div class="tm-divider"></div>
                    <div class="tm-row">
                        <div class="tm-field" style="margin-bottom: 12px;">
                            <label class="tm-label"><i class="fas fa-tag tm-label-icon"></i> موضوع</label>
                            <select name="subject_id" id="edit_subject_id" class="tm-input">
                                <option value="">بدون موضوع</option>
                                <?php foreach ($subjects as $sub): ?><option value="<?= (int)$sub['id'] ?>"><?= htmlspecialchars($sub['title'], ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="tm-field" style="margin-bottom: 12px;">
                            <label class="tm-label"><i class="fas fa-flag tm-label-icon"></i> اولویت</label>
                            <div class="tm-priority-group" id="edit_priority_group">
                                <label class="tm-priority-opt" data-priority="low"><input type="radio" name="priority" value="low"><span>کم</span></label>
                                <label class="tm-priority-opt" data-priority="medium"><input type="radio" name="priority" value="medium"><span>متوسط</span></label>
                                <label class="tm-priority-opt" data-priority="high"><input type="radio" name="priority" value="high"><span>زیاد</span></label>
                            </div>
                        </div>
                    </div>
                    <div class="tm-field">
                        <label class="tm-label"><i class="fas fa-folder tm-label-icon"></i> پروژه</label>
                        <div class="project-picker" id="editProjectPicker">
                            <input type="hidden" name="project_id" value="" id="editProjectInput">
                            <div class="project-picker__trigger" onclick="toggleProjectPicker('editProjectPicker')">
                                <div class="project-picker__avatar is-empty" id="editProjectAvatar"><i class="fas fa-folder"></i></div>
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
                        <label class="tm-label"><i class="fas fa-user tm-label-icon"></i> مسئول وظیفه</label>
                        <div class="assignee-picker" id="editAssigneePicker">
                            <input type="hidden" name="assignee_id" value="<?= (int)$userId ?>" id="editAssigneeInput">
                            <div class="assignee-picker__trigger" onclick="toggleAssigneePicker('editAssigneePicker')">
                                <div class="assignee-picker__avatar is-self" id="editAssigneeAvatar">
                                    <?php if ($currentUserAvatar): ?><img src="<?= htmlspecialchars($currentUserAvatar, ENT_QUOTES, 'UTF-8') ?>" alt=""><?php else: ?><?= htmlspecialchars(mb_substr($currentUserFirstName, 0, 1, 'UTF-8'), ENT_QUOTES, 'UTF-8') ?><?php endif; ?>
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
                    <div class="tm-row">
                        <div class="tm-field" style="margin-bottom: 12px;">
                            <label class="tm-label"><i class="fas fa-calendar tm-label-icon"></i> تاریخ مهلت</label>
                            <div class="date-picker-wrap" id="edit_due_date_wrap">
                                <input type="text" id="edit_due_date_display" class="tm-input date-display-input" placeholder="انتخاب تاریخ..." autocomplete="off" readonly>
                                <span class="date-picker-icon"><i class="fas fa-calendar-alt"></i></span>
                                <button type="button" class="date-clear-btn" onclick="clearPersianDate('edit')"><i class="fas fa-times"></i></button>
                                <input type="hidden" name="due_date" id="edit_due_date" value="">
                            </div>
                        </div>
                        <div class="tm-field" style="margin-bottom: 12px;">
                            <label class="tm-label"><i class="fas fa-clock tm-label-icon"></i> ساعت مهلت</label>
                            <input type="time" name="due_time" id="edit_due_time" class="tm-input time-input" autocomplete="off">
                        </div>
                    </div>
                    <div class="modal-attachments-section">
                        <div class="modal-attachments-header"><span class="ma-icon"><i class="fas fa-paperclip"></i></span> فایل‌های پیوست <span class="ma-count" id="modalAttachmentsCount">۰</span></div>
                        <div class="modal-attachments-list" id="modalAttachmentsList">
                            <div class="modal-attachments-loading">در حال بارگذاری...</div>
                        </div>
                    </div>
                    <div class="tm-field">
                        <label class="tm-label"><i class="fas fa-plus-circle tm-label-icon"></i> افزودن فایل جدید</label>
                        <div class="file-upload-zone" id="editFileDropZone">
                            <input type="file" name="task_attachments[]" id="editFileInput" multiple accept="<?= htmlspecialchars($allowedExtsAttr, ENT_QUOTES, 'UTF-8') ?>">
                            <div class="file-upload-zone__icon"><i class="fas fa-cloud-upload-alt"></i></div>
                            <div class="file-upload-zone__title">کلیک کنید یا فایل‌ها را رها کنید</div>
                        </div>
                        <ul class="file-preview-list" id="editFilePreviewList"></ul>
                    </div>
                    <div class="modal-notes-section" id="modalNotesSection">
                        <div class="modal-notes-header">
                            <div class="modal-notes-header-icon"><i class="fas fa-comments"></i></div> گزارش‌ها <span class="modal-notes-header-count" id="modalNotesCount">۰</span>
                        </div>
                        <div class="modal-notes-list" id="modalNotesList">
                            <p class="modal-notes-empty">در حال بارگذاری...</p>
                        </div>
                        <div class="modal-note-input-wrap">
                            <div class="modal-note-mentions-preview" id="modalNoteMentionsPreview"></div>
                            <div class="modal-note-files-preview" id="modalNoteFilesPreview"></div>
                            <div class="modal-note-input-row">
                                <label class="modal-note-attach-btn" title="فایل پیوست"><i class="fas fa-paperclip"></i><input type="file" id="modalNoteFileInput" multiple style="display:none" accept=".pdf,.xls,.xlsx,.csv,.doc,.docx,.txt,.rtf,.jpg,.jpeg,.png,.gif,.webp,.bmp,.zip,.rar,.7z"></label>
                                <button type="button" class="modal-note-attach-btn" id="modalNoteMentionBtn" onclick="toggleMentionPicker(event)"><i class="fas fa-at"></i></button>
                                <textarea id="modalNoteText" placeholder="متن گزارش..." maxlength="5000"></textarea>
                                <button type="button" class="modal-note-submit" id="modalNoteSubmitBtn" onclick="submitModalNote()"><i class="fas fa-paper-plane"></i></button>
                            </div>
                            <div class="modal-note-mention-picker" id="modalNoteMentionPicker" style="display:none;">
                                <div class="mention-picker__header"><i class="fas fa-at"></i> انتخاب همکار</div>
                                <div class="mention-picker__list" id="mentionPickerList"></div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="tm-footer">
                    <button type="button" class="tm-btn-cancel" onclick="requestCloseEditModal()"><i class="fas fa-times" style="margin-left: 6px;"></i> بستن</button>
                    <button type="submit" name="update_task" class="tm-btn-save" id="edit_submit_btn"><i class="fas fa-save"></i> ذخیره تغییرات</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ========== Confirm Close Modal ========== -->
    <div id="confirmCloseModal" class="modal-overlay" onclick="if(event.target === this) closeModal('confirmCloseModal')">
        <div class="modal-box" style="max-width: 440px;" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h3><span style="width: 38px; height: 38px; border-radius: 12px; background: linear-gradient(135deg, #ffa751, #ff8a3d); color: #fff; display: inline-flex; align-items: center; justify-content: center; margin-left: 10px;"><i class="fas fa-exclamation-triangle"></i></span> تغییرات ذخیره نشده</h3><button class="modal-close" onclick="closeModal('confirmCloseModal')">&times;</button>
            </div>
            <p style="line-height: 1.9; margin-bottom: 20px;">تغییراتی اعمال کرده‌اید که ذخیره نشده. مطمئن هستید؟</p>
            <div style="display: flex; gap: 10px; justify-content: flex-end; flex-wrap: wrap;">
                <button type="button" class="tm-btn-cancel" onclick="closeModal('confirmCloseModal')">بازگشت</button>
                <button type="button" class="tm-btn-cancel" id="confirmCloseDiscard" onclick="forceCloseEditModal()" style="background: #ffe4ec; color: #c81e4a;">بستن بدون ذخیره</button>
                <button type="button" class="tm-btn-save" id="confirmCloseSave" onclick="saveAndCloseEditModal()"><i class="fas fa-save"></i> ذخیره</button>
            </div>
        </div>
    </div>

    <!-- ========== Info Modal ========== -->
    <div id="taskInfoModal" class="modal-overlay">
        <div class="modal-box info-modal-box">
            <div class="modal-header">
                <h3><span class="info-modal-header-icon"><i class="fas fa-info-circle"></i></span> جزئیات وظیفه</h3><button class="modal-close" onclick="closeModal('taskInfoModal')">&times;</button>
            </div>
            <div class="info-task-title" id="info_task_title">—</div>
            <div class="info-grid">
                <div class="info-row"><span class="info-row__label"><i class="fas fa-folder purple"></i> پروژه</span><span class="info-row__value" id="info_project">—</span></div>
                <div class="info-row"><span class="info-row__label"><i class="fas fa-tag teal"></i> موضوع</span><span class="info-row__value" id="info_subject">—</span></div>
                <div class="info-row"><span class="info-row__label"><i class="fas fa-flag orange"></i> اولویت</span><span class="info-row__value" id="info_priority">—</span></div>
                <div class="info-row"><span class="info-row__label"><i class="fas fa-user-check blue"></i> مسئول</span><span class="info-row__value rtl" id="info_assignee">—</span></div>
                <div class="info-row"><span class="info-row__label"><i class="fas fa-user-pen green"></i> ایجاد کننده</span><span class="info-row__value rtl" id="info_creator">—</span></div>
                <div class="info-row"><span class="info-row__label"><i class="fas fa-calendar-day pink"></i> مهلت</span><span class="info-row__value rtl" id="info_due">—</span></div>
                <div class="info-row"><span class="info-row__label"><i class="fas fa-paperclip teal"></i> پیوست‌ها</span><span class="info-row__value" id="info_attachments">—</span></div>
            </div>
        </div>
    </div>

    <!-- ========== Edit Subject Modal ========== -->
    <div id="editSubjectModal" class="modal-overlay">
        <div class="modal-box" style="max-width: 400px;">
            <div class="modal-header">
                <h3>ویرایش موضوع</h3><button class="modal-close" onclick="closeModal('editSubjectModal')">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="form_token" value="<?= htmlspecialchars($formTokenSubject, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="subject_id" id="edit_subject_id_field">
                <div class="form-group"><label>عنوان موضوع</label><input type="text" name="subject_title" id="edit_subject_title_field" required maxlength="100"></div>
                <button type="submit" name="update_subject" class="btn" style="width: 100%;">ذخیره</button>
            </form>
        </div>
    </div>

    <div class="app-toast" id="appToast"><i class="fas fa-check-circle"></i><span id="appToastText"></span></div>

    <script>
        /* ============================================================
   CHAT PAGE SCRIPT
   صفحه چت — تمام منطق محلی (UI، پیام‌ها، جستجو، لایت‌باکس، ...)
   نوتیفیکیشن‌های سراسری توسط assets/js/chat_notifications.js مدیریت می‌شود.
============================================================ */

        const CURRENT_USER_ID = <?= (int)$userId ?>;
        const CHAT_LIST = <?= $chatListJson ?>;
        const AUTO_OPEN_CHAT_ID = <?= (int)$autoOpenChatId ?>;

        const MAX_FILE_SIZE = 50 * 1024 * 1024;
        const ALLOWED_EXTENSIONS = ['png', 'jpg', 'jpeg', 'pdf', 'zip', 'txt'];
        const FILE_BASE_URL = '../uploads/chat_files/';
        const PAGE_SIZE = 30;

        const FILE_ICONS = {
            'png': 'fa-file-image',
            'jpg': 'fa-file-image',
            'jpeg': 'fa-file-image',
            'pdf': 'fa-file-pdf',
            'zip': 'fa-file-archive',
            'txt': 'fa-file-alt'
        };

        /* ===================== STATE ===================== */
        let selectedFile = null;
        let currentChatUserId = null;
        let currentIsSaved = false;
        let currentIsBot = false;
        let pollTimer = null;
        let statusTimer = null;
        let statusRequestInFlight = false;
        let sendingMessage = false;
        let lastRenderedDay = null;
        let loadedMessageIds = new Set();
        let firstLoadedMsgId = 0;
        let lastLoadedMsgId = 0;
        let hasMoreBefore = false;
        let hasMoreAfter = false;
        let loadingOlder = false;
        let isAtBottom = true;
        let pendingHighlightMsgId = null;

        /* ===================== LIGHTBOX STATE ===================== */
        let lightboxZoom = 1;
        const ZOOM_MIN = 0.25,
            ZOOM_MAX = 5,
            ZOOM_STEP = 0.25;
        let lightboxCurrentUrl = '',
            lightboxCurrentName = '';
        let lightboxPanX = 0,
            lightboxPanY = 0;
        let isDragging = false,
            dragStartX = 0,
            dragStartY = 0;

        /* ===================== EDIT / DELETE WINDOW ===================== */
        const EDIT_DELETE_WINDOW_MS = 60 * 60 * 1000;
        let contextMenuMsgData = null;
        let pendingDeleteMsgId = null;
        let editingMsgId = null;

        /* ===================== SEARCH ===================== */
        let searchScope = 'all';
        let searchTimer = null;
        let searchAbortController = null;
        let lastSearchResults = [];
        let searchInputTouched = false;

        /* ===================== HELPERS ===================== */
        function parseServerTime(s) {
            if (!s) return 0;
            const ts = Date.parse(String(s).replace(' ', 'T'));
            return isNaN(ts) ? 0 : ts;
        }

        function escapeHtml(t) {
            if (t === null || t === undefined) return '';
            const m = {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            };
            return String(t).replace(/[&<>"']/g, c => m[c]);
        }

        function toPersianDigits(n) {
            const fa = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
            return String(n).replace(/\d/g, d => fa[parseInt(d, 10)]);
        }

        /* ===================== PERMISSIONS ===================== */
        function checkMessagePermissions(el) {
            const senderId = parseInt(el.getAttribute('data-sender-id'), 10);
            const createdMs = parseInt(el.getAttribute('data-created-ms'), 10);
            const currentUserId = parseInt(CURRENT_USER_ID, 10);
            const isMine = !isNaN(senderId) && !isNaN(currentUserId) && senderId === currentUserId;
            const now = Date.now();
            const elapsed = now - createdMs;
            const isWithinWindow = !isNaN(createdMs) && createdMs > 0 && elapsed >= 0 && elapsed <= EDIT_DELETE_WINDOW_MS;

            return {
                isMine,
                createdMs,
                elapsed,
                isWithinWindow,
                hasFile: !!el.querySelector('.chat-file-card, .chat-image-wrapper'),
                hasText: (() => {
                    const divs = el.children;
                    for (let i = 0; i < divs.length; i++) {
                        const c = divs[i];
                        if (c.classList.contains('chat-bubble__time')) continue;
                        if (c.classList.contains('chat-bubble__attachment')) continue;
                        if (c.classList.contains('task-notif-header')) continue;
                        if (c.classList.contains('task-notif-actions')) continue;
                        if (c.tagName === 'DIV' && (c.innerText || c.textContent || '').trim()) return true;
                    }
                    return false;
                })(),
            };
        }

        function extractMessageDataFromBubble(el) {
            const msgId = parseInt(el.getAttribute('data-msg-id'), 10) || 0;
            const senderId = parseInt(el.getAttribute('data-sender-id'), 10) || 0;
            const createdMs = parseInt(el.getAttribute('data-created-ms'), 10) || 0;

            let messageText = '';
            const divs = el.children;
            for (let i = 0; i < divs.length; i++) {
                const c = divs[i];
                if (c.classList.contains('chat-bubble__time')) continue;
                if (c.classList.contains('chat-bubble__attachment')) continue;
                if (c.classList.contains('task-notif-header')) continue;
                if (c.classList.contains('task-notif-actions')) continue;
                if (c.tagName === 'DIV') {
                    messageText = c.innerText || c.textContent || '';
                    break;
                }
            }

            let attachmentUrl = null,
                attachmentName = null;
            const fileCard = el.querySelector('.chat-file-card');
            const imgWrap = el.querySelector('.chat-image-wrapper');
            if (fileCard) {
                attachmentUrl = fileCard.getAttribute('href');
                const nameEl = fileCard.querySelector('.chat-file-card__name');
                attachmentName = nameEl ? nameEl.textContent.trim() : 'file';
            } else if (imgWrap) {
                const img = imgWrap.querySelector('img');
                if (img) {
                    attachmentUrl = img.getAttribute('src');
                    attachmentName = img.getAttribute('alt') || 'image';
                }
            }

            return {
                id: msgId,
                sender_id: senderId,
                message: messageText,
                attachment: attachmentUrl,
                attachment_name: attachmentName,
                createdAtMs: createdMs
            };
        }

        /* ===================== CONTEXT MENU ===================== */
        function openContextMenu(e, bubbleEl) {
            e.preventDefault();
            e.stopPropagation();
            const msgId = parseInt(bubbleEl.getAttribute('data-msg-id'), 10);
            if (!msgId) return;

            const perm = checkMessagePermissions(bubbleEl);
            contextMenuMsgData = extractMessageDataFromBubble(bubbleEl);

            document.querySelectorAll('.chat-bubble.context-active').forEach(el => el.classList.remove('context-active'));
            bubbleEl.classList.add('context-active');

            const menu = document.getElementById('msgContextMenu');
            document.getElementById('ctxDownloadBtn').style.display = perm.hasFile ? 'flex' : 'none';

            const showEdit = perm.isMine && perm.hasText && !perm.hasFile && perm.isWithinWindow;
            const showDelete = perm.isMine && perm.isWithinWindow;

            document.getElementById('ctxEditBtn').style.display = showEdit ? 'flex' : 'none';
            document.getElementById('ctxEditDivider').style.display = showEdit ? 'block' : 'none';
            document.getElementById('ctxDeleteBtn').style.display = showDelete ? 'flex' : 'none';
            document.getElementById('ctxDeleteDivider').style.display = showDelete ? 'block' : 'none';

            if (!perm.hasText && !perm.hasFile && !showEdit && !showDelete) {
                bubbleEl.classList.remove('context-active');
                return;
            }

            menu.style.left = '0px';
            menu.style.top = '0px';
            menu.classList.add('active');

            requestAnimationFrame(() => {
                const r = menu.getBoundingClientRect();
                let x = e.clientX,
                    y = e.clientY;
                if (x + r.width > window.innerWidth - 10) x = window.innerWidth - r.width - 10;
                if (x < 10) x = 10;
                if (y + r.height > window.innerHeight - 10) y = window.innerHeight - r.height - 10;
                if (y < 10) y = 10;
                menu.style.left = x + 'px';
                menu.style.top = y + 'px';
            });
        }

        function closeContextMenu() {
            document.getElementById('msgContextMenu').classList.remove('active');
            document.querySelectorAll('.chat-bubble.context-active').forEach(el => el.classList.remove('context-active'));
            contextMenuMsgData = null;
        }

        function handleContextAction(action, e) {
            if (e) {
                e.preventDefault();
                e.stopPropagation();
            }
            if (!contextMenuMsgData) return;
            const data = {
                ...contextMenuMsgData
            };
            closeContextMenu();

            switch (action) {
                case 'copy':
                    copyMessageText(data.message);
                    break;
                case 'download':
                    downloadAttachment(data.attachment, data.attachment_name);
                    break;
                case 'edit':
                    openEditModal(data);
                    break;
                case 'delete':
                    openDeleteModal(data.id);
                    break;
            }
        }

        function copyMessageText(text) {
            if (!text || !text.trim()) {
                showToast('متنی برای کپی وجود ندارد', 'error');
                return;
            }
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text)
                    .then(() => showToast('متن پیام کپی شد', 'success'))
                    .catch(() => fallbackCopy(text));
            } else fallbackCopy(text);
        }

        function fallbackCopy(text) {
            const ta = document.createElement('textarea');
            ta.value = text;
            ta.style.cssText = 'position:fixed;opacity:0;top:0;left:0;';
            document.body.appendChild(ta);
            ta.focus();
            ta.select();
            try {
                document.execCommand('copy');
                showToast('متن پیام کپی شد', 'success');
            } catch (e) {
                showToast('کپی نشد', 'error');
            }
            document.body.removeChild(ta);
        }

        function downloadAttachment(url, name) {
            if (!url) {
                showToast('فایلی برای دانلود وجود ندارد', 'error');
                return;
            }
            showToast('در حال آماده‌سازی دانلود...', 'info');
            fetch(url)
                .then(r => {
                    if (!r.ok) throw 0;
                    return r.blob();
                })
                .then(blob => {
                    const bUrl = URL.createObjectURL(blob);
                    const a = document.createElement('a');
                    a.href = bUrl;
                    a.download = name || 'file';
                    document.body.appendChild(a);
                    a.click();
                    document.body.removeChild(a);
                    setTimeout(() => URL.revokeObjectURL(bUrl), 1000);
                    showToast('دانلود شروع شد', 'success');
                })
                .catch(() => {
                    window.open(url, '_blank');
                    showToast('فایل در تب جدید باز شد', 'info');
                });
        }

        /* ===================== DELETE / EDIT MODAL ===================== */
        function openDeleteModal(msgId) {
            pendingDeleteMsgId = msgId;
            document.getElementById('deleteModal').classList.add('active');
            const btn = document.getElementById('confirmDeleteBtn');
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-trash-alt"></i> بله، حذف کن';
        }

        function closeDeleteModal() {
            document.getElementById('deleteModal').classList.remove('active');
            pendingDeleteMsgId = null;
        }

        function confirmDeleteMessage() {
            if (!pendingDeleteMsgId) return;
            const btn = document.getElementById('confirmDeleteBtn');
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> در حال حذف...';

            const fd = new FormData();
            fd.append('action', 'delete');
            fd.append('message_id', pendingDeleteMsgId);

            fetch('api.php', {
                    method: 'POST',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: fd
                })
                .then(r => r.json())
                .then(data => {
                    if (data.status === 'success') {
                        const b = document.querySelector(`.chat-bubble[data-msg-id="${pendingDeleteMsgId}"]`);
                        if (b) {
                            b.style.transition = 'all 0.3s ease';
                            b.style.opacity = '0';
                            b.style.transform = 'translateX(-30px) scale(0.9)';
                            setTimeout(() => b.remove(), 300);
                        }
                        loadedMessageIds.delete(Number(pendingDeleteMsgId));
                        closeDeleteModal();
                        showToast('پیام برای هر دو طرف حذف شد', 'success');
                        refreshSavedBadge();
                    } else {
                        showToast(data.message || 'خطا در حذف پیام', 'error');
                        btn.disabled = false;
                        btn.innerHTML = '<i class="fas fa-trash-alt"></i> بله، حذف کن';
                    }
                })
                .catch(() => {
                    showToast('خطا در ارتباط با سرور', 'error');
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-trash-alt"></i> بله، حذف کن';
                });
        }

        function openEditModal(data) {
            editingMsgId = data.id;
            document.getElementById('editMessageTextarea').value = data.message || '';
            document.getElementById('editModal').classList.add('active');
            setTimeout(() => {
                const ta = document.getElementById('editMessageTextarea');
                ta.focus();
                ta.setSelectionRange(ta.value.length, ta.value.length);
            }, 200);
            const btn = document.getElementById('confirmEditBtn');
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-check"></i> ذخیره تغییرات';
        }

        function closeEditModal() {
            document.getElementById('editModal').classList.remove('active');
            editingMsgId = null;
        }

        function confirmEditMessage() {
            if (!editingMsgId) return;
            const newText = document.getElementById('editMessageTextarea').value.trim();
            if (!newText) {
                showToast('متن پیام نمی‌تواند خالی باشد', 'error');
                return;
            }

            const btn = document.getElementById('confirmEditBtn');
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> در حال ذخیره...';

            const fd = new FormData();
            fd.append('action', 'edit');
            fd.append('message_id', editingMsgId);
            fd.append('message', newText);

            fetch('api.php', {
                    method: 'POST',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: fd
                })
                .then(r => r.json())
                .then(data => {
                    if (data.status === 'success') {
                        updateBubbleText(editingMsgId, newText);
                        closeEditModal();
                        showToast('پیام ویرایش شد', 'success');
                    } else {
                        showToast(data.message || 'خطا در ویرایش', 'error');
                        btn.disabled = false;
                        btn.innerHTML = '<i class="fas fa-check"></i> ذخیره تغییرات';
                    }
                })
                .catch(() => {
                    showToast('خطا در ارتباط با سرور', 'error');
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-check"></i> ذخیره تغییرات';
                });
        }

        function updateBubbleText(msgId, newText) {
            const bubble = document.querySelector(`.chat-bubble[data-msg-id="${msgId}"]`);
            if (!bubble) return;
            const divs = bubble.children;
            for (let i = 0; i < divs.length; i++) {
                const c = divs[i];
                if (c.classList.contains('chat-bubble__time')) continue;
                if (c.classList.contains('chat-bubble__attachment')) continue;
                if (c.classList.contains('task-notif-header')) continue;
                if (c.classList.contains('task-notif-actions')) continue;
                if (c.tagName === 'DIV') {
                    c.innerHTML = escapeHtml(newText).replace(/\n/g, '<br>');
                    break;
                }
            }
            const timeEl = bubble.querySelector('.chat-bubble__time');
            if (timeEl && !bubble.querySelector('.chat-bubble__edited')) {
                const sp = document.createElement('span');
                sp.className = 'chat-bubble__edited';
                sp.textContent = '(ویرایش‌شده)';
                timeEl.insertBefore(sp, timeEl.firstChild);
            }
            bubble.setAttribute('data-edited', '1');
        }

        /* ===================== TOAST ===================== */
        let toastTimer = null;

        function showToast(text, type = 'success') {
            const toast = document.getElementById('chatToast');
            document.getElementById('chatToastText').textContent = text;
            const icon = toast.querySelector('i');
            icon.className = 'fas';
            toast.classList.remove('chat-toast--success', 'chat-toast--error', 'chat-toast--info');

            if (type === 'success') {
                icon.classList.add('fa-check-circle');
                toast.classList.add('chat-toast--success');
            } else if (type === 'error') {
                icon.classList.add('fa-exclamation-circle');
                toast.classList.add('chat-toast--error');
            } else {
                icon.classList.add('fa-info-circle');
                toast.classList.add('chat-toast--info');
            }

            toast.classList.add('active');
            clearTimeout(toastTimer);
            toastTimer = setTimeout(() => toast.classList.remove('active'), 2400);
        }

        /* ======================================================
           ⭐ OPEN CHAT
           ====================================================== */
        function openChat(userId, element) {
            if (currentChatUserId === userId && !pendingHighlightMsgId) return;

            /* ⭐ اطلاع به سیستم نوتیف سراسری که کاربر در این چت است
               این کار قبل از هر poll انجام می‌شود تا نوتیف تکراری نیاید */
            if (window.ChatNotifications) {
                try {
                    window.ChatNotifications.setActiveChat(userId);
                } catch (e) {}
            }

            const isSaved = element && element.getAttribute('data-is-saved') === '1';
            const isBot = element && element.getAttribute('data-is-bot') === '1';

            currentChatUserId = userId;
            currentIsSaved = isSaved;
            currentIsBot = isBot;

            lastRenderedDay = null;
            loadedMessageIds = new Set();
            firstLoadedMsgId = 0;
            lastLoadedMsgId = 0;
            hasMoreBefore = false;
            hasMoreAfter = false;
            loadingOlder = false;

            document.querySelectorAll('.chat-item').forEach(el => el.classList.remove('active'));
            if (element) element.classList.add('active');

            let person = element ? {
                    name: element.getAttribute('data-name') || '',
                    initial: element.getAttribute('data-initial') || '?',
                    avatar_url: element.getAttribute('data-avatar-url') || null,
                } :
                (CHAT_LIST.find(c => Number(c.user_id) === Number(userId)) || {
                    name: '',
                    initial: '?',
                    avatar_url: null
                });

            if (!element) currentIsSaved = !!person.is_saved;

            document.getElementById('chatHeaderName').textContent = person.name;

            const headerAvatar = document.getElementById('chatHeaderAvatar');
            const headerStatus = document.getElementById('chatHeaderStatus');
            const headerStatusText = document.getElementById('chatHeaderStatusText');
            const chatInputEl = document.getElementById('chatInput');

            headerAvatar.classList.remove('is-saved-avatar', 'is-bot-avatar', 'online', 'offline');

            if (currentIsSaved) {
                headerAvatar.innerHTML = '<i class="fas fa-bookmark"></i>';
                headerAvatar.classList.add('is-saved-avatar');
                headerStatus.className = 'chat-header__status saved';
                headerStatusText.textContent = 'ذخیره‌سازی شخصی';
                chatInputEl.placeholder = 'یادداشت، لینک یا فایل خود را اینجا بنویسید...';
            } else if (currentIsBot) {
                headerAvatar.innerHTML = '<i class="fas fa-robot"></i>';
                headerAvatar.classList.add('is-bot-avatar');
                headerStatus.className = 'chat-header__status bot';
                headerStatusText.textContent = 'اطلاع‌رسانی خودکار وظایف';
                chatInputEl.placeholder = 'این حساب فقط اطلاع‌رسانی می‌کند';
            } else {
                if (person.avatar_url) {
                    headerAvatar.innerHTML = `<img src="${escapeHtml(person.avatar_url)}" alt="${escapeHtml(person.name)}">`;
                } else {
                    headerAvatar.textContent = person.initial || '?';
                }
                chatInputEl.placeholder = 'پیام خود را بنویسید...';
            }

            document.getElementById('chatEmptyState').style.display = 'none';
            document.getElementById('chatActiveArea').style.display = 'flex';
            document.getElementById('chatBody').innerHTML = '';

            const container = document.getElementById('chatContainer');
            if (container) container.classList.add('chat-open');

            const badge = document.querySelector(`.chat-item__badge[data-user-id="${userId}"]`);
            if (badge) badge.remove();

            if (pendingHighlightMsgId) {
                loadMessagesAround(pendingHighlightMsgId);
                pendingHighlightMsgId = null;
            } else {
                loadInitialMessages();
            }

            setTimeout(() => chatInputEl.focus(), 200);

            if (pollTimer) clearInterval(pollTimer);
            pollTimer = setInterval(pollNewMessages, 3000);

            if (statusTimer) clearInterval(statusTimer);
            if (!currentIsSaved && !currentIsBot) {
                fetchUserStatus();
                statusTimer = setInterval(fetchUserStatus, 5000);
            }

            /* ⭐ همگام‌سازی با poller سراسری: یک‌بار دیگر poll کن
               تا نوتیف‌های pending این کاربر پاک شوند */
            if (window.ChatNotifications && typeof window.ChatNotifications.pollNow === 'function') {
                setTimeout(() => {
                    try {
                        window.ChatNotifications.pollNow();
                    } catch (e) {}
                }, 400);
            }
        }

        function closeChatOnMobile() {
            /* ⭐ اطلاع به سیستم نوتیف سراسری */
            if (window.ChatNotifications) {
                try {
                    window.ChatNotifications.clearActiveChat();
                } catch (e) {}
            }

            const c = document.getElementById('chatContainer');
            if (c) c.classList.remove('chat-open');

            currentChatUserId = null;
            currentIsSaved = false;
            currentIsBot = false;

            if (pollTimer) {
                clearInterval(pollTimer);
                pollTimer = null;
            }
            if (statusTimer) {
                clearInterval(statusTimer);
                statusTimer = null;
            }

            clearFilePreview();
            closeContextMenu();
        }

        /* ======================================================
           LAZY LOADING
           ====================================================== */
        function loadInitialMessages() {
            if (!currentChatUserId) return;
            const body = document.getElementById('chatBody');
            body.innerHTML = '<div class="chat-load-more loading" id="loadMoreEl"><span class="chat-load-more__spinner"></span><span class="chat-load-more__text">در حال بارگذاری...</span></div>';

            fetch(`api.php?action=fetch&user_id=${currentChatUserId}&mode=initial&limit=${PAGE_SIZE}`, {
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                })
                .then(r => r.json())
                .then(data => {
                    if (data.status !== 'success') {
                        showToast('خطا در دریافت پیام‌ها', 'error');
                        return;
                    }
                    const messages = data.messages || [];
                    hasMoreBefore = !!data.has_more_before;
                    hasMoreAfter = !!data.has_more_after;

                    body.innerHTML = '';
                    lastRenderedDay = null;

                    if (messages.length === 0) {
                        if (currentIsSaved) {
                            body.innerHTML = `<div class="chat-empty"><i class="fas fa-bookmark" style="color:#fbbf24;opacity:0.6;"></i><h3>پیام‌های ذخیره شده</h3><p>اینجا فضای شخصی شماست. می‌توانید یادداشت‌ها، لینک‌ها و فایل‌های خود را ذخیره کنید.</p></div>`;
                        } else if (currentIsBot) {
                            body.innerHTML = `<div class="chat-empty"><i class="fas fa-robot" style="color:#a78bfa;opacity:0.6;"></i><h3>اطلاع‌رسانی تسکام</h3><p>هر زمان شما در وظیفه‌ای منشن شوید، اطلاع‌رسانی آن در اینجا نمایش داده می‌شود.</p></div>`;
                        } else {
                            body.innerHTML = '<div class="chat-day"><span>هنوز پیامی رد و بدل نشده</span></div>';
                        }
                        return;
                    }

                    const loader = document.createElement('div');
                    loader.className = 'chat-load-more';
                    loader.id = 'loadMoreEl';
                    loader.innerHTML = '<span class="chat-load-more__spinner"></span><span class="chat-load-more__text">در حال بارگذاری...</span>';
                    body.appendChild(loader);

                    messages.forEach(msg => {
                        appendMessage(msg);
                        loadedMessageIds.add(Number(msg.id));
                    });

                    if (messages.length > 0) {
                        firstLoadedMsgId = Number(messages[0].id);
                        lastLoadedMsgId = Number(messages[messages.length - 1].id);
                    }

                    updateLoadMoreUI();
                    requestAnimationFrame(() => {
                        body.scrollTop = body.scrollHeight;
                    });
                })
                .catch(() => showToast('خطا در ارتباط با سرور', 'error'));
        }

        function loadOlderMessages() {
            if (!currentChatUserId || loadingOlder || !hasMoreBefore || firstLoadedMsgId <= 0) return;
            loadingOlder = true;

            const body = document.getElementById('chatBody');
            const loader = document.getElementById('loadMoreEl');
            if (loader) loader.classList.add('loading');

            const prevScrollHeight = body.scrollHeight;
            const prevScrollTop = body.scrollTop;

            fetch(`api.php?action=fetch&user_id=${currentChatUserId}&mode=older&before_id=${firstLoadedMsgId}&limit=${PAGE_SIZE}`, {
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                })
                .then(r => r.json())
                .then(data => {
                    if (data.status !== 'success') return;
                    const messages = data.messages || [];
                    hasMoreBefore = !!data.has_more_before;

                    if (messages.length === 0) {
                        updateLoadMoreUI();
                        return;
                    }

                    firstLoadedMsgId = Number(messages[0].id);
                    const frag = buildMessagesFragment(messages);
                    messages.forEach(m => loadedMessageIds.add(Number(m.id)));

                    const loaderEl = document.getElementById('loadMoreEl');
                    if (loaderEl) loaderEl.insertAdjacentElement('afterend', frag);
                    else body.insertBefore(frag, body.firstChild);

                    requestAnimationFrame(() => {
                        const newScrollHeight = body.scrollHeight;
                        body.scrollTop = prevScrollTop + (newScrollHeight - prevScrollHeight);
                    });

                    updateLoadMoreUI();
                })
                .catch(() => {})
                .finally(() => {
                    loadingOlder = false;
                });
        }

        function loadMessagesAround(targetId) {
            const body = document.getElementById('chatBody');
            body.innerHTML = '<div class="chat-load-more loading"><span class="chat-load-more__spinner"></span><span class="chat-load-more__text">در حال بارگذاری...</span></div>';

            fetch(`api.php?action=fetch&user_id=${currentChatUserId}&mode=around&target_id=${targetId}&limit=${PAGE_SIZE}`, {
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                })
                .then(r => r.json())
                .then(data => {
                    if (data.status !== 'success') return;
                    const messages = data.messages || [];
                    hasMoreBefore = !!data.has_more_before;
                    hasMoreAfter = !!data.has_more_after;

                    body.innerHTML = '';
                    lastRenderedDay = null;

                    const loader = document.createElement('div');
                    loader.className = 'chat-load-more';
                    loader.id = 'loadMoreEl';
                    loader.innerHTML = '<span class="chat-load-more__spinner"></span><span class="chat-load-more__text">در حال بارگذاری...</span>';
                    body.appendChild(loader);

                    messages.forEach(msg => {
                        appendMessage(msg);
                        loadedMessageIds.add(Number(msg.id));
                    });

                    if (messages.length > 0) {
                        firstLoadedMsgId = Number(messages[0].id);
                        lastLoadedMsgId = Number(messages[messages.length - 1].id);
                    }

                    updateLoadMoreUI();

                    setTimeout(() => {
                        const target = document.querySelector(`.chat-bubble[data-msg-id="${targetId}"]`);
                        if (target) {
                            target.scrollIntoView({
                                block: 'center',
                                behavior: 'auto'
                            });
                            target.classList.add('search-highlight');
                            setTimeout(() => target.classList.remove('search-highlight'), 2400);
                        }
                    }, 150);
                })
                .catch(() => {});
        }

        function pollNewMessages() {
            if (!currentChatUserId) return;
            if (lastLoadedMsgId <= 0) return;

            fetch(`api.php?action=fetch&user_id=${currentChatUserId}&mode=newer&after_id=${lastLoadedMsgId}&limit=50`, {
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                })
                .then(r => r.json())
                .then(data => {
                    if (data.status !== 'success') return;
                    const messages = data.messages || [];
                    if (messages.length === 0) return;

                    const body = document.getElementById('chatBody');
                    isAtBottom = (body.scrollHeight - body.scrollTop - body.clientHeight) < 80;

                    let hasNewFromMe = false;
                    messages.forEach(msg => {
                        const msgId = Number(msg.id);
                        if (!loadedMessageIds.has(msgId)) {
                            appendMessage(msg);
                            loadedMessageIds.add(msgId);
                            if (Number(msg.sender_id) === CURRENT_USER_ID) hasNewFromMe = true;
                        } else {
                            updateMessageReadStatus(msgId, Number(msg.is_read));
                            updateMessageTextIfEdited(msgId, msg);
                        }
                    });
                    lastLoadedMsgId = Math.max(lastLoadedMsgId, Number(messages[messages.length - 1].id));

                    if (isAtBottom || hasNewFromMe) {
                        requestAnimationFrame(() => {
                            body.scrollTop = body.scrollHeight;
                        });
                    }
                })
                .catch(() => {});
        }

        function updateLoadMoreUI() {
            const loader = document.getElementById('loadMoreEl');
            if (!loader) return;
            loader.classList.remove('loading');
            if (!hasMoreBefore) {
                loader.classList.add('no-more');
                loader.querySelector('.chat-load-more__text').textContent = 'ابتدای گفتگو';
            } else {
                loader.querySelector('.chat-load-more__text').textContent = 'برای دیدن پیام‌های قدیمی‌تر اسکرول کنید';
            }
        }

        function updateMessageTextIfEdited(msgId, msg) {
            const b = document.querySelector(`.chat-bubble[data-msg-id="${msgId}"]`);
            if (!b) return;
            if (Number(msg.is_edited) === 1 && msg.message) {
                const divs = b.children;
                let textDiv = null;
                for (let i = 0; i < divs.length; i++) {
                    const c = divs[i];
                    if (c.classList.contains('chat-bubble__time')) continue;
                    if (c.classList.contains('chat-bubble__attachment')) continue;
                    if (c.classList.contains('task-notif-header')) continue;
                    if (c.classList.contains('task-notif-actions')) continue;
                    if (c.tagName === 'DIV') {
                        textDiv = c;
                        break;
                    }
                }
                const cur = textDiv ? (textDiv.innerText || textDiv.textContent || '') : '';
                if (cur !== msg.message) {
                    if (textDiv) textDiv.innerHTML = escapeHtml(msg.message).replace(/\n/g, '<br>');
                    const timeEl = b.querySelector('.chat-bubble__time');
                    if (timeEl && !b.querySelector('.chat-bubble__edited')) {
                        const sp = document.createElement('span');
                        sp.className = 'chat-bubble__edited';
                        sp.textContent = '(ویرایش‌شده)';
                        timeEl.insertBefore(sp, timeEl.firstChild);
                    }
                }
            }
        }

        function updateMessageReadStatus(msgId, isRead) {
            const b = document.querySelector(`.chat-bubble[data-msg-id="${msgId}"]`);
            if (!b) return;
            const c = b.querySelector('.chat-bubble__time .check');
            if (!c) return;
            const wasRead = c.classList.contains('read');
            const nowRead = isRead === 1;
            if (wasRead === nowRead) return;
            if (nowRead) {
                c.classList.remove('fa-check');
                c.classList.add('fa-check-double', 'read');
            } else {
                c.classList.remove('fa-check-double', 'read');
                c.classList.add('fa-check');
            }
        }

        /* ===================== FILE ===================== */
        function getFileExtension(n) {
            return n.split('.').pop().toLowerCase();
        }

        function getFileIcon(e) {
            return FILE_ICONS[e] || 'fa-file';
        }

        function formatFileSize(b) {
            if (!b || b === 0) return '0 B';
            const u = ['B', 'KB', 'MB', 'GB'];
            const i = Math.floor(Math.log(b) / Math.log(1024));
            return (b / Math.pow(1024, i)).toFixed(1) + ' ' + u[i];
        }

        function handleFileSelect(input) {
            if (!input.files || !input.files[0]) {
                clearFilePreview();
                return;
            }
            const f = input.files[0];
            const ext = getFileExtension(f.name);

            if (f.size > MAX_FILE_SIZE) {
                alert('حجم فایل نباید بیشتر از ۵۰ مگابایت باشد.');
                input.value = '';
                clearFilePreview();
                return;
            }
            if (!ALLOWED_EXTENSIONS.includes(ext)) {
                alert('فرمت فایل مجاز نیست.\nفرمت‌های مجاز: PNG, JPG, PDF, ZIP, TXT');
                input.value = '';
                clearFilePreview();
                return;
            }

            selectedFile = f;

            const preview = document.getElementById('filePreview');
            const iconEl = document.getElementById('filePreviewIcon');
            const nameEl = document.getElementById('filePreviewName');
            const sizeEl = document.getElementById('filePreviewSize');

            iconEl.className = 'file-preview__icon';
            if (ext === 'pdf') iconEl.classList.add('type-pdf');
            else if (ext === 'zip') iconEl.classList.add('type-zip');
            else if (ext === 'txt') iconEl.classList.add('type-txt');
            else if (['png', 'jpg', 'jpeg'].includes(ext)) iconEl.classList.add('type-' + (ext === 'jpeg' ? 'jpg' : ext));

            iconEl.innerHTML = `<i class="fas ${getFileIcon(ext)}"></i>`;
            nameEl.textContent = f.name;
            sizeEl.textContent = formatFileSize(f.size);

            preview.classList.add('active');
            setTimeout(() => document.getElementById('chatInput').focus(), 100);
        }

        function clearFilePreview() {
            selectedFile = null;
            document.getElementById('fileInput').value = '';
            document.getElementById('filePreview').classList.remove('active');
        }

        function updateUploadProgress(p) {
            const w = document.getElementById('uploadProgress');
            const b = document.getElementById('uploadProgressBar');
            if (p > 0 && p < 100) {
                w.classList.add('active');
                b.style.width = p + '%';
            } else if (p >= 100) {
                b.style.width = '100%';
                setTimeout(() => {
                    w.classList.remove('active');
                    b.style.width = '0%';
                }, 300);
            } else {
                w.classList.remove('active');
                b.style.width = '0%';
            }
        }

        /* ===================== MESSAGE RENDER ===================== */
        function createMessageElement(msg) {
            const senderIdNum = Number(msg.sender_id);
            const isSent = senderIdNum === CURRENT_USER_ID;

            let cls;
            if (currentIsSaved) cls = 'saved-self';
            else cls = isSent ? 'sent' : 'received';

            const createdMs = parseServerTime(msg.created_at);
            const dateObj = createdMs ? new Date(createdMs) : new Date();

            let checkIcon = '';
            if (isSent && !currentIsSaved) {
                checkIcon = Number(msg.is_read) === 1 ?
                    '<i class="fas fa-check-double check read"></i>' :
                    '<i class="fas fa-check check"></i>';
            } else if (currentIsSaved) {
                checkIcon = '<i class="fas fa-bookmark" style="font-size:0.65rem;color:#d97706;"></i>';
            }

            let contentHtml = '';
            let isTaskNotification = false;
            let taskBtnHtml = '';

            if (msg.message && msg.message.trim() !== '') {
                let rawMsg = msg.message;

                rawMsg = rawMsg.replace(/\[\[task:([a-fA-F0-9]+)\]\]/g, function(m, token) {
                    isTaskNotification = true;
                    const url = '../tasks/index.php?task=' + encodeURIComponent(token);
                    taskBtnHtml += '<a href="' + url + '" class="chat-task-action-btn" target="_blank" rel="noopener"><i class="fas fa-external-link-alt"></i> باز کردن وظیفه</a>';
                    return '';
                });

                rawMsg = rawMsg.replace(/\[\[call:([a-fA-F0-9]+)\]\]/g, function(m, token) {
                    isTaskNotification = true;
                    const url = '../call_requests/index.php?call=' + encodeURIComponent(token);
                    taskBtnHtml += '<a href="' + url + '" class="chat-task-action-btn" style="background:linear-gradient(135deg,#f59e0b,#d97706);box-shadow:0 4px 12px rgba(245,158,11,0.4);" target="_blank" rel="noopener"><i class="fas fa-phone-volume"></i> باز کردن درخواست تماس</a>';
                    return '';
                });

                rawMsg = rawMsg.replace(/\s+$/, '').replace(/\n{3,}/g, '\n\n');

                if (isTaskNotification) {
                    let headerLabel = 'اطلاع‌رسانی';
                    let headerIcon = 'fa-bell';
                    let headerColor = '';

                    if (rawMsg.indexOf('📌') === 0 || rawMsg.indexOf('📌 وظیفه جدید') !== -1) {
                        headerLabel = 'وظیفه جدید';
                        headerIcon = 'fa-plus-circle';
                        headerColor = 'style="color:#059669;"';
                    } else if (rawMsg.indexOf('📞') === 0 || rawMsg.indexOf('📞 درخواست تماس') !== -1) {
                        headerLabel = 'درخواست تماس جدید';
                        headerIcon = 'fa-phone-volume';
                        headerColor = 'style="color:#d97706;"';
                    } else if (rawMsg.indexOf('📝') === 0 || rawMsg.indexOf('📝 یادداشت') !== -1) {
                        headerLabel = 'یادداشت جدید';
                        headerIcon = 'fa-comment-dots';
                        headerColor = 'style="color:#7c3aed;"';
                    }

                    const headerHtml = '<div class="task-notif-header"><span class="tn-icon" ' + headerColor + '><i class="fas ' + headerIcon + '"></i></span><span>' + headerLabel + '</span></div>';
                    const bodyHtml = rawMsg.trim() ? '<div class="task-notif-body">' + escapeHtml(rawMsg.trim()).replace(/\n/g, '<br>') + '</div>' : '';
                    const actionsHtml = taskBtnHtml ? '<div class="task-notif-actions">' + taskBtnHtml + '</div>' : '';
                    contentHtml = headerHtml + bodyHtml + actionsHtml;
                } else {
                    if (rawMsg.trim()) contentHtml += '<div>' + escapeHtml(rawMsg).replace(/\n/g, '<br>') + '</div>';
                    if (taskBtnHtml) contentHtml += '<div class="task-notif-actions">' + taskBtnHtml + '</div>';
                }
            }

            if (msg.attachment) {
                const ext = (msg.attachment_type || getFileExtension(msg.attachment)).toLowerCase();
                const fileUrl = FILE_BASE_URL + msg.attachment;
                const fileName = msg.attachment_name || msg.attachment;
                const fileSize = formatFileSize(msg.attachment_size || 0);
                const icon = getFileIcon(ext);

                if (['png', 'jpg', 'jpeg'].includes(ext)) {
                    contentHtml += `<div class="chat-bubble__attachment"><div class="chat-image-wrapper" onclick="event.stopPropagation();openLightbox('${escapeHtml(fileUrl)}','${escapeHtml(fileName)}')"><img src="${escapeHtml(fileUrl)}" alt="${escapeHtml(fileName)}" class="chat-image-preview"><div class="image-zoom-hint"><i class="fas fa-expand"></i></div></div></div>`;
                } else {
                    contentHtml += `<div class="chat-bubble__attachment"><a href="${escapeHtml(fileUrl)}" class="chat-file-card" download="${escapeHtml(fileName)}" target="_blank" onclick="event.stopPropagation();"><div class="chat-file-card__icon type-${ext}"><i class="fas ${icon}"></i></div><div class="chat-file-card__info"><div class="chat-file-card__name">${escapeHtml(fileName)}</div><div class="chat-file-card__size"><i class="fas fa-download"></i> ${escapeHtml(fileSize)}</div></div></a></div>`;
                }
            }

            const editedHtml = Number(msg.is_edited) === 1 ? '<span class="chat-bubble__edited">(ویرایش‌شده)</span>' : '';

            const bubble = document.createElement('div');
            const clsExtra = isTaskNotification ? ' task-notification' : '';
            bubble.className = 'chat-bubble ' + cls + clsExtra;
            bubble.setAttribute('data-msg-id', String(msg.id));
            bubble.setAttribute('data-created-ms', String(createdMs || Date.now()));
            bubble.setAttribute('data-sender-id', String(senderIdNum));
            if (Number(msg.is_edited) === 1) bubble.setAttribute('data-edited', '1');

            bubble.innerHTML = `
        ${contentHtml}
        <div class="chat-bubble__time">
            ${editedHtml}
            <span>${formatTime(dateObj)}</span>
            ${checkIcon}
        </div>
    `;
            return bubble;
        }

        function buildMessagesFragment(messages) {
            const frag = document.createDocumentFragment();
            let lastDay = null;
            messages.forEach(msg => {
                const createdMs = parseServerTime(msg.created_at);
                const dateObj = createdMs ? new Date(createdMs) : new Date();
                const dayLabel = formatDayLabel(dateObj);
                if (lastDay !== dayLabel) {
                    const d = document.createElement('div');
                    d.className = 'chat-day';
                    d.innerHTML = `<span>${escapeHtml(dayLabel)}</span>`;
                    frag.appendChild(d);
                    lastDay = dayLabel;
                }
                frag.appendChild(createMessageElement(msg));
            });
            return frag;
        }

        function appendMessage(msg) {
            const body = document.getElementById('chatBody');
            const createdMs = parseServerTime(msg.created_at);
            const dateObj = createdMs ? new Date(createdMs) : new Date();
            const dayLabel = formatDayLabel(dateObj);
            if (lastRenderedDay !== dayLabel) {
                const d = document.createElement('div');
                d.className = 'chat-day';
                d.innerHTML = `<span>${escapeHtml(dayLabel)}</span>`;
                body.appendChild(d);
                lastRenderedDay = dayLabel;
            }
            body.appendChild(createMessageElement(msg));
        }

        function formatTime(d) {
            return String(d.getHours()).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0');
        }

        function formatDayLabel(d) {
            const t = new Date(),
                y = new Date();
            y.setDate(t.getDate() - 1);
            const s = d.toDateString();
            if (s === t.toDateString()) return 'امروز';
            if (s === y.toDateString()) return 'دیروز';
            const m = ['فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];
            return d.getDate() + ' ' + m[d.getMonth()] + ' ' + d.getFullYear();
        }

        /* ===================== HEARTBEAT / STATUS ===================== */
        function sendHeartbeat() {
            fetch('api.php?action=heartbeat', {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            }).catch(() => {});
        }

        function fetchUserStatus() {
            if (!currentChatUserId || currentIsSaved || currentIsBot) return;
            if (statusRequestInFlight) return;
            statusRequestInFlight = true;

            fetch('api.php?action=user_status&user_id=' + currentChatUserId, {
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                })
                .then(r => r.json())
                .then(data => {
                    if (data.status === 'success') {
                        updateStatusUI(currentChatUserId, data.is_online, data.last_seen, data.last_seen_text);
                    }
                })
                .catch(() => {})
                .finally(() => {
                    statusRequestInFlight = false;
                });
        }

        function updateStatusUI(targetUserId, isOnline, lastSeenTs, lastSeenText) {
            if (currentIsSaved && Number(targetUserId) === Number(CURRENT_USER_ID)) return;

            if (currentChatUserId && Number(currentChatUserId) === Number(targetUserId)) {
                if (currentIsBot) return;
                const s = document.getElementById('chatHeaderStatus');
                const t = document.getElementById('chatHeaderStatusText');
                const a = document.getElementById('chatHeaderAvatar');
                s.classList.remove('saved', 'bot');

                if (isOnline) {
                    s.classList.remove('offline');
                    s.classList.add('online');
                    t.textContent = 'آنلاین';
                    if (a) {
                        a.classList.remove('offline');
                        a.classList.add('online');
                    }
                } else {
                    s.classList.remove('online');
                    s.classList.add('offline');
                    t.textContent = 'آفلاین — ' + (lastSeenText || 'نامشخص');
                    if (a) {
                        a.classList.remove('online');
                        a.classList.add('offline');
                    }
                }
            }

            const la = document.querySelector(`.chat-item__avatar[data-avatar-for="${targetUserId}"]`);
            if (la && !la.classList.contains('is-saved-avatar') && !la.classList.contains('is-bot-avatar')) {
                if (isOnline) {
                    la.classList.remove('offline');
                    la.classList.add('online');
                } else {
                    la.classList.remove('online');
                    la.classList.add('offline');
                }
            }
        }

        function fetchAllUsersStatus() {
            const items = document.querySelectorAll('.chat-item');
            const ids = [];
            items.forEach(item => {
                if (item.getAttribute('data-is-saved') === '1') return;
                if (item.getAttribute('data-is-bot') === '1') return;
                const uid = item.getAttribute('data-user-id');
                if (uid && Number(uid) !== currentChatUserId) ids.push(Number(uid));
            });
            if (ids.length === 0) return;

            fetch('api.php?action=users_status&user_ids=' + ids.join(','), {
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                })
                .then(r => r.json())
                .then(data => {
                    if (data.status !== 'success' || !data.users) return;
                    Object.keys(data.users).forEach(uid => {
                        const info = data.users[uid];
                        updateStatusUI(Number(uid), info.is_online, info.last_seen, info.last_seen_text);
                    });
                })
                .catch(() => {});
        }

        /* ===================== SEND ===================== */
        function sendFileWithMessage() {
            if (!currentChatUserId || !selectedFile || sendingMessage) return;
            const inputEl = document.getElementById('chatInput');
            const text = inputEl.value.trim();
            sendingMessage = true;

            document.getElementById('chatSendBtn').disabled = true;
            document.getElementById('attachBtn').disabled = true;

            const fd = new FormData();
            fd.append('action', 'send_file');
            fd.append('receiver_id', currentChatUserId);
            fd.append('message', text);
            fd.append('attachment', selectedFile);

            const xhr = new XMLHttpRequest();
            xhr.upload.addEventListener('progress', e => {
                if (e.lengthComputable) updateUploadProgress(Math.round((e.loaded / e.total) * 100));
            });

            xhr.addEventListener('load', () => {
                updateUploadProgress(100);
                sendingMessage = false;
                document.getElementById('chatSendBtn').disabled = false;
                document.getElementById('attachBtn').disabled = false;
                try {
                    const data = JSON.parse(xhr.responseText);
                    if (data.status === 'success') {
                        inputEl.value = '';
                        autoResize(inputEl);
                        clearFilePreview();
                        pollNewMessages();
                        refreshSavedBadge();
                    } else alert(data.message || 'خطا در ارسال فایل');
                } catch (e) {
                    alert('خطا در پاسخ سرور');
                }
            });

            xhr.addEventListener('error', () => {
                updateUploadProgress(0);
                sendingMessage = false;
                document.getElementById('chatSendBtn').disabled = false;
                document.getElementById('attachBtn').disabled = false;
                alert('خطا در ارتباط با سرور');
            });

            xhr.open('POST', 'api.php');
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            xhr.send(fd);
        }

        function sendMessage(e) {
            e.preventDefault();
            if (!currentChatUserId || sendingMessage) return;
            if (currentIsBot) {
                showToast('این حساب ربات اطلاع‌رسانی است', 'info');
                return;
            }
            if (selectedFile) {
                sendFileWithMessage();
                return;
            }

            const inputEl = document.getElementById('chatInput');
            const text = inputEl.value.trim();
            if (!text) return;

            sendingMessage = true;
            document.getElementById('chatSendBtn').disabled = true;

            const fd = new FormData();
            fd.append('action', 'send');
            fd.append('receiver_id', currentChatUserId);
            fd.append('message', text);

            fetch('api.php', {
                    method: 'POST',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: fd
                })
                .then(r => r.json())
                .then(data => {
                    if (data.status === 'success') {
                        inputEl.value = '';
                        autoResize(inputEl);
                        pollNewMessages();
                        refreshSavedBadge();
                    } else alert(data.message || 'خطا در ارسال');
                })
                .catch(() => alert('خطا در ارتباط با سرور'))
                .finally(() => {
                    sendingMessage = false;
                    document.getElementById('chatSendBtn').disabled = false;
                });
        }

        /* ===================== CHAT INPUT ===================== */
        const chatInputEl = document.getElementById('chatInput');

        function autoResize(el) {
            el.style.height = 'auto';
            el.style.height = Math.min(el.scrollHeight, 100) + 'px';
        }

        chatInputEl.addEventListener('input', function() {
            autoResize(this);
        });
        chatInputEl.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                document.getElementById('chatForm').dispatchEvent(new Event('submit'));
            }
        });

        /* ===================== SIDEBAR SEARCH ===================== */
        document.getElementById('chatSearchInput').addEventListener('input', function() {
            const q = this.value.trim().toLowerCase();
            document.querySelectorAll('.chat-item').forEach(item => {
                const name = (item.getAttribute('data-name') || '').toLowerCase();
                item.style.display = (!q || name.includes(q)) ? 'flex' : 'none';
            });
        });

        document.getElementById('chatBody').addEventListener('scroll', function() {
            closeContextMenu();
            if (this.scrollTop < 120 && hasMoreBefore && !loadingOlder) {
                loadOlderMessages();
            }
        });

        /* ===================== UNREAD COUNTS ===================== */
        function pollUnreadCounts() {
            fetch('api.php?action=unread_counts', {
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                })
                .then(r => r.json())
                .then(data => {
                    if (data.status !== 'success') return;
                    const counts = data.counts || {};
                    document.querySelectorAll('.chat-item').forEach(item => {
                        if (item.getAttribute('data-is-saved') === '1') return;
                        const uid = item.getAttribute('data-user-id');
                        if (!uid) return;
                        if (currentChatUserId && String(currentChatUserId) === uid && !currentIsSaved) return;
                        const ex = item.querySelector('.chat-item__badge');
                        const c = counts[uid] || 0;
                        if (c > 0) {
                            if (ex) ex.textContent = c;
                            else {
                                const b = document.createElement('div');
                                b.className = 'chat-item__badge';
                                b.setAttribute('data-user-id', uid);
                                b.textContent = c;
                                item.appendChild(b);
                            }
                        } else if (ex) ex.remove();
                    });
                })
                .catch(() => {});
        }

        function refreshSavedBadge() {
            if (!currentIsSaved) return;
            fetch('api.php?action=saved_info', {
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                })
                .then(r => r.json())
                .then(data => {
                    if (data.status !== 'success') return;
                    const item = document.querySelector('.chat-item.is-saved');
                    if (!item) return;
                    let badge = item.querySelector('.saved-count-badge');
                    if (data.total_count > 0) {
                        if (!badge) {
                            badge = document.createElement('span');
                            badge.className = 'saved-count-badge';
                            const n = item.querySelector('.chat-item__name');
                            if (n) n.appendChild(badge);
                        }
                        badge.textContent = data.total_count;
                    } else if (badge) badge.remove();

                    const lastEl = item.querySelector('.chat-item__last');
                    if (lastEl && data.last_message) {
                        let p = '';
                        if (data.last_message.message) p = data.last_message.message.substring(0, 40);
                        else if (data.last_message.attachment_name) p = '📎 ' + data.last_message.attachment_name.substring(0, 35);
                        else p = 'فایل';
                        lastEl.textContent = p;
                    }
                })
                .catch(() => {});
        }

        /* ===================== SEARCH MODAL ===================== */
        function openSearchModal(defaultScope) {
            const overlay = document.getElementById('searchModalOverlay');
            overlay.classList.add('active');
            document.body.style.overflow = 'hidden';

            const convoBtn = document.getElementById('scopeConversationBtn');
            if (currentChatUserId && !currentIsSaved) {
                convoBtn.disabled = false;
                setSearchScope(defaultScope === 'conversation' ? 'conversation' : 'all');
            } else {
                convoBtn.disabled = true;
                setSearchScope('all');
            }

            setTimeout(() => {
                const inp = document.getElementById('searchQueryInput');
                inp.focus();
                inp.select();
            }, 150);
        }

        function closeSearchModal() {
            const overlay = document.getElementById('searchModalOverlay');
            overlay.classList.remove('active');
            document.body.style.overflow = '';
            if (searchAbortController) {
                searchAbortController.abort();
                searchAbortController = null;
            }
            clearTimeout(searchTimer);
        }

        function setSearchScope(scope) {
            searchScope = scope;
            document.querySelectorAll('.search-modal__scope-btn').forEach(btn => {
                btn.classList.toggle('active', btn.getAttribute('data-scope') === scope);
            });
            if (searchInputTouched) triggerSearch();
        }

        document.getElementById('searchQueryInput').addEventListener('input', function() {
            searchInputTouched = true;
            clearTimeout(searchTimer);
            const q = this.value.trim();
            if (q.length < 2) {
                document.getElementById('searchResultsBody').innerHTML = '<div class="search-modal__empty"><i class="fas fa-keyboard"></i>حداقل ۲ کاراکتر تایپ کنید تا جستجو شروع شود...</div>';
                return;
            }
            document.getElementById('searchResultsBody').innerHTML = '<div class="search-modal__empty"><i class="fas fa-spinner fa-spin"></i>در حال جستجو...</div>';
            searchTimer = setTimeout(triggerSearch, 400);
        });

        function triggerSearch() {
            const q = document.getElementById('searchQueryInput').value.trim();
            if (q.length < 2) return;

            if (searchAbortController) searchAbortController.abort();
            searchAbortController = new AbortController();

            let url = `api.php?action=search&q=${encodeURIComponent(q)}&scope=${searchScope}`;
            if (searchScope === 'conversation' && currentChatUserId) url += `&user_id=${currentChatUserId}`;

            fetch(url, {
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    signal: searchAbortController.signal
                })
                .then(r => r.json())
                .then(data => {
                    if (data.status !== 'success') {
                        document.getElementById('searchResultsBody').innerHTML = `<div class="search-modal__empty"><i class="fas fa-exclamation-circle"></i>${escapeHtml(data.message || 'خطا در جستجو')}</div>`;
                        return;
                    }
                    renderSearchResults(data.results || [], q);
                })
                .catch(err => {
                    if (err.name === 'AbortError') return;
                    document.getElementById('searchResultsBody').innerHTML = '<div class="search-modal__empty"><i class="fas fa-exclamation-circle"></i>خطا در ارتباط با سرور</div>';
                });
        }

        function renderSearchResults(results, query) {
            lastSearchResults = results;
            const body = document.getElementById('searchResultsBody');
            if (results.length === 0) {
                body.innerHTML = `<div class="search-modal__empty"><i class="fas fa-search-minus"></i>نتیجه‌ای برای «${escapeHtml(query)}» یافت نشد</div>`;
                return;
            }
            let html = `<div class="search-modal__info">${results.length} نتیجه یافت شد</div>`;
            const qLower = query.toLowerCase();

            results.forEach(r => {
                const createdMs = parseServerTime(r.created_at);
                const dateObj = createdMs ? new Date(createdMs) : new Date();
                const timeStr = formatTime(dateObj);
                const dayLabel = formatDayLabel(dateObj);

                let avatar = '?';
                if (r.from_saved) avatar = '<i class="fas fa-bookmark"></i>';
                else avatar = escapeHtml(r.from_name ? r.from_name.charAt(0) : '?');

                let preview = '';
                if (r.message && r.message.trim()) preview = r.message.replace(/\[\[task:[a-fA-F0-9]+\]\]/g, '').trim();
                else if (r.attachment_name) preview = '📎 ' + r.attachment_name;

                const highlighted = highlightQuery(escapeHtml(preview), qLower);

                let badges = '';
                if (r.is_mine) badges += '<span class="search-result__badge">شما</span>';
                if (r.attachment_name) badges += '<span class="search-result__badge file">📎 فایل</span>';

                html += `
            <div class="search-result" onclick="jumpToResult(${r.id}, ${r.other_user_id}, ${r.from_saved ? 1 : 0})">
                <div class="search-result__avatar ${r.from_saved ? 'is-saved-avatar' : ''}">${avatar}</div>
                <div class="search-result__info">
                    <div class="search-result__top">
                        <div class="search-result__name">${escapeHtml(r.from_saved ? 'پیام های ذخیره شده' : (r.from_name || 'کاربر'))} ${badges}</div>
                        <div class="search-result__time">${escapeHtml(dayLabel)} • ${timeStr}</div>
                    </div>
                    <div class="search-result__text">${highlighted || '(بدون متن)'}</div>
                </div>
            </div>`;
            });
            body.innerHTML = html;
        }

        function highlightQuery(escapedText, qLower) {
            if (!escapedText) return '';
            const q = qLower.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
            if (!q) return escapedText;
            try {
                const re = new RegExp('(' + q + ')', 'gi');
                return escapedText.replace(re, '<mark>$1</mark>');
            } catch (e) {
                return escapedText;
            }
        }

        function jumpToResult(msgId, otherUserId, fromSaved) {
            closeSearchModal();
            pendingHighlightMsgId = msgId;

            if (currentChatUserId === otherUserId && currentIsSaved === !!fromSaved) {
                loadMessagesAround(msgId);
                pendingHighlightMsgId = null;
                return;
            }

            const item = document.querySelector(`.chat-item[data-user-id="${otherUserId}"]`);
            openChat(otherUserId, item);
        }

        /* ===================== LIGHTBOX ===================== */
        const lightboxEl = document.getElementById('imageLightbox');
        const lightboxImg = document.getElementById('lightboxImage');
        const lightboxCanvas = document.getElementById('lightboxCanvas');
        const zoomLevelEl = document.getElementById('zoomLevel');

        function openLightbox(src, name) {
            lightboxCurrentUrl = src;
            lightboxCurrentName = name || 'image';
            lightboxImg.src = src;
            lightboxImg.alt = name || '';
            lightboxZoom = 1;
            lightboxPanX = 0;
            lightboxPanY = 0;
            updateLightboxTransform();
            lightboxEl.classList.add('active');
            document.body.style.overflow = 'hidden';

            lightboxImg.onload = function() {
                const w = lightboxImg.naturalWidth;
                const h = lightboxImg.naturalHeight;
                const vw = window.innerWidth * 0.9;
                const vh = window.innerHeight * 0.8;
                if (w > vw || h > vh) {
                    const r = Math.min(vw / w, vh / h);
                    if (r < 1) {
                        lightboxZoom = r;
                        updateLightboxTransform();
                    }
                }
            };
        }

        function closeLightbox() {
            lightboxEl.classList.remove('active');
            document.body.style.overflow = '';
            lightboxImg.src = '';
            lightboxCurrentUrl = '';
            lightboxCurrentName = '';
        }

        function updateLightboxTransform() {
            lightboxImg.style.transform = `translate(${lightboxPanX}px, ${lightboxPanY}px) scale(${lightboxZoom})`;
            zoomLevelEl.textContent = Math.round(lightboxZoom * 100) + '%';
        }

        function zoomIn() {
            if (lightboxZoom < ZOOM_MAX) {
                lightboxZoom = Math.min(ZOOM_MAX, lightboxZoom + ZOOM_STEP);
                updateLightboxTransform();
            }
        }

        function zoomOut() {
            if (lightboxZoom > ZOOM_MIN) {
                lightboxZoom = Math.max(ZOOM_MIN, lightboxZoom - ZOOM_STEP);
                if (lightboxZoom <= 1) {
                    lightboxPanX = 0;
                    lightboxPanY = 0;
                }
                updateLightboxTransform();
            }
        }

        function resetZoom() {
            lightboxZoom = 1;
            lightboxPanX = 0;
            lightboxPanY = 0;
            updateLightboxTransform();
        }

        function downloadLightboxImage() {
            if (!lightboxCurrentUrl) return;
            fetch(lightboxCurrentUrl)
                .then(r => r.blob())
                .then(blob => {
                    const u = URL.createObjectURL(blob);
                    const a = document.createElement('a');
                    a.href = u;
                    a.download = lightboxCurrentName || 'image';
                    document.body.appendChild(a);
                    a.click();
                    document.body.removeChild(a);
                    setTimeout(() => URL.revokeObjectURL(u), 1000);
                })
                .catch(() => window.open(lightboxCurrentUrl, '_blank'));
        }

        lightboxCanvas.addEventListener('mousedown', e => {
            if (lightboxZoom <= 1) return;
            isDragging = true;
            lightboxCanvas.classList.add('dragging');
            dragStartX = e.clientX - lightboxPanX;
            dragStartY = e.clientY - lightboxPanY;
            e.preventDefault();
        });

        document.addEventListener('mousemove', e => {
            if (!isDragging) return;
            lightboxPanX = e.clientX - dragStartX;
            lightboxPanY = e.clientY - dragStartY;
            updateLightboxTransform();
        });

        document.addEventListener('mouseup', () => {
            if (isDragging) {
                isDragging = false;
                lightboxCanvas.classList.remove('dragging');
            }
        });

        lightboxEl.addEventListener('wheel', e => {
            if (!lightboxEl.classList.contains('active')) return;
            e.preventDefault();
            if (e.deltaY < 0) zoomIn();
            else zoomOut();
        }, {
            passive: false
        });

        /* ===================== GLOBAL EVENTS ===================== */
        document.addEventListener('contextmenu', e => {
            const b = e.target.closest('.chat-bubble');
            if (b && b.hasAttribute('data-msg-id')) {
                if (b.classList.contains('task-notification')) {
                    e.preventDefault();
                    const txt = (b.querySelector('.task-notif-body') || {}).innerText || '';
                    if (txt.trim()) {
                        if (navigator.clipboard) {
                            navigator.clipboard.writeText(txt)
                                .then(() => showToast('متن کپی شد', 'success'))
                                .catch(() => {});
                        }
                    }
                    return;
                }
                openContextMenu(e, b);
            }
        });

        document.addEventListener('click', e => {
            const m = document.getElementById('msgContextMenu');
            if (!m || !m.classList.contains('active')) return;
            if (e.target.closest('#msgContextMenu')) return;
            closeContextMenu();
        });

        document.addEventListener('keydown', e => {
            if (e.key === 'Escape') {
                closeContextMenu();
                closeSidebar();
                if (lightboxEl.classList.contains('active')) closeLightbox();
                closeDeleteModal();
                closeEditModal();
                const sm = document.getElementById('searchModalOverlay');
                if (sm.classList.contains('active')) closeSearchModal();
            }
            if ((e.ctrlKey || e.metaKey) && (e.key === 'f' || e.key === 'k')) {
                e.preventDefault();
                openSearchModal(currentChatUserId && !currentIsSaved ? 'conversation' : 'all');
            }
            if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) {
                const em = document.getElementById('editModal');
                if (em && em.classList.contains('active')) {
                    e.preventDefault();
                    confirmEditMessage();
                }
            }
            if (lightboxEl.classList.contains('active')) {
                switch (e.key) {
                    case '+':
                    case '=':
                        e.preventDefault();
                        zoomIn();
                        break;
                    case '-':
                    case '_':
                        e.preventDefault();
                        zoomOut();
                        break;
                    case '0':
                        e.preventDefault();
                        resetZoom();
                        break;
                    case 'd':
                    case 'D':
                        e.preventDefault();
                        downloadLightboxImage();
                        break;
                }
            }
        });

        /* ===================== SIDEBAR TOGGLE ===================== */
        function toggleSidebar() {
            const s = document.getElementById('appSidebar');
            const o = document.getElementById('sidebarOverlay');
            const b = document.getElementById('hamburgerBtn');
            if (s.classList.contains('open')) closeSidebar();
            else {
                s.classList.add('open');
                o.classList.add('active');
                b.classList.add('active');
                document.body.style.overflow = 'hidden';
            }
        }

        function closeSidebar() {
            const s = document.getElementById('appSidebar');
            const o = document.getElementById('sidebarOverlay');
            const b = document.getElementById('hamburgerBtn');
            if (!s || !o || !b) return;
            s.classList.remove('open');
            o.classList.remove('active');
            b.classList.remove('active');
            document.body.style.overflow = '';
        }

        let resizeTimer;
        window.addEventListener('resize', () => {
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(() => {
                if (window.innerWidth > 900) closeSidebar();
            }, 150);
        });

        /* ===================== INIT ===================== */
        sendHeartbeat();
        setInterval(sendHeartbeat, 10000);
        setInterval(pollUnreadCounts, 8000);
        setInterval(fetchAllUsersStatus, 5000);
        setTimeout(fetchAllUsersStatus, 500);

        /* ⭐ باز کردن خودکار گفتگو از طریق ?chat=USER_ID */
        if (AUTO_OPEN_CHAT_ID > 0) {
            const autoItem = document.querySelector(`.chat-item[data-user-id="${AUTO_OPEN_CHAT_ID}"]`);
            if (autoItem) {
                setTimeout(() => openChat(AUTO_OPEN_CHAT_ID, autoItem), 350);
            }
            try {
                const cleanUrl = new URL(window.location.href);
                cleanUrl.searchParams.delete('chat');
                window.history.replaceState({}, '', cleanUrl.toString());
            } catch (e) {}
        }
    </script>
</body>

</html>