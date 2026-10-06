<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../database/Database.php';
require_once __DIR__ . '/../includes/impersonate_banner.php';
require_once __DIR__ . '/../includes/sidebar.php';
require_once __DIR__ . '/../includes/quick_access.php';
require_once __DIR__ . '/../includes/chat_notifications.php';

if (!Auth::check()) {
    header('Location: ../auth/login.php');
    exit;
}

// ⭐⭐⭐ قفل کردن تایم‌زون PHP روی تهران — باید قبل از هر date/strtotime باشد
date_default_timezone_set('Asia/Tehran');

$db = Database::getInstance();

try {
    $db->exec("SET NAMES 'utf8mb4'");
    // ⭐⭐⭐ قفل کردن تایم‌زون Session MySQL روی تهران (برای ستون‌های TIMESTAMP)
    $db->exec("SET time_zone = '+03:30'");
} catch (PDOException $e) {
    error_log('DB timezone/encoding error: ' . $e->getMessage());
}

// ================== تبدیل تاریخ میلادی به شمسی ==================
if (!function_exists('gregorianToJalali')) {
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
}

if (!function_exists('formatPersianDate')) {
    function formatPersianDate($datetime, $includeWeekday = true) {
        if (empty($datetime) || $datetime === '0000-00-00 00:00:00') return '—';
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
}

if (!function_exists('formatPersianDateShort')) {
    function formatPersianDateShort($datetime) {
        if (empty($datetime) || $datetime === '0000-00-00 00:00:00') return '—';
        $ts = strtotime($datetime);
        if (!$ts) return $datetime;

        $gy = (int)date('Y', $ts);
        $gm = (int)date('n', $ts);
        $gd = (int)date('j', $ts);
        list($jy, $jm, $jd) = gregorianToJalali($gy, $gm, $gd);

        $months = ['فروردین','اردیبهشت','خرداد','تیر','مرداد','شهریور','مهر','آبان','آذر','دی','بهمن','اسفند'];
        return $jd . ' ' . $months[$jm - 1] . ' ' . $jy;
    }
}

// ================== توابع لینک اشتراکی و ربات تسکام ==================
if (!function_exists('ensureCallShareToken')) {
    function ensureCallShareToken(PDO $db, int $reqId): string {
        if ($reqId <= 0) return '';
        try {
            $stmt = $db->prepare("SELECT share_token FROM call_requests WHERE id = ? LIMIT 1");
            $stmt->execute([$reqId]);
            $existing = $stmt->fetchColumn();
            if (!empty($existing)) return (string)$existing;
        } catch (PDOException $e) {
            return '';
        }
        $token = bin2hex(random_bytes(16));
        try {
            $db->prepare("UPDATE call_requests SET share_token = ? WHERE id = ?")->execute([$token, $reqId]);
            return $token;
        } catch (PDOException $e) {
            error_log('call share_token generate error: ' . $e->getMessage());
            return '';
        }
    }
}

if (!function_exists('getCallTaskamBotId')) {
    function getCallTaskamBotId(PDO $db): int {
        static $cachedId = null;
        if ($cachedId !== null) return $cachedId;
        try {
            $stmt = $db->query("SELECT id FROM users WHERE mobile = '00000000000' LIMIT 1");
            $existing = (int)$stmt->fetchColumn();
            if ($existing > 0) { $cachedId = $existing; return $cachedId; }
            $stmt = $db->prepare("INSERT INTO users (mobile, first_name, last_name, password, status, role) VALUES ('00000000000', 'تسکام', '', ?, 'active', 'user')");
            $stmt->execute([password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT)]);
            $cachedId = (int)$db->lastInsertId();
            return $cachedId;
        } catch (PDOException $e) {
            error_log('call taskam bot error: ' . $e->getMessage());
            $cachedId = 0;
            return 0;
        }
    }
}

$userId = (int)$_SESSION['user_id'];
$page = 'call_requests';
$msg = '';
$msgType = 'success';

// ================================================================
// ⭐ تنظیمات صفحه‌بندی درخواست‌ها
// برای تغییر تعداد درخواست‌ها در هر صفحه، فقط این عدد را ویرایش کنید
// ================================================================
$CALLS_PER_PAGE = 10;

// ================== اطمینان از وجود ستون share_token ==================
if (empty($_SESSION['checked_call_share_col'])) {
    try {
        $colCheck = $db->query("SHOW COLUMNS FROM call_requests LIKE 'share_token'");
        if ($colCheck && $colCheck->rowCount() === 0) {
            $db->exec("ALTER TABLE call_requests ADD COLUMN share_token VARCHAR(64) DEFAULT NULL");
            try { $db->exec("ALTER TABLE call_requests ADD UNIQUE INDEX idx_call_share_token (share_token)"); } catch (PDOException $e2) {}
        }
        $_SESSION['checked_call_share_col'] = 1;
    } catch (PDOException $e) {
        error_log('call share_token check error: ' . $e->getMessage());
    }
}

// شمارش پیام‌های خوانده‌نشده
$sidebarUnread = 0;
try {
    $stmt = $db->prepare("SELECT COUNT(*) FROM colleague_messages WHERE receiver_id = ? AND is_read = 0");
    $stmt->execute([$userId]);
    $sidebarUnread = (int)$stmt->fetchColumn();
} catch (PDOException $e) {}

// ================== واکشی لیست همکاران ==================
$colleaguesList = [];
$colleaguesById = [];
try {
    $colleaguesListStmt = $db->prepare("
        SELECT u.id, u.first_name, u.last_name, u.mobile, u.avatar
        FROM colleagues c
        INNER JOIN users u ON u.id = c.colleague_user_id
        WHERE c.user_id = ?
          AND u.status = 'active'
          AND u.allow_colleague_requests = 1
          AND NOT EXISTS (
              SELECT 1 FROM colleague_blocks cb
              WHERE cb.blocker_user_id = u.id
                AND cb.blocked_user_id = c.user_id
          )
          AND NOT EXISTS (
              SELECT 1 FROM colleague_blocks cb
              WHERE cb.blocker_user_id = c.user_id
                AND cb.blocked_user_id = u.id
          )
        ORDER BY u.first_name ASC, u.last_name ASC
    ");
    $colleaguesListStmt->execute([$userId]);
    $colleaguesList = $colleaguesListStmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($colleaguesList as $c) {
        $colleaguesById[(int)$c['id']] = $c;
    }
} catch (PDOException $e) {
    $colleaguesList = [];
    $colleaguesById = [];
}

// ================== واکشی آواتار و نام کاربر جاری ==================
$currentUserAvatar = null;
$currentUserFirstName = 'خودم';
try {
    $curStmt = $db->prepare("SELECT first_name, avatar FROM users WHERE id = ? LIMIT 1");
    $curStmt->execute([$userId]);
    $curRow = $curStmt->fetch(PDO::FETCH_ASSOC);
    if ($curRow) {
        if (!empty($curRow['first_name'])) $currentUserFirstName = $curRow['first_name'];
        if (!empty($curRow['avatar']) && file_exists(__DIR__ . '/../uploads/avatars/' . $curRow['avatar'])) {
            $currentUserAvatar = '../uploads/avatars/' . $curRow['avatar'];
        }
    }
} catch (PDOException $e) {}

// ================== تابع بررسی دسترسی روی درخواست ==================
function checkCallPermission($db, $reqId, $userId, $action) {
    static $cache = [];
    $cacheKey = $reqId . '_' . $userId;

    if (!isset($cache[$cacheKey])) {
        $stmt = $db->prepare("SELECT * FROM call_requests WHERE id = ? AND (user_id = ? OR assignee_id = ?) LIMIT 1");
        $stmt->execute([$reqId, $userId, $userId]);
        $cache[$cacheKey] = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    $req = $cache[$cacheKey];
    if (!$req) {
        return ['allowed' => false, 'reason' => 'not_found', 'req' => null, 'is_owner' => false];
    }

    if ((int)$req['user_id'] === $userId) {
        return ['allowed' => true, 'reason' => 'owner', 'req' => $req, 'is_owner' => true];
    }

    $permStmt = $db->prepare("SELECT permissions FROM colleagues WHERE user_id = ? AND colleague_user_id = ? LIMIT 1");
    $permStmt->execute([(int)$req['user_id'], $userId]);
    $permRow = $permStmt->fetch(PDO::FETCH_ASSOC);

    if (!$permRow) {
        return ['allowed' => false, 'reason' => 'not_a_colleague', 'req' => $req, 'is_owner' => false];
    }

    $perms = json_decode($permRow['permissions'] ?? '[]', true);
    if (!is_array($perms)) $perms = [];

    if (in_array($action, $perms, true)) {
        return ['allowed' => true, 'reason' => 'permitted', 'req' => $req, 'is_owner' => false];
    }

    return ['allowed' => false, 'reason' => 'no_permission', 'req' => $req, 'is_owner' => false];
}

// ================== Endpoint: دریافت لینک اشتراکی درخواست ==================
if (isset($_GET['get_call_token'])) {
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');

    $reqId = (int)$_GET['get_call_token'];
    if ($reqId <= 0) { echo json_encode(['status'=>'error','message'=>'شناسه نامعتبر'], JSON_UNESCAPED_UNICODE); exit; }

    $chk = $db->prepare("SELECT id, user_id, assignee_id FROM call_requests WHERE id = ? AND (user_id = ? OR assignee_id = ?) LIMIT 1");
    $chk->execute([$reqId, $userId, $userId]);
    $row = $chk->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        echo json_encode(['status'=>'error','message'=>'دسترسی به این درخواست ندارید.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $token = ensureCallShareToken($db, $reqId);
    if ($token === '') {
        echo json_encode(['status'=>'error','message'=>'خطا در ساخت لینک'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode([
        'status' => 'success',
        'token'  => $token,
        'url'    => 'index.php?call=' . $token,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ================== ثبت درخواست تماس جدید ==================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_call_request'])) {
    $firstName = trim($_POST['first_name'] ?? '');
    $lastName  = trim($_POST['last_name'] ?? '');
    $mobile    = trim($_POST['mobile'] ?? '');
    $email     = trim($_POST['email'] ?? '');
    $store     = trim($_POST['store'] ?? '');
    $website   = trim($_POST['website'] ?? '');
    $description = trim($_POST['description'] ?? '');

    $assigneeId = !empty($_POST['assignee_id']) ? (int)$_POST['assignee_id'] : $userId;
    if (!(($assigneeId === $userId) || isset($colleaguesById[$assigneeId]))) {
        $assigneeId = $userId;
    }

    if (!empty($firstName) && !empty($lastName) && !empty($mobile)) {
        $stmt = $db->prepare("
            INSERT INTO call_requests (user_id, assignee_id, first_name, last_name, mobile, email, store, website, description)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$userId, $assigneeId, $firstName, $lastName, $mobile, $email ?: null, $store ?: null, $website ?: null, $description ?: null]);
        $newReqId = (int)$db->lastInsertId();

        if ($newReqId > 0) {
            ensureCallShareToken($db, $newReqId);
        }

        if ($newReqId > 0 && $assigneeId > 0 && $assigneeId !== $userId) {
            try {
                $taskamId = getCallTaskamBotId($db);
                if ($taskamId > 0) {
                    $tokenStmt = $db->prepare("SELECT share_token FROM call_requests WHERE id = ? LIMIT 1");
                    $tokenStmt->execute([$newReqId]);
                    $shareToken = (string)$tokenStmt->fetchColumn();
                    if ($shareToken === '') {
                        $shareToken = ensureCallShareToken($db, $newReqId);
                    }

                    $creatorName = 'کاربر';
                    $creatorStmt = $db->prepare("SELECT first_name, last_name, mobile FROM users WHERE id = ? LIMIT 1");
                    $creatorStmt->execute([$userId]);
                    $creatorRow = $creatorStmt->fetch(PDO::FETCH_ASSOC);
                    if ($creatorRow) {
                        $creatorName = trim(($creatorRow['first_name'] ?? '') . ' ' . ($creatorRow['last_name'] ?? ''));
                        if ($creatorName === '') $creatorName = $creatorRow['mobile'] ?? 'کاربر';
                    }

                    $lines = [];
                    $lines[] = "📞 درخواست تماس جدید برای شما";
                    $lines[] = "";
                    $lines[] = "نام مشتری: {$firstName} {$lastName}";
                    $lines[] = "تلفن: {$mobile}";
                    if (!empty($email))   $lines[] = "ایمیل: {$email}";
                    if (!empty($store))   $lines[] = "فروشگاه: {$store}";
                    if (!empty($website)) $lines[] = "سایت: {$website}";
                    $lines[] = "ثبت‌کننده: {$creatorName}";
                    if (!empty($description)) {
                        $descPreview = mb_substr(trim($description), 0, 200, 'UTF-8');
                        $lines[] = "";
                        $lines[] = "توضیحات: {$descPreview}";
                    }

                    $notifyMsg = implode("\n", $lines) . "\n\n[[call:{$shareToken}]]";

                    $insNotify = $db->prepare("INSERT INTO colleague_messages (sender_id, receiver_id, message, is_read) VALUES (?, ?, ?, 0)");
                    $insNotify->execute([$taskamId, $assigneeId, $notifyMsg]);
                }
            } catch (PDOException $e) {
                error_log('new call notify error: ' . $e->getMessage());
            }
        }

        $msg = 'درخواست تماس با موفقیت ثبت شد.';

        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['status' => 'success', 'message' => $msg]);
            exit;
        }
    } else {
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['status' => 'error', 'message' => 'لطفاً فیلدهای ستاره‌دار را تکمیل کنید.']);
            exit;
        }
        $msg = 'لطفاً فیلدهای ستاره‌دار را تکمیل کنید.';
        $msgType = 'warning';
    }
}

// ================== ویرایش درخواست ==================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_call_request'])) {
    $reqId = (int)($_POST['request_id'] ?? 0);
    $firstName = trim($_POST['first_name'] ?? '');
    $lastName  = trim($_POST['last_name'] ?? '');
    $mobile    = trim($_POST['mobile'] ?? '');
    $email     = trim($_POST['email'] ?? '');
    $store     = trim($_POST['store'] ?? '');
    $website   = trim($_POST['website'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $assigneeId = !empty($_POST['assignee_id']) ? (int)$_POST['assignee_id'] : $userId;

    if (!(($assigneeId === $userId) || isset($colleaguesById[$assigneeId]))) {
        $assigneeId = $userId;
    }

    $perm = checkCallPermission($db, $reqId, $userId, 'edit');
    if (!$perm['allowed']) {
        $msg = 'شما دسترسی ویرایش این درخواست را ندارید.';
        $msgType = 'danger';
    } else {
        $oldAssignee = (int)$perm['req']['assignee_id'];
        $canProceed = true;

        if (!$perm['is_owner'] && $assigneeId !== $oldAssignee) {
            $reassignPerm = checkCallPermission($db, $reqId, $userId, 'reassign');
            if (!$reassignPerm['allowed']) {
                $msg = 'شما دسترسی تغییر مسئول این درخواست را ندارید.';
                $msgType = 'danger';
                $canProceed = false;
            }
        }

        if ($canProceed) {
            if (!empty($firstName) && !empty($lastName) && !empty($mobile)) {
                $stmt = $db->prepare("
                    UPDATE call_requests
                    SET assignee_id = ?, first_name = ?, last_name = ?, mobile = ?, email = ?, store = ?, website = ?, description = ?
                    WHERE id = ?
                ");
                $stmt->execute([$assigneeId, $firstName, $lastName, $mobile, $email ?: null, $store ?: null, $website ?: null, $description ?: null, $reqId]);
                ensureCallShareToken($db, $reqId);
                $msg = 'درخواست با موفقیت ویرایش شد.';
            } else {
                $msg = 'لطفاً فیلدهای ستاره‌دار را تکمیل کنید.';
                $msgType = 'warning';
            }
        }
    }
}

// ================== تکمیل درخواست (AJAX) ==================
if (isset($_GET['complete']) && isset($_GET['id'])) {
    header('Content-Type: application/json; charset=utf-8');
    $reqId = (int)$_GET['id'];

    $perm = checkCallPermission($db, $reqId, $userId, 'complete');

    if (!$perm['allowed']) {
        echo json_encode(['status' => 'error', 'message' => 'شما دسترسی تکمیل این درخواست را ندارید.', 'affected' => 0]);
        exit;
    }

    $stmt = $db->prepare("UPDATE call_requests SET status = 1, completed_at = NOW() WHERE id = ? AND status = 0");
    $stmt->execute([$reqId]);

    echo json_encode(['status' => 'success', 'affected' => $stmt->rowCount()]);
    exit;
}

// ================== حذف درخواست ==================
if (isset($_GET['delete'])) {
    $reqId = (int)$_GET['delete'];
    $perm = checkCallPermission($db, $reqId, $userId, 'delete');
    if ($perm['allowed']) {
        $db->prepare("DELETE FROM call_requests WHERE id = ?")->execute([$reqId]);
    }
    header('Location: index.php');
    exit;
}

// ================== ⭐ پردازش لینک اشتراکی درخواست ==================
$sharedRequestData = null;
$sharedCanEdit = false;
$sharedCanReassign = false;

$sharedCallParam = trim((string)($_GET['call'] ?? ''));
if ($sharedCallParam !== '' && preg_match('/^[a-f0-9]{16,64}$/i', $sharedCallParam)) {
    try {
        $sharedStmt = $db->prepare("
            SELECT r.*,
                   ua.first_name AS assignee_first_name,
                   ua.last_name AS assignee_last_name,
                   ua.mobile AS assignee_mobile,
                   uc.first_name AS creator_first_name,
                   uc.last_name AS creator_last_name,
                   uc.mobile AS creator_mobile
            FROM call_requests r
            LEFT JOIN users ua ON ua.id = r.assignee_id
            LEFT JOIN users uc ON uc.id = r.user_id
            WHERE r.share_token = ?
            LIMIT 1
        ");
        $sharedStmt->execute([$sharedCallParam]);
        $sharedReq = $sharedStmt->fetch(PDO::FETCH_ASSOC);

        if ($sharedReq) {
            $isCreator  = ((int)$sharedReq['user_id'] === $userId);
            $isAssignee = ((int)$sharedReq['assignee_id'] === $userId);

            if ($isCreator || $isAssignee) {
                $sharedRequestData = $sharedReq;

                if ($isCreator) {
                    $sharedCanEdit = true;
                    $sharedCanReassign = true;
                } else {
                    $permStmt = $db->prepare("SELECT permissions FROM colleagues WHERE user_id = ? AND colleague_user_id = ? LIMIT 1");
                    $permStmt->execute([(int)$sharedReq['user_id'], $userId]);
                    $permRow = $permStmt->fetch(PDO::FETCH_ASSOC);
                    if ($permRow) {
                        $perms = json_decode($permRow['permissions'] ?? '[]', true);
                        if (is_array($perms)) {
                            $sharedCanEdit = in_array('edit', $perms, true);
                            $sharedCanReassign = in_array('reassign', $perms, true);
                        }
                    }
                    if (!$sharedCanEdit && !$sharedCanReassign) {
                        $sharedCanEdit = false;
                        $sharedCanReassign = false;
                    }
                }
            } else {
                $_SESSION['call_flash_msg'] = 'شما به این درخواست تماس دسترسی ندارید.';
                $_SESSION['call_flash_type'] = 'danger';
                header('Location: index.php');
                exit;
            }
        } else {
            $_SESSION['call_flash_msg'] = 'درخواست تماس یافت نشد یا لینک نامعتبر است.';
            $_SESSION['call_flash_type'] = 'danger';
            header('Location: index.php');
            exit;
        }
    } catch (PDOException $e) {
        error_log('shared call load error: ' . $e->getMessage());
    }
}

// ================== واکشی همه درخواست‌ها ==================
$newRequests = [];
$doneRequests = [];
try {
    $stmt = $db->prepare("
        SELECT
            r.*,
            ua.first_name AS assignee_first_name,
            ua.last_name AS assignee_last_name,
            ua.mobile AS assignee_mobile,
            uc.first_name AS creator_first_name,
            uc.last_name AS creator_last_name,
            uc.mobile AS creator_mobile,
            cc.permissions AS my_req_permissions
        FROM call_requests r
        LEFT JOIN users ua ON ua.id = r.assignee_id
        LEFT JOIN users uc ON uc.id = r.user_id
        LEFT JOIN colleagues cc ON cc.user_id = r.user_id AND cc.colleague_user_id = ?
        WHERE (r.user_id = ? OR r.assignee_id = ?)
        ORDER BY r.status ASC, r.created_at DESC
    ");
    $stmt->execute([$userId, $userId, $userId]);
    $allRequests = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($allRequests as $r) {
        if ((int)$r['status'] === 0) {
            $newRequests[] = $r;
        } else {
            $doneRequests[] = $r;
        }
    }
} catch (PDOException $e) {}

// ================================================================
// ⭐ صفحه‌بندی — تقسیم به جدید و انجام‌شده
// ================================================================
$activeTab = $_GET['tab'] ?? 'new';
if (!in_array($activeTab, ['new', 'done'], true)) $activeTab = 'new';

$pageNew  = max(1, (int)($_GET['p_n'] ?? 1));
$pageDone = max(1, (int)($_GET['p_d'] ?? 1));

$totalNew  = count($newRequests);
$totalDone = count($doneRequests);

$totalPagesNew  = max(1, (int)ceil($totalNew  / $CALLS_PER_PAGE));
$totalPagesDone = max(1, (int)ceil($totalDone / $CALLS_PER_PAGE));

if ($pageNew  > $totalPagesNew)  $pageNew  = $totalPagesNew;
if ($pageDone > $totalPagesDone) $pageDone = $totalPagesDone;

$newRequestsPaged  = array_slice($newRequests,  ($pageNew  - 1) * $CALLS_PER_PAGE, $CALLS_PER_PAGE);
$doneRequestsPaged = array_slice($doneRequests, ($pageDone - 1) * $CALLS_PER_PAGE, $CALLS_PER_PAGE);

// ================================================================
// ⭐ تابع کمکی ساخت HTML صفحه‌بندی
// ================================================================
if (!function_exists('renderCallsPagination')) {
    function renderCallsPagination(int $currentPage, int $totalPages, int $totalCount, string $paramKey, string $activeTab, bool $hasSharedParam = false): string {
        if ($totalPages <= 1) return '';

        $buildLink = function(int $page) use ($paramKey, $activeTab, $hasSharedParam) {
            $params = [];
            $params['tab'] = $activeTab;
            $params[$paramKey] = $page;
            if ($hasSharedParam && isset($_GET['call'])) {
                $params['call'] = $_GET['call'];
            }
            return '?' . http_build_query($params);
        };

        $html = '<div class="calls-pagination-inner">';

        // دکمه قبلی (سمت راست در RTL)
        if ($currentPage > 1) {
            $html .= '<a class="pag-btn" href="' . htmlspecialchars($buildLink($currentPage - 1), ENT_QUOTES, 'UTF-8') . '" title="صفحه قبلی"><i class="fas fa-chevron-right"></i></a>';
        } else {
            $html .= '<span class="pag-btn disabled"><i class="fas fa-chevron-right"></i></span>';
        }

        // شماره صفحات
        $pages = [1];
        for ($i = $currentPage - 1; $i <= $currentPage + 1; $i++) {
            if ($i > 1 && $i < $totalPages) $pages[] = $i;
        }
        if ($totalPages > 1) $pages[] = $totalPages;
        $pages = array_values(array_unique($pages));
        sort($pages);

        $prev = 0;
        foreach ($pages as $p) {
            if ($p > $prev + 1) {
                $html .= '<span class="pag-dots">…</span>';
            }
            if ($p === $currentPage) {
                $html .= '<span class="pag-btn active">' . $p . '</span>';
            } else {
                $html .= '<a class="pag-btn" href="' . htmlspecialchars($buildLink($p), ENT_QUOTES, 'UTF-8') . '">' . $p . '</a>';
            }
            $prev = $p;
        }

        // دکمه بعدی (سمت چپ در RTL)
        if ($currentPage < $totalPages) {
            $html .= '<a class="pag-btn" href="' . htmlspecialchars($buildLink($currentPage + 1), ENT_QUOTES, 'UTF-8') . '" title="صفحه بعدی"><i class="fas fa-chevron-left"></i></a>';
        } else {
            $html .= '<span class="pag-btn disabled"><i class="fas fa-chevron-left"></i></span>';
        }

        $html .= '<span class="pag-info">صفحه ' . $currentPage . ' از ' . $totalPages . ' (کل: ' . $totalCount . ')</span>';
        $html .= '</div>';
        return $html;
    }
}

$displayUser = $_SESSION['user_mobile'] ?? $_SESSION['mobile'] ?? $_SESSION['username'] ?? 'کاربر';

$makeAvatarUrl = function($avatarFile) {
    if (!empty($avatarFile) && file_exists(__DIR__ . '/../uploads/avatars/' . $avatarFile)) {
        return '../uploads/avatars/' . $avatarFile;
    }
    return null;
};

$assigneeOptionsJson = json_encode([
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
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);

$callSharedDataJson = $sharedRequestData ? json_encode($sharedRequestData, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) : 'null';
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <title>درخواست‌های تماس</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="icon" href="https://img.icons8.com/color/48/dashboard-layout.png" type="image/png">
<style>
    /* =========================================================
       ROOT
       ========================================================= */
    :root {
        --primary: #1e293b;
        --accent: #2563eb;
        --accent-hover: #1d4ed8;
        --bg: #f8fafc;
        --card: #ffffff;
        --text: #334155;
        --border: #e2e8f0;
        --success: #10b981;
        --warning: #f59e0b;
        --danger: #ef4444;
    }
    * { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
    html, body { max-width: 100%; overflow-x: hidden; }

    body {
        background: var(--bg);
        color: var(--text);
        font-family: Tahoma, sans-serif;
        margin: 0;
        display: flex;
        height: 100vh;
        height: 100dvh;
        overflow: hidden;
        -webkit-font-smoothing: antialiased;
    }

    /* =========================================================
       ⭐ SIDEBAR — رنگ آبی مثل صفحه تسک + مخفی اسکرول + قفل ارتفاع
       ========================================================= */
    .sidebar {
        background: linear-gradient(180deg, #4c8bf5 0%, #2f6bdc 100%) !important;
        color: #fff !important;
        box-shadow: -6px 0 28px rgba(76, 139, 245, 0.18);
        overflow: visible !important;
        scrollbar-width: none !important;
        -ms-overflow-style: none !important;
    }
    .sidebar::-webkit-scrollbar { display: none !important; width: 0 !important; height: 0 !important; }
    .sidebar::-webkit-scrollbar-track,
    .sidebar::-webkit-scrollbar-thumb { display: none !important; background: transparent !important; }

    body > .sidebar,
    body .sidebar {
        height: 100vh !important;
        height: 100dvh !important;
        max-height: 100vh !important;
        max-height: 100dvh !important;
        align-self: flex-start !important;
    }

    .sidebar > nav,
    .sidebar > ul,
    .sidebar > .sidebar-menu,
    .sidebar > .sidebar-nav,
    .sidebar > .menu-wrapper,
    .sidebar > div[class*="nav"],
    .sidebar > div[class*="menu"] {
        overflow-y: auto !important;
        overflow-x: visible !important;
        scrollbar-width: none !important;
        -ms-overflow-style: none !important;
    }
    .sidebar > nav::-webkit-scrollbar,
    .sidebar > ul::-webkit-scrollbar,
    .sidebar > .sidebar-menu::-webkit-scrollbar,
    .sidebar > .sidebar-nav::-webkit-scrollbar,
    .sidebar > .menu-wrapper::-webkit-scrollbar,
    .sidebar > div[class*="nav"]::-webkit-scrollbar,
    .sidebar > div[class*="menu"]::-webkit-scrollbar {
        display: none !important;
        width: 0 !important;
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

    /* =========================================================
       OVERLAY
       ========================================================= */
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

    /* =========================================================
       MAIN WRAPPER
       ========================================================= */
    .main-wrapper {
        flex: 1;
        display: flex;
        flex-direction: column;
        overflow: hidden;
        min-width: 0;
    }

    .topbar {
        background: var(--card);
        padding: 15px clamp(16px, 2vw, 30px);
        border-bottom: 1px solid var(--border);
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 12px;
        flex-shrink: 0;
    }
    .topbar h1 {
        margin: 0;
        font-size: clamp(1rem, 1.4vw, 1.2rem);
        color: var(--primary);
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .topbar-user {
        font-size: 0.9rem;
        font-weight: bold;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        max-width: 220px;
        flex-shrink: 0;
    }

    .content-area {
        flex: 1;
        padding: clamp(12px, 2vw, 25px);
        overflow-y: auto;
        -webkit-overflow-scrolling: touch;
    }

    /* =========================================================
       ANALYTICS
       ========================================================= */
    .analytics-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
        gap: 16px;
        margin-bottom: 25px;
    }
    .analytic-card {
        background: var(--card);
        border: 1px solid var(--border);
        padding: 20px;
        border-radius: 16px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        min-width: 0;
        transition: border-color 0.15s ease, box-shadow 0.15s ease;
    }
    .analytic-card:hover { border-color: #cbd5e1; box-shadow: 0 4px 12px rgba(0,0,0,0.04); }
    .analytic-card .info { min-width: 0; }
    .analytic-card .info div:first-child { font-size: 0.8rem; color: #64748b; margin-bottom: 5px; }
    .analytic-card .info div:last-child { font-size: 1.4rem; font-weight: bold; color: var(--primary); }
    .analytic-icon {
        width: 48px; height: 48px;
        border-radius: 12px;
        display: flex; align-items: center; justify-content: center;
        background: #e0f2fe; color: #0284c7;
        flex-shrink: 0;
    }

    /* =========================================================
       CARD
       ========================================================= */
    .card {
        background: var(--card);
        border: 1px solid var(--border);
        border-radius: 20px;
        padding: clamp(14px, 2vw, 24px);
        margin-bottom: 20px;
    }

    /* =========================================================
       FORM
       ========================================================= */
    .form-group { margin-bottom: 18px; }
    label {
        display: block;
        margin-bottom: 8px;
        font-weight: 600;
        font-size: 0.9rem;
        color: #475569;
        letter-spacing: -0.2px;
    }
    label .required-star { color: var(--danger); margin-right: 3px; }

    input[type="text"],
    input[type="email"],
    input[type="tel"],
    input[type="url"],
    input[type="date"],
    select,
    textarea {
        width: 100%;
        padding: 12px 18px;
        background: #f8fafc;
        border: 2px solid #e2e8f0;
        border-radius: 14px;
        font-size: 0.95rem;
        font-family: Tahoma, sans-serif;
        color: #1e293b;
        transition: border-color 0.2s ease, box-shadow 0.2s ease, background-color 0.2s ease;
    }
    textarea { resize: vertical; min-height: 80px; line-height: 1.7; }
    input:focus, select:focus, textarea:focus {
        outline: none;
        border-color: var(--accent);
        background: #ffffff;
        box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.12);
    }

    button.btn {
        background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
        color: #fff;
        border: none;
        padding: 11px 22px;
        border-radius: 12px;
        font-weight: bold;
        cursor: pointer;
        transition: background-color 0.2s ease, transform 0.15s ease;
        font-family: Tahoma, sans-serif;
        font-size: 0.9rem;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
    }
    button.btn:hover { background: linear-gradient(135deg, #1d4ed8 0%, #1e40af 100%); }
    button.btn:active { transform: scale(0.98); }

    /* =========================================================
       TABS
       ========================================================= */
    .tabs-header {
        display: flex;
        gap: 10px;
        border-bottom: 2px solid #f1f5f9;
        margin-bottom: 20px;
        padding-bottom: 12px;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
    }
    .tabs-header > div:first-child { display: flex; gap: 10px; }
    .tab-btn {
        background: #f1f5f9;
        border: none;
        padding: 9px 18px;
        font-weight: bold;
        font-size: 0.9rem;
        cursor: pointer;
        color: #64748b;
        border-radius: 12px;
        transition: background-color 0.15s ease, color 0.15s ease;
        font-family: Tahoma, sans-serif;
        white-space: nowrap;
    }
    .tab-btn.active { background: var(--accent); color: #fff; }
    .tab-content { display: none; }
    .tab-content.active { display: block; }

    /* =========================================================
       REQUEST ROW
       ========================================================= */
    .request-row {
        position: relative;
        display: flex;
        align-items: center;
        justify-content: space-between;
        background: #ffffff;
        border: 1px solid #e2e8f0;
        padding: 16px 20px;
        border-radius: 16px;
        margin-bottom: 12px;
        transition: border-color 0.15s ease, box-shadow 0.15s ease, background-color 0.15s ease;
        contain: layout style;
        cursor: pointer;
    }
    .request-row:hover { border-color: #cbd5e1; box-shadow: 0 4px 12px rgba(0, 0, 0, 0.04); }
    .request-row.no-edit { cursor: default; }
    .request-row.no-edit:hover { border-color: #e2e8f0; box-shadow: none; }
    .request-row.animating { opacity: 0.4; }
    .request-row.completed-row { background: #f8fafc; }
    .request-row.completed-row .request-name { text-decoration: line-through; color: #94a3b8; }

    .request-row.received-task,
    .request-row.assigned-task { padding-right: 26px; }
    .request-row.received-task { background: #fffdf5; }
    .request-row.assigned-task { background: #fafaff; }

    .task-color-bar {
        position: absolute;
        top: 14px; bottom: 14px; right: 0;
        width: 6px;
        border-radius: 6px 0 0 6px;
        z-index: 3;
        cursor: help;
        transition: width 0.15s ease;
    }
    .task-color-bar.bar-received { background: linear-gradient(180deg, #f59e0b, #d97706); }
    .task-color-bar.bar-sent { background: linear-gradient(180deg, #6366f1, #4f46e5); }
    .task-color-bar:hover { width: 8px; }

    .task-tooltip {
        position: absolute;
        top: 50%; right: 22px;
        transform: translateY(-50%) translateX(8px);
        background: #1e293b; color: #fff;
        padding: 10px 14px; border-radius: 12px;
        font-size: 0.78rem; white-space: nowrap;
        opacity: 0; pointer-events: none;
        transition: opacity 0.15s ease, transform 0.15s ease;
        z-index: 100;
        display: flex; align-items: center; gap: 10px; line-height: 1.5;
    }
    .task-tooltip::after {
        content: '';
        position: absolute;
        top: 50%; left: 100%;
        transform: translateY(-50%);
        border: 7px solid transparent;
        border-left-color: #1e293b;
    }
    .task-color-bar:hover ~ .task-tooltip { opacity: 1; transform: translateY(-50%) translateX(0); }
    .task-tooltip .tip-icon {
        width: 26px; height: 26px; border-radius: 8px;
        display: flex; align-items: center; justify-content: center;
        font-size: 0.72rem; flex-shrink: 0;
    }
    .task-tooltip.tip-received .tip-icon { background: rgba(245, 158, 11, 0.25); color: #fbbf24; }
    .task-tooltip.tip-sent .tip-icon { background: rgba(99, 102, 241, 0.25); color: #a5b4fc; }
    .task-tooltip .tip-label { color: #94a3b8; font-size: 0.7rem; margin-bottom: 2px; }
    .task-tooltip .tip-name { color: #fff; font-weight: bold; }
    .task-tooltip .tip-mobile { color: #94a3b8; font-size: 0.7rem; direction: ltr; display: inline-block; margin-right: 5px; }

    .request-info { display: flex; align-items: flex-start; gap: 16px; flex: 1; min-width: 0; }
    .custom-checkbox {
        width: 22px; height: 22px;
        cursor: pointer;
        accent-color: var(--success);
        border-radius: 6px;
        flex-shrink: 0;
        margin-top: 3px;
    }
    .custom-checkbox:disabled { cursor: not-allowed; opacity: 0.5; }
    .request-name { font-weight: bold; font-size: 0.95rem; color: #1e293b; word-break: break-word; }
    .request-meta {
        font-size: 0.78rem; color: #64748b; margin-top: 4px;
        display: flex; flex-wrap: wrap; gap: 10px;
    }
    .request-meta span { display: inline-flex; align-items: center; gap: 4px; word-break: break-all; }
    .request-meta i { color: #94a3b8; }

    .request-description {
        margin-top: 8px;
        font-size: 0.8rem;
        color: #475569;
        background: #f8fafc;
        padding: 8px 12px;
        border-radius: 10px;
        border-right: 3px solid #cbd5e1;
        line-height: 1.7;
        word-break: break-word;
        white-space: pre-wrap;
    }

    .request-actions { display: flex; gap: 8px; align-items: center; flex-shrink: 0; }

    .action-link-delete {
        background: #fef2f2;
        color: var(--danger);
        text-decoration: none;
        font-size: 0.9rem;
        font-weight: 600;
        padding: 0;
        width: 36px; height: 36px;
        display: inline-flex; align-items: center; justify-content: center;
        border-radius: 10px;
        border: 1px solid #fee2e2;
        transition: background-color 0.2s ease, color 0.2s ease, transform 0.15s ease;
        flex-shrink: 0;
        cursor: pointer;
    }
    .action-link-delete:hover { background: var(--danger); color: #fff; border-color: var(--danger); transform: translateY(-1px); }
    .action-link-delete:active { transform: scale(0.95); }

    /* =========================================================
       ⭐ PAGINATION
       ========================================================= */
    .calls-pagination {
        display: flex;
        justify-content: center;
        margin-top: 22px;
        margin-bottom: 8px;
    }
    .calls-pagination-inner {
        display: flex;
        gap: 6px;
        align-items: center;
        flex-wrap: wrap;
        padding: 8px 14px;
        background: #ffffff;
        border-radius: 14px;
        border: 1px solid var(--border);
        box-shadow: 0 4px 16px rgba(0, 0, 0, 0.03);
    }
    .pag-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 38px;
        height: 38px;
        padding: 0 10px;
        border-radius: 10px;
        background: #f1f5f9;
        color: #334155;
        font-weight: bold;
        font-size: 0.85rem;
        text-decoration: none;
        border: 1px solid var(--border);
        transition: all 0.15s ease;
        font-family: Tahoma, sans-serif;
        cursor: pointer;
    }
    .pag-btn:hover {
        background: #e0f2fe;
        color: var(--accent);
        border-color: #bfdbfe;
    }
    .pag-btn.active {
        background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
        color: #fff;
        border-color: var(--accent);
        box-shadow: 0 4px 12px rgba(37, 99, 235, 0.25);
    }
    .pag-btn.disabled {
        opacity: 0.4;
        cursor: not-allowed;
        pointer-events: none;
    }
    .pag-dots {
        color: #94a3b8;
        padding: 0 6px;
        font-weight: bold;
        user-select: none;
    }
    .pag-info {
        color: #64748b;
        font-size: 0.72rem;
        font-weight: 600;
        padding: 0 8px;
    }

    /* =========================================================
       MODAL
       ========================================================= */
    .modal-overlay {
        position: fixed;
        top: 0; left: 0;
        width: 100%; height: 100%;
        background: rgba(15, 23, 42, 0.7);
        display: none;
        align-items: center;
        justify-content: center;
        z-index: 1000;
        padding: 15px;
        overflow-y: auto;
        -webkit-overflow-scrolling: touch;
    }
    .modal-overlay.active { display: flex; }
    .modal-box {
        background: #ffffff;
        width: 100%;
        max-width: 520px;
        border-radius: 24px;
        padding: 30px;
        max-height: 90vh;
        max-height: 90dvh;
        overflow-y: auto;
        -webkit-overflow-scrolling: touch;
    }
    .modal-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 20px;
        border-bottom: 1px solid #f1f5f9;
        padding-bottom: 14px;
        gap: 10px;
    }
    .modal-header h3 { margin: 0; font-size: 1.15rem; color: var(--primary); }
    .modal-header-actions { display: flex; align-items: center; gap: 8px; margin-right: auto; }
    .modal-close {
        background: #f1f5f9;
        border: none;
        width: 32px; height: 32px;
        border-radius: 50%;
        display: flex; align-items: center; justify-content: center;
        font-size: 1.2rem;
        cursor: pointer;
        color: #64748b;
        font-weight: bold;
        transition: background-color 0.15s ease;
        flex-shrink: 0;
    }
    .modal-close:hover { background: #e2e8f0; color: #1e293b; }

    .modal-header-share {
        width: 34px; height: 34px;
        border-radius: 10px;
        background: #eff6ff;
        border: 1px solid #bfdbfe;
        color: var(--accent);
        cursor: pointer;
        display: inline-flex; align-items: center; justify-content: center;
        font-size: 0.85rem;
        transition: all 0.2s ease;
        font-family: Tahoma, sans-serif;
        padding: 0;
        flex-shrink: 0;
    }
    .modal-header-share:hover {
        background: var(--accent);
        color: #fff;
        border-color: var(--accent);
        transform: translateY(-1px);
        box-shadow: 0 4px 12px rgba(37, 99, 235, 0.35);
    }
    .modal-header-share:active { transform: translateY(0) scale(0.96); }
    .modal-header-share.copied { background: #d1fae5; color: #065f46; border-color: #a7f3d0; }

    /* =========================================================
       EMPTY STATE
       ========================================================= */
    .empty-state { text-align: center; color: #94a3b8; padding: 40px 20px; font-size: 0.9rem; }
    .empty-state i { font-size: 2.5rem; color: #cbd5e1; display: block; margin-bottom: 12px; }

    /* =========================================================
       ASSIGNEE PICKER
       ========================================================= */
    .assignee-picker { position: relative; width: 100%; user-select: none; }
    .assignee-picker__trigger {
        display: flex; align-items: center; gap: 12px;
        padding: 10px 14px;
        background: #ffffff;
        border: 2px solid #e2e8f0;
        border-radius: 16px;
        cursor: pointer;
        transition: border-color 0.2s ease, box-shadow 0.2s ease;
        min-height: 62px;
    }
    .assignee-picker__trigger:hover { border-color: #cbd5e1; }
    .assignee-picker.open .assignee-picker__trigger { border-color: var(--accent); box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.12); }

    .assignee-picker__avatar {
        width: 42px; height: 42px;
        border-radius: 50%;
        background: linear-gradient(135deg, #2563eb, #1d4ed8);
        color: #fff;
        display: flex; align-items: center; justify-content: center;
        font-weight: bold; font-size: 1rem;
        flex-shrink: 0;
        overflow: hidden;
    }
    .assignee-picker__avatar.is-self { background: linear-gradient(135deg, #10b981, #059669); }
    .assignee-picker__avatar img { width: 100%; height: 100%; object-fit: cover; display: block; }

    .assignee-picker__info { flex: 1; min-width: 0; }
    .assignee-picker__name { font-weight: bold; font-size: 0.92rem; color: #1e293b; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .assignee-picker__mobile { font-size: 0.75rem; color: #64748b; direction: ltr; text-align: right; margin-top: 2px; }

    .assignee-picker__chevron { color: #94a3b8; font-size: 0.9rem; transition: transform 0.2s ease; flex-shrink: 0; }
    .assignee-picker.open .assignee-picker__chevron { transform: rotate(180deg); color: var(--accent); }

    .assignee-picker__dropdown {
        position: absolute;
        top: calc(100% + 8px);
        right: 0; left: 0;
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 18px;
        padding: 8px;
        max-height: 320px;
        overflow-y: auto;
        z-index: 200;
        opacity: 0;
        visibility: hidden;
        transform: translateY(-8px);
        transition: opacity 0.15s ease, transform 0.15s ease, visibility 0.15s;
    }
    .assignee-picker.open .assignee-picker__dropdown { opacity: 1; visibility: visible; transform: translateY(0); }
    .assignee-picker__dropdown::-webkit-scrollbar { width: 6px; }
    .assignee-picker__dropdown::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 3px; }

    .assignee-picker__section-label { font-size: 0.7rem; font-weight: bold; color: #94a3b8; padding: 8px 12px 6px; }
    .assignee-picker__section-label:not(:first-child) { border-top: 1px solid #f1f5f9; margin-top: 4px; padding-top: 12px; }

    .assignee-picker__option {
        display: flex; align-items: center; gap: 12px;
        padding: 10px 12px;
        border-radius: 12px;
        cursor: pointer;
        transition: background-color 0.15s ease;
        position: relative;
    }
    .assignee-picker__option:hover { background: #f1f5f9; }
    .assignee-picker__option.selected { background: linear-gradient(135deg, rgba(37, 99, 235, 0.1), rgba(37, 99, 235, 0.05)); }
    .assignee-picker__option.selected::after {
        content: '\f00c';
        font-family: 'Font Awesome 6 Free';
        font-weight: 900;
        position: absolute;
        left: 14px;
        color: var(--accent);
        font-size: 0.85rem;
    }

    .assignee-picker__option-avatar {
        width: 38px; height: 38px;
        border-radius: 50%;
        background: linear-gradient(135deg, #6366f1, #4f46e5);
        color: #fff;
        display: flex; align-items: center; justify-content: center;
        font-weight: bold; font-size: 0.9rem;
        flex-shrink: 0;
        overflow: hidden;
    }
    .assignee-picker__option-avatar.is-self { background: linear-gradient(135deg, #10b981, #059669); }
    .assignee-picker__option-avatar img { width: 100%; height: 100%; object-fit: cover; display: block; }

    .assignee-picker__option-info { flex: 1; min-width: 0; }
    .assignee-picker__option-name { font-weight: 600; font-size: 0.88rem; color: #1e293b; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .assignee-picker__option-mobile { font-size: 0.72rem; color: #94a3b8; direction: ltr; text-align: right; margin-top: 2px; }

    .assignee-picker__empty { padding: 20px 16px; text-align: center; font-size: 0.82rem; color: #94a3b8; }
    .assignee-picker__empty a { color: var(--accent); text-decoration: none; font-weight: bold; display: inline-flex; align-items: center; gap: 5px; margin-top: 8px; }

    .assignee-picker.is-disabled { pointer-events: none; opacity: 0.65; }

    /* =========================================================
       HAMBURGER
       ========================================================= */
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
        transition: transform 0.15s ease, box-shadow 0.2s ease;
        box-shadow: 0 6px 16px rgba(76, 139, 245, 0.25);
    }
    .hamburger-btn:hover { transform: translateY(-1px); box-shadow: 0 8px 20px rgba(76, 139, 245, 0.35); }
    .hamburger-btn:active { transform: scale(0.95); }
    .hamburger-btn .hamburger-lines {
        width: 22px; height: 16px;
        display: flex; flex-direction: column; justify-content: space-between;
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

    /* =========================================================
       TOAST
       ========================================================= */
    .app-toast {
        position: fixed;
        bottom: 30px;
        left: 50%;
        transform: translateX(-50%) translateY(80px);
        background: linear-gradient(135deg, #1e293b, #0f172a);
        color: #fff;
        padding: 12px 22px;
        border-radius: 30px;
        font-size: 0.85rem;
        font-weight: 700;
        display: flex;
        align-items: center;
        gap: 10px;
        box-shadow: 0 12px 32px rgba(15, 23, 42, 0.4);
        z-index: 99999;
        opacity: 0;
        transition: all 0.35s cubic-bezier(0.34, 1.56, 0.64, 1);
        pointer-events: none;
        max-width: 90vw;
        font-family: Tahoma, sans-serif;
        direction: rtl;
    }
    .app-toast.active { opacity: 1; transform: translateX(-50%) translateY(0); }
    .app-toast i { font-size: 1rem; }
    .app-toast.toast-success i { color: #34d399; }
    .app-toast.toast-error i { color: #f87171; }
    .app-toast.toast-info i { color: #60a5fa; }

    /* =========================================================
       BREAKPOINT: 1100px
       ========================================================= */
    @media (max-width: 1100px) {
        .analytics-grid { grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); }
        .task-tooltip { white-space: normal; max-width: 220px; }
    }

    /* =========================================================
       📱 BREAKPOINT: 900px
       ========================================================= */
    @media (max-width: 900px) {
        .hamburger-btn { display: flex; }

        body .sidebar {
            position: fixed !important;
            top: 0 !important;
            right: 0 !important;
            bottom: 0 !important;
            left: auto !important;

            width: 280px !important;
            min-width: 280px !important;
            max-width: 85vw !important;

            height: 100vh !important;
            height: 100dvh !important;
            max-height: 100vh !important;
            max-height: 100dvh !important;

            transform: translateX(105%) !important;
            align-self: auto !important;

            transition: transform 0.35s cubic-bezier(0.65, 0, 0.35, 1) !important;
            will-change: transform;

            z-index: 999 !important;
            box-shadow: -20px 0 50px rgba(0, 0, 0, 0.25) !important;

            padding-top: env(safe-area-inset-top) !important;
            padding-bottom: env(safe-area-inset-bottom) !important;
        }
        body .sidebar.open { transform: translateX(0) !important; }
        body .sidebar.is-collapsed { width: 280px !important; min-width: 280px !important; }

        .main-wrapper { width: 100%; }

        .topbar {
            padding: 12px 16px;
            padding-top: max(12px, env(safe-area-inset-top));
            gap: 12px;
            flex-wrap: nowrap;
        }
        .topbar h1 { font-size: 0.95rem; flex: 1; margin: 0; text-align: center; }
        .topbar-user { font-size: 0.78rem !important; max-width: 110px; }

        .content-area { padding: 14px; }
        .card { padding: 16px; border-radius: 16px; }
    }

    /* =========================================================
       BREAKPOINT: 700px
       ========================================================= */
    @media (max-width: 700px) {
        .content-area { padding: 12px; }
        .topbar { padding: 10px 14px; padding-top: max(10px, env(safe-area-inset-top)); }
        .topbar h1 { font-size: 0.88rem; }
        .topbar-user { display: none; }

        .hamburger-btn { width: 40px; height: 40px; border-radius: 11px; }
        .hamburger-btn .hamburger-lines { width: 20px; height: 14px; }
        .hamburger-btn .hamburger-lines span { height: 2.2px; }
        .hamburger-btn.active .hamburger-lines span:nth-child(1) { transform: translateY(5.9px) rotate(45deg); }
        .hamburger-btn.active .hamburger-lines span:nth-child(3) { transform: translateY(-5.9px) rotate(-45deg); }

        .card { padding: 14px; border-radius: 14px; }

        .analytics-grid { grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 18px; }
        .analytic-card {
            padding: 14px;
            border-radius: 14px;
            position: relative;
            flex-direction: column;
            align-items: flex-start;
            gap: 8px;
        }
        .analytic-card .info div:first-child { font-size: 0.72rem; }
        .analytic-card .info div:last-child { font-size: 1.15rem; }
        .analytic-icon {
            width: 36px; height: 36px;
            font-size: 0.85rem;
            position: absolute;
            top: 12px; left: 12px;
            border-radius: 10px;
        }

        .tabs-header { flex-direction: column; align-items: stretch; gap: 12px; }
        .tabs-header > div:first-child { display: flex; gap: 8px; width: 100%; }
        .tabs-header .tab-btn { flex: 1; padding: 9px 10px; font-size: 0.82rem; }
        .tabs-header > button.btn { width: 100%; }

        .request-row {
            flex-direction: column;
            align-items: stretch;
            gap: 12px;
            padding: 14px 16px;
            padding-right: 22px;
        }
        .request-row.received-task,
        .request-row.assigned-task { padding-right: 24px; }
        .request-info { max-width: 100%; width: 100%; gap: 12px; }
        .request-name { font-size: 0.9rem; }
        .request-meta { font-size: 0.72rem; gap: 8px; }
        .request-description { font-size: 0.75rem; padding: 7px 10px; }
        .request-actions {
            display: flex !important;
            flex-wrap: wrap;
            gap: 8px !important;
            justify-content: flex-end;
            align-items: center;
        }

        .task-tooltip { right: 16px; font-size: 0.72rem; padding: 8px 11px; white-space: normal; max-width: 200px; }

        .modal-overlay { padding: 10px; align-items: flex-start; padding-top: 20px; }
        .modal-box {
            max-width: 100%;
            padding: 20px;
            border-radius: 18px;
            max-height: calc(100dvh - 40px);
        }
        .modal-header h3 { font-size: 1rem; }
        .modal-close { width: 28px; height: 28px; font-size: 1rem; }
        .modal-header-share { width: 30px; height: 30px; font-size: 0.78rem; }

        .modal-box form > div[style*="grid-template-columns"] {
            grid-template-columns: 1fr !important;
            gap: 0 !important;
        }

        input[type="text"], input[type="email"], input[type="tel"], input[type="url"],
        select, textarea { padding: 10px 14px; font-size: 0.9rem; border-radius: 12px; }
        label { font-size: 0.85rem; margin-bottom: 6px; }
        button.btn { padding: 10px 18px; font-size: 0.88rem; }

        .assignee-picker__trigger { min-height: 56px; padding: 9px 12px; }
        .assignee-picker__avatar { width: 38px; height: 38px; font-size: 0.9rem; }
        .assignee-picker__dropdown { max-height: 260px; }
        .assignee-picker__name { font-size: 0.86rem; }
        .assignee-picker__mobile { font-size: 0.7rem; }
        .assignee-picker__option { padding: 8px 10px; gap: 10px; }
        .assignee-picker__option-avatar { width: 32px; height: 32px; font-size: 0.8rem; }
        .assignee-picker__option-name { font-size: 0.84rem; }
        .assignee-picker__option-mobile { font-size: 0.68rem; }
        .assignee-picker__section-label { font-size: 0.68rem; padding: 6px 10px 4px; }

        .empty-state { padding: 30px 16px; font-size: 0.85rem; }
        .empty-state i { font-size: 2rem; }
        .action-link-delete { width: 32px; height: 32px; font-size: 0.82rem; }
        .app-toast { bottom: 20px; padding: 10px 18px; font-size: 0.8rem; }

        .pag-btn { min-width: 34px; height: 34px; font-size: 0.78rem; padding: 0 8px; }
        .pag-info { font-size: 0.68rem; width: 100%; text-align: center; padding: 4px 0 0; }
        .calls-pagination-inner { justify-content: center; }
    }

    /* =========================================================
       BREAKPOINT: 400px
       ========================================================= */
    @media (max-width: 400px) {
        .content-area { padding: 10px; }
        .analytics-grid { grid-template-columns: 1fr; }
        .analytic-card { flex-direction: row; align-items: center; }
        .analytic-icon { position: static; align-self: center; }
        .topbar h1 { font-size: 0.82rem; }
        .tabs-header .tab-btn { font-size: 0.75rem; padding: 8px 6px; }
        .request-actions { justify-content: space-between; }
        .modal-box { padding: 16px; border-radius: 16px; }
    }

    /* =========================================================
       REDUCED MOTION
       ========================================================= */
    @media (prefers-reduced-motion: reduce) {
        .sidebar,
        .sidebar-overlay,
        .hamburger-btn,
        .hamburger-btn .hamburger-lines span,
        .modal-box,
        .assignee-picker__dropdown { transition: none !important; }
    }

    /* =========================================================
       USER SELECT
       ========================================================= */
    body, .modal-box, input, textarea, select, button, a {
        -webkit-user-select: none;
        -moz-user-select: none;
        -ms-user-select: none;
        user-select: none;
    }
    input, textarea {
        -webkit-user-select: text;
        -moz-user-select: text;
        -ms-user-select: text;
        user-select: text;
    }
</style>
</head>
<body>

<?php sidebar(); ?>

<div class="main-wrapper">
    <div class="topbar">
        <button class="hamburger-btn" id="hamburgerBtn" onclick="toggleSidebar()" aria-label="باز کردن منو">
            <div class="hamburger-lines">
                <span></span>
                <span></span>
                <span></span>
            </div>
        </button>
        <h1>مدیریت درخواست‌های تماس</h1>
        <div class="topbar-user" style="font-size: 0.9rem; font-weight: bold;">کاربر: <?= htmlspecialchars($displayUser) ?></div>
    </div>

    <div class="content-area">
        <?php if (!empty($msg)): ?>
            <div style="background: <?= $msgType === 'danger' ? '#fee2e2' : ($msgType === 'warning' ? '#fef3c7' : '#d1fae5') ?>; color: <?= $msgType === 'danger' ? '#991b1b' : ($msgType === 'warning' ? '#92400e' : '#065f46') ?>; padding: 12px 15px; border-radius: 12px; margin-bottom: 20px; font-weight: bold;"><?= htmlspecialchars($msg) ?></div>
        <?php endif; ?>

        <?php if (!empty($_SESSION['call_flash_msg'])): ?>
            <?php
                $cfm = (string)$_SESSION['call_flash_msg'];
                $cft = (string)($_SESSION['call_flash_type'] ?? 'danger');
                unset($_SESSION['call_flash_msg'], $_SESSION['call_flash_type']);
            ?>
            <div style="background: <?= $cft === 'danger' ? '#fee2e2' : '#d1fae5' ?>; color: <?= $cft === 'danger' ? '#991b1b' : '#065f46' ?>; padding: 12px 15px; border-radius: 12px; margin-bottom: 20px; font-weight: bold;"><?= htmlspecialchars($cfm) ?></div>
        <?php endif; ?>

        <div class="analytics-grid">
            <div class="analytic-card">
                <div class="info"><div>کل درخواست‌ها</div><div><?= count($newRequests) + count($doneRequests) ?></div></div>
                <div class="analytic-icon"><i class="fas fa-phone-alt"></i></div>
            </div>
            <div class="analytic-card">
                <div class="info"><div>درخواست‌های جدید</div><div style="color: var(--warning);"><?= count($newRequests) ?></div></div>
                <div class="analytic-icon" style="background:#fef3c7; color:var(--warning);"><i class="fas fa-bell"></i></div>
            </div>
            <div class="analytic-card">
                <div class="info"><div>انجام شده</div><div style="color: var(--success);"><?= count($doneRequests) ?></div></div>
                <div class="analytic-icon" style="background:#d1fae5; color:var(--success);"><i class="fas fa-check-double"></i></div>
            </div>
        </div>

        <div class="card">
            <div class="tabs-header">
                <div style="display: flex; gap: 10px;">
                    <button class="tab-btn <?= $activeTab === 'new' ? 'active' : '' ?>" data-tab="new" onclick="switchTab('new', this)">جدید (<?= count($newRequests) ?>)</button>
                    <button class="tab-btn <?= $activeTab === 'done' ? 'active' : '' ?>" data-tab="done" onclick="switchTab('done', this)">انجام شده (<?= count($doneRequests) ?>)</button>
                </div>
                <button class="btn" onclick="openModal('createRequestModal')"><i class="fas fa-plus" style="margin-left: 5px;"></i> ثبت درخواست</button>
            </div>

            <div id="tab-new" class="tab-content <?= $activeTab === 'new' ? 'active' : '' ?>">
                <?php if (empty($newRequests)): ?>
                    <div class="empty-state">
                        <i class="fas fa-inbox"></i>
                        هیچ درخواست جدیدی وجود ندارد.
                    </div>
                <?php else: ?>
                    <?php foreach ($newRequestsPaged as $r): ?>
                        <?php
                            $isAssignedByMe = ((int)$r['user_id'] === $userId) && ((int)$r['assignee_id'] !== $userId);
                            $isAssignedToMe = ((int)$r['user_id'] !== $userId) && ((int)$r['assignee_id'] === $userId);

                            $assigneeName = trim(($r['assignee_first_name'] ?? '') . ' ' . ($r['assignee_last_name'] ?? ''));
                            $creatorName  = trim(($r['creator_first_name'] ?? '') . ' ' . ($r['creator_last_name'] ?? ''));
                            $assigneeMobile = $r['assignee_mobile'] ?? '';
                            $creatorMobile  = $r['creator_mobile'] ?? '';
                            if ($assigneeName === '') $assigneeName = $assigneeMobile;
                            if ($creatorName === '')  $creatorName  = $creatorMobile ?: 'کاربر';

                            $reqPerms = ['edit' => false, 'delete' => false, 'complete' => false, 'reassign' => false];
                            if ((int)$r['user_id'] === $userId) {
                                $reqPerms = ['edit' => true, 'delete' => true, 'complete' => true, 'reassign' => true];
                            } else {
                                $myPerms = json_decode($r['my_req_permissions'] ?? '[]', true);
                                if (!is_array($myPerms)) $myPerms = [];
                                foreach ($myPerms as $p) {
                                    if (isset($reqPerms[$p])) $reqPerms[$p] = true;
                                }
                            }

                            $createdPersian = formatPersianDate($r['created_at']);
                        ?>
                        <div class="request-row <?= $isAssignedToMe ? 'received-task' : '' ?> <?= $isAssignedByMe ? 'assigned-task' : '' ?> <?= $reqPerms['edit'] ? '' : 'no-edit' ?>"
                             id="request-row-<?= $r['id'] ?>"
                             data-request='<?= json_encode($r, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>'
                             data-can-edit="<?= $reqPerms['edit'] ? '1' : '0' ?>"
                             data-can-reassign="<?= $reqPerms['reassign'] ? '1' : '0' ?>"
                             onclick="handleRequestRowClick(this, event)">

                            <?php if ($isAssignedToMe): ?>
                                <div class="task-color-bar bar-received"></div>
                                <div class="task-tooltip tip-received">
                                    <div class="tip-icon"><i class="fas fa-inbox"></i></div>
                                    <div>
                                        <div class="tip-label">واگذارشده از طرف</div>
                                        <div><span class="tip-name"><?= htmlspecialchars($creatorName) ?></span>
                                            <?php if ($creatorMobile): ?><span class="tip-mobile"><?= htmlspecialchars($creatorMobile) ?></span><?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php elseif ($isAssignedByMe): ?>
                                <div class="task-color-bar bar-sent"></div>
                                <div class="task-tooltip tip-sent">
                                    <div class="tip-icon"><i class="fas fa-paper-plane"></i></div>
                                    <div>
                                        <div class="tip-label">واگذارشده به</div>
                                        <div><span class="tip-name"><?= htmlspecialchars($assigneeName) ?></span>
                                            <?php if ($assigneeMobile): ?><span class="tip-mobile"><?= htmlspecialchars($assigneeMobile) ?></span><?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <div class="request-info">
                                <?php if ($reqPerms['complete']): ?>
                                    <input type="checkbox" class="custom-checkbox" onclick="event.stopPropagation(); completeRequest(<?= $r['id'] ?>, this)">
                                <?php else: ?>
                                    <input type="checkbox" class="custom-checkbox" disabled title="شما دسترسی تکمیل این درخواست را ندارید">
                                <?php endif; ?>
                                <div style="min-width:0; flex:1;">
                                    <div class="request-name"><?= htmlspecialchars($r['first_name'] . ' ' . $r['last_name']) ?></div>
                                    <div class="request-meta">
                                        <span><i class="fas fa-phone"></i> <?= htmlspecialchars($r['mobile']) ?></span>
                                        <?php if (!empty($r['email'])): ?><span><i class="fas fa-envelope"></i> <?= htmlspecialchars($r['email']) ?></span><?php endif; ?>
                                        <?php if (!empty($r['store'])): ?><span><i class="fas fa-store"></i> <?= htmlspecialchars($r['store']) ?></span><?php endif; ?>
                                        <?php if (!empty($r['website'])): ?><span><i class="fas fa-globe"></i> <?= htmlspecialchars($r['website']) ?></span><?php endif; ?>
                                        <span><i class="fas fa-clock"></i> <?= htmlspecialchars($createdPersian) ?></span>
                                    </div>
                                    <?php if (!empty($r['description'])): ?>
                                        <div class="request-description"><?= nl2br(htmlspecialchars($r['description'])) ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="request-actions">
                                <?php if ($reqPerms['delete']): ?>
                                    <a href="index.php?delete=<?= $r['id'] ?>"
                                       class="action-link-delete"
                                       title="حذف"
                                       onclick="event.stopPropagation(); return confirm('این درخواست حذف شود؟');">
                                        <i class="fas fa-trash-alt"></i>
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>

                    <!-- ⭐ صفحه‌بندی تب جدید -->
                    <?php if ($totalPagesNew > 1): ?>
                        <div class="calls-pagination">
                            <?= renderCallsPagination($pageNew, $totalPagesNew, $totalNew, 'p_n', 'new') ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

            <div id="tab-done" class="tab-content <?= $activeTab === 'done' ? 'active' : '' ?>">
                <?php if (empty($doneRequests)): ?>
                    <div class="empty-state">
                        <i class="fas fa-check-circle"></i>
                        هنوز درخواستی تکمیل نشده است.
                    </div>
                <?php else: ?>
                    <?php foreach ($doneRequestsPaged as $r): ?>
                        <?php
                            $isAssignedByMe = ((int)$r['user_id'] === $userId) && ((int)$r['assignee_id'] !== $userId);
                            $isAssignedToMe = ((int)$r['user_id'] !== $userId) && ((int)$r['assignee_id'] === $userId);

                            $assigneeName = trim(($r['assignee_first_name'] ?? '') . ' ' . ($r['assignee_last_name'] ?? ''));
                            $creatorName  = trim(($r['creator_first_name'] ?? '') . ' ' . ($r['creator_last_name'] ?? ''));
                            $assigneeMobile = $r['assignee_mobile'] ?? '';
                            $creatorMobile  = $r['creator_mobile'] ?? '';
                            if ($assigneeName === '') $assigneeName = $assigneeMobile;
                            if ($creatorName === '')  $creatorName  = $creatorMobile ?: 'کاربر';

                            $reqPerms = ['edit' => false, 'delete' => false, 'complete' => false, 'reassign' => false];
                            if ((int)$r['user_id'] === $userId) {
                                $reqPerms = ['edit' => true, 'delete' => true, 'complete' => true, 'reassign' => true];
                            } else {
                                $myPerms = json_decode($r['my_req_permissions'] ?? '[]', true);
                                if (!is_array($myPerms)) $myPerms = [];
                                foreach ($myPerms as $p) {
                                    if (isset($reqPerms[$p])) $reqPerms[$p] = true;
                                }
                            }

                            $createdPersian   = formatPersianDate($r['created_at']);
                            $completedPersian = !empty($r['completed_at'])
                                ? formatPersianDate($r['completed_at'])
                                : $createdPersian;
                        ?>
                        <div class="request-row completed-row <?= $isAssignedToMe ? 'received-task' : '' ?> <?= $isAssignedByMe ? 'assigned-task' : '' ?> <?= $reqPerms['edit'] ? '' : 'no-edit' ?>"
                             id="request-row-<?= $r['id'] ?>"
                             data-request='<?= json_encode($r, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>'
                             data-can-edit="<?= $reqPerms['edit'] ? '1' : '0' ?>"
                             data-can-reassign="<?= $reqPerms['reassign'] ? '1' : '0' ?>"
                             onclick="handleRequestRowClick(this, event)">

                            <?php if ($isAssignedToMe): ?>
                                <div class="task-color-bar bar-received"></div>
                                <div class="task-tooltip tip-received">
                                    <div class="tip-icon"><i class="fas fa-inbox"></i></div>
                                    <div>
                                        <div class="tip-label">واگذارشده از طرف</div>
                                        <div><span class="tip-name"><?= htmlspecialchars($creatorName) ?></span>
                                            <?php if ($creatorMobile): ?><span class="tip-mobile"><?= htmlspecialchars($creatorMobile) ?></span><?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php elseif ($isAssignedByMe): ?>
                                <div class="task-color-bar bar-sent"></div>
                                <div class="task-tooltip tip-sent">
                                    <div class="tip-icon"><i class="fas fa-paper-plane"></i></div>
                                    <div>
                                        <div class="tip-label">واگذارشده به</div>
                                        <div><span class="tip-name"><?= htmlspecialchars($assigneeName) ?></span>
                                            <?php if ($assigneeMobile): ?><span class="tip-mobile"><?= htmlspecialchars($assigneeMobile) ?></span><?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <div class="request-info">
                                <input type="checkbox" class="custom-checkbox" checked disabled onclick="event.stopPropagation();">
                                <div style="min-width:0; flex:1;">
                                    <div class="request-name"><?= htmlspecialchars($r['first_name'] . ' ' . $r['last_name']) ?></div>
                                    <div class="request-meta">
                                        <span><i class="fas fa-phone"></i> <?= htmlspecialchars($r['mobile']) ?></span>
                                        <?php if (!empty($r['email'])): ?><span><i class="fas fa-envelope"></i> <?= htmlspecialchars($r['email']) ?></span><?php endif; ?>
                                        <?php if (!empty($r['store'])): ?><span><i class="fas fa-store"></i> <?= htmlspecialchars($r['store']) ?></span><?php endif; ?>
                                        <?php if (!empty($r['website'])): ?><span><i class="fas fa-globe"></i> <?= htmlspecialchars($r['website']) ?></span><?php endif; ?>
                                        <span><i class="fas fa-calendar-plus"></i> ثبت: <?= htmlspecialchars($createdPersian) ?></span>
                                        <span style="color: var(--success);"><i class="fas fa-check-circle"></i> تکمیل: <?= htmlspecialchars($completedPersian) ?></span>
                                    </div>
                                    <?php if (!empty($r['description'])): ?>
                                        <div class="request-description"><?= nl2br(htmlspecialchars($r['description'])) ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="request-actions">
                                <?php if ($reqPerms['delete']): ?>
                                    <a href="index.php?delete=<?= $r['id'] ?>"
                                       class="action-link-delete"
                                       title="حذف"
                                       onclick="event.stopPropagation(); return confirm('این درخواست حذف شود؟');">
                                        <i class="fas fa-trash-alt"></i>
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>

                    <!-- ⭐ صفحه‌بندی تب انجام شده -->
                    <?php if ($totalPagesDone > 1): ?>
                        <div class="calls-pagination">
                            <?= renderCallsPagination($pageDone, $totalPagesDone, $totalDone, 'p_d', 'done') ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- پاپ‌آپ ثبت درخواست -->
<div id="createRequestModal" class="modal-overlay">
    <div class="modal-box">
        <div class="modal-header">
            <h3><i class="fas fa-phone-volume" style="color: var(--accent); margin-left: 8px;"></i> ثبت درخواست تماس</h3>
            <button class="modal-close" onclick="closeModal('createRequestModal')">&times;</button>
        </div>
        <form id="createRequestForm" onsubmit="submitCallRequest(event)">
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                <div class="form-group">
                    <label>نام مشتری <span class="required-star">*</span></label>
                    <input type="text" name="first_name" required>
                </div>
                <div class="form-group">
                    <label>نام خانوادگی مشتری <span class="required-star">*</span></label>
                    <input type="text" name="last_name" required>
                </div>
            </div>
            <div class="form-group">
                <label>تلفن <span class="required-star">*</span></label>
                <input type="tel" name="mobile" required placeholder="مثلاً 09123456789">
            </div>
            <div class="form-group">
                <label>ایمیل</label>
                <input type="email" name="email" placeholder="example@mail.com">
            </div>
            <div class="form-group">
                <label>فروشگاه</label>
                <input type="text" name="store" placeholder="نام فروشگاه">
            </div>
            <div class="form-group">
                <label>سایت</label>
                <input type="url" name="website" placeholder="https://example.com">
            </div>
            <div class="form-group">
                <label>توضیحات</label>
                <textarea name="description" rows="3" placeholder="توضیحات اضافی (اختیاری)"></textarea>
            </div>

            <div class="form-group">
                <label>مسئول پیگیری</label>
                <div class="assignee-picker" id="createAssigneePicker">
                    <input type="hidden" name="assignee_id" value="<?= $userId ?>" id="createAssigneeInput">
                    <div class="assignee-picker__trigger" onclick="toggleAssigneePicker('createAssigneePicker')">
                        <div class="assignee-picker__avatar is-self" id="createAssigneeAvatar">
                            <?php if ($currentUserAvatar): ?>
                                <img src="<?= htmlspecialchars($currentUserAvatar) ?>" alt="من">
                            <?php else: ?>
                                <?= htmlspecialchars(mb_substr($currentUserFirstName, 0, 1, 'UTF-8')) ?>
                            <?php endif; ?>
                        </div>
                        <div class="assignee-picker__info">
                            <div class="assignee-picker__name" id="createAssigneeName">خودم</div>
                            <div class="assignee-picker__mobile" id="createAssigneeMobile"><?= htmlspecialchars($displayUser) ?></div>
                        </div>
                        <i class="fas fa-chevron-down assignee-picker__chevron"></i>
                    </div>
                    <div class="assignee-picker__dropdown" id="createAssigneeDropdown"></div>
                </div>
            </div>

            <button type="submit" id="createRequestSubmitBtn" class="btn" style="width: 100%; margin-top: 5px;">
                <i class="fas fa-save" style="margin-left: 5px;"></i> ثبت درخواست
            </button>
        </form>
    </div>
</div>

<!-- پاپ‌آپ ویرایش درخواست -->
<div id="editRequestModal" class="modal-overlay" onclick="if(event.target === this) closeModal('editRequestModal')">
    <div class="modal-box" onclick="event.stopPropagation()">
        <div class="modal-header">
            <h3><i class="fas fa-pen" style="color: var(--accent); margin-left: 8px;"></i> ویرایش درخواست تماس</h3>
            <div class="modal-header-actions">
                <button type="button" class="modal-header-share" id="edit_share_btn" onclick="copyCallLink()" title="کپی لینک اختصاصی درخواست">
                    <i class="fas fa-link"></i>
                </button>
                <button class="modal-close" onclick="closeModal('editRequestModal')">&times;</button>
            </div>
        </div>
        <form id="editRequestForm" method="POST">
            <input type="hidden" name="update_call_request" value="1">
            <input type="hidden" name="request_id" id="edit_request_id">

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                <div class="form-group">
                    <label>نام مشتری <span class="required-star">*</span></label>
                    <input type="text" name="first_name" id="edit_first_name" required>
                </div>
                <div class="form-group">
                    <label>نام خانوادگی مشتری <span class="required-star">*</span></label>
                    <input type="text" name="last_name" id="edit_last_name" required>
                </div>
            </div>
            <div class="form-group">
                <label>تلفن <span class="required-star">*</span></label>
                <input type="tel" name="mobile" id="edit_mobile" required>
            </div>
            <div class="form-group">
                <label>ایمیل</label>
                <input type="email" name="email" id="edit_email">
            </div>
            <div class="form-group">
                <label>فروشگاه</label>
                <input type="text" name="store" id="edit_store">
            </div>
            <div class="form-group">
                <label>سایت</label>
                <input type="url" name="website" id="edit_website">
            </div>
            <div class="form-group">
                <label>توضیحات</label>
                <textarea name="description" id="edit_description" rows="3" placeholder="توضیحات اضافی (اختیاری)"></textarea>
            </div>

            <div class="form-group">
                <label>مسئول پیگیری</label>
                <div class="assignee-picker" id="editAssigneePicker">
                    <input type="hidden" name="assignee_id" value="<?= $userId ?>" id="editAssigneeInput">
                    <div class="assignee-picker__trigger" onclick="toggleAssigneePicker('editAssigneePicker')">
                        <div class="assignee-picker__avatar is-self" id="editAssigneeAvatar">
                            <?php if ($currentUserAvatar): ?>
                                <img src="<?= htmlspecialchars($currentUserAvatar) ?>" alt="من">
                            <?php else: ?>
                                <?= htmlspecialchars(mb_substr($currentUserFirstName, 0, 1, 'UTF-8')) ?>
                            <?php endif; ?>
                        </div>
                        <div class="assignee-picker__info">
                            <div class="assignee-picker__name" id="editAssigneeName">خودم</div>
                            <div class="assignee-picker__mobile" id="editAssigneeMobile"><?= htmlspecialchars($displayUser) ?></div>
                        </div>
                        <i class="fas fa-chevron-down assignee-picker__chevron"></i>
                    </div>
                    <div class="assignee-picker__dropdown" id="editAssigneeDropdown"></div>
                </div>
            </div>

            <button type="submit" id="editRequestSubmitBtn" class="btn" style="width: 100%; margin-top: 5px;">
                <i class="fas fa-save" style="margin-left: 5px;"></i> ذخیره تغییرات
            </button>
        </form>
    </div>
</div>

<!-- ⭐ Toast -->
<div class="app-toast" id="appToast">
    <i class="fas fa-check-circle"></i>
    <span id="appToastText"></span>
</div>

<script>
    const CURRENT_USER_ID = <?= (int)$userId ?>;
    const ASSIGNEE_OPTIONS = <?= $assigneeOptionsJson ?>;
    // ⭐⭐⭐ از var استفاده می‌کنیم تا window.SHARED_CALL_DATA در دسترس باشد
    var SHARED_CALL_DATA = <?= $callSharedDataJson ?>;
    var SHARED_CALL_PERMS = {
        edit: <?= $sharedCanEdit ? 'true' : 'false' ?>,
        reassign: <?= $sharedCanReassign ? 'true' : 'false' ?>
    };

    document.addEventListener('contextmenu', function(e) {
        e.preventDefault();
        return false;
    });

    /* ================== غیرفعال کردن سلکت همه با Ctrl+A ================== */
    document.addEventListener('keydown', function(e) {
        if ((e.ctrlKey || e.metaKey) && (e.key === 'a' || e.key === 'A')) {
            const tag = (e.target.tagName || '').toLowerCase();
            if (tag !== 'input' && tag !== 'textarea') {
                e.preventDefault();
                return false;
            }
        }

        if ((e.ctrlKey || e.metaKey) && (e.key === 'u' || e.key === 'U')) {
            e.preventDefault();
            return false;
        }
    });

    /* ================== غیرفعال کردن درگ تصاویر/متن ================== */
    document.addEventListener('dragstart', function(e) {
        if (e.target.tagName === 'IMG' || e.target.tagName === 'A') {
            e.preventDefault();
        }
    });

    /* ============================================================
       ⌨️ Ctrl+S / Cmd+S — ذخیره درخواست (ثبت یا ویرایش)
    ============================================================ */
    document.addEventListener('keydown', function(e) {
        const isSaveCombo = (e.ctrlKey || e.metaKey) && !e.shiftKey && !e.altKey &&
                            (e.key === 's' || e.key === 'S' || e.keyCode === 83);

        if (!isSaveCombo) return;

        const createModal = document.getElementById('createRequestModal');
        const editModal   = document.getElementById('editRequestModal');

        const isCreateOpen = createModal && createModal.classList.contains('active');
        const isEditOpen   = editModal   && editModal.classList.contains('active');

        if (isCreateOpen) {
            e.preventDefault();
            e.stopPropagation();
            const btn = document.getElementById('createRequestSubmitBtn');
            if (btn && !btn.disabled) btn.click();
            return false;
        }

        if (isEditOpen) {
            e.preventDefault();
            e.stopPropagation();
            const btn = document.getElementById('editRequestSubmitBtn');
            if (btn && !btn.disabled) btn.click();
            return false;
        }

        e.preventDefault();
        return false;
    }, true);

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
       🖱️ کلیک روی ردیف → باز شدن مودال ویرایش
    ============================================================ */
    function handleRequestRowClick(row, event) {
        const target = event.target;
        if (target.closest('.custom-checkbox') ||
            target.closest('.action-link-delete') ||
            target.closest('.assignee-picker') ||
            target.closest('a') ||
            target.closest('button')) {
            return;
        }

        if (row.getAttribute('data-can-edit') !== '1') return;

        try {
            const req = JSON.parse(row.getAttribute('data-request'));
            const canReassign = row.getAttribute('data-can-reassign') === '1';
            openEditModal(req, canReassign);
        } catch (err) {
            console.error('خطا در باز کردن مودال ویرایش:', err);
        }
    }

    /* ================== Assignee Picker ================== */
    function renderAssigneeDropdown(pickerId, selectedId) {
        const picker = document.getElementById(pickerId);
        const dropdown = picker.querySelector('.assignee-picker__dropdown');
        const colleagues = ASSIGNEE_OPTIONS.colleagues || [];
        const self = ASSIGNEE_OPTIONS.self;

        let html = `<div class="assignee-picker__section-label">خودم</div>`;
        html += renderAssigneeOption(self, selectedId);

        if (colleagues.length > 0) {
            html += `<div class="assignee-picker__section-label">همکاران</div>`;
            colleagues.forEach(c => { html += renderAssigneeOption(c, selectedId); });
        } else {
            html += `<div class="assignee-picker__section-label">همکاران</div>`;
            html += `<div class="assignee-picker__empty">هنوز همکاری اضافه نکرده‌اید.<br><a href="../colleagues/index.php"><i class="fas fa-user-plus"></i> افزودن همکار</a></div>`;
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
        const avatarClass = person.is_self ? 'is-self' : '';
        const selectedClass = isSelected ? 'selected' : '';
        const avatarContent = person.avatar_url
            ? `<img src="${escapeHtml(person.avatar_url)}" alt="">`
            : escapeHtml(person.initial || '?');

        return `
            <div class="assignee-picker__option ${selectedClass}" data-id="${person.id}">
                <div class="assignee-picker__option-avatar ${avatarClass}">${avatarContent}</div>
                <div class="assignee-picker__option-info">
                    <div class="assignee-picker__option-name">${escapeHtml(person.name)}</div>
                    ${person.mobile ? `<div class="assignee-picker__option-mobile">${escapeHtml(person.mobile)}</div>` : ''}
                </div>
            </div>
        `;
    }

    function selectAssignee(pickerId, id) {
        const picker = document.getElementById(pickerId);
        const input = picker.querySelector('input[type="hidden"]');
        const avatarEl = picker.querySelector('.assignee-picker__avatar');
        const nameEl = picker.querySelector('.assignee-picker__name');
        const mobileEl = picker.querySelector('.assignee-picker__mobile');

        let person = String(ASSIGNEE_OPTIONS.self.id) === String(id)
            ? ASSIGNEE_OPTIONS.self
            : (ASSIGNEE_OPTIONS.colleagues || []).find(c => String(c.id) === String(id));
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
        if (picker.classList.contains('is-disabled')) return;
        const wasOpen = picker.classList.contains('open');

        document.querySelectorAll('.assignee-picker.open').forEach(p => {
            if (p.id !== pickerId) p.classList.remove('open');
        });

        if (wasOpen) {
            picker.classList.remove('open');
        } else {
            renderAssigneeDropdown(pickerId, picker.querySelector('input[type="hidden"]').value);
            picker.classList.add('open');
        }
    }

    document.addEventListener('click', function(e) {
        if (!e.target.closest('.assignee-picker')) {
            document.querySelectorAll('.assignee-picker.open').forEach(p => p.classList.remove('open'));
        }
    });

    /* ================== توابع اصلی ================== */
    function switchTab(tabName, btn) {
        document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
        document.querySelectorAll('.tab-btn').forEach(el => el.classList.remove('active'));
        document.getElementById('tab-' + tabName).classList.add('active');
        btn.classList.add('active');
    }

    function openModal(id) {
        document.getElementById(id).classList.add('active');
        if (id === 'createRequestModal') {
            document.getElementById('createAssigneeInput').value = CURRENT_USER_ID;
            const createAvatarEl = document.getElementById('createAssigneeAvatar');
            if (ASSIGNEE_OPTIONS.self.avatar_url) {
                createAvatarEl.innerHTML = `<img src="${escapeHtml(ASSIGNEE_OPTIONS.self.avatar_url)}" alt="من">`;
            } else {
                createAvatarEl.textContent = ASSIGNEE_OPTIONS.self.initial || '?';
            }
            createAvatarEl.className = 'assignee-picker__avatar is-self';
            document.getElementById('createAssigneeName').textContent = 'خودم';
            document.getElementById('createAssigneeMobile').textContent = ASSIGNEE_OPTIONS.self.mobile || '';
            renderAssigneeDropdown('createAssigneePicker', CURRENT_USER_ID);
        }
    }
    function closeModal(id) { document.getElementById(id).classList.remove('active'); }

    function openEditModal(req, canReassign) {
        document.getElementById('edit_request_id').value = req.id;
        document.getElementById('edit_first_name').value = req.first_name || '';
        document.getElementById('edit_last_name').value = req.last_name || '';
        document.getElementById('edit_mobile').value = req.mobile || '';
        document.getElementById('edit_email').value = req.email || '';
        document.getElementById('edit_store').value = req.store || '';
        document.getElementById('edit_website').value = req.website || '';
        document.getElementById('edit_description').value = req.description || '';

        const targetId = req.assignee_id ? String(req.assignee_id) : String(CURRENT_USER_ID);
        selectAssignee('editAssigneePicker', targetId);

        const picker = document.getElementById('editAssigneePicker');
        if (canReassign) picker.classList.remove('is-disabled');
        else picker.classList.add('is-disabled');

        // ⭐ نمایش/مخفی کردن دکمه اشتراک‌گذاری
        const shareBtn = document.getElementById('edit_share_btn');
        if (shareBtn) shareBtn.style.display = 'inline-flex';

        openModal('editRequestModal');
    }

    /* ============================================================
       ⭐ کپی لینک اختصاصی درخواست
    ============================================================ */
    function copyCallLink() {
        const reqId = document.getElementById('edit_request_id').value;
        if (!reqId) return;
        const btn = document.getElementById('edit_share_btn');
        if (btn) btn.classList.add('copied');

        fetch('index.php?get_call_token=' + encodeURIComponent(reqId), {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(r => r.text())
        .then(text => {
            let data;
            try { data = JSON.parse(text); }
            catch (e) { console.error('❌ copyCallLink raw:', text); throw new Error('invalid'); }
            if (data.status !== 'success') {
                showAppToast(data.message || 'خطا در ساخت لینک', 'error');
                if (btn) btn.classList.remove('copied');
                return;
            }
            const fullUrl = new URL(data.url, window.location.href).href;
            const done = () => {
                showAppToast('لینک اختصاصی درخواست کپی شد', 'success');
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
            console.error('copyCallLink error:', err);
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

    function submitCallRequest(e) {
        e.preventDefault();
        const form = document.getElementById('createRequestForm');
        const formData = new FormData(form);
        formData.append('add_call_request', '1');

        fetch('index.php', {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if (data.status === 'success') {
                window.location.reload();
            } else {
                alert(data.message || 'خطا در ثبت درخواست');
            }
        })
        .catch(() => alert('خطا در ارتباط با سرور'));
    }

    function completeRequest(id, checkbox) {
        const row = document.getElementById('request-row-' + id);
        row.classList.add('animating');
        checkbox.disabled = true;

        fetch('index.php?complete=1&id=' + id, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(res => res.json())
        .then(data => {
            if (data.status === 'error') {
                alert(data.message || 'شما دسترسی تکمیل این درخواست را ندارید.');
                checkbox.disabled = false;
                row.classList.remove('animating');
                return;
            }
            if (data.status === 'success' && data.affected > 0) {
                row.style.transition = 'opacity 0.25s ease';
                row.style.opacity = '0';
                setTimeout(() => window.location.reload(), 250);
            } else {
                window.location.reload();
            }
        })
        .catch(() => {
            checkbox.disabled = false;
            row.classList.remove('animating');
        });
    }

    function escapeHtml(text) {
        if (text === null || text === undefined) return '';
        const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
        return String(text).replace(/[&<>"']/g, function(m) { return map[m]; });
    }

    /* ================== HAMBURGER ================== */
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
        if (e.key === 'Escape') closeSidebar();
    });

    let resizeTimer;
    window.addEventListener('resize', function() {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(function() {
            if (window.innerWidth > 900) closeSidebar();
        }, 150);
    });

    /* ============================================================
       ⭐ باز کردن خودکار مودال با لینک اشتراکی
    ============================================================ */
    document.addEventListener('DOMContentLoaded', function() {
        if (window.SHARED_CALL_DATA) {
            try {
                openEditModal(window.SHARED_CALL_DATA, window.SHARED_CALL_PERMS.reassign);
            } catch (err) {
                console.error('auto-open shared call error:', err);
            }
        }
    });
</script>
</body>
</html>