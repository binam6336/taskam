<?php
/**
 * POST /api/v1/texts/create/
 * ایجاد متن آماده جدید
 * 
 * Body (JSON):
 *   {
 *     "title": "string (اجباری)",
 *     "content": "string (اجباری)",
 *     "sort_order": int
 *   }
 * 
 * نکته: id به‌صورت خودکار تولید می‌شود
 */
require_once dirname(__DIR__, 2) . '/_bootstrap.php';

requireMethod('POST');

$input = $body;

$title = trim((string)($input['title'] ?? ''));
if ($title === '') apiError('عنوان متن الزامی است.', 400, 'TITLE_REQUIRED');
if (mb_strlen($title) > 255) apiError('عنوان بسیار طولانی است.', 400, 'TITLE_TOO_LONG');

$content = trim((string)($input['content'] ?? ''));
if ($content === '') apiError('محتوای متن الزامی است.', 400, 'CONTENT_REQUIRED');

$sortOrder = (int)($input['sort_order'] ?? 0);

// تولید id یکتا (الگوی دیتابیس: هش_هش)
$newId = bin2hex(random_bytes(6)) . '_' . bin2hex(random_bytes(4));

try {
    $stmt = $db->prepare("
        INSERT INTO texts (id, user_id, title, content, sort_order, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, NOW(), NOW())
    ");
    $stmt->execute([$newId, $userId, $title, $content, $sortOrder]);

    apiSuccess([
        'id'         => $newId,
        'title'      => $title,
        'content'    => $content,
        'sort_order' => $sortOrder,
        'created_at' => date('Y-m-d H:i:s'),
    ], 'متن با موفقیت ایجاد شد.');
} catch (Throwable $e) {
    error_log('[API texts/create] ' . $e->getMessage());
    apiError('خطا در ایجاد متن.', 500);
}