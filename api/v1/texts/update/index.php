<?php
/**
 * POST /api/v1/texts/update/?id=XXX
 * ویرایش متن آماده
 */
require_once dirname(__DIR__, 2) . '/_bootstrap.php';

requireMethod('POST');

$input = $body;
$id = trim((string)($_GET['id'] ?? $input['id'] ?? ''));
if ($id === '') apiError('پارامتر id الزامی است.', 400, 'INVALID_ID');
if (strlen($id) > 50) apiError('شناسه بسیار طولانی است.', 400, 'INVALID_ID');

try {
    $chk = $db->prepare("SELECT * FROM texts WHERE id = ? AND user_id = ? LIMIT 1");
    $chk->execute([$id, $userId]);
    $text = $chk->fetch(PDO::FETCH_ASSOC);
    if (!$text) apiError('متن یافت نشد.', 404, 'TEXT_NOT_FOUND');
} catch (Throwable $e) {
    apiError('خطا در بررسی متن.', 500);
}

$title = trim((string)($input['title'] ?? $text['title']));
if ($title === '') apiError('عنوان متن الزامی است.', 400, 'TITLE_REQUIRED');
if (mb_strlen($title) > 255) apiError('عنوان بسیار طولانی است.', 400, 'TITLE_TOO_LONG');

$content = trim((string)($input['content'] ?? $text['content']));
if ($content === '') apiError('محتوای متن الزامی است.', 400, 'CONTENT_REQUIRED');

$sortOrder = array_key_exists('sort_order', $input)
    ? (int)$input['sort_order']
    : (int)$text['sort_order'];

try {
    $stmt = $db->prepare("
        UPDATE texts 
        SET title = ?, content = ?, sort_order = ?, updated_at = NOW()
        WHERE id = ? AND user_id = ?
    ");
    $stmt->execute([$title, $content, $sortOrder, $id, $userId]);

    apiSuccess([
        'id'         => $id,
        'title'      => $title,
        'content'    => $content,
        'sort_order' => $sortOrder,
        'updated_at' => date('Y-m-d H:i:s'),
    ], 'متن با موفقیت ویرایش شد.');
} catch (Throwable $e) {
    error_log('[API texts/update] ' . $e->getMessage());
    apiError('خطا در ویرایش متن.', 500);
}