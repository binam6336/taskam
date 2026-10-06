<?php
/**
 * POST /api/v1/tasks/toggle/?id=N
 * تغییر وضعیت تکمیل
 */
require_once dirname(__DIR__, 2) . '/_bootstrap.php';

requireMethod('POST');

$input = $body;
$id = (int)($_GET['id'] ?? $input['id'] ?? 0);
if ($id <= 0) apiError('پارامتر id الزامی است.', 400, 'INVALID_ID');

$perm = getTaskPermission($db, $id, $userId, 'complete');
if (!$perm['task']) apiError('تسک یافت نشد.', 404, 'TASK_NOT_FOUND');
if (!$perm['allowed'] && !$perm['is_owner']) apiError('دسترسی ندارید.', 403, 'FORBIDDEN');

$current = (int)$perm['task']['is_completed'];

try {
    if ($current === 1) {
        $db->prepare("UPDATE tasks SET is_completed = 0, completed_at = NULL WHERE id = ?")->execute([$id]);
        $newState = false;
    } else {
        $db->prepare("UPDATE tasks SET is_completed = 1, completed_at = NOW() WHERE id = ?")->execute([$id]);
        $newState = true;
    }
    apiSuccess(['id' => $id, 'is_completed' => $newState], 'وضعیت تسک تغییر کرد.');
} catch (Throwable $e) {
    error_log('[API tasks/toggle] ' . $e->getMessage());
    apiError('خطا در تغییر وضعیت.', 500);
}