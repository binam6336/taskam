<?php
/**
 * GET /api/v1/colleagues/stats/
 * آمار همکاران کاربر
 */
require_once dirname(__DIR__, 2) . '/_bootstrap.php';

requireMethod('GET');

try {
    $stmt = $db->prepare("
        SELECT 
            (SELECT COUNT(*) FROM colleagues c
                INNER JOIN users u ON u.id = c.colleague_user_id
                WHERE c.user_id = ? AND u.status = 'active') AS colleagues_count,
            (SELECT COUNT(*) FROM colleagues c
                INNER JOIN users u ON u.id = c.user_id
                WHERE c.colleague_user_id = ? AND u.status = 'active') AS followers_count,
            (SELECT COUNT(*) FROM colleague_blocks WHERE blocker_user_id = ?) AS blocked_count,
            (SELECT COUNT(*) FROM colleague_blocks WHERE blocked_user_id = ?) AS blocked_by_count
    ");
    $stmt->execute([$userId, $userId, $userId, $userId]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);

    apiSuccess([
        'colleagues'    => (int)($r['colleagues_count'] ?? 0),
        'followers'     => (int)($r['followers_count'] ?? 0),
        'blocked'       => (int)($r['blocked_count'] ?? 0),
        'blocked_by'    => (int)($r['blocked_by_count'] ?? 0),
    ]);
} catch (Throwable $e) {
    error_log('[API colleagues/stats] ' . $e->getMessage());
    apiError('خطا در محاسبه آمار.', 500);
}