<?php
/**
 * GET /api/v1/calls/stats/
 * آمار درخواست‌های تماس کاربر
 */
require_once dirname(__DIR__, 2) . '/_bootstrap.php';

requireMethod('GET');

try {
    $stmt = $db->prepare("
        SELECT 
            COUNT(*) AS total,
            COALESCE(SUM(CASE WHEN status = 0 THEN 1 ELSE 0 END), 0) AS new_count,
            COALESCE(SUM(CASE WHEN status = 1 THEN 1 ELSE 0 END), 0) AS done_count,
            COALESCE(SUM(CASE WHEN DATE(created_at) = CURDATE() THEN 1 ELSE 0 END), 0) AS today,
            COALESCE(SUM(CASE WHEN assignee_id IS NULL OR assignee_id = 0 THEN 1 ELSE 0 END), 0) AS unassigned
        FROM call_requests
        WHERE user_id = ? OR assignee_id = ?
    ");
    $stmt->execute([$userId, $userId]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);

    apiSuccess([
        'total'      => (int)($r['total'] ?? 0),
        'new'        => (int)($r['new_count'] ?? 0),
        'done'       => (int)($r['done_count'] ?? 0),
        'today'      => (int)($r['today'] ?? 0),
        'unassigned' => (int)($r['unassigned'] ?? 0),
    ]);
} catch (Throwable $e) {
    error_log('[API calls/stats] ' . $e->getMessage());
    apiError('خطا در محاسبه آمار.', 500);
}