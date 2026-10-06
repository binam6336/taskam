<?php
/**
 * GET /api/v1/texts/show/?id=XXX
 * جزئیات یک متن آماده
 * 
 * نکته: id در texts از نوع varchar است (نه int)
 */
require_once dirname(__DIR__, 2) . '/_bootstrap.php';

requireMethod('GET');

$id = trim((string)($_GET['id'] ?? ''));
if ($id === '') apiError('پارامتر id الزامی است.', 400, 'INVALID_ID');
if (strlen($id) > 50) apiError('شناسه بسیار طولانی است.', 400, 'INVALID_ID');

try {
    $stmt = $db->prepare("SELECT * FROM texts WHERE id = ? AND user_id = ? LIMIT 1");
    $stmt->execute([$id, $userId]);
    $text = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$text) apiError('متن یافت نشد.', 404, 'TEXT_NOT_FOUND');

    $text['sort_order'] = (int)$text['sort_order'];

    apiSuccess($text);
} catch (Throwable $e) {
    error_log('[API texts/show] ' . $e->getMessage());
    apiError('خطا در واکشی متن.', 500);
}