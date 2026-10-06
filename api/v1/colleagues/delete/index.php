<?php
/**
 * POST /api/v1/colleagues/delete/
 * حذف همکار از لیست
 * 
 * پارامترها:
 *   ?row_id=N         شناسه ردیف در colleagues
 *   یا
 *   ?user_id=N        شناسه کاربری همکار
 */
require_once dirname(__DIR__, 2) . '/_bootstrap.php';

requireMethod('POST');

$input = $body;

$rowId           = (int)($_GET['row_id'] ?? $input['row_id'] ?? 0);
$colleagueUserId = (int)($_GET['user_id'] ?? $input['user_id'] ?? 0);

if ($rowId <= 0 && $colleagueUserId <= 0) {
    apiError('پارامتر row_id یا user_id الزامی است.', 400, 'INVALID_ID');
}

try {
    if ($rowId > 0) {
        $stmt = $db->prepare("DELETE FROM colleagues WHERE id = ? AND user_id = ?");
        $stmt->execute([$rowId, $userId]);
    } else {
        $stmt = $db->prepare("DELETE FROM colleagues WHERE user_id = ? AND colleague_user_id = ?");
        $stmt->execute([$userId, $colleagueUserId]);
    }

    if ($stmt->rowCount() === 0) {
        apiError('همکار یافت نشد یا در لیست شما نیست.', 404, 'COLLEAGUE_NOT_FOUND');
    }

    apiSuccess([
        'deleted' => true,
    ], 'همکار از لیست حذف شد.');
} catch (Throwable $e) {
    error_log('[API colleagues/delete] ' . $e->getMessage());
    apiError('خطا در حذف همکار.', 500);
}