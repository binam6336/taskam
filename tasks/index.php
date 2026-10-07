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
            border-radius: 11px;
            background: linear-gradient(135deg, #a26bfa, #7f4cf0);
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
            border-radius: 11px;
            background: linear-gradient(135deg, #a26bfa, #7f4cf0);
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

            (function() {
                function ie(el) {
                    if (!el || !el.tagName) return false;
                    const t = el.tagName.toLowerCase();
                    return t === 'input' || t === 'textarea' || t === 'select' || el.isContentEditable;
                }

                function ic(el) {
                    if (!el || !el.closest) return false;
                    return !!(el.closest('.task-title') || el.closest('.task-desc-clamp') || el.closest('.task-meta-row') || el.closest('.modal-note-text') || el.closest('.task-tooltip') || el.closest('.info-task-title'));
                }
                document.addEventListener('contextmenu', function(e) {
                    if (ie(e.target) || ic(e.target)) return true;
                    e.preventDefault();
                    return false;
                }, true);
                document.addEventListener('selectstart', function(e) {
                    if (ie(e.target) || ic(e.target)) return true;
                    e.preventDefault();
                    return false;
                }, true);
                document.addEventListener('copy', function(e) {
                    if (ie(e.target) || ic(e.target)) return true;
                    e.preventDefault();
                    return false;
                }, true);
                document.addEventListener('dragstart', function(e) {
                    if (ie(e.target) || ic(e.target)) return true;
                    e.preventDefault();
                    return false;
                }, true);
                document.addEventListener('mousedown', function(e) {
                    if (e.detail > 1 && !ie(e.target) && !ic(e.target)) {
                        e.preventDefault();
                        return false;
                    }
                }, true);
            })();

        let appToastTimer = null;

        function showAppToast(text, type = 'success') {
            const toast = document.getElementById('appToast');
            if (!toast) return;
            document.getElementById('appToastText').textContent = text;
            const icon = toast.querySelector('i');
            toast.classList.remove('toast-success', 'toast-error', 'toast-info');
            if (type === 'success') {
                icon.className = 'fas fa-check-circle';
                toast.classList.add('toast-success');
            } else if (type === 'error') {
                icon.className = 'fas fa-exclamation-circle';
                toast.classList.add('toast-error');
            } else {
                icon.className = 'fas fa-info-circle';
                toast.classList.add('toast-info');
            }
            toast.classList.add('active');
            clearTimeout(appToastTimer);
            appToastTimer = setTimeout(() => toast.classList.remove('active'), 3000);
        }

        function setPersianDateInput(dId, hId, greg, wId) {
            const d = document.getElementById(dId);
            const h = document.getElementById(hId);
            const w = document.getElementById(wId);
            if (!d || !h) return;
            if (!greg) {
                d.value = '';
                h.value = '';
                if (w) w.classList.remove('has-value');
                return;
            }
            try {
                const p = greg.split('-');
                if (p.length !== 3) throw new Error('bad');
                const dt = new Date(parseInt(p[0], 10), parseInt(p[1], 10) - 1, parseInt(p[2], 10), 12, 0, 0);
                const pd = new persianDate(dt);
                d.value = pd.format('YYYY/MM/DD');
                h.value = greg;
                if (w) w.classList.add('has-value');
            } catch (e) {
                d.value = '';
                h.value = '';
                if (w) w.classList.remove('has-value');
            }
        }

        function clearPersianDate(prefix) {
            setPersianDateInput(prefix + '_due_date_display', prefix + '_due_date', '', prefix + '_due_date_wrap');
            const t = document.getElementById(prefix + '_due_time');
            if (t) t.value = '';
        }

        function initPersianDatePickers() {
            if (!window.jQuery || !window.jQuery.fn || !window.jQuery.fn.persianDatepicker) return;
            if (!window.persianDate) return;
            const base = {
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
                        text: {
                            fa: 'امروز'
                        }
                    },
                    closeButton: {
                        enabled: true,
                        text: {
                            fa: 'بستن'
                        }
                    }
                },
                navigator: {
                    scroll: {
                        enabled: false
                    }
                },
                timePicker: {
                    enabled: false
                }
            };
            ['create', 'edit'].forEach(function(prefix) {
                const dId = prefix + '_due_date_display';
                const hId = prefix + '_due_date';
                const wId = prefix + '_due_date_wrap';
                window.jQuery('#' + dId).persianDatepicker(Object.assign({}, base, {
                    altField: '#' + hId,
                    altFieldFormatter: function(u) {
                        const d = new Date(u);
                        return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
                    },
                    onSelect: function() {
                        setTimeout(function() {
                            const el = document.getElementById(dId);
                            const w = document.getElementById(wId);
                            if (el && el.value && w) w.classList.add('has-value');
                        }, 20);
                    },
                    onClear: function() {
                        const h = document.getElementById(hId);
                        if (h) h.value = '';
                        const w = document.getElementById(wId);
                        if (w) w.classList.remove('has-value');
                    }
                }));
            });
        }

        function convertPersianDigitsInElement(el) {
            if (!el) return;
            const fa = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
            const en = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
            const walker = document.createTreeWalker(el, NodeFilter.SHOW_TEXT, null, false);
            let n;
            while ((n = walker.nextNode())) {
                let t = n.nodeValue;
                let ch = false;
                for (let i = 0; i < 10; i++) {
                    if (t.indexOf(fa[i]) !== -1) {
                        t = t.split(fa[i]).join(en[i]);
                        ch = true;
                    }
                }
                if (ch) n.nodeValue = t;
            }
        }

        function fixAllDatepickers() {
            document.querySelectorAll('.datepicker-plot-area').forEach(convertPersianDigitsInElement);
        }

        function setupDatepickerDigitConversion() {
            fixAllDatepickers();
            const obs = new MutationObserver(function(muts) {
                let needsFix = false;
                muts.forEach(function(m) {
                    if (m.type === 'childList') {
                        m.addedNodes.forEach(function(node) {
                            if (node.nodeType === 1) {
                                if (node.classList && node.classList.contains('datepicker-plot-area')) needsFix = true;
                                else if (node.querySelector && node.querySelector('.datepicker-plot-area')) needsFix = true;
                                else if (node.closest && node.closest('.datepicker-plot-area')) needsFix = true;
                            }
                        });
                    } else if (m.type === 'characterData') {
                        if (m.target && m.target.parentElement && m.target.parentElement.closest && m.target.parentElement.closest('.datepicker-plot-area')) needsFix = true;
                    }
                });
                if (needsFix) setTimeout(fixAllDatepickers, 0);
            });
            obs.observe(document.body, {
                childList: true,
                subtree: true,
                characterData: true
            });
            document.addEventListener('click', function() {
                setTimeout(fixAllDatepickers, 30);
            }, true);
            document.addEventListener('focusin', function() {
                setTimeout(fixAllDatepickers, 30);
            }, true);
            document.addEventListener('mouseover', function(e) {
                if (e.target && e.target.closest && e.target.closest('.datepicker-plot-area')) setTimeout(fixAllDatepickers, 0);
            }, true);
        }

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

        function getAssigneeOptionsForProject(pid) {
            const s = String(pid || '');
            if (s !== '' && PROJECTS_MEMBERS && PROJECTS_MEMBERS[s] && PROJECTS_MEMBERS[s].length > 0) {
                const l = PROJECTS_MEMBERS[s];
                const t = (PROJECT_OPTIONS || []).find(p => String(p.id) === s)?.title || '';
                return {
                    mode: 'project',
                    self: l.find(p => p.is_self) || null,
                    others: l.filter(p => !p.is_self),
                    projectTitle: t
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
            const dd = picker.querySelector('.assignee-picker__dropdown');
            const pid = getLinkedProjectId(pickerId);
            const o = getAssigneeOptionsForProject(pid);
            let h = '';
            if (o.mode === 'project') {
                const tt = o.projectTitle ? ` «${escapeHtml(o.projectTitle)}»` : '';
                h += `<div class="assignee-picker__section-label"><i class="fas fa-diagram-project" style="margin-left: 4px; color: #4c8bf5;"></i> اعضای پروژه${tt}</div>`;
                if (o.self) h += renderAssigneeOption(o.self, selectedId);
                o.others.forEach(c => {
                    h += renderAssigneeOption(c, selectedId);
                });
                if (!o.self && o.others.length === 0) h += `<div class="assignee-picker__empty">این پروژه عضوی ندارد.</div>`;
            } else {
                h += `<div class="assignee-picker__section-label">خودم</div>`;
                h += renderAssigneeOption(o.self, selectedId);
                if (o.others.length > 0) {
                    h += `<div class="assignee-picker__section-label">همکاران</div>`;
                    o.others.forEach(c => {
                        h += renderAssigneeOption(c, selectedId);
                    });
                } else {
                    h += `<div class="assignee-picker__section-label">همکاران</div><div class="assignee-picker__empty">هنوز همکاری اضافه نکرده‌اید.<br><a href="../colleagues/index.php"><i class="fas fa-user-plus"></i> افزودن</a></div>`;
                }
            }
            dd.innerHTML = h;
            dd.querySelectorAll('.assignee-picker__option').forEach(opt => {
                opt.addEventListener('click', function(e) {
                    e.stopPropagation();
                    selectAssignee(pickerId, this.getAttribute('data-id'));
                });
            });
        }

        function renderAssigneeOption(p, sel) {
            const isSel = String(p.id) === String(sel);
            const avc = (p.is_self ? 'is-self' : '') + (p.is_creator ? ' is-creator' : '');
            const scls = isSel ? 'selected' : '';
            let ac = p.avatar_url ? `<img src="${escapeHtml(p.avatar_url)}" alt="">` : escapeHtml(p.initial || '?');
            const crown = p.is_creator ? '<i class="fas fa-crown creator-crown"></i>' : '';
            return `<div class="assignee-picker__option ${scls}" data-id="${p.id}"><div class="assignee-picker__option-avatar ${avc}">${ac}</div><div class="assignee-picker__option-info"><div class="assignee-picker__option-name">${escapeHtml(p.name)}${crown}</div>${p.mobile?`<div class="assignee-picker__option-mobile">${escapeHtml(p.mobile)}</div>`:''}</div></div>`;
        }

        function selectAssignee(pickerId, id) {
            const picker = document.getElementById(pickerId);
            if (!picker) return;
            const input = picker.querySelector('input[type="hidden"]');
            const av = picker.querySelector('.assignee-picker__avatar');
            const name = picker.querySelector('.assignee-picker__name');
            const mob = picker.querySelector('.assignee-picker__mobile');
            let p = null;
            if (String(ASSIGNEE_OPTIONS.self.id) === String(id)) p = ASSIGNEE_OPTIONS.self;
            if (!p) p = (ASSIGNEE_OPTIONS.colleagues || []).find(c => String(c.id) === String(id));
            if (!p && PROJECTS_MEMBERS)
                for (const pid in PROJECTS_MEMBERS) {
                    const f = PROJECTS_MEMBERS[pid].find(m => String(m.id) === String(id));
                    if (f) {
                        p = {
                            id: f.id,
                            name: f.is_self ? 'خودم' : f.name,
                            mobile: f.mobile,
                            initial: f.initial,
                            avatar_url: f.avatar_url,
                            is_self: f.is_self,
                            is_creator: f.is_creator
                        };
                        break;
                    }
                }
            if (!p) return;
            input.value = p.id;
            if (p.avatar_url) av.innerHTML = `<img src="${escapeHtml(p.avatar_url)}" alt="">`;
            else av.textContent = p.initial || '?';
            av.className = 'assignee-picker__avatar' + (p.is_self ? ' is-self' : '');
            name.textContent = p.name;
            mob.textContent = p.mobile || '';
            picker.classList.remove('open');
            renderAssigneeDropdown(pickerId, p.id);
        }

        function toggleAssigneePicker(pickerId) {
            const picker = document.getElementById(pickerId);
            if (!picker || picker.classList.contains('is-disabled')) return;
            const wasOpen = picker.classList.contains('open');
            document.querySelectorAll('.assignee-picker.open, .project-picker.open').forEach(p => p.classList.remove('open'));
            if (!wasOpen) {
                renderAssigneeDropdown(pickerId, picker.querySelector('input[type="hidden"]').value);
                picker.classList.add('open');
            }
        }

        function renderProjectDropdown(pickerId, selectedId) {
            const picker = document.getElementById(pickerId);
            if (!picker) return;
            const dd = picker.querySelector('.project-picker__dropdown');
            const projects = PROJECT_OPTIONS || [];
            let h = `<div class="project-picker__section-label">بدون پروژه</div>`;
            h += renderProjectOption({
                id: '',
                title: 'بدون پروژه',
                initial: '',
                image_url: null
            }, selectedId, true);
            if (projects.length > 0) {
                h += `<div class="project-picker__section-label">پروژه‌های من</div>`;
                projects.forEach(p => {
                    h += renderProjectOption(p, selectedId, false);
                });
            } else {
                h += `<div class="project-picker__section-label">پروژه‌های من</div><div class="project-picker__empty">هنوز پروژه‌ای نساخته‌اید.</div>`;
            }
            dd.innerHTML = h;
            dd.querySelectorAll('.project-picker__option').forEach(opt => {
                opt.addEventListener('click', function(e) {
                    e.stopPropagation();
                    selectProject(pickerId, this.getAttribute('data-id'));
                });
            });
        }

        function renderProjectOption(p, sel, isEmpty) {
            const curId = (sel === null || sel === undefined || sel === '') ? '' : String(sel);
            const isSel = (isEmpty ? '' : String(p.id)) === curId;
            const scls = isSel ? 'selected' : '';
            let ac, avc = '';
            if (isEmpty) {
                ac = '<i class="fas fa-folder-open"></i>';
                avc = 'is-empty';
            } else if (p.image_url) ac = `<img src="${escapeHtml(p.image_url)}" alt="">`;
            else ac = escapeHtml(p.initial || '?');
            return `<div class="project-picker__option ${scls}" data-id="${isEmpty?'':p.id}"><div class="project-picker__option-avatar ${avc}">${ac}</div><div class="project-picker__option-info"><div class="project-picker__option-name">${escapeHtml(p.title)}</div>${!isEmpty&&p.is_creator?`<div class="project-picker__option-mobile">سازنده</div>`:''}</div></div>`;
        }

        function selectProject(pickerId, id, options = {}) {
            const {
                skipAssigneeReset = false
            } = options;
            const picker = document.getElementById(pickerId);
            if (!picker) return;
            const input = picker.querySelector('input[type="hidden"]');
            const av = picker.querySelector('.project-picker__avatar');
            const name = picker.querySelector('.project-picker__name');
            const meta = picker.querySelector('.project-picker__mobile');
            if (id === '' || id === null) {
                input.value = '';
                av.innerHTML = '<i class="fas fa-folder-open"></i>';
                av.className = 'project-picker__avatar is-empty';
                name.textContent = 'بدون پروژه';
                meta.textContent = '—';
                picker.classList.remove('open');
                renderProjectDropdown(pickerId, '');
                if (!skipAssigneeReset) {
                    if (pickerId === 'createProjectPicker') resetAssigneeForProject('createAssigneePicker');
                    else if (pickerId === 'editProjectPicker') resetAssigneeForProject('editAssigneePicker');
                }
                return;
            }
            const p = (PROJECT_OPTIONS || []).find(x => String(x.id) === String(id));
            if (!p) return;
            input.value = p.id;
            if (p.image_url) av.innerHTML = `<img src="${escapeHtml(p.image_url)}" alt="">`;
            else av.textContent = p.initial || '?';
            av.className = 'project-picker__avatar';
            name.textContent = p.title;
            meta.textContent = p.is_creator ? 'سازنده پروژه' : 'عضو پروژه';
            picker.classList.remove('open');
            renderProjectDropdown(pickerId, p.id);
            if (!skipAssigneeReset) {
                if (pickerId === 'createProjectPicker') resetAssigneeForProject('createAssigneePicker');
                else if (pickerId === 'editProjectPicker') resetAssigneeForProject('editAssigneePicker');
            }
        }

        function resetAssigneeForProject(apid) {
            const pid = getLinkedProjectId(apid);
            const o = getAssigneeOptionsForProject(pid);
            let n = null;
            if (o.self && String(o.self.id) === String(CURRENT_USER_ID)) n = CURRENT_USER_ID;
            else if (o.self) n = o.self.id;
            else if (o.others.length > 0) n = o.others[0].id;
            else n = CURRENT_USER_ID;
            selectAssignee(apid, n);
        }

        function toggleProjectPicker(pickerId) {
            const picker = document.getElementById(pickerId);
            if (!picker || picker.classList.contains('is-disabled')) return;
            const wasOpen = picker.classList.contains('open');
            document.querySelectorAll('.assignee-picker.open, .project-picker.open').forEach(p => p.classList.remove('open'));
            if (!wasOpen) {
                renderProjectDropdown(pickerId, picker.querySelector('input[type="hidden"]').value);
                picker.classList.add('open');
            }
        }

        document.addEventListener('click', function(e) {
            if (!e.target.closest('.assignee-picker')) document.querySelectorAll('.assignee-picker.open').forEach(p => p.classList.remove('open'));
            if (!e.target.closest('.project-picker')) document.querySelectorAll('.project-picker.open').forEach(p => p.classList.remove('open'));
            const mp = document.getElementById('modalNoteMentionPicker');
            if (mp && mp.style.display !== 'none' && !e.target.closest('#modalNoteMentionPicker') && !e.target.closest('#modalNoteMentionBtn')) mp.style.display = 'none';
        });

        function switchMainTab(t, b) {
            document.querySelectorAll('.main-tab-content').forEach(el => el.classList.remove('active'));
            document.querySelectorAll('.main-tab-btn').forEach(el => el.classList.remove('active'));
            const t2 = document.getElementById('main-tab-' + t);
            if (t2) t2.classList.add('active');
            b.classList.add('active');
        }

        function switchTab(t, b) {
            const mc = b.closest('.main-tab-content');
            if (mc) {
                mc.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
                mc.querySelectorAll('.tab-btn').forEach(el => el.classList.remove('active'));
                const t2 = mc.querySelector('#tab-' + t);
                if (t2) t2.classList.add('active');
            }
            b.classList.add('active');
        }
        async function refreshTaskList() {
            try {
                const url = window.location.pathname + window.location.search;
                const res = await fetch(url, {
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    cache: 'no-store'
                });
                const html = await res.text();
                const parser = new DOMParser();
                const doc = parser.parseFromString(html, 'text/html');
                const nc = doc.querySelector('.content-area');
                const cc = document.querySelector('.content-area');
                if (nc && cc) {
                    cc.innerHTML = nc.innerHTML;
                    bindAjaxForms();
                }
            } catch (e) {
                console.error(e);
            }
        }

        function toggleTask(id) {
            const row = document.getElementById('task-row-' + id);
            if (row) row.classList.add('animating');
            const fd = new FormData();
            fd.append('toggle', id);
            fd.append('csrf_token', CSRF_TOKEN);
            fetch('index.php?page=list', {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: fd
            }).then(r => r.text()).then(text => {
                try {
                    return JSON.parse(text);
                } catch (e) {
                    throw new Error('invalid');
                }
            }).then(async data => {
                if (data.status === 'error') {
                    alert(data.message || 'دسترسی ندارید');
                    if (row) row.classList.remove('animating');
                    return;
                }
                showAppToast('وضعیت به‌روز شد', 'success');
                await refreshTaskList();
            }).catch(() => {
                if (row) row.classList.remove('animating');
            });
        }

        function handleTaskRowClick(row) {
            if (row.getAttribute('data-can-open') !== '1') return;
            try {
                openEditModal(JSON.parse(row.getAttribute('data-task')), row.getAttribute('data-can-edit') === '1', row.getAttribute('data-can-reassign') === '1', row.getAttribute('data-can-change-project') === '1', row.getAttribute('data-can-complete') === '1');
            } catch (e) {
                console.error(e);
            }
        }

        function openInfoModalFromRow(taskId) {
            const row = document.getElementById('task-row-' + taskId);
            if (!row) return;
            try {
                openInfoModal(JSON.parse(row.getAttribute('data-task')));
            } catch (e) {}
        }

        function openInfoModal(task) {
            document.getElementById('info_task_title').textContent = task.title || '—';
            const pc = document.getElementById('info_project');
            if (task.project_title) {
                const th = task.project_image_url ? `<img src="${escapeHtml(task.project_image_url)}" alt="">` : escapeHtml(task.project_initial || '?');
                pc.innerHTML = `<span class="info-project-cell"><span class="info-project-thumb">${th}</span><span>${escapeHtml(task.project_title)}</span></span>`;
            } else pc.innerHTML = `<span class="info-project-cell"><span class="info-project-thumb is-empty"><i class="fas fa-folder-open"></i></span><span style="color: var(--text-soft);">بدون پروژه</span></span>`;
            const sc = document.getElementById('info_subject');
            if (task.subject_title) sc.innerHTML = `<span class="info-subject-badge"><i class="fas fa-tag"></i>${escapeHtml(task.subject_title)}</span>`;
            else sc.innerHTML = `<span style="color: var(--text-soft);">بدون موضوع</span>`;
            document.getElementById('info_priority').innerHTML = `<span class="info-priority-badge ${escapeHtml(task.priority||'medium')}">${escapeHtml(task.priority_label||task.priority||'—')}</span>`;
            document.getElementById('info_assignee').textContent = task.assignee_name || '—';
            document.getElementById('info_creator').textContent = task.creator_name || '—';
            const de = document.getElementById('info_due');
            let dt = task.due_date || '';
            if (task.due_time_raw) dt += ' - ' + task.due_time_raw;
            if (dt) {
                if (task.is_overdue) de.innerHTML = `<span style="color: #c81e4a; font-weight: 700;">${escapeHtml(dt)} <span class="overdue-badge"><i class="fas fa-exclamation-triangle"></i> تاخیر</span></span>`;
                else de.textContent = dt;
            } else de.textContent = 'بدون مهلت';
            const ac = parseInt(task.attachments_count || 0, 10);
            document.getElementById('info_attachments').innerHTML = ac > 0 ? `<span class="info-subject-badge"><i class="fas fa-paperclip"></i> ${toPersianDigits(ac)} فایل</span>` : `<span style="color: var(--text-soft);">بدون فایل</span>`;
            openModal('taskInfoModal');
        }

        function openModal(id) {
            const el = document.getElementById(id);
            if (!el) return;
            el.classList.add('active');
            document.body.style.overflow = 'hidden';
            if (id === 'createTaskModal') {
                document.getElementById('createAssigneeInput').value = CURRENT_USER_ID;
                const av = document.getElementById('createAssigneeAvatar');
                if (ASSIGNEE_OPTIONS.self.avatar_url) av.innerHTML = `<img src="${escapeHtml(ASSIGNEE_OPTIONS.self.avatar_url)}" alt="">`;
                else av.textContent = ASSIGNEE_OPTIONS.self.initial || '?';
                av.className = 'assignee-picker__avatar is-self';
                document.getElementById('createAssigneeName').textContent = 'خودم';
                document.getElementById('createAssigneeMobile').textContent = ASSIGNEE_OPTIONS.self.mobile || '';
                document.getElementById('createProjectInput').value = '';
                const pv = document.getElementById('createProjectAvatar');
                pv.innerHTML = '<i class="fas fa-folder-open"></i>';
                pv.className = 'project-picker__avatar is-empty';
                document.getElementById('createProjectName').textContent = 'بدون پروژه';
                document.getElementById('createProjectMeta').textContent = '—';
                renderAssigneeDropdown('createAssigneePicker', CURRENT_USER_ID);
                setPersianDateInput('create_due_date_display', 'create_due_date', '', 'create_due_date_wrap');
                const t = document.getElementById('create_due_time');
                if (t) t.value = '';
                const cf = document.getElementById('createFileInput');
                if (cf) cf.value = '';
                renderFilePreview('createFileInput', 'createFilePreviewList');
            }
        }

        function closeModal(id) {
            const el = document.getElementById(id);
            if (!el) return;
            el.classList.remove('active');
            document.body.style.overflow = '';
        }
        let editFormInitialState = null;

        function captureEditFormState() {
            const s = {};
            s.title = (document.getElementById('edit_task_title') || {}).value || '';
            s.desc = (document.getElementById('edit_task_desc') || {}).value || '';
            s.subject = (document.getElementById('edit_subject_id') || {}).value || '';
            const pr = document.querySelector('#editTaskModal input[name="priority"]:checked');
            s.priority = pr ? pr.value : '';
            s.project = (document.getElementById('editProjectInput') || {}).value || '';
            s.assignee = (document.getElementById('editAssigneeInput') || {}).value || '';
            s.due = (document.getElementById('edit_due_date') || {}).value || '';
            s.dueTime = (document.getElementById('edit_due_time') || {}).value || '';
            return JSON.stringify(s);
        }

        function hasEditFormChanged() {
            return editFormInitialState ? captureEditFormState() !== editFormInitialState : false;
        }

        function requestCloseEditModal() {
            if (hasEditFormChanged()) {
                const sb = document.getElementById('edit_submit_btn');
                const showSave = sb && sb.style.display !== 'none';
                const sci = document.getElementById('confirmCloseSave');
                if (sci) sci.style.display = showSave ? '' : 'none';
                openModal('confirmCloseModal');
            } else closeModal('editTaskModal');
        }

        function forceCloseEditModal() {
            closeModal('confirmCloseModal');
            closeModal('editTaskModal');
            editFormInitialState = null;
        }

        function saveAndCloseEditModal() {
            closeModal('confirmCloseModal');
            const sb = document.getElementById('edit_submit_btn');
            if (sb && sb.style.display !== 'none') sb.click();
            else {
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
            setPersianDateInput('edit_due_date_display', 'edit_due_date', task.due_date_raw || '', 'edit_due_date_wrap');
            const t = document.getElementById('edit_due_time');
            if (t) t.value = (task.due_time_raw || '').substring(0, 5);
            document.querySelectorAll('#editTaskModal input[name="priority"]').forEach(r => {
                r.checked = (r.value === task.priority);
            });
            ['edit_task_title', 'edit_subject_id', 'edit_task_desc'].forEach(id => {
                const el = document.getElementById(id);
                if (!el) return;
                if (canEdit) {
                    el.disabled = false;
                    el.classList.remove('field-locked');
                } else {
                    el.disabled = true;
                    el.classList.add('field-locked');
                }
            });
            const dw = document.getElementById('edit_due_date_wrap');
            const dd = document.getElementById('edit_due_date_display');
            const dtl = document.getElementById('edit_due_time');
            if (dd) {
                if (canEdit) {
                    dd.classList.remove('field-locked');
                    if (dw) dw.style.pointerEvents = '';
                    if (dtl) {
                        dtl.disabled = false;
                        dtl.classList.remove('field-locked');
                    }
                } else {
                    dd.classList.add('field-locked');
                    if (dw) dw.style.pointerEvents = 'none';
                    if (dtl) {
                        dtl.disabled = true;
                        dtl.classList.add('field-locked');
                    }
                }
            }
            document.querySelectorAll('#editTaskModal input[name="priority"]').forEach(r => {
                r.disabled = !canEdit;
            });
            const pg = document.getElementById('edit_priority_group');
            if (canEdit) pg.classList.remove('field-locked');
            else pg.classList.add('field-locked');
            selectProject('editProjectPicker', task.project_id ? String(task.project_id) : '', {
                skipAssigneeReset: true
            });
            selectAssignee('editAssigneePicker', task.assignee_id ? String(task.assignee_id) : String(CURRENT_USER_ID));
            const ap = document.getElementById('editAssigneePicker');
            if (canReassign) ap.classList.remove('is-disabled');
            else ap.classList.add('is-disabled');
            const pp = document.getElementById('editProjectPicker');
            if (canChangeProject) pp.classList.remove('is-disabled');
            else pp.classList.add('is-disabled');
            const sb = document.getElementById('edit_submit_btn');
            if (sb) sb.style.display = (canEdit || canReassign || canChangeProject) ? '' : 'none';
            const ef = document.getElementById('editFileInput');
            if (ef) ef.value = '';
            renderFilePreview('editFileInput', 'editFilePreviewList');
            const nfi = document.getElementById('modalNoteFileInput');
            if (nfi) nfi.value = '';
            const nt = document.getElementById('modalNoteText');
            if (nt) nt.value = '';
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
            const tid = document.getElementById('edit_task_id').value;
            if (!tid) return;
            const btn = document.getElementById('edit_share_btn');
            if (btn) btn.classList.add('copied');
            fetch('index.php?get_task_token=' + encodeURIComponent(tid), {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            }).then(r => r.text()).then(text => {
                let d;
                try {
                    d = JSON.parse(text);
                } catch (e) {
                    throw new Error('invalid');
                }
                if (d.status !== 'success') {
                    showAppToast(d.message || 'خطا', 'error');
                    if (btn) btn.classList.remove('copied');
                    return;
                }
                const url = new URL(d.url, window.location.href).href;
                const done = () => {
                    showAppToast('لینک کپی شد', 'success');
                    setTimeout(() => {
                        if (btn) btn.classList.remove('copied');
                    }, 1500);
                };
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(url).then(done).catch(() => {
                        fallbackCopyText(url);
                        done();
                    });
                } else {
                    fallbackCopyText(url);
                    done();
                }
            }).catch(() => {
                showAppToast('خطا', 'error');
                if (btn) btn.classList.remove('copied');
            });
        }

        function fallbackCopyText(t) {
            try {
                const ta = document.createElement('textarea');
                ta.value = t;
                ta.style.cssText = 'position:fixed;top:0;left:0;opacity:0;';
                document.body.appendChild(ta);
                ta.focus();
                ta.select();
                document.execCommand('copy');
                document.body.removeChild(ta);
            } catch (e) {}
        }

        function fileIconClass(n) {
            const e = (n.split('.').pop() || '').toLowerCase();
            if (e === 'pdf') return {
                cls: 'is-pdf',
                icon: 'fa-file-pdf'
            };
            if (['xls', 'xlsx', 'csv'].includes(e)) return {
                cls: 'is-excel',
                icon: 'fa-file-excel'
            };
            if (['doc', 'docx', 'rtf', 'txt'].includes(e)) return {
                cls: 'is-word',
                icon: 'fa-file-word'
            };
            if (['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'].includes(e)) return {
                cls: 'is-image',
                icon: 'fa-file-image'
            };
            if (['zip', 'rar', '7z'].includes(e)) return {
                cls: 'is-zip',
                icon: 'fa-file-zipper'
            };
            return {
                cls: '',
                icon: 'fa-file'
            };
        }

        function humanFileSizeJS(b) {
            b = parseInt(b, 10) || 0;
            if (b < 1024) return b + ' بایت';
            if (b < 1048576) return (b / 1024).toFixed(1) + ' کیلوبایت';
            return (b / 1048576).toFixed(2) + ' مگابایت';
        }

        function renderFilePreview(iid, lid) {
            const input = document.getElementById(iid);
            const list = document.getElementById(lid);
            if (!input || !list) return;
            list.innerHTML = '';
            Array.from(input.files || []).forEach((f, idx) => {
                const {
                    cls,
                    icon
                } = fileIconClass(f.name);
                const li = document.createElement('li');
                li.className = 'file-preview-item';
                li.innerHTML = `<div class="file-preview-item__icon ${cls}"><i class="fas ${icon}"></i></div><div class="file-preview-item__info"><div class="file-preview-item__name">${escapeHtml(f.name)}</div><div class="file-preview-item__meta">${humanFileSizeJS(f.size)}</div></div><button type="button" class="file-preview-item__remove" data-idx="${idx}"><i class="fas fa-times"></i></button>`;
                list.appendChild(li);
            });
            list.querySelectorAll('.file-preview-item__remove').forEach(b => {
                b.addEventListener('click', function() {
                    removeFileFromInput(iid, parseInt(this.getAttribute('data-idx'), 10));
                });
            });
        }

        function removeFileFromInput(iid, idx) {
            const input = document.getElementById(iid);
            if (!input) return;
            const dt = new DataTransfer();
            Array.from(input.files || []).forEach((f, i) => {
                if (i !== idx) dt.items.add(f);
            });
            input.files = dt.files;
            renderFilePreview(iid, iid === 'createFileInput' ? 'createFilePreviewList' : 'editFilePreviewList');
        }

        function setupDropZone(zid, iid, lid) {
            const z = document.getElementById(zid);
            const i = document.getElementById(iid);
            if (!z || !i) return;
            ['dragenter', 'dragover'].forEach(ev => {
                z.addEventListener(ev, e => {
                    e.preventDefault();
                    e.stopPropagation();
                    z.classList.add('dragover');
                });
            });
            ['dragleave', 'drop'].forEach(ev => {
                z.addEventListener(ev, e => {
                    e.preventDefault();
                    e.stopPropagation();
                    z.classList.remove('dragover');
                });
            });
            z.addEventListener('drop', e => {
                const dt = e.dataTransfer;
                if (!dt || !dt.files || !dt.files.length) return;
                const n = new DataTransfer();
                Array.from(i.files || []).forEach(f => n.items.add(f));
                Array.from(dt.files).forEach(f => n.items.add(f));
                i.files = n.files;
                renderFilePreview(iid, lid);
            });
        }

        function loadTaskAttachments(tid) {
            const c = document.getElementById('modalAttachmentsList');
            const ce = document.getElementById('modalAttachmentsCount');
            if (!c) return;
            c.innerHTML = '<div class="modal-attachments-loading">در حال بارگذاری...</div>';
            fetch('index.php?get_attachments=1&task_id=' + encodeURIComponent(tid), {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            }).then(r => r.text()).then(text => {
                let d;
                try {
                    d = JSON.parse(text);
                } catch (e) {
                    throw new Error('invalid');
                }
                if (d.status !== 'success') {
                    c.innerHTML = `<div class="modal-attachments-empty">${escapeHtml(d.message||'خطا')}</div>`;
                    return;
                }
                const a = d.attachments || [];
                if (ce) ce.textContent = toPersianDigits(a.length);
                if (a.length === 0) {
                    c.innerHTML = '<div class="modal-attachments-empty">هنوز فایلی بارگذاری نشده.</div>';
                    return;
                }
                let h = '';
                a.forEach(x => {
                    const iC = x.icon || 'fa-file';
                    let cc = '';
                    if (iC.includes('pdf')) cc = 'is-pdf';
                    else if (iC.includes('excel')) cc = 'is-excel';
                    else if (iC.includes('word')) cc = 'is-word';
                    else if (iC.includes('image')) cc = 'is-image';
                    else if (iC.includes('zipper')) cc = 'is-zip';
                    h += `<div class="modal-attachment-item"><div class="modal-attachment-item__icon ${cc}"><i class="fas ${escapeHtml(iC)}"></i></div><div class="modal-attachment-item__body"><div class="modal-attachment-item__name"><a href="${escapeHtml(x.url)}" target="_blank" rel="noopener" download>${escapeHtml(x.file_name)}</a></div><div class="modal-attachment-item__meta"><span><i class="fas fa-hdd"></i> ${escapeHtml(x.file_size_human||'')}</span><span><i class="fas fa-clock"></i> ${escapeHtml(x.persian_date||'')}</span></div></div><div class="modal-attachment-item__actions"><a class="att-download" href="${escapeHtml(x.url)}" target="_blank" rel="noopener" download><i class="fas fa-download"></i></a><button type="button" class="att-delete" onclick="deleteTaskAttachment(${x.id}, ${tid})"><i class="fas fa-trash-alt"></i></button></div></div>`;
                });
                c.innerHTML = h;
            }).catch(() => {
                c.innerHTML = '<div class="modal-attachments-empty">خطا</div>';
            });
        }

        function deleteTaskAttachment(aid, tid) {
            if (!confirm('حذف شود؟')) return;
            const fd = new FormData();
            fd.append('delete_attachment', '1');
            fd.append('attachment_id', aid);
            fd.append('csrf_token', CSRF_TOKEN);
            fetch('index.php', {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: fd
            }).then(r => r.text()).then(text => {
                let d;
                try {
                    d = JSON.parse(text);
                } catch (e) {
                    throw new Error('invalid');
                }
                if (d.status === 'success') loadTaskAttachments(tid);
            }).catch(() => alert('خطا'));
        }

        function renderNoteFilePreview() {
            const i = document.getElementById('modalNoteFileInput');
            const p = document.getElementById('modalNoteFilesPreview');
            const ab = document.querySelector('label.modal-note-attach-btn');
            if (!i || !p) return;
            p.innerHTML = '';
            const files = Array.from(i.files || []);
            if (ab) {
                if (files.length > 0) ab.classList.add('has-files');
                else ab.classList.remove('has-files');
            }
            files.forEach((f, idx) => {
                const {
                    cls,
                    icon
                } = fileIconClass(f.name);
                const it = document.createElement('div');
                it.className = 'modal-note-file-item';
                it.innerHTML = `<div class="modal-note-file-item__icon ${cls}"><i class="fas ${icon}"></i></div><div class="modal-note-file-item__info"><div class="modal-note-file-item__name">${escapeHtml(f.name)}</div><div class="modal-note-file-item__meta">${humanFileSizeJS(f.size)}</div></div><button type="button" class="modal-note-file-item__remove" data-idx="${idx}"><i class="fas fa-times"></i></button>`;
                p.appendChild(it);
            });
            p.querySelectorAll('.modal-note-file-item__remove').forEach(b => {
                b.addEventListener('click', function() {
                    const idx = parseInt(this.getAttribute('data-idx'), 10);
                    const dt = new DataTransfer();
                    Array.from(i.files || []).forEach((f, j) => {
                        if (j !== idx) dt.items.add(f);
                    });
                    i.files = dt.files;
                    renderNoteFilePreview();
                });
            });
        }
        let currentMentions = [];

        function toggleMentionPicker(e) {
            if (e) {
                e.stopPropagation();
                e.preventDefault();
            }
            const p = document.getElementById('modalNoteMentionPicker');
            if (!p) return;
            if (p.style.display === 'none' || !p.style.display) {
                renderMentionPicker();
                p.style.display = 'flex';
            } else p.style.display = 'none';
        }

        function renderMentionPicker() {
            const l = document.getElementById('mentionPickerList');
            if (!l) return;
            const o = MENTION_OPTIONS || [];
            if (o.length === 0) {
                l.innerHTML = '<div class="mention-picker__empty">هیچ همکاری نیست</div>';
                return;
            }
            let h = '';
            o.forEach(x => {
                const s = currentMentions.some(m => m.id === x.id);
                const av = x.avatar_url ? `<img src="${escapeHtml(x.avatar_url)}" alt="">` : escapeHtml(x.initial || '?');
                h += `<div class="mention-picker__item ${s?'selected':''}" data-id="${x.id}" data-name="${escapeHtml(x.name)}"><div class="mention-picker__avatar">${av}</div><div class="mention-picker__name">${escapeHtml(x.name)}</div>${s?'<i class="fas fa-check mention-picker__check"></i>':''}</div>`;
            });
            l.innerHTML = h;
            l.querySelectorAll('.mention-picker__item').forEach(el => {
                el.addEventListener('click', function(ev) {
                    ev.stopPropagation();
                    toggleMention(parseInt(this.getAttribute('data-id'), 10), this.getAttribute('data-name'));
                });
            });
        }

        function toggleMention(id, name) {
            const idx = currentMentions.findIndex(m => m.id === id);
            const ta = document.getElementById('modalNoteText');
            if (idx >= 0) {
                currentMentions.splice(idx, 1);
                if (ta) {
                    const re = new RegExp('@' + name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '\\s?', 'g');
                    ta.value = ta.value.replace(re, '');
                }
            } else {
                currentMentions.push({
                    id,
                    name
                });
                if (ta) {
                    const pos = ta.selectionStart || ta.value.length;
                    const b = ta.value.substring(0, pos);
                    const a = ta.value.substring(pos);
                    const pfx = (b && !b.endsWith(' ') && !b.endsWith('\n')) ? ' ' : '';
                    const ins = pfx + '@' + name + ' ';
                    ta.value = b + ins + a;
                    try {
                        ta.setSelectionRange(b.length + ins.length, b.length + ins.length);
                    } catch (e) {}
                    ta.focus();
                }
            }
            renderMentionsPreview();
            renderMentionPicker();
        }

        function renderMentionsPreview() {
            const p = document.getElementById('modalNoteMentionsPreview');
            const b = document.getElementById('modalNoteMentionBtn');
            if (!p) return;
            p.innerHTML = '';
            if (currentMentions.length === 0) {
                if (b) b.classList.remove('has-mentions');
                return;
            }
            if (b) b.classList.add('has-mentions');
            currentMentions.forEach(m => {
                const o = (MENTION_OPTIONS || []).find(x => x.id === m.id);
                const av = (o && o.avatar_url) ? `<img src="${escapeHtml(o.avatar_url)}" alt="">` : escapeHtml((o && o.initial) || m.name.charAt(0) || '?');
                const c = document.createElement('div');
                c.className = 'mention-chip';
                c.innerHTML = `<span class="mention-chip__avatar">${av}</span><span>${escapeHtml(m.name)}</span><button type="button" class="mention-chip__remove"><i class="fas fa-times"></i></button>`;
                c.querySelector('.mention-chip__remove').addEventListener('click', function(e) {
                    e.stopPropagation();
                    toggleMention(m.id, m.name);
                });
                p.appendChild(c);
            });
        }
        let notesAddInProgress = false;

        function loadModalNotes(tid) {
            const c = document.getElementById('modalNotesList');
            if (!c) return;
            c.innerHTML = '<p class="modal-notes-empty">در حال بارگذاری...</p>';
            fetch('index.php?get_notes=1&task_id=' + encodeURIComponent(tid), {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            }).then(r => r.text()).then(text => {
                let d;
                try {
                    d = JSON.parse(text);
                } catch (e) {
                    throw new Error('invalid');
                }
                if (d.status !== 'success') {
                    c.innerHTML = `<p class="modal-notes-empty">${escapeHtml(d.message||'خطا')}</p>`;
                    return;
                }
                const n = d.notes || [];
                const ce = document.getElementById('modalNotesCount');
                if (ce) ce.textContent = toPersianDigits(n.length);
                if (n.length === 0) {
                    c.innerHTML = '<p class="modal-notes-empty">هنوز گزارشی ثبت نشده.</p>';
                    return;
                }
                let h = '';
                n.forEach(x => {
                    h += renderModalNote(x);
                });
                c.innerHTML = h;
            }).catch(() => {
                c.innerHTML = '<p class="modal-notes-empty">خطا</p>';
            });
        }

        function renderModalNote(note) {
            const isMine = !!note.is_mine || Number(note.user_id) === CURRENT_USER_ID;
            const an = ((note.first_name || '') + ' ' + (note.last_name || '')).trim() || note.mobile || 'کاربر';
            const al = isMine ? 'من' : an;
            const ini = (note.first_name || note.mobile || '?').trim().charAt(0);
            const ds = note.persian_date || note.created_at || '';
            let ah = '';
            const at = note.attachments || [];
            if (at.length > 0) {
                let it = '';
                at.forEach(a => {
                    const iC = a.icon || 'fa-file';
                    let cc = '';
                    if (iC.includes('pdf')) cc = 'is-pdf';
                    else if (iC.includes('excel')) cc = 'is-excel';
                    else if (iC.includes('word')) cc = 'is-word';
                    else if (iC.includes('image')) cc = 'is-image';
                    else if (iC.includes('zipper')) cc = 'is-zip';
                    it += `<div class="modal-note-attachment"><div class="modal-note-attachment__icon ${cc}"><i class="fas ${escapeHtml(iC)}"></i></div><a class="modal-note-attachment__body" href="${escapeHtml(a.url)}" target="_blank" rel="noopener" download><div class="modal-note-attachment__name">${escapeHtml(a.file_name)}</div><div class="modal-note-attachment__meta">${escapeHtml(a.file_size_human||'')}</div></a><div class="modal-note-attachment__actions"><a class="att-download" href="${escapeHtml(a.url)}" target="_blank" rel="noopener" download><i class="fas fa-download"></i></a><button type="button" class="att-del" onclick="deleteNoteAttachment(${a.id}, ${note.id})"><i class="fas fa-trash-alt"></i></button></div></div>`;
                });
                ah = `<div class="modal-note-attachments">${it}</div>`;
            }
            let nth = escapeHtml(note.note);
            if (MENTION_OPTIONS && MENTION_OPTIONS.length) {
                MENTION_OPTIONS.forEach(o => {
                    const nm = o.name;
                    if (!nm) return;
                    const safe = nm.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
                    const re = new RegExp('@' + safe + '(?=\\s|$)', 'g');
                    nth = nth.replace(re, '<span class="note-mention">@' + escapeHtml(nm) + '</span>');
                });
            }
            return `<div class="modal-note-item" data-note-id="${note.id}"><div class="modal-note-avatar ${isMine?'is-mine':''}">${escapeHtml(ini)}</div><div class="modal-note-body"><div class="modal-note-header"><span class="modal-note-author ${isMine?'is-mine':''}">${escapeHtml(al)}</span><span class="modal-note-date">${escapeHtml(ds)}</span></div><div class="modal-note-text">${nth}</div>${ah}<div class="modal-note-actions">${isMine?`<button type="button" class="modal-note-edit" onclick="startEditNote(${note.id})"><i class="fas fa-pen"></i></button>`:''}<button type="button" class="modal-note-delete" onclick="deleteModalNote(${note.id})"><i class="fas fa-trash-alt"></i></button></div></div></div>`;
        }

        function deleteNoteAttachment(aid, nid) {
            if (!confirm('حذف شود؟')) return;
            const fd = new FormData();
            fd.append('delete_note_attachment', '1');
            fd.append('attachment_id', aid);
            fd.append('csrf_token', CSRF_TOKEN);
            fetch('index.php', {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: fd
            }).then(r => r.text()).then(text => {
                let d;
                try {
                    d = JSON.parse(text);
                } catch (e) {
                    throw new Error('invalid');
                }
                if (d.status === 'success') {
                    const t = document.getElementById('edit_task_id').value;
                    if (t) loadModalNotes(t);
                }
            }).catch(() => alert('خطا'));
        }

        function submitModalNote() {
            if (notesAddInProgress) return;
            const tid = document.getElementById('edit_task_id').value;
            const ta = document.getElementById('modalNoteText');
            const fi = document.getElementById('modalNoteFileInput');
            const nt = (ta.value || '').trim();
            const hf = fi && fi.files && fi.files.length > 0;
            const hm = currentMentions.length > 0;
            if (!tid || tid === '0') {
                alert('ابتدا وظیفه ذخیره شود.');
                return;
            }
            if (!nt && !hf) {
                alert('متن یا فایل وارد کنید.');
                return;
            }
            if (hm && !nt) {
                alert('برای منشن متن بنویسید.');
                return;
            }
            notesAddInProgress = true;
            const btn = document.getElementById('modalNoteSubmitBtn');
            if (btn) btn.disabled = true;
            const fd = new FormData();
            fd.append('add_task_note', '1');
            fd.append('note_task_id', tid);
            fd.append('note_text', nt);
            fd.append('csrf_token', CSRF_TOKEN);
            currentMentions.forEach(m => fd.append('mention_ids[]', m.id));
            if (hf) Array.from(fi.files).forEach(f => fd.append('note_attachments[]', f));
            fetch('index.php', {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: fd
            }).then(r => r.text()).then(text => {
                let d;
                try {
                    d = JSON.parse(text);
                } catch (e) {
                    throw new Error('invalid');
                }
                if (d.status === 'success' || d.status === 'warning') {
                    ta.value = '';
                    if (fi) fi.value = '';
                    renderNoteFilePreview();
                    currentMentions = [];
                    renderMentionsPreview();
                    const mp = document.getElementById('modalNoteMentionPicker');
                    if (mp) mp.style.display = 'none';
                    loadModalNotes(tid);
                    showAppToast('گزارش ثبت شد', 'success');
                } else alert(d.message || 'خطا');
            }).catch(() => alert('خطا')).finally(() => {
                notesAddInProgress = false;
                if (btn) btn.disabled = false;
            });
        }

        function startEditNote(nid) {
            const it = document.querySelector(`.modal-note-item[data-note-id="${nid}"]`);
            if (!it) return;
            const te = it.querySelector('.modal-note-text');
            const ae = it.querySelector('.modal-note-actions');
            if (!te || !ae) return;
            const ta = document.createElement('textarea');
            ta.className = 'modal-note-edit-textarea';
            ta.value = te.textContent || '';
            te.replaceWith(ta);
            ta.focus();
            ae.innerHTML = `<button type="button" class="modal-note-save" onclick="saveEditNote(${nid})"><i class="fas fa-check"></i></button><button type="button" class="modal-note-cancel" onclick="cancelEditNote()"><i class="fas fa-times"></i></button>`;
        }

        function cancelEditNote() {
            const t = document.getElementById('edit_task_id').value;
            if (t) loadModalNotes(t);
        }

        function saveEditNote(nid) {
            const it = document.querySelector(`.modal-note-item[data-note-id="${nid}"]`);
            if (!it) return;
            const ta = it.querySelector('.modal-note-edit-textarea');
            if (!ta) return;
            const nt = (ta.value || '').trim();
            if (!nt) {
                alert('متن خالی است.');
                return;
            }
            const fd = new FormData();
            fd.append('edit_note', '1');
            fd.append('note_id', nid);
            fd.append('note_text', nt);
            fd.append('csrf_token', CSRF_TOKEN);
            fetch('index.php', {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: fd
            }).then(r => r.text()).then(text => {
                let d;
                try {
                    d = JSON.parse(text);
                } catch (e) {
                    throw new Error('invalid');
                }
                if (d.status === 'success') {
                    const t = document.getElementById('edit_task_id').value;
                    if (t) loadModalNotes(t);
                }
            }).catch(() => alert('خطا'));
        }

        function deleteModalNote(nid) {
            if (!confirm('حذف شود؟')) return;
            const fd = new FormData();
            fd.append('delete_note', '1');
            fd.append('note_id', nid);
            fd.append('csrf_token', CSRF_TOKEN);
            fetch('index.php', {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: fd
            }).then(r => r.text()).then(text => {
                let d;
                try {
                    d = JSON.parse(text);
                } catch (e) {
                    throw new Error('invalid');
                }
                if (d.status === 'success') {
                    const t = document.getElementById('edit_task_id').value;
                    if (t) loadModalNotes(t);
                }
            }).catch(() => alert('خطا'));
        }

        function toPersianDigits(n) {
            const fa = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
            return String(n).replace(/\d/g, d => fa[parseInt(d, 10)]);
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
            return String(t).replace(/[&<>"']/g, function(x) {
                return m[x];
            });
        }

        function openEditSubjectModalFromBtn(btn) {
            document.getElementById('edit_subject_id_field').value = btn.getAttribute('data-subject-id');
            document.getElementById('edit_subject_title_field').value = btn.getAttribute('data-subject-title') || '';
            openModal('editSubjectModal');
        }

        function toggleSidebar() {
            const s = document.getElementById('appSidebar'),
                o = document.getElementById('sidebarOverlay'),
                b = document.getElementById('hamburgerBtn');
            if (!s || !o || !b) return;
            if (s.classList.contains('open')) closeSidebar();
            else {
                s.classList.add('open');
                o.classList.add('active');
                b.classList.add('active');
                document.body.style.overflow = 'hidden';
            }
        }

        function closeSidebar() {
            const s = document.getElementById('appSidebar'),
                o = document.getElementById('sidebarOverlay'),
                b = document.getElementById('hamburgerBtn');
            if (!s || !o || !b) return;
            s.classList.remove('open');
            o.classList.remove('active');
            b.classList.remove('active');
            document.body.style.overflow = '';
        }
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                const em = document.getElementById('editTaskModal');
                if (em && em.classList.contains('active')) {
                    requestCloseEditModal();
                    return;
                }
                const cm = document.getElementById('confirmCloseModal');
                if (cm && cm.classList.contains('active')) {
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
            if (typeof Chart === 'undefined') return;
            const D = window.DASHBOARD_DATA;
            if (!D) return;
            Chart.defaults.font.family = 'Segoe UI, Tahoma, sans-serif';
            Chart.defaults.font.size = 12;
            Chart.defaults.color = '#8a94ad';
            Chart.defaults.animation = false;
            const tc = document.getElementById('trendChart');
            if (tc) {
                const g = tc.getContext('2d').createLinearGradient(0, 0, 0, 240);
                g.addColorStop(0, 'rgba(76, 139, 245, 0.35)');
                g.addColorStop(1, 'rgba(76, 139, 245, 0.02)');
                new Chart(tc, {
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
                            pointHoverBorderWidth: 3
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        animation: false,
                        plugins: {
                            legend: {
                                display: false
                            }
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                ticks: {
                                    stepSize: 1,
                                    precision: 0
                                },
                                grid: {
                                    color: '#f1f4fb'
                                }
                            },
                            x: {
                                grid: {
                                    display: false
                                }
                            }
                        }
                    }
                });
            }
            const pc = document.getElementById('priorityChart');
            if (pc) {
                const gB = pc.getContext('2d').createLinearGradient(0, 0, 0, 240);
                gB.addColorStop(0, '#4ed4a3');
                gB.addColorStop(1, '#2ebc8a');
                new Chart(pc, {
                    type: 'bar',
                    data: {
                        labels: ['کم', 'متوسط', 'زیاد'],
                        datasets: [{
                            label: 'تعداد',
                            data: [D.priorityLow, D.priorityMedium, D.priorityHigh],
                            backgroundColor: gB,
                            borderRadius: 10,
                            borderSkipped: false,
                            barThickness: 42
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        animation: false,
                        plugins: {
                            legend: {
                                display: false
                            }
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                ticks: {
                                    stepSize: 1,
                                    precision: 0
                                },
                                grid: {
                                    color: '#f1f4fb'
                                }
                            },
                            x: {
                                grid: {
                                    display: false
                                },
                                ticks: {
                                    font: {
                                        size: 12,
                                        weight: 'bold'
                                    }
                                }
                            }
                        }
                    }
                });
            }
            const tkc = document.getElementById('tasksChart');
            if (tkc) new Chart(tkc, {
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
                                font: {
                                    size: 13,
                                    weight: 'bold'
                                },
                                usePointStyle: true,
                                pointStyle: 'circle'
                            }
                        }
                    }
                }
            });
            const atc = document.getElementById('avgTimeChart');
            if (atc) new Chart(atc, {
                type: 'bar',
                data: {
                    labels: ['وظایف من', 'درخواست‌های تماس', 'تیکت‌ها'],
                    datasets: [{
                        label: 'میانگین زمان (ساعت)',
                        data: [D.taskAvgHours, D.callAvgHours, D.ticketAvgHours],
                        backgroundColor: ['rgba(76, 139, 245, 0.85)', 'rgba(46, 188, 138, 0.85)', 'rgba(127, 76, 240, 0.85)'],
                        borderRadius: 10,
                        borderSkipped: false,
                        barThickness: 55
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    animation: false,
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            callbacks: {
                                label: function(c) {
                                    const h = c.parsed.y;
                                    if (h <= 0) return 'بدون داده';
                                    if (h < 1) return Math.round(h * 60) + ' دقیقه';
                                    if (h < 24) {
                                        const hh = Math.floor(h);
                                        const mm = Math.round((h - hh) * 60);
                                        return mm === 0 ? hh + ' ساعت' : hh + ' ساعت و ' + mm + ' دقیقه';
                                    }
                                    return Math.floor(h / 24) + ' روز';
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            grid: {
                                color: '#f1f4fb'
                            }
                        },
                        x: {
                            grid: {
                                display: false
                            },
                            ticks: {
                                font: {
                                    size: 12,
                                    weight: 'bold'
                                }
                            }
                        }
                    }
                }
            });
        }

        function bindAjaxForms() {
            const cf = document.querySelector('#createTaskModal form.task-modal-form');
            if (cf && !cf.__ajaxBound) {
                cf.__ajaxBound = true;
                cf.addEventListener('submit', async function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    await submitTaskFormAjax(cf, 'createTaskModal');
                });
            }
            const ef = document.getElementById('editTaskForm');
            if (ef && !ef.__ajaxBound) {
                ef.__ajaxBound = true;
                ef.addEventListener('submit', async function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    await submitTaskFormAjax(ef, 'editTaskModal');
                });
            }
            document.querySelectorAll('form').forEach(form => {
                if (form.__ajaxBound) return;
                const di = form.querySelector('input[name="delete"]');
                const ds = form.querySelector('input[name="delete_subject"]');
                if (di || ds) {
                    form.__ajaxBound = true;
                    form.addEventListener('submit', async function(e) {
                        e.preventDefault();
                        e.stopPropagation();
                        if (!confirm('حذف شود؟')) return;
                        const fd = new FormData(form);
                        try {
                            const res = await fetch('index.php', {
                                method: 'POST',
                                headers: {
                                    'X-Requested-With': 'XMLHttpRequest'
                                },
                                body: fd
                            });
                            const data = await res.json();
                            if (data.status === 'success' || data.status === 'warning') {
                                showAppToast(data.message || 'حذف شد', 'success');
                                await refreshTaskList();
                            } else showAppToast(data.message || 'خطا', 'error');
                        } catch (err) {
                            showAppToast('خطا', 'error');
                        }
                    });
                }
            });
        }
        async function submitTaskFormAjax(form, mid) {
            const sb = form.querySelector('button[type="submit"]');
            if (sb) sb.disabled = true;
            try {
                const fd = new FormData(form);
                const btn = form.querySelector('button[name="add_task"], button[name="update_task"]');
                if (btn && btn.name && !fd.has(btn.name)) fd.append(btn.name, btn.value || '1');
                const res = await fetch('index.php', {
                    method: 'POST',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: fd
                });
                const data = await res.json();
                if (data.status === 'success' || data.status === 'warning') {
                    closeModal(mid);
                    showAppToast(data.message || 'ذخیره شد', data.type === 'warning' ? 'info' : 'success');
                    await refreshTaskList();
                } else showAppToast(data.message || 'خطا', 'error');
            } catch (err) {
                showAppToast('خطای شبکه', 'error');
            } finally {
                if (sb) sb.disabled = false;
            }
        }

        function requestNotificationPermission() {
            if (!('Notification' in window)) return;
            if (Notification.permission === 'default') {
                try {
                    Notification.requestPermission();
                } catch (e) {}
            }
        }
        let notificationPollerStarted = false;

        function startNotificationPoller() {
            if (notificationPollerStarted) return;
            notificationPollerStarted = true;
            async function poll() {
                try {
                    const res = await fetch('index.php?check_due_notifications=1', {
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    });
                    const data = await res.json();
                    if (data.status === 'success' && Array.isArray(data.tasks) && data.tasks.length > 0) {
                        data.tasks.forEach(t => {
                            const lines = [];
                            if (t.description) lines.push(t.description);
                            if (t.project_title) lines.push('پروژه: ' + t.project_title);
                            const body = lines.join('\n') || 'زمان انجام این وظیفه رسیده است.';
                            const title = 'یادآوری وظیف : ' + t.title;
                            if ('Notification' in window && Notification.permission === 'granted') {
                                try {
                                    const n = new Notification(title, {
                                        body: body,
                                        icon: 'https://img.icons8.com/color/96/dashboard-layout.png',
                                        tag: 'task-due-' + t.id,
                                        requireInteraction: true
                                    });
                                    n.onclick = function() {
                                        window.focus();
                                        n.close();
                                    };
                                } catch (e) {}
                            }
                            showAppToast('⏰ یادآوری: ' + t.title, 'info');
                        });
                    }
                } catch (e) {}
            }
            poll();
            setInterval(poll, 30000);
        }

        document.addEventListener('DOMContentLoaded', function() {
            initPersianDatePickers();
            setupDatepickerDigitConversion();
            const cfi = document.getElementById('createFileInput');
            if (cfi) cfi.addEventListener('change', () => renderFilePreview('createFileInput', 'createFilePreviewList'));
            const efi = document.getElementById('editFileInput');
            if (efi) efi.addEventListener('change', () => renderFilePreview('editFileInput', 'editFilePreviewList'));
            setupDropZone('createFileDropZone', 'createFileInput', 'createFilePreviewList');
            setupDropZone('editFileDropZone', 'editFileInput', 'editFilePreviewList');
            const nfi = document.getElementById('modalNoteFileInput');
            if (nfi) nfi.addEventListener('change', renderNoteFilePreview);
            const nt = document.getElementById('modalNoteText');
            if (nt) {
                nt.addEventListener('keydown', function(e) {
                    if (e.key === 'Enter' && !e.shiftKey) {
                        e.preventDefault();
                        e.stopPropagation();
                        submitModalNote();
                    }
                });
            }
            requestNotificationPermission();
            startNotificationPoller();
            bindAjaxForms();
            const observer = new MutationObserver(function() {
                bindAjaxForms();
            });
            observer.observe(document.body, {
                childList: true,
                subtree: true
            });
            if (typeof Chart === 'undefined') {
                const s = document.createElement('script');
                s.src = 'https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js';
                s.onload = initCharts;
                document.head.appendChild(s);
            } else initCharts();
            <?php if ($sharedTaskData): ?>
                    (function tryOpenSharedTask() {
                        let attempts = 0;

                        function attempt() {
                            attempts++;
                            const em = document.getElementById('editTaskModal');
                            const eid = document.getElementById('edit_task_id');
                            if (em && eid && typeof openEditModal === 'function') {
                                try {
                                    openEditModal(SHARED_TASK_DATA, SHARED_TASK_PERMS.edit, SHARED_TASK_PERMS.reassign, SHARED_TASK_PERMS.change_project, SHARED_TASK_PERMS.complete);
                                } catch (e) {}
                                return;
                            }
                            if (attempts < 30) setTimeout(attempt, 100);
                        }
                        setTimeout(attempt, 300);
                    })();
            <?php endif; ?>
        });

        document.addEventListener('keydown', function(e) {
            const isSaveCombo = (e.ctrlKey || e.metaKey) && !e.shiftKey && !e.altKey && (e.key === 's' || e.key === 'S' || e.keyCode === 83);
            if (!isSaveCombo) return;
            const cm = document.getElementById('createTaskModal');
            const em = document.getElementById('editTaskModal');
            if (cm && cm.classList.contains('active')) {
                e.preventDefault();
                e.stopPropagation();
                const b = cm.querySelector('button[name="add_task"]');
                if (b) b.click();
                return;
            }
            if (em && em.classList.contains('active')) {
                e.preventDefault();
                e.stopPropagation();
                const b = em.querySelector('button[name="update_task"]');
                if (b && b.style.display !== 'none') b.click();
                return;
            }
        }, true);
        document.addEventListener('keydown', function(e) {
            const em = document.getElementById('editTaskModal');
            if (!em || !em.classList.contains('active')) return;
            const nt = document.getElementById('modalNoteText');
            if (!nt || document.activeElement !== nt) return;
            if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
                e.preventDefault();
                submitModalNote();
            }
        }, true);
    </script>
</body>

</html>