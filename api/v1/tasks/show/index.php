<?php
/**
 * GET /api/v1/tasks/show/?id=N
 * جزئیات یک تسک + یادداشت‌ها
 */
require_once dirname(__DIR__, 2) . '/_bootstrap.php';

requireMethod('GET');

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) apiError('پارامتر id الزامی است.', 400, 'INVALID_ID');

try {
    $stmt = $db->prepare("
        SELECT 
            t.*,
            s.title AS subject_title,
            p.title AS project_title,
            ua.first_name AS assignee_first_name,
            ua.last_name  AS assignee_last_name,
            ua.mobile     AS assignee_mobile,
            uc.first_name AS creator_first_name,
            uc.last_name  AS creator_last_name,
            uc.mobile     AS creator_mobile
        FROM tasks t
        LEFT JOIN subjects s ON t.subject_id = s.id
        LEFT JOIN projects p ON t.project_id = p.id
        LEFT JOIN users ua ON ua.id = t.assignee_id
        LEFT JOIN users uc ON uc.id = t.user_id
        WHERE t.id = ? AND (t.user_id = ? OR t.assignee_id = ?)
        LIMIT 1
    ");
    $stmt->execute([$id, $userId, $userId]);
    $task = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$task) apiError('تسک یافت نشد.', 404, 'TASK_NOT_FOUND');

    $task['id']           = (int)$task['id'];
    $task['user_id']      = (int)$task['user_id'];
    $task['assignee_id']  = $task['assignee_id'] !== null ? (int)$task['assignee_id'] : null;
    $task['subject_id']   = $task['subject_id']  !== null ? (int)$task['subject_id']  : null;
    $task['project_id']   = $task['project_id']  !== null ? (int)$task['project_id']  : null;
    $task['is_completed'] = (bool)$task['is_completed'];

    $n = $db->prepare("
        SELECT tn.id, tn.user_id, tn.note, tn.created_at, u.first_name, u.last_name, u.mobile
        FROM task_notes tn
        LEFT JOIN users u ON u.id = tn.user_id
        WHERE tn.task_id = ?
        ORDER BY tn.created_at DESC
    ");
    $n->execute([$id]);
    $task['notes'] = $n->fetchAll(PDO::FETCH_ASSOC);

    apiSuccess($task);
} catch (Throwable $e) {
    error_log('[API tasks/show] ' . $e->getMessage());
    apiError('خطا در واکشی تسک.', 500);
}