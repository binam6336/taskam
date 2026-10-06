<?php
/**
 * GET /api/v1/colleagues/search/?mobile=09123456789
 * جستجوی کاربر با شماره موبایل (برای افزودن به همکاران)
 */
require_once dirname(__DIR__, 2) . '/_bootstrap.php';

requireMethod('GET');

$mobile = trim((string)($_GET['mobile'] ?? ''));

if ($mobile === '') {
    apiError('پارامتر mobile الزامی است.', 400, 'MOBILE_REQUIRED');
}

// تبدیل اعداد فارسی به لاتین
$mobile = str_replace(
    ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹','٠','١','٢','٣','٤','٥','٦','٧','٨','٩'],
    ['0','1','2','3','4','5','6','7','8','9','0','1','2','3','4','5','6','7','8','9'],
    $mobile
);

if (!preg_match('/^09\d{9}$/', $mobile)) {
    apiError('فرمت شماره موبایل نامعتبر است.', 400, 'MOBILE_INVALID');
}

try {
    $stmt = $db->prepare("
        SELECT id, first_name, last_name, mobile, email, avatar, allow_colleague_requests
        FROM users
        WHERE mobile = ? AND id != ? AND status = 'active'
        LIMIT 1
    ");
    $stmt->execute([$mobile, $userId]);
    $found = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$found) apiError('کاربری با این شماره موبایل یافت نشد.', 404, 'USER_NOT_FOUND');

    $foundId = (int)$found['id'];

    // چک وضعیت همکاری
    $statusCheck = $db->prepare("
        SELECT 
            (SELECT COUNT(*) FROM colleagues WHERE user_id = ? AND colleague_user_id = ?) AS already_added,
            (SELECT COUNT(*) FROM colleague_blocks WHERE blocker_user_id = ? AND blocked_user_id = ?) AS is_blocked_by_me,
            (SELECT COUNT(*) FROM colleague_blocks WHERE blocker_user_id = ? AND blocked_user_id = ?) AS has_blocked_me
    ");
    $statusCheck->execute([$userId, $foundId, $userId, $foundId, $foundId, $userId]);
    $statusRow = $statusCheck->fetch(PDO::FETCH_ASSOC);

    $fullName = trim(($found['first_name'] ?? '') . ' ' . ($found['last_name'] ?? ''));
    if ($fullName === '') $fullName = $found['mobile'];

    $avatarUrl = null;
    if (!empty($found['avatar'])) {
        $avBase = basename($found['avatar']);
        if (preg_match('/^[A-Za-z0-9_.\-]+$/', $avBase)
            && file_exists(dirname(__DIR__, 4) . '/uploads/avatars/' . $avBase)) {
            $avatarUrl = '/uploads/avatars/' . rawurlencode($avBase);
        }
    }

    apiSuccess([
        'user' => [
            'id'         => $foundId,
            'first_name' => $found['first_name'],
            'last_name'  => $found['last_name'],
            'full_name'  => $fullName,
            'mobile'     => $found['mobile'],
            'email'      => $found['email'],
            'avatar_url' => $avatarUrl,
        ],
        'already_added'    => (int)$statusRow['already_added'] > 0,
        'is_blocked'       => (int)($found['allow_colleague_requests'] ?? 1) !== 1,
        'is_blocked_by_me' => (int)$statusRow['is_blocked_by_me'] > 0,
        'has_blocked_me'   => (int)$statusRow['has_blocked_me'] > 0,
    ]);
} catch (Throwable $e) {
    error_log('[API colleagues/search] ' . $e->getMessage());
    apiError('خطا در جستجو.', 500);
}