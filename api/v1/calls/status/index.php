<?php
/**
 * POST /api/v1/calls/status/?id=N
 * تغییر وضعیت درخواست (جدید ↔ انجام‌شده)
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
    $chk = $db->prepare("SELECT * FROM call_requests WHERE id = ? AND (user_id = ? OR assignee_id = ?) LIMIT 1");
    $chk->execute([$id, $userId, $userId]);
    $call = $chk->fetch(PDO::FETCH_ASSOC);
    if (!$call) apiError('درخواست یافت نشد.', 404, 'CALL_NOT_FOUND');
} catch (Throwable $e) {
    apiError('خطا در بررسی درخواست.', 500);
}

$currentStatus = (int)$call['status'];

// اگه status فرستاده شده، ازش استفاده کن. وگرنه toggle کن
if (array_key_exists('status', $input)) {
    $newStatus = (int)$input['status'];
    if (!in_array($newStatus, [0, 1], true)) {
        apiError('status باید 0 (جدید) یا 1 (انجام‌شده) باشد.', 400, 'INVALID_STATUS');
    }
} else {
    $newStatus = $currentStatus === 1 ? 0 : 1;
}

try {
    if ($newStatus === 1) {
        $stmt = $db->prepare("UPDATE call_requests SET status = 1, completed_at = NOW() WHERE id = ?");
    } else {
        $stmt = $db->prepare("UPDATE call_requests SET status = 0, completed_at = NULL WHERE id = ?");
    }
    $stmt->execute([$id]);

    apiSuccess([
        'id'           => $id,
        'status'       => $newStatus,
        'is_done'      => ($newStatus === 1),
        'completed_at' => $newStatus === 1 ? date('Y-m-d H:i:s') : null,
    ], 'وضعیت درخواست تغییر کرد.');
} catch (Throwable $e) {
    error_log('[API calls/status] ' . $e->getMessage());
    apiError('خطا در تغییر وضعیت.', 500);
}