<?php
/**
 * GET /api/v1/texts/stats/
 * آمار متن‌های آماده کاربر
 */
require_once dirname(__DIR__, 2) . '/_bootstrap.php';

requireMethod('GET');

try {
    $stmt = $db->prepare("
        SELECT 
            COUNT(*) AS total,
            COALESCE(SUM(CASE WHEN DATE(created_at) = CURDATE() THEN 1 ELSE 0 END), 0) AS today,
            COALESCE(SUM(CASE WHEN DATE(updated_at) = CURDATE() THEN 1 ELSE 0 END), 0) AS updated_today
        FROM texts
        WHERE user_id = ?
    ");
    $stmt->execute([$userId]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);

    apiSuccess([
        'total'          => (int)($r['total'] ?? 0),
        'today'          => (int)($r['today'] ?? 0),
        'updated_today'  => (int)($r['updated_today'] ?? 0),
    ]);
} catch (Throwable $e) {
    error_log('[API texts/stats] ' . $e->getMessage());
    apiError('خطا در محاسبه آمار.', 500);
}