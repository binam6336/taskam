<?php
/**
 * GET /api/v1/colleagues/blocked/
 * لیست کاربرانی که من مسدود کرده‌ام
 */
require_once dirname(__DIR__, 2) . '/_bootstrap.php';

requireMethod('GET');

$limit  = min(200, max(1, (int)($_GET['limit'] ?? 100)));
$offset = max(0, (int)($_GET['offset'] ?? 0));

$sql = "
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
    LIMIT " . (int)$limit . " OFFSET " . (int)$offset;

try {
    $stmt = $db->prepare($sql);
    $stmt->execute([$userId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $blocked = [];
    foreach ($rows as $b) {
        $fullName = trim(($b['first_name'] ?? '') . ' ' . ($b['last_name'] ?? ''));
        if ($fullName === '') $fullName = $b['mobile'];

        $avatarUrl = null;
        if (!empty($b['avatar'])) {
            $avBase = basename($b['avatar']);
            if (preg_match('/^[A-Za-z0-9_.\-]+$/', $avBase)
                && file_exists(dirname(__DIR__, 4) . '/uploads/avatars/' . $avBase)) {
                $avatarUrl = '/uploads/avatars/' . rawurlencode($avBase);
            }
        }

        $blocked[] = [
            'block_id'   => (int)$b['block_id'],
            'user_id'    => (int)$b['user_id'],
            'full_name'  => $fullName,
            'mobile'     => $b['mobile'],
            'avatar_url' => $avatarUrl,
            'blocked_at' => $b['blocked_at'],
        ];
    }

    apiSuccess([
        'count'  => count($blocked),
        'limit'  => $limit,
        'offset' => $offset,
        'blocked' => $blocked,
    ]);
} catch (Throwable $e) {
    error_log('[API colleagues/blocked] ' . $e->getMessage());
    apiError('خطا در واکشی لیست.', 500);
}