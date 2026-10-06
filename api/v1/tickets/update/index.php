<?php
/**
 * POST /api/v1/tickets/update/?id=N
 * ویرایش تیکت
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

// استخراج مقادیر جدید
$ticketNumber = trim((string)($input['ticket_number'] ?? $ticket['ticket_number']));
if ($ticketNumber === '') apiError('شماره تیکت الزامی است.', 400, 'TICKET_NUMBER_REQUIRED');
if (mb_strlen($ticketNumber) > 50) apiError('شماره تیکت بسیار طولانی است.', 400);

$subject = trim((string)($input['subject'] ?? $ticket['subject'] ?? ''));
if (mb_strlen($subject) > 255) $subject = mb_substr($subject, 0, 255);

$taskLink = trim((string)($input['task_link'] ?? $ticket['task_link'] ?? ''));
if (mb_strlen($taskLink) > 500) $taskLink = mb_substr($taskLink, 0, 500);

$priority = (string)($input['priority'] ?? $ticket['priority'] ?? 'medium');
if (!in_array($priority, ['low', 'medium', 'high', 'very_high'], true)) $priority = 'medium';

$text = trim((string)($input['text'] ?? $ticket['text'] ?? ''));

$isVip = array_key_exists('is_vip', $input)
    ? (!empty($input['is_vip']) ? 1 : 0)
    : (int)($ticket['is_vip'] ?? 0);

try {
    $stmt = $db->prepare("
        UPDATE tickets 
        SET ticket_number = ?, subject = ?, task_link = ?, priority = ?, text = ?, is_vip = ?
        WHERE id = ?
    ");
    $stmt->execute([
        $ticketNumber,
        $subject !== '' ? $subject : null,
        $taskLink !== '' ? $taskLink : null,
        $priority,
        $text !== '' ? $text : null,
        $isVip,
        $id,
    ]);

    apiSuccess([
        'id'            => $id,
        'ticket_number' => $ticketNumber,
        'subject'       => $subject ?: null,
        'task_link'     => $taskLink ?: null,
        'priority'      => $priority,
        'text'          => $text ?: null,
        'is_vip'        => (bool)$isVip,
    ], 'تیکت با موفقیت ویرایش شد.');
} catch (Throwable $e) {
    error_log('[API tickets/update] ' . $e->getMessage());
    apiError('خطا در ویرایش تیکت.', 500);
}