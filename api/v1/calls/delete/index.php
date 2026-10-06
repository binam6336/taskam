<?php
/**
 * POST /api/v1/calls/delete/?id=N
 * حذف درخواست تماس
 */
require_once dirname(__DIR__, 2) . '/_bootstrap.php';

requireMethod('POST');

$input = $body;
$id = (int)($_GET['id'] ?? $input['id'] ?? 0);
if ($id <= 0) apiError('پارامتر id الزامی است.', 400, 'INVALID_ID');

try {
    $chk = $db->prepare("SELECT user_id FROM call_requests WHERE id = ? LIMIT 1");
    $chk->execute([$id]);
    $ownerId = $chk->fetchColumn();

    if ($ownerId === false) apiError('درخواست یافت نشد.', 404, 'CALL_NOT_FOUND');

    if ((int)$ownerId !== $userId) {
        apiError('فقط سازنده می‌تواند این درخواست را حذف کند.', 403, 'FORBIDDEN');
    }

    $db->prepare("DELETE FROM call_requests WHERE id = ?")->execute([$id]);
    apiSuccess(['id' => $id], 'درخواست حذف شد.');
} catch (Throwable $e) {
    error_log('[API calls/delete] ' . $e->getMessage());
    apiError('خطا در حذف درخواست.', 500);
}