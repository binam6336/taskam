<?php
/**
 * POST /api/v1/projects/update/?id=N
 * ویرایش پروژه
 * 
 * Body (JSON):
 *   {
 *     "title": "string",
 *     "description": "string",
 *     "max_members": 15
 *   }
 */
require_once dirname(__DIR__, 2) . '/_bootstrap.php';

requireMethod('POST');

$input = $body;
$id = (int)($_GET['id'] ?? $input['id'] ?? 0);
if ($id <= 0) apiError('پارامتر id الزامی است.', 400, 'INVALID_ID');

// چک پروژه
try {
    $chk = $db->prepare("SELECT * FROM projects WHERE id = ? LIMIT 1");
    $chk->execute([$id]);
    $project = $chk->fetch(PDO::FETCH_ASSOC);
    if (!$project) apiError('پروژه یافت نشد.', 404, 'PROJECT_NOT_FOUND');
} catch (Throwable $e) {
    apiError('خطا در بررسی پروژه.', 500);
}

$isCreator = ((int)$project['user_id'] === $userId);

// چک دسترسی ویرایش
$canEdit = $isCreator;
if (!$canEdit) {
    try {
        $p = $db->prepare("SELECT permissions FROM project_members WHERE project_id = ? AND user_id = ? LIMIT 1");
        $p->execute([$id, $userId]);
        $perms = json_decode($p->fetchColumn() ?: '[]', true);
        if (is_array($perms) && in_array('edit_project', $perms, true)) $canEdit = true;
    } catch (Throwable $e) {}
}

if (!$canEdit) apiError('شما دسترسی ویرایش این پروژه را ندارید.', 403, 'FORBIDDEN');

// استخراج مقادیر جدید
$title = trim((string)($input['title'] ?? $project['title']));
if ($title === '') apiError('عنوان پروژه الزامی است.', 400, 'TITLE_REQUIRED');
if (mb_strlen($title) > 255) apiError('عنوان بسیار طولانی است.', 400, 'TITLE_TOO_LONG');

$description = trim((string)($input['description'] ?? $project['description'] ?? ''));

$maxMembers = array_key_exists('max_members', $input)
    ? (int)$input['max_members']
    : (int)$project['max_members'];

if ($maxMembers < 1 || $maxMembers > 100) {
    apiError('حداکثر اعضا باید بین ۱ تا ۱۰۰ باشد.', 400, 'INVALID_MAX_MEMBERS');
}

// چک تعداد فعلی اعضا
$countStmt = $db->prepare("SELECT COUNT(*) FROM project_members WHERE project_id = ?");
$countStmt->execute([$id]);
$currentCount = (int)$countStmt->fetchColumn();

if ($currentCount > $maxMembers) {
    apiError("تعداد اعضای فعلی ({$currentCount}) از حداکثر جدید ({$maxMembers}) بیشتر است.", 400, 'MAX_MEMBERS_TOO_SMALL');
}

try {
    $stmt = $db->prepare("
        UPDATE projects 
        SET title = ?, description = ?, max_members = ?
        WHERE id = ?
    ");
    $stmt->execute([$title, $description ?: null, $maxMembers, $id]);

    apiSuccess([
        'id'          => $id,
        'title'       => $title,
        'description' => $description ?: null,
        'max_members' => $maxMembers,
        'updated_at'  => date('Y-m-d H:i:s'),
    ], 'پروژه با موفقیت ویرایش شد.');
} catch (Throwable $e) {
    error_log('[API projects/update] ' . $e->getMessage());
    apiError('خطا در ویرایش پروژه.', 500);
}