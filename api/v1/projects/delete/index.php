<?php
/**
 * POST /api/v1/projects/delete/?id=N
 * حذف پروژه (فقط سازنده)
 */
require_once dirname(__DIR__, 2) . '/_bootstrap.php';

requireMethod('POST');

$input = $body;
$id = (int)($_GET['id'] ?? $input['id'] ?? 0);
if ($id <= 0) apiError('پارامتر id الزامی است.', 400, 'INVALID_ID');

try {
    $chk = $db->prepare("SELECT user_id, profile_image FROM projects WHERE id = ? LIMIT 1");
    $chk->execute([$id]);
    $row = $chk->fetch(PDO::FETCH_ASSOC);

    if (!$row) apiError('پروژه یافت نشد.', 404, 'PROJECT_NOT_FOUND');
    if ((int)$row['user_id'] !== $userId) {
        apiError('فقط سازنده می‌تواند پروژه را حذف کند.', 403, 'FORBIDDEN');
    }

    // حذف تصویر
    if (!empty($row['profile_image'])) {
        $imgPath = dirname(__DIR__, 4) . '/uploads/projects/' . basename($row['profile_image']);
        if (file_exists($imgPath)) @unlink($imgPath);
    }

    $db->beginTransaction();
    $db->prepare("DELETE FROM project_members WHERE project_id = ?")->execute([$id]);
    $db->prepare("DELETE FROM projects WHERE id = ?")->execute([$id]);
    $db->commit();

    apiSuccess(['id' => $id], 'پروژه با موفقیت حذف شد.');
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    error_log('[API projects/delete] ' . $e->getMessage());
    apiError('خطا در حذف پروژه.', 500);
}