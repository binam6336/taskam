<?php
/**
 * POST /api/v1/tickets/create/
 * ایجاد تیکت جدید
 * 
 * Body (JSON):
 *   {
 *     "ticket_number": "94632" (اجباری),
 *     "subject": "string",
 *     "priority": "low|medium|high|very_high",
 *     "task_link": "https://...",
 *     "text": "string",
 *     "is_vip": 0|1,
 *     "comment": "string" (اختیاری — اولین کامنت)
 *   }
 */
require_once dirname(__DIR__, 2) . '/_bootstrap.php';

requireMethod('POST');

$input = $body;

// --- اعتبارسنجی ---
$ticketNumber = trim((string)($input['ticket_number'] ?? ''));
if ($ticketNumber === '') apiError('شماره تیکت الزامی است.', 400, 'TICKET_NUMBER_REQUIRED');
if (mb_strlen($ticketNumber) > 50) apiError('شماره تیکت بسیار طولانی است.', 400, 'TICKET_NUMBER_TOO_LONG');

$subject = trim((string)($input['subject'] ?? ''));
if (mb_strlen($subject) > 255) $subject = mb_substr($subject, 0, 255);

$taskLink = trim((string)($input['task_link'] ?? ''));
if (mb_strlen($taskLink) > 500) $taskLink = mb_substr($taskLink, 0, 500);

$priority = (string)($input['priority'] ?? 'medium');
if (!in_array($priority, ['low', 'medium', 'high', 'very_high'], true)) $priority = 'medium';

$text = trim((string)($input['text'] ?? ''));
$isVip = !empty($input['is_vip']) ? 1 : 0;
$comment = trim((string)($input['comment'] ?? ''));

// --- درج ---
try {
    $db->beginTransaction();

    $stmt = $db->prepare("
        INSERT INTO tickets 
            (ticket_number, subject, task_link, priority, status, text, is_vip, created_at)
        VALUES (?, ?, ?, ?, 0, ?, ?, NOW())
    ");
    $stmt->execute([
        $ticketNumber,
        $subject !== '' ? $subject : null,
        $taskLink !== '' ? $taskLink : null,
        $priority,
        $text !== '' ? $text : null,
        $isVip,
    ]);
    $newId = (int)$db->lastInsertId();

    // اگه کامنت اولیه بود، اضافه کن
    if ($comment !== '') {
        $c = $db->prepare("INSERT INTO ticket_comments (ticket_id, comment, created_at) VALUES (?, ?, NOW())");
        $c->execute([$newId, $comment]);
    }

    $db->commit();

    apiSuccess([
        'id'            => $newId,
        'ticket_number' => $ticketNumber,
        'subject'       => $subject ?: null,
        'task_link'     => $taskLink ?: null,
        'priority'      => $priority,
        'text'          => $text ?: null,
        'is_vip'        => (bool)$isVip,
        'status'        => 0,
        'is_done'       => false,
        'created_at'    => date('Y-m-d H:i:s'),
    ], 'تیکت با موفقیت ایجاد شد.');
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    error_log('[API tickets/create] ' . $e->getMessage());
    apiError('خطا در ایجاد تیکت.', 500);
}