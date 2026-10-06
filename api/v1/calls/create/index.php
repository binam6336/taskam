<?php
/**
 * POST /api/v1/calls/create/
 * ایجاد درخواست تماس جدید
 * 
 * Body (JSON):
 *   {
 *     "first_name": "string (اجباری)",
 *     "last_name":  "string (اجباری)",
 *     "mobile":     "string (اجباری)",
 *     "email":      "string",
 *     "store":      "string",
 *     "website":    "string",
 *     "assignee_id": int
 *   }
 */
require_once dirname(__DIR__, 2) . '/_bootstrap.php';

requireMethod('POST');

$input = $body;

// --- اعتبارسنجی ---
$firstName = trim((string)($input['first_name'] ?? ''));
if ($firstName === '') apiError('نام الزامی است.', 400, 'FIRST_NAME_REQUIRED');
if (mb_strlen($firstName) > 150) apiError('نام بسیار طولانی است.', 400, 'FIRST_NAME_TOO_LONG');

$lastName = trim((string)($input['last_name'] ?? ''));
if ($lastName === '') apiError('نام خانوادگی الزامی است.', 400, 'LAST_NAME_REQUIRED');
if (mb_strlen($lastName) > 150) apiError('نام خانوادگی بسیار طولانی است.', 400, 'LAST_NAME_TOO_LONG');

$mobile = trim((string)($input['mobile'] ?? ''));
if ($mobile === '') apiError('شماره موبایل الزامی است.', 400, 'MOBILE_REQUIRED');
if (!preg_match('/^09\d{9}$/', $mobile)) {
    apiError('فرمت شماره موبایل نامعتبر است. مثال: 09123456789', 400, 'MOBILE_INVALID');
}

$email = trim((string)($input['email'] ?? ''));
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    apiError('فرمت ایمیل نامعتبر است.', 400, 'EMAIL_INVALID');
}
if (mb_strlen($email) > 150) $email = mb_substr($email, 0, 150);

$store = trim((string)($input['store'] ?? ''));
if (mb_strlen($store) > 255) $store = mb_substr($store, 0, 255);

$website = trim((string)($input['website'] ?? ''));
if (mb_strlen($website) > 255) $website = mb_substr($website, 0, 255);

$assigneeId = !empty($input['assignee_id']) ? (int)$input['assignee_id'] : null;

// --- اعتبارسنجی assignee (باید همکار باشد) ---
if ($assigneeId !== null) {
    if ($assigneeId === $userId) {
        // خودش، مشکلی نیست
    } else {
        try {
            $chk = $db->prepare("SELECT 1 FROM colleagues WHERE user_id = ? AND colleague_user_id = ? LIMIT 1");
            $chk->execute([$userId, $assigneeId]);
            if (!$chk->fetchColumn()) {
                apiError('شما اجازه واگذاری به این کاربر را ندارید.', 403, 'ASSIGNEE_NOT_ALLOWED');
            }
        } catch (Throwable $e) {
            apiError('خطا در بررسی مسئول.', 500);
        }
    }
}

// --- درج ---
try {
    $stmt = $db->prepare("
        INSERT INTO call_requests 
            (user_id, assignee_id, first_name, last_name, mobile, email, store, website, status, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, NOW())
    ");
    $stmt->execute([
        $userId,
        $assigneeId,
        $firstName,
        $lastName,
        $mobile,
        $email !== '' ? $email : null,
        $store !== '' ? $store : null,
        $website !== '' ? $website : null,
    ]);
    $newId = (int)$db->lastInsertId();

    apiSuccess([
        'id'          => $newId,
        'first_name'  => $firstName,
        'last_name'   => $lastName,
        'mobile'      => $mobile,
        'email'       => $email ?: null,
        'store'       => $store ?: null,
        'website'     => $website ?: null,
        'assignee_id' => $assigneeId,
        'status'      => 0,
        'is_done'     => false,
        'created_at'  => date('Y-m-d H:i:s'),
    ], 'درخواست تماس با موفقیت ثبت شد.');
} catch (Throwable $e) {
    error_log('[API calls/create] ' . $e->getMessage());
    apiError('خطا در ایجاد درخواست.', 500);
}