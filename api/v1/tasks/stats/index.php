<?php
/**
 * GET /api/v1/tasks/stats/
 * آمار تسک‌ها
 */
require_once dirname(__DIR__, 2) . '/_bootstrap.php';

requireMethod('GET');

try {
    $stmt = $db->prepare("
        SELECT 
            COUNT(*) AS total,
            COALESCE(SUM(CASE WHEN is_completed = 1 THEN 1 ELSE 0 END), 0) AS completed,
            COALESCE(SUM(CASE WHEN is_completed = 0 THEN 1 ELSE 0 END), 0) AS pending,
            COALESCE(SUM(CASE WHEN priority = 'high' THEN 1 ELSE 0 END), 0) AS high,
            COALESCE(SUM(CASE WHEN priority = 'medium' THEN 1 ELSE 0 END), 0) AS medium,
            COALESCE(SUM(CASE WHEN priority = 'low' THEN 1 ELSE 0 END), 0) AS low
        FROM tasks
        WHERE assignee_id = ?
    ");
    $stmt->execute([$userId]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);

    apiSuccess([
        'total'     => (int)($r['total'] ?? 0),
        'completed' => (int)($r['completed'] ?? 0),
        'pending'   => (int)($r['pending'] ?? 0),
        'high'      => (int)($r['high'] ?? 0),
        'medium'    => (int)($r['medium'] ?? 0),
        'low'       => (int)($r['low'] ?? 0),
    ]);
} catch (Throwable $e) {
    error_log('[API tasks/stats] ' . $e->getMessage());
    apiError('خطا در محاسبه آمار.', 500);
}