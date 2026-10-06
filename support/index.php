<?php
ob_start();

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../database/Database.php';
require_once __DIR__ . '/../includes/chat_notifications.php';

$page = 'support';

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
    return ($includeWeekday ? $weekdays[$persianWd] . ' ' : '')
         . $jd . ' ' . $months[$jm - 1] . ' ' . $jy . ' - ' . $time;
}

// ================== CSRF ==================
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

function verifyCsrf(): bool {
    if (!isset($_POST['csrf_token'], $_SESSION['csrf_token'])) return false;
    return hash_equals($_SESSION['csrf_token'], (string)$_POST['csrf_token']);
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

function redirectTo(string $url, string $msg = '', string $type = 'success'): void {
    while (ob_get_level() > 0) ob_end_clean();
    if ($msg !== '') {
        $_SESSION['flash_msg'] = $msg;
        $_SESSION['flash_type'] = $type;
    }
    header('Location: ' . $url);
    exit;
}

// ================== بررسی وضعیت کاربر ==================
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

// ================== ساخت خودکار جداول (یکبار) ==================
if (empty($_SESSION['checked_support_tables'])) {
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS support_tickets (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            subject VARCHAR(255) NOT NULL,
            priority ENUM('low','medium','high','very_high') NOT NULL DEFAULT 'medium',
            status ENUM('open','answered','closed') NOT NULL DEFAULT 'open',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            last_reply_at TIMESTAMP NULL DEFAULT NULL,
            closed_at TIMESTAMP NULL DEFAULT NULL,
            INDEX idx_support_tickets_user (user_id),
            INDEX idx_support_tickets_status (status),
            INDEX idx_support_tickets_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;");

        $db->exec("CREATE TABLE IF NOT EXISTS support_messages (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ticket_id INT NOT NULL,
            sender_id INT NOT NULL,
            sender_role ENUM('user','admin') NOT NULL DEFAULT 'user',
            message TEXT NOT NULL,
            is_read_by_user TINYINT(1) NOT NULL DEFAULT 0,
            is_read_by_admin TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_support_messages_ticket (ticket_id),
            INDEX idx_support_messages_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;");

        $_SESSION['checked_support_tables'] = 1;
    } catch (PDOException $e) {
        error_log('support tables check error: ' . $e->getMessage());
    }
}

$msg = '';
$msgType = 'success';
if (!empty($_SESSION['flash_msg'])) {
    $msg = (string)$_SESSION['flash_msg'];
    $msgType = (string)($_SESSION['flash_type'] ?? 'success');
    unset($_SESSION['flash_msg'], $_SESSION['flash_type']);
}

$ticketId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// ================== ثبت تیکت جدید ==================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_ticket'])) {
    if (!verifyCsrf()) redirectTo('index.php', 'درخواست نامعتبر است.', 'danger');
    if (!verifyFormToken('support_create')) redirectTo('index.php', 'فرم قبلاً ارسال شده است.', 'warning');

    $subject  = trim((string)($_POST['subject'] ?? ''));
    $message  = trim((string)($_POST['message'] ?? ''));
    $priority = (string)($_POST['priority'] ?? 'medium');

    if (mb_strlen($subject) > 255) redirectTo('index.php', 'عنوان تیکت بسیار طولانی است.', 'danger');
    if (mb_strlen($message) > 5000) redirectTo('index.php', 'متن تیکت بسیار طولانی است.', 'danger');
    if (!in_array($priority, ['low','medium','high','very_high'], true)) $priority = 'medium';

    if ($subject === '') redirectTo('index.php', 'عنوان تیکت الزامی است.', 'danger');
    if ($message === '') redirectTo('index.php', 'متن تیکت الزامی است.', 'danger');

    try {
        $db->beginTransaction();

        $stmt = $db->prepare("INSERT INTO support_tickets (user_id, subject, priority, status) VALUES (?, ?, ?, 'open')");
        $stmt->execute([$userId, $subject, $priority]);
        $newId = (int)$db->lastInsertId();

        if ($newId > 0) {
            $ins = $db->prepare("INSERT INTO support_messages (ticket_id, sender_id, sender_role, message, is_read_by_user, is_read_by_admin) VALUES (?, ?, 'user', ?, 1, 0)");
            $ins->execute([$newId, $userId, $message]);

            $db->prepare("UPDATE support_tickets SET last_reply_at = NOW() WHERE id = ?")->execute([$newId]);
        }

        $db->commit();
        redirectTo('index.php?id=' . $newId, 'تیکت شما با موفقیت ثبت شد.');
    } catch (PDOException $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('create support ticket error: ' . $e->getMessage());
        redirectTo('index.php', 'خطا در ثبت تیکت. لطفاً دوباره تلاش کنید.', 'danger');
    }
}

// ================== ارسال پاسخ ==================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reply_ticket'])) {
    $tid = (int)$_POST['reply_ticket'];
    if (!verifyCsrf()) redirectTo('index.php?id=' . $tid, 'درخواست نامعتبر است.', 'danger');

    $message = trim((string)($_POST['message'] ?? ''));
    if (mb_strlen($message) > 5000) redirectTo('index.php?id=' . $tid, 'متن پاسخ بسیار طولانی است.', 'danger');
    if ($message === '') redirectTo('index.php?id=' . $tid, 'متن پاسخ الزامی است.', 'danger');

    try {
        $chk = $db->prepare("SELECT id, status FROM support_tickets WHERE id = ? AND user_id = ? LIMIT 1");
        $chk->execute([$tid, $userId]);
        $row = $chk->fetch(PDO::FETCH_ASSOC);

        if (!$row) redirectTo('index.php', 'تیکت یافت نشد.', 'danger');
        if ($row['status'] === 'closed') redirectTo('index.php?id=' . $tid, 'این تیکت بسته شده است.', 'warning');

        $ins = $db->prepare("INSERT INTO support_messages (ticket_id, sender_id, sender_role, message, is_read_by_user, is_read_by_admin) VALUES (?, ?, 'user', ?, 1, 0)");
        $ins->execute([$tid, $userId, $message]);

        $db->prepare("UPDATE support_tickets SET status = 'open', last_reply_at = NOW() WHERE id = ?")->execute([$tid]);

        redirectTo('index.php?id=' . $tid, 'پاسخ شما ثبت شد.');
    } catch (PDOException $e) {
        error_log('reply support ticket error: ' . $e->getMessage());
        redirectTo('index.php?id=' . $tid, 'خطا در ثبت پاسخ.', 'danger');
    }
}

// ================== بستن / بازگشایی تیکت ==================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['close_ticket'])) {
    $tid = (int)$_POST['close_ticket'];
    if (!verifyCsrf()) redirectTo('index.php?id=' . $tid, 'درخواست نامعتبر است.', 'danger');

    try {
        $chk = $db->prepare("SELECT id FROM support_tickets WHERE id = ? AND user_id = ? LIMIT 1");
        $chk->execute([$tid, $userId]);
        if (!$chk->fetchColumn()) redirectTo('index.php', 'تیکت یافت نشد.', 'danger');

        $db->prepare("UPDATE support_tickets SET status = 'closed', closed_at = NOW() WHERE id = ?")->execute([$tid]);
        redirectTo('index.php?id=' . $tid, 'تیکت بسته شد.');
    } catch (PDOException $e) {
        error_log('close support ticket error: ' . $e->getMessage());
        redirectTo('index.php?id=' . $tid, 'خطا در بستن تیکت.', 'danger');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reopen_ticket'])) {
    $tid = (int)$_POST['reopen_ticket'];
    if (!verifyCsrf()) redirectTo('index.php?id=' . $tid, 'درخواست نامعتبر است.', 'danger');

    try {
        $chk = $db->prepare("SELECT id FROM support_tickets WHERE id = ? AND user_id = ? LIMIT 1");
        $chk->execute([$tid, $userId]);
        if (!$chk->fetchColumn()) redirectTo('index.php', 'تیکت یافت نشد.', 'danger');

        $db->prepare("UPDATE support_tickets SET status = 'open', closed_at = NULL WHERE id = ?")->execute([$tid]);
        redirectTo('index.php?id=' . $tid, 'تیکت بازگشایی شد.');
    } catch (PDOException $e) {
        error_log('reopen support ticket error: ' . $e->getMessage());
        redirectTo('index.php?id=' . $tid, 'خطا در بازگشایی تیکت.', 'danger');
    }
}

// ================== حذف تیکت ==================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_ticket'])) {
    if (!verifyCsrf()) redirectTo('index.php', 'درخواست نامعتبر است.', 'danger');

    $tid = (int)$_POST['delete_ticket'];
    try {
        $db->prepare("DELETE FROM support_tickets WHERE id = ? AND user_id = ?")->execute([$tid, $userId]);
        redirectTo('index.php', 'تیکت حذف شد.');
    } catch (PDOException $e) {
        error_log('delete support ticket error: ' . $e->getMessage());
        redirectTo('index.php', 'خطا در حذف تیکت.', 'danger');
    }
}

// ================== کاربر جاری ==================
$currentUserFirstName = 'کاربر';
$currentUserAvatar = null;
$displayUser = $_SESSION['user_mobile'] ?? $_SESSION['mobile'] ?? $_SESSION['username'] ?? 'کاربر';

try {
    $curStmt = $db->prepare("SELECT first_name, avatar FROM users WHERE id = ? LIMIT 1");
    $curStmt->execute([$userId]);
    $curRow = $curStmt->fetch(PDO::FETCH_ASSOC);
    if ($curRow) {
        if (!empty($curRow['first_name'])) $currentUserFirstName = $curRow['first_name'];
        if (!empty($curRow['avatar'])) {
            $avBase = basename($curRow['avatar']);
            if (preg_match('/^[A-Za-z0-9_.\-]+$/', $avBase) && file_exists(__DIR__ . '/../uploads/avatars/' . $avBase)) {
                $currentUserAvatar = '../uploads/avatars/' . rawurlencode($avBase);
            }
        }
    }
} catch (PDOException $e) {
    error_log('current user load error: ' . $e->getMessage());
}

$priorityLabels = [
    'low'       => 'کم',
    'medium'    => 'متوسط',
    'high'      => 'زیاد',
    'very_high' => 'خیلی زیاد',
];

$statusLabels = [
    'open'     => 'در انتظار پاسخ',
    'answered' => 'پاسخ داده شده',
    'closed'   => 'بسته شده',
];

// ================== اطلاعات ==================
$singleTicket = null;
$messages = [];
$cTotal = $cOpen = $cAnswered = $cClosed = 0;
$tickets = [];

if ($ticketId > 0) {
    try {
        $stmt = $db->prepare("
            SELECT id, user_id, subject, priority, status, created_at, updated_at, last_reply_at, closed_at
            FROM support_tickets
            WHERE id = ? AND user_id = ?
            LIMIT 1
        ");
        $stmt->execute([$ticketId, $userId]);
        $singleTicket = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$singleTicket) {
            redirectTo('index.php', 'تیکت یافت نشد یا به آن دسترسی ندارید.', 'danger');
        }

        // علامت‌گذاری همه پیام‌های ادمین به‌عنوان خوانده‌شده توسط کاربر
        $db->prepare("UPDATE support_messages SET is_read_by_user = 1 WHERE ticket_id = ? AND sender_role = 'admin' AND is_read_by_user = 0")
           ->execute([$ticketId]);

        // بارگذاری پیام‌ها
        $mStmt = $db->prepare("
            SELECT sm.id, sm.sender_id, sm.sender_role, sm.message, sm.created_at,
                   u.first_name, u.last_name, u.mobile, u.avatar
            FROM support_messages sm
            LEFT JOIN users u ON u.id = sm.sender_id
            WHERE sm.ticket_id = ?
            ORDER BY sm.created_at ASC, sm.id ASC
        ");
        $mStmt->execute([$ticketId]);
        $messages = $mStmt->fetchAll(PDO::FETCH_ASSOC);

    } catch (PDOException $e) {
        error_log('load support ticket error: ' . $e->getMessage());
        redirectTo('index.php', 'خطا در بارگذاری تیکت.', 'danger');
    }
} else {
    // لیست
    try {
        $stmt = $db->prepare("
            SELECT st.id, st.subject, st.priority, st.status, st.created_at, st.last_reply_at,
                   (SELECT COUNT(*) FROM support_messages sm WHERE sm.ticket_id = st.id) AS msg_count,
                   (SELECT COUNT(*) FROM support_messages sm WHERE sm.ticket_id = st.id AND sm.sender_role = 'admin' AND sm.is_read_by_user = 0) AS unread_count,
                   (SELECT message FROM support_messages sm WHERE sm.ticket_id = st.id ORDER BY sm.created_at DESC, sm.id DESC LIMIT 1) AS last_message
            FROM support_tickets st
            WHERE st.user_id = ?
            ORDER BY COALESCE(st.last_reply_at, st.created_at) DESC
        ");
        $stmt->execute([$userId]);
        $tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($tickets as $t) {
            $cTotal++;
            if ($t['status'] === 'open')     $cOpen++;
            if ($t['status'] === 'answered') $cAnswered++;
            if ($t['status'] === 'closed')   $cClosed++;
        }
    } catch (PDOException $e) {
        error_log('list support tickets error: ' . $e->getMessage());
    }
}

$formTokenCreate = formToken('support_create');

if (ob_get_level() > 0) ob_end_flush();

require_once __DIR__ . '/../includes/sidebar.php';
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <title>پشتیبانی — تسکام</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="icon" href="https://img.icons8.com/color/48/dashboard-layout.png" type="image/png">
<style>
    :root {
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
        --purple: #7f4cf0;
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

    /* ==== Sidebar theme matching ==== */
    .sidebar {
        background: linear-gradient(180deg, #4c8bf5 0%, #2f6bdc 100%) !important;
        color: #fff !important;
        box-shadow: -6px 0 28px rgba(76, 139, 245, 0.18);
    }
    .sidebar-brand { border-bottom: 1px solid rgba(255,255,255,0.15) !important; }
    .sidebar .menu-item { color: rgba(255,255,255,0.85) !important; }
    .sidebar .menu-item:hover,
    .sidebar .menu-item.active { background: rgba(255,255,255,0.18) !important; color: #fff !important; }
    .menu-parent { color: rgba(255,255,255,0.82) !important; }
    .menu-parent:hover, .menu-parent.is-open, .menu-parent.is-current {
        background: rgba(255,255,255,0.14) !important;
        color: #fff !important;
    }
    .submenu-inner a { color: rgba(255,255,255,0.78) !important; }
    .submenu-inner a:hover, .submenu-inner a.active {
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
        font-weight: 700; font-size: 0.85rem;
        overflow: hidden; flex-shrink: 0;
    }
    .topbar-user .avatar img { width: 100%; height: 100%; object-fit: cover; }

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
        font-size: 0.88rem;
    }
    .flash-success { background: #e3f8ef; color: #0e7a55; border: 1px solid #a7f3d0; }
    .flash-warning { background: #fff3e0; color: #92400e; border: 1px solid #fde68a; }
    .flash-danger  { background: #ffe4ec; color: #991b1b; border: 1px solid #fecaca; }

    /* ==== Stats ==== */
    .hero-stats {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 16px;
        margin-bottom: 22px;
    }
    .hero-card {
        border-radius: 18px;
        padding: 20px;
        color: #fff;
        position: relative;
        overflow: hidden;
        min-height: 120px;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
        box-shadow: 0 10px 24px rgba(0,0,0,0.08);
        transition: box-shadow 0.25s ease, transform 0.25s ease;
    }
    .hero-card:hover { box-shadow: 0 16px 32px rgba(0,0,0,0.14); transform: translateY(-1px); }
    .hero-card::before {
        content: '';
        position: absolute;
        top: -40px; left: -40px;
        width: 130px; height: 130px;
        background: rgba(255,255,255,0.14);
        border-radius: 50%;
    }
    .hero-card__top { display: flex; justify-content: flex-start; position: relative; z-index: 2; }
    .hero-card__icon {
        width: 40px; height: 40px;
        border-radius: 12px;
        background: rgba(255,255,255,0.24);
        display: flex; align-items: center; justify-content: center;
        font-size: 0.95rem;
    }
    .hero-card__bottom { position: relative; z-index: 2; }
    .hero-card__value { font-size: 1.9rem; font-weight: 800; line-height: 1; letter-spacing: -1px; }
    .hero-card__label { font-size: 0.78rem; opacity: 0.92; margin-top: 6px; font-weight: 500; }

    .card-blue   { background: linear-gradient(135deg, #6ba4ff 0%, #2f6bdc 100%); }
    .card-orange { background: linear-gradient(135deg, #ffa751 0%, #ff8a3d 100%); }
    .card-green  { background: linear-gradient(135deg, #4ed4a3 0%, #2ebc8a 100%); }
    .card-purple { background: linear-gradient(135deg, #a26bfa 0%, #7f4cf0 100%); }

    /* ==== Section header ==== */
    .section-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 12px;
        flex-wrap: wrap;
        margin-bottom: 16px;
    }
    .section-title {
        display: flex;
        align-items: center;
        gap: 10px;
        font-size: 0.98rem;
        font-weight: 700;
        color: var(--text);
        margin: 0;
    }
    .section-title i {
        width: 32px; height: 32px;
        border-radius: 10px;
        background: #e7f0ff;
        color: #4c8bf5;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 0.82rem;
    }

    .btn-new {
        background: linear-gradient(135deg, #4c8bf5 0%, #2f6bdc 100%);
        color: #fff;
        border: none;
        padding: 11px 20px;
        border-radius: 12px;
        font-weight: 700;
        cursor: pointer;
        font-family: inherit;
        font-size: 0.88rem;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        box-shadow: 0 6px 16px rgba(76, 139, 245, 0.28);
        transition: transform 0.15s ease, box-shadow 0.2s ease;
    }
    .btn-new:hover { transform: translateY(-1px); box-shadow: 0 8px 20px rgba(76, 139, 245, 0.4); }

    /* ==== Tabs ==== */
    .tabs-bar {
        display: flex;
        gap: 6px;
        margin-bottom: 18px;
        background: #fff;
        padding: 6px;
        border-radius: 14px;
        box-shadow: var(--shadow);
        border: 1px solid var(--border);
        width: fit-content;
        max-width: 100%;
        overflow-x: auto;
    }
    .tab-btn {
        background: transparent;
        border: none;
        padding: 9px 18px;
        font-weight: 700;
        font-size: 0.84rem;
        cursor: pointer;
        color: var(--text-soft);
        border-radius: 10px;
        font-family: inherit;
        transition: all 0.18s ease;
        white-space: nowrap;
        display: inline-flex;
        align-items: center;
        gap: 6px;
    }
    .tab-btn:hover:not(.active) { background: #f1f4fb; color: var(--text); }
    .tab-btn.active {
        background: linear-gradient(135deg, #4c8bf5, #2f6bdc);
        color: #fff;
        box-shadow: 0 4px 12px rgba(76, 139, 245, 0.3);
    }
    .tab-count {
        background: #f1f4fb;
        color: var(--text-soft);
        padding: 1px 8px;
        border-radius: 8px;
        font-size: 0.7rem;
        font-weight: 700;
    }
    .tab-btn.active .tab-count { background: rgba(255,255,255,0.25); color: #fff; }

    /* ==== Ticket list ==== */
    .ticket-list { display: flex; flex-direction: column; gap: 12px; }

    .ticket-card {
        background: #fff;
        border: 1px solid var(--border);
        border-radius: 16px;
        padding: 16px 20px 16px 22px;
        position: relative;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 14px;
        transition: border-color 0.15s ease, box-shadow 0.15s ease, transform 0.15s ease;
        cursor: pointer;
        text-decoration: none;
        color: inherit;
    }
    .ticket-card:hover {
        border-color: #d8e0f0;
        box-shadow: 0 6px 18px rgba(76, 108, 200, 0.08);
        transform: translateY(-1px);
    }

    .ticket-bar {
        position: absolute;
        top: 12px; bottom: 12px;
        right: 0;
        width: 5px;
        border-radius: 6px 0 0 6px;
    }
    .bar-low       { background: linear-gradient(180deg, #4ed4a3, #2ebc8a); }
    .bar-medium    { background: linear-gradient(180deg, #ffc76b, #ff8a3d); }
    .bar-high      { background: linear-gradient(180deg, #ff8ba3, #f54e7a); }
    .bar-very_high { background: linear-gradient(180deg, #b14bff, #7f4cf0); }

    .ticket-info { flex: 1; min-width: 0; }
    .ticket-head {
        display: flex;
        align-items: center;
        gap: 10px;
        flex-wrap: wrap;
        margin-bottom: 6px;
    }
    .ticket-id-badge {
        font-size: 0.72rem;
        background: #f1f4fb;
        color: var(--text-soft);
        padding: 3px 9px;
        border-radius: 8px;
        font-weight: 700;
        direction: ltr;
    }
    .ticket-subject {
        font-size: 0.95rem;
        font-weight: 700;
        color: var(--text);
        line-height: 1.5;
        word-break: break-word;
    }
    .ticket-preview {
        font-size: 0.8rem;
        color: var(--text-soft);
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        max-width: 100%;
        line-height: 1.6;
    }
    .ticket-meta {
        display: flex;
        align-items: center;
        gap: 8px;
        flex-wrap: wrap;
        margin-top: 8px;
    }
    .badge {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        font-size: 0.7rem;
        font-weight: 700;
        padding: 3px 9px;
        border-radius: 8px;
        background: #f1f4fb;
        color: var(--text-soft);
    }
    .badge-priority-low       { background: #e3f8ef; color: #0e7a55; }
    .badge-priority-medium    { background: #fff3e0; color: #b45309; }
    .badge-priority-high      { background: #ffe4ec; color: #c81e4a; }
    .badge-priority-very_high { background: #f0e9ff; color: #6b21a8; }
    .badge-status-open        { background: #e7f0ff; color: #2f6bdc; }
    .badge-status-answered    { background: #fff7d6; color: #ca8a04; }
    .badge-status-closed      { background: #f1f4fb; color: #8a94ad; }
    .badge-date               { background: #eef1f8; color: #5b6b8c; }
    .badge-unread {
        background: #f54e7a;
        color: #fff;
        font-size: 0.68rem;
        min-width: 20px;
        height: 20px;
        border-radius: 50%;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 0 6px;
        animation: pulse 2s ease-in-out infinite;
    }
    @keyframes pulse {
        0%, 100% { transform: scale(1); }
        50% { transform: scale(1.1); }
    }

    .ticket-arrow {
        color: var(--text-soft);
        font-size: 0.9rem;
        flex-shrink: 0;
    }

    /* ==== Empty ==== */
    .empty-state {
        text-align: center;
        padding: 60px 20px;
        background: #fff;
        border: 1px dashed var(--border);
        border-radius: 18px;
        color: var(--text-soft);
    }
    .empty-state i { font-size: 2.5rem; color: #c7d7ff; margin-bottom: 14px; }
    .empty-state p { margin: 0 0 16px; font-size: 0.9rem; }

    /* ==== Modal ==== */
    .modal-overlay {
        position: fixed;
        inset: 0;
        background: rgba(20, 30, 60, 0.6);
        display: none;
        align-items: center;
        justify-content: center;
        z-index: 1000;
        padding: 15px;
    }
    .modal-overlay.active { display: flex; }
    .modal-box {
        background: #fff;
        width: 100%;
        max-width: 560px;
        border-radius: 22px;
        display: flex;
        flex-direction: column;
        max-height: 92vh;
        overflow: hidden;
        box-shadow: 0 24px 60px rgba(20, 30, 60, 0.25);
        animation: modalIn 0.25s ease;
    }
    @keyframes modalIn {
        from { transform: translateY(20px); opacity: 0; }
        to   { transform: translateY(0); opacity: 1; }
    }
    .modal-header {
        padding: 18px 24px;
        border-bottom: 1px solid var(--border);
        display: flex; justify-content: space-between; align-items: center;
        gap: 10px;
    }
    .modal-header h3 {
        margin: 0; font-size: 1rem; font-weight: 700; color: var(--text);
        display: flex; align-items: center; gap: 10px;
    }
    .modal-header h3 .h-icon {
        width: 34px; height: 34px;
        border-radius: 10px;
        background: linear-gradient(135deg, #4c8bf5, #2f6bdc);
        color: #fff;
        display: flex; align-items: center; justify-content: center;
        font-size: 0.85rem;
    }
    .modal-close {
        background: #f1f4fb; border: none;
        width: 34px; height: 34px; border-radius: 50%;
        display: flex; align-items: center; justify-content: center;
        font-size: 1.2rem; cursor: pointer; color: var(--text-soft);
        font-weight: 700; transition: all 0.15s ease; flex-shrink: 0;
    }
    .modal-close:hover { background: #ffe4ec; color: #c81e4a; }
    .modal-body { padding: 20px 24px; overflow-y: auto; flex: 1; }
    .modal-footer {
        padding: 16px 24px;
        border-top: 1px solid var(--border);
        display: flex; justify-content: space-between; align-items: center;
        gap: 12px; background: #fff;
    }

    .form-group { margin-bottom: 16px; }
    .form-group label {
        display: flex; align-items: center; gap: 7px;
        font-size: 0.8rem; font-weight: 700; color: var(--text-soft);
        margin-bottom: 8px;
    }
    .form-group label i { font-size: 0.72rem; color: var(--accent); width: 14px; text-align: center; }

    .form-control {
        width: 100%;
        padding: 12px 14px;
        background: #fafbfe;
        border: 2px solid var(--border);
        border-radius: 12px;
        font-size: 0.9rem;
        font-family: inherit;
        color: var(--text);
        transition: border-color 0.2s ease, box-shadow 0.2s ease, background 0.2s ease;
    }
    .form-control:focus {
        outline: none;
        border-color: var(--accent);
        background: #fff;
        box-shadow: 0 0 0 4px rgba(76, 139, 245, 0.12);
    }
    textarea.form-control { resize: vertical; min-height: 130px; line-height: 1.7; }

   /* ==== Priority Group (FIXED) ==== */
.priority-group {
    display: flex;
    gap: 6px;
    width: 100%;
}

.priority-opt {
    flex: 1 1 0;
    min-width: 0;
    display: flex;
    position: relative;
    cursor: pointer;
    margin: 0;
}

.priority-opt input {
    position: absolute;
    opacity: 0;
    pointer-events: none;
    inset: 0;
    width: 100%;
    height: 100%;
    margin: 0;
}

.priority-opt span {
    flex: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    width: 100%;
    min-height: 44px;
    padding: 10px 8px;
    border-radius: 11px;
    background: #fafbfe;
    border: 2px solid var(--border);
    font-size: 0.78rem;
    font-weight: 700;
    color: var(--text-soft);
    transition: all 0.2s ease;
    text-align: center;
    white-space: nowrap;
    box-sizing: border-box;
}

.priority-opt:hover span {
    border-color: #d8e0f0;
    background: #f4f7fe;
    color: var(--text);
}

.priority-opt input:checked + span {
    background: linear-gradient(135deg, #4c8bf5, #2f6bdc);
    border-color: var(--accent);
    color: #fff;
    box-shadow: 0 4px 10px rgba(76, 139, 245, 0.25);
}

.priority-opt[data-priority="low"] input:checked + span {
    background: linear-gradient(135deg, #4ed4a3, #2ebc8a);
    border-color: var(--success);
    box-shadow: 0 4px 10px rgba(46, 188, 138, 0.25);
}

.priority-opt[data-priority="high"] input:checked + span {
    background: linear-gradient(135deg, #ff6b8b, #f54e7a);
    border-color: var(--danger);
    box-shadow: 0 4px 10px rgba(245, 78, 122, 0.25);
}

.priority-opt[data-priority="very_high"] input:checked + span {
    background: linear-gradient(135deg, #b14bff, #7f4cf0);
    border-color: var(--purple);
    box-shadow: 0 4px 10px rgba(127, 76, 240, 0.25);
}

    .btn {
        padding: 11px 22px;
        border-radius: 12px;
        font-weight: 700;
        cursor: pointer;
        font-family: inherit;
        font-size: 0.88rem;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        transition: transform 0.15s ease, box-shadow 0.2s ease, background-color 0.2s ease;
        border: none;
    }
    .btn-primary {
        background: linear-gradient(135deg, #4c8bf5, #2f6bdc);
        color: #fff;
        box-shadow: 0 6px 16px rgba(76, 139, 245, 0.3);
    }
    .btn-primary:hover { transform: translateY(-1px); box-shadow: 0 8px 20px rgba(76, 139, 245, 0.4); }
    .btn-secondary { background: #f1f4fb; color: var(--text); }
    .btn-secondary:hover { background: #e4e9f5; }
    .btn-success { background: linear-gradient(135deg, #4ed4a3, #2ebc8a); color: #fff; }
    .btn-success:hover { box-shadow: 0 6px 16px rgba(46, 188, 138, 0.35); }
    .btn-warning { background: linear-gradient(135deg, #ffc76b, #ff8a3d); color: #fff; }
    .btn-warning:hover { box-shadow: 0 6px 16px rgba(255, 138, 61, 0.35); }
    .btn-danger  { background: linear-gradient(135deg, #ff6b8b, #f54e7a); color: #fff; }
    .btn-danger:hover  { box-shadow: 0 6px 16px rgba(245, 78, 122, 0.35); }

    /* ==== Conversation view ==== */
    .conversation-header {
        background: #fff;
        border: 1px solid var(--border);
        border-radius: 18px;
        padding: 20px 24px;
        margin-bottom: 18px;
        box-shadow: var(--shadow);
    }
    .conversation-top {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 14px;
        flex-wrap: wrap;
    }
    .conversation-title-wrap { flex: 1; min-width: 0; }
    .conversation-title {
        font-size: 1.05rem;
        font-weight: 700;
        color: var(--text);
        line-height: 1.5;
        word-break: break-word;
        margin-bottom: 8px;
    }
    .conversation-meta {
        display: flex;
        align-items: center;
        gap: 8px;
        flex-wrap: wrap;
    }
    .conversation-actions {
        display: flex;
        gap: 6px;
        flex-wrap: wrap;
    }
    .conversation-actions .btn {
        padding: 9px 16px;
        font-size: 0.82rem;
    }

    .back-link {
        display: inline-flex;
        align-items: center;
        gap: 7px;
        color: var(--text-soft);
        text-decoration: none;
        font-size: 0.85rem;
        font-weight: 600;
        margin-bottom: 14px;
        padding: 8px 14px;
        background: #fff;
        border: 1px solid var(--border);
        border-radius: 10px;
        transition: all 0.15s ease;
    }
    .back-link:hover { color: var(--accent); border-color: #c7d7ff; background: #f7faff; }

    .chat-wrap {
        background: #fff;
        border: 1px solid var(--border);
        border-radius: 18px;
        overflow: hidden;
        box-shadow: var(--shadow);
    }
    .chat-body {
        padding: 22px 24px;
        max-height: calc(100vh - 420px);
        min-height: 320px;
        overflow-y: auto;
        display: flex;
        flex-direction: column;
        gap: 16px;
        background: linear-gradient(180deg, #fafbfe 0%, #f7f9fe 100%);
    }

    .chat-msg {
        display: flex;
        gap: 10px;
        max-width: 82%;
    }
    .chat-msg.is-user { align-self: flex-end; flex-direction: row-reverse; }
    .chat-msg.is-admin { align-self: flex-start; }

    .chat-avatar {
        width: 38px; height: 38px;
        border-radius: 50%;
        display: flex; align-items: center; justify-content: center;
        font-size: 0.85rem; font-weight: 700;
        flex-shrink: 0;
        color: #fff;
        overflow: hidden;
    }
    .chat-avatar.user  { background: linear-gradient(135deg, #4c8bf5, #2f6bdc); }
    .chat-avatar.admin { background: linear-gradient(135deg, #a26bfa, #7f4cf0); }
    .chat-avatar img { width: 100%; height: 100%; object-fit: cover; }

    .chat-bubble-wrap { min-width: 0; }
    .chat-meta {
        font-size: 0.7rem;
        color: var(--text-soft);
        margin-bottom: 5px;
        display: flex;
        gap: 8px;
        align-items: center;
        flex-wrap: wrap;
    }
    .chat-msg.is-user .chat-meta { justify-content: flex-end; }
    .chat-author { font-weight: 700; }
    .chat-msg.is-user .chat-author  { color: #2f6bdc; }
    .chat-msg.is-admin .chat-author { color: #7f4cf0; }

    .chat-bubble {
        padding: 12px 16px;
        border-radius: 16px;
        font-size: 0.88rem;
        line-height: 1.9;
        white-space: pre-wrap;
        word-wrap: break-word;
        box-shadow: 0 2px 6px rgba(76, 108, 200, 0.05);
    }
    .chat-msg.is-user .chat-bubble {
        background: linear-gradient(135deg, #4c8bf5, #2f6bdc);
        color: #fff;
        border-top-right-radius: 4px;
    }
    .chat-msg.is-admin .chat-bubble {
        background: #fff;
        color: var(--text);
        border: 1px solid var(--border);
        border-top-left-radius: 4px;
    }

    .chat-reply-area {
        padding: 16px 20px;
        border-top: 1px solid var(--border);
        background: #fff;
    }
    .chat-reply-row {
        display: flex;
        gap: 10px;
        align-items: flex-end;
    }
    .chat-reply-row textarea {
        flex: 1;
        min-height: 48px;
        max-height: 160px;
        padding: 12px 14px;
        border: 2px solid var(--border);
        border-radius: 12px;
        background: #fafbfe;
        font-family: inherit;
        font-size: 0.88rem;
        color: var(--text);
        resize: vertical;
        transition: all 0.2s ease;
    }
    .chat-reply-row textarea:focus {
        outline: none;
        border-color: var(--accent);
        background: #fff;
        box-shadow: 0 0 0 4px rgba(76, 139, 245, 0.12);
    }
    .chat-reply-btn {
        background: linear-gradient(135deg, #4c8bf5, #2f6bdc);
        color: #fff;
        border: none;
        width: 48px; height: 48px;
        border-radius: 12px;
        cursor: pointer;
        display: flex; align-items: center; justify-content: center;
        font-size: 0.95rem;
        transition: transform 0.15s ease, box-shadow 0.2s ease;
        box-shadow: 0 4px 12px rgba(76, 139, 245, 0.25);
        flex-shrink: 0;
    }
    .chat-reply-btn:hover { transform: translateY(-1px); box-shadow: 0 6px 16px rgba(76, 139, 245, 0.35); }

    .closed-banner {
        padding: 16px 20px;
        border-top: 1px solid var(--border);
        background: #fff7d6;
        text-align: center;
        font-size: 0.85rem;
        font-weight: 600;
        color: #92400e;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 10px;
        flex-wrap: wrap;
    }

    /* ==== Toast ==== */
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
        display: flex; align-items: center; gap: 10px;
        box-shadow: 0 12px 32px rgba(20, 30, 60, 0.4);
        z-index: 9999; opacity: 0;
        transition: all 0.35s cubic-bezier(0.34, 1.56, 0.64, 1);
        pointer-events: none;
        max-width: 90vw;
    }
    .app-toast.active { opacity: 1; transform: translateX(-50%) translateY(0); }

    /* ==== Hamburger ==== */
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
        box-shadow: 0 6px 16px rgba(76, 139, 245, 0.25);
    }
    .hamburger-btn .hamburger-lines {
        width: 22px; height: 16px;
        display: flex; flex-direction: column; justify-content: space-between;
    }
    .hamburger-btn .hamburger-lines span {
        display: block; height: 2.5px; width: 100%;
        background: #fff; border-radius: 3px;
        transition: transform 0.3s, opacity 0.2s;
        transform-origin: center;
    }
    .hamburger-btn .hamburger-lines span:nth-child(2) { width: 70%; }
    .hamburger-btn.active .hamburger-lines span:nth-child(1) { transform: translateY(6.75px) rotate(45deg); }
    .hamburger-btn.active .hamburger-lines span:nth-child(2) { opacity: 0; transform: translateX(-10px); }
    .hamburger-btn.active .hamburger-lines span:nth-child(3) { transform: translateY(-6.75px) rotate(-45deg); }

    .sidebar-overlay {
        display: none;
        position: fixed; inset: 0;
        background: rgba(20, 30, 60, 0.5);
        z-index: 998; opacity: 0;
        transition: opacity 0.25s ease;
    }
    .sidebar-overlay.active { display: block; opacity: 1; }

    @media (max-width: 900px) {
        .hamburger-btn { display: flex; }
        .sidebar {
            position: fixed !important;
            top: 0; right: 0; bottom: 0;
            width: 280px; max-width: 85vw;
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
        .chat-body { max-height: calc(100vh - 420px); }
        .chat-msg { max-width: 92%; }
    }

    @media (max-width: 600px) {
        .content-area { padding: 10px 14px 20px; }
        .topbar { padding: 12px 14px 4px; }
        .topbar h1 { font-size: 0.9rem; }
        .topbar-user span:not(.avatar) { display: none; }
        .topbar-user { padding: 4px; }
        .hero-stats { grid-template-columns: 1fr; gap: 10px; }
        .hero-card { min-height: 100px; padding: 16px; }
        .hero-card__value { font-size: 1.6rem; }
        .section-header { flex-direction: column; align-items: stretch; }
        .btn-new { width: 100%; justify-content: center; }
        .tabs-bar { width: 100%; }
        .tab-btn { flex: 1; padding: 8px 8px; font-size: 0.74rem; justify-content: center; }
        .ticket-card { flex-direction: column; align-items: stretch; padding: 14px 18px 14px 20px; }
        .ticket-arrow { display: none; }
        .conversation-header { padding: 16px 18px; }
        .conversation-actions { width: 100%; }
        .conversation-actions .btn { flex: 1; }
        .chat-body { padding: 16px; max-height: calc(100vh - 460px); }
        .chat-msg { max-width: 95%; }
        .chat-reply-area { padding: 14px; }
    }
</style>
</head>
<body>

<?php sidebar(); ?>

<div class="main-wrapper">
    <div class="topbar">
        <button class="hamburger-btn" id="hamburgerBtn" onclick="toggleSidebar()" aria-label="باز کردن منو">
            <div class="hamburger-lines"><span></span><span></span><span></span></div>
        </button>
        <h1>
            پشتیبانی
            <small><?= $ticketId > 0 ? 'مشاهده گفتگو' : 'ارتباط با تیم پشتیبانی' ?></small>
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

        <?php if ($ticketId > 0 && $singleTicket): ?>
            <?php
                $status = (string)$singleTicket['status'];
                $priority = (string)$singleTicket['priority'];
                $isClosed = $status === 'closed';
            ?>

            <a href="index.php" class="back-link">
                <i class="fas fa-arrow-right"></i>
                بازگشت به لیست تیکت‌ها
            </a>

            <div class="conversation-header">
                <div class="conversation-top">
                    <div class="conversation-title-wrap">
                        <div class="ticket-head" style="margin-bottom: 10px;">
                            <span class="ticket-id-badge">#<?= (int)$singleTicket['id'] ?></span>
                            <span class="badge badge-priority-<?= htmlspecialchars($priority, ENT_QUOTES, 'UTF-8') ?>">
                                <i class="fas fa-flag"></i> <?= htmlspecialchars($priorityLabels[$priority] ?? $priority, ENT_QUOTES, 'UTF-8') ?>
                            </span>
                            <span class="badge badge-status-<?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?>">
                                <i class="fas fa-circle-info"></i> <?= htmlspecialchars($statusLabels[$status] ?? $status, ENT_QUOTES, 'UTF-8') ?>
                            </span>
                        </div>
                        <div class="conversation-title"><?= htmlspecialchars($singleTicket['subject'], ENT_QUOTES, 'UTF-8') ?></div>
                        <div class="conversation-meta">
                            <span class="badge badge-date">
                                <i class="fas fa-calendar-plus"></i>
                                ایجاد: <?= htmlspecialchars(formatPersianDate($singleTicket['created_at']), ENT_QUOTES, 'UTF-8') ?>
                            </span>
                            <?php if (!empty($singleTicket['closed_at'])): ?>
                                <span class="badge badge-date">
                                    <i class="fas fa-calendar-check"></i>
                                    بسته شده: <?= htmlspecialchars(formatPersianDate($singleTicket['closed_at']), ENT_QUOTES, 'UTF-8') ?>
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="conversation-actions">
                        <?php if ($isClosed): ?>
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="reopen_ticket" value="<?= (int)$singleTicket['id'] ?>">
                                <button type="submit" class="btn btn-success">
                                    <i class="fas fa-lock-open"></i> بازگشایی تیکت
                                </button>
                            </form>
                        <?php else: ?>
                            <form method="POST" style="display:inline;" onsubmit="return confirm('آیا از بستن این تیکت مطمئن هستید؟');">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="close_ticket" value="<?= (int)$singleTicket['id'] ?>">
                                <button type="submit" class="btn btn-warning">
                                    <i class="fas fa-lock"></i> بستن تیکت
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="chat-wrap">
                <div class="chat-body" id="chatBody">
                    <?php if (empty($messages)): ?>
                        <div style="text-align:center; color: var(--text-soft); padding: 20px;">هیچ پیامی وجود ندارد.</div>
                    <?php else: ?>
                        <?php foreach ($messages as $m):
                            $isAdminMsg = ($m['sender_role'] === 'admin');
                            $senderName = '';
                            if ($isAdminMsg) {
                                $senderName = 'پشتیبانی تسکام';
                            } else {
                                $senderName = trim(($m['first_name'] ?? '') . ' ' . ($m['last_name'] ?? ''));
                                if ($senderName === '') $senderName = $m['mobile'] ?: 'کاربر';
                            }
                            $initial = mb_substr($senderName, 0, 1, 'UTF-8');
                            $avatarUrl = null;
                            if (!$isAdminMsg && !empty($m['avatar'])) {
                                $avBase = basename($m['avatar']);
                                if (preg_match('/^[A-Za-z0-9_.\-]+$/', $avBase) && file_exists(__DIR__ . '/../uploads/avatars/' . $avBase)) {
                                    $avatarUrl = '../uploads/avatars/' . rawurlencode($avBase);
                                }
                            }
                        ?>
                            <div class="chat-msg <?= $isAdminMsg ? 'is-admin' : 'is-user' ?>">
                                <div class="chat-avatar <?= $isAdminMsg ? 'admin' : 'user' ?>">
                                    <?php if ($avatarUrl): ?>
                                        <img src="<?= htmlspecialchars($avatarUrl, ENT_QUOTES, 'UTF-8') ?>" alt="">
                                    <?php elseif ($isAdminMsg): ?>
                                        <i class="fas fa-headset"></i>
                                    <?php else: ?>
                                        <?= htmlspecialchars($initial, ENT_QUOTES, 'UTF-8') ?>
                                    <?php endif; ?>
                                </div>
                                <div class="chat-bubble-wrap">
                                    <div class="chat-meta">
                                        <span class="chat-author"><?= htmlspecialchars($senderName, ENT_QUOTES, 'UTF-8') ?></span>
                                        <span>•</span>
                                        <span><?= htmlspecialchars(formatPersianDate($m['created_at']), ENT_QUOTES, 'UTF-8') ?></span>
                                    </div>
                                    <div class="chat-bubble"><?= htmlspecialchars($m['message'], ENT_QUOTES, 'UTF-8') ?></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <?php if ($isClosed): ?>
                    <div class="closed-banner">
                        <i class="fas fa-lock"></i>
                        این تیکت بسته شده است. برای ادامه گفتگو، آن را بازگشایی کنید.
                    </div>
                <?php else: ?>
                    <div class="chat-reply-area">
                        <form method="POST" id="replyForm">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="reply_ticket" value="<?= (int)$singleTicket['id'] ?>">
                            <div class="chat-reply-row">
                                <textarea name="message" id="replyTextarea"
                                          placeholder="پاسخ خود را بنویسید... (Ctrl+Enter برای ارسال سریع)"
                                          maxlength="5000" required></textarea>
                                <button type="submit" class="chat-reply-btn" title="ارسال">
                                    <i class="fas fa-paper-plane"></i>
                                </button>
                            </div>
                        </form>
                    </div>
                <?php endif; ?>
            </div>

            <script>
                document.addEventListener('DOMContentLoaded', function() {
                    const cb = document.getElementById('chatBody');
                    if (cb) cb.scrollTop = cb.scrollHeight;

                    const ta = document.getElementById('replyTextarea');
                    if (ta) {
                        ta.addEventListener('keydown', function(e) {
                            if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
                                e.preventDefault();
                                document.getElementById('replyForm').submit();
                            }
                        });
                        ta.focus();
                    }
                });
            </script>

        <?php else: ?>

            <!-- ===== آمار ===== -->
            <div class="hero-stats">
                <div class="hero-card card-blue">
                    <div class="hero-card__top">
                        <div class="hero-card__icon"><i class="fas fa-headset"></i></div>
                    </div>
                    <div class="hero-card__bottom">
                        <div class="hero-card__value"><?= $cTotal ?></div>
                        <div class="hero-card__label">کل تیکت‌ها</div>
                    </div>
                </div>
                <div class="hero-card card-orange">
                    <div class="hero-card__top">
                        <div class="hero-card__icon"><i class="fas fa-hourglass-half"></i></div>
                    </div>
                    <div class="hero-card__bottom">
                        <div class="hero-card__value"><?= $cOpen ?></div>
                        <div class="hero-card__label">در انتظار پاسخ</div>
                    </div>
                </div>
                <div class="hero-card card-purple">
                    <div class="hero-card__top">
                        <div class="hero-card__icon"><i class="fas fa-comments"></i></div>
                    </div>
                    <div class="hero-card__bottom">
                        <div class="hero-card__value"><?= $cAnswered ?></div>
                        <div class="hero-card__label">پاسخ داده شده</div>
                    </div>
                </div>
                <div class="hero-card card-green">
                    <div class="hero-card__top">
                        <div class="hero-card__icon"><i class="fas fa-check-double"></i></div>
                    </div>
                    <div class="hero-card__bottom">
                        <div class="hero-card__value"><?= $cClosed ?></div>
                        <div class="hero-card__label">بسته شده</div>
                    </div>
                </div>
            </div>

            <!-- ===== Section Header ===== -->
            <div class="section-header">
                <h2 class="section-title">
                    <i class="fas fa-list"></i>
                    تیکت‌های پشتیبانی من
                </h2>
                <button type="button" class="btn-new" onclick="openCreateModal()">
                    <i class="fas fa-plus"></i>
                    ایجاد تیکت جدید
                </button>
            </div>

            <?php if (empty($tickets)): ?>
                <div class="empty-state">
                    <i class="fas fa-headset"></i>
                    <p>هنوز هیچ تیکتی برای پشتیبانی ثبت نکرده‌اید.</p>
                    <button type="button" class="btn-new" onclick="openCreateModal()">
                        <i class="fas fa-plus"></i>
                        اولین تیکت خود را ایجاد کنید
                    </button>
                </div>
            <?php else: ?>
                <!-- ===== Tabs ===== -->
                <div class="tabs-bar">
                    <button type="button" class="tab-btn active" data-tab="all" onclick="switchTab('all', this)">
                        <i class="fas fa-list-ul"></i> همه
                        <span class="tab-count"><?= $cTotal ?></span>
                    </button>
                    <button type="button" class="tab-btn" data-tab="open" onclick="switchTab('open', this)">
                        <i class="fas fa-hourglass-half"></i> در انتظار
                        <span class="tab-count"><?= $cOpen ?></span>
                    </button>
                    <button type="button" class="tab-btn" data-tab="answered" onclick="switchTab('answered', this)">
                        <i class="fas fa-comments"></i> پاسخ داده
                        <span class="tab-count"><?= $cAnswered ?></span>
                    </button>
                    <button type="button" class="tab-btn" data-tab="closed" onclick="switchTab('closed', this)">
                        <i class="fas fa-check"></i> بسته شده
                        <span class="tab-count"><?= $cClosed ?></span>
                    </button>
                </div>

                <div class="ticket-list" id="ticketList">
                    <?php foreach ($tickets as $t):
                        $tid     = (int)$t['id'];
                        $status  = (string)$t['status'];
                        $priority= (string)$t['priority'];
                        $unread  = (int)$t['unread_count'];
                        $preview = trim((string)($t['last_message'] ?? ''));
                        $preview = preg_replace('/\s+/', ' ', $preview);
                        $preview = mb_substr($preview, 0, 130, 'UTF-8');
                    ?>
                        <a href="index.php?id=<?= $tid ?>"
                           class="ticket-card"
                           data-status="<?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?>">
                            <div class="ticket-bar bar-<?= htmlspecialchars($priority, ENT_QUOTES, 'UTF-8') ?>"></div>

                            <div class="ticket-info">
                                <div class="ticket-head">
                                    <span class="ticket-id-badge">#<?= $tid ?></span>
                                    <span class="ticket-subject"><?= htmlspecialchars($t['subject'], ENT_QUOTES, 'UTF-8') ?></span>
                                    <?php if ($unread > 0): ?>
                                        <span class="badge badge-unread" title="<?= $unread ?> پیام خوانده‌نشده"><?= $unread ?></span>
                                    <?php endif; ?>
                                </div>

                                <?php if ($preview !== ''): ?>
                                    <div class="ticket-preview"><?= htmlspecialchars($preview, ENT_QUOTES, 'UTF-8') ?></div>
                                <?php endif; ?>

                                <div class="ticket-meta">
                                    <span class="badge badge-priority-<?= htmlspecialchars($priority, ENT_QUOTES, 'UTF-8') ?>">
                                        <i class="fas fa-flag"></i>
                                        <?= htmlspecialchars($priorityLabels[$priority] ?? $priority, ENT_QUOTES, 'UTF-8') ?>
                                    </span>
                                    <span class="badge badge-status-<?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?>">
                                        <?php if ($status === 'open'): ?>
                                            <i class="fas fa-hourglass-half"></i>
                                        <?php elseif ($status === 'answered'): ?>
                                            <i class="fas fa-comments"></i>
                                        <?php else: ?>
                                            <i class="fas fa-lock"></i>
                                        <?php endif; ?>
                                        <?= htmlspecialchars($statusLabels[$status] ?? $status, ENT_QUOTES, 'UTF-8') ?>
                                    </span>
                                    <span class="badge badge-date">
                                        <i class="fas fa-clock"></i>
                                        <?= htmlspecialchars(formatPersianDate($t['last_reply_at'] ?: $t['created_at']), ENT_QUOTES, 'UTF-8') ?>
                                    </span>
                                    <span class="badge">
                                        <i class="fas fa-comment-dots"></i>
                                        <?= (int)$t['msg_count'] ?> پیام
                                    </span>
                                </div>
                            </div>

                            <i class="fas fa-chevron-left ticket-arrow"></i>
                        </a>
                    <?php endforeach; ?>
                </div>

                <div class="empty-state" id="emptyFiltered" style="display:none; margin-top: 12px;">
                    <i class="fas fa-filter"></i>
                    <p>تیکتی با این فیلتر یافت نشد.</p>
                </div>
            <?php endif; ?>

        <?php endif; ?>
    </div>
</div>

<!-- ============================================================
     مودال ایجاد تیکت جدید
============================================================ -->
<div id="createSupportModal" class="modal-overlay" onclick="if(event.target === this) closeModal('createSupportModal')">
    <div class="modal-box" onclick="event.stopPropagation()">
        <div class="modal-header">
            <h3>
                <span class="h-icon"><i class="fas fa-plus"></i></span>
                ایجاد تیکت پشتیبانی
            </h3>
            <button type="button" class="modal-close" onclick="closeModal('createSupportModal')">&times;</button>
        </div>

        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="form_token" value="<?= htmlspecialchars($formTokenCreate, ENT_QUOTES, 'UTF-8') ?>">

            <div class="modal-body">
                <div class="form-group">
                    <label><i class="fas fa-heading"></i> عنوان تیکت <span style="color: var(--danger);">*</span></label>
                    <input type="text" name="subject" class="form-control" required maxlength="255"
                           placeholder="موضوع مشکل یا درخواست را وارد کنید...">
                </div>

                <div class="form-group">
                    <label><i class="fas fa-align-right"></i> متن پیام <span style="color: var(--danger);">*</span></label>
                    <textarea name="message" class="form-control" required maxlength="5000"
                              placeholder="شرح کامل مشکل، سوال یا درخواست خود را بنویسید..."></textarea>
                </div>

                <div class="form-group">
                    <label><i class="fas fa-flag"></i> اولویت</label>
                    <div class="priority-group">
                        <label class="priority-opt" data-priority="low">
                            <input type="radio" name="priority" value="low">
                            <span>کم</span>
                        </label>
                        <label class="priority-opt" data-priority="medium">
                            <input type="radio" name="priority" value="medium" checked>
                            <span>متوسط</span>
                        </label>
                        <label class="priority-opt" data-priority="high">
                            <input type="radio" name="priority" value="high">
                            <span>زیاد</span>
                        </label>
                        <label class="priority-opt" data-priority="very_high">
                            <input type="radio" name="priority" value="very_high">
                            <span>خیلی زیاد</span>
                        </label>
                    </div>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('createSupportModal')">
                    <i class="fas fa-times"></i> انصراف
                </button>
                <button type="submit" name="create_ticket" class="btn btn-primary">
                    <i class="fas fa-paper-plane"></i> ثبت تیکت
                </button>
            </div>
        </form>
    </div>
</div>

<div class="app-toast" id="appToast">
    <i class="fas fa-check-circle"></i>
    <span id="appToastText"></span>
</div>

<script>
    /* ============ Modal ============ */
    function openCreateModal() {
        const el = document.getElementById('createSupportModal');
        el.classList.add('active');
        document.body.style.overflow = 'hidden';
        setTimeout(() => {
            const inp = el.querySelector('input[name="subject"]');
            if (inp) inp.focus();
        }, 100);
    }
    function closeModal(id) {
        const el = document.getElementById(id);
        if (!el) return;
        el.classList.remove('active');
        document.body.style.overflow = '';
    }

    /* ============ Tabs ============ */
    function switchTab(tab, btn) {
        document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');

        const cards = document.querySelectorAll('#ticketList .ticket-card');
        let visible = 0;

        cards.forEach(card => {
            const st = card.getAttribute('data-status');
            const show = (tab === 'all') || (st === tab);
            card.style.display = show ? '' : 'none';
            if (show) visible++;
        });

        const emptyEl = document.getElementById('emptyFiltered');
        if (emptyEl) emptyEl.style.display = (visible === 0 && cards.length > 0) ? '' : 'none';
    }

    /* ============ Sidebar (mobile) ============ */
    function toggleSidebar() {
        const sb = document.getElementById('appSidebar');
        const ov = document.getElementById('sidebarOverlay');
        const btn = document.getElementById('hamburgerBtn');
        if (!sb || !ov || !btn) return;
        if (sb.classList.contains('open')) {
            closeSidebar();
        } else {
            sb.classList.add('open');
            ov.classList.add('active');
            btn.classList.add('active');
            document.body.style.overflow = 'hidden';
        }
    }
    function closeSidebar() {
        const sb = document.getElementById('appSidebar');
        const ov = document.getElementById('sidebarOverlay');
        const btn = document.getElementById('hamburgerBtn');
        if (!sb || !ov || !btn) return;
        sb.classList.remove('open');
        ov.classList.remove('active');
        btn.classList.remove('active');
        document.body.style.overflow = '';
    }

    /* ============ Escape ============ */
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape') {
            const m = document.getElementById('createSupportModal');
            if (m && m.classList.contains('active')) {
                closeModal('createSupportModal');
                return;
            }
            closeSidebar();
        }
    });

    /* ============ Ctrl+S in create modal ============ */
    document.addEventListener('keydown', e => {
        const isSave = (e.ctrlKey || e.metaKey) && !e.shiftKey && !e.altKey && (e.key === 's' || e.key === 'S');
        if (!isSave) return;
        const m = document.getElementById('createSupportModal');
        if (m && m.classList.contains('active')) {
            e.preventDefault();
            const sb = m.querySelector('button[name="create_ticket"]');
            if (sb) sb.click();
        }
    }, true);
</script>
</body>
</html>