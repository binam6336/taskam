<?php
/**
 * POST /api/v1/projects/block/?id=N
 * مسدود کردن پروژه (پروژه از لیست کاربر مخفی می‌شه)
 */
require_once dirname(__DIR__, 2) . '/_bootstrap.php';

requireMethod('POST');

$input = $body;
$id = (int)($_GET['id'] ?? $input['id'] ?? 0);
if ($id <= 0) apiError('پارامتر id الزامی است.', 400, 'INVALID_ID');

try {
    // چک وجود پروژه
    $chk = $db->prepare("SELECT id FROM projects WHERE id = ? LIMIT 1");
    $chk->execute([$id]);
    if (!$chk->fetchColumn()) apiError('پروژه یافت نشد.', 404, 'PROJECT_NOT_FOUND');

    // چک تکراری
    $dup = $db->prepare("SELECT id FROM project_blocks WHERE project_id = ? AND user_id = ? LIMIT 1");
    $dup->execute([$id, $userId]);
    if ($dup->fetchColumn()) apiError('این پروژه قبلاً مسدود شده است.', 400, 'ALREADY_BLOCKED');

    $stmt = $db->prepare("INSERT INTO project_blocks (project_id, user_id, created_at) VALUES (?, ?, NOW())");
    $stmt->execute([$id, $userId]);

    apiSuccess([
        'project_id' => $id,
        'blocked_at' => date('Y-m-d H:i:s'),
    ], 'پروژه مسدود شد.');
} catch (Throwable $e) {
    error_log('[API projects/block] ' . $e->getMessage());
    apiError('خطا در مسدودسازی.', 500);
}