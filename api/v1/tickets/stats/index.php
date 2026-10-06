<?php
/**
 * GET /api/v1/tickets/stats/
 * آمار تیکت‌ها (سراسری)
 */
require_once dirname(__DIR__, 2) . '/_bootstrap.php';

requireMethod('GET');

try {
    $stmt = $db->query("
        SELECT 
            COUNT(*) AS total,
            COALESCE(SUM(CASE WHEN status = 0 THEN 1 ELSE 0 END), 0) AS open_count,
            COALESCE(SUM(CASE WHEN status = 1 THEN 1 ELSE 0 END), 0) AS done_count,
            COALESCE(SUM(CASE WHEN is_vip = 1 THEN 1 ELSE 0 END), 0) AS vip_count,
            COALESCE(SUM(CASE WHEN priority = 'very_high' THEN 1 ELSE 0 END), 0) AS very_high,
            COALESCE(SUM(CASE WHEN priority = 'high' THEN 1 ELSE 0 END), 0) AS high,
            COALESCE(SUM(CASE WHEN priority = 'medium' THEN 1 ELSE 0 END), 0) AS medium,
            COALESCE(SUM(CASE WHEN priority = 'low' THEN 1 ELSE 0 END), 0) AS low,
            COALESCE(SUM(CASE WHEN DATE(created_at) = CURDATE() THEN 1 ELSE 0 END), 0) AS today
        FROM tickets
    ");
    $r = $stmt->fetch(PDO::FETCH_ASSOC);

    apiSuccess([
        'total'      => (int)($r['total'] ?? 0),
        'open'       => (int)($r['open_count'] ?? 0),
        'done'       => (int)($r['done_count'] ?? 0),
        'vip'        => (int)($r['vip_count'] ?? 0),
        'very_high'  => (int)($r['very_high'] ?? 0),
        'high'       => (int)($r['high'] ?? 0),
        'medium'     => (int)($r['medium'] ?? 0),
        'low'        => (int)($r['low'] ?? 0),
        'today'      => (int)($r['today'] ?? 0),
    ]);
} catch (Throwable $e) {
    error_log('[API tickets/stats] ' . $e->getMessage());
    apiError('خطا در محاسبه آمار.', 500);
}