<?php
/**
 * POST /api/v1/colleagues/permissions/
 * ویرایش دسترسی‌های همکار
 * 
 * پارامترها:
 *   ?row_id=N         شناسه ردیف در colleagues
 *   یا
 *   ?user_id=N        شناسه کاربری همکار
 * 
 * Body (JSON):
 *   {
 *     "permissions": ["edit", "notes", "change_project"]
 *   }
 * 
 * لیست دسترسی‌های مجاز:
 *   - edit            ویرایش تسک
 *   - delete          حذف تسک
 *   - complete        تکمیل تسک
 *   - reassign        تغییر مسئول
 *   - notes           افزودن/حذف یادداشت
 *   - change_project  تغییر پروژه تسک
 */
require_once dirname(__DIR__, 2) . '/_bootstrap.php';

requireMethod('POST');

$ALLOWED_PERMISSIONS = ['edit', 'delete', 'complete', 'reassign', 'notes', 'change_project'];

$input = $body;

$rowId           = (int)($_GET['row_id'] ?? $input['row_id'] ?? 0);
$colleagueUserId = (int)($_GET['user_id'] ?? $input['user_id'] ?? 0);

if ($rowId <= 0 && $colleagueUserId <= 0) {
    apiError('پارامتر row_id یا user_id الزامی است.', 400, 'INVALID_ID');
}

// پیدا کردن ردیف
try {
    if ($rowId > 0) {
        $stmt = $db->prepare("SELECT id, colleague_user_id FROM colleagues WHERE id = ? AND user_id = ? LIMIT 1");
        $stmt->execute([$rowId, $userId]);
    } else {
        $stmt = $db->prepare("SELECT id, colleague_user_id FROM colleagues WHERE user_id = ? AND colleague_user_id = ? LIMIT 1");
        $stmt->execute([$userId, $colleagueUserId]);
    }
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) apiError('همکار یافت نشد.', 404, 'COLLEAGUE_NOT_FOUND');
} catch (Throwable $e) {
    apiError('خطا در بررسی همکار.', 500);
}

// اعتبارسنجی permissions
$permsIn = $input['permissions'] ?? [];
if (!is_array($permsIn)) $permsIn = [];

$validPerms = [];
foreach ($permsIn as $p) {
    $p = (string)$p;
    if (in_array($p, $ALLOWED_PERMISSIONS, true)) $validPerms[] = $p;
}
$validPerms = array_values(array_unique($validPerms));

try {
    $json = json_encode($validPerms, JSON_UNESCAPED_UNICODE);
    $update = $db->prepare("UPDATE colleagues SET permissions = ? WHERE id = ? AND user_id = ?");
    $update->execute([$json, $row['id'], $userId]);

    apiSuccess([
        'row_id'      => (int)$row['id'],
        'user_id'     => (int)$row['colleague_user_id'],
        'permissions' => $validPerms,
        'updated_at'  => date('Y-m-d H:i:s'),
    ], 'دسترسی‌های همکار با موفقیت بروزرسانی شد.');
} catch (Throwable $e) {
    error_log('[API colleagues/permissions] ' . $e->getMessage());
    apiError('خطا در بروزرسانی دسترسی‌ها.', 500);
}