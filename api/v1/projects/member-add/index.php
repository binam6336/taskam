<?php
/**
 * POST /api/v1/projects/member-add/?id=N
 * افزودن عضو به پروژه
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
$newUserId = (int)($input['user_id'] ?? 0);

if ($projectId <= 0) apiError('پارامتر id پروژه الزامی است.', 400, 'INVALID_ID');
if ($newUserId <= 0) apiError('پارامتر user_id الزامی است.', 400, 'INVALID_USER_ID');

try {
    $chk = $db->prepare("SELECT user_id, max_members FROM projects WHERE id = ? LIMIT 1");
    $chk->execute([$projectId]);
    $project = $chk->fetch(PDO::FETCH_ASSOC);
    if (!$project) apiError('پروژه یافت نشد.', 404, 'PROJECT_NOT_FOUND');

    $creatorId = (int)$project['user_id'];
    $isCreator = ($creatorId === $userId);

    // چک دسترسی
    $canAdd = $isCreator;
    if (!$canAdd) {
        $p = $db->prepare("SELECT permissions FROM project_members WHERE project_id = ? AND user_id = ? LIMIT 1");
        $p->execute([$projectId, $userId]);
        $perms = json_decode($p->fetchColumn() ?: '[]', true);
        if (is_array($perms) && in_array('add_member', $perms, true)) $canAdd = true;
    }

    if (!$canAdd) apiError('شما دسترسی افزودن کاربر ندارید.', 403, 'FORBIDDEN');

    // چک وجود کاربر
    $u = $db->prepare("SELECT id, status FROM users WHERE id = ? LIMIT 1");
    $u->execute([$newUserId]);
    $user = $u->fetch(PDO::FETCH_ASSOC);
    if (!$user) apiError('کاربر یافت نشد.', 404, 'USER_NOT_FOUND');
    if ($user['status'] !== 'active') apiError('کاربر فعال نیست.', 400, 'USER_NOT_ACTIVE');

    // چک همکار بودن
    $c = $db->prepare("SELECT 1 FROM colleagues WHERE user_id = ? AND colleague_user_id = ? LIMIT 1");
    $c->execute([$userId, $newUserId]);
    if (!$c->fetchColumn()) {
        apiError('این کاربر در لیست همکاران شما نیست.', 403, 'NOT_A_COLLEAGUE');
    }

    // چک تکراری
    $d = $db->prepare("SELECT 1 FROM project_members WHERE project_id = ? AND user_id = ? LIMIT 1");
    $d->execute([$projectId, $newUserId]);
    if ($d->fetchColumn()) apiError('این کاربر قبلاً عضو پروژه است.', 400, 'ALREADY_MEMBER');

    // چک ظرفیت
    $cnt = $db->prepare("SELECT COUNT(*) FROM project_members WHERE project_id = ?");
    $cnt->execute([$projectId]);
    $currentCount = (int)$cnt->fetchColumn();

    if ($currentCount >= (int)$project['max_members']) {
        apiError('ظرفیت پروژه تکمیل است.', 400, 'PROJECT_FULL');
    }

    // درج
    $emptyPerms = json_encode([], JSON_UNESCAPED_UNICODE);
    $stmt = $db->prepare("INSERT INTO project_members (project_id, user_id, permissions, added_at) VALUES (?, ?, ?, NOW())");
    $stmt->execute([$projectId, $newUserId, $emptyPerms]);

    apiSuccess([
        'project_id' => $projectId,
        'user_id'    => $newUserId,
        'added_at'   => date('Y-m-d H:i:s'),
    ], 'کاربر با موفقیت به پروژه اضافه شد.');
} catch (Throwable $e) {
    error_log('[API projects/member-add] ' . $e->getMessage());
    apiError('خطا در افزودن عضو.', 500);
}