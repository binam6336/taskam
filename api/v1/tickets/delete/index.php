<?php
/**
 * POST /api/v1/tickets/delete/?id=N
 * حذف تیکت + کامنت‌های آن
 */
require_once dirname(__DIR__, 2) . '/_bootstrap.php';

requireMethod('POST');

$input = $body;
$id = (int)($_GET['id'] ?? $input['id'] ?? 0);
if ($id <= 0) apiError('پارامتر id الزامی است.', 400, 'INVALID_ID');

try {
    $chk = $db->prepare("SELECT id FROM tickets WHERE id = ? LIMIT 1");
    $chk->execute([$id]);
    if (!$chk->fetchColumn()) apiError('تیکت یافت نشد.', 404, 'TICKET_NOT_FOUND');

    $db->prepare("DELETE FROM ticket_comments WHERE ticket_id = ?")->execute([$id]);
    $db->prepare("DELETE FROM tickets WHERE id = ?")->execute([$id]);

    apiSuccess(['id' => $id], 'تیکت حذف شد.');
} catch (Throwable $e) {
    error_log('[API tickets/delete] ' . $e->getMessage());
    apiError('خطا در حذف تیکت.', 500);
}