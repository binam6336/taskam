<?php
/**
 * POST /api/v1/tickets/status/?id=N
 * تغییر وضعیت تیکت (باز ↔ بسته)
 * 
 * Body (JSON) — اختیاری:
 *   {
 *     "status": 0 | 1    // اگه نفرستی، toggle می‌کنه
 *   }
 */
require_once dirname(__DIR__, 2) . '/_bootstrap.php';

requireMethod('POST');

$input = $body;
$id = (int)($_GET['id'] ?? $input['id'] ?? 0);
if ($id <= 0) apiError('پارامتر id الزامی است.', 400, 'INVALID_ID');

try {
    $chk = $db->prepare("SELECT * FROM tickets WHERE id = ? LIMIT 1");
    $chk->execute([$id]);
    $ticket = $chk->fetch(PDO::FETCH_ASSOC);
    if (!$ticket) apiError('تیکت یافت نشد.', 404, 'TICKET_NOT_FOUND');
} catch (Throwable $e) {
    apiError('خطا در بررسی تیکت.', 500);
}

$currentStatus = (int)$ticket['status'];

if (array_key_exists('status', $input)) {
    $newStatus = (int)$input['status'];
    if (!in_array($newStatus, [0, 1], true)) {
        apiError('status باید 0 (باز) یا 1 (بسته) باشد.', 400, 'INVALID_STATUS');
    }
} else {
    $newStatus = $currentStatus === 1 ? 0 : 1;
}

try {
    if ($newStatus === 1) {
        $db->prepare("UPDATE tickets SET status = 1, completed_at = NOW() WHERE id = ?")->execute([$id]);
    } else {
        $db->prepare("UPDATE tickets SET status = 0, completed_at = NULL WHERE id = ?")->execute([$id]);
    }

    apiSuccess([
        'id'           => $id,
        'status'       => $newStatus,
        'is_done'      => ($newStatus === 1),
        'completed_at' => $newStatus === 1 ? date('Y-m-d H:i:s') : null,
    ], 'وضعیت تیکت تغییر کرد.');
} catch (Throwable $e) {
    error_log('[API tickets/status] ' . $e->getMessage());
    apiError('خطا در تغییر وضعیت.', 500);
}