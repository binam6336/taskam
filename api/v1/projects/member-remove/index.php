<?php
/**
 * POST /api/v1/projects/member-remove/?id=N
 * حذف عضو از پروژه
 * 
 * Body (JSON):
 *   {
 *     "user_id": 5
 *   }
 */
require_once dirname(__DIR__, 2) . '/_bootstrap.php';

requireMethod('POST');

$input = $body;
$projectId = (int)($_GET['id'] ?? $input['project_id'] ?? 0);
$targetUserId = (int)($input['user_id'] ?? 0);

if ($projectId <= 0) apiError('پارامتر id پروژه الزامی است.', 400, 'INVALID_ID');
if ($targetUserId <= 0) apiError('پارامتر user_id الزامی است.', 400, 'INVALID_USER_ID');

try {
    $chk = $db->prepare("SELECT user_id FROM projects WHERE id = ? LIMIT 1");
    $chk->execute([$projectId]);
    $creatorId = (int)$chk->fetchColumn();
    if (!$creatorId) apiError('پروژه یافت نشد.', 404, 'PROJECT_NOT_FOUND');

    if ($targetUserId === $creatorId) {
        apiError('نمی‌توانید سازنده پروژه را حذف کنید.', 400, 'CANNOT_REMOVE_CREATOR');
    }

    $isCreator = ($creatorId === $userId);

    // چک دسترسی
    $canRemove = $isCreator;
    if (!$canRemove) {
        $p = $db->prepare("SELECT permissions FROM project_members WHERE project_id = ? AND user_id = ? LIMIT 1");
        $p->execute([$projectId, $userId]);
        $perms = json_decode($p->fetchColumn() ?: '[]', true);
        if (is_array($perms) && in_array('remove_member', $perms, true)) $canRemove = true;
    }

    if (!$canRemove) apiError('شما دسترسی حذف کاربر ندارید.', 403, 'FORBIDDEN');

    // چک عضویت
    $d = $db->prepare("SELECT id FROM project_members WHERE project_id = ? AND user_id = ? LIMIT 1");
    $d->execute([$projectId, $targetUserId]);
    if (!$d->fetchColumn()) apiError('این کاربر عضو پروژه نیست.', 404, 'NOT_A_MEMBER');

    // حذف
    $del = $db->prepare("DELETE FROM project_members WHERE project_id = ? AND user_id = ?");
    $del->execute([$projectId, $targetUserId]);

    apiSuccess([
        'project_id' => $projectId,
        'user_id'    => $targetUserId,
    ], 'کاربر از پروژه حذف شد.');
} catch (Throwable $e) {
    error_log('[API projects/member-remove] ' . $e->getMessage());
    apiError('خطا در حذف عضو.', 500);
}