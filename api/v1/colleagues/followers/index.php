<?php
/**
 * GET /api/v1/colleagues/followers/
 * لیست کاربرانی که من را به عنوان همکار اضافه کرده‌اند
 */
require_once dirname(__DIR__, 2) . '/_bootstrap.php';

requireMethod('GET');

$limit  = min(200, max(1, (int)($_GET['limit'] ?? 100)));
$offset = max(0, (int)($_GET['offset'] ?? 0));

$sql = "
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
    LIMIT " . (int)$limit . " OFFSET " . (int)$offset;

try {
    $stmt = $db->prepare($sql);
    $stmt->execute([$userId, $userId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $followers = [];
    foreach ($rows as $f) {
        $fullName = trim(($f['first_name'] ?? '') . ' ' . ($f['last_name'] ?? ''));
        if ($fullName === '') $fullName = $f['mobile'];

        $avatarUrl = null;
        if (!empty($f['avatar'])) {
            $avBase = basename($f['avatar']);
            if (preg_match('/^[A-Za-z0-9_.\-]+$/', $avBase)
                && file_exists(dirname(__DIR__, 4) . '/uploads/avatars/' . $avBase)) {
                $avatarUrl = '/uploads/avatars/' . rawurlencode($avBase);
            }
        }

        $followers[] = [
            'row_id'         => (int)$f['row_id'],
            'user_id'        => (int)$f['user_id'],
            'full_name'      => $fullName,
            'mobile'         => $f['mobile'],
            'email'          => $f['email'],
            'avatar_url'     => $avatarUrl,
            'added_at'       => $f['added_at'],
            'is_blocked_by_me' => (int)$f['is_blocked_by_me'] > 0,
        ];
    }

    apiSuccess([
        'count'  => count($followers),
        'limit'  => $limit,
        'offset' => $offset,
        'followers' => $followers,
    ]);
} catch (Throwable $e) {
    error_log('[API colleagues/followers] ' . $e->getMessage());
    apiError('خطا در واکشی لیست.', 500);
}