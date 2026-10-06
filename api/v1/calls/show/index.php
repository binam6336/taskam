<?php
/**
 * GET /api/v1/calls/show/?id=N
 * جزئیات یک درخواست تماس
 */
require_once dirname(__DIR__, 2) . '/_bootstrap.php';

requireMethod('GET');

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) apiError('پارامتر id الزامی است.', 400, 'INVALID_ID');

try {
    $stmt = $db->prepare("
        SELECT 
            cr.*,
            ua.first_name AS assignee_first_name,
            ua.last_name  AS assignee_last_name,
            ua.mobile     AS assignee_mobile,
            uc.first_name AS creator_first_name,
            uc.last_name  AS creator_last_name,
            uc.mobile     AS creator_mobile
        FROM call_requests cr
        LEFT JOIN users ua ON ua.id = cr.assignee_id
        LEFT JOIN users uc ON uc.id = cr.user_id
        WHERE cr.id = ? AND (cr.user_id = ? OR cr.assignee_id = ?)
        LIMIT 1
    ");
    $stmt->execute([$id, $userId, $userId]);
    $call = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$call) apiError('درخواست یافت نشد.', 404, 'CALL_NOT_FOUND');

    $call['id']          = (int)$call['id'];
    $call['user_id']     = (int)$call['user_id'];
    $call['assignee_id'] = $call['assignee_id'] !== null ? (int)$call['assignee_id'] : null;
    $call['status']      = (int)$call['status'];
    $call['is_done']     = ($call['status'] === 1);

    apiSuccess($call);
} catch (Throwable $e) {
    error_log('[API calls/show] ' . $e->getMessage());
    apiError('خطا در واکشی درخواست.', 500);
}