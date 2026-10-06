<?php
/**
 * POST /api/v1/projects/leave/?id=N
 * خروج از پروژه (خود کاربر)
 * 
 * نکته: سازنده نمی‌تواند از پروژه خودش خارج شود
 */
require_once dirname(__DIR__, 2) . '/_bootstrap.php';

requireMethod('POST');

$input = $body;
$id = (int)($_GET['id'] ?? $input['id'] ?? 0);
if ($id <= 0) apiError('پارامتر id الزامی است.', 400, 'INVALID_ID');

try {
    $chk = $db->prepare("SELECT user_id, title, profile_image FROM projects WHERE id = ? LIMIT 1");
    $chk->execute([$id]);
    $project = $chk->fetch(PDO::FETCH_ASSOC);
    if (!$project) apiError('پروژه یافت نشد.', 404, 'PROJECT_NOT_FOUND');

    if ((int)$project['user_id'] === $userId) {
        apiError('سازنده نمی‌تواند از پروژه خودش خارج شود.', 400, 'CREATOR_CANNOT_LEAVE');
    }

    // چک عضویت
    $d = $db->prepare("SELECT id FROM project_members WHERE project_id = ? AND user_id = ? LIMIT 1");
    $d->execute([$id, $userId]);
    if (!$d->fetchColumn()) apiError('شما عضو این پروژه نیستید.', 404, 'NOT_A_MEMBER');

    $db->beginTransaction();

    // ثبت تاریخچه
    $hist = $db->prepare("
        INSERT INTO project_leave_history (project_id, user_id, project_title, project_image, left_at)
        VALUES (?, ?, ?, ?, NOW())
    ");
    $hist->execute([$id, $userId, $project['title'], $project['profile_image']]);

    // حذف از اعضا
    $db->prepare("DELETE FROM project_members WHERE project_id = ? AND user_id = ?")->execute([$id, $userId]);

    $db->commit();

    apiSuccess([
        'project_id' => $id,
        'left_at'    => date('Y-m-d H:i:s'),
    ], 'با موفقیت از پروژه خارج شدید.');
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    error_log('[API projects/leave] ' . $e->getMessage());
    apiError('خطا در خروج از پروژه.', 500);
}