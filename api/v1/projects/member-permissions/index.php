<?php
/**
 * POST /api/v1/projects/member-permissions/?id=N
 * ویرایش دسترسی‌های یک عضو پروژه
 * 
 * Body (JSON):
 *   {
 *     "user_id": 5,
 *     "permissions": ["edit_project", "add_member"]
 *   }
 * 
 * دسترسی‌های مجاز:
 *   - edit_project
 *   - add_member
 *   - remove_member
 *   - manage_permissions
 */
require_once dirname(__DIR__, 2) . '/_bootstrap.php';

requireMethod('POST');

$ALLOWED_PERMS = ['edit_project', 'add_member', 'remove_member', 'manage_permissions'];

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
        apiError('مجوزهای سازنده پروژه قابل تغییر نیست.', 400, 'CANNOT_CHANGE_CREATOR_PERMS');
    }

    $isCreator = ($creatorId === $userId);

    // چک دسترسی
    $canManage = $isCreator;
    $grantable = $ALLOWED_PERMS;
    if (!$canManage) {
        $p = $db->prepare("SELECT permissions FROM project_members WHERE project_id = ? AND user_id = ? LIMIT 1");
        $p->execute([$projectId, $userId]);
        $myPerms = json_decode($p->fetchColumn() ?: '[]', true) ?: [];
        if (in_array('manage_permissions', $myPerms, true)) {
            $canManage = true;
            $grantable = array_values(array_intersect($myPerms, $ALLOWED_PERMS));
        }
    }

    if (!$canManage) apiError('شما دسترسی اعطای دسترسی ندارید.', 403, 'FORBIDDEN');

    // چک عضویت
    $d = $db->prepare("SELECT id FROM project_members WHERE project_id = ? AND user_id = ? LIMIT 1");
    $d->execute([$projectId, $targetUserId]);
    if (!$d->fetchColumn()) apiError('این کاربر عضو پروژه نیست.', 404, 'NOT_A_MEMBER');

    // اعتبارسنجی permissions
    $permsIn = $input['permissions'] ?? [];
    if (!is_array($permsIn)) $permsIn = [];

    $validPerms = [];
    foreach ($permsIn as $p) {
        $p = (string)$p;
        if (in_array($p, $grantable, true)) $validPerms[] = $p;
    }
    $validPerms = array_values(array_unique($validPerms));

    // اگر کاربر خودش رو ادیت می‌کنه، manage_permissions رو نگیره
    if ($targetUserId === $userId && !in_array('manage_permissions', $validPerms, true)) {
        if (in_array('manage_permissions', $grantable, true)) {
            $validPerms[] = 'manage_permissions';
        }
    }

    // به‌روزرسانی
    $json = json_encode($validPerms, JSON_UNESCAPED_UNICODE);
    $stmt = $db->prepare("UPDATE project_members SET permissions = ? WHERE project_id = ? AND user_id = ?");
    $stmt->execute([$json, $projectId, $targetUserId]);

    apiSuccess([
        'project_id'  => $projectId,
        'user_id'     => $targetUserId,
        'permissions' => $validPerms,
    ], 'دسترسی‌های عضو با موفقیت بروزرسانی شد.');
} catch (Throwable $e) {
    error_log('[API projects/member-permissions] ' . $e->getMessage());
    apiError('خطا در ویرایش دسترسی‌ها.', 500);
}