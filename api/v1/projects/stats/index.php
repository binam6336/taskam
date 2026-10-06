<?php
/**
 * GET /api/v1/projects/stats/
 * آمار پروژه‌های کاربر
 */
require_once dirname(__DIR__, 2) . '/_bootstrap.php';

requireMethod('GET');

try {
    $stmt = $db->prepare("
        SELECT 
            (SELECT COUNT(*) FROM projects WHERE user_id = ?) AS created_count,
            (SELECT COUNT(*) FROM project_members WHERE user_id = ?) AS total_memberships,
            (SELECT COUNT(DISTINCT p.id) FROM projects p
                WHERE p.user_id = ?
                   OR EXISTS (SELECT 1 FROM project_members pm WHERE pm.project_id = p.id AND pm.user_id = ?)) AS total_projects,
            (SELECT COUNT(*) FROM project_blocks WHERE user_id = ?) AS blocked_count
    ");
    $stmt->execute([$userId, $userId, $userId, $userId, $userId]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);

    apiSuccess([
        'created'         => (int)($r['created_count'] ?? 0),
        'total_projects'  => (int)($r['total_projects'] ?? 0),
        'total_members'   => (int)($r['total_memberships'] ?? 0),
        'blocked'         => (int)($r['blocked_count'] ?? 0),
    ]);
} catch (Throwable $e) {
    error_log('[API projects/stats] ' . $e->getMessage());
    apiError('خطا در محاسبه آمار.', 500);
}