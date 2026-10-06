<?php
/**
 * POST /api/v1/colleagues/create/
 * افزودن همکار جدید با شماره موبایل
 * 
 * Body (JSON):
 *   {
 *     "mobile": "09123456789"    (اجباری)
 *   }
 * 
 * یا با شناسه:
 *   {
 *     "user_id": 5
 *   }
 */
require_once dirname(__DIR__, 2) . '/_bootstrap.php';

requireMethod('POST');

$input = $body;

$colleagueId = 0;

// حالت ۱: با user_id
if (!empty($input['user_id'])) {
    $colleagueId = (int)$input['user_id'];
}
// حالت ۲: با mobile
elseif (!empty($input['mobile'])) {
    $mobile = trim((string)$input['mobile']);
    $mobile = str_replace(
        ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹','٠','١','٢','٣','٤','٥','٦','٧','٨','٩'],
        ['0','1','2','3','4','5','6','7','8','9','0','1','2','3','4','5','6','7','8','9'],
        $mobile
    );

    if (!preg_match('/^09\d{9}$/', $mobile)) {
        apiError('فرمت شماره موبایل نامعتبر است.', 400, 'MOBILE_INVALID');
    }

    try {
        $find = $db->prepare("SELECT id FROM users WHERE mobile = ? AND status = 'active' LIMIT 1");
        $find->execute([$mobile]);
        $colleagueId = (int)$find->fetchColumn();

        if ($colleagueId <= 0) {
            apiError('کاربری با این شماره موبایل یافت نشد.', 404, 'USER_NOT_FOUND');
        }
    } catch (Throwable $e) {
        apiError('خطا در جستجوی کاربر.', 500);
    }
} else {
    apiError('پارامتر mobile یا user_id الزامی است.', 400, 'MISSING_PARAM');
}

if ($colleagueId === $userId) {
    apiError('نمی‌توانید خودتان را به عنوان همکار اضافه کنید.', 400, 'CANNOT_ADD_SELF');
}

try {
    // چک مسدودیت
    $chkBlock = $db->prepare("SELECT 1 FROM colleague_blocks WHERE blocker_user_id = ? AND blocked_user_id = ? LIMIT 1");
    $chkBlock->execute([$userId, $colleagueId]);
    if ($chkBlock->fetchColumn()) {
        apiError('شما این کاربر را مسدود کرده‌اید. ابتدا از لیست مسدودشده‌ها خارج کنید.', 403, 'BLOCKED_BY_ME');
    }

    $chkBlockedMe = $db->prepare("SELECT 1 FROM colleague_blocks WHERE blocker_user_id = ? AND blocked_user_id = ? LIMIT 1");
    $chkBlockedMe->execute([$colleagueId, $userId]);
    if ($chkBlockedMe->fetchColumn()) {
        apiError('این کاربر شما را مسدود کرده است.', 403, 'HAS_BLOCKED_ME');
    }

    // چک فعال بودن کاربر
    $chkUser = $db->prepare("SELECT id, allow_colleague_requests FROM users WHERE id = ? AND status = 'active' LIMIT 1");
    $chkUser->execute([$colleagueId]);
    $targetUser = $chkUser->fetch(PDO::FETCH_ASSOC);

    if (!$targetUser) apiError('کاربر مورد نظر معتبر نیست.', 404, 'USER_NOT_FOUND');

    if ((int)($targetUser['allow_colleague_requests'] ?? 1) !== 1) {
        apiError('این کاربر دریافت درخواست همکاری را مسدود کرده است.', 403, 'COLLEAGUE_REQUESTS_BLOCKED');
    }

    // چک تکراری
    $chkDup = $db->prepare("SELECT id FROM colleagues WHERE user_id = ? AND colleague_user_id = ? LIMIT 1");
    $chkDup->execute([$userId, $colleagueId]);
    if ($chkDup->fetchColumn()) {
        apiError('این کاربر قبلاً در لیست همکاران شما وجود دارد.', 400, 'ALREADY_EXISTS');
    }

    // درج
    $stmt = $db->prepare("INSERT INTO colleagues (user_id, colleague_user_id, permissions) VALUES (?, ?, '[]')");
    $stmt->execute([$userId, $colleagueId]);
    $newRowId = (int)$db->lastInsertId();

    apiSuccess([
        'row_id'      => $newRowId,
        'user_id'     => $colleagueId,
        'permissions' => [],
        'created_at'  => date('Y-m-d H:i:s'),
    ], 'همکار با موفقیت اضافه شد.');
} catch (Throwable $e) {
    error_log('[API colleagues/create] ' . $e->getMessage());
    apiError('خطا در افزودن همکار.', 500);
}