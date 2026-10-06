<?php
/**
 * POST /api/v1/colleagues/unblock/
 * رفع مسدودی کاربر
 * 
 * Body (JSON):
 *   {
 *     "user_id": 5    (اجباری)
 *   }
 */
require_once dirname(__DIR__, 2) . '/_bootstrap.php';

requireMethod('POST');

$input = $body;
$unblockUserId = (int)($input['user_id'] ?? $_GET['user_id'] ?? 0);

if ($unblockUserId <= 0) apiError('پارامتر user_id الزامی است.', 400, 'INVALID_ID');

try {
    $stmt = $db->prepare("DELETE FROM colleague_blocks WHERE blocker_user_id = ? AND blocked_user_id = ?");
    $stmt->execute([$userId, $unblockUserId]);

    if ($stmt->rowCount() === 0) {
        apiError('این کاربر در لیست مسدودشده‌های شما نیست.', 404, 'NOT_BLOCKED');
    }

    apiSuccess([
        'unblocked_user_id' => $unblockUserId,
    ], 'مسدودیت کاربر برداشته شد.');
} catch (Throwable $e) {
    error_log('[API colleagues/unblock] ' . $e->getMessage());
    apiError('خطا در رفع مسدودی.', 500);
}