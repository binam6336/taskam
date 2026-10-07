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
    function gregorianToJalali($gy, $gm, $gd)
    {
        $g_d_m = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
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
    function formatPersianDate($datetime, $includeWeekday = true)
    {
        if (empty($datetime) || $datetime === '0000-00-00 00:00:00') return '—';
        $ts = strtotime($datetime);
        if (!$ts) return $datetime;

        $gy = (int)date('Y', $ts);
        $gm = (int)date('n', $ts);
        $gd = (int)date('j', $ts);
        list($jy, $jm, $jd) = gregorianToJalali($gy, $gm, $gd);

        $months   = ['فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];
        $weekdays = ['شنبه', 'یکشنبه', 'دوشنبه', 'سه‌شنبه', 'چهارشنبه', 'پنجشنبه', 'جمعه'];
        $wd = (int)date('w', $ts);
        $persianWd = ($wd + 1) % 7;

        $time = date('H:i', $ts);
        $result = ($includeWeekday ? $weekdays[$persianWd] . ' ' : '')
            . $jd . ' ' . $months[$jm - 1] . ' ' . $jy . ' - ' . $time;
        return $result;
    }
}

if (!function_exists('formatPersianDateShort')) {
    function formatPersianDateShort($datetime)
    {
        if (empty($datetime) || $datetime === '0000-00-00 00:00:00') return '—';
        $ts = strtotime($datetime);
        if (!$ts) return $datetime;

        $gy = (int)date('Y', $ts);
        $gm = (int)date('n', $ts);
        $gd = (int)date('j', $ts);
        list($jy, $jm, $jd) = gregorianToJalali($gy, $gm, $gd);

        $months = ['فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];
        return $jd . ' ' . $months[$jm - 1] . ' ' . $jy;
    }
}

// ================== توابع لینک اشتراکی و ربات تسکام ==================
if (!function_exists('ensureCallShareToken')) {
    function ensureCallShareToken(PDO $db, int $reqId): string
    {
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
    function getCallTaskamBotId(PDO $db): int
    {
        static $cachedId = null;
        if ($cachedId !== null) return $cachedId;
        try {
            $stmt = $db->query("SELECT id FROM users WHERE mobile = '00000000000' LIMIT 1");
            $existing = (int)$stmt->fetchColumn();
            if ($existing > 0) {
                $cachedId = $existing;
                return $cachedId;
            }
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
            try {
                $db->exec("ALTER TABLE call_requests ADD UNIQUE INDEX idx_call_share_token (share_token)");
            } catch (PDOException $e2) {
            }
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
} catch (PDOException $e) {
}

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
} catch (PDOException $e) {
}

// ================== تابع بررسی دسترسی روی درخواست ==================
function checkCallPermission($db, $reqId, $userId, $action)
{
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
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/json; charset=utf-8');

    $reqId = (int)$_GET['get_call_token'];
    if ($reqId <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'شناسه نامعتبر'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $chk = $db->prepare("SELECT id, user_id, assignee_id FROM call_requests WHERE id = ? AND (user_id = ? OR assignee_id = ?) LIMIT 1");
    $chk->execute([$reqId, $userId, $userId]);
    $row = $chk->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        echo json_encode(['status' => 'error', 'message' => 'دسترسی به این درخواست ندارید.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $token = ensureCallShareToken($db, $reqId);
    if ($token === '') {
        echo json_encode(['status' => 'error', 'message' => 'خطا در ساخت لینک'], JSON_UNESCAPED_UNICODE);
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
} catch (PDOException $e) {
}

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

$newRequestsPaged  = array_slice($newRequests, ($pageNew  - 1) * $CALLS_PER_PAGE, $CALLS_PER_PAGE);
$doneRequestsPaged = array_slice($doneRequests, ($pageDone - 1) * $CALLS_PER_PAGE, $CALLS_PER_PAGE);

// ================================================================
// ⭐ تابع کمکی ساخت HTML صفحه‌بندی
// ================================================================
if (!function_exists('renderCallsPagination')) {
    function renderCallsPagination(int $currentPage, int $totalPages, int $totalCount, string $paramKey, string $activeTab, bool $hasSharedParam = false): string
    {
        if ($totalPages <= 1) return '';

        $buildLink = function (int $page) use ($paramKey, $activeTab, $hasSharedParam) {
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

$makeAvatarUrl = function ($avatarFile) {
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
    'colleagues' => array_map(function ($c) use ($makeAvatarUrl) {
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
    <link rel="stylesheet" href="style.css">

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
                    <div class="info">
                        <div>کل درخواست‌ها</div>
                        <div><?= count($newRequests) + count($doneRequests) ?></div>
                    </div>
                    <div class="analytic-icon"><i class="fas fa-phone-alt"></i></div>
                </div>
                <div class="analytic-card">
                    <div class="info">
                        <div>درخواست‌های جدید</div>
                        <div style="color: var(--warning);"><?= count($newRequests) ?></div>
                    </div>
                    <div class="analytic-icon" style="background:#fef3c7; color:var(--warning);"><i class="fas fa-bell"></i></div>
                </div>
                <div class="analytic-card">
                    <div class="info">
                        <div>انجام شده</div>
                        <div style="color: var(--success);"><?= count($doneRequests) ?></div>
                    </div>
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
    </script>

    <script src="app.js"></script>
</body>

</html>