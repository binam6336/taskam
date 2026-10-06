<?php
/**
 * صفحه مدیریت همکاران و دسترسی‌ها
 * نسخه کامل با Ajax + سیستم تایید همکاری + پاپ‌آپ سفارشی
 * ✅ رفع کامل خطای 500 در تایید درخواست
 */

ob_start();

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../database/Database.php';
require_once __DIR__ . '/../includes/sidebar.php';
require_once __DIR__ . '/../includes/chat_notifications.php';

if (!Auth::check()) {
    header('Location: ../auth/login.php');
    exit;
}

$db = Database::getInstance();

try {
    $db->exec("SET NAMES 'utf8mb4'");
} catch (PDOException $e) {
    error_log('DB encoding error: ' . $e->getMessage());
}

$userId = (int)$_SESSION['user_id'];
$page = 'colleagues';
$msg = '';
$msgType = 'success';

// ================== CSRF Token ==================
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

function verifyCsrf(): bool {
    if (!isset($_POST['csrf_token'], $_SESSION['csrf_token'])) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], (string)$_POST['csrf_token']);
}

function jsonResponse(array $data, int $httpCode = 200): void {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($httpCode);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// ================== اطمینان از وجود جدول‌ها و اصلاح ساختار ==================
if (empty($_SESSION['colleagues_tables_checked_v4'])) {
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS colleagues (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            colleague_user_id INT NOT NULL,
            permissions TEXT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_colleague (user_id, colleague_user_id),
            KEY user_id (user_id),
            KEY colleague_user_id (colleague_user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;");

        $db->exec("CREATE TABLE IF NOT EXISTS colleague_blocks (
            id INT AUTO_INCREMENT PRIMARY KEY,
            blocker_user_id INT NOT NULL,
            blocked_user_id INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_block (blocker_user_id, blocked_user_id),
            KEY blocker_user_id (blocker_user_id),
            KEY blocked_user_id (blocked_user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;");

        $db->exec("CREATE TABLE IF NOT EXISTS colleague_requests (
            id INT AUTO_INCREMENT PRIMARY KEY,
            requester_id INT NOT NULL COMMENT 'کاربری که درخواست داده',
            target_id INT NOT NULL COMMENT 'کاربری که باید تایید کند',
            status ENUM('pending','accepted','rejected') NOT NULL DEFAULT 'pending',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            resolved_at TIMESTAMP NULL DEFAULT NULL,
            KEY idx_requester (requester_id),
            KEY idx_target (target_id),
            KEY idx_status (status),
            KEY idx_pair_status (requester_id, target_id, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;");

        // ⭐ حذف کلید یکتای معیوب در صورت وجود (نسخه‌های قبلی)
        try {
            $db->exec("ALTER TABLE colleague_requests DROP INDEX unique_request");
        } catch (PDOException $e) {
            // اگر وجود ندارد، نادیده بگیر
        }

        // ⭐ حذف رکوردهای تکراری (نگه‌داشتن جدیدترین)
        try {
            $db->exec("
                DELETE r1 FROM colleague_requests r1
                INNER JOIN colleague_requests r2
                WHERE r1.id < r2.id
                  AND r1.requester_id = r2.requester_id
                  AND r1.target_id = r2.target_id
                  AND r1.status = r2.status
            ");
        } catch (PDOException $e) {
            error_log('Duplicate cleanup error: ' . $e->getMessage());
        }

        $_SESSION['colleagues_tables_checked_v4'] = 1;
    } catch (PDOException $e) {
        error_log('Colleagues table check error: ' . $e->getMessage());
    }
}

// ================== لیست دسترسی‌ها ==================
$ALL_PERMISSIONS = [
    'edit'           => ['label' => 'ویرایش تسک',              'icon' => 'fa-pen',             'color' => '#2563eb', 'desc' => 'توانایی تغییر عنوان، توضیحات، اولویت و تاریخ سررسید تسک'],
    'delete'         => ['label' => 'حذف تسک',                 'icon' => 'fa-trash-alt',       'color' => '#ef4444', 'desc' => 'توانایی حذف کامل تسک از سیستم'],
    'complete'       => ['label' => 'تکمیل تسک',               'icon' => 'fa-check-circle',    'color' => '#10b981', 'desc' => 'توانایی علامت‌گذاری تسک به عنوان انجام‌شده یا برگرداندن آن'],
    'reassign'       => ['label' => 'تغییر مسئول وظیفه',       'icon' => 'fa-user-friends',    'color' => '#8b5cf6', 'desc' => 'توانایی واگذاری تسک به همکار دیگر'],
    'notes'          => ['label' => 'افزودن و حذف یادداشت',    'icon' => 'fa-comment-dots',    'color' => '#f59e0b', 'desc' => 'توانایی ثبت گزارش پیشرفت و حذف یادداشت‌ها'],
    'change_project' => ['label' => 'تغییر پروژه تسک',          'icon' => 'fa-folder-tree',     'color' => '#0ea5e9', 'desc' => 'توانایی تغییر پروژه‌ی تسک‌ها (انتقال تسک به پروژه دیگر یا حذف آن از پروژه)'],
];

$makeAvatarUrl = function($avatarFile) {
    if (!empty($avatarFile) && file_exists(__DIR__ . '/../uploads/avatars/' . $avatarFile)) {
        return '../uploads/avatars/' . rawurlencode($avatarFile);
    }
    return null;
};

// ================== پاکسازی خودکار ==================
try {
    $cleanup = $db->prepare("
        DELETE c FROM colleagues c
        INNER JOIN users u ON u.id = c.colleague_user_id
        WHERE c.user_id = ? AND u.status != 'active'
    ");
    $cleanup->execute([$userId]);
} catch (PDOException $e) {
    error_log('Colleague cleanup error: ' . $e->getMessage());
}

// ================== API: جستجوی کاربر ==================
if (isset($_GET['search_mobile'])) {
    try {
        $mobile = trim((string)($_GET['search_mobile'] ?? ''));

        if ($mobile === '') {
            jsonResponse(['status' => 'error', 'message' => 'شماره موبایل را وارد کنید.']);
        }

        $mobile = str_replace(
            ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹','٠','١','٢','٣','٤','٥','٦','٧','٨','٩'],
            ['0','1','2','3','4','5','6','7','8','9','0','1','2','3','4','5','6','7','8','9'],
            $mobile
        );
        $mobile = preg_replace('/[^0-9]/', '', $mobile);

        if (!preg_match('/^09[0-9]{9}$/', $mobile)) {
            jsonResponse([
                'status' => 'error',
                'message' => 'فرمت شماره موبایل نامعتبر است. شماره باید با ۰۹ شروع شده و ۱۱ رقم باشد.'
            ]);
        }

        $stmt = $db->prepare("
            SELECT id, first_name, last_name, mobile, email, avatar,
                   allow_colleague_requests, project_join_requires_approval
            FROM users
            WHERE mobile = ? AND id != ? AND status = 'active'
            LIMIT 1
        ");
        $stmt->execute([$mobile, $userId]);
        $found = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$found) {
            jsonResponse([
                'status' => 'not_found',
                'message' => 'کاربری با این شماره موبایل یافت نشد.'
            ]);
        }

        $foundId = (int)$found['id'];

        $statusCheck = $db->prepare("
            SELECT 
                (SELECT COUNT(*) FROM colleagues WHERE user_id = ? AND colleague_user_id = ?) AS already_added,
                (SELECT COUNT(*) FROM colleague_blocks WHERE blocker_user_id = ? AND blocked_user_id = ?) AS is_blocked_by_me,
                (SELECT COUNT(*) FROM colleague_blocks WHERE blocker_user_id = ? AND blocked_user_id = ?) AS has_blocked_me,
                (SELECT COUNT(*) FROM colleague_requests WHERE requester_id = ? AND target_id = ? AND status = 'pending') AS has_pending_request
        ");
        $statusCheck->execute([
            $userId, $foundId,
            $userId, $foundId,
            $foundId, $userId,
            $userId, $foundId
        ]);
        $statusRow = $statusCheck->fetch(PDO::FETCH_ASSOC);

        $fullName = trim(($found['first_name'] ?? '') . ' ' . ($found['last_name'] ?? ''));
        if ($fullName === '') {
            $fullName = $found['mobile'];
        }
        $initial = mb_substr(trim($found['first_name'] ?: $found['mobile']), 0, 1, 'UTF-8');

        jsonResponse([
            'status'  => 'success',
            'user'    => [
                'id'         => $foundId,
                'first_name' => $found['first_name'],
                'last_name'  => $found['last_name'],
                'mobile'     => $found['mobile'],
                'email'      => $found['email'],
                'full_name'  => $fullName,
                'initial'    => $initial,
                'avatar_url' => $makeAvatarUrl($found['avatar'] ?? null),
            ],
            'already_added'         => (int)$statusRow['already_added'] > 0,
            'is_blocked'            => (int)($found['allow_colleague_requests'] ?? 1) !== 1,
            'is_blocked_by_me'      => (int)$statusRow['is_blocked_by_me'] > 0,
            'has_blocked_me'        => (int)$statusRow['has_blocked_me'] > 0,
            'has_pending_request'   => (int)$statusRow['has_pending_request'] > 0,
            'requires_approval'     => (int)($found['project_join_requires_approval'] ?? 0) === 1,
        ]);

    } catch (PDOException $e) {
        error_log('Search mobile PDO error: ' . $e->getMessage());
        jsonResponse([
            'status' => 'error',
            'message' => 'خطا در جستجوی کاربر. لطفاً دوباره تلاش کنید.'
        ], 500);
    }
}

// ================== API: افزودن همکار ==================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_colleague'])) {
    if (!verifyCsrf()) {
        jsonResponse(['status' => 'error', 'message' => 'درخواست نامعتبر است.'], 403);
    }

    $colleagueId = (int)($_POST['colleague_user_id'] ?? 0);

    if ($colleagueId <= 0 || $colleagueId === $userId) {
        jsonResponse(['status' => 'error', 'message' => 'شناسه همکار نامعتبر است.']);
    }

    try {
        $chkBlock = $db->prepare("SELECT id FROM colleague_blocks WHERE blocker_user_id = ? AND blocked_user_id = ? LIMIT 1");
        $chkBlock->execute([$userId, $colleagueId]);
        if ($chkBlock->fetchColumn()) {
            jsonResponse(['status' => 'error', 'message' => 'شما این کاربر را مسدود کرده‌اید. ابتدا از لیست مسدودشده‌ها خارجش کنید.']);
        }

        $chkBlockedMe = $db->prepare("SELECT id FROM colleague_blocks WHERE blocker_user_id = ? AND blocked_user_id = ? LIMIT 1");
        $chkBlockedMe->execute([$colleagueId, $userId]);
        if ($chkBlockedMe->fetchColumn()) {
            jsonResponse(['status' => 'error', 'message' => 'این کاربر شما را مسدود کرده است.']);
        }

        $chkUser = $db->prepare("SELECT id, first_name, last_name, mobile, allow_colleague_requests, project_join_requires_approval FROM users WHERE id = ? AND status = 'active' LIMIT 1");
        $chkUser->execute([$colleagueId]);
        $targetUser = $chkUser->fetch(PDO::FETCH_ASSOC);

        if (!$targetUser) {
            jsonResponse(['status' => 'error', 'message' => 'کاربر مورد نظر معتبر نیست.']);
        }

        if ((int)($targetUser['allow_colleague_requests'] ?? 1) !== 1) {
            jsonResponse(['status' => 'error', 'message' => 'این کاربر دریافت درخواست همکاری را مسدود کرده است.']);
        }

        // ⭐ پاکسازی درخواست‌های قبلی این جفت (چه rejected چه accepted چه pending)
        $delOld = $db->prepare("DELETE FROM colleague_requests WHERE requester_id = ? AND target_id = ?");
        $delOld->execute([$userId, $colleagueId]);

        $requiresApproval = (int)($targetUser['project_join_requires_approval'] ?? 0) === 1;

        if ($requiresApproval) {
            $insReq = $db->prepare("INSERT INTO colleague_requests (requester_id, target_id, status) VALUES (?, ?, 'pending')");
            $insReq->execute([$userId, $colleagueId]);

            $targetName = trim(($targetUser['first_name'] ?? '') . ' ' . ($targetUser['last_name'] ?? ''));
            if ($targetName === '') $targetName = $targetUser['mobile'];

            jsonResponse([
                'status' => 'pending',
                'message' => 'درخواست همکاری برای ' . $targetName . ' ارسال شد و در انتظار تایید ایشان است.',
                'requires_approval' => true
            ]);
        } else {
            try {
                $stmt = $db->prepare("INSERT INTO colleagues (user_id, colleague_user_id, permissions) VALUES (?, ?, '[]')");
                $stmt->execute([$userId, $colleagueId]);
            } catch (PDOException $e) {
                if ($e->getCode() === '23000') {
                    jsonResponse(['status' => 'error', 'message' => 'این کاربر قبلاً در لیست همکاران شما وجود دارد.']);
                }
                throw $e;
            }

            jsonResponse([
                'status' => 'success',
                'message' => 'همکار با موفقیت به لیست شما اضافه شد.',
                'requires_approval' => false
            ]);
        }

    } catch (PDOException $e) {
        error_log('Add colleague error: ' . $e->getMessage());
        jsonResponse(['status' => 'error', 'message' => 'خطا در افزودن همکار: ' . $e->getMessage()], 500);
    }
}

// ================== API: تایید درخواست همکاری ==================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accept_request'])) {
    if (!verifyCsrf()) {
        jsonResponse(['status' => 'error', 'message' => 'درخواست نامعتبر است.'], 403);
    }

    $requestId = (int)($_POST['request_id'] ?? 0);

    if ($requestId <= 0) {
        jsonResponse(['status' => 'error', 'message' => 'شناسه درخواست نامعتبر است.']);
    }

    try {
        // 1) دریافت اطلاعات درخواست
        $chkReq = $db->prepare("SELECT id, requester_id, target_id FROM colleague_requests WHERE id = ? AND target_id = ? AND status = 'pending' LIMIT 1");
        $chkReq->execute([$requestId, $userId]);
        $request = $chkReq->fetch(PDO::FETCH_ASSOC);

        if (!$request) {
            jsonResponse(['status' => 'error', 'message' => 'درخواست یافت نشد یا قبلاً بررسی شده است.']);
        }

        $requesterId = (int)$request['requester_id'];
        $targetId    = (int)$request['target_id'];

        // 2) بررسی معتبر بودن هر دو کاربر
        $chkUsers = $db->prepare("SELECT COUNT(*) FROM users WHERE id IN (?, ?) AND status = 'active'");
        $chkUsers->execute([$requesterId, $targetId]);
        if ((int)$chkUsers->fetchColumn() < 2) {
            jsonResponse(['status' => 'error', 'message' => 'یکی از کاربران معتبر نیست.']);
        }

        // 3) درج در جدول colleagues (اگر از قبل نبود)
        //    ✅ ترتیب صحیح: user_id = درخواست‌دهنده (X)، colleague_user_id = تاییدکننده (Y)
        //    نتیجه: X همکار Y را به لیست خود اضافه کرده
        try {
            $chkCol = $db->prepare("SELECT id FROM colleagues WHERE user_id = ? AND colleague_user_id = ? LIMIT 1");
            $chkCol->execute([$requesterId, $targetId]);
            if (!$chkCol->fetchColumn()) {
                $insCol = $db->prepare("INSERT INTO colleagues (user_id, colleague_user_id, permissions) VALUES (?, ?, '[]')");
                $insCol->execute([$requesterId, $targetId]);
            }
        } catch (PDOException $e) {
            error_log('Accept - insert colleague error: ' . $e->getMessage());
            // اگر خطای 23000 بود (تکراری)، نادیده بگیر
            if ($e->getCode() !== '23000') {
                jsonResponse([
                    'status' => 'error',
                    'message' => 'خطا در افزودن همکار: ' . $e->getMessage()
                ], 500);
            }
        }

        // 4) حذف تمام درخواست‌های قبلی این جفت (پاکسازی)
        $delOld = $db->prepare("DELETE FROM colleague_requests WHERE requester_id = ? AND target_id = ? AND id != ?");
        $delOld->execute([$requesterId, $targetId, $requestId]);

        // 5) به‌روزرسانی وضعیت درخواست
        $updReq = $db->prepare("UPDATE colleague_requests SET status = 'accepted', resolved_at = NOW() WHERE id = ?");
        $updReq->execute([$requestId]);

        jsonResponse([
            'status' => 'success',
            'message' => 'درخواست همکاری تایید شد.'
        ]);

    } catch (PDOException $e) {
        error_log('Accept request error: ' . $e->getMessage());
        jsonResponse([
            'status' => 'error',
            'message' => 'خطا در تایید درخواست: ' . $e->getMessage()
        ], 500);
    }
}

// ================== API: رد درخواست همکاری ==================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reject_request'])) {
    if (!verifyCsrf()) {
        jsonResponse(['status' => 'error', 'message' => 'درخواست نامعتبر است.'], 403);
    }

    $requestId = (int)($_POST['request_id'] ?? 0);

    if ($requestId <= 0) {
        jsonResponse(['status' => 'error', 'message' => 'شناسه درخواست نامعتبر است.']);
    }

    try {
        $chkReq = $db->prepare("SELECT id FROM colleague_requests WHERE id = ? AND target_id = ? AND status = 'pending' LIMIT 1");
        $chkReq->execute([$requestId, $userId]);
        if (!$chkReq->fetchColumn()) {
            jsonResponse(['status' => 'error', 'message' => 'درخواست یافت نشد یا قبلاً بررسی شده است.']);
        }

        $updReq = $db->prepare("UPDATE colleague_requests SET status = 'rejected', resolved_at = NOW() WHERE id = ?");
        $updReq->execute([$requestId]);

        jsonResponse([
            'status' => 'success',
            'message' => 'درخواست همکاری رد شد.'
        ]);

    } catch (PDOException $e) {
        error_log('Reject request error: ' . $e->getMessage());
        jsonResponse(['status' => 'error', 'message' => 'خطا در رد درخواست.'], 500);
    }
}

// ================== API: ذخیره دسترسی‌ها ==================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_permissions'])) {
    if (!verifyCsrf()) {
        jsonResponse(['status' => 'error', 'message' => 'درخواست نامعتبر است.'], 403);
    }

    $colleagueRowId = (int)($_POST['colleague_row_id'] ?? 0);
    $permKeys = $_POST['permissions'] ?? [];

    $validPerms = [];
    if (is_array($permKeys)) {
        foreach ($permKeys as $p) {
            if (isset($ALL_PERMISSIONS[$p])) {
                $validPerms[] = $p;
            }
        }
    }

    if ($colleagueRowId <= 0) {
        jsonResponse(['status' => 'error', 'message' => 'شناسه نامعتبر است.']);
    }

    try {
        $chk = $db->prepare("SELECT id FROM colleagues WHERE id = ? AND user_id = ? LIMIT 1");
        $chk->execute([$colleagueRowId, $userId]);
        if (!$chk->fetchColumn()) {
            jsonResponse(['status' => 'error', 'message' => 'دسترسی ندارید.']);
        }

        $stmt = $db->prepare("UPDATE colleagues SET permissions = ? WHERE id = ? AND user_id = ?");
        $stmt->execute([json_encode($validPerms, JSON_UNESCAPED_UNICODE), $colleagueRowId, $userId]);

        jsonResponse([
            'status' => 'success',
            'message' => 'دسترسی‌های همکار با موفقیت بروزرسانی شد.',
            'permissions' => $validPerms
        ]);

    } catch (PDOException $e) {
        error_log('Save permissions error: ' . $e->getMessage());
        jsonResponse(['status' => 'error', 'message' => 'خطا در ذخیره دسترسی‌ها.'], 500);
    }
}

// ================== API: حذف همکار ==================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['remove_colleague'])) {
    if (!verifyCsrf()) {
        jsonResponse(['status' => 'error', 'message' => 'درخواست نامعتبر است.'], 403);
    }

    $colleagueRowId = (int)($_POST['colleague_row_id'] ?? 0);

    if ($colleagueRowId <= 0) {
        jsonResponse(['status' => 'error', 'message' => 'شناسه نامعتبر است.']);
    }

    try {
        $stmt = $db->prepare("DELETE FROM colleagues WHERE id = ? AND user_id = ?");
        $stmt->execute([$colleagueRowId, $userId]);

        if ($stmt->rowCount() > 0) {
            jsonResponse(['status' => 'success', 'message' => 'همکار از لیست حذف شد.']);
        } else {
            jsonResponse(['status' => 'error', 'message' => 'همکار یافت نشد.']);
        }
    } catch (PDOException $e) {
        error_log('Remove colleague error: ' . $e->getMessage());
        jsonResponse(['status' => 'error', 'message' => 'خطا در حذف همکار.'], 500);
    }
}

// ================== API: مسدود کردن ==================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['block_user'])) {
    if (!verifyCsrf()) {
        jsonResponse(['status' => 'error', 'message' => 'درخواست نامعتبر است.'], 403);
    }

    $blockUserId = (int)($_POST['block_user_id'] ?? 0);

    if ($blockUserId <= 0 || $blockUserId === $userId) {
        jsonResponse(['status' => 'error', 'message' => 'شناسه نامعتبر است.']);
    }

    try {
        $stmt = $db->prepare("INSERT IGNORE INTO colleague_blocks (blocker_user_id, blocked_user_id) VALUES (?, ?)");
        $stmt->execute([$userId, $blockUserId]);
        jsonResponse(['status' => 'success', 'message' => 'کاربر مورد نظر مسدود شد.']);
    } catch (PDOException $e) {
        error_log('Block user error: ' . $e->getMessage());
        jsonResponse(['status' => 'error', 'message' => 'خطا در مسدودسازی.'], 500);
    }
}

// ================== API: رفع مسدودی ==================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['unblock_user'])) {
    if (!verifyCsrf()) {
        jsonResponse(['status' => 'error', 'message' => 'درخواست نامعتبر است.'], 403);
    }

    $unblockUserId = (int)($_POST['unblock_user_id'] ?? 0);

    if ($unblockUserId <= 0) {
        jsonResponse(['status' => 'error', 'message' => 'شناسه نامعتبر است.']);
    }

    try {
        $stmt = $db->prepare("DELETE FROM colleague_blocks WHERE blocker_user_id = ? AND blocked_user_id = ?");
        $stmt->execute([$userId, $unblockUserId]);
        jsonResponse(['status' => 'success', 'message' => 'مسدودیت این کاربر برداشته شد.']);
    } catch (PDOException $e) {
        error_log('Unblock user error: ' . $e->getMessage());
        jsonResponse(['status' => 'error', 'message' => 'خطا در رفع مسدودی.'], 500);
    }
}

// ================== واکشی داده‌ها ==================

// ✅ همکاران من: user_id = من، colleague_user_id = همکار
$colleagues = [];
try {
    $colleaguesStmt = $db->prepare("
        SELECT
            c.id AS row_id,
            c.permissions,
            c.created_at,
            u.id AS user_id,
            u.first_name,
            u.last_name,
            u.mobile,
            u.email,
            u.avatar,
            (SELECT COUNT(*) FROM colleague_blocks cb WHERE cb.blocker_user_id = ? AND cb.blocked_user_id = u.id) AS is_blocked_by_me,
            (SELECT COUNT(*) FROM colleague_blocks cb WHERE cb.blocker_user_id = u.id AND cb.blocked_user_id = ?) AS has_blocked_me
        FROM colleagues c
        INNER JOIN users u ON u.id = c.colleague_user_id
        WHERE c.user_id = ? AND u.status = 'active'
        ORDER BY c.created_at DESC
    ");
    $colleaguesStmt->execute([$userId, $userId, $userId]);
    $colleaguesRaw = $colleaguesStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($colleaguesRaw as $c) {
        $perms = json_decode($c['permissions'] ?? '[]', true);
        if (!is_array($perms)) $perms = [];

        $fullName = trim(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? ''));
        if ($fullName === '') $fullName = $c['mobile'];

        $c['permissions_array'] = $perms;
        $c['is_blocked_by_me'] = (int)$c['is_blocked_by_me'] === 1;
        $c['has_blocked_me'] = (int)$c['has_blocked_me'] === 1;
        $c['full_name'] = $fullName;
        $c['initial'] = mb_substr(trim($c['first_name'] ?: $c['mobile']), 0, 1, 'UTF-8');
        $c['avatar_url'] = $makeAvatarUrl($c['avatar'] ?? null);

        $colleagues[] = $c;
    }
} catch (PDOException $e) {
    error_log('Load colleagues error: ' . $e->getMessage());
}

// ✅ کسانی که منو همکار کردن: colleague_user_id = من، user_id = آن کاربر
$followers = [];
try {
    $followersStmt = $db->prepare("
        SELECT
            c.id AS row_id,
            c.created_at AS added_at,
            u.id AS user_id,
            u.first_name,
            u.last_name,
            u.mobile,
            u.email,
            u.avatar,
            (SELECT COUNT(*) FROM colleague_blocks cb WHERE cb.blocker_user_id = ? AND cb.blocked_user_id = u.id) AS is_blocked_by_me
        FROM colleagues c
        INNER JOIN users u ON u.id = c.user_id
        WHERE c.colleague_user_id = ? AND u.status = 'active'
        ORDER BY c.created_at DESC
    ");
    $followersStmt->execute([$userId, $userId]);
    $followersRaw = $followersStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($followersRaw as $f) {
        $fullName = trim(($f['first_name'] ?? '') . ' ' . ($f['last_name'] ?? ''));
        if ($fullName === '') $fullName = $f['mobile'];

        $f['is_blocked_by_me'] = (int)$f['is_blocked_by_me'] === 1;
        $f['full_name'] = $fullName;
        $f['initial'] = mb_substr(trim($f['first_name'] ?: $f['mobile']), 0, 1, 'UTF-8');
        $f['avatar_url'] = $makeAvatarUrl($f['avatar'] ?? null);

        $followers[] = $f;
    }
} catch (PDOException $e) {
    error_log('Load followers error: ' . $e->getMessage());
}

$blockedUsers = [];
try {
    $blockedStmt = $db->prepare("
        SELECT
            b.id AS block_id,
            b.created_at AS blocked_at,
            u.id AS user_id,
            u.first_name,
            u.last_name,
            u.mobile,
            u.avatar
        FROM colleague_blocks b
        INNER JOIN users u ON u.id = b.blocked_user_id
        WHERE b.blocker_user_id = ?
        ORDER BY b.created_at DESC
    ");
    $blockedStmt->execute([$userId]);
    $blockedUsersRaw = $blockedStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($blockedUsersRaw as $b) {
        $fullName = trim(($b['first_name'] ?? '') . ' ' . ($b['last_name'] ?? ''));
        if ($fullName === '') $fullName = $b['mobile'];

        $b['full_name'] = $fullName;
        $b['initial'] = mb_substr(trim($b['first_name'] ?: $b['mobile']), 0, 1, 'UTF-8');
        $b['avatar_url'] = $makeAvatarUrl($b['avatar'] ?? null);

        $blockedUsers[] = $b;
    }
} catch (PDOException $e) {
    error_log('Load blocked users error: ' . $e->getMessage());
}

// ⭐ درخواست‌های همکاری در انتظار تایید من
$pendingRequests = [];
try {
    $pendingStmt = $db->prepare("
        SELECT
            r.id AS request_id,
            r.created_at AS requested_at,
            u.id AS user_id,
            u.first_name,
            u.last_name,
            u.mobile,
            u.email,
            u.avatar
        FROM colleague_requests r
        INNER JOIN users u ON u.id = r.requester_id
        WHERE r.target_id = ? AND r.status = 'pending' AND u.status = 'active'
        ORDER BY r.created_at DESC
    ");
    $pendingStmt->execute([$userId]);
    $pendingRaw = $pendingStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($pendingRaw as $p) {
        $fullName = trim(($p['first_name'] ?? '') . ' ' . ($p['last_name'] ?? ''));
        if ($fullName === '') $fullName = $p['mobile'];

        $p['full_name'] = $fullName;
        $p['initial'] = mb_substr(trim($p['first_name'] ?: $p['mobile']), 0, 1, 'UTF-8');
        $p['avatar_url'] = $makeAvatarUrl($p['avatar'] ?? null);

        $pendingRequests[] = $p;
    }
} catch (PDOException $e) {
    error_log('Load pending requests error: ' . $e->getMessage());
}

$displayUser = $_SESSION['user_mobile'] ?? $_SESSION['mobile'] ?? $_SESSION['username'] ?? 'کاربر';

if (ob_get_level() > 0) {
    ob_end_flush();
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <title>مدیریت همکاران</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="icon" href="https://img.icons8.com/color/48/dashboard-layout.png" type="image/png">
<style>
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

    .sidebar {
        background: linear-gradient(180deg, #4c8bf5 0%, #2f6bdc 100%) !important;
        color: #fff !important;
        box-shadow: -6px 0 28px rgba(76, 139, 245, 0.18);
        overflow: visible !important;
        scrollbar-width: none !important;
        -ms-overflow-style: none !important;
    }
    .sidebar::-webkit-scrollbar { display: none !important; width: 0 !important; height: 0 !important; }
    body > .sidebar, body .sidebar {
        height: 100vh !important; height: 100dvh !important;
        max-height: 100vh !important; max-height: 100dvh !important;
        align-self: flex-start !important;
    }
    .sidebar .menu-item { color: rgba(255,255,255,0.85) !important; }
    .sidebar .menu-item:hover, .sidebar .menu-item.active {
        background: rgba(255,255,255,0.18) !important; color: #fff !important;
    }
    .sidebar .submenu a { color: rgba(255,255,255,0.75) !important; }
    .sidebar .submenu a:hover, .sidebar .submenu a.active {
        color: #fff !important; background: rgba(255,255,255,0.12) !important;
    }
    .menu-parent { color: rgba(255,255,255,0.82) !important; }
    .menu-parent:hover, .menu-parent.is-open, .menu-parent.is-current {
        background: rgba(255,255,255,0.14) !important; color: #fff !important;
    }
    .submenu-inner a { color: rgba(255,255,255,0.78) !important; }
    .submenu-inner a:hover, .submenu-inner a.active {
        color: #fff !important; background: rgba(255,255,255,0.14) !important;
    }

    .sidebar-overlay {
        display: none; position: fixed; inset: 0;
        background: rgba(20, 30, 60, 0.5); z-index: 998;
        opacity: 0; transition: opacity 0.25s ease;
    }
    .sidebar-overlay.active { display: block; opacity: 1; }

    .main-wrapper { flex: 1; display: flex; flex-direction: column; overflow: hidden; min-width: 0; }

    .topbar {
        background: var(--card);
        padding: 15px clamp(16px, 2vw, 30px);
        border-bottom: 1px solid var(--border);
        display: flex; justify-content: space-between; align-items: center;
        gap: 12px; flex-shrink: 0;
    }
    .topbar h1 {
        margin: 0; font-size: clamp(1rem, 1.4vw, 1.2rem);
        color: var(--primary); white-space: nowrap; overflow: hidden;
        text-overflow: ellipsis; display: flex; align-items: center;
    }
    .topbar-user {
        font-size: 0.9rem; font-weight: bold; white-space: nowrap;
        overflow: hidden; text-overflow: ellipsis; max-width: 220px; flex-shrink: 0;
    }
    .content-area { flex: 1; padding: clamp(12px, 2vw, 25px); overflow-y: auto; -webkit-overflow-scrolling: touch; }

    .card {
        background: var(--card); border: 1px solid var(--border);
        border-radius: 20px; padding: clamp(14px, 2vw, 24px); margin-bottom: 20px;
    }

    .search-box { display: flex; gap: 10px; align-items: stretch; }
    .search-box input[type="text"] {
        flex: 1; padding: 13px 18px; background: #f8fafc;
        border: 2px solid #e2e8f0; border-radius: 14px;
        font-size: 0.95rem; font-family: Tahoma, sans-serif;
        color: #1e293b; min-width: 0;
        transition: border-color 0.2s ease, box-shadow 0.2s ease;
    }
    .search-box input[type="text"]:focus {
        outline: none; border-color: var(--accent); background: #ffffff;
        box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.12);
    }

    button.btn {
        background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
        color: #fff; border: none; padding: 11px 22px;
        border-radius: 12px; font-weight: bold; cursor: pointer;
        transition: all 0.2s ease; font-family: Tahoma, sans-serif;
        font-size: 0.9rem; display: inline-flex; align-items: center;
        justify-content: center; gap: 5px; white-space: nowrap;
    }
    button.btn:hover { background: linear-gradient(135deg, #1d4ed8 0%, #1e40af 100%); transform: translateY(-1px); }
    button.btn:active { transform: scale(0.98); }
    button.btn:disabled { opacity: 0.5; cursor: not-allowed; transform: none; }
    button.btn-success { background: linear-gradient(135deg, #10b981 0%, #059669 100%); }
    button.btn-success:hover { background: linear-gradient(135deg, #059669 0%, #047857 100%); }
    button.btn-sm { padding: 9px 16px; font-size: 0.82rem; }
    button.btn-ghost { background: #f1f5f9; color: #64748b; box-shadow: none; }
    button.btn-ghost:hover { background: #e2e8f0; color: #334155; }

    .search-result-card {
        margin-top: 18px; padding: 18px; border-radius: 16px;
        border: 2px dashed #cbd5e1; background: #f8fafc;
        display: flex; align-items: center; justify-content: space-between; gap: 15px;
        animation: fadeIn 0.3s ease;
    }
    @keyframes fadeIn { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: translateY(0); } }
    .search-result-card.found { border-color: var(--success); border-style: solid; background: #f0fdf4; }
    .search-result-card.not-found { border-color: var(--danger); border-style: solid; background: #fef2f2; }
    .search-result-card.already { border-color: var(--warning); border-style: solid; background: #fffbeb; }
    .search-result-card.blocked { border-color: var(--danger); border-style: solid; background: #fef2f2; }
    .search-result-card.blocked-by-me { border-color: #9333ea; border-style: solid; background: #faf5ff; }
    .search-result-card.blocked-me { border-color: var(--danger); border-style: solid; background: #fef2f2; }
    .search-result-card.pending-request { border-color: #f59e0b; border-style: solid; background: #fffbeb; }
    .search-result-card.needs-approval { border-color: #8b5cf6; border-style: solid; background: #faf5ff; }

    .user-avatar {
        width: 52px; height: 52px; border-radius: 50%;
        background: linear-gradient(135deg, #2563eb, #1d4ed8);
        color: #fff; display: flex; align-items: center; justify-content: center;
        font-size: 1.3rem; font-weight: bold; flex-shrink: 0; overflow: hidden;
    }
    .user-avatar img { width: 100%; height: 100%; object-fit: cover; display: block; }
    .user-info { flex: 1; min-width: 0; }
    .user-info .name { font-weight: bold; font-size: 1rem; color: #1e293b; margin-bottom: 4px; }
    .user-info .meta { font-size: 0.8rem; color: #64748b; display: flex; gap: 15px; flex-wrap: wrap; }
    .user-info .meta span { display: flex; align-items: center; gap: 5px; }

    .colleagues-list, .followers-list, .blocked-list, .requests-list {
        display: flex; flex-direction: column; gap: 12px;
    }

    .colleague-card {
        background: #ffffff; border: 1px solid #e2e8f0;
        border-radius: 18px; padding: 20px;
        display: flex; flex-direction: column; gap: 16px;
        transition: all 0.15s ease;
    }
    .colleague-card:hover { border-color: #cbd5e1; box-shadow: 0 4px 12px rgba(0,0,0,0.04); }
    .colleague-card.is-blocked-by-them { background: #fef2f2; border-color: #fecaca; }

    .colleague-card__header {
        display: flex; align-items: center; gap: 14px;
        padding-bottom: 14px; border-bottom: 1px dashed #e2e8f0;
    }

    .colleague-avatar {
        width: 52px; height: 52px; border-radius: 50%;
        background: linear-gradient(135deg, #6366f1, #4f46e5);
        color: #fff; display: flex; align-items: center; justify-content: center;
        font-weight: bold; font-size: 1.15rem; flex-shrink: 0; overflow: hidden;
    }
    .colleague-avatar img { width: 100%; height: 100%; object-fit: cover; display: block; }
    .colleague-card.is-blocked-by-them .colleague-avatar { background: linear-gradient(135deg, #ef4444, #dc2626); }

    .colleague-info { flex: 1; min-width: 0; }
    .colleague-name {
        font-weight: bold; font-size: 1rem; color: #1e293b;
        margin-bottom: 4px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    }
    .colleague-card.is-blocked-by-them .colleague-name { color: #991b1b; }
    .colleague-mobile {
        font-size: 0.8rem; color: #64748b; direction: ltr; text-align: right;
        display: inline-flex; align-items: center; gap: 5px;
    }
    .colleague-actions { display: flex; gap: 8px; flex-shrink: 0; }

    .blocked-by-them-badge {
        display: inline-flex; align-items: center; gap: 6px;
        background: #fee2e2; color: #dc2626; border: 1px solid #fecaca;
        padding: 8px 14px; border-radius: 12px; font-size: 0.8rem;
        font-weight: bold; margin-top: 8px; line-height: 1.5;
    }

    .icon-btn {
        width: 38px; height: 38px; border-radius: 11px;
        border: 1px solid #e2e8f0; background: #f8fafc; color: #64748b;
        cursor: pointer; display: flex; align-items: center; justify-content: center;
        transition: all 0.15s ease; font-size: 0.9rem; flex-shrink: 0;
    }
    .icon-btn:hover { background: var(--accent); color: #fff; border-color: var(--accent); }
    .icon-btn.danger { color: var(--danger); }
    .icon-btn.danger:hover { background: var(--danger); border-color: var(--danger); color: #fff; }
    .icon-btn.success { color: var(--success); }
    .icon-btn.success:hover { background: var(--success); border-color: var(--success); color: #fff; }
    .icon-btn.active { background: var(--accent); color: #fff; border-color: var(--accent); }
    .icon-btn:disabled { opacity: 0.4; cursor: not-allowed; pointer-events: none; }

    .permissions-panel { display: none; }
    .permissions-panel.open { display: block; animation: fadeIn 0.25s ease; }

    .permissions-title {
        font-size: 0.85rem; font-weight: bold; color: #475569;
        margin-bottom: 14px; display: flex; align-items: center; gap: 8px;
    }
    .permissions-grid {
        display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
        gap: 10px; margin-bottom: 14px;
    }

    .perm-option {
        display: flex; align-items: center; gap: 12px;
        padding: 12px 14px; background: #f8fafc;
        border: 2px solid #e2e8f0; border-radius: 12px;
        cursor: pointer; transition: all 0.15s ease; user-select: none;
    }
    .perm-option:hover { border-color: #cbd5e1; background: #ffffff; }
    .perm-option.checked { border-color: var(--accent); background: linear-gradient(135deg, rgba(37,99,235,0.08), rgba(37,99,235,0.03)); }

    .perm-checkbox {
        width: 22px; height: 22px; border-radius: 6px;
        border: 2px solid #cbd5e1; background: #ffffff;
        display: flex; align-items: center; justify-content: center;
        color: #ffffff; font-size: 0.7rem; flex-shrink: 0;
        transition: all 0.15s ease;
    }
    .perm-option.checked .perm-checkbox { background: var(--accent); border-color: var(--accent); }
    .perm-icon {
        width: 36px; height: 36px; border-radius: 10px;
        display: flex; align-items: center; justify-content: center;
        font-size: 0.9rem; flex-shrink: 0;
    }
    .perm-text { flex: 1; min-width: 0; }
    .perm-label { font-size: 0.85rem; font-weight: bold; color: #334155; margin-bottom: 2px; }
    .perm-desc { font-size: 0.72rem; color: #94a3b8; line-height: 1.4; }

    .permissions-actions {
        display: flex; gap: 8px; justify-content: flex-end;
        padding-top: 14px; border-top: 1px dashed #e2e8f0;
    }
    .permissions-summary { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 6px; }
    .perm-badge {
        font-size: 0.7rem; font-weight: bold; padding: 3px 9px;
        border-radius: 6px; display: inline-flex; align-items: center; gap: 4px;
    }

    .follower-card, .blocked-card, .request-card {
        display: flex; align-items: center; gap: 14px;
        padding: 14px 18px; background: #f8fafc;
        border: 1px solid #e2e8f0; border-radius: 14px;
        transition: all 0.15s ease;
    }
    .follower-card:hover, .blocked-card:hover, .request-card:hover {
        border-color: #cbd5e1; background: #ffffff;
    }
    .follower-card.is-blocked { background: #fef2f2; border-color: #fecaca; opacity: 0.75; }
    .request-card { background: #fffbeb; border-color: #fde68a; }
    .request-card:hover { background: #fef3c7; }

    .follower-avatar, .blocked-avatar, .request-avatar {
        width: 46px; height: 46px; border-radius: 50%;
        color: #fff; display: flex; align-items: center; justify-content: center;
        font-weight: bold; font-size: 1rem; flex-shrink: 0; overflow: hidden;
    }
    .follower-avatar { background: linear-gradient(135deg, #10b981, #059669); }
    .blocked-avatar { background: linear-gradient(135deg, #ef4444, #dc2626); }
    .request-avatar { background: linear-gradient(135deg, #f59e0b, #d97706); }
    .follower-avatar img, .blocked-avatar img, .request-avatar img {
        width: 100%; height: 100%; object-fit: cover; display: block;
    }
    .follower-card.is-blocked .follower-avatar { background: linear-gradient(135deg, #ef4444, #dc2626); }

    .follower-info, .blocked-info, .request-info { flex: 1; min-width: 0; }
    .follower-name, .blocked-name, .request-name {
        font-weight: bold; font-size: 0.92rem; color: #1e293b;
        margin-bottom: 3px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    }
    .blocked-name { color: #991b1b; }
    .request-name { color: #92400e; }

    .follower-meta, .blocked-meta, .request-meta {
        font-size: 0.75rem; color: #64748b;
        display: flex; align-items: center; gap: 12px; flex-wrap: wrap;
    }
    .follower-meta span, .blocked-meta span, .request-meta span {
        display: inline-flex; align-items: center; gap: 5px;
    }
    .follower-meta .mobile, .blocked-meta .mobile, .request-meta .mobile { direction: ltr; }
    .blocked-meta { color: #b91c1c; }
    .request-meta { color: #92400e; }

    .follower-actions, .request-actions {
        display: flex; gap: 8px; flex-shrink: 0; align-items: center;
    }

    .btn-block, .btn-unblock, .btn-accept, .btn-reject {
        border-radius: 10px; padding: 8px 14px;
        font-size: 0.8rem; font-weight: bold; cursor: pointer;
        transition: all 0.15s ease; font-family: Tahoma, sans-serif;
        display: inline-flex; align-items: center; gap: 6px;
        border: 1px solid;
    }
    .btn-block { background: #fef2f2; color: var(--danger); border-color: #fee2e2; }
    .btn-block:hover { background: var(--danger); color: #fff; border-color: var(--danger); }
    .btn-unblock { background: #f0fdf4; color: var(--success); border-color: #a7f3d0; }
    .btn-unblock:hover { background: var(--success); color: #fff; border-color: var(--success); }
    .btn-accept { background: #f0fdf4; color: var(--success); border-color: #a7f3d0; }
    .btn-accept:hover { background: var(--success); color: #fff; border-color: var(--success); }
    .btn-reject { background: #fef2f2; color: var(--danger); border-color: #fee2e2; }
    .btn-reject:hover { background: var(--danger); color: #fff; border-color: var(--danger); }

    .block-status-badge {
        font-size: 0.7rem; font-weight: bold; padding: 3px 10px;
        border-radius: 6px; display: inline-flex; align-items: center; gap: 4px;
    }
    .block-status-badge.blocked { background: #fee2e2; color: var(--danger); }
    .block-status-badge.active { background: #d1fae5; color: var(--success); }

    .alert {
        padding: 12px 16px; border-radius: 12px;
        margin-bottom: 18px; font-weight: bold; font-size: 0.9rem;
    }
    .alert-success { background: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; }
    .alert-warning { background: #fef3c7; color: #92400e; border: 1px solid #fde68a; }
    .alert-danger  { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }

    .empty-state { text-align: center; color: #94a3b8; padding: 40px 20px; font-size: 0.9rem; }
    .empty-state i { font-size: 2.5rem; margin-bottom: 12px; opacity: 0.5; display: block; }

    .loading-spinner {
        display: inline-block; width: 16px; height: 16px;
        border: 2px solid #fff; border-top-color: transparent;
        border-radius: 50%; animation: spin 0.8s linear infinite;
    }
    @keyframes spin { to { transform: rotate(360deg); } }

    .count-badge {
        background: #e0f2fe; color: #0284c7; font-size: 0.75rem;
        padding: 3px 10px; border-radius: 8px; margin-right: 5px;
    }
    .count-badge.green { background: #d1fae5; color: #059669; }
    .count-badge.red { background: #fee2e2; color: #dc2626; }
    .count-badge.orange { background: #fef3c7; color: #d97706; }
    .count-badge.purple { background: #ede9fe; color: #7c3aed; }

    .empty-blocked {
        text-align: center; padding: 22px 20px;
        background: #f8fafc; border: 2px dashed #e2e8f0;
        border-radius: 14px; color: #94a3b8; font-size: 0.82rem;
        display: flex; align-items: center; justify-content: center; gap: 10px;
    }
    .empty-blocked i { font-size: 1.4rem; opacity: 0.5; }

    .custom-modal-overlay {
        position: fixed; inset: 0;
        background: rgba(15, 23, 42, 0.6);
        backdrop-filter: blur(4px);
        display: none; align-items: center; justify-content: center;
        z-index: 2000; padding: 20px;
        animation: fadeIn 0.2s ease;
    }
    .custom-modal-overlay.active { display: flex; }

    .custom-modal {
        background: #fff; border-radius: 20px;
        padding: 28px; max-width: 440px; width: 100%;
        box-shadow: 0 24px 60px rgba(20, 30, 60, 0.25);
        animation: modalIn 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
    }
    @keyframes modalIn {
        from { transform: translateY(20px) scale(0.95); opacity: 0; }
        to { transform: translateY(0) scale(1); opacity: 1; }
    }

    .custom-modal-header {
        display: flex; align-items: center; gap: 14px; margin-bottom: 18px;
    }
    .custom-modal-icon {
        width: 50px; height: 50px; border-radius: 14px;
        display: flex; align-items: center; justify-content: center;
        font-size: 1.3rem; flex-shrink: 0;
    }
    .custom-modal-icon.warning { background: #fef3c7; color: #d97706; }
    .custom-modal-icon.danger { background: #fee2e2; color: #dc2626; }
    .custom-modal-icon.success { background: #d1fae5; color: #059669; }
    .custom-modal-icon.info { background: #dbeafe; color: #2563eb; }
    .custom-modal-icon.question { background: #ede9fe; color: #7c3aed; }

    .custom-modal-title {
        font-size: 1.05rem; font-weight: bold; color: #1e293b; margin: 0;
    }
    .custom-modal-body {
        font-size: 0.9rem; color: #475569; line-height: 1.7;
        margin-bottom: 22px;
    }
    .custom-modal-actions {
        display: flex; gap: 10px; justify-content: flex-end;
    }
    .custom-modal-actions button {
        padding: 10px 20px; border-radius: 11px;
        font-weight: bold; font-size: 0.88rem; cursor: pointer;
        border: none; font-family: Tahoma, sans-serif;
        transition: all 0.15s ease;
    }
    .custom-modal-actions .btn-cancel {
        background: #f1f5f9; color: #475569;
    }
    .custom-modal-actions .btn-cancel:hover { background: #e2e8f0; }
    .custom-modal-actions .btn-confirm {
        background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
        color: #fff;
    }
    .custom-modal-actions .btn-confirm:hover { background: linear-gradient(135deg, #1d4ed8 0%, #1e40af 100%); }
    .custom-modal-actions .btn-confirm.danger {
        background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
    }
    .custom-modal-actions .btn-confirm.danger:hover { background: linear-gradient(135deg, #dc2626 0%, #b91c1c 100%); }
    .custom-modal-actions .btn-confirm.success {
        background: linear-gradient(135deg, #10b981 0%, #059669 100%);
    }
    .custom-modal-actions .btn-confirm.success:hover { background: linear-gradient(135deg, #059669 0%, #047857 100%); }

    .app-toast {
        position: fixed; bottom: 30px; left: 50%;
        transform: translateX(-50%) translateY(80px);
        background: linear-gradient(135deg, #1e293b, #0f172a);
        color: #fff; padding: 12px 22px; border-radius: 30px;
        font-size: 0.85rem; font-weight: bold;
        display: flex; align-items: center; gap: 10px;
        box-shadow: 0 12px 32px rgba(20, 30, 60, 0.4);
        z-index: 9999; opacity: 0;
        transition: all 0.35s cubic-bezier(0.34, 1.56, 0.64, 1);
        pointer-events: none; max-width: 90vw;
    }
    .app-toast.active { opacity: 1; transform: translateX(-50%) translateY(0); }
    .app-toast i { font-size: 1rem; }
    .app-toast.toast-success i { color: #4ed4a3; }
    .app-toast.toast-error i { color: #ff6b8b; }
    .app-toast.toast-info i { color: #4c8bf5; }
    .app-toast.toast-warning i { color: #fbbf24; }

    .hamburger-btn {
        display: none; width: 44px; height: 44px; border: none;
        background: linear-gradient(135deg, #4c8bf5 0%, #2f6bdc 100%);
        border-radius: 13px; cursor: pointer; flex-shrink: 0;
        align-items: center; justify-content: center; padding: 0;
        transition: all 0.15s ease;
        box-shadow: 0 6px 16px rgba(76, 139, 245, 0.25);
    }
    .hamburger-btn:hover { transform: translateY(-1px); box-shadow: 0 8px 20px rgba(76, 139, 245, 0.35); }
    .hamburger-btn .hamburger-lines {
        width: 22px; height: 16px;
        display: flex; flex-direction: column; justify-content: space-between;
    }
    .hamburger-btn .hamburger-lines span {
        display: block; height: 2.5px; width: 100%; background: #fff;
        border-radius: 3px; transition: all 0.3s; transform-origin: center;
    }
    .hamburger-btn .hamburger-lines span:nth-child(2) { width: 70%; }
    .hamburger-btn.active .hamburger-lines span:nth-child(1) { transform: translateY(6.75px) rotate(45deg); }
    .hamburger-btn.active .hamburger-lines span:nth-child(2) { opacity: 0; transform: translateX(-10px); }
    .hamburger-btn.active .hamburger-lines span:nth-child(3) { transform: translateY(-6.75px) rotate(-45deg); }

    @media (max-width: 1100px) {
        .permissions-grid { grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); }
    }
    @media (max-width: 900px) {
        .hamburger-btn { display: flex; }
        body .sidebar {
            position: fixed !important;
            top: 0 !important; right: 0 !important; bottom: 0 !important; left: auto !important;
            width: 280px !important; min-width: 280px !important; max-width: 85vw !important;
            height: 100vh !important; height: 100dvh !important;
            transform: translateX(105%) !important;
            transition: transform 0.35s cubic-bezier(0.65, 0, 0.35, 1) !important;
            z-index: 999 !important;
            box-shadow: -20px 0 50px rgba(0, 0, 0, 0.25) !important;
        }
        body .sidebar.open { transform: translateX(0) !important; }
        .main-wrapper { width: 100%; }
        .topbar { padding: 12px 16px; gap: 12px; flex-wrap: nowrap; }
        .topbar h1 { font-size: 0.95rem; flex: 1; margin: 0; text-align: center; justify-content: center; }
        .topbar-user { display: none; }
        .content-area { padding: 14px; }
        .card { padding: 16px; border-radius: 16px; }
    }
    @media (max-width: 700px) {
        .content-area { padding: 12px; }
        .topbar { padding: 10px 14px; }
        .card { padding: 14px; border-radius: 14px; }
        .search-box { flex-direction: column; gap: 8px; }
        .search-box input[type="text"], .search-box .btn { width: 100%; }
        .search-result-card { flex-direction: column; align-items: stretch; gap: 12px; padding: 14px; }

        .permissions-grid { grid-template-columns: 1fr; gap: 8px; }
        .perm-option { padding: 10px 12px; gap: 10px; }
        .perm-checkbox { width: 20px; height: 20px; }
        .perm-icon { width: 32px; height: 32px; font-size: 0.82rem; }
        .permissions-actions { flex-direction: column-reverse; }
        .permissions-actions .btn { width: 100%; }

        .colleague-card { padding: 14px; gap: 12px; border-radius: 14px; }
        .colleague-card__header { flex-wrap: wrap; gap: 12px; padding-bottom: 12px; }
        .colleague-avatar { width: 44px; height: 44px; font-size: 1rem; }
        .colleague-actions { width: 100%; justify-content: flex-end; }

        .follower-card, .blocked-card, .request-card { flex-wrap: wrap; gap: 12px; padding: 12px 14px; }
        .follower-avatar, .blocked-avatar, .request-avatar { width: 42px; height: 42px; }
        .follower-actions, .request-actions { width: 100%; justify-content: flex-end; }
        .btn-block, .btn-unblock, .btn-accept, .btn-reject { font-size: 0.75rem; padding: 7px 12px; }
    }
    @media (max-width: 400px) {
        .card { padding: 12px; }
        .colleague-avatar { width: 40px; height: 40px; }
        .follower-avatar, .blocked-avatar, .request-avatar { width: 38px; height: 38px; }
        .user-avatar { width: 44px; height: 44px; }
    }
    @media (prefers-reduced-motion: reduce) {
        *, *::before, *::after { transition: none !important; animation: none !important; }
    }
    body, .topbar, .card, .colleague-card, .follower-card, .blocked-card, .perm-option,
    .search-result-card, button, a, .user-info, .colleague-info, .follower-info, .blocked-info {
        -webkit-user-select: none; -moz-user-select: none; -ms-user-select: none; user-select: none;
    }
    input, textarea {
        -webkit-user-select: text; -moz-user-select: text; -ms-user-select: text; user-select: text;
    }
    img, a {
        -webkit-user-drag: none; -khtml-user-drag: none; -moz-user-drag: none;
        -o-user-drag: none; user-drag: none;
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
        <h1><i class="fas fa-users" style="color: var(--accent); margin-left: 8px;"></i>مدیریت همکاران و دسترسی‌ها</h1>
        <div class="topbar-user">کاربر: <?= htmlspecialchars($displayUser) ?></div>
    </div>

    <div class="content-area">
        <?php if (!empty($msg)): ?>
            <div class="alert alert-<?= htmlspecialchars($msgType) ?>"><?= htmlspecialchars($msg) ?></div>
        <?php endif; ?>

        <!-- درخواست‌های همکاری در انتظار تایید -->
        <?php if (!empty($pendingRequests)): ?>
            <div class="card" style="border: 2px solid #fde68a; background: #fffbeb;">
                <h3 style="margin-top:0; font-size:1.05rem; color: #92400e; margin-bottom: 18px; display: flex; align-items: center; gap: 8px;">
                    <i class="fas fa-bell" style="color: #f59e0b;"></i>
                    درخواست‌های همکاری در انتظار تایید شما
                    <span class="count-badge orange"><?= count($pendingRequests) ?></span>
                </h3>
                <div class="requests-list">
                    <?php foreach ($pendingRequests as $p): ?>
                        <div class="request-card" data-request-id="<?= (int)$p['request_id'] ?>">
                            <div class="request-avatar">
                                <?php if ($p['avatar_url']): ?>
                                    <img src="<?= htmlspecialchars($p['avatar_url']) ?>" alt="">
                                <?php else: ?>
                                    <?= htmlspecialchars($p['initial']) ?>
                                <?php endif; ?>
                            </div>
                            <div class="request-info">
                                <div class="request-name" title="<?= htmlspecialchars($p['full_name']) ?>">
                                    <?= htmlspecialchars($p['full_name']) ?>
                                </div>
                                <div class="request-meta">
                                    <span class="mobile"><i class="fas fa-mobile-alt"></i> <?= htmlspecialchars($p['mobile']) ?></span>
                                    <span><i class="fas fa-clock"></i> <?= htmlspecialchars($p['requested_at']) ?></span>
                                </div>
                            </div>
                            <div class="request-actions">
                                <button type="button" class="btn-accept" onclick="acceptRequest(<?= (int)$p['request_id'] ?>, this)">
                                    <i class="fas fa-check"></i> تایید
                                </button>
                                <button type="button" class="btn-reject" onclick="rejectRequest(<?= (int)$p['request_id'] ?>, this)">
                                    <i class="fas fa-times"></i> رد
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- جستجو -->
        <div class="card">
            <h3 style="margin-top:0; font-size:1.05rem; color: var(--primary); margin-bottom: 18px; display: flex; align-items: center; gap: 8px;">
                <i class="fas fa-search" style="color: var(--accent);"></i>
                جستجوی کاربر با شماره موبایل
            </h3>

            <div class="search-box">
                <input type="text" id="mobileSearchInput" placeholder="مثلاً: 09123456789" autocomplete="off" inputmode="numeric">
                <button type="button" class="btn" id="searchBtn" onclick="searchUser()">
                    <i class="fas fa-search" style="margin-left: 5px;"></i> جستجو
                </button>
            </div>

            <div id="searchResultContainer"></div>
        </div>

        <!-- همکاران من -->
        <div class="card">
            <h3 style="margin-top:0; font-size:1.05rem; color: var(--primary); margin-bottom: 18px; display: flex; align-items: center; gap: 8px;">
                <i class="fas fa-user-friends" style="color: var(--accent);"></i>
                همکاران من
                <span class="count-badge" id="colleaguesCount"><?= count($colleagues) ?></span>
                <?php
                    $blockedByThemCount = count(array_filter($colleagues, fn($c) => $c['has_blocked_me']));
                    if ($blockedByThemCount > 0):
                ?>
                    <span class="count-badge orange"><?= $blockedByThemCount ?> مسدودت کرده</span>
                <?php endif; ?>
            </h3>

            <div id="colleaguesListContainer">
                <?php if (empty($colleagues)): ?>
                    <div class="empty-state" id="colleaguesEmpty">
                        <i class="fas fa-user-plus"></i>
                        هنوز هیچ همکاری اضافه نکرده‌اید.<br>
                        با جستجوی شماره موبایل، همکاران خود را اضافه کنید.
                    </div>
                <?php else: ?>
                    <div class="colleagues-list">
                        <?php foreach ($colleagues as $c): ?>
                            <?php $hasBlockedMe = $c['has_blocked_me']; ?>
                            <div class="colleague-card <?= $hasBlockedMe ? 'is-blocked-by-them' : '' ?>" data-row-id="<?= (int)$c['row_id'] ?>">
                                <div class="colleague-card__header">
                                    <div class="colleague-avatar">
                                        <?php if ($c['avatar_url']): ?>
                                            <img src="<?= htmlspecialchars($c['avatar_url']) ?>" alt="">
                                        <?php elseif ($hasBlockedMe): ?>
                                            <i class="fas fa-user-slash"></i>
                                        <?php else: ?>
                                            <?= htmlspecialchars($c['initial']) ?>
                                        <?php endif; ?>
                                    </div>

                                    <div class="colleague-info">
                                        <div class="colleague-name" title="<?= htmlspecialchars($c['full_name']) ?>">
                                            <?= htmlspecialchars($c['full_name']) ?>
                                        </div>
                                        <div class="colleague-mobile">
                                            <i class="fas fa-mobile-alt"></i>
                                            <?= htmlspecialchars($c['mobile']) ?>
                                        </div>

                                        <?php if ($hasBlockedMe): ?>
                                            <div class="blocked-by-them-badge">
                                                <i class="fas fa-ban"></i>
                                                این کاربر شما را مسدود کرده است — امکان واگذاری تسک وجود ندارد
                                            </div>
                                        <?php else: ?>
                                            <div class="permissions-summary">
                                                <?php if (empty($c['permissions_array'])): ?>
                                                    <span class="perm-badge" style="background:#f1f5f9; color:#64748b;">
                                                        <i class="fas fa-eye"></i> فقط مشاهده (بدون دسترسی)
                                                    </span>
                                                <?php else: ?>
                                                    <?php foreach ($c['permissions_array'] as $pKey): ?>
                                                        <?php if (isset($ALL_PERMISSIONS[$pKey])): ?>
                                                            <?php $pInfo = $ALL_PERMISSIONS[$pKey]; ?>
                                                            <span class="perm-badge" style="background: <?= $pInfo['color'] ?>22; color: <?= $pInfo['color'] ?>;">
                                                                <i class="fas <?= $pInfo['icon'] ?>"></i>
                                                                <?= htmlspecialchars($pInfo['label']) ?>
                                                            </span>
                                                        <?php endif; ?>
                                                    <?php endforeach; ?>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>

                                    <div class="colleague-actions">
                                        <button type="button"
                                                class="icon-btn"
                                                id="perm-toggle-btn-<?= (int)$c['row_id'] ?>"
                                                onclick="togglePermissionsPanel(<?= (int)$c['row_id'] ?>)"
                                                title="تنظیم دسترسی‌ها"
                                                <?= $hasBlockedMe ? 'disabled' : '' ?>>
                                            <i class="fas fa-shield-alt"></i>
                                        </button>

                                        <button type="button" class="icon-btn danger" title="حذف"
                                                onclick="confirmRemoveColleague(<?= (int)$c['row_id'] ?>, '<?= htmlspecialchars($c['full_name'], ENT_QUOTES) ?>')">
                                            <i class="fas fa-trash-alt"></i>
                                        </button>
                                    </div>
                                </div>

                                <?php if (!$hasBlockedMe): ?>
                                    <div class="permissions-panel" id="perm-panel-<?= (int)$c['row_id'] ?>">
                                        <div class="permissions-title">
                                            <i class="fas fa-shield-alt" style="color: var(--accent);"></i>
                                            دسترسی‌های این همکار روی تسک‌های شما
                                        </div>

                                        <div class="permissions-grid" data-row-id="<?= (int)$c['row_id'] ?>">
                                            <?php foreach ($ALL_PERMISSIONS as $pKey => $pInfo): ?>
                                                <?php $isChecked = in_array($pKey, $c['permissions_array'], true); ?>
                                                <label class="perm-option <?= $isChecked ? 'checked' : '' ?>" data-perm="<?= htmlspecialchars($pKey) ?>">
                                                    <input type="checkbox" value="<?= htmlspecialchars($pKey) ?>"
                                                           <?= $isChecked ? 'checked' : '' ?> style="display:none;" onchange="togglePermOption(this)">
                                                    <span class="perm-checkbox"><i class="fas fa-check"></i></span>
                                                    <span class="perm-icon" style="background: <?= $pInfo['color'] ?>18; color: <?= $pInfo['color'] ?>;">
                                                        <i class="fas <?= $pInfo['icon'] ?>"></i>
                                                    </span>
                                                    <span class="perm-text">
                                                        <div class="perm-label"><?= htmlspecialchars($pInfo['label']) ?></div>
                                                        <div class="perm-desc"><?= htmlspecialchars($pInfo['desc']) ?></div>
                                                    </span>
                                                </label>
                                            <?php endforeach; ?>
                                        </div>

                                        <div class="permissions-actions">
                                            <button type="button" class="btn btn-sm btn-ghost" onclick="closePermissionsPanel(<?= (int)$c['row_id'] ?>)">
                                                انصراف
                                            </button>
                                            <button type="button" class="btn btn-sm" onclick="savePermissions(<?= (int)$c['row_id'] ?>, this)">
                                                <i class="fas fa-save" style="margin-left: 4px;"></i>
                                                ذخیره دسترسی‌ها
                                            </button>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- کسانی که منو همکار کردن -->
        <div class="card">
            <h3 style="margin-top:0; font-size:1.05rem; color: var(--primary); margin-bottom: 8px; display: flex; align-items: center; gap: 8px;">
                <i class="fas fa-user-check" style="color: #10b981;"></i>
                کسانی که منو همکار کردن
                <span class="count-badge green"><?= count(array_filter($followers, fn($f) => !$f['is_blocked_by_me'])) ?></span>
                <?php if (count(array_filter($followers, fn($f) => $f['is_blocked_by_me'])) > 0): ?>
                    <span class="count-badge red"><?= count(array_filter($followers, fn($f) => $f['is_blocked_by_me'])) ?> مسدود</span>
                <?php endif; ?>
            </h3>
            <p style="font-size: 0.78rem; color: #94a3b8; margin: 0 0 18px 0; line-height: 1.6;">
                این کاربران شما را به عنوان همکار اضافه کرده‌اند و به شما دسترسی داده‌اند.
                در صورت تمایل می‌توانید هر کاربر را به صورت فردی مسدود کنید.
            </p>

            <?php if (empty($followers)): ?>
                <div class="empty-state">
                    <i class="fas fa-user-slash"></i>
                    هنوز هیچ کاربری شما را به عنوان همکار اضافه نکرده است.
                </div>
            <?php else: ?>
                <div class="followers-list">
                    <?php foreach ($followers as $f): ?>
                        <?php $isBlockedByMe = $f['is_blocked_by_me']; ?>
                        <div class="follower-card <?= $isBlockedByMe ? 'is-blocked' : '' ?>" data-user-id="<?= (int)$f['user_id'] ?>">
                            <div class="follower-avatar">
                                <?php if ($f['avatar_url']): ?>
                                    <img src="<?= htmlspecialchars($f['avatar_url']) ?>" alt="">
                                <?php elseif ($isBlockedByMe): ?>
                                    <i class="fas fa-user-slash"></i>
                                <?php else: ?>
                                    <?= htmlspecialchars($f['initial']) ?>
                                <?php endif; ?>
                            </div>

                            <div class="follower-info">
                                <div class="follower-name" title="<?= htmlspecialchars($f['full_name']) ?>">
                                    <?= htmlspecialchars($f['full_name']) ?>
                                </div>
                                <div class="follower-meta">
                                    <span class="mobile"><i class="fas fa-mobile-alt"></i> <?= htmlspecialchars($f['mobile']) ?></span>
                                    <span><i class="fas fa-clock"></i> <?= htmlspecialchars($f['added_at']) ?></span>
                                    <?php if ($isBlockedByMe): ?>
                                        <span class="block-status-badge blocked"><i class="fas fa-ban"></i> مسدود توسط شما</span>
                                    <?php else: ?>
                                        <span class="block-status-badge active"><i class="fas fa-check-circle"></i> فعال</span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="follower-actions">
                                <?php if ($isBlockedByMe): ?>
                                    <button type="button" class="btn-unblock" onclick="unblockUser(<?= (int)$f['user_id'] ?>, this)">
                                        <i class="fas fa-unlock"></i> رفع مسدودی
                                    </button>
                                <?php else: ?>
                                    <button type="button" class="btn-block" onclick="confirmBlockUser(<?= (int)$f['user_id'] ?>, '<?= htmlspecialchars($f['full_name'], ENT_QUOTES) ?>')">
                                        <i class="fas fa-ban"></i> مسدود کن
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- لیست مسدودشده‌های من -->
        <div class="card">
            <h3 style="margin-top:0; font-size:1.05rem; color: var(--primary); margin-bottom: 8px; display: flex; align-items: center; gap: 8px;">
                <i class="fas fa-user-lock" style="color: #dc2626;"></i>
                کاربران مسدودشده توسط من
                <span class="count-badge red"><?= count($blockedUsers) ?></span>
            </h3>
            <p style="font-size: 0.78rem; color: #94a3b8; margin: 0 0 18px 0; line-height: 1.6;">
                این کاربران توسط شما مسدود شده‌اند و نمی‌توانند شما را به عنوان همکار اضافه کنند.
                در هر زمان می‌توانید انسداد هرکدام را بردارید.
            </p>

            <?php if (empty($blockedUsers)): ?>
                <div class="empty-blocked">
                    <i class="fas fa-shield-alt"></i>
                    <span>هیچ کاربری را مسدود نکرده‌اید.</span>
                </div>
            <?php else: ?>
                <div class="blocked-list">
                    <?php foreach ($blockedUsers as $b): ?>
                        <div class="blocked-card" data-user-id="<?= (int)$b['user_id'] ?>">
                            <div class="blocked-avatar">
                                <?php if ($b['avatar_url']): ?>
                                    <img src="<?= htmlspecialchars($b['avatar_url']) ?>" alt="">
                                <?php else: ?>
                                    <i class="fas fa-user-slash"></i>
                                <?php endif; ?>
                            </div>

                            <div class="blocked-info">
                                <div class="blocked-name" title="<?= htmlspecialchars($b['full_name']) ?>">
                                    <?= htmlspecialchars($b['full_name']) ?>
                                </div>
                                <div class="blocked-meta">
                                    <span class="mobile"><i class="fas fa-mobile-alt"></i> <?= htmlspecialchars($b['mobile']) ?></span>
                                    <span><i class="fas fa-clock"></i> مسدود شده در: <?= htmlspecialchars($b['blocked_at']) ?></span>
                                </div>
                            </div>

                            <div class="follower-actions">
                                <button type="button" class="btn-unblock" onclick="unblockUser(<?= (int)$b['user_id'] ?>, this)">
                                    <i class="fas fa-unlock"></i> رفع انسداد
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Custom Confirm Modal -->
<div class="custom-modal-overlay" id="confirmModal">
    <div class="custom-modal">
        <div class="custom-modal-header">
            <div class="custom-modal-icon warning" id="confirmModalIcon">
                <i class="fas fa-question"></i>
            </div>
            <h3 class="custom-modal-title" id="confirmModalTitle">تایید عملیات</h3>
        </div>
        <div class="custom-modal-body" id="confirmModalBody">
            آیا از انجام این عملیات مطمئن هستید؟
        </div>
        <div class="custom-modal-actions">
            <button type="button" class="btn-cancel" onclick="closeConfirmModal()">انصراف</button>
            <button type="button" class="btn-confirm" id="confirmModalOkBtn">تایید</button>
        </div>
    </div>
</div>

<!-- Toast -->
<div class="app-toast" id="appToast">
    <i class="fas fa-check-circle"></i>
    <span id="appToastText"></span>
</div>

<script>
    const CSRF_TOKEN = <?= json_encode($csrfToken) ?>;

    /* غیرفعال کردن راست کلیک */
    document.addEventListener('contextmenu', function(e) {
        const tag = (e.target.tagName || '').toLowerCase();
        if (tag === 'input' || tag === 'textarea') return true;
        e.preventDefault();
        return false;
    });
    document.addEventListener('dragstart', function(e) {
        if (e.target.tagName === 'IMG' || e.target.tagName === 'A') e.preventDefault();
    });

    /* Toast */
    let toastTimer = null;
    function showToast(text, type = 'success') {
        const toast = document.getElementById('appToast');
        const textEl = document.getElementById('appToastText');
        const icon = toast.querySelector('i');
        textEl.textContent = text;
        toast.classList.remove('toast-success', 'toast-error', 'toast-info', 'toast-warning');
        if (type === 'success') { icon.className = 'fas fa-check-circle'; toast.classList.add('toast-success'); }
        else if (type === 'error') { icon.className = 'fas fa-exclamation-circle'; toast.classList.add('toast-error'); }
        else if (type === 'warning') { icon.className = 'fas fa-exclamation-triangle'; toast.classList.add('toast-warning'); }
        else { icon.className = 'fas fa-info-circle'; toast.classList.add('toast-info'); }
        toast.classList.add('active');
        clearTimeout(toastTimer);
        toastTimer = setTimeout(() => toast.classList.remove('active'), 3000);
    }

    /* Custom Confirm Modal */
    let confirmCallback = null;
    function showConfirm(options) {
        const {
            title = 'تایید عملیات',
            body = 'آیا از انجام این عملیات مطمئن هستید؟',
            okText = 'تایید',
            cancelText = 'انصراف',
            iconType = 'warning',
            confirmType = 'primary',
            onConfirm = null
        } = options;

        const modal = document.getElementById('confirmModal');
        const iconEl = document.getElementById('confirmModalIcon');
        const titleEl = document.getElementById('confirmModalTitle');
        const bodyEl = document.getElementById('confirmModalBody');
        const okBtn = document.getElementById('confirmModalOkBtn');
        const cancelBtn = modal.querySelector('.btn-cancel');

        iconEl.className = 'custom-modal-icon ' + iconType;
        iconEl.innerHTML = iconType === 'danger'
            ? '<i class="fas fa-exclamation-triangle"></i>'
            : iconType === 'success'
                ? '<i class="fas fa-check-circle"></i>'
                : iconType === 'question'
                    ? '<i class="fas fa-question-circle"></i>'
                    : '<i class="fas fa-exclamation-circle"></i>';

        titleEl.textContent = title;
        bodyEl.innerHTML = body;
        okBtn.textContent = okText;
        cancelBtn.textContent = cancelText;
        okBtn.className = 'btn-confirm' + (confirmType === 'danger' ? ' danger' : confirmType === 'success' ? ' success' : '');
        confirmCallback = onConfirm;
        modal.classList.add('active');
        document.body.style.overflow = 'hidden';
    }

    function closeConfirmModal() {
        const modal = document.getElementById('confirmModal');
        modal.classList.remove('active');
        document.body.style.overflow = '';
        confirmCallback = null;
    }

    document.getElementById('confirmModalOkBtn').addEventListener('click', function() {
        const cb = confirmCallback;
        closeConfirmModal();
        if (typeof cb === 'function') cb();
    });

    document.getElementById('confirmModal').addEventListener('click', function(e) {
        if (e.target === this) closeConfirmModal();
    });
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') closeConfirmModal();
    });

    /* Search */
    const input = document.getElementById('mobileSearchInput');
    const resultBox = document.getElementById('searchResultContainer');
    const searchBtn = document.getElementById('searchBtn');

    input.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') { e.preventDefault(); searchUser(); }
    });

    function searchUser() {
        const mobile = input.value.trim();
        if (!mobile) {
            resultBox.innerHTML = '<div class="search-result-card not-found"><span style="color: var(--danger); font-weight: bold;">لطفاً شماره موبایل را وارد کنید.</span></div>';
            return;
        }
        searchBtn.disabled = true;
        searchBtn.innerHTML = '<span class="loading-spinner"></span> در حال جستجو...';
        resultBox.innerHTML = '';

        fetch('index.php?search_mobile=' + encodeURIComponent(mobile), {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(async res => {
            const text = await res.text();
            if (text.trim().startsWith('<')) throw new Error('پاسخ نامعتبر');
            try { return JSON.parse(text); }
            catch (e) { throw new Error('خطا در پردازش پاسخ'); }
        })
        .then(data => {
            searchBtn.disabled = false;
            searchBtn.innerHTML = '<i class="fas fa-search" style="margin-left: 5px;"></i> جستجو';
            renderSearchResult(data);
        })
        .catch(err => {
            console.error('Search error:', err);
            searchBtn.disabled = false;
            searchBtn.innerHTML = '<i class="fas fa-search" style="margin-left: 5px;"></i> جستجو';
            resultBox.innerHTML = `
                <div class="search-result-card not-found">
                    <span style="color: var(--danger); font-weight: bold;">
                        <i class="fas fa-exclamation-triangle"></i>
                        خطا در ارتباط با سرور.
                    </span>
                </div>`;
        });
    }

    function renderSearchResult(data) {
        if (data.status === 'not_found') {
            resultBox.innerHTML = `
                <div class="search-result-card not-found">
                    <div style="display: flex; align-items: center; gap: 12px;">
                        <div class="user-avatar" style="background: linear-gradient(135deg, #ef4444, #dc2626);">
                            <i class="fas fa-user-slash"></i>
                        </div>
                        <div class="user-info">
                            <div class="name" style="color: var(--danger);">کاربری یافت نشد</div>
                            <div class="meta"><span>${escapeHtml(data.message || 'هیچ کاربری یافت نشد.')}</span></div>
                        </div>
                    </div>
                </div>`;
            return;
        }

        if (data.status !== 'success') {
            resultBox.innerHTML = `<div class="search-result-card not-found"><span style="color: var(--danger);">${escapeHtml(data.message || 'خطا در جستجو')}</span></div>`;
            return;
        }

        const u = data.user;

        if (data.has_blocked_me) {
            resultBox.innerHTML = `
                <div class="search-result-card blocked-me">
                    <div style="display: flex; align-items: center; gap: 12px;">
                        <div class="user-avatar" style="background: linear-gradient(135deg, #ef4444, #dc2626);">
                            ${u.avatar_url ? `<img src="${escapeHtml(u.avatar_url)}" alt="">` : '<i class="fas fa-user-slash"></i>'}
                        </div>
                        <div class="user-info">
                            <div class="name" style="color: var(--danger);">${escapeHtml(u.full_name)}</div>
                            <div class="meta"><span><i class="fas fa-mobile-alt"></i> ${escapeHtml(u.mobile)}</span></div>
                        </div>
                    </div>
                    <div style="color: var(--danger); font-weight: bold; font-size: 0.85rem;">
                        <i class="fas fa-ban"></i> این کاربر شما را مسدود کرده است
                    </div>
                </div>`;
            return;
        }

        if (data.is_blocked_by_me) {
            resultBox.innerHTML = `
                <div class="search-result-card blocked-by-me">
                    <div style="display: flex; align-items: center; gap: 12px;">
                        <div class="user-avatar" style="background: linear-gradient(135deg, #9333ea, #7e22ce);">
                            ${u.avatar_url ? `<img src="${escapeHtml(u.avatar_url)}" alt="">` : '<i class="fas fa-user-lock"></i>'}
                        </div>
                        <div class="user-info">
                            <div class="name" style="color:#7e22ce;">${escapeHtml(u.full_name)}</div>
                            <div class="meta"><span><i class="fas fa-mobile-alt"></i> ${escapeHtml(u.mobile)}</span></div>
                        </div>
                    </div>
                    <div style="color:#9333ea; font-weight: bold; font-size: 0.85rem;">
                        <i class="fas fa-ban"></i> این کاربر توسط شما مسدود شده است
                    </div>
                </div>`;
            return;
        }

        if (data.is_blocked) {
            resultBox.innerHTML = `
                <div class="search-result-card blocked">
                    <div style="display: flex; align-items: center; gap: 12px;">
                        <div class="user-avatar" style="background: linear-gradient(135deg, #ef4444, #dc2626);">
                            ${u.avatar_url ? `<img src="${escapeHtml(u.avatar_url)}" alt="">` : '<i class="fas fa-user-slash"></i>'}
                        </div>
                        <div class="user-info">
                            <div class="name">${escapeHtml(u.full_name)}</div>
                            <div class="meta"><span><i class="fas fa-mobile-alt"></i> ${escapeHtml(u.mobile)}</span></div>
                        </div>
                    </div>
                    <div style="color: var(--danger); font-weight: bold; font-size: 0.85rem;">
                        <i class="fas fa-ban"></i> درخواست همکاری مسدود است
                    </div>
                </div>`;
            return;
        }

        if (data.already_added) {
            resultBox.innerHTML = renderUserCard(u, 'already', 'قبلاً اضافه شده', '#d97706', 'fa-check-circle');
            return;
        }

        if (data.has_pending_request) {
            resultBox.innerHTML = `
                <div class="search-result-card pending-request">
                    <div style="display: flex; align-items: center; gap: 12px;">
                        <div class="user-avatar" style="background: linear-gradient(135deg, #f59e0b, #d97706);">
                            ${u.avatar_url ? `<img src="${escapeHtml(u.avatar_url)}" alt="">` : escapeHtml(u.initial || '?')}
                        </div>
                        <div class="user-info">
                            <div class="name">${escapeHtml(u.full_name)}</div>
                            <div class="meta">
                                <span><i class="fas fa-mobile-alt"></i> ${escapeHtml(u.mobile)}</span>
                            </div>
                        </div>
                    </div>
                    <div style="color: #d97706; font-weight: bold; font-size: 0.85rem;">
                        <i class="fas fa-clock"></i> درخواست شما در انتظار تایید است
                    </div>
                </div>`;
            return;
        }

        const needsApproval = !!data.requires_approval;
        const btnText = needsApproval ? 'ارسال درخواست' : 'افزودن همکار';
        const btnIcon = needsApproval ? 'fa-paper-plane' : 'fa-user-plus';
        const cardClass = needsApproval ? 'needs-approval' : 'found';
        const notice = needsApproval
            ? `<div style="color: #7c3aed; font-weight: bold; font-size: 0.8rem; margin-top: 6px;">
                   <i class="fas fa-shield-alt"></i> این کاربر نیاز به تایید دارد
               </div>`
            : '';

        resultBox.innerHTML = `
            <div class="search-result-card ${cardClass}">
                <div style="display: flex; align-items: center; gap: 12px;">
                    <div class="user-avatar">${u.avatar_url ? `<img src="${escapeHtml(u.avatar_url)}" alt="">` : escapeHtml(u.initial || '?')}</div>
                    <div class="user-info">
                        <div class="name">${escapeHtml(u.full_name)}</div>
                        <div class="meta">
                            <span><i class="fas fa-mobile-alt"></i> ${escapeHtml(u.mobile)}</span>
                            ${u.email ? `<span><i class="fas fa-envelope"></i> ${escapeHtml(u.email)}</span>` : ''}
                        </div>
                        ${notice}
                    </div>
                </div>
                <button type="button" class="btn btn-success" onclick="addColleague(${u.id}, this)">
                    <i class="fas ${btnIcon}" style="margin-left: 5px;"></i> ${btnText}
                </button>
            </div>`;
    }

    function renderUserCard(u, cardClass, statusText, statusColor, statusIcon) {
        return `
            <div class="search-result-card ${cardClass}">
                <div style="display: flex; align-items: center; gap: 12px;">
                    <div class="user-avatar">${u.avatar_url ? `<img src="${escapeHtml(u.avatar_url)}" alt="">` : escapeHtml(u.initial || '?')}</div>
                    <div class="user-info">
                        <div class="name">${escapeHtml(u.full_name)}</div>
                        <div class="meta">
                            <span><i class="fas fa-mobile-alt"></i> ${escapeHtml(u.mobile)}</span>
                            ${u.email ? `<span><i class="fas fa-envelope"></i> ${escapeHtml(u.email)}</span>` : ''}
                        </div>
                    </div>
                </div>
                <div style="color: ${statusColor}; font-weight: bold; font-size: 0.85rem; display: flex; align-items: center; gap: 6px;">
                    <i class="fas ${statusIcon}"></i> ${statusText}
                </div>
            </div>`;
    }

    /* Add Colleague */
    function addColleague(colleagueId, btnEl) {
        if (btnEl) {
            btnEl.disabled = true;
            btnEl.innerHTML = '<span class="loading-spinner"></span> در حال ارسال...';
        }

        const formData = new FormData();
        formData.append('add_colleague', '1');
        formData.append('colleague_user_id', colleagueId);
        formData.append('csrf_token', CSRF_TOKEN);

        fetch('index.php', {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: formData
        })
        .then(async res => {
            const text = await res.text();
            if (text.trim().startsWith('<')) throw new Error('پاسخ نامعتبر');
            try { return JSON.parse(text); }
            catch (e) { throw new Error('خطا در پردازش پاسخ'); }
        })
        .then(data => {
            if (data.status === 'success') {
                showToast(data.message || 'همکار اضافه شد', 'success');
                setTimeout(() => window.location.reload(), 800);
            } else if (data.status === 'pending') {
                showToast(data.message || 'درخواست ارسال شد', 'info');
                if (btnEl) {
                    btnEl.disabled = true;
                    btnEl.innerHTML = '<i class="fas fa-clock" style="margin-left:5px;"></i> در انتظار تایید';
                    btnEl.style.background = '#f59e0b';
                }
            } else {
                showToast(data.message || 'خطا', 'error');
                if (btnEl) {
                    btnEl.disabled = false;
                    btnEl.innerHTML = '<i class="fas fa-user-plus" style="margin-left:5px;"></i> افزودن همکار';
                }
            }
        })
        .catch(err => {
            console.error('Add colleague error:', err);
            showToast('خطا در ارتباط با سرور', 'error');
            if (btnEl) {
                btnEl.disabled = false;
                btnEl.innerHTML = '<i class="fas fa-user-plus" style="margin-left:5px;"></i> افزودن همکار';
            }
        });
    }

    /* Accept / Reject Request */
    function acceptRequest(requestId, btnEl) {
        if (btnEl) {
            btnEl.disabled = true;
            btnEl.innerHTML = '<span class="loading-spinner"></span>';
        }

        const formData = new FormData();
        formData.append('accept_request', '1');
        formData.append('request_id', requestId);
        formData.append('csrf_token', CSRF_TOKEN);

        fetch('index.php', {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: formData
        })
        .then(async res => {
            const text = await res.text();
            try { return JSON.parse(text); }
            catch (e) { throw new Error('invalid'); }
        })
        .then(data => {
            if (data.status === 'success') {
                showToast(data.message || 'درخواست تایید شد', 'success');
                setTimeout(() => window.location.reload(), 700);
            } else {
                showToast(data.message || 'خطا', 'error');
                if (btnEl) {
                    btnEl.disabled = false;
                    btnEl.innerHTML = '<i class="fas fa-check"></i> تایید';
                }
            }
        })
        .catch(() => {
            showToast('خطا در ارتباط با سرور', 'error');
            if (btnEl) {
                btnEl.disabled = false;
                btnEl.innerHTML = '<i class="fas fa-check"></i> تایید';
            }
        });
    }

    function rejectRequest(requestId, btnEl) {
        showConfirm({
            title: 'رد درخواست همکاری',
            body: 'آیا از رد این درخواست همکاری مطمئن هستید؟',
            okText: 'بله، رد کن',
            cancelText: 'انصراف',
            iconType: 'warning',
            confirmType: 'danger',
            onConfirm: () => {
                if (btnEl) {
                    btnEl.disabled = true;
                    btnEl.innerHTML = '<span class="loading-spinner"></span>';
                }

                const formData = new FormData();
                formData.append('reject_request', '1');
                formData.append('request_id', requestId);
                formData.append('csrf_token', CSRF_TOKEN);

                fetch('index.php', {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    body: formData
                })
                .then(async res => {
                    const text = await res.text();
                    try { return JSON.parse(text); }
                    catch (e) { throw new Error('invalid'); }
                })
                .then(data => {
                    if (data.status === 'success') {
                        showToast(data.message || 'درخواست رد شد', 'success');
                        setTimeout(() => window.location.reload(), 700);
                    } else {
                        showToast(data.message || 'خطا', 'error');
                    }
                })
                .catch(() => showToast('خطا در ارتباط با سرور', 'error'));
            }
        });
    }

    /* Remove Colleague */
    function confirmRemoveColleague(rowId, name) {
        showConfirm({
            title: 'حذف همکار',
            body: `آیا از حذف <strong>${escapeHtml(name)}</strong> از لیست همکاران مطمئن هستید؟<br><span style="color:#94a3b8; font-size:0.82rem;">دسترسی‌های این همکار نیز حذف خواهند شد.</span>`,
            okText: 'حذف کن',
            cancelText: 'انصراف',
            iconType: 'danger',
            confirmType: 'danger',
            onConfirm: () => {
                const cardEl = document.querySelector(`.colleague-card[data-row-id="${rowId}"]`);
                if (cardEl) {
                    cardEl.style.opacity = '0.5';
                    cardEl.style.pointerEvents = 'none';
                }

                const formData = new FormData();
                formData.append('remove_colleague', '1');
                formData.append('colleague_row_id', rowId);
                formData.append('csrf_token', CSRF_TOKEN);

                fetch('index.php', {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    body: formData
                })
                .then(async res => {
                    const text = await res.text();
                    try { return JSON.parse(text); }
                    catch (e) { throw new Error('invalid'); }
                })
                .then(data => {
                    if (data.status === 'success') {
                        showToast(data.message || 'همکار حذف شد', 'success');
                        if (cardEl) {
                            cardEl.style.transition = 'all 0.3s ease';
                            cardEl.style.transform = 'translateX(-20px)';
                            cardEl.style.opacity = '0';
                            setTimeout(() => cardEl.remove(), 300);
                        }
                    } else {
                        showToast(data.message || 'خطا', 'error');
                        if (cardEl) {
                            cardEl.style.opacity = '';
                            cardEl.style.pointerEvents = '';
                        }
                    }
                })
                .catch(() => {
                    showToast('خطا در ارتباط با سرور', 'error');
                    if (cardEl) {
                        cardEl.style.opacity = '';
                        cardEl.style.pointerEvents = '';
                    }
                });
            }
        });
    }

    /* Block / Unblock */
    function confirmBlockUser(userId, name) {
        showConfirm({
            title: 'مسدود کردن کاربر',
            body: `آیا از مسدود کردن <strong>${escapeHtml(name)}</strong> مطمئن هستید؟<br><span style="color:#94a3b8; font-size:0.82rem;">این کاربر دیگر نمی‌تواند شما را به عنوان همکار اضافه کند.</span>`,
            okText: 'مسدود کن',
            cancelText: 'انصراف',
            iconType: 'danger',
            confirmType: 'danger',
            onConfirm: () => blockUser(userId)
        });
    }

    function blockUser(userId) {
        const formData = new FormData();
        formData.append('block_user', '1');
        formData.append('block_user_id', userId);
        formData.append('csrf_token', CSRF_TOKEN);

        fetch('index.php', {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: formData
        })
        .then(async res => {
            const text = await res.text();
            try { return JSON.parse(text); }
            catch (e) { throw new Error('invalid'); }
        })
        .then(data => {
            if (data.status === 'success') {
                showToast(data.message || 'کاربر مسدود شد', 'success');
                setTimeout(() => window.location.reload(), 600);
            } else {
                showToast(data.message || 'خطا', 'error');
            }
        })
        .catch(() => showToast('خطا در ارتباط با سرور', 'error'));
    }

    function unblockUser(userId) {
        const formData = new FormData();
        formData.append('unblock_user', '1');
        formData.append('unblock_user_id', userId);
        formData.append('csrf_token', CSRF_TOKEN);

        fetch('index.php', {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: formData
        })
        .then(async res => {
            const text = await res.text();
            try { return JSON.parse(text); }
            catch (e) { throw new Error('invalid'); }
        })
        .then(data => {
            if (data.status === 'success') {
                showToast(data.message || 'رفع مسدودی شد', 'success');
                setTimeout(() => window.location.reload(), 600);
            } else {
                showToast(data.message || 'خطا', 'error');
            }
        })
        .catch(() => showToast('خطا در ارتباط با سرور', 'error'));
    }

    /* Permissions */
    function togglePermissionsPanel(rowId) {
        const panel = document.getElementById('perm-panel-' + rowId);
        const btn = document.getElementById('perm-toggle-btn-' + rowId);
        if (!panel || !btn) return;
        const isOpen = panel.classList.contains('open');
        document.querySelectorAll('.permissions-panel.open').forEach(p => p.classList.remove('open'));
        document.querySelectorAll('.icon-btn.active').forEach(b => b.classList.remove('active'));
        if (!isOpen) {
            panel.classList.add('open');
            btn.classList.add('active');
        }
    }

    function closePermissionsPanel(rowId) {
        const panel = document.getElementById('perm-panel-' + rowId);
        const btn = document.getElementById('perm-toggle-btn-' + rowId);
        if (panel) panel.classList.remove('open');
        if (btn) btn.classList.remove('active');
    }

    function togglePermOption(checkbox) {
        const label = checkbox.closest('.perm-option');
        if (checkbox.checked) label.classList.add('checked');
        else label.classList.remove('checked');
    }

    function savePermissions(rowId, btnEl) {
        const grid = document.querySelector(`.permissions-grid[data-row-id="${rowId}"]`);
        if (!grid) return;

        const checked = grid.querySelectorAll('input[type="checkbox"]:checked');
        const perms = Array.from(checked).map(cb => cb.value);

        if (btnEl) {
            btnEl.disabled = true;
            btnEl.innerHTML = '<span class="loading-spinner"></span> در حال ذخیره...';
        }

        const formData = new FormData();
        formData.append('save_permissions', '1');
        formData.append('colleague_row_id', rowId);
        formData.append('csrf_token', CSRF_TOKEN);
        perms.forEach(p => formData.append('permissions[]', p));

        fetch('index.php', {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: formData
        })
        .then(async res => {
            const text = await res.text();
            try { return JSON.parse(text); }
            catch (e) { throw new Error('invalid'); }
        })
        .then(data => {
            if (btnEl) {
                btnEl.disabled = false;
                btnEl.innerHTML = '<i class="fas fa-save" style="margin-left: 4px;"></i> ذخیره دسترسی‌ها';
            }
            if (data.status === 'success') {
                showToast(data.message || 'دسترسی‌ها ذخیره شد', 'success');
                closePermissionsPanel(rowId);
            } else {
                showToast(data.message || 'خطا', 'error');
            }
        })
        .catch(() => {
            if (btnEl) {
                btnEl.disabled = false;
                btnEl.innerHTML = '<i class="fas fa-save" style="margin-left: 4px;"></i> ذخیره دسترسی‌ها';
            }
            showToast('خطا در ارتباط با سرور', 'error');
        });
    }

    /* Utilities */
    function escapeHtml(text) {
        if (text === null || text === undefined) return '';
        const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
        return String(text).replace(/[&<>"']/g, m => map[m]);
    }

    /* Sidebar */
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
</script>
</body>
</html>