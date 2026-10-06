<?php
/**
 * POST /api/v1/colleagues/block/
 * مسدود کردن یک کاربر
 * 
 * Body (JSON):
 *   {
 *     "user_id": 5    (اجباری)
 *   }
 */
require_once dirname(__DIR__, 2) . '/_bootstrap.php';

requireMethod('POST');

$input = $body;
$blockUserId = (int)($input['user_id'] ?? $_GET['user_id'] ?? 0);

if ($blockUserId <= 0) apiError('پارامتر user_id الزامی است.', 400, 'INVALID_ID');

if ($blockUserId === $userId) apiError('نمی‌توانید خودتان را مسدود کنید.', 400, 'CANNOT_BLOCK_SELF');

try {
    // چک وجود کاربر
    $chk = $db->prepare("SELECT id FROM users WHERE id = ? LIMIT 1");
    $chk->execute([$blockUserId]);
    if (!$chk->fetchColumn()) apiError('کاربر یافت نشد.', 404, 'USER_NOT_FOUND');

    // چک تکراری
    $dup = $db->prepare("SELECT id FROM colleague_blocks WHERE blocker_user_id = ? AND blocked_user_id = ? LIMIT 1");
    $dup->execute([$userId, $blockUserId]);
    if ($dup->fetchColumn()) {
        apiError('این کاربر قبلاً مسدود شده است.', 400, 'ALREADY_BLOCKED');
    }

    $stmt = $db->prepare("INSERT INTO colleague_blocks (blocker_user_id, blocked_user_id) VALUES (?, ?)");
    $stmt->execute([$userId, $blockUserId]);

    apiSuccess([
        'blocked_user_id' => $blockUserId,
        'blocked_at'      => date('Y-m-d H:i:s'),
    ], 'کاربر مسدود شد.');
} catch (Throwable $e) {
    error_log('[API colleagues/block] ' . $e->getMessage());
    apiError('خطا در مسدودسازی.', 500);
}