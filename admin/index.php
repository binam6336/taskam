<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../database/Database.php';

$db = Database::getInstance();

try {
    $db->exec("SET NAMES 'utf8mb4'");
    $db->exec("SET CHARACTER SET utf8mb4");
    $db->exec("SET character_set_connection = utf8mb4");
} catch (PDOException $e) {}

// ============ Impersonate exit ============
if (isset($_GET['exit_impersonate']) && isset($_SESSION['impersonator'])) {
    $imp = $_SESSION['impersonator'];
    $_SESSION['user_id'] = $imp['user_id'];
    $_SESSION['user_mobile'] = $imp['user_mobile'];
    $_SESSION['user_role'] = $imp['user_role'];
    $_SESSION['user_status'] = $imp['user_status'];
    unset($_SESSION['impersonator'], $_SESSION['impersonated_user_name']);
    header('Location: index.php');
    exit;
}

// ============ Admin Auth ============
if (!Auth::check() || ($_SESSION['user_role'] ?? '') !== 'admin') {
    header('Location: ../auth/login.php');
    exit;
}

// ============ Ensure admin_permissions column ============
try {
    $colInfo = $db->query("SHOW COLUMNS FROM users LIKE 'admin_permissions'")->fetch(PDO::FETCH_ASSOC);
    if (!$colInfo) {
        $db->exec("ALTER TABLE users ADD COLUMN admin_permissions TEXT DEFAULT NULL AFTER role");
    }
} catch (PDOException $e) {}

$currentUserId = (int)$_SESSION['user_id'];
$msg = '';
$msgType = 'success';

// ============ Section determination ============
$section = $_GET['section'] ?? 'users';
if (!in_array($section, ['users', 'tickets'], true)) $section = 'users';

$ticketId = ($section === 'tickets' && isset($_GET['id'])) ? (int)$_GET['id'] : 0;

// ============ Persian Date Helpers ============
function gregorianToJalali($gy, $gm, $gd) {
    $g_d_m = [0,31,59,90,120,151,181,212,243,273,304,334];
    $gy2 = ($gm > 2) ? ($gy + 1) : $gy;
    $days = 355666 + (365 * $gy) + ((int)(($gy2 + 3) / 4)) - ((int)(($gy2 + 99) / 100))
          + ((int)(($gy2 + 399) / 400)) + $gd + $g_d_m[$gm - 1];
    $jy = -1595 + (33 * ((int)($days / 12053)));
    $days %= 12053;
    $jy += 4 * ((int)($days / 1461));
    $days %= 1461;
    if ($days > 365) { $jy += (int)(($days - 1) / 365); $days = ($days - 1) % 365; }
    if ($days < 186) { $jm = 1 + (int)($days / 31); $jd = 1 + ($days % 31); }
    else { $jm = 7 + (int)(($days - 186) / 30); $jd = 1 + (($days - 186) % 30); }
    return [$jy, $jm, $jd];
}

function formatPersianDate($datetime) {
    if (empty($datetime)) return '';
    $ts = strtotime($datetime);
    if (!$ts) return (string)$datetime;
    list($jy, $jm, $jd) = gregorianToJalali((int)date('Y', $ts), (int)date('n', $ts), (int)date('j', $ts));
    $months = ['فروردین','اردیبهشت','خرداد','تیر','مرداد','شهریور','مهر','آبان','آذر','دی','بهمن','اسفند'];
    $time = date('H:i', $ts);
    $idx = max(0, min(11, $jm - 1));
    return $jd . ' ' . $months[$idx] . ' ' . $jy . ' - ' . $time;
}

function timeAgoPersian($datetime) {
    if (empty($datetime)) return '—';
    $ts = strtotime($datetime);
    if (!$ts) return '—';
    $now = time();
    $diff = $now - $ts;
    if ($diff < 0) {
        $absDiff = abs($diff);
        if ($absDiff < 300)    return 'لحظاتی پیش';
        if ($absDiff < 86400)  return 'امروز';
        if ($absDiff < 172800) return 'دیروز';
        return formatPersianDate($datetime);
    }
    if ($diff < 60)      return 'لحظاتی پیش';
    if ($diff < 3600)    return floor($diff / 60) . ' دقیقه پیش';
    if ($diff < 86400)   return floor($diff / 3600) . ' ساعت پیش';
    if ($diff < 172800)  return 'دیروز';
    if ($diff < 604800)  return floor($diff / 86400) . ' روز پیش';
    if ($diff < 2592000) return floor($diff / 604800) . ' هفته پیش';
    return formatPersianDate($datetime);
}

// ============ CSRF ============
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

function verifyCsrf() {
    return isset($_POST['csrf_token'], $_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], (string)$_POST['csrf_token']);
}

// ============ Impersonate (Login as user) ============
if (isset($_GET['impersonate'])) {
    $targetId = (int)$_GET['impersonate'];

    if ($targetId === $currentUserId) {
        $msg = 'نمی‌توانید به پنل خودتان وارد شوید.';
        $msgType = 'error';
    } else {
        $targetStmt = $db->prepare("SELECT * FROM users WHERE id = ? AND status = 'active'");
        $targetStmt->execute([$targetId]);
        $targetUser = $targetStmt->fetch(PDO::FETCH_ASSOC);

        if (!$targetUser) {
            $msg = 'کاربر مورد نظر یافت نشد یا فعال نیست.';
            $msgType = 'error';
        } else {
            $adminStmt = $db->prepare("SELECT * FROM users WHERE id = ?");
            $adminStmt->execute([$currentUserId]);
            $adminUser = $adminStmt->fetch(PDO::FETCH_ASSOC);

            $_SESSION['impersonator'] = [
                'user_id' => $currentUserId,
                'user_mobile' => $adminUser['mobile'],
                'user_role' => $adminUser['role'],
                'user_status' => $adminUser['status'],
                'first_name' => $adminUser['first_name'],
                'last_name' => $adminUser['last_name'],
                'impersonated_at' => time(),
            ];

            $_SESSION['user_id'] = (int)$targetUser['id'];
            $_SESSION['user_mobile'] = $targetUser['mobile'];
            $_SESSION['user_role'] = $targetUser['role'];
            $_SESSION['user_status'] = $targetUser['status'];
            $_SESSION['impersonated_user_name'] = trim(($targetUser['first_name'] ?? '') . ' ' . ($targetUser['last_name'] ?? ''));

            header('Location: ../tasks/index.php?page=dashboard');
            exit;
        }
    }
}

// ============ Permissions ============
$ALL_ADMIN_PERMISSIONS = [
    'add_user'      => ['label' => 'افزودن کاربر',        'icon' => 'fa-user-plus',      'color' => '#34d399', 'desc' => 'توانایی ایجاد کاربر جدید'],
    'delete_user'   => ['label' => 'حذف کاربر',           'icon' => 'fa-user-minus',     'color' => '#f87171', 'desc' => 'توانایی حذف کامل کاربر'],
    'toggle_status' => ['label' => 'تایید / غیرفعال‌سازی', 'icon' => 'fa-toggle-on',      'color' => '#fbbf24', 'desc' => 'فعال/غیرفعال/مسدود کردن کاربر'],
    'edit_user'     => ['label' => 'ویرایش کاربر',        'icon' => 'fa-user-pen',       'color' => '#a855f7', 'desc' => 'ویرایش اطلاعات و دسترسی‌های کاربر'],
];

$currentUserStmt = $db->prepare("SELECT * FROM users WHERE id = ?");
$currentUserStmt->execute([$currentUserId]);
$currentUser = $currentUserStmt->fetch(PDO::FETCH_ASSOC);

$firstAdminStmt = $db->query("SELECT id FROM users WHERE role = 'admin' ORDER BY id ASC LIMIT 1");
$firstAdminId = (int)$firstAdminStmt->fetchColumn();
$isSuperAdmin = ($currentUserId === $firstAdminId);

$currentPermissions = [];
if ($isSuperAdmin) {
    $currentPermissions = array_keys($ALL_ADMIN_PERMISSIONS);
} else {
    $perms = json_decode($currentUser['admin_permissions'] ?? '[]', true);
    if (!is_array($perms)) $perms = [];
    $currentPermissions = $perms;
}

function hasAdminPermission($permission) {
    global $currentPermissions;
    return in_array($permission, $currentPermissions, true);
}

// ============================================================
// ============ USER MANAGEMENT HANDLERS =====================
// ============================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_user'])) {
    if (!hasAdminPermission('add_user')) {
        $msg = 'شما دسترسی افزودن کاربر را ندارید.'; $msgType = 'error';
    } else {
        $firstName = trim($_POST['first_name'] ?? '');
        $lastName  = trim($_POST['last_name'] ?? '');
        $mobile    = trim($_POST['mobile'] ?? '');
        $email     = trim($_POST['email'] ?? '');
        $password  = $_POST['password'] ?? '';
        $role      = $_POST['role'] ?? 'user';
        $status    = $_POST['status'] ?? 'active';
        $adminPerms = $_POST['admin_permissions'] ?? [];

        if (empty($firstName) || empty($lastName) || empty($mobile) || empty($password)) {
            $msg = 'لطفاً همه فیلدهای ستاره‌دار را تکمیل کنید.'; $msgType = 'error';
        } elseif (!preg_match('/^09\d{9}$/', $mobile)) {
            $msg = 'شماره موبایل باید با 09 شروع شده و 11 رقم باشد.'; $msgType = 'error';
        } elseif (strlen($password) < 6) {
            $msg = 'رمز عبور باید حداقل ۶ کاراکتر باشد.'; $msgType = 'error';
        } elseif (!in_array($role, ['admin', 'user'])) {
            $msg = 'نقش انتخاب‌شده معتبر نیست.'; $msgType = 'error';
        } elseif (!in_array($status, ['active', 'pending', 'deactivated', 'blocked'])) {
            $msg = 'وضعیت انتخاب‌شده معتبر نیست.'; $msgType = 'error';
        } else {
            $check = $db->prepare("SELECT id FROM users WHERE mobile = ?");
            $check->execute([$mobile]);
            if ($check->fetch()) {
                $msg = 'این شماره موبایل قبلاً ثبت شده است.'; $msgType = 'error';
            } else {
                $validPerms = [];
                if ($role === 'admin' && is_array($adminPerms)) {
                    foreach ($adminPerms as $p) {
                        if (isset($ALL_ADMIN_PERMISSIONS[$p]) && hasAdminPermission($p)) $validPerms[] = $p;
                    }
                }
                $hashed = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $db->prepare("
                    INSERT INTO users (mobile, first_name, last_name, email, password, status, role, admin_permissions)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $mobile, $firstName, $lastName, $email ?: null, $hashed, $status, $role,
                    $role === 'admin' ? json_encode($validPerms, JSON_UNESCAPED_UNICODE) : null
                ]);
                $msg = 'کاربر جدید با موفقیت ایجاد شد.';
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_user'])) {
    if (!hasAdminPermission('edit_user')) {
        $msg = 'شما دسترسی ویرایش کاربر را ندارید.'; $msgType = 'error';
    } else {
        $targetId = (int)($_POST['user_id'] ?? 0);
        $firstName = trim($_POST['first_name'] ?? '');
        $lastName  = trim($_POST['last_name'] ?? '');
        $mobile    = trim($_POST['mobile'] ?? '');
        $email     = trim($_POST['email'] ?? '');
        $password  = $_POST['password'] ?? '';
        $role      = $_POST['role'] ?? 'user';
        $status    = $_POST['status'] ?? 'active';
        $adminPerms = $_POST['admin_permissions'] ?? [];

        $targetStmt = $db->prepare("SELECT * FROM users WHERE id = ?");
        $targetStmt->execute([$targetId]);
        $targetUser = $targetStmt->fetch(PDO::FETCH_ASSOC);

        if (!$targetUser) {
            $msg = 'کاربر مورد نظر یافت نشد.'; $msgType = 'error';
        } elseif ((int)$targetUser['id'] === $firstAdminId && !$isSuperAdmin) {
            $msg = 'شما دسترسی ویرایش مدیر کل را ندارید.'; $msgType = 'error';
        } elseif (empty($firstName) || empty($lastName) || empty($mobile)) {
            $msg = 'لطفاً همه فیلدهای ستاره‌دار را تکمیل کنید.'; $msgType = 'error';
        } elseif (!preg_match('/^09\d{9}$/', $mobile)) {
            $msg = 'شماره موبایل باید با 09 شروع شده و 11 رقم باشد.'; $msgType = 'error';
        } elseif (!in_array($role, ['admin', 'user'])) {
            $msg = 'نقش انتخاب‌شده معتبر نیست.'; $msgType = 'error';
        } elseif (!in_array($status, ['active', 'pending', 'deactivated', 'blocked'])) {
            $msg = 'وضعیت انتخاب‌شده معتبر نیست.'; $msgType = 'error';
        } else {
            $check = $db->prepare("SELECT id FROM users WHERE mobile = ? AND id != ?");
            $check->execute([$mobile, $targetId]);
            if ($check->fetch()) {
                $msg = 'این شماره موبایل برای کاربر دیگری ثبت شده است.'; $msgType = 'error';
            } else {
                $validPerms = [];
                if ($role === 'admin' && is_array($adminPerms)) {
                    foreach ($adminPerms as $p) {
                        if (isset($ALL_ADMIN_PERMISSIONS[$p]) && hasAdminPermission($p)) $validPerms[] = $p;
                    }
                }
                $updatePassword = false; $hashed = null;
                if (!empty($password)) {
                    if (strlen($password) < 6) { $msg = 'رمز عبور باید حداقل ۶ کاراکتر باشد.'; $msgType = 'error'; }
                    else { $hashed = password_hash($password, PASSWORD_DEFAULT); $updatePassword = true; }
                }
                if ($msgType !== 'error') {
                    if ($targetId === $firstAdminId && $role !== 'admin') $role = 'admin';

                    if ($updatePassword) {
                        $stmt = $db->prepare("UPDATE users SET first_name=?, last_name=?, mobile=?, email=?, password=?, role=?, status=?, admin_permissions=? WHERE id=?");
                        $stmt->execute([$firstName, $lastName, $mobile, $email ?: null, $hashed, $role, $status, $role === 'admin' ? json_encode($validPerms, JSON_UNESCAPED_UNICODE) : null, $targetId]);
                    } else {
                        $stmt = $db->prepare("UPDATE users SET first_name=?, last_name=?, mobile=?, email=?, role=?, status=?, admin_permissions=? WHERE id=?");
                        $stmt->execute([$firstName, $lastName, $mobile, $email ?: null, $role, $status, $role === 'admin' ? json_encode($validPerms, JSON_UNESCAPED_UNICODE) : null, $targetId]);
                    }
                    $msg = 'اطلاعات کاربر با موفقیت بروزرسانی شد.';
                }
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_status'], $_POST['user_id'])) {
    if (!hasAdminPermission('toggle_status')) {
        $msg = 'شما دسترسی تغییر وضعیت کاربر را ندارید.'; $msgType = 'error';
    } else {
        $targetId = (int)$_POST['user_id'];
        $newStatus = $_POST['action_status'];
        if (in_array($newStatus, ['active', 'pending', 'deactivated', 'blocked'])) {
            if ($targetId === $firstAdminId) {
                $msg = 'امکان تغییر وضعیت مدیر کل وجود ندارد.'; $msgType = 'error';
            } else {
                $stmt = $db->prepare("UPDATE users SET status = ? WHERE id = ? AND role != 'admin'");
                $stmt->execute([$newStatus, $targetId]);
                $msg = 'وضعیت کاربر با موفقیت بروزرسانی شد.';
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_delete'], $_POST['user_id'])) {
    if (!hasAdminPermission('delete_user')) {
        $msg = 'شما دسترسی حذف کاربر را ندارید.'; $msgType = 'error';
    } else {
        $targetId = (int)$_POST['user_id'];
        if ($targetId === $firstAdminId) {
            $msg = 'امکان حذف مدیر کل وجود ندارد.'; $msgType = 'error';
        } else {
            $stmt = $db->prepare("DELETE FROM users WHERE id = ? AND role != 'admin'");
            $stmt->execute([$targetId]);
            $msg = 'کاربر با موفقیت حذف شد.';
        }
    }
}

// ============================================================
// ============ SUPPORT TICKET HANDLERS =======================
// ============================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reply_ticket'])) {
    if (!verifyCsrf()) {
        $msg = 'درخواست نامعتبر است.'; $msgType = 'error';
    } else {
        $tid = (int)$_POST['reply_ticket'];
        $msgText = trim((string)($_POST['message'] ?? ''));

        if ($msgText === '') {
            $msg = 'متن پاسخ الزامی است.'; $msgType = 'error';
        } elseif (mb_strlen($msgText) > 5000) {
            $msg = 'متن پاسخ بسیار طولانی است.'; $msgType = 'error';
        } else {
            try {
                $chk = $db->prepare("SELECT id FROM support_tickets WHERE id = ? LIMIT 1");
                $chk->execute([$tid]);
                if (!$chk->fetchColumn()) {
                    $msg = 'تیکت یافت نشد.'; $msgType = 'error';
                } else {
                    $ins = $db->prepare("INSERT INTO support_messages (ticket_id, sender_id, sender_role, message, is_read_by_user, is_read_by_admin) VALUES (?, ?, 'admin', ?, 0, 1)");
                    $ins->execute([$tid, $currentUserId, $msgText]);
                    $db->prepare("UPDATE support_tickets SET status = 'answered', last_reply_at = NOW() WHERE id = ?")->execute([$tid]);

                    while (ob_get_level() > 0) ob_end_clean();
                    header('Location: index.php?section=tickets&id=' . $tid);
                    exit;
                }
            } catch (PDOException $e) {
                error_log('admin reply error: ' . $e->getMessage());
                $msg = 'خطا در ارسال پاسخ.'; $msgType = 'error';
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['close_ticket'])) {
    $tid = (int)$_POST['close_ticket'];
    if (verifyCsrf()) {
        try {
            $db->prepare("UPDATE support_tickets SET status = 'closed', closed_at = NOW() WHERE id = ?")->execute([$tid]);
            while (ob_get_level() > 0) ob_end_clean();
            header('Location: index.php?section=tickets&id=' . $tid);
            exit;
        } catch (PDOException $e) {}
    }
    header('Location: index.php?section=tickets');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reopen_ticket'])) {
    $tid = (int)$_POST['reopen_ticket'];
    if (verifyCsrf()) {
        try {
            $db->prepare("UPDATE support_tickets SET status = 'open', closed_at = NULL WHERE id = ?")->execute([$tid]);
            while (ob_get_level() > 0) ob_end_clean();
            header('Location: index.php?section=tickets&id=' . $tid);
            exit;
        } catch (PDOException $e) {}
    }
    header('Location: index.php?section=tickets');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_ticket'])) {
    $tid = (int)$_POST['delete_ticket'];
    if (verifyCsrf()) {
        try { $db->prepare("DELETE FROM support_tickets WHERE id = ?")->execute([$tid]); } catch (PDOException $e) {}
    }
    while (ob_get_level() > 0) ob_end_clean();
    header('Location: index.php?section=tickets');
    exit;
}

// ============================================================
// ============ DATA LOADING ==================================
// ============================================================

$displayUser = $_SESSION['user_mobile'] ?? $_SESSION['mobile'] ?? $_SESSION['username'] ?? 'ادمین';

// ---------- Users ----------
$users = [];
$totalUsers = $activeUsers = $pendingUsers = $blockedUsers = $adminUsers = 0;
try {
    $stmt = $db->query("SELECT * FROM users ORDER BY (role = 'admin') DESC, created_at DESC");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $totalUsers   = count($users);
    $activeUsers  = count(array_filter($users, fn($u) => $u['status'] === 'active'));
    $pendingUsers = count(array_filter($users, fn($u) => $u['status'] === 'pending'));
    $blockedUsers = count(array_filter($users, fn($u) => $u['status'] === 'blocked'));
    $adminUsers   = count(array_filter($users, fn($u) => $u['role'] === 'admin'));
} catch (PDOException $e) {
    error_log('users load: ' . $e->getMessage());
}

$usersJson = json_encode($users, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
$permissionsJson = json_encode($ALL_ADMIN_PERMISSIONS, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
$currentPermissionsJson = json_encode($currentPermissions, JSON_UNESCAPED_UNICODE);

// ---------- Tickets ----------
$priorityLabels = ['low'=>'کم','medium'=>'متوسط','high'=>'زیاد','very_high'=>'خیلی زیاد'];
$statusLabels   = ['open'=>'در انتظار پاسخ','answered'=>'پاسخ داده شده','closed'=>'بسته شده'];

$ticketStats = ['total'=>0, 'open'=>0, 'answered'=>0, 'closed'=>0, 'unread'=>0];
try {
    $row = $db->query("
        SELECT
            COUNT(*) AS total,
            COALESCE(SUM(CASE WHEN status='open' THEN 1 ELSE 0 END),0) AS open_count,
            COALESCE(SUM(CASE WHEN status='answered' THEN 1 ELSE 0 END),0) AS answered_count,
            COALESCE(SUM(CASE WHEN status='closed' THEN 1 ELSE 0 END),0) AS closed_count
        FROM support_tickets
    ")->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $ticketStats['total']    = (int)$row['total'];
        $ticketStats['open']     = (int)$row['open_count'];
        $ticketStats['answered'] = (int)$row['answered_count'];
        $ticketStats['closed']   = (int)$row['closed_count'];
    }
    $ticketStats['unread'] = (int)$db->query("SELECT COUNT(*) FROM support_messages WHERE sender_role='user' AND is_read_by_admin=0")->fetchColumn();
} catch (PDOException $e) {
    error_log('ticket stats: ' . $e->getMessage());
}

$tickets = [];
try {
    $tStmt = $db->query("
        SELECT st.*,
               u.mobile, u.first_name, u.last_name,
               (SELECT COUNT(*) FROM support_messages sm WHERE sm.ticket_id = st.id) AS msg_count,
               (SELECT COUNT(*) FROM support_messages sm WHERE sm.ticket_id = st.id AND sm.sender_role='user' AND sm.is_read_by_admin=0) AS unread_count
        FROM support_tickets st
        LEFT JOIN users u ON u.id = st.user_id
        ORDER BY COALESCE(st.last_reply_at, st.created_at) DESC
    ");
    $tickets = $tStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('tickets list: ' . $e->getMessage());
}

// ---------- Single ticket for modal ----------
$singleTicket = null;
$messages = [];
$previousTickets = [];
$userTicketStats = ['total'=>0, 'open'=>0, 'closed'=>0];

if ($ticketId > 0) {
    try {
        $stmt = $db->prepare("
            SELECT st.*, u.mobile, u.email, u.first_name, u.last_name, u.avatar
            FROM support_tickets st
            LEFT JOIN users u ON u.id = st.user_id
            WHERE st.id = ? LIMIT 1
        ");
        $stmt->execute([$ticketId]);
        $singleTicket = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($singleTicket) {
            $singleUserId = (int)$singleTicket['user_id'];

            $db->prepare("UPDATE support_messages SET is_read_by_admin = 1 WHERE ticket_id = ? AND sender_role = 'user' AND is_read_by_admin = 0")->execute([$ticketId]);

            $mStmt = $db->prepare("
                SELECT sm.*, u.first_name, u.last_name, u.mobile, u.avatar
                FROM support_messages sm
                LEFT JOIN users u ON u.id = sm.sender_id
                WHERE sm.ticket_id = ?
                ORDER BY sm.created_at ASC, sm.id ASC
            ");
            $mStmt->execute([$ticketId]);
            $messages = $mStmt->fetchAll(PDO::FETCH_ASSOC);

            $pStmt = $db->prepare("
                SELECT id, subject, status, priority, created_at, last_reply_at,
                       (SELECT COUNT(*) FROM support_messages sm WHERE sm.ticket_id = support_tickets.id) AS msg_count
                FROM support_tickets
                WHERE user_id = ? AND id != ?
                ORDER BY COALESCE(last_reply_at, created_at) DESC LIMIT 20
            ");
            $pStmt->execute([$singleUserId, $ticketId]);
            $previousTickets = $pStmt->fetchAll(PDO::FETCH_ASSOC);

            $sStmt = $db->prepare("
                SELECT
                    COUNT(*) AS total,
                    COALESCE(SUM(CASE WHEN status IN ('open','answered') THEN 1 ELSE 0 END),0) AS open_count,
                    COALESCE(SUM(CASE WHEN status='closed' THEN 1 ELSE 0 END),0) AS closed_count
                FROM support_tickets WHERE user_id = ?
            ");
            $sStmt->execute([$singleUserId]);
            $sRow = $sStmt->fetch(PDO::FETCH_ASSOC);
            if ($sRow) {
                $userTicketStats['total']  = (int)$sRow['total'];
                $userTicketStats['open']   = (int)$sRow['open_count'];
                $userTicketStats['closed'] = (int)$sRow['closed_count'];
            }
        }
    } catch (PDOException $e) {
        error_log('single ticket: ' . $e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <title>پنل مدیریت — Admin Console</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="icon" href="https://img.icons8.com/color/48/dashboard-layout.png" type="image/png">
    <style>
        :root {
            /* ===== Dark Purple Theme ===== */
            --bg: #0a0714;
            --bg-2: #0f0b1d;
            --surface: #161127;
            --surface-2: #1c1631;
            --surface-3: #241c3d;

            --border: rgba(168, 85, 247, 0.10);
            --border-2: rgba(168, 85, 247, 0.22);
            --border-3: rgba(168, 85, 247, 0.35);

            --text: #f5f3ff;
            --text-2: #b8aede;
            --text-3: #6b6288;

            --violet: #a855f7;
            --violet-2: #8b5cf6;
            --violet-3: #c084fc;
            --pink: #ec4899;
            --pink-2: #f472b6;
            --teal: #2dd4bf;
            --green: #34d399;
            --amber: #fbbf24;
            --red: #f87171;
            --blue: #60a5fa;

            --grad-1: linear-gradient(135deg, #a855f7 0%, #ec4899 100%);
            --grad-2: linear-gradient(135deg, #8b5cf6 0%, #ec4899 100%);
            --grad-3: linear-gradient(135deg, #a78bfa 0%, #f472b6 100%);

            --radius: 18px;
            --radius-lg: 24px;
            --radius-sm: 12px;

            --shadow: 0 12px 32px -12px rgba(0,0,0,0.7);
            --glow-violet: 0 0 30px -8px rgba(168, 85, 247, 0.55);
            --glow-pink: 0 0 30px -8px rgba(236, 72, 153, 0.5);

            --t: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }
        html, body { max-width: 100%; overflow-x: hidden; }

        body {
            background:
                radial-gradient(ellipse 80% 50% at 50% -10%, rgba(168, 85, 247, 0.12), transparent 60%),
                radial-gradient(ellipse 60% 40% at 100% 100%, rgba(236, 72, 153, 0.06), transparent 60%),
                var(--bg);
            color: var(--text);
            font-family: Tahoma, "Segoe UI", sans-serif;
            display: flex;
            min-height: 100vh;
            direction: rtl;
            font-size: 14px;
        }

        /* ============ ICON SIDEBAR ============ */
        .sidebar {
            width: 76px;
            background: var(--bg-2);
            display: flex; flex-direction: column;
            align-items: center;
            padding: 20px 0;
            gap: 6px;
            border-left: 1px solid var(--border);
            flex-shrink: 0;
            z-index: 5;
        }

        .sidebar__logo {
            width: 44px; height: 44px;
            border-radius: 14px;
            background: var(--grad-2);
            display: flex; align-items: center; justify-content: center;
            font-size: 1rem; color: #fff;
            box-shadow: var(--glow-violet);
            margin-bottom: 22px;
        }

        .sidebar__nav {
            display: flex; flex-direction: column;
            gap: 8px;
            flex: 1;
        }

        .nav-icon {
            width: 46px; height: 46px;
            border-radius: 14px;
            display: flex; align-items: center; justify-content: center;
            color: var(--text-3);
            font-size: 1.05rem;
            text-decoration: none;
            transition: var(--t);
            position: relative;
        }
        .nav-icon:hover {
            background: var(--surface);
            color: var(--violet-3);
        }
        .nav-icon.active {
            background: var(--grad-2);
            color: #fff;
            box-shadow: 0 8px 20px -6px rgba(168, 85, 247, 0.6);
        }
        .nav-icon.active::after {
            content: '';
            position: absolute;
            inset: -1px;
            border-radius: 14px;
            background: linear-gradient(135deg, rgba(255,255,255,0.2), transparent);
            pointer-events: none;
        }
        .nav-icon.logout { color: var(--red); }
        .nav-icon.logout:hover { background: rgba(248, 113, 113, 0.1); }

        /* ============ MAIN ============ */
        .main {
            flex: 1;
            display: flex;
            flex-direction: column;
            min-width: 0;
            overflow: hidden;
        }

        /* ============ TOPBAR ============ */
        .topbar {
            padding: 22px 32px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            flex-shrink: 0;
        }

        .topbar__title { display: flex; flex-direction: column; gap: 4px; }
        .topbar__title h1 {
            font-size: 1.35rem; font-weight: bold; color: #fff;
            letter-spacing: -0.3px;
        }
        .topbar__title span { font-size: 0.75rem; color: var(--text-3); }

        .topbar__actions { display: flex; align-items: center; gap: 12px; }

        .search-box {
            display: flex; align-items: center;
            gap: 10px;
            background: var(--surface);
            border: 1px solid var(--border);
            padding: 10px 16px;
            border-radius: 30px;
            min-width: 240px;
            transition: var(--t);
        }
        .search-box:focus-within {
            border-color: var(--border-2);
            box-shadow: 0 0 0 4px rgba(168, 85, 247, 0.08);
        }
        .search-box i { color: var(--text-3); font-size: 0.85rem; }
        .search-box input {
            background: transparent; border: none; outline: none;
            color: var(--text); font-family: inherit; font-size: 0.82rem;
            flex: 1; min-width: 0;
        }
        .search-box input::placeholder { color: var(--text-3); }

        .icon-btn {
            width: 42px; height: 42px;
            border-radius: 12px;
            background: var(--surface);
            border: 1px solid var(--border);
            color: var(--text-2);
            display: flex; align-items: center; justify-content: center;
            cursor: pointer;
            transition: var(--t);
            font-size: 0.9rem;
            text-decoration: none;
        }
        .icon-btn:hover { color: var(--violet-3); border-color: var(--border-2); }

        .avatar-btn {
            width: 42px; height: 42px;
            border-radius: 12px;
            background: var(--grad-2);
            display: flex; align-items: center; justify-content: center;
            color: #fff; font-weight: bold; font-size: 0.9rem;
            border: none; cursor: pointer;
            box-shadow: 0 6px 18px -6px rgba(168, 85, 247, 0.7);
            transition: var(--t);
            position: relative;
        }
        .avatar-btn::after {
            content: ''; position: absolute; bottom: -1px; left: -1px;
            width: 12px; height: 12px;
            background: var(--green);
            border: 2px solid var(--bg);
            border-radius: 50%;
        }
        .avatar-btn:hover { transform: translateY(-2px); }

        /* ============ CONTENT ============ */
        .content {
            flex: 1;
            padding: 0 32px 32px;
            overflow-y: auto;
        }
        .content::-webkit-scrollbar { width: 6px; }
        .content::-webkit-scrollbar-thumb { background: var(--surface-3); border-radius: 3px; }

        /* ============ ALERT ============ */
        .alert {
            padding: 14px 18px;
            border-radius: var(--radius-sm);
            margin-bottom: 20px;
            font-weight: 600;
            font-size: 0.85rem;
            display: flex; align-items: center; gap: 10px;
            animation: alertIn 0.4s ease;
        }
        @keyframes alertIn { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: translateY(0); } }
        .alert-success { background: rgba(52, 211, 153, 0.12); color: #6ee7b7; border: 1px solid rgba(52, 211, 153, 0.3); }
        .alert-error { background: rgba(248, 113, 113, 0.12); color: #fca5a5; border: 1px solid rgba(248, 113, 113, 0.3); }

        /* ============ STATS ============ */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }

        .stat-card {
            background: var(--surface);
            border: 1px solid var(--border);
            padding: 20px;
            border-radius: var(--radius-lg);
            display: flex; align-items: center; justify-content: space-between;
            transition: var(--t);
            position: relative;
            overflow: hidden;
        }
        .stat-card::before {
            content: '';
            position: absolute; top: 0; right: 0;
            width: 3px; height: 100%;
            background: var(--violet);
        }
        .stat-card.success::before { background: var(--green); }
        .stat-card.warning::before { background: var(--amber); }
        .stat-card.danger::before  { background: var(--red); }
        .stat-card.info::before    { background: var(--blue); }
        .stat-card.purple::before  { background: var(--violet-3); }
        .stat-card:hover { border-color: var(--border-2); transform: translateY(-2px); }

        .stat-card__label {
            font-size: 0.7rem; color: var(--text-3);
            text-transform: uppercase; letter-spacing: 0.6px;
            font-weight: bold;
        }
        .stat-card__value {
            font-size: 1.9rem; font-weight: bold; color: #fff;
            line-height: 1; margin-top: 6px;
            letter-spacing: -1px;
        }
        .stat-card__icon {
            width: 48px; height: 48px;
            border-radius: 14px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.1rem;
            background: rgba(168, 85, 247, 0.12);
            color: var(--violet-3);
            flex-shrink: 0;
        }
        .stat-card.success .stat-card__icon { background: rgba(52, 211, 153, 0.12); color: var(--green); }
        .stat-card.warning .stat-card__icon { background: rgba(251, 191, 36, 0.12); color: var(--amber); }
        .stat-card.danger  .stat-card__icon { background: rgba(248, 113, 113, 0.12); color: var(--red); }
        .stat-card.info    .stat-card__icon { background: rgba(96, 165, 250, 0.12); color: var(--blue); }
        .stat-card.purple  .stat-card__icon { background: rgba(192, 132, 252, 0.15); color: var(--violet-3); }

        /* ============ PANEL ============ */
        .panel {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            overflow: hidden;
            margin-bottom: 24px;
        }

        .panel__header {
            padding: 20px 24px;
            border-bottom: 1px solid var(--border);
            display: flex; align-items: center; justify-content: space-between;
            flex-wrap: wrap; gap: 12px;
        }
        .panel__title {
            font-size: 0.95rem; font-weight: bold; color: #fff;
            display: flex; align-items: center; gap: 10px;
        }
        .panel__title i { color: var(--violet-3); }
        .panel__body { padding: 20px 24px; }
        .panel__body--table { padding: 0; }

        /* ============ TABS ============ */
        .tabs {
            display: flex; gap: 6px; flex-wrap: wrap;
            background: rgba(10, 7, 20, 0.5);
            padding: 6px;
            border-radius: var(--radius-sm);
            border: 1px solid var(--border);
        }
        .tab-btn {
            background: transparent; border: none;
            padding: 9px 16px;
            border-radius: 10px;
            font-family: inherit; font-size: 0.8rem; font-weight: bold;
            color: var(--text-3);
            cursor: pointer;
            transition: var(--t);
            display: inline-flex; align-items: center; gap: 6px;
        }
        .tab-btn:hover { color: var(--text-2); background: rgba(255,255,255,0.04); }
        .tab-btn.active {
            background: var(--grad-2);
            color: #fff;
            box-shadow: 0 6px 16px -6px rgba(168, 85, 247, 0.6);
        }
        .tab-btn .count {
            background: rgba(255,255,255,0.1);
            padding: 1px 8px;
            border-radius: 8px;
            font-size: 0.68rem;
            min-width: 20px;
            text-align: center;
        }
        .tab-btn.active .count { background: rgba(255,255,255,0.25); }

        /* ============ BUTTONS ============ */
        .btn {
            background: var(--grad-2);
            color: #fff;
            border: none;
            padding: 11px 20px;
            border-radius: var(--radius-sm);
            font-weight: bold;
            cursor: pointer;
            font-family: inherit;
            font-size: 0.82rem;
            display: inline-flex; align-items: center; gap: 8px;
            box-shadow: 0 6px 20px -8px rgba(168, 85, 247, 0.7);
            transition: var(--t);
        }
        .btn:hover { transform: translateY(-2px); box-shadow: 0 10px 28px -8px rgba(168, 85, 247, 0.85); }
        .btn-ghost {
            background: transparent;
            color: var(--text-2);
            border: 1px solid var(--border);
            box-shadow: none;
        }
        .btn-ghost:hover { background: var(--surface-2); color: #fff; border-color: var(--border-2); box-shadow: none; }
        .btn-sm { padding: 8px 14px; font-size: 0.75rem; }
        .btn-success { background: linear-gradient(135deg, #34d399, #10b981); }
        .btn-success:hover { box-shadow: 0 10px 28px -8px rgba(52, 211, 153, 0.6); }
        .btn-warning { background: linear-gradient(135deg, #fbbf24, #f59e0b); }
        .btn-warning:hover { box-shadow: 0 10px 28px -8px rgba(251, 191, 36, 0.6); }
        .btn-danger  { background: linear-gradient(135deg, #f87171, #ef4444); }
        .btn-danger:hover { box-shadow: 0 10px 28px -8px rgba(248, 113, 113, 0.6); }

        /* ============ ADD USER PANEL ============ */
        .add-user-panel {
            display: none;
            padding: 22px 24px;
            border-bottom: 1px solid var(--border);
            background: rgba(10, 7, 20, 0.35);
        }
        .add-user-panel.open { display: block; animation: slideDown 0.3s ease; }
        @keyframes slideDown { from { opacity: 0; max-height: 0; } to { opacity: 1; max-height: 800px; } }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 14px;
            margin-bottom: 16px;
        }
        .form-field { display: flex; flex-direction: column; gap: 6px; }
        .form-field label {
            font-size: 0.75rem; color: var(--text-2);
            font-weight: bold;
        }
        .form-field label .req { color: var(--red); }
        .form-field input,
        .form-field select,
        .form-field textarea {
            width: 100%;
            padding: 11px 14px;
            background: rgba(10, 7, 20, 0.5);
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            font-size: 0.82rem;
            font-family: inherit;
            color: var(--text);
            transition: var(--t);
        }
        .form-field input::placeholder,
        .form-field textarea::placeholder { color: var(--text-3); }
        .form-field input:focus,
        .form-field select:focus,
        .form-field textarea:focus {
            outline: none;
            border-color: var(--violet);
            background: rgba(10, 7, 20, 0.8);
            box-shadow: 0 0 0 3px rgba(168, 85, 247, 0.15);
        }
        .form-field input[dir="ltr"] { direction: ltr; text-align: right; }
        .form-field select option { background: var(--surface-2); color: var(--text); }

        /* ============ USERS GRID ============ */
        .users-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(340px, 1fr));
            gap: 16px;
        }
        .user-card {
            background: rgba(10, 7, 20, 0.35);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            padding: 20px;
            transition: var(--t);
            position: relative;
            overflow: hidden;
            animation: cardIn 0.35s ease;
        }
        @keyframes cardIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        .user-card:hover {
            background: var(--surface-2);
            border-color: var(--border-2);
            transform: translateY(-3px);
            box-shadow: var(--shadow);
        }

        .user-card__header {
            display: flex; align-items: center; gap: 14px;
            margin-bottom: 16px;
            padding-bottom: 16px;
            border-bottom: 1px dashed var(--border);
        }
        .user-card__avatar {
            width: 54px; height: 54px;
            border-radius: 16px;
            display: flex; align-items: center; justify-content: center;
            font-weight: bold; font-size: 1.15rem;
            color: #fff;
            flex-shrink: 0;
            background: linear-gradient(135deg, #64748b, #475569);
            box-shadow: var(--shadow);
            position: relative;
        }
        .user-card__avatar.is-admin { background: linear-gradient(135deg, #fbbf24, #d97706); }
        .user-card__avatar.is-active { background: linear-gradient(135deg, #34d399, #059669); }
        .user-card__avatar.is-blocked { background: linear-gradient(135deg, #f87171, #dc2626); }
        .user-card__avatar.is-pending { background: linear-gradient(135deg, #60a5fa, #0284c7); }
        .user-card__avatar::after {
            content: ''; position: absolute;
            bottom: -2px; left: -2px;
            width: 14px; height: 14px;
            border-radius: 50%;
            background: var(--green);
            border: 3px solid var(--bg);
        }
        .user-card__avatar.is-blocked::after { background: var(--red); }
        .user-card__avatar.is-pending::after { background: var(--amber); }

        .user-card__info { flex: 1; min-width: 0; }
        .user-card__name {
            font-weight: bold; font-size: 0.92rem;
            color: #fff; margin-bottom: 4px;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .user-card__mobile {
            font-size: 0.72rem; color: var(--text-2);
            direction: ltr; text-align: right;
            display: flex; align-items: center; gap: 5px;
        }

        .user-card__badges {
            display: flex; gap: 6px; flex-wrap: wrap;
            margin-bottom: 14px;
        }
        .badge {
            padding: 5px 10px;
            border-radius: 8px;
            font-size: 0.68rem;
            font-weight: bold;
            display: inline-flex; align-items: center; gap: 5px;
            letter-spacing: 0.3px;
        }
        .badge i { font-size: 0.62rem; }
        .badge.active      { background: rgba(52, 211, 153, 0.15); color: #6ee7b7; border: 1px solid rgba(52, 211, 153, 0.3); }
        .badge.pending     { background: rgba(251, 191, 36, 0.15); color: #fcd34d; border: 1px solid rgba(251, 191, 36, 0.3); }
        .badge.deactivated { background: rgba(100, 116, 139, 0.15); color: #cbd5e1; border: 1px solid rgba(100, 116, 139, 0.3); }
        .badge.blocked     { background: rgba(248, 113, 113, 0.15); color: #fca5a5; border: 1px solid rgba(248, 113, 113, 0.3); }
        .badge.admin       { background: rgba(251, 191, 36, 0.18); color: #fcd34d; border: 1px solid rgba(251, 191, 36, 0.35); }
        .badge.user        { background: rgba(168, 85, 247, 0.15); color: #c4b5fd; border: 1px solid rgba(168, 85, 247, 0.35); }
        .badge.super       { background: linear-gradient(135deg, rgba(168, 85, 247, 0.25), rgba(236, 72, 153, 0.15)); color: #d8b4fe; border: 1px solid rgba(168, 85, 247, 0.4); }

        .badge-priority-low       { background: rgba(52, 211, 153, 0.15); color: #6ee7b7; border: 1px solid rgba(52, 211, 153, 0.3); }
        .badge-priority-medium    { background: rgba(251, 191, 36, 0.15); color: #fcd34d; border: 1px solid rgba(251, 191, 36, 0.3); }
        .badge-priority-high      { background: rgba(248, 113, 113, 0.15); color: #fca5a5; border: 1px solid rgba(248, 113, 113, 0.3); }
        .badge-priority-very_high { background: rgba(168, 85, 247, 0.2); color: #d8b4fe; border: 1px solid rgba(168, 85, 247, 0.35); }

        .badge-status-open        { background: rgba(168, 85, 247, 0.15); color: #c4b5fd; border: 1px solid rgba(168, 85, 247, 0.3); }
        .badge-status-answered    { background: rgba(251, 191, 36, 0.15); color: #fcd34d; border: 1px solid rgba(251, 191, 36, 0.3); }
        .badge-status-closed      { background: rgba(100, 116, 139, 0.15); color: #cbd5e1; border: 1px solid rgba(100, 116, 139, 0.3); }

        .user-card__meta {
            display: flex; flex-direction: column; gap: 8px;
            margin-bottom: 16px;
            font-size: 0.75rem;
            color: var(--text-2);
        }
        .user-card__meta-row { display: flex; align-items: center; gap: 8px; }
        .user-card__meta-row i { width: 16px; color: var(--text-3); font-size: 0.72rem; }
        .user-card__meta-row span {
            direction: ltr; text-align: right;
            flex: 1; word-break: break-all;
        }
        .user-card__meta-row.date span { direction: rtl; }

        .user-card__permissions {
            padding: 12px;
            background: rgba(168, 85, 247, 0.06);
            border: 1px solid rgba(168, 85, 247, 0.18);
            border-radius: var(--radius-sm);
            margin-bottom: 14px;
        }
        .user-card__permissions-title {
            font-size: 0.68rem;
            color: var(--violet-3);
            font-weight: bold;
            margin-bottom: 8px;
            display: flex; align-items: center; gap: 5px;
        }
        .user-card__permissions-list {
            display: flex; flex-wrap: wrap; gap: 5px;
        }
        .perm-chip {
            font-size: 0.66rem;
            padding: 3px 8px;
            border-radius: 6px;
            background: rgba(168, 85, 247, 0.12);
            color: #c4b5fd;
            border: 1px solid rgba(168, 85, 247, 0.25);
            display: inline-flex; align-items: center; gap: 4px;
        }
        .perm-chip i { font-size: 0.6rem; }
        .perm-chip.none { background: rgba(100, 116, 139, 0.15); color: #94a3b8; border-color: rgba(100, 116, 139, 0.25); }

        .user-card__actions {
            display: flex; gap: 6px; flex-wrap: wrap;
            padding-top: 14px;
            border-top: 1px dashed var(--border);
        }

        .action-btn {
            padding: 8px 12px;
            border-radius: 10px;
            font-size: 0.72rem;
            font-weight: bold;
            cursor: pointer;
            border: 1px solid;
            transition: var(--t);
            font-family: inherit;
            display: inline-flex; align-items: center; gap: 5px; justify-content: center;
            background: transparent;
            text-decoration: none;
        }
        .action-btn i { font-size: 0.72rem; }
        .action-btn:hover { transform: translateY(-1px); }

        .action-btn.primary { color: #c4b5fd; border-color: rgba(168, 85, 247, 0.4); flex: 1; }
        .action-btn.primary:hover { background: var(--violet); color: #fff; border-color: var(--violet); box-shadow: 0 4px 12px -4px rgba(168, 85, 247, 0.7); }

        .action-btn.impersonate {
            color: #6ee7b7;
            border-color: rgba(52, 211, 153, 0.4);
            background: rgba(52, 211, 153, 0.06);
            flex: 1;
        }
        .action-btn.impersonate:hover { background: var(--green); color: #fff; border-color: var(--green); }

        .action-btn.success { color: #6ee7b7; border-color: rgba(52, 211, 153, 0.4); flex: 1; }
        .action-btn.success:hover { background: var(--green); color: #fff; border-color: var(--green); }

        .action-btn.warning { color: #fcd34d; border-color: rgba(251, 191, 36, 0.4); flex: 1; }
        .action-btn.warning:hover { background: var(--amber); color: #fff; border-color: var(--amber); }

        .action-btn.danger { color: #fca5a5; border-color: rgba(248, 113, 113, 0.4); flex: 1; }
        .action-btn.danger:hover { background: var(--red); color: #fff; border-color: var(--red); }

        .action-btn.unblock { color: #c4b5fd; border-color: rgba(168, 85, 247, 0.4); flex: 1; }
        .action-btn.unblock:hover { background: var(--violet); color: #fff; border-color: var(--violet); }

        .action-btn.icon-only { flex: 0 0 auto; padding: 8px 10px; }

        .user-card.is-admin-card {
            background: linear-gradient(135deg, rgba(251, 191, 36, 0.06), rgba(251, 191, 36, 0.01));
            border-color: rgba(251, 191, 36, 0.25);
        }
        .user-card.is-super-admin {
            background: linear-gradient(135deg, rgba(168, 85, 247, 0.08), rgba(236, 72, 153, 0.03));
            border-color: rgba(168, 85, 247, 0.35);
        }

        /* ============ EMPTY ============ */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--text-3);
            grid-column: 1 / -1;
        }
        .empty-state i { font-size: 3rem; margin-bottom: 16px; opacity: 0.4; display: block; }
        .empty-state p { font-size: 0.85rem; }

        /* ============ TICKETS TABLE ============ */
        .table-wrap { overflow-x: auto; }
        table.tickets-table {
            width: 100%; border-collapse: collapse;
            font-size: 0.82rem;
        }
        table.tickets-table thead { background: rgba(10, 7, 20, 0.6); }
        table.tickets-table th {
            padding: 14px 16px;
            text-align: right;
            font-weight: bold;
            color: var(--text-3);
            font-size: 0.72rem;
            border-bottom: 1px solid var(--border);
            white-space: nowrap;
            letter-spacing: 0.4px;
        }
        table.tickets-table td {
            padding: 14px 16px;
            color: var(--text);
            border-bottom: 1px solid var(--border);
            vertical-align: middle;
        }
        table.tickets-table tbody tr { transition: var(--t); }
        table.tickets-table tbody tr:hover { background: rgba(168, 85, 247, 0.05); }
        table.tickets-table tbody tr.has-unread { background: rgba(248, 113, 113, 0.03); }
        table.tickets-table tbody tr.has-unread:hover { background: rgba(248, 113, 113, 0.07); }

        .ticket-id-cell {
            font-weight: bold;
            color: var(--violet-3);
            direction: ltr; text-align: right;
            white-space: nowrap;
        }
        .ticket-subject-cell {
            font-weight: 600; color: #fff;
            max-width: 320px;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .ticket-user-cell {
            display: flex; align-items: center; gap: 8px;
            white-space: nowrap;
        }
        .ticket-user-cell .avatar {
            width: 32px; height: 32px;
            border-radius: 50%;
            background: var(--grad-2);
            color: #fff;
            font-weight: bold; font-size: 0.75rem;
            display: inline-flex; align-items: center; justify-content: center;
            flex-shrink: 0;
        }
        .ticket-user-cell .avatar.is-empty { background: linear-gradient(135deg, #64748b, #475569); }
        .ticket-user-cell .name { font-weight: 600; font-size: 0.8rem; }
        .ticket-user-cell .mobile { font-size: 0.68rem; color: var(--text-3); direction: ltr; }

        .ticket-date-cell {
            white-space: nowrap;
            font-size: 0.72rem;
            color: var(--text-2);
        }
        .ticket-date-cell .fa-clock { color: var(--blue); margin-left: 4px; font-size: 0.65rem; }

        .unread-dot {
            display: inline-flex; align-items: center; justify-content: center;
            background: var(--pink);
            color: #fff;
            width: 22px; height: 22px;
            border-radius: 50%;
            font-size: 0.66rem;
            font-weight: bold;
            margin-right: 4px;
            box-shadow: 0 0 12px -2px rgba(236, 72, 153, 0.7);
        }

        .action-link {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 8px 14px;
            border-radius: 10px;
            font-size: 0.75rem;
            font-weight: bold;
            text-decoration: none;
            cursor: pointer;
            border: 1px solid rgba(168, 85, 247, 0.4);
            background: rgba(168, 85, 247, 0.08);
            color: #c4b5fd;
            transition: var(--t);
            font-family: inherit;
        }
        .action-link:hover {
            background: var(--violet);
            color: #fff;
            border-color: var(--violet);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px -4px rgba(168, 85, 247, 0.7);
        }
        .action-link i { font-size: 0.7rem; }

        /* ============ MODALS ============ */
        .modal-overlay {
            position: fixed; inset: 0;
            background: rgba(2, 6, 23, 0.85);
            backdrop-filter: blur(8px);
            display: none;
            align-items: center; justify-content: center;
            z-index: 1000;
            padding: 20px;
            animation: fadeIn 0.3s ease;
        }
        .modal-overlay.active { display: flex; }
        @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }

        .modal-box {
            background: var(--surface);
            border: 1px solid var(--border-2);
            width: 100%;
            max-width: 640px;
            border-radius: var(--radius-lg);
            box-shadow: 0 30px 80px -20px rgba(0,0,0,0.8), 0 0 60px -20px rgba(168, 85, 247, 0.4);
            animation: modalIn 0.35s cubic-bezier(0.4, 0, 0.2, 1);
            max-height: 90vh;
            display: flex; flex-direction: column;
            overflow: hidden;
        }
        @keyframes modalIn {
            from { transform: translateY(20px) scale(0.98); opacity: 0; }
            to   { transform: translateY(0) scale(1); opacity: 1; }
        }

        .modal-header {
            padding: 20px 24px;
            border-bottom: 1px solid var(--border);
            display: flex; justify-content: space-between; align-items: center;
            flex-shrink: 0;
        }
        .modal-header h3 {
            margin: 0; font-size: 1rem;
            color: #fff;
            display: flex; align-items: center; gap: 10px;
            font-weight: bold;
        }
        .modal-header h3 i { color: var(--violet-3); }
        .modal-close {
            background: transparent;
            border: none;
            color: var(--text-2);
            width: 32px; height: 32px;
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.2rem;
            cursor: pointer;
            transition: var(--t);
            flex-shrink: 0;
            text-decoration: none;
        }
        .modal-close:hover { background: var(--surface-3); color: #fff; }

        .modal-body {
            padding: 24px;
            overflow-y: auto;
            flex: 1;
        }
        .modal-body::-webkit-scrollbar { width: 6px; }
        .modal-body::-webkit-scrollbar-thumb { background: var(--surface-3); border-radius: 3px; }

        .modal-footer {
            padding: 16px 24px;
            border-top: 1px solid var(--border);
            display: flex; gap: 10px; justify-content: flex-end;
            flex-shrink: 0;
        }

        /* Perms */
        .perms-title {
            font-size: 0.78rem;
            color: var(--text-2);
            font-weight: bold;
            margin: 20px 0 12px;
            display: flex; align-items: center; gap: 8px;
            padding-bottom: 10px;
            border-bottom: 1px dashed var(--border);
        }
        .perms-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
            gap: 10px;
        }
        .perm-option {
            display: flex; align-items: center; gap: 12px;
            padding: 12px 14px;
            background: rgba(10, 7, 20, 0.4);
            border: 2px solid var(--border);
            border-radius: var(--radius-sm);
            cursor: pointer;
            transition: var(--t);
            user-select: none;
        }
        .perm-option:hover { border-color: var(--border-2); background: rgba(10, 7, 20, 0.7); }
        .perm-option.checked {
            border-color: var(--violet);
            background: linear-gradient(135deg, rgba(168, 85, 247, 0.15), rgba(168, 85, 247, 0.04));
            box-shadow: 0 0 0 3px rgba(168, 85, 247, 0.12);
        }
        .perm-checkbox {
            width: 20px; height: 20px;
            border-radius: 6px;
            border: 2px solid var(--border-2);
            background: transparent;
            display: flex; align-items: center; justify-content: center;
            color: #fff; font-size: 0.62rem;
            flex-shrink: 0;
            transition: var(--t);
        }
        .perm-option.checked .perm-checkbox { background: var(--violet); border-color: var(--violet); }
        .perm-icon {
            width: 34px; height: 34px;
            border-radius: 9px;
            display: flex; align-items: center; justify-content: center;
            font-size: 0.85rem;
            flex-shrink: 0;
        }
        .perm-text { flex: 1; min-width: 0; }
        .perm-label { font-size: 0.8rem; font-weight: bold; color: var(--text); margin-bottom: 2px; }
        .perm-desc { font-size: 0.68rem; color: var(--text-3); line-height: 1.4; }

        /* ============ TICKET CONVERSATION MODAL ============ */
        .ticket-modal-overlay {
            position: fixed; inset: 0;
            background: rgba(2, 6, 23, 0.9);
            backdrop-filter: blur(10px);
            z-index: 2000;
            display: none;
            padding: 20px;
            align-items: center; justify-content: center;
            overflow-y: auto;
        }
        .ticket-modal-overlay.active { display: flex; }

        .ticket-modal-window {
            background: var(--surface);
            border: 1px solid var(--border-2);
            border-radius: var(--radius-lg);
            box-shadow: 0 30px 80px -20px rgba(0,0,0,0.8), 0 0 80px -20px rgba(168, 85, 247, 0.35);
            width: 100%;
            max-width: 1300px;
            height: 90vh;
            max-height: 900px;
            display: flex; flex-direction: column;
            overflow: hidden;
            animation: modalIn 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .ticket-modal-header {
            padding: 16px 22px;
            background: rgba(10, 7, 20, 0.6);
            border-bottom: 1px solid var(--border);
            display: flex; align-items: center; justify-content: space-between;
            gap: 12px;
            flex-shrink: 0;
        }
        .ticket-modal-header__title {
            display: flex; align-items: center; gap: 12px;
            font-size: 0.9rem; font-weight: bold; color: #fff;
            min-width: 0; flex: 1;
        }
        .ticket-modal-header__icon {
            width: 36px; height: 36px;
            border-radius: 10px;
            background: var(--grad-2);
            color: #fff;
            display: flex; align-items: center; justify-content: center;
            font-size: 0.85rem;
            flex-shrink: 0;
            box-shadow: 0 6px 16px -6px rgba(168, 85, 247, 0.7);
        }
        .ticket-modal-header__subject {
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
            max-width: 400px;
        }
        .ticket-modal-header__badges {
            display: flex; gap: 6px;
            flex-shrink: 0; flex-wrap: wrap;
        }

        .ticket-modal-body {
            flex: 1; min-height: 0;
            display: grid;
            grid-template-columns: 1fr 380px;
        }

        .ticket-chat {
            display: flex; flex-direction: column;
            min-height: 0;
            border-left: 1px solid var(--border);
        }
        .ticket-chat__messages {
            flex: 1; min-height: 0;
            overflow-y: auto;
            padding: 22px;
            display: flex; flex-direction: column; gap: 16px;
            background:
                radial-gradient(circle at 20% 10%, rgba(168, 85, 247, 0.05), transparent 40%),
                radial-gradient(circle at 80% 90%, rgba(236, 72, 153, 0.04), transparent 40%),
                rgba(10, 7, 20, 0.35);
        }
        .ticket-chat__messages::-webkit-scrollbar { width: 6px; }
        .ticket-chat__messages::-webkit-scrollbar-thumb { background: var(--surface-3); border-radius: 3px; }

        .chat-msg { display: flex; gap: 10px; max-width: 80%; }
        .chat-msg.is-user  { align-self: flex-end; flex-direction: row-reverse; }
        .chat-msg.is-admin { align-self: flex-start; flex-direction: row; }

        .chat-avatar {
            width: 38px; height: 38px;
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 0.82rem; font-weight: bold;
            flex-shrink: 0;
            color: #fff;
            overflow: hidden;
        }
        .chat-avatar.user  { background: var(--grad-2); }
        .chat-avatar.admin { background: linear-gradient(135deg, #fbbf24, #d97706); }
        .chat-avatar img { width: 100%; height: 100%; object-fit: cover; }

        .chat-bubble-wrap { min-width: 0; flex: 1; }
        .chat-meta {
            font-size: 0.66rem;
            color: var(--text-3);
            margin-bottom: 5px;
            display: flex; gap: 8px; align-items: center;
            flex-wrap: wrap;
        }
        .chat-msg.is-user .chat-meta { justify-content: flex-end; }
        .chat-author { font-weight: bold; }
        .chat-msg.is-user  .chat-author { color: var(--violet-3); }
        .chat-msg.is-admin .chat-author { color: #fcd34d; }
        .chat-role-badge {
            font-size: 0.6rem;
            padding: 1px 7px;
            border-radius: 6px;
            background: rgba(255,255,255,0.06);
            color: var(--text-3);
            font-weight: bold;
        }

        .chat-bubble {
            padding: 12px 16px;
            border-radius: 16px;
            font-size: 0.85rem;
            line-height: 1.85;
            white-space: pre-wrap;
            word-wrap: break-word;
            box-shadow: 0 2px 8px -4px rgba(0,0,0,0.4);
        }
        .chat-msg.is-user  .chat-bubble {
            background: var(--surface-2);
            color: var(--text);
            border: 1px solid var(--border-2);
            border-top-left-radius: 4px;
        }
        .chat-msg.is-admin .chat-bubble {
            background: var(--grad-2);
            color: #fff;
            border-top-right-radius: 4px;
            box-shadow: 0 6px 20px -8px rgba(168, 85, 247, 0.6);
        }

        .ticket-chat__reply {
            padding: 16px 20px;
            border-top: 1px solid var(--border);
            background: rgba(10, 7, 20, 0.5);
            flex-shrink: 0;
        }
        .ticket-chat__reply.closed {
            padding: 18px;
            text-align: center;
            background: rgba(251, 191, 36, 0.06);
            color: #fcd34d;
            font-size: 0.82rem;
            font-weight: bold;
            display: flex; align-items: center; justify-content: center;
            gap: 10px; flex-wrap: wrap;
        }
        .reply-form { display: flex; gap: 10px; align-items: flex-end; }
        .reply-form textarea {
            flex: 1;
            min-height: 48px; max-height: 140px;
            padding: 12px 14px;
            border: 2px solid var(--border);
            border-radius: var(--radius-sm);
            background: rgba(10, 7, 20, 0.5);
            font-family: inherit;
            font-size: 0.85rem;
            color: var(--text);
            resize: vertical;
            transition: var(--t);
        }
        .reply-form textarea:focus {
            outline: none;
            border-color: var(--violet);
            background: rgba(10, 7, 20, 0.8);
            box-shadow: 0 0 0 4px rgba(168, 85, 247, 0.15);
        }
        .reply-btn {
            background: var(--grad-2);
            color: #fff;
            border: none;
            width: 48px; height: 48px;
            border-radius: var(--radius-sm);
            cursor: pointer;
            display: flex; align-items: center; justify-content: center;
            font-size: 0.95rem;
            flex-shrink: 0;
            transition: var(--t);
            box-shadow: 0 4px 14px -6px rgba(168, 85, 247, 0.7);
        }
        .reply-btn:hover { transform: translateY(-1px); box-shadow: 0 6px 20px -6px rgba(168, 85, 247, 0.9); }

        .ticket-modal-side {
            display: flex; flex-direction: column;
            min-height: 0;
            overflow-y: auto;
            background: rgba(10, 7, 20, 0.4);
        }
        .ticket-modal-side::-webkit-scrollbar { width: 6px; }
        .ticket-modal-side::-webkit-scrollbar-thumb { background: var(--surface-3); border-radius: 3px; }

        .side-section {
            padding: 18px 20px;
            border-bottom: 1px solid var(--border);
        }
        .side-section:last-child { border-bottom: none; }
        .side-section__title {
            font-size: 0.72rem;
            color: var(--text-3);
            text-transform: uppercase;
            letter-spacing: 1px;
            font-weight: bold;
            margin-bottom: 12px;
            display: flex; align-items: center; gap: 8px;
        }
        .side-section__title i { color: var(--violet-3); }

        .user-profile {
            display: flex; align-items: center; gap: 12px;
            padding: 12px;
            background: rgba(10, 7, 20, 0.4);
            border-radius: var(--radius-sm);
            border: 1px solid var(--border);
            margin-bottom: 12px;
        }
        .user-profile__avatar {
            width: 52px; height: 52px;
            border-radius: 14px;
            background: var(--grad-2);
            color: #fff;
            font-weight: bold; font-size: 1.15rem;
            display: inline-flex; align-items: center; justify-content: center;
            overflow: hidden;
            flex-shrink: 0;
        }
        .user-profile__avatar img { width: 100%; height: 100%; object-fit: cover; }
        .user-profile__info { flex: 1; min-width: 0; }
        .user-profile__name {
            font-weight: bold; color: #fff;
            font-size: 0.88rem; margin-bottom: 3px;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .user-profile__mobile {
            font-size: 0.7rem;
            color: var(--text-3);
            direction: ltr;
            display: flex; align-items: center; gap: 4px;
        }

        .mini-stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 6px;
        }
        .mini-stat {
            background: rgba(10, 7, 20, 0.5);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 10px 6px;
            text-align: center;
        }
        .mini-stat__value {
            font-size: 1.05rem; font-weight: bold;
            color: #fff; line-height: 1;
        }
        .mini-stat__label {
            font-size: 0.6rem;
            color: var(--text-3);
            margin-top: 5px;
        }
        .mini-stat.open   .mini-stat__value { color: var(--violet-3); }
        .mini-stat.closed .mini-stat__value { color: var(--green); }
        .mini-stat.total  .mini-stat__value { color: var(--amber); }

        .info-row {
            display: flex; justify-content: space-between; align-items: center;
            gap: 10px;
            padding: 9px 0;
            border-bottom: 1px dashed var(--border);
            font-size: 0.78rem;
        }
        .info-row:last-child { border-bottom: none; }
        .info-row .lbl {
            color: var(--text-2);
            display: flex; align-items: center; gap: 7px;
            font-size: 0.72rem;
            flex-shrink: 0;
        }
        .info-row .lbl i { color: var(--violet-3); width: 14px; text-align: center; font-size: 0.68rem; }
        .info-row .val {
            color: #fff; font-weight: bold;
            text-align: left;
            word-break: break-word;
            font-size: 0.76rem;
        }
        .info-row .val.ltr { direction: ltr; }

        .prev-ticket {
            display: flex; flex-direction: column; gap: 5px;
            padding: 11px 12px 11px 16px;
            background: rgba(10, 7, 20, 0.4);
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            margin-bottom: 8px;
            text-decoration: none;
            color: inherit;
            transition: var(--t);
            cursor: pointer;
            position: relative;
        }
        .prev-ticket:last-child { margin-bottom: 0; }
        .prev-ticket:hover {
            background: var(--surface-2);
            border-color: var(--border-2);
            transform: translateX(-2px);
        }
        .prev-ticket::before {
            content: '';
            position: absolute;
            right: 6px; top: 12px; bottom: 12px;
            width: 3px; border-radius: 3px;
        }
        .prev-ticket.open::before     { background: var(--violet); }
        .prev-ticket.answered::before { background: var(--amber); }
        .prev-ticket.closed::before   { background: #64748b; }

        .prev-ticket__header {
            display: flex; align-items: center; justify-content: space-between;
            gap: 6px;
        }
        .prev-ticket__id { font-size: 0.66rem; color: var(--violet-3); font-weight: bold; direction: ltr; }
        .prev-ticket__subject {
            font-size: 0.8rem; font-weight: bold; color: #fff;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .prev-ticket__meta {
            display: flex; align-items: center; gap: 8px;
            font-size: 0.64rem;
            color: var(--text-3);
            flex-wrap: wrap;
        }
        .prev-ticket__meta span { display: inline-flex; align-items: center; gap: 4px; }

        .prev-empty {
            text-align: center;
            padding: 20px 12px;
            color: var(--text-3);
            font-size: 0.75rem;
        }
        .prev-empty i { font-size: 1.6rem; opacity: 0.4; display: block; margin-bottom: 6px; }

        .modal-actions-bar {
            display: flex; gap: 6px; flex-wrap: wrap;
            margin-bottom: 12px;
        }
        .modal-actions-bar form { display: contents; }

        /* ============ HAMBURGER (mobile) ============ */
        .hamburger-btn {
            display: none;
            width: 44px; height: 44px;
            border: none;
            background: var(--grad-2);
            border-radius: 13px;
            cursor: pointer;
            box-shadow: 0 6px 16px -6px rgba(168, 85, 247, 0.7);
            flex-shrink: 0;
            align-items: center; justify-content: center;
            padding: 0;
        }
        .hamburger-btn .hamburger-lines {
            width: 22px; height: 16px;
            display: flex; flex-direction: column; justify-content: space-between;
        }
        .hamburger-btn .hamburger-lines span {
            display: block; height: 2.5px; width: 100%;
            background: #fff; border-radius: 3px;
            transition: transform 0.35s, opacity 0.25s, width 0.3s;
            transform-origin: center;
        }
        .hamburger-btn .hamburger-lines span:nth-child(2) { width: 70%; }
        .hamburger-btn.active .hamburger-lines span:nth-child(1) { transform: translateY(6.75px) rotate(45deg); }
        .hamburger-btn.active .hamburger-lines span:nth-child(2) { opacity: 0; transform: translateX(-10px); }
        .hamburger-btn.active .hamburger-lines span:nth-child(3) { transform: translateY(-6.75px) rotate(-45deg); }

        .sidebar-overlay {
            display: none;
            position: fixed; inset: 0;
            background: rgba(2, 6, 23, 0.8);
            backdrop-filter: blur(6px);
            z-index: 998;
            opacity: 0;
            transition: opacity 0.3s ease;
        }
        .sidebar-overlay.active { display: block; opacity: 1; }

        /* ============ RESPONSIVE ============ */
        @media (max-width: 1200px) {
            .stats-grid { grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); }
            .users-grid { grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); }
            .ticket-modal-body { grid-template-columns: 1fr 320px; }
        }

        @media (max-width: 900px) {
            .hamburger-btn { display: flex; }

            .sidebar {
                position: fixed;
                top: 0; right: 0; bottom: 0;
                width: 76px;
                transform: translateX(105%);
                transition: transform 0.4s cubic-bezier(0.65, 0, 0.35, 1);
                z-index: 999;
                box-shadow: -20px 0 50px rgba(0, 0, 0, 0.5);
                padding: 20px 0;
            }
            .sidebar.open { transform: translateX(0); }

            .main { width: 100%; }
            .topbar { padding: 16px 20px; gap: 12px; }
            .content { padding: 0 20px 24px; }
            .search-box { min-width: auto; }
            .search-box input { display: none; }
            .panel__header { flex-direction: column; align-items: stretch; gap: 12px; }
            .panel__header > div:last-child { flex-direction: column; align-items: stretch; gap: 10px; }
            .panel__header .btn { width: 100%; justify-content: center; }

            .ticket-modal-overlay { padding: 0; }
            .ticket-modal-window { max-width: 100%; height: 100vh; max-height: 100vh; border-radius: 0; }
            .ticket-modal-body { grid-template-columns: 1fr; }
            .ticket-chat { border-left: none; }
            .ticket-modal-side { max-height: 300px; border-top: 1px solid var(--border); }
        }

        @media (max-width: 700px) {
            .content { padding: 0 14px 20px; }
            .topbar { padding: 14px 16px; }
            .topbar__title h1 { font-size: 1rem; }
            .topbar__title span { display: none; }

            .stats-grid { grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 18px; }
            .stat-card { padding: 14px; flex-direction: column; align-items: flex-start; gap: 8px; position: relative; }
            .stat-card__value { font-size: 1.35rem; }
            .stat-card__icon { width: 36px; height: 36px; font-size: 0.9rem; position: absolute; top: 12px; left: 12px; }

            .panel { border-radius: var(--radius); }
            .panel__header { padding: 16px 18px; }
            .panel__title { font-size: 0.88rem; }
            .panel__body { padding: 16px 18px; }
            .add-user-panel { padding: 16px 18px; }

            .tabs {
                gap: 4px; padding: 4px;
                overflow-x: auto; flex-wrap: nowrap;
                scrollbar-width: none;
            }
            .tabs::-webkit-scrollbar { display: none; }
            .tab-btn { padding: 8px 12px; font-size: 0.72rem; white-space: nowrap; flex-shrink: 0; }
            .tab-btn .count { font-size: 0.62rem; padding: 1px 6px; min-width: 18px; }

            .form-grid { grid-template-columns: 1fr; gap: 10px; }
            .form-field label { font-size: 0.72rem; }
            .form-field input, .form-field select { padding: 10px 12px; font-size: 0.8rem; }

            .users-grid { grid-template-columns: 1fr; gap: 12px; }
            .user-card { padding: 16px; border-radius: var(--radius); }
            .user-card__avatar { width: 48px; height: 48px; font-size: 1rem; border-radius: 14px; }
            .user-card__name { font-size: 0.88rem; }
            .badge { font-size: 0.64rem; padding: 4px 8px; }
            .user-card__meta { font-size: 0.72rem; }
            .perm-chip { font-size: 0.62rem; padding: 2px 7px; }
            .action-btn { font-size: 0.68rem; padding: 7px 10px; }
            .btn { padding: 10px 16px; font-size: 0.8rem; }

            .modal-box { max-width: 96vw; border-radius: var(--radius); max-height: 92vh; }
            .modal-header { padding: 16px 18px; }
            .modal-header h3 { font-size: 0.92rem; }
            .modal-body { padding: 18px; }
            .modal-footer { padding: 14px 18px; flex-direction: column-reverse; }
            .modal-footer .btn { width: 100%; justify-content: center; }

            .perms-grid { grid-template-columns: 1fr; }
            .perm-option { padding: 10px 12px; gap: 10px; }

            .alert { font-size: 0.8rem; padding: 11px 14px; }

            table.tickets-table th, table.tickets-table td { padding: 10px 12px; font-size: 0.72rem; }
            .ticket-subject-cell { max-width: 180px; }
            .chat-msg { max-width: 92%; }
            .ticket-chat__messages { padding: 14px; }
            .ticket-chat__reply { padding: 12px; }
            .side-section { padding: 14px 16px; }
        }

        @media (max-width: 400px) {
            .content { padding: 0 10px 16px; }
            .topbar { padding: 10px 12px; }
            .topbar__title h1 { font-size: 0.9rem; }
            .stats-grid { grid-template-columns: 1fr; }
        }

        @media (prefers-reduced-motion: reduce) {
            * { transition: none !important; animation: none !important; }
        }
    </style>
</head>
<body>

<!-- Overlay -->
<div class="sidebar-overlay" id="sidebarOverlay" onclick="closeSidebar()"></div>

<!-- ============ SIDEBAR ============ -->
<aside class="sidebar" id="appSidebar">
    <div class="sidebar__logo">
        <i class="fas fa-crown"></i>
    </div>

    <nav class="sidebar__nav">
        <a href="index.php<?= $section === 'users' ? '' : '' ?>"
           class="nav-icon <?= $section === 'users' ? 'active' : '' ?>"
           title="کاربران">
            <i class="fas fa-users"></i>
        </a>

        <a href="index.php?section=tickets"
           class="nav-icon <?= $section === 'tickets' ? 'active' : '' ?>"
           title="تیکت‌ها"
           style="position: relative;">
            <i class="fas fa-headset"></i>
            <?php if ($ticketStats['unread'] > 0): ?>
                <span style="
                    position: absolute;
                    top: 4px; right: 4px;
                    background: var(--pink);
                    color: #fff;
                    min-width: 18px; height: 18px;
                    border-radius: 50%;
                    font-size: 0.6rem;
                    font-weight: bold;
                    display: inline-flex;
                    align-items: center; justify-content: center;
                    border: 2px solid var(--bg-2);
                    padding: 0 4px;
                "><?= $ticketStats['unread'] ?></span>
            <?php endif; ?>
        </a>

        <a href="analysis/index.php" class="nav-icon" title="آمار و تحلیل">
            <i class="fas fa-chart-pie"></i>
        </a>
    </nav>

    <a href="../auth/login.php?logout=1" class="nav-icon logout" title="خروج از سامانه">
        <i class="fas fa-sign-out-alt"></i>
    </a>
</aside>

<!-- ============ MAIN ============ -->
<div class="main">

    <header class="topbar">
        <button class="hamburger-btn" id="hamburgerBtn" onclick="toggleSidebar()" aria-label="باز کردن منو">
            <div class="hamburger-lines">
                <span></span><span></span><span></span>
            </div>
        </button>

        <div class="topbar__title">
            <h1><?= $section === 'tickets' ? 'تیکت‌های پشتیبانی' : 'مدیریت کاربران' ?></h1>
            <span><?= $section === 'tickets' ? 'مدیریت گفتگوها و پاسخ به کاربران' : 'لیست، ایجاد و مدیریت کاربران سیستم' ?></span>
        </div>

        <div class="topbar__actions">
            <div class="search-box">
                <i class="fas fa-search"></i>
                <input type="text"
                       id="<?= $section === 'tickets' ? 'ticketSearchInput' : 'userSearchInput' ?>"
                       placeholder="جستجو..."
                       oninput="<?= $section === 'tickets' ? 'filterTickets()' : 'filterUsers()' ?>">
            </div>
            <button class="icon-btn" title="اعلان‌ها">
                <i class="fas fa-bell"></i>
            </button>
            <button class="avatar-btn" title="<?= htmlspecialchars($displayUser) ?>">
                <?php if ($isSuperAdmin): ?>
                    <i class="fas fa-shield-halved"></i>
                <?php else: ?>
                    <?= htmlspecialchars(mb_substr($displayUser, 0, 1, 'UTF-8')) ?>
                <?php endif; ?>
            </button>
        </div>
    </header>

    <main class="content">
        <?php if (!empty($msg)): ?>
            <div class="alert alert-<?= $msgType === 'error' ? 'error' : 'success' ?>">
                <i class="fas fa-<?= $msgType === 'error' ? 'exclamation-circle' : 'check-circle' ?>"></i>
                <?= htmlspecialchars($msg) ?>
            </div>
        <?php endif; ?>

        <?php if ($section === 'users'): ?>

        <!-- ================================================================
             SECTION: USERS
        ================================================================= -->

        <div class="stats-grid">
            <div class="stat-card">
                <div>
                    <div class="stat-card__label">کل کاربران</div>
                    <div class="stat-card__value"><?= $totalUsers ?></div>
                </div>
                <div class="stat-card__icon"><i class="fas fa-users"></i></div>
            </div>
            <div class="stat-card success">
                <div>
                    <div class="stat-card__label">فعال</div>
                    <div class="stat-card__value" style="color:#6ee7b7;"><?= $activeUsers ?></div>
                </div>
                <div class="stat-card__icon"><i class="fas fa-user-check"></i></div>
            </div>
            <div class="stat-card warning">
                <div>
                    <div class="stat-card__label">در انتظار</div>
                    <div class="stat-card__value" style="color:#fcd34d;"><?= $pendingUsers ?></div>
                </div>
                <div class="stat-card__icon"><i class="fas fa-hourglass-half"></i></div>
            </div>
            <div class="stat-card purple">
                <div>
                    <div class="stat-card__label">مدیران</div>
                    <div class="stat-card__value" style="color:#d8b4fe;"><?= $adminUsers ?></div>
                </div>
                <div class="stat-card__icon"><i class="fas fa-crown"></i></div>
            </div>
        </div>

        <div class="panel">
            <div class="panel__header">
                <div class="panel__title">
                    <i class="fas fa-list"></i>
                    لیست کاربران سیستم
                </div>
                <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
                    <?php if (hasAdminPermission('add_user')): ?>
                        <button type="button" class="btn" onclick="toggleAddPanel()">
                            <i class="fas fa-user-plus"></i>
                            افزودن کاربر
                        </button>
                    <?php endif; ?>
                </div>
            </div>

            <div style="padding: 14px 24px; border-bottom: 1px solid var(--border);">
                <div class="tabs">
                    <button class="tab-btn active" data-filter="all" onclick="switchUserFilter('all', this)">
                        <i class="fas fa-layer-group"></i> همه <span class="count"><?= $totalUsers ?></span>
                    </button>
                    <button class="tab-btn" data-filter="active" onclick="switchUserFilter('active', this)">
                        <i class="fas fa-circle-check"></i> فعال <span class="count"><?= $activeUsers ?></span>
                    </button>
                    <button class="tab-btn" data-filter="pending" onclick="switchUserFilter('pending', this)">
                        <i class="fas fa-clock"></i> در انتظار <span class="count"><?= $pendingUsers ?></span>
                    </button>
                    <button class="tab-btn" data-filter="blocked" onclick="switchUserFilter('blocked', this)">
                        <i class="fas fa-ban"></i> مسدود <span class="count"><?= $blockedUsers ?></span>
                    </button>
                    <button class="tab-btn" data-filter="admin" onclick="switchUserFilter('admin', this)">
                        <i class="fas fa-crown"></i> مدیران <span class="count"><?= $adminUsers ?></span>
                    </button>
                </div>
            </div>

            <?php if (hasAdminPermission('add_user')): ?>
            <div class="add-user-panel" id="addUserPanel">
                <form method="POST" autocomplete="off">
                    <input type="hidden" name="add_user" value="1">
                    <div class="form-grid">
                        <div class="form-field">
                            <label>نام <span class="req">*</span></label>
                            <input type="text" name="first_name" required>
                        </div>
                        <div class="form-field">
                            <label>نام خانوادگی <span class="req">*</span></label>
                            <input type="text" name="last_name" required>
                        </div>
                        <div class="form-field">
                            <label>شماره موبایل <span class="req">*</span></label>
                            <input type="tel" name="mobile" required pattern="09[0-9]{9}" placeholder="09xxxxxxxxx" dir="ltr">
                        </div>
                        <div class="form-field">
                            <label>ایمیل</label>
                            <input type="email" name="email" placeholder="user@mail.com" dir="ltr">
                        </div>
                        <div class="form-field">
                            <label>رمز عبور <span class="req">*</span></label>
                            <input type="password" name="password" required minlength="6" placeholder="حداقل ۶ کاراکتر" dir="ltr">
                        </div>
                        <div class="form-field">
                            <label>نقش</label>
                            <select name="role" id="addRole" onchange="toggleAddPermissions()">
                                <option value="user" selected>کاربر عادی</option>
                                <?php if ($isSuperAdmin): ?>
                                    <option value="admin">مدیر</option>
                                <?php endif; ?>
                            </select>
                        </div>
                        <div class="form-field">
                            <label>وضعیت اولیه</label>
                            <select name="status">
                                <option value="active" selected>فعال</option>
                                <option value="pending">در انتظار تایید</option>
                                <option value="deactivated">غیرفعال</option>
                                <option value="blocked">مسدود</option>
                            </select>
                        </div>
                    </div>

                    <?php if ($isSuperAdmin): ?>
                    <div id="addPermissionsSection" style="display:none;">
                        <div class="perms-title"><i class="fas fa-shield-halved"></i> دسترسی‌های مدیریتی</div>
                        <div class="perms-grid">
                            <?php foreach ($ALL_ADMIN_PERMISSIONS as $pKey => $pInfo): ?>
                                <label class="perm-option">
                                    <input type="checkbox" name="admin_permissions[]" value="<?= htmlspecialchars($pKey) ?>" style="display:none;" onchange="this.parentElement.classList.toggle('checked', this.checked)">
                                    <span class="perm-checkbox"><i class="fas fa-check"></i></span>
                                    <span class="perm-icon" style="background: <?= $pInfo['color'] ?>22; color: <?= $pInfo['color'] ?>;">
                                        <i class="fas <?= $pInfo['icon'] ?>"></i>
                                    </span>
                                    <span class="perm-text">
                                        <div class="perm-label"><?= htmlspecialchars($pInfo['label']) ?></div>
                                        <div class="perm-desc"><?= htmlspecialchars($pInfo['desc']) ?></div>
                                    </span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div style="display:flex; gap:10px; justify-content: flex-end; margin-top: 16px;">
                        <button type="button" class="btn btn-ghost" onclick="toggleAddPanel()">
                            <i class="fas fa-times"></i> انصراف
                        </button>
                        <button type="submit" class="btn">
                            <i class="fas fa-check"></i> ایجاد کاربر
                        </button>
                    </div>
                </form>
            </div>
            <?php endif; ?>

            <div class="panel__body">
                <div class="users-grid" id="usersGrid">
                    <?php if (empty($users)): ?>
                        <div class="empty-state">
                            <i class="fas fa-users-slash"></i>
                            <p>هیچ کاربری یافت نشد</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($users as $u): ?>
                            <?php
                                $statusLabels = ['active' => 'فعال', 'pending' => 'در انتظار', 'deactivated' => 'غیرفعال', 'blocked' => 'مسدود'];
                                $statusIcons = ['active' => 'fa-circle-check', 'pending' => 'fa-clock', 'deactivated' => 'fa-circle-pause', 'blocked' => 'fa-ban'];
                                $statusLabel = $statusLabels[$u['status']] ?? $u['status'];
                                $statusIcon = $statusIcons[$u['status']] ?? 'fa-circle';

                                $fullName = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''));
                                if ($fullName === '') $fullName = '— بدون نام —';
                                $initial = mb_substr(trim($u['first_name'] ?: $u['mobile']), 0, 1, 'UTF-8');

                                $isAdmin = $u['role'] === 'admin';
                                $isSuper = ((int)$u['id'] === $firstAdminId);
                                $userPerms = [];
                                if ($isAdmin && !$isSuper) {
                                    $userPerms = json_decode($u['admin_permissions'] ?? '[]', true);
                                    if (!is_array($userPerms)) $userPerms = [];
                                } elseif ($isSuper) {
                                    $userPerms = array_keys($ALL_ADMIN_PERMISSIONS);
                                }

                                $avatarClass = 'is-' . $u['status'];
                                if ($isSuper) $avatarClass = 'is-admin';

                                $cardClass = '';
                                if ($isSuper) $cardClass = 'is-super-admin';
                                elseif ($isAdmin) $cardClass = 'is-admin-card';

                                $canEditThis = hasAdminPermission('edit_user');
                                if ($isSuper && !$isSuperAdmin) $canEditThis = false;
                                $canToggleThis = hasAdminPermission('toggle_status') && !$isAdmin;
                                $canDeleteThis = hasAdminPermission('delete_user') && !$isAdmin;
                                $canImpersonate = ($u['status'] === 'active' && (int)$u['id'] !== $currentUserId);

                                $searchText = mb_strtolower($fullName . ' ' . $u['mobile'] . ' ' . ($u['email'] ?? ''));
                            ?>
                            <div class="user-card <?= $cardClass ?>"
                                 data-status="<?= htmlspecialchars($u['status']) ?>"
                                 data-role="<?= htmlspecialchars($u['role']) ?>"
                                 data-search="<?= htmlspecialchars($searchText, ENT_QUOTES) ?>">

                                <div class="user-card__header">
                                    <div class="user-card__avatar <?= $avatarClass ?>">
                                        <?php if ($isAdmin): ?>
                                            <i class="fas fa-<?= $isSuper ? 'shield-halved' : 'crown' ?>"></i>
                                        <?php else: ?>
                                            <?= htmlspecialchars($initial) ?>
                                        <?php endif; ?>
                                    </div>
                                    <div class="user-card__info">
                                        <div class="user-card__name" title="<?= htmlspecialchars($fullName) ?>">
                                            <?= htmlspecialchars($fullName) ?>
                                        </div>
                                        <div class="user-card__mobile">
                                            <i class="fas fa-mobile-alt"></i>
                                            <?= htmlspecialchars($u['mobile']) ?>
                                        </div>
                                    </div>
                                </div>

                                <div class="user-card__badges">
                                    <?php if ($isSuper): ?>
                                        <span class="badge super"><i class="fas fa-shield-halved"></i> مدیر کل</span>
                                    <?php elseif ($isAdmin): ?>
                                        <span class="badge admin"><i class="fas fa-crown"></i> مدیر</span>
                                    <?php else: ?>
                                        <span class="badge user"><i class="fas fa-user"></i> کاربر عادی</span>
                                    <?php endif; ?>
                                    <span class="badge <?= $u['status'] ?>">
                                        <i class="fas <?= $statusIcon ?>"></i> <?= $statusLabel ?>
                                    </span>
                                </div>

                                <div class="user-card__meta">
                                    <div class="user-card__meta-row">
                                        <i class="fas fa-envelope"></i>
                                        <span><?= htmlspecialchars($u['email'] ?? '—') ?></span>
                                    </div>
                                    <div class="user-card__meta-row date">
                                        <i class="fas fa-calendar"></i>
                                        <span><?= htmlspecialchars($u['created_at']) ?></span>
                                    </div>
                                </div>

                                <?php if ($isAdmin): ?>
                                    <div class="user-card__permissions">
                                        <div class="user-card__permissions-title">
                                            <i class="fas fa-shield-halved"></i>
                                            دسترسی‌های مدیریتی
                                        </div>
                                        <div class="user-card__permissions-list">
                                            <?php if (empty($userPerms)): ?>
                                                <span class="perm-chip none"><i class="fas fa-ban"></i> بدون دسترسی</span>
                                            <?php else: ?>
                                                <?php foreach ($userPerms as $pKey): ?>
                                                    <?php if (isset($ALL_ADMIN_PERMISSIONS[$pKey])): $pInfo = $ALL_ADMIN_PERMISSIONS[$pKey]; ?>
                                                        <span class="perm-chip">
                                                            <i class="fas <?= $pInfo['icon'] ?>"></i>
                                                            <?= htmlspecialchars($pInfo['label']) ?>
                                                        </span>
                                                    <?php endif; ?>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <div class="user-card__actions">
                                    <?php if ($canImpersonate): ?>
                                        <a href="index.php?impersonate=<?= (int)$u['id'] ?>"
                                           class="action-btn impersonate"
                                           target="_blank"
                                           onclick="return confirm('ورود به پنل «<?= htmlspecialchars($fullName, ENT_QUOTES) ?>»؟');">
                                            <i class="fas fa-right-to-bracket"></i> ورود به پنل
                                        </a>
                                    <?php endif; ?>

                                    <?php if ($canEditThis): ?>
                                        <button type="button" class="action-btn primary" onclick="openEditModal(<?= (int)$u['id'] ?>)">
                                            <i class="fas fa-pen"></i> ویرایش
                                        </button>
                                    <?php endif; ?>

                                    <?php if (!$isAdmin && $canToggleThis): ?>
                                        <?php if ($u['status'] !== 'active' && $u['status'] !== 'blocked'): ?>
                                            <form method="POST" style="display: contents;">
                                                <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                                <button type="submit" name="action_status" value="active" class="action-btn success">
                                                    <i class="fas fa-check"></i> فعال
                                                </button>
                                            </form>
                                        <?php endif; ?>

                                        <?php if ($u['status'] === 'active'): ?>
                                            <form method="POST" style="display: contents;">
                                                <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                                <button type="submit" name="action_status" value="deactivated" class="action-btn warning">
                                                    <i class="fas fa-pause"></i> غیرفعال
                                                </button>
                                            </form>
                                        <?php endif; ?>

                                        <?php if ($u['status'] === 'blocked'): ?>
                                            <form method="POST" style="display: contents;">
                                                <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                                <button type="submit" name="action_status" value="active" class="action-btn unblock">
                                                    <i class="fas fa-unlock"></i> رفع مسدودی
                                                </button>
                                            </form>
                                        <?php else: ?>
                                            <form method="POST" style="display: contents;">
                                                <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                                <button type="submit" name="action_status" value="blocked" class="action-btn danger"
                                                        onclick="return confirm('کاربر مسدود شود؟');">
                                                    <i class="fas fa-ban"></i> مسدود
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    <?php endif; ?>

                                    <?php if (!$isAdmin && $canDeleteThis): ?>
                                        <form method="POST" style="display: contents;">
                                            <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                            <button type="submit" name="action_delete" value="1"
                                                    class="action-btn danger icon-only"
                                                    onclick="return confirm('این کاربر به طور کامل حذف شود؟');"
                                                    title="حذف">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <?php else: ?>

        <!-- ================================================================
             SECTION: TICKETS
        ================================================================= -->

        <div class="stats-grid">
            <div class="stat-card">
                <div>
                    <div class="stat-card__label">کل تیکت‌ها</div>
                    <div class="stat-card__value"><?= $ticketStats['total'] ?></div>
                </div>
                <div class="stat-card__icon"><i class="fas fa-headset"></i></div>
            </div>
            <div class="stat-card danger">
                <div>
                    <div class="stat-card__label">در انتظار پاسخ</div>
                    <div class="stat-card__value" style="color:#fca5a5;"><?= $ticketStats['open'] ?></div>
                </div>
                <div class="stat-card__icon"><i class="fas fa-hourglass-half"></i></div>
            </div>
            <div class="stat-card warning">
                <div>
                    <div class="stat-card__label">پاسخ داده شده</div>
                    <div class="stat-card__value" style="color:#fcd34d;"><?= $ticketStats['answered'] ?></div>
                </div>
                <div class="stat-card__icon"><i class="fas fa-reply"></i></div>
            </div>
            <div class="stat-card success">
                <div>
                    <div class="stat-card__label">بسته شده</div>
                    <div class="stat-card__value" style="color:#6ee7b7;"><?= $ticketStats['closed'] ?></div>
                </div>
                <div class="stat-card__icon"><i class="fas fa-check-double"></i></div>
            </div>
            <div class="stat-card info">
                <div>
                    <div class="stat-card__label">خوانده نشده</div>
                    <div class="stat-card__value" style="color:#7dd3fc;"><?= $ticketStats['unread'] ?></div>
                </div>
                <div class="stat-card__icon"><i class="fas fa-bell"></i></div>
            </div>
        </div>

        <div class="panel">
            <div class="panel__header">
                <div class="panel__title">
                    <i class="fas fa-list"></i>
                    لیست تیکت‌های کاربران
                </div>
            </div>

            <div style="padding: 14px 24px; border-bottom: 1px solid var(--border);">
                <div class="tabs">
                    <button class="tab-btn active" data-tfilter="all" onclick="switchTicketFilter('all', this)">
                        <i class="fas fa-layer-group"></i> همه <span class="count"><?= $ticketStats['total'] ?></span>
                    </button>
                    <button class="tab-btn" data-tfilter="open" onclick="switchTicketFilter('open', this)">
                        <i class="fas fa-hourglass-half"></i> در انتظار <span class="count"><?= $ticketStats['open'] ?></span>
                    </button>
                    <button class="tab-btn" data-tfilter="answered" onclick="switchTicketFilter('answered', this)">
                        <i class="fas fa-reply"></i> پاسخ داده <span class="count"><?= $ticketStats['answered'] ?></span>
                    </button>
                    <button class="tab-btn" data-tfilter="closed" onclick="switchTicketFilter('closed', this)">
                        <i class="fas fa-check"></i> بسته شده <span class="count"><?= $ticketStats['closed'] ?></span>
                    </button>
                    <button class="tab-btn" data-tfilter="unread" onclick="switchTicketFilter('unread', this)">
                        <i class="fas fa-bell"></i> خوانده‌نشده <span class="count"><?= $ticketStats['unread'] ?></span>
                    </button>
                </div>
            </div>

            <div class="panel__body--table">
                <?php if (empty($tickets)): ?>
                    <div class="empty-state">
                        <i class="fas fa-inbox"></i>
                        <p>هیچ تیکتی ثبت نشده است</p>
                    </div>
                <?php else: ?>
                    <div class="table-wrap">
                        <table class="tickets-table">
                            <thead>
                                <tr>
                                    <th>شماره</th>
                                    <th>عنوان</th>
                                    <th>کاربر</th>
                                    <th>اولویت</th>
                                    <th>وضعیت</th>
                                    <th>تاریخ ایجاد</th>
                                    <th>آخرین پاسخ</th>
                                    <th>عملیات</th>
                                </tr>
                            </thead>
                            <tbody id="ticketsTableBody">
                                <?php foreach ($tickets as $t):
                                    $tId = (int)$t['id'];
                                    $tStatus = (string)$t['status'];
                                    $tPriority = (string)$t['priority'];
                                    $unread = (int)$t['unread_count'];
                                    $tUserFullName = trim(($t['first_name'] ?? '') . ' ' . ($t['last_name'] ?? ''));
                                    if ($tUserFullName === '') $tUserFullName = $t['mobile'] ?: 'کاربر';
                                    $tInitial = mb_substr($t['first_name'] ?: $t['mobile'], 0, 1, 'UTF-8');
                                    $searchText = mb_strtolower($tId . ' ' . $t['subject'] . ' ' . $tUserFullName . ' ' . ($t['mobile'] ?? ''));
                                ?>
                                <tr class="<?= $unread > 0 ? 'has-unread' : '' ?>"
                                    data-status="<?= htmlspecialchars($tStatus) ?>"
                                    data-unread="<?= $unread ?>"
                                    data-tsearch="<?= htmlspecialchars($searchText, ENT_QUOTES) ?>">

                                    <td class="ticket-id-cell">#<?= $tId ?></td>

                                    <td>
                                        <div class="ticket-subject-cell" title="<?= htmlspecialchars($t['subject']) ?>">
                                            <?= htmlspecialchars($t['subject']) ?>
                                        </div>
                                    </td>

                                    <td>
                                        <div class="ticket-user-cell">
                                            <div class="avatar <?= empty($t['first_name']) ? 'is-empty' : '' ?>">
                                                <?= htmlspecialchars($tInitial) ?>
                                            </div>
                                            <div>
                                                <div class="name"><?= htmlspecialchars($tUserFullName) ?></div>
                                                <?php if (!empty($t['mobile'])): ?>
                                                    <div class="mobile"><?= htmlspecialchars($t['mobile']) ?></div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>

                                    <td>
                                        <span class="badge badge-priority-<?= htmlspecialchars($tPriority) ?>">
                                            <i class="fas fa-flag"></i>
                                            <?= htmlspecialchars($priorityLabels[$tPriority] ?? $tPriority) ?>
                                        </span>
                                    </td>

                                    <td>
                                        <span class="badge badge-status-<?= htmlspecialchars($tStatus) ?>">
                                            <?php if ($tStatus === 'open'): ?>
                                                <i class="fas fa-hourglass-half"></i>
                                            <?php elseif ($tStatus === 'answered'): ?>
                                                <i class="fas fa-reply"></i>
                                            <?php else: ?>
                                                <i class="fas fa-lock"></i>
                                            <?php endif; ?>
                                            <?= htmlspecialchars($statusLabels[$tStatus] ?? $tStatus) ?>
                                        </span>
                                        <?php if ($unread > 0): ?>
                                            <span class="unread-dot" title="<?= $unread ?> پیام خوانده‌نشده"><?= $unread ?></span>
                                        <?php endif; ?>
                                    </td>

                                    <td class="ticket-date-cell">
                                        <i class="fas fa-clock"></i>
                                        <?= htmlspecialchars(timeAgoPersian($t['created_at'])) ?>
                                    </td>

                                    <td class="ticket-date-cell">
                                        <i class="fas fa-clock"></i>
                                        <?= htmlspecialchars(timeAgoPersian($t['last_reply_at'] ?: $t['created_at'])) ?>
                                    </td>

                                    <td>
                                        <a href="index.php?section=tickets&id=<?= $tId ?>" class="action-link">
                                            <i class="fas fa-comments"></i>
                                            <span>مشاهده تیکت</span>
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="empty-state" id="emptyFilteredTickets" style="display:none;">
                        <i class="fas fa-filter"></i>
                        <p>تیکتی با این فیلتر یافت نشد</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <?php endif; ?>
    </main>
</div>

<!-- ================== MODAL: ویرایش کاربر ================== -->
<div id="editUserModal" class="modal-overlay">
    <div class="modal-box">
        <div class="modal-header">
            <h3><i class="fas fa-user-pen"></i> ویرایش کاربر</h3>
            <button class="modal-close" onclick="closeEditModal()">&times;</button>
        </div>

        <form method="POST" id="editUserForm">
            <input type="hidden" name="edit_user" value="1">
            <input type="hidden" name="user_id" id="edit_user_id">

            <div class="modal-body">
                <div class="form-grid">
                    <div class="form-field">
                        <label>نام <span class="req">*</span></label>
                        <input type="text" name="first_name" id="edit_first_name" required>
                    </div>
                    <div class="form-field">
                        <label>نام خانوادگی <span class="req">*</span></label>
                        <input type="text" name="last_name" id="edit_last_name" required>
                    </div>
                    <div class="form-field">
                        <label>شماره موبایل <span class="req">*</span></label>
                        <input type="tel" name="mobile" id="edit_mobile" required pattern="09[0-9]{9}" dir="ltr">
                    </div>
                    <div class="form-field">
                        <label>ایمیل</label>
                        <input type="email" name="email" id="edit_email" dir="ltr">
                    </div>
                    <div class="form-field">
                        <label>رمز عبور جدید</label>
                        <input type="password" name="password" id="edit_password" minlength="6" placeholder="خالی = بدون تغییر" dir="ltr">
                    </div>
                    <div class="form-field">
                        <label>نقش</label>
                        <select name="role" id="edit_role" onchange="toggleEditPermissions()">
                            <option value="user">کاربر عادی</option>
                            <option value="admin">مدیر</option>
                        </select>
                    </div>
                    <div class="form-field">
                        <label>وضعیت</label>
                        <select name="status" id="edit_status">
                            <option value="active">فعال</option>
                            <option value="pending">در انتظار تایید</option>
                            <option value="deactivated">غیرفعال</option>
                            <option value="blocked">مسدود</option>
                        </select>
                    </div>
                </div>

                <div id="editPermissionsSection" style="display:none;">
                    <div class="perms-title">
                        <i class="fas fa-shield-halved"></i>
                        دسترسی‌های مدیریتی
                    </div>
                    <div class="perms-grid">
                        <?php foreach ($ALL_ADMIN_PERMISSIONS as $pKey => $pInfo): ?>
                            <label class="perm-option" data-perm="<?= htmlspecialchars($pKey) ?>">
                                <input type="checkbox" name="admin_permissions[]" value="<?= htmlspecialchars($pKey) ?>" style="display:none;" onchange="this.parentElement.classList.toggle('checked', this.checked)">
                                <span class="perm-checkbox"><i class="fas fa-check"></i></span>
                                <span class="perm-icon" style="background: <?= $pInfo['color'] ?>22; color: <?= $pInfo['color'] ?>;">
                                    <i class="fas <?= $pInfo['icon'] ?>"></i>
                                </span>
                                <span class="perm-text">
                                    <div class="perm-label"><?= htmlspecialchars($pInfo['label']) ?></div>
                                    <div class="perm-desc"><?= htmlspecialchars($pInfo['desc']) ?></div>
                                </span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-ghost" onclick="closeEditModal()">
                    <i class="fas fa-times"></i> انصراف
                </button>
                <button type="submit" class="btn">
                    <i class="fas fa-save"></i> ذخیره
                </button>
            </div>
        </form>
    </div>
</div>

<?php if ($singleTicket):
    $tid = (int)$singleTicket['id'];
    $status = (string)$singleTicket['status'];
    $priority = (string)$singleTicket['priority'];
    $isClosed = $status === 'closed';
    $userFullName = trim(($singleTicket['first_name'] ?? '') . ' ' . ($singleTicket['last_name'] ?? ''));
    if ($userFullName === '') $userFullName = $singleTicket['mobile'] ?: 'کاربر';
    $userInitial = mb_substr($singleTicket['first_name'] ?: $singleTicket['mobile'], 0, 1, 'UTF-8');
    $userAvatarUrl = null;
    if (!empty($singleTicket['avatar'])) {
        $avBase = basename($singleTicket['avatar']);
        if (preg_match('/^[A-Za-z0-9_.\-]+$/', $avBase) && file_exists(__DIR__ . '/../uploads/avatars/' . $avBase)) {
            $userAvatarUrl = '../uploads/avatars/' . rawurlencode($avBase);
        }
    }
?>

<!-- ================== MODAL: گفتگوی تیکت ================== -->
<div class="ticket-modal-overlay active" id="ticketModal">
    <div class="ticket-modal-window" role="dialog" aria-modal="true">

        <div class="ticket-modal-header">
            <div class="ticket-modal-header__title">
                <div class="ticket-modal-header__icon"><i class="fas fa-comments"></i></div>
                <div class="ticket-modal-header__subject" title="<?= htmlspecialchars($singleTicket['subject']) ?>">
                    تیکت #<?= $tid ?> — <?= htmlspecialchars($singleTicket['subject']) ?>
                </div>
                <div class="ticket-modal-header__badges">
                    <span class="badge badge-priority-<?= htmlspecialchars($priority) ?>">
                        <i class="fas fa-flag"></i> <?= htmlspecialchars($priorityLabels[$priority] ?? $priority) ?>
                    </span>
                    <span class="badge badge-status-<?= htmlspecialchars($status) ?>">
                        <?php if ($status === 'open'): ?><i class="fas fa-hourglass-half"></i>
                        <?php elseif ($status === 'answered'): ?><i class="fas fa-reply"></i>
                        <?php else: ?><i class="fas fa-lock"></i><?php endif; ?>
                        <?= htmlspecialchars($statusLabels[$status] ?? $status) ?>
                    </span>
                </div>
            </div>

            <a href="index.php?section=tickets" class="modal-close" title="بستن">
                <i class="fas fa-times"></i>
            </a>
        </div>

        <div class="ticket-modal-body">

            <!-- Chat -->
            <div class="ticket-chat">
                <div class="ticket-chat__messages" id="chatBody">
                    <?php if (empty($messages)): ?>
                        <div style="text-align: center; color: var(--text-3); padding: 40px;">
                            <i class="fas fa-comment-slash" style="font-size: 2rem; opacity: 0.4; display: block; margin-bottom: 12px;"></i>
                            هیچ پیامی در این تیکت وجود ندارد.
                        </div>
                    <?php else: ?>
                        <?php foreach ($messages as $m):
                            $isAdminMsg = ($m['sender_role'] === 'admin');
                            if ($isAdminMsg) $senderName = 'پشتیبانی';
                            else {
                                $senderName = trim(($m['first_name'] ?? '') . ' ' . ($m['last_name'] ?? ''));
                                if ($senderName === '') $senderName = $m['mobile'] ?: 'کاربر';
                            }
                            $mInitial = mb_substr($senderName, 0, 1, 'UTF-8');
                            $mAvatarUrl = null;
                            if (!$isAdminMsg && !empty($m['avatar'])) {
                                $avBase = basename($m['avatar']);
                                if (preg_match('/^[A-Za-z0-9_.\-]+$/', $avBase) && file_exists(__DIR__ . '/../uploads/avatars/' . $avBase)) {
                                    $mAvatarUrl = '../uploads/avatars/' . rawurlencode($avBase);
                                }
                            }
                        ?>
                            <div class="chat-msg <?= $isAdminMsg ? 'is-admin' : 'is-user' ?>">
                                <div class="chat-avatar <?= $isAdminMsg ? 'admin' : 'user' ?>">
                                    <?php if ($mAvatarUrl): ?>
                                        <img src="<?= htmlspecialchars($mAvatarUrl) ?>" alt="">
                                    <?php elseif ($isAdminMsg): ?>
                                        <i class="fas fa-headset"></i>
                                    <?php else: ?>
                                        <?= htmlspecialchars($mInitial) ?>
                                    <?php endif; ?>
                                </div>
                                <div class="chat-bubble-wrap">
                                    <div class="chat-meta">
                                        <span class="chat-author"><?= htmlspecialchars($senderName) ?></span>
                                        <span class="chat-role-badge"><?= $isAdminMsg ? 'پشتیبانی' : 'کاربر' ?></span>
                                        <span>•</span>
                                        <span><?= htmlspecialchars(formatPersianDate($m['created_at'])) ?></span>
                                    </div>
                                    <div class="chat-bubble"><?= htmlspecialchars($m['message']) ?></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <?php if ($isClosed): ?>
                    <div class="ticket-chat__reply closed">
                        <i class="fas fa-lock"></i>
                        این تیکت بسته شده است. برای پاسخ دادن، ابتدا آن را بازگشایی کنید.
                    </div>
                <?php else: ?>
                    <div class="ticket-chat__reply">
                        <form method="POST" action="index.php?section=tickets&id=<?= $tid ?>" id="replyForm">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                            <input type="hidden" name="reply_ticket" value="<?= $tid ?>">
                            <div class="reply-form">
                                <textarea name="message" id="replyTextarea"
                                          placeholder="پاسخ خود را بنویسید... (Ctrl+Enter برای ارسال سریع)"
                                          maxlength="5000" required></textarea>
                                <button type="submit" class="reply-btn" title="ارسال">
                                    <i class="fas fa-paper-plane"></i>
                                </button>
                            </div>
                        </form>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Side -->
            <div class="ticket-modal-side">

                <div class="side-section">
                    <div class="side-section__title"><i class="fas fa-bolt"></i> عملیات</div>
                    <div class="modal-actions-bar">
                        <?php if ($isClosed): ?>
                            <form method="POST" action="index.php?section=tickets&id=<?= $tid ?>" onsubmit="return confirm('این تیکت بازگشایی شود؟');">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                <input type="hidden" name="reopen_ticket" value="<?= $tid ?>">
                                <button type="submit" class="btn btn-sm btn-success">
                                    <i class="fas fa-lock-open"></i> بازگشایی
                                </button>
                            </form>
                        <?php else: ?>
                            <form method="POST" action="index.php?section=tickets&id=<?= $tid ?>" onsubmit="return confirm('این تیکت بسته شود؟');">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                <input type="hidden" name="close_ticket" value="<?= $tid ?>">
                                <button type="submit" class="btn btn-sm btn-warning">
                                    <i class="fas fa-lock"></i> بستن
                                </button>
                            </form>
                        <?php endif; ?>
                        <form method="POST" action="index.php?section=tickets" onsubmit="return confirm('این تیکت برای همیشه حذف شود؟');">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                            <input type="hidden" name="delete_ticket" value="<?= $tid ?>">
                            <button type="submit" class="btn btn-sm btn-danger">
                                <i class="fas fa-trash"></i> حذف
                            </button>
                        </form>
                    </div>
                </div>

                <div class="side-section">
                    <div class="side-section__title"><i class="fas fa-user"></i> اطلاعات کاربر</div>
                    <div class="user-profile">
                        <div class="user-profile__avatar">
                            <?php if ($userAvatarUrl): ?>
                                <img src="<?= htmlspecialchars($userAvatarUrl) ?>" alt="">
                            <?php else: ?>
                                <?= htmlspecialchars($userInitial) ?>
                            <?php endif; ?>
                        </div>
                        <div class="user-profile__info">
                            <div class="user-profile__name"><?= htmlspecialchars($userFullName) ?></div>
                            <?php if (!empty($singleTicket['mobile'])): ?>
                                <div class="user-profile__mobile">
                                    <i class="fas fa-mobile-alt"></i>
                                    <?= htmlspecialchars($singleTicket['mobile']) ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="mini-stats">
                        <div class="mini-stat total">
                            <div class="mini-stat__value"><?= (int)$userTicketStats['total'] ?></div>
                            <div class="mini-stat__label">کل</div>
                        </div>
                        <div class="mini-stat open">
                            <div class="mini-stat__value"><?= (int)$userTicketStats['open'] ?></div>
                            <div class="mini-stat__label">باز</div>
                        </div>
                        <div class="mini-stat closed">
                            <div class="mini-stat__value"><?= (int)$userTicketStats['closed'] ?></div>
                            <div class="mini-stat__label">بسته</div>
                        </div>
                    </div>

                    <?php if (!empty($singleTicket['email'])): ?>
                        <div class="info-row" style="margin-top: 12px;">
                            <span class="lbl"><i class="fas fa-envelope"></i> ایمیل</span>
                            <span class="val ltr"><?= htmlspecialchars($singleTicket['email']) ?></span>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="side-section">
                    <div class="side-section__title"><i class="fas fa-circle-info"></i> اطلاعات تیکت جاری</div>
                    <div class="info-row">
                        <span class="lbl"><i class="fas fa-hashtag"></i> شماره</span>
                        <span class="val ltr">#<?= $tid ?></span>
                    </div>
                    <div class="info-row">
                        <span class="lbl"><i class="fas fa-calendar-plus"></i> تاریخ ثبت</span>
                        <span class="val"><?= htmlspecialchars(formatPersianDate($singleTicket['created_at'])) ?></span>
                    </div>
                    <div class="info-row">
                        <span class="lbl"><i class="fas fa-clock"></i> آخرین بروزرسانی</span>
                        <span class="val"><?= htmlspecialchars(timeAgoPersian($singleTicket['last_reply_at'] ?: $singleTicket['updated_at'])) ?></span>
                    </div>
                    <?php if (!empty($singleTicket['closed_at'])): ?>
                        <div class="info-row">
                            <span class="lbl"><i class="fas fa-calendar-check"></i> تاریخ بسته شدن</span>
                            <span class="val"><?= htmlspecialchars(formatPersianDate($singleTicket['closed_at'])) ?></span>
                        </div>
                    <?php endif; ?>
                    <div class="info-row">
                        <span class="lbl"><i class="fas fa-comments"></i> تعداد پیام‌ها</span>
                        <span class="val"><?= count($messages) ?></span>
                    </div>
                </div>

                <div class="side-section">
                    <div class="side-section__title">
                        <i class="fas fa-history"></i>
                        تیکت‌های قبلی این کاربر (<?= count($previousTickets) ?>)
                    </div>

                    <?php if (empty($previousTickets)): ?>
                        <div class="prev-empty">
                            <i class="fas fa-inbox"></i>
                            تیکت قبلی‌ای وجود ندارد.
                        </div>
                    <?php else: ?>
                        <?php foreach ($previousTickets as $pt):
                            $ptId = (int)$pt['id'];
                            $ptStatus = (string)$pt['status'];
                            $ptPriority = (string)$pt['priority'];
                        ?>
                            <a href="index.php?section=tickets&id=<?= $ptId ?>" class="prev-ticket <?= htmlspecialchars($ptStatus) ?>">
                                <div class="prev-ticket__header">
                                    <span class="prev-ticket__id">#<?= $ptId ?></span>
                                    <span class="badge badge-status-<?= htmlspecialchars($ptStatus) ?>" style="font-size: 0.58rem; padding: 2px 7px;">
                                        <?= htmlspecialchars($statusLabels[$ptStatus] ?? $ptStatus) ?>
                                    </span>
                                </div>
                                <div class="prev-ticket__subject" title="<?= htmlspecialchars($pt['subject']) ?>">
                                    <?= htmlspecialchars($pt['subject']) ?>
                                </div>
                                <div class="prev-ticket__meta">
                                    <span><i class="fas fa-flag"></i> <?= htmlspecialchars($priorityLabels[$ptPriority] ?? $ptPriority) ?></span>
                                    <span><i class="fas fa-comments"></i> <?= (int)$pt['msg_count'] ?> پیام</span>
                                    <span><i class="fas fa-clock"></i> <?= htmlspecialchars(timeAgoPersian($pt['last_reply_at'] ?: $pt['created_at'])) ?></span>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

            </div>

        </div>
    </div>
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
            ta.setSelectionRange(ta.value.length, ta.value.length);
        }
    });
</script>

<?php endif; ?>

<script>
    const USERS = <?= $usersJson ?>;
    const PERMISSIONS = <?= $permissionsJson ?>;
    const SUPER_ADMIN_ID = <?= (int)$firstAdminId ?>;
    const IS_SUPER_ADMIN = <?= $isSuperAdmin ? 'true' : 'false' ?>;
    const CURRENT_PERMISSIONS = <?= $currentPermissionsJson ?>;

    function hasPermission(perm) {
        return IS_SUPER_ADMIN || CURRENT_PERMISSIONS.includes(perm);
    }

    /* ============ Hamburger Sidebar ============ */
    function toggleSidebar() {
        const sidebar = document.getElementById('appSidebar');
        const overlay = document.getElementById('sidebarOverlay');
        const btn = document.getElementById('hamburgerBtn');
        if (!sidebar || !overlay || !btn) return;

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

    /* ============ Add User Panel ============ */
    function toggleAddPanel() {
        const panel = document.getElementById('addUserPanel');
        if (!panel) return;
        panel.classList.toggle('open');
        if (panel.classList.contains('open')) {
            setTimeout(() => {
                panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                panel.querySelector('input[name="first_name"]')?.focus();
            }, 100);
        }
    }

    function toggleAddPermissions() {
        const role = document.getElementById('addRole')?.value;
        const section = document.getElementById('addPermissionsSection');
        if (section) section.style.display = role === 'admin' ? 'block' : 'none';
    }

    /* ============ Users Filter ============ */
    let currentUserFilter = 'all';
    let currentUserSearch = '';

    function switchUserFilter(filter, btn) {
        currentUserFilter = filter;
        document.querySelectorAll('.tab-btn[data-filter]').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        applyUserFilters();
    }

    function filterUsers() {
        currentUserSearch = document.getElementById('userSearchInput').value.trim().toLowerCase();
        applyUserFilters();
    }

    function applyUserFilters() {
        const cards = document.querySelectorAll('.user-card');
        let visible = 0;

        cards.forEach(card => {
            const status = card.getAttribute('data-status');
            const role = card.getAttribute('data-role');
            const search = card.getAttribute('data-search') || '';

            let matchFilter = false;
            if (currentUserFilter === 'all') matchFilter = true;
            else if (currentUserFilter === 'admin') matchFilter = (role === 'admin');
            else matchFilter = (status === currentUserFilter);

            const matchSearch = !currentUserSearch || search.includes(currentUserSearch);

            if (matchFilter && matchSearch) {
                card.style.display = '';
                visible++;
            } else {
                card.style.display = 'none';
            }
        });

        let emptyEl = document.querySelector('.empty-state.dynamic');
        const grid = document.getElementById('usersGrid');

        if (visible === 0 && !emptyEl && grid) {
            emptyEl = document.createElement('div');
            emptyEl.className = 'empty-state dynamic';
            emptyEl.innerHTML = `<i class="fas fa-search"></i><p>هیچ کاربری با این فیلتر یافت نشد</p>`;
            grid.appendChild(emptyEl);
        } else if (visible > 0 && emptyEl) {
            emptyEl.remove();
        }
    }

    /* ============ Tickets Filter ============ */
    let currentTicketFilter = 'all';
    let currentTicketSearch = '';

    function switchTicketFilter(filter, btn) {
        currentTicketFilter = filter;
        document.querySelectorAll('.tab-btn[data-tfilter]').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        applyTicketFilters();
    }

    function filterTickets() {
        currentTicketSearch = document.getElementById('ticketSearchInput').value.trim().toLowerCase();
        applyTicketFilters();
    }

    function applyTicketFilters() {
        const rows = document.querySelectorAll('#ticketsTableBody tr');
        let visible = 0;

        rows.forEach(row => {
            const status = row.getAttribute('data-status');
            const unread = parseInt(row.getAttribute('data-unread') || '0', 10);
            const search = row.getAttribute('data-tsearch') || '';

            let matchFilter = false;
            if (currentTicketFilter === 'all')         matchFilter = true;
            else if (currentTicketFilter === 'unread') matchFilter = (unread > 0);
            else                                       matchFilter = (status === currentTicketFilter);

            const matchSearch = !currentTicketSearch || search.includes(currentTicketSearch);

            if (matchFilter && matchSearch) {
                row.style.display = '';
                visible++;
            } else {
                row.style.display = 'none';
            }
        });

        const emptyEl = document.getElementById('emptyFilteredTickets');
        if (emptyEl) emptyEl.style.display = (visible === 0 && rows.length > 0) ? '' : 'none';
    }

    /* ============ Edit User Modal ============ */
    function openEditModal(userId) {
        const user = USERS.find(u => Number(u.id) === Number(userId));
        if (!user) return;

        document.getElementById('edit_user_id').value = user.id;
        document.getElementById('edit_first_name').value = user.first_name || '';
        document.getElementById('edit_last_name').value = user.last_name || '';
        document.getElementById('edit_mobile').value = user.mobile || '';
        document.getElementById('edit_email').value = user.email || '';
        document.getElementById('edit_password').value = '';
        document.getElementById('edit_role').value = user.role || 'user';
        document.getElementById('edit_status').value = user.status || 'active';

        const isTargetSuper = Number(user.id) === Number(SUPER_ADMIN_ID);
        const roleSelect = document.getElementById('edit_role');
        const statusSelect = document.getElementById('edit_status');

        if (isTargetSuper) {
            roleSelect.disabled = true;
            statusSelect.disabled = true;
            ensureHidden('edit_role_hidden', 'role', 'admin');
            ensureHidden('edit_status_hidden', 'status', user.status);
        } else {
            roleSelect.disabled = false;
            statusSelect.disabled = false;
            removeHidden('edit_role_hidden');
            removeHidden('edit_status_hidden');
        }

        let perms = [];
        if (isTargetSuper) {
            perms = Object.keys(PERMISSIONS);
        } else if (user.role === 'admin') {
            try {
                perms = JSON.parse(user.admin_permissions || '[]');
                if (!Array.isArray(perms)) perms = [];
            } catch (e) { perms = []; }
        }

        document.querySelectorAll('#editPermissionsSection .perm-option').forEach(label => {
            const perm = label.getAttribute('data-perm');
            const checkbox = label.querySelector('input[type="checkbox"]');
            const iHaveIt = hasPermission(perm);

            checkbox.checked = perms.includes(perm);
            label.classList.toggle('checked', checkbox.checked);
            checkbox.disabled = !iHaveIt;
            label.style.opacity = iHaveIt ? '1' : '0.5';
            label.style.cursor = iHaveIt ? 'pointer' : 'not-allowed';
        });

        toggleEditPermissions();
        document.getElementById('editUserModal').classList.add('active');
    }

    function closeEditModal() {
        document.getElementById('editUserModal').classList.remove('active');
    }

    function toggleEditPermissions() {
        const role = document.getElementById('edit_role').value;
        const section = document.getElementById('editPermissionsSection');
        if (section) section.style.display = role === 'admin' ? 'block' : 'none';
    }

    function ensureHidden(id, name, value) {
        let el = document.getElementById(id);
        if (!el) {
            el = document.createElement('input');
            el.type = 'hidden';
            el.id = id;
            el.name = name;
            document.getElementById('editUserForm').appendChild(el);
        }
        el.value = value;
    }
    function removeHidden(id) {
        const el = document.getElementById(id);
        if (el) el.remove();
    }

    document.getElementById('editUserModal')?.addEventListener('click', function(e) {
        if (e.target === this) closeEditModal();
    });

    /* ============ Keyboard ============ */
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            const modal = document.getElementById('ticketModal');
            if (modal && modal.classList.contains('active')) {
                window.location.href = 'index.php?section=tickets';
                return;
            }
            closeEditModal();
            closeSidebar();
        }
    });

    document.getElementById('userSearchInput')?.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') e.preventDefault();
    });
    document.getElementById('ticketSearchInput')?.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') e.preventDefault();
    });

    let resizeTimer;
    window.addEventListener('resize', function() {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(function() {
            if (window.innerWidth > 900) closeSidebar();
        }, 150);
    });
</script>
</body>
</html>