<?php
/**
 * POST /api/v1/tasks/update/?id=N
 * ویرایش تسک
 */
require_once dirname(__DIR__, 2) . '/_bootstrap.php';

requireMethod('POST');

$input = $body;
$id = (int)($_GET['id'] ?? $input['id'] ?? 0);
if ($id <= 0) apiError('پارامتر id الزامی است.', 400, 'INVALID_ID');

$perm = getTaskPermission($db, $id, $userId, 'edit');
if (!$perm['task']) apiError('تسک یافت نشد.', 404, 'TASK_NOT_FOUND');
if (!$perm['allowed'] && !$perm['is_owner']) apiError('دسترسی ندارید.', 403, 'FORBIDDEN');

$title = trim((string)($input['title'] ?? $perm['task']['title']));
if ($title === '') apiError('عنوان وظیفه الزامی است.', 400, 'TITLE_REQUIRED');
if (mb_strlen($title) > 255) apiError('عنوان بسیار طولانی است.', 400, 'TITLE_TOO_LONG');

$desc = trim((string)($input['description'] ?? $perm['task']['description'] ?? ''));
if (mb_strlen($desc) > 5000) apiError('توضیحات بسیار طولانی است.', 400, 'DESC_TOO_LONG');

$priority = (string)($input['priority'] ?? $perm['task']['priority'] ?? 'medium');
if (!in_array($priority, ['low', 'medium', 'high'], true)) $priority = 'medium';

$subjectId = array_key_exists('subject_id', $input)
    ? (!empty($input['subject_id']) ? (int)$input['subject_id'] : null)
    : ($perm['task']['subject_id'] !== null ? (int)$perm['task']['subject_id'] : null);

$projectId = array_key_exists('project_id', $input)
    ? (!empty($input['project_id']) ? (int)$input['project_id'] : null)
    : ($perm['task']['project_id'] !== null ? (int)$perm['task']['project_id'] : null);

$assigneeId = !empty($input['assignee_id'])
    ? (int)$input['assignee_id']
    : (int)($perm['task']['assignee_id'] ?? $userId);

$dueDate = array_key_exists('due_date', $input)
    ? (!empty($input['due_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$input['due_date']) ? (string)$input['due_date'] : null)
    : ($perm['task']['due_date'] ?? null);

if (!isAssigneeAllowed($db, $userId, $assigneeId, $projectId)) {
    apiError('شما اجازه واگذاری تسک به این کاربر را ندارید.', 403, 'ASSIGNEE_NOT_ALLOWED');
}

try {
    $stmt = $db->prepare("
        UPDATE tasks 
        SET subject_id = ?, project_id = ?, assignee_id = ?, title = ?, description = ?, priority = ?, due_date = ?
        WHERE id = ?
    ");
    $stmt->execute([$subjectId, $projectId, $assigneeId, $title, $desc, $priority, $dueDate, $id]);

    apiSuccess([
        'id'          => $id,
        'title'       => $title,
        'priority'    => $priority,
        'assignee_id' => $assigneeId,
        'project_id'  => $projectId,
        'subject_id'  => $subjectId,
        'due_date'    => $dueDate,
    ], 'وظیفه با موفقیت ویرایش شد.');
} catch (Throwable $e) {
    error_log('[API tasks/update] ' . $e->getMessage());
    apiError('خطا در ویرایش تسک.', 500);
}