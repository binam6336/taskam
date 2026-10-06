<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../database/Database.php';

date_default_timezone_set('Asia/Tehran');

if (!Auth::check()) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => 'error', 'message' => 'unauthorized'], JSON_UNESCAPED_UNICODE);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

$db = Database::getInstance();
try {
    $db->exec("SET NAMES 'utf8mb4'");
    $db->exec("SET CHARACTER SET utf8mb4");
    $db->exec("SET character_set_connection = utf8mb4");
    $db->exec("SET time_zone = '+03:30'");
} catch (PDOException $e) {}

$userId = (int)$_SESSION['user_id'];
$action = $_GET['action'] ?? $_POST['action'] ?? '';

define('ONLINE_THRESHOLD', 25);
define('EDIT_DELETE_WINDOW_SECONDS', 3600);
define('DEFAULT_PAGE_SIZE', 30);
define('MAX_PAGE_SIZE', 100);

$MAX_FILE_SIZE = 50 * 1024 * 1024;
$ALLOWED_FILE_TYPES = [
    'image/png' => 'png', 'image/jpeg' => 'jpg',
    'application/pdf' => 'pdf', 'application/zip' => 'zip',
    'application/x-zip-compressed' => 'zip', 'application/x-zip' => 'zip',
    'multipart/x-zip' => 'zip', 'text/plain' => 'txt',
];
$CHAT_UPLOAD_DIR = __DIR__ . '/../uploads/chat_files/';

// ================== اطمینان از جدول‌ها ==================
try {
    $db->exec("CREATE TABLE IF NOT EXISTS colleague_messages (
        id INT AUTO_INCREMENT PRIMARY KEY,
        sender_id INT NOT NULL,
        receiver_id INT NOT NULL,
        message TEXT NOT NULL,
        attachment VARCHAR(255) DEFAULT NULL,
        attachment_name VARCHAR(255) DEFAULT NULL,
        attachment_size INT UNSIGNED DEFAULT NULL,
        attachment_type VARCHAR(50) DEFAULT NULL,
        is_read TINYINT(1) NOT NULL DEFAULT 0,
        read_at TIMESTAMP NULL DEFAULT NULL,
        is_edited TINYINT(1) NOT NULL DEFAULT 0,
        edited_at TIMESTAMP NULL DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY sender_id (sender_id),
        KEY receiver_id (receiver_id),
        KEY conversation (sender_id, receiver_id),
        KEY created_at (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;");
} catch (PDOException $e) {}

foreach ([
    "ALTER TABLE colleague_messages ADD COLUMN read_at TIMESTAMP NULL DEFAULT NULL AFTER is_read",
    "ALTER TABLE colleague_messages ADD COLUMN attachment VARCHAR(255) DEFAULT NULL AFTER message",
    "ALTER TABLE colleague_messages ADD COLUMN attachment_name VARCHAR(255) DEFAULT NULL AFTER attachment",
    "ALTER TABLE colleague_messages ADD COLUMN attachment_size INT UNSIGNED DEFAULT NULL AFTER attachment_name",
    "ALTER TABLE colleague_messages ADD COLUMN attachment_type VARCHAR(50) DEFAULT NULL AFTER attachment_size",
    "ALTER TABLE colleague_messages ADD COLUMN is_edited TINYINT(1) NOT NULL DEFAULT 0 AFTER read_at",
    "ALTER TABLE colleague_messages ADD COLUMN edited_at TIMESTAMP NULL DEFAULT NULL AFTER is_edited",
] as $alterSql) {
    try { $db->exec($alterSql); } catch (PDOException $e) {}
}

try { $db->exec("ALTER TABLE colleague_messages ADD INDEX idx_saved_messages (sender_id, receiver_id, id)"); } catch (PDOException $e) {}

try {
    $colInfo = $db->query("SHOW COLUMNS FROM users LIKE 'last_seen'")->fetch(PDO::FETCH_ASSOC);
    if ($colInfo) {
        if (stripos($colInfo['Type'], 'int') === false) {
            try { $db->exec("ALTER TABLE users DROP COLUMN last_seen"); } catch (PDOException $e) {}
            $db->exec("ALTER TABLE users ADD COLUMN last_seen INT UNSIGNED NULL DEFAULT NULL AFTER status");
        }
    } else {
        $db->exec("ALTER TABLE users ADD COLUMN last_seen INT UNSIGNED NULL DEFAULT NULL AFTER status");
    }
} catch (PDOException $e) {}

if (!is_dir($CHAT_UPLOAD_DIR)) { @mkdir($CHAT_UPLOAD_DIR, 0755, true); }

// ================== توابع کمکی ==================
function canChatWith($db, $userId, $otherId) {
    if ($userId === $otherId) return true;

    // چک بلاک
    $chkBlock = $db->prepare("SELECT 1 FROM colleague_blocks WHERE (blocker_user_id=? AND blocked_user_id=?) OR (blocker_user_id=? AND blocked_user_id=?) LIMIT 1");
    $chkBlock->execute([$userId, $otherId, $otherId, $userId]);
    if ($chkBlock->fetchColumn()) return false;

    // چک همکاری
    $chk = $db->prepare("SELECT 1 FROM colleagues WHERE (user_id=? AND colleague_user_id=?) OR (user_id=? AND colleague_user_id=?) LIMIT 1");
    $chk->execute([$userId, $otherId, $otherId, $userId]);
    if ($chk->fetchColumn()) return true;

    // ⭐ چک مکالمه موجود (برای پیام‌های سیستمی مانند تسکام)
    $chk2 = $db->prepare("SELECT 1 FROM colleague_messages WHERE (sender_id=? AND receiver_id=?) OR (sender_id=? AND receiver_id=?) LIMIT 1");
    $chk2->execute([$userId, $otherId, $otherId, $userId]);
    return (bool)$chk2->fetchColumn();
}

function normalizeMessages(&$messages) {
    foreach ($messages as &$m) {
        $m['id'] = (int)$m['id'];
        $m['sender_id'] = (int)$m['sender_id'];
        $m['receiver_id'] = (int)$m['receiver_id'];
        $m['is_read'] = (int)$m['is_read'];
        $m['is_edited'] = isset($m['is_edited']) ? (int)$m['is_edited'] : 0;
        $m['attachment_size'] = !empty($m['attachment_size']) ? (int)$m['attachment_size'] : null;
    }
    unset($m);
}

function updateLastSeen($db, $userId) {
    try { $db->prepare("UPDATE users SET last_seen=? WHERE id=?")->execute([time(), $userId]); return true; }
    catch (PDOException $e) { return false; }
}

function isUserOnline($lastSeenInt) {
    if (empty($lastSeenInt)) return false;
    return (time() - (int)$lastSeenInt) <= ONLINE_THRESHOLD;
}

function gregorianToPersian($gy, $gm, $gd) {
    $g_d_m = [0,31,59,90,120,151,181,212,243,273,304,334];
    $gy2 = ($gm > 2) ? ($gy + 1) : $gy;
    $days = 355666 + (365*$gy) + ((int)(($gy2+3)/4)) - ((int)(($gy2+99)/100)) + ((int)(($gy2+399)/400)) + $gd + $g_d_m[$gm-1];
    $jy = -1595 + (33*((int)($days/12053))); $days %= 12053;
    $jy += 4*((int)($days/1461)); $days %= 1461;
    if ($days > 365) { $jy += (int)(($days-1)/365); $days = ($days-1)%365; }
    if ($days < 186) { $jm = 1 + (int)($days/31); $jd = 1 + ($days%31); }
    else { $jm = 7 + (int)(($days-186)/30); $jd = 1 + (($days-186)%30); }
    return ['year'=>$jy,'month'=>$jm,'day'=>$jd];
}

function formatLastSeenTehran($lastSeenInt) {
    if (empty($lastSeenInt)) return ['text'=>'آخرین بازدید: نامشخص','relative'=>null,'absolute'=>null,'is_just_now'=>false];
    $lastSeenInt = (int)$lastSeenInt;
    $diffSec = time() - $lastSeenInt;
    if ($diffSec < -300) return ['text'=>'آخرین بازدید: نامشخص','relative'=>null,'absolute'=>null,'is_just_now'=>false];
    if ($diffSec < 0) $diffSec = 0;
    if ($diffSec < 60) $relativeText = 'لحظاتی پیش';
    elseif ($diffSec < 3600) $relativeText = (int)floor($diffSec/60).' دقیقه پیش';
    elseif ($diffSec < 86400) $relativeText = (int)floor($diffSec/3600).' ساعت پیش';
    else $relativeText = (int)floor($diffSec/86400).' روز پیش';
    $date = new DateTime('@'.$lastSeenInt);
    $date->setTimezone(new DateTimeZone('Asia/Tehran'));
    $timeStr = $date->format('H:i');
    $today = new DateTime('now', new DateTimeZone('Asia/Tehran'));
    $today->setTime(0,0,0);
    $yesterday = clone $today; $yesterday->modify('-1 day');
    $lastSeenDay = clone $date; $lastSeenDay->setTime(0,0,0);
    if ($lastSeenDay == $today) $absoluteText = 'امروز ساعت '.$timeStr;
    elseif ($lastSeenDay == $yesterday) $absoluteText = 'دیروز ساعت '.$timeStr;
    else {
        $persian = gregorianToPersian((int)$date->format('Y'),(int)$date->format('n'),(int)$date->format('j'));
        $months = [1=>'فروردین',2=>'اردیبهشت',3=>'خرداد',4=>'تیر',5=>'مرداد',6=>'شهریور',7=>'مهر',8=>'آبان',9=>'آذر',10=>'دی',11=>'بهمن',12=>'اسفند'];
        $absoluteText = $persian['day'].' '.$months[$persian['month']].' ساعت '.$timeStr;
    }
    return ['text'=>'آخرین بازدید: '.$relativeText,'relative'=>$relativeText,'absolute'=>$absoluteText,'is_just_now'=>$diffSec<60];
}

function checkEditDeleteWindow($createdAtStr) {
    $createdTs = strtotime($createdAtStr);
    if ($createdTs === false) return ['allowed'=>false,'reason'=>'تاریخ پیام نامعتبر است','remaining'=>0];
    $elapsed = time() - $createdTs;
    $remaining = EDIT_DELETE_WINDOW_SECONDS - $elapsed;
    if ($remaining <= 0) return ['allowed'=>false,'reason'=>'مهلت ۱ ساعته ویرایش/حذف این پیام به پایان رسیده است','remaining'=>0];
    return ['allowed'=>true,'reason'=>null,'remaining'=>$remaining];
}

// ================== زمان تهران ==================
if ($action === 'tehran_time') {
    $now = new DateTime('now', new DateTimeZone('Asia/Tehran'));
    $months = [1=>'فروردین',2=>'اردیبهشت',3=>'خرداد',4=>'تیر',5=>'مرداد',6=>'شهریور',7=>'مهر',8=>'آبان',9=>'آذر',10=>'دی',11=>'بهمن',12=>'اسفند'];
    $persian = gregorianToPersian((int)$now->format('Y'),(int)$now->format('n'),(int)$now->format('j'));
    $weekdays = [0=>'یکشنبه',1=>'دوشنبه',2=>'سه‌شنبه',3=>'چهارشنبه',4=>'پنجشنبه',5=>'جمعه',6=>'شنبه'];
    echo json_encode([
        'status'=>'success','timezone'=>'Asia/Tehran','iso'=>$now->format('c'),
        'gregorian'=>['date'=>$now->format('Y-m-d'),'time'=>$now->format('H:i:s')],
        'persian'=>[
            'year'=>$persian['year'],'month'=>$persian['month'],'day'=>$persian['day'],
            'month_name'=>$months[$persian['month']],
            'formatted'=>$persian['year'].'/'.str_pad($persian['month'],2,'0',STR_PAD_LEFT).'/'.str_pad($persian['day'],2,'0',STR_PAD_LEFT),
            'formatted_long'=>$persian['day'].' '.$months[$persian['month']].' '.$persian['year'],
            'weekday'=>$weekdays[(int)$now->format('w')],
        ],
        'unix_timestamp'=>time(),'unix_ms'=>(int)(microtime(true)*1000),
    ], JSON_UNESCAPED_UNICODE); exit;
}

// ================== ارسال پیام متنی ==================
if ($action === 'send') {
    $receiverId = (int)($_POST['receiver_id'] ?? 0);
    $message = trim($_POST['message'] ?? '');
    if ($receiverId <= 0 || $message === '') { echo json_encode(['status'=>'error','message'=>'اطلاعات ناقص'], JSON_UNESCAPED_UNICODE); exit; }
    if (mb_strlen($message) > 2000) $message = mb_substr($message, 0, 2000);

    // ⭐ چک ربات تسکام: اگر گیرنده ربات است، پیام قابل ارسال نیست
    $chkBot = $db->prepare("SELECT mobile FROM users WHERE id = ? LIMIT 1");
    $chkBot->execute([$receiverId]);
    $recvMobile = $chkBot->fetchColumn();
    if ($recvMobile === '00000000000') {
        echo json_encode(['status'=>'error','message'=>'این حساب ربات اطلاع‌رسانی است و امکان ارسال پیام به آن وجود ندارد'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (!canChatWith($db, $userId, $receiverId)) { echo json_encode(['status'=>'error','message'=>'امکان ارسال پیام وجود ندارد'], JSON_UNESCAPED_UNICODE); exit; }
    updateLastSeen($db, $userId);
    $isSaved = ($userId === $receiverId);
    $isRead = $isSaved ? 1 : 0;
    $readAt = $isSaved ? date('Y-m-d H:i:s') : null;
    $stmt = $db->prepare("INSERT INTO colleague_messages (sender_id, receiver_id, message, is_read, read_at) VALUES (?,?,?,?,?)");
    $stmt->execute([$userId, $receiverId, $message, $isRead, $readAt]);
    $newId = (int)$db->lastInsertId();
    echo json_encode(['status'=>'success','message'=>[
        'id'=>$newId,'sender_id'=>$userId,'receiver_id'=>$receiverId,'message'=>$message,
        'attachment'=>null,'attachment_name'=>null,'attachment_size'=>null,'attachment_type'=>null,
        'is_read'=>$isRead,'is_edited'=>0,'created_at'=>date('Y-m-d H:i:s'),
    ]], JSON_UNESCAPED_UNICODE); exit;
}

// ================== ارسال فایل ==================
if ($action === 'send_file') {
    $receiverId = (int)($_POST['receiver_id'] ?? 0);
    $messageText = trim($_POST['message'] ?? '');
    if ($receiverId <= 0) { echo json_encode(['status'=>'error','message'=>'شناسه گیرنده نامعتبر'], JSON_UNESCAPED_UNICODE); exit; }

    // ⭐ چک ربات تسکام
    $chkBot = $db->prepare("SELECT mobile FROM users WHERE id = ? LIMIT 1");
    $chkBot->execute([$receiverId]);
    $recvMobile = $chkBot->fetchColumn();
    if ($recvMobile === '00000000000') {
        echo json_encode(['status'=>'error','message'=>'این حساب ربات اطلاع‌رسانی است و امکان ارسال پیام به آن وجود ندارد'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (!canChatWith($db, $userId, $receiverId)) { echo json_encode(['status'=>'error','message'=>'امکان ارسال پیام وجود ندارد'], JSON_UNESCAPED_UNICODE); exit; }
    if (!isset($_FILES['attachment']) || $_FILES['attachment']['error'] !== UPLOAD_ERR_OK) {
        $uploadErrors = [UPLOAD_ERR_INI_SIZE=>'حجم فایل از محدودیت سرور بیشتر است.',UPLOAD_ERR_FORM_SIZE=>'حجم فایل بیش از حد مجاز است.',UPLOAD_ERR_PARTIAL=>'آپلود به طور کامل انجام نشد.',UPLOAD_ERR_NO_FILE=>'فایلی انتخاب نشده است.',UPLOAD_ERR_NO_TMP_DIR=>'پوشه موقت وجود ندارد.',UPLOAD_ERR_CANT_WRITE=>'نوشتن فایل ممکن نیست.',UPLOAD_ERR_EXTENSION=>'آپلود توسط افزونه متوقف شد.'];
        $errCode = $_FILES['attachment']['error'] ?? UPLOAD_ERR_NO_FILE;
        echo json_encode(['status'=>'error','message'=>$uploadErrors[$errCode] ?? 'خطای نامشخص در آپلود'], JSON_UNESCAPED_UNICODE); exit;
    }
    $file = $_FILES['attachment'];
    if ($file['size'] > $MAX_FILE_SIZE) { echo json_encode(['status'=>'error','message'=>'حجم فایل نباید بیشتر از 50 مگابایت باشد.'], JSON_UNESCAPED_UNICODE); exit; }
    if ($file['size'] <= 0) { echo json_encode(['status'=>'error','message'=>'فایل خالی است.'], JSON_UNESCAPED_UNICODE); exit; }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->file($file['tmp_name']);
    if (!isset($ALLOWED_FILE_TYPES[$mimeType])) { echo json_encode(['status'=>'error','message'=>'فرمت فایل مجاز نیست.'], JSON_UNESCAPED_UNICODE); exit; }
    $ext = $ALLOWED_FILE_TYPES[$mimeType];
    if (!is_dir($CHAT_UPLOAD_DIR)) @mkdir($CHAT_UPLOAD_DIR, 0755, true);
    $uniqueName = 'chat_'.$userId.'_'.time().'_'.bin2hex(random_bytes(4)).'.'.$ext;
    $destPath = $CHAT_UPLOAD_DIR . $uniqueName;
    if (!move_uploaded_file($file['tmp_name'], $destPath)) { echo json_encode(['status'=>'error','message'=>'خطا در ذخیره فایل'], JSON_UNESCAPED_UNICODE); exit; }
    $originalName = mb_substr(basename($file['name']), 0, 200);
    updateLastSeen($db, $userId);
    $isSaved = ($userId === $receiverId);
    $isRead = $isSaved ? 1 : 0;
    $readAt = $isSaved ? date('Y-m-d H:i:s') : null;
    $stmt = $db->prepare("INSERT INTO colleague_messages (sender_id, receiver_id, message, attachment, attachment_name, attachment_size, attachment_type, is_read, read_at) VALUES (?,?,?,?,?,?,?,?,?)");
    $stmt->execute([$userId, $receiverId, $messageText, $uniqueName, $originalName, (int)$file['size'], $ext, $isRead, $readAt]);
    $newId = (int)$db->lastInsertId();
    echo json_encode(['status'=>'success','message'=>[
        'id'=>$newId,'sender_id'=>$userId,'receiver_id'=>$receiverId,'message'=>$messageText,
        'attachment'=>$uniqueName,'attachment_name'=>$originalName,'attachment_size'=>(int)$file['size'],
        'attachment_type'=>$ext,'is_read'=>$isRead,'is_edited'=>0,'created_at'=>date('Y-m-d H:i:s'),
    ]], JSON_UNESCAPED_UNICODE); exit;
}

// ================== ⭐ دریافت پیام‌ها با لود تنبل ⭐ ==================
if ($action === 'fetch') {
    $otherId  = (int)($_GET['user_id'] ?? 0);
    $mode     = $_GET['mode'] ?? 'initial'; // initial | older | newer | around
    $beforeId = (int)($_GET['before_id'] ?? 0);
    $afterId  = (int)($_GET['after_id']  ?? 0);
    $targetId = (int)($_GET['target_id'] ?? 0);
    $limit    = (int)($_GET['limit'] ?? DEFAULT_PAGE_SIZE);
    if ($limit < 5) $limit = 5;
    if ($limit > MAX_PAGE_SIZE) $limit = MAX_PAGE_SIZE;

    if ($otherId <= 0) { echo json_encode(['status'=>'error','message'=>'شناسه نامعتبر'], JSON_UNESCAPED_UNICODE); exit; }
    if (!canChatWith($db, $userId, $otherId)) { echo json_encode(['status'=>'error','message'=>'دسترسی ندارید'], JSON_UNESCAPED_UNICODE); exit; }

    updateLastSeen($db, $userId);

    // علامت‌گذاری به‌عنوان خوانده‌شده
    $upd = $db->prepare("UPDATE colleague_messages SET is_read=1, read_at=NOW() WHERE sender_id=? AND receiver_id=? AND is_read=0");
    $upd->execute([$otherId, $userId]);

    $messages = [];
    $hasMoreBefore = false;
    $hasMoreAfter  = false;

    $baseSelect = "SELECT id, sender_id, receiver_id, message, attachment, attachment_name, attachment_size, attachment_type, is_read, is_edited, edited_at, created_at FROM colleague_messages";

    if ($mode === 'older' && $beforeId > 0) {
        $stmt = $db->prepare("$baseSelect
            WHERE ((sender_id=? AND receiver_id=?) OR (sender_id=? AND receiver_id=?))
              AND id < ?
            ORDER BY id DESC LIMIT ?");
        $stmt->execute([$userId, $otherId, $otherId, $userId, $beforeId, $limit + 1]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) > $limit) { $hasMoreBefore = true; array_pop($rows); }
        $messages = array_reverse($rows);
        $hasMoreAfter = true;

    } elseif ($mode === 'newer' && $afterId > 0) {
        $stmt = $db->prepare("$baseSelect
            WHERE ((sender_id=? AND receiver_id=?) OR (sender_id=? AND receiver_id=?))
              AND id > ?
            ORDER BY id ASC LIMIT ?");
        $stmt->execute([$userId, $otherId, $otherId, $userId, $afterId, $limit]);
        $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $hasMoreBefore = true;
        if (count($messages) === $limit) {
            $lastId = (int)end($messages)['id'];
            $chk = $db->prepare("SELECT 1 FROM colleague_messages WHERE ((sender_id=? AND receiver_id=?) OR (sender_id=? AND receiver_id=?)) AND id > ? LIMIT 1");
            $chk->execute([$userId, $otherId, $otherId, $userId, $lastId]);
            $hasMoreAfter = (bool)$chk->fetchColumn();
        }

    } elseif ($mode === 'around' && $targetId > 0) {
        $half = (int)floor($limit / 2);
        $before = $db->prepare("$baseSelect
            WHERE ((sender_id=? AND receiver_id=?) OR (sender_id=? AND receiver_id=?))
              AND id < ?
            ORDER BY id DESC LIMIT ?");
        $before->execute([$userId, $otherId, $otherId, $userId, $targetId, $half]);
        $beforeRows = array_reverse($before->fetchAll(PDO::FETCH_ASSOC));

        $targetStmt = $db->prepare("$baseSelect WHERE id=? AND ((sender_id=? AND receiver_id=?) OR (sender_id=? AND receiver_id=?)) LIMIT 1");
        $targetStmt->execute([$targetId, $userId, $otherId, $otherId, $userId]);
        $targetRow = $targetStmt->fetch(PDO::FETCH_ASSOC);

        $afterStmt = $db->prepare("$baseSelect
            WHERE ((sender_id=? AND receiver_id=?) OR (sender_id=? AND receiver_id=?))
              AND id > ?
            ORDER BY id ASC LIMIT ?");
        $afterStmt->execute([$userId, $otherId, $otherId, $userId, $targetId, $half]);
        $afterRows = $afterStmt->fetchAll(PDO::FETCH_ASSOC);

        $messages = $beforeRows;
        if ($targetRow) $messages[] = $targetRow;
        $messages = array_merge($messages, $afterRows);

        $hasMoreBefore = count($beforeRows) >= $half;
        $hasMoreAfter  = count($afterRows) >= $half;

    } else {
        // initial: آخرین N پیام
        $stmt = $db->prepare("$baseSelect
            WHERE ((sender_id=? AND receiver_id=?) OR (sender_id=? AND receiver_id=?))
            ORDER BY id DESC LIMIT ?");
        $stmt->execute([$userId, $otherId, $otherId, $userId, $limit + 1]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) > $limit) { $hasMoreBefore = true; array_pop($rows); }
        $messages = array_reverse($rows);
        $hasMoreAfter = false;
    }

    normalizeMessages($messages);

    // به‌روزرسانی وضعیت خوانده‌شده برای پیام‌های من
    $myMsgIds = [];
    foreach ($messages as $m) if ((int)$m['sender_id'] === $userId) $myMsgIds[] = (int)$m['id'];
    if (!empty($myMsgIds)) {
        $placeholders = implode(',', array_fill(0, count($myMsgIds), '?'));
        $reRead = $db->prepare("SELECT id, is_read FROM colleague_messages WHERE id IN ($placeholders)");
        $reRead->execute($myMsgIds);
        $readMap = [];
        foreach ($reRead->fetchAll(PDO::FETCH_ASSOC) as $row) $readMap[(int)$row['id']] = (int)$row['is_read'];
        foreach ($messages as &$m) {
            if ((int)$m['sender_id'] === $userId && isset($readMap[(int)$m['id']])) $m['is_read'] = $readMap[(int)$m['id']];
        }
        unset($m);
    }

    echo json_encode([
        'status' => 'success',
        'messages' => $messages,
        'has_more_before' => $hasMoreBefore,
        'has_more_after'  => $hasMoreAfter,
        'mode' => $mode,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ================== ⭐ جستجو در پیام‌ها ⭐ ==================
if ($action === 'search') {
    $q        = trim($_GET['q'] ?? '');
    $otherId  = (int)($_GET['user_id'] ?? 0);
    $scope    = $_GET['scope'] ?? 'all'; // all | conversation
    $limit    = (int)($_GET['limit'] ?? 60);
    if ($limit < 10) $limit = 10;
    if ($limit > 200) $limit = 200;

    if (mb_strlen($q) < 2) {
        echo json_encode(['status'=>'error','message'=>'حداقل ۲ کاراکتر وارد کنید'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (mb_strlen($q) > 100) $q = mb_substr($q, 0, 100);

    $like = '%' . str_replace(['%','_'], ['\\%','\\_'], $q) . '%';

    $sql = "SELECT cm.id, cm.sender_id, cm.receiver_id, cm.message, cm.attachment, cm.attachment_name,
                   cm.attachment_type, cm.attachment_size, cm.is_read, cm.is_edited, cm.created_at,
                   u.first_name, u.last_name, u.mobile
            FROM colleague_messages cm
            LEFT JOIN users u ON u.id = CASE WHEN cm.sender_id = ? THEN cm.receiver_id ELSE cm.sender_id END
            WHERE ";

    if ($scope === 'conversation' && $otherId > 0) {
        $sql .= "((cm.sender_id=? AND cm.receiver_id=?) OR (cm.sender_id=? AND cm.receiver_id=?))
                 AND (cm.message LIKE ? OR cm.attachment_name LIKE ?)
                 ORDER BY cm.id DESC LIMIT ?";
        $params = [$userId, $userId, $otherId, $otherId, $userId, $like, $like, $limit];
    } else {
        $sql .= "(cm.sender_id=? OR cm.receiver_id=?)
                 AND (cm.message LIKE ? OR cm.attachment_name LIKE ?)
                 ORDER BY cm.id DESC LIMIT ?";
        $params = [$userId, $userId, $userId, $like, $like, $limit];
    }

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($results as &$r) {
        $r['id'] = (int)$r['id'];
        $r['sender_id'] = (int)$r['sender_id'];
        $r['receiver_id'] = (int)$r['receiver_id'];
        $r['is_read'] = (int)$r['is_read'];
        $r['is_edited'] = (int)$r['is_edited'];
        $r['attachment_size'] = !empty($r['attachment_size']) ? (int)$r['attachment_size'] : null;
        $other = ((int)$r['sender_id'] === $userId) ? (int)$r['receiver_id'] : (int)$r['sender_id'];
        $r['other_user_id'] = $other;
        $r['is_mine'] = ((int)$r['sender_id'] === $userId);
        $r['from_saved'] = ((int)$r['sender_id'] === $userId && (int)$r['receiver_id'] === $userId);
        $fullName = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));
        if ($fullName === '') $fullName = $r['mobile'] ?? '';
        $r['from_name'] = $r['from_saved'] ? 'پیام های ذخیره شده' : $fullName;
    }
    unset($r);

    echo json_encode([
        'status'=>'success','query'=>$q,'scope'=>$scope,'count'=>count($results),'results'=>$results,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ================== شمارش پیام‌های خوانده‌نشده ==================
if ($action === 'unread_counts') {
    $stmt = $db->prepare("SELECT sender_id, COUNT(*) AS cnt FROM colleague_messages WHERE receiver_id=? AND is_read=0 AND sender_id != receiver_id GROUP BY sender_id");
    $stmt->execute([$userId]);
    $counts = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) $counts[(int)$r['sender_id']] = (int)$r['cnt'];
    echo json_encode(['status'=>'success','counts'=>$counts], JSON_UNESCAPED_UNICODE); exit;
}

if ($action === 'total_unread') {
    $stmt = $db->prepare("SELECT COUNT(*) FROM colleague_messages WHERE receiver_id=? AND is_read=0 AND sender_id != receiver_id");
    $stmt->execute([$userId]);
    echo json_encode(['status'=>'success','total'=>(int)$stmt->fetchColumn()], JSON_UNESCAPED_UNICODE); exit;
}

if ($action === 'mark_read') {
    $otherId = (int)($_POST['user_id'] ?? 0);
    if ($otherId <= 0) { echo json_encode(['status'=>'error','message'=>'شناسه نامعتبر'], JSON_UNESCAPED_UNICODE); exit; }
    $stmt = $db->prepare("UPDATE colleague_messages SET is_read=1, read_at=NOW() WHERE sender_id=? AND receiver_id=? AND is_read=0");
    $stmt->execute([$otherId, $userId]);
    echo json_encode(['status'=>'success','affected'=>$stmt->rowCount()], JSON_UNESCAPED_UNICODE); exit;
}

// ================== ویرایش پیام ==================
if ($action === 'edit') {
    $msgId = (int)($_POST['message_id'] ?? 0);
    $newText = trim($_POST['message'] ?? '');
    if ($msgId <= 0) { echo json_encode(['status'=>'error','message'=>'شناسه پیام نامعتبر'], JSON_UNESCAPED_UNICODE); exit; }
    if ($newText === '') { echo json_encode(['status'=>'error','message'=>'متن پیام نمی‌تواند خالی باشد'], JSON_UNESCAPED_UNICODE); exit; }
    if (mb_strlen($newText) > 2000) $newText = mb_substr($newText, 0, 2000);
    $chk = $db->prepare("SELECT id, sender_id, receiver_id, message, attachment, is_edited, created_at FROM colleague_messages WHERE id=? AND sender_id=? LIMIT 1");
    $chk->execute([$msgId, $userId]);
    $msgRow = $chk->fetch(PDO::FETCH_ASSOC);
    if (!$msgRow) { echo json_encode(['status'=>'error','message'=>'پیام یافت نشد'], JSON_UNESCAPED_UNICODE); exit; }
    if (!empty($msgRow['attachment'])) { echo json_encode(['status'=>'error','message'=>'پیام‌های دارای فایل قابل ویرایش نیستند'], JSON_UNESCAPED_UNICODE); exit; }
    $window = checkEditDeleteWindow($msgRow['created_at']);
    if (!$window['allowed']) { echo json_encode(['status'=>'error','message'=>$window['reason'],'remaining'=>$window['remaining']], JSON_UNESCAPED_UNICODE); exit; }
    if (trim($msgRow['message']) === $newText) { echo json_encode(['status'=>'success','message'=>['id'=>(int)$msgRow['id'],'message'=>$newText,'is_edited'=>(int)$msgRow['is_edited'],'edited_at'=>null,'unchanged'=>true]], JSON_UNESCAPED_UNICODE); exit; }
    $upd = $db->prepare("UPDATE colleague_messages SET message=?, is_edited=1, edited_at=NOW() WHERE id=? AND sender_id=?");
    $upd->execute([$newText, $msgId, $userId]);
    if ($upd->rowCount() === 0) { echo json_encode(['status'=>'error','message'=>'خطا در ذخیره تغییرات'], JSON_UNESCAPED_UNICODE); exit; }
    $getStmt = $db->prepare("SELECT id, message, is_edited, edited_at FROM colleague_messages WHERE id=? LIMIT 1");
    $getStmt->execute([$msgId]);
    $updated = $getStmt->fetch(PDO::FETCH_ASSOC);
    echo json_encode(['status'=>'success','message'=>['id'=>(int)$updated['id'],'message'=>$updated['message'],'is_edited'=>(int)$updated['is_edited'],'edited_at'=>$updated['edited_at']]], JSON_UNESCAPED_UNICODE); exit;
}

// ================== حذف پیام ==================
if ($action === 'delete') {
    $msgId = (int)($_POST['message_id'] ?? 0);
    if ($msgId <= 0) { echo json_encode(['status'=>'error','message'=>'شناسه پیام نامعتبر'], JSON_UNESCAPED_UNICODE); exit; }
    $chk = $db->prepare("SELECT id, sender_id, receiver_id, attachment, created_at FROM colleague_messages WHERE id=? AND sender_id=? LIMIT 1");
    $chk->execute([$msgId, $userId]);
    $msgRow = $chk->fetch(PDO::FETCH_ASSOC);
    if (!$msgRow) { echo json_encode(['status'=>'error','message'=>'پیام یافت نشد'], JSON_UNESCAPED_UNICODE); exit; }
    $window = checkEditDeleteWindow($msgRow['created_at']);
    if (!$window['allowed']) { echo json_encode(['status'=>'error','message'=>$window['reason'],'remaining'=>$window['remaining']], JSON_UNESCAPED_UNICODE); exit; }
    if (!empty($msgRow['attachment'])) {
        $filePath = $CHAT_UPLOAD_DIR . basename($msgRow['attachment']);
        if (file_exists($filePath)) @unlink($filePath);
    }
    $del = $db->prepare("DELETE FROM colleague_messages WHERE id=? AND sender_id=?");
    $del->execute([$msgId, $userId]);
    if ($del->rowCount() === 0) { echo json_encode(['status'=>'error','message'=>'خطا در حذف پیام'], JSON_UNESCAPED_UNICODE); exit; }
    echo json_encode(['status'=>'success','message'=>'پیام برای هر دو طرف حذف شد','deleted_id'=>$msgId], JSON_UNESCAPED_UNICODE); exit;
}

// ================== لیست همکاران ==================
if ($action === 'list') {
    $stmt = $db->prepare("
        SELECT DISTINCT u.id AS user_id, u.first_name, u.last_name, u.mobile, u.avatar,
            (SELECT COUNT(*) FROM colleague_blocks cb WHERE cb.blocker_user_id=? AND cb.blocked_user_id=u.id) AS is_blocked_by_me,
            (SELECT COUNT(*) FROM colleague_blocks cb WHERE cb.blocker_user_id=u.id AND cb.blocked_user_id=?) AS has_blocked_me,
            (SELECT COUNT(*) FROM colleague_messages cm WHERE cm.sender_id=u.id AND cm.receiver_id=? AND cm.is_read=0) AS unread_count,
            (SELECT MAX(cm.created_at) FROM colleague_messages cm WHERE (cm.sender_id=u.id AND cm.receiver_id=?) OR (cm.sender_id=? AND cm.receiver_id=u.id)) AS last_msg_time
        FROM users u
        WHERE u.id != ? AND u.status = 'active'
          AND (
            EXISTS (SELECT 1 FROM colleagues c WHERE c.user_id=? AND c.colleague_user_id=u.id)
            OR EXISTS (SELECT 1 FROM colleagues c WHERE c.user_id=u.id AND c.colleague_user_id=?)
            OR EXISTS (SELECT 1 FROM colleague_messages cm2 WHERE (cm2.sender_id=u.id AND cm2.receiver_id=?) OR (cm2.sender_id=? AND cm2.receiver_id=u.id))
          )
        ORDER BY (last_msg_time IS NULL) ASC, last_msg_time DESC, u.first_name ASC");
    $stmt->execute([$userId, $userId, $userId, $userId, $userId, $userId, $userId, $userId, $userId, $userId]);
    $list = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($list as &$l) {
        $l['user_id'] = (int)$l['user_id'];
        $l['is_blocked_by_me'] = (int)$l['is_blocked_by_me'] === 1;
        $l['has_blocked_me'] = (int)$l['has_blocked_me'] === 1;
        $l['unread_count'] = (int)$l['unread_count'];
    }
    unset($l);
    echo json_encode(['status'=>'success','list'=>$list], JSON_UNESCAPED_UNICODE); exit;
}

// ================== Heartbeat ==================
if ($action === 'heartbeat') {
    $ok = updateLastSeen($db, $userId);
    echo json_encode(['status'=>$ok?'success':'error','now'=>time()], JSON_UNESCAPED_UNICODE); exit;
}

// ================== وضعیت کاربر ==================
if ($action === 'user_status') {
    $otherId = (int)($_GET['user_id'] ?? 0);
    if ($otherId <= 0) { echo json_encode(['status'=>'error','message'=>'شناسه نامعتبر'], JSON_UNESCAPED_UNICODE); exit; }
    if ($otherId === $userId) {
        echo json_encode(['status'=>'success','is_saved'=>true,'is_online'=>true,'last_seen'=>time(),'last_seen_text'=>null,'last_seen_absolute'=>null,'last_seen_relative'=>null,'now'=>time(),'threshold'=>ONLINE_THRESHOLD], JSON_UNESCAPED_UNICODE); exit;
    }

    // ⭐ چک ربات
    $chkBot = $db->prepare("SELECT mobile FROM users WHERE id = ? LIMIT 1");
    $chkBot->execute([$otherId]);
    if ($chkBot->fetchColumn() === '00000000000') {
        echo json_encode([
            'status'=>'success','is_saved'=>false,'is_bot'=>true,
            'is_online'=>false,'last_seen'=>null,
            'last_seen_text'=>'ربات اطلاع‌رسانی','last_seen_absolute'=>null,'last_seen_relative'=>null,
            'now'=>time(),'threshold'=>ONLINE_THRESHOLD
        ], JSON_UNESCAPED_UNICODE); exit;
    }

    $stmt = $db->prepare("SELECT id, last_seen FROM users WHERE id=? AND status='active' LIMIT 1");
    $stmt->execute([$otherId]);
    $target = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$target) { echo json_encode(['status'=>'error','message'=>'کاربر یافت نشد'], JSON_UNESCAPED_UNICODE); exit; }
    $lastSeenInt = $target['last_seen'] ? (int)$target['last_seen'] : null;
    $isOnline = isUserOnline($lastSeenInt);
    $lastSeenFormatted = formatLastSeenTehran($lastSeenInt);
    echo json_encode(['status'=>'success','is_saved'=>false,'is_bot'=>false,'is_online'=>$isOnline,'last_seen'=>$lastSeenInt,'last_seen_text'=>$lastSeenFormatted['text'],'last_seen_absolute'=>$lastSeenFormatted['absolute'],'last_seen_relative'=>$lastSeenFormatted['relative'],'now'=>time(),'threshold'=>ONLINE_THRESHOLD], JSON_UNESCAPED_UNICODE); exit;
}

if ($action === 'users_status') {
    $idsStr = $_GET['user_ids'] ?? '';
    $ids = array_values(array_filter(array_map('intval', explode(',', $idsStr))));
    $ids = array_values(array_filter($ids, function($id) use ($userId) { return $id !== $userId; }));
    if (empty($ids)) { echo json_encode(['status'=>'success','users'=>[],'now'=>time()], JSON_UNESCAPED_UNICODE); exit; }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $db->prepare("SELECT id, mobile, last_seen FROM users WHERE id IN ($placeholders) AND status='active'");
    $stmt->execute($ids);
    $users = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $isBot = ($row['mobile'] ?? '') === '00000000000';
        if ($isBot) {
            $users[(int)$row['id']] = [
                'is_bot'=>true,'is_online'=>false,'last_seen'=>null,
                'last_seen_text'=>'ربات اطلاع‌رسانی','last_seen_absolute'=>null,'last_seen_relative'=>null
            ];
            continue;
        }
        $lastSeenInt = $row['last_seen'] ? (int)$row['last_seen'] : null;
        $f = formatLastSeenTehran($lastSeenInt);
        $users[(int)$row['id']] = ['is_bot'=>false,'is_online'=>isUserOnline($lastSeenInt),'last_seen'=>$lastSeenInt,'last_seen_text'=>$f['text'],'last_seen_absolute'=>$f['absolute'],'last_seen_relative'=>$f['relative']];
    }
    echo json_encode(['status'=>'success','users'=>$users,'now'=>time(),'threshold'=>ONLINE_THRESHOLD], JSON_UNESCAPED_UNICODE); exit;
}

// ================== Saved Messages ==================
if ($action === 'saved_info') {
    updateLastSeen($db, $userId);
    $stmt = $db->prepare("SELECT id, message, attachment, attachment_name, attachment_type, attachment_size, created_at FROM colleague_messages WHERE sender_id=? AND receiver_id=? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$userId, $userId]);
    $lastMsg = $stmt->fetch(PDO::FETCH_ASSOC);
    $countStmt = $db->prepare("SELECT COUNT(*) FROM colleague_messages WHERE sender_id=? AND receiver_id=?");
    $countStmt->execute([$userId, $userId]);
    $totalCount = (int)$countStmt->fetchColumn();
    if ($lastMsg) { $lastMsg['id'] = (int)$lastMsg['id']; $lastMsg['attachment_size'] = !empty($lastMsg['attachment_size']) ? (int)$lastMsg['attachment_size'] : null; }
    echo json_encode(['status'=>'success','last_message'=>$lastMsg ?: null,'total_count'=>$totalCount], JSON_UNESCAPED_UNICODE); exit;
}

// ================== ⭐ آخرین پیام خوانده‌نشده هر فرستنده ==================
if ($action === 'latest_unread') {
    $stmt = $db->prepare("
        SELECT cm.sender_id, cm.id AS message_id, cm.message, cm.attachment_name, cm.created_at,
               u.first_name, u.last_name, u.mobile
        FROM colleague_messages cm
        INNER JOIN (
            SELECT sender_id, MAX(id) AS max_id
            FROM colleague_messages
            WHERE receiver_id = ? AND is_read = 0 AND sender_id != receiver_id
            GROUP BY sender_id
        ) AS latest ON cm.id = latest.max_id
        LEFT JOIN users u ON u.id = cm.sender_id
        ORDER BY cm.id DESC
    ");
    $stmt->execute([$userId]);

    $items = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $fullName = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));
        if ($fullName === '') $fullName = $r['mobile'] ?? 'کاربر';
        $items[] = [
            'sender_id'       => (int)$r['sender_id'],
            'message_id'      => (int)$r['message_id'],
            'name'            => $fullName,
            'message'         => (string)($r['message'] ?? ''),
            'attachment_name' => $r['attachment_name'] ?: null,
            'created_at'      => $r['created_at'],
        ];
    }
    echo json_encode(['status' => 'success', 'items' => $items], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['status'=>'error','message'=>'action نامعتبر'], JSON_UNESCAPED_UNICODE);