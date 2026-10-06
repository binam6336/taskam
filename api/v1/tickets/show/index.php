<?php
/**
 * GET /api/v1/tickets/show/?id=N
 * جزئیات یک تیکت + کامنت‌ها
 */
require_once dirname(__DIR__, 2) . '/_bootstrap.php';

requireMethod('GET');

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) apiError('پارامتر id الزامی است.', 400, 'INVALID_ID');

try {
    $stmt = $db->prepare("SELECT * FROM tickets WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    $ticket = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$ticket) apiError('تیکت یافت نشد.', 404, 'TICKET_NOT_FOUND');

    $ticket['id']      = (int)$ticket['id'];
    $ticket['status']  = (int)$ticket['status'];
    $ticket['is_vip']  = (bool)$ticket['is_vip'];
    $ticket['is_done'] = ($ticket['status'] === 1);

    // کامنت‌ها
    $c = $db->prepare("
        SELECT id, comment, created_at
        FROM ticket_comments
        WHERE ticket_id = ?
        ORDER BY created_at ASC
    ");
    $c->execute([$id]);
    $ticket['comments'] = $c->fetchAll(PDO::FETCH_ASSOC);

    apiSuccess($ticket);
} catch (Throwable $e) {
    error_log('[API tickets/show] ' . $e->getMessage());
    apiError('خطا در واکشی تیکت.', 500);
}