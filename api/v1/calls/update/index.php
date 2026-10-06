<?php
/**
 * POST /api/v1/calls/update/?id=N
 * ویرایش درخواست تماس
 * 
 * Body (JSON): فقط فیلدهایی که می‌خوای عوض کنی
 */
require_once dirname(__DIR__, 2) . '/_bootstrap.php';

requireMethod('POST');

$input = $body;
$id = (int)($_GET['id'] ?? $input['id'] ?? 0);
if ($id <= 0) apiError('پارامتر id الزامی است.', 400, 'INVALID_ID');

// --- بررسی دسترسی ---
try {
    $chk = $db->prepare("SELECT * FROM call_requests WHERE id = ? AND (user_id = ? OR assignee_id = ?) LIMIT 1");
    $chk->execute([$id, $userId, $userId]);
    $call = $chk->fetch(PDO::FETCH_ASSOC);
    if (!$call) apiError('درخواست یافت نشد.', 404, 'CALL_NOT_FOUND');
} catch (Throwable $e) {
    apiError('خطا در بررسی درخواست.', 500);
}

$isOwner = ((int)$call['user_id'] === $userId);

// --- استخراج مقادیر جدید ---
$firstName = trim((string)($input['first_name'] ?? $call['first_name']));
if ($firstName === '') apiError('نام الزامی است.', 400, 'FIRST_NAME_REQUIRED');
if (mb_strlen($firstName) > 150) apiError('نام بسیار طولانی است.', 400);

$lastName = trim((string)($input['last_name'] ?? $call['last_name']));
if ($lastName === '') apiError('نام خانوادگی الزامی است.', 400, 'LAST_NAME_REQUIRED');
if (mb_strlen($lastName) > 150) apiError('نام خانوادگی بسیار طولانی است.', 400);

$mobile = trim((string)($input['mobile'] ?? $call['mobile']));
if ($mobile === '') apiError('موبایل الزامی است.', 400, 'MOBILE_REQUIRED');
if (!preg_match('/^09\d{9}$/', $mobile)) apiError('فرمت موبایل نامعتبر است.', 400, 'MOBILE_INVALID');

$email = trim((string)($input['email'] ?? $call['email'] ?? ''));
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    apiError('فرمت ایمیل نامعتبر است.', 400, 'EMAIL_INVALID');
}

$store = trim((string)($input['store'] ?? $call['store'] ?? ''));
if (mb_strlen($store) > 255) $store = mb_substr($store, 0, 255);

$website = trim((string)($input['website'] ?? $call['website'] ?? ''));
if (mb_strlen($website) > 255) $website = mb_substr($website, 0, 255);

// assignee فقط توسط owner قابل تغییره
$assigneeId = $call['assignee_id'] !== null ? (int)$call['assignee_id'] : null;
if ($isOwner && array_key_exists('assignee_id', $input)) {
    $newAssignee = !empty($input['assignee_id']) ? (int)$input['assignee_id'] : null;
    if ($newAssignee !== null && $newAssignee !== $userId) {
        try {
            $c = $db->prepare("SELECT 1 FROM colleagues WHERE user_id = ? AND colleague_user_id = ? LIMIT 1");
            $c->execute([$userId, $newAssignee]);
            if (!$c->fetchColumn()) apiError('شما اجازه واگذاری به این کاربر را ندارید.', 403, 'ASSIGNEE_NOT_ALLOWED');
        } catch (Throwable $e) {
            apiError('خطا در بررسی مسئول.', 500);
        }
    }
    $assigneeId = $newAssignee;
}

try {
    $stmt = $db->prepare("
        UPDATE call_requests 
        SET first_name = ?, last_name = ?, mobile = ?, email = ?, store = ?, website = ?, assignee_id = ?
        WHERE id = ?
    ");
    $stmt->execute([
        $firstName,
        $lastName,
        $mobile,
        $email !== '' ? $email : null,
        $store !== '' ? $store : null,
        $website !== '' ? $website : null,
        $assigneeId,
        $id,
    ]);

    apiSuccess([
        'id'          => $id,
        'first_name'  => $firstName,
        'last_name'   => $lastName,
        'mobile'      => $mobile,
        'email'       => $email ?: null,
        'store'       => $store ?: null,
        'website'     => $website ?: null,
        'assignee_id' => $assigneeId,
    ], 'درخواست با موفقیت ویرایش شد.');
} catch (Throwable $e) {
    error_log('[API calls/update] ' . $e->getMessage());
    apiError('خطا در ویرایش درخواست.', 500);
}