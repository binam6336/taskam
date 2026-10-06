<?php
/**
 * GET /api/v1/colleagues/show/
 * جزئیات یک همکار
 * 
 * پارامترها:
 *   ?user_id=N     شناسه کاربری همکار
 *   یا
 *   ?row_id=N      شناسه ردیف در جدول colleagues
 */
require_once dirname(__DIR__, 2) . '/_bootstrap.php';

requireMethod('GET');

$colleagueUserId = (int)($_GET['user_id'] ?? 0);
$rowId           = (int)($_GET['row_id'] ?? 0);

if ($colleagueUserId <= 0 && $rowId <= 0) {
    apiError('پارامتر user_id یا row_id الزامی است.', 400, 'INVALID_ID');
}

try {
    if ($rowId > 0) {
        $stmt = $db->prepare("
            SELECT c.*, u.first_name, u.last_name, u.mobile, u.email, u.avatar, u.status AS user_status
            FROM colleagues c
            INNER JOIN users u ON u.id = c.colleague_user_id
            WHERE c.id = ? AND c.user_id = ?
            LIMIT 1
        ");
        $stmt->execute([$rowId, $userId]);
    } else {
        $stmt = $db->prepare("
            SELECT c.*, u.first_name, u.last_name, u.mobile, u.email, u.avatar, u.status AS user_status
            FROM colleagues c
            INNER JOIN users u ON u.id = c.colleague_user_id
            WHERE c.user_id = ? AND c.colleague_user_id = ?
            LIMIT 1
        ");
        $stmt->execute([$userId, $colleagueUserId]);
    }

    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) apiError('همکار یافت نشد.', 404, 'COLLEAGUE_NOT_FOUND');

    $perms = json_decode($row['permissions'] ?? '[]', true);
    if (!is_array($perms)) $perms = [];

    $fullName = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
    if ($fullName === '') $fullName = $row['mobile'];

    $avatarUrl = null;
    if (!empty($row['avatar'])) {
        $avBase = basename($row['avatar']);
        if (preg_match('/^[A-Za-z0-9_.\-]+$/', $avBase)
            && file_exists(dirname(__DIR__, 4) . '/uploads/avatars/' . $avBase)) {
            $avatarUrl = '/uploads/avatars/' . rawurlencode($avBase);
        }
    }

    // چک بلاک‌ها
    $blk = $db->prepare("
        SELECT 
            (SELECT COUNT(*) FROM colleague_blocks WHERE blocker_user_id = ? AND blocked_user_id = ?) AS is_blocked_by_me,
            (SELECT COUNT(*) FROM colleague_blocks WHERE blocker_user_id = ? AND blocked_user_id = ?) AS has_blocked_me
    ");
    $blk->execute([$userId, $row['colleague_user_id'], $row['colleague_user_id'], $userId]);
    $blkRow = $blk->fetch(PDO::FETCH_ASSOC);

    apiSuccess([
        'row_id'           => (int)$row['id'],
        'user_id'          => (int)$row['colleague_user_id'],
        'first_name'       => $row['first_name'],
        'last_name'        => $row['last_name'],
        'full_name'        => $fullName,
        'mobile'           => $row['mobile'],
        'email'            => $row['email'],
        'avatar_url'       => $avatarUrl,
        'permissions'      => $perms,
        'added_at'         => $row['created_at'],
        'is_blocked_by_me' => (int)$blkRow['is_blocked_by_me'] > 0,
        'has_blocked_me'   => (int)$blkRow['has_blocked_me'] > 0,
    ]);
} catch (Throwable $e) {
    error_log('[API colleagues/show] ' . $e->getMessage());
    apiError('خطا در واکشی همکار.', 500);
}