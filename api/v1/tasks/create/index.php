<?php
/**
 * POST /api/v1/tasks/create/
 * ایجاد تسک جدید
 * 
 * Body (JSON):
 *   {
 *     "title": "...",
 *     "description": "...",
 *     "priority": "low|medium|high",
 *     "subject_id": int,
 *     "project_id": int,
 *     "assignee_id": int,
 *     "due_date": "YYYY-MM-DD"
 *   }
 */
require_once dirname(__DIR__, 2) . '/_bootstrap.php';

requireMethod('POST');

$input = $body;

$title = trim((string)($input['title'] ?? ''));
if ($title === '') apiError('عنوان وظیفه الزامی است.', 400, 'TITLE_REQUIRED');
if (mb_strlen($title) > 255) apiError('عنوان بسیار طولانی است.', 400, 'TITLE_TOO_LONG');

$desc = trim((string)($input['description'] ?? ''));
if (mb_strlen($desc) > 5000) apiError('توضیحات بسیار طولانی است.', 400, 'DESC_TOO_LONG');

$priority = (string)($input['priority'] ?? 'medium');
if (!in_array($priority, ['low', 'medium', 'high'], true)) $priority = 'medium';

$subjectId  = !empty($input['subject_id'])  ? (int)$input['subject_id']  : null;
$projectId  = !empty($input['project_id'])  ? (int)$input['project_id']  : null;
$assigneeId = !empty($input['assignee_id']) ? (int)$input['assignee_id'] : $userId;

$dueDate = null;
if (!empty($input['due_date'])) {
    $d = (string)$input['due_date'];
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) $dueDate = $d;
}

// اعتبارسنجی subject
if ($subjectId !== null) {
    try {
        $c = $db->prepare("SELECT id FROM subjects WHERE id = ? AND user_id = ? LIMIT 1");
        $c->execute([$subjectId, $userId]);
        if (!$c->fetchColumn()) $subjectId = null;
    } catch (Throwable $e) { $subjectId = null; }
}

// اعتبارسنجی project
if ($projectId !== null) {
    try {
        $c = $db->prepare("
            SELECT p.id FROM projects p
            WHERE p.id = ? AND (
                p.user_id = ?
                OR EXISTS (SELECT 1 FROM project_members pm WHERE pm.project_id = p.id AND pm.user_id = ?)
            ) LIMIT 1
        ");
        $c->execute([$projectId, $userId, $userId]);
        if (!$c->fetchColumn()) $projectId = null;
    } catch (Throwable $e) { $projectId = null; }
}

// اعتبارسنجی assignee
if (!isAssigneeAllowed($db, $userId, $assigneeId, $projectId)) {
    apiError('شما اجازه واگذاری تسک به این کاربر را ندارید.', 403, 'ASSIGNEE_NOT_ALLOWED');
}

try {
    $stmt = $db->prepare("
        INSERT INTO tasks (user_id, assignee_id, subject_id, project_id, title, description, priority, due_date)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([$userId, $assigneeId, $subjectId, $projectId, $title, $desc, $priority, $dueDate]);
    $newId = (int)$db->lastInsertId();

    apiSuccess([
        'id'          => $newId,
        'title'       => $title,
        'priority'    => $priority,
        'assignee_id' => $assigneeId,
        'project_id'  => $projectId,
        'subject_id'  => $subjectId,
        'due_date'    => $dueDate,
        'created_at'  => date('Y-m-d H:i:s'),
    ], 'وظیفه با موفقیت ایجاد شد.');
} catch (Throwable $e) {
    error_log('[API tasks/create] ' . $e->getMessage());
    apiError('خطا در ایجاد تسک.', 500);
}