<?php
/**
 * POST /api/v1/tasks/delete/?id=N
 */
require_once dirname(__DIR__, 2) . '/_bootstrap.php';

requireMethod('POST');

$input = $body;
$id = (int)($_GET['id'] ?? $input['id'] ?? 0);
if ($id <= 0) apiError('پارامتر id الزامی است.', 400, 'INVALID_ID');

$perm = getTaskPermission($db, $id, $userId, 'delete');
if (!$perm['task']) apiError('تسک یافت نشد.', 404, 'TASK_NOT_FOUND');
if (!$perm['allowed'] && !$perm['is_owner']) apiError('دسترسی ندارید.', 403, 'FORBIDDEN');

try {
    $db->prepare("DELETE FROM task_notes WHERE task_id = ?")->execute([$id]);
    $db->prepare("DELETE FROM tasks WHERE id = ?")->execute([$id]);
    apiSuccess(['id' => $id], 'وظیفه حذف شد.');
} catch (Throwable $e) {
    error_log('[API tasks/delete] ' . $e->getMessage());
    apiError('خطا در حذف تسک.', 500);
}