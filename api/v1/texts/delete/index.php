<?php
/**
 * POST /api/v1/texts/delete/?id=XXX
 * حذف متن آماده
 */
require_once dirname(__DIR__, 2) . '/_bootstrap.php';

requireMethod('POST');

$input = $body;
$id = trim((string)($_GET['id'] ?? $input['id'] ?? ''));
if ($id === '') apiError('پارامتر id الزامی است.', 400, 'INVALID_ID');

try {
    $chk = $db->prepare("SELECT id FROM texts WHERE id = ? AND user_id = ? LIMIT 1");
    $chk->execute([$id, $userId]);
    if (!$chk->fetchColumn()) apiError('متن یافت نشد.', 404, 'TEXT_NOT_FOUND');

    $db->prepare("DELETE FROM texts WHERE id = ? AND user_id = ?")->execute([$id, $userId]);

    apiSuccess(['id' => $id], 'متن حذف شد.');
} catch (Throwable $e) {
    error_log('[API texts/delete] ' . $e->getMessage());
    apiError('خطا در حذف متن.', 500);
}