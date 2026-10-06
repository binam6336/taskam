<?php
/**
 * POST /api/v1/projects/create/
 * ایجاد پروژه جدید
 * 
 * Body (JSON):
 *   {
 *     "title": "string (اجباری)",
 *     "description": "string",
 *     "max_members": 10,
 *     "members": [5, 9, 10]
 *   }
 */
require_once dirname(__DIR__, 2) . '/_bootstrap.php';

requireMethod('POST');

$input = $body;

$title = trim((string)($input['title'] ?? ''));
if ($title === '') apiError('عنوان پروژه الزامی است.', 400, 'TITLE_REQUIRED');
if (mb_strlen($title) > 255) apiError('عنوان بسیار طولانی است.', 400, 'TITLE_TOO_LONG');

$description = trim((string)($input['description'] ?? ''));

$maxMembers = (int)($input['max_members'] ?? 10);
if ($maxMembers < 1 || $maxMembers > 100) {
    apiError('حداکثر اعضا باید بین ۱ تا ۱۰۰ باشد.', 400, 'INVALID_MAX_MEMBERS');
}

// اعتبارسنجی اعضا
$memberIds = [];
if (!empty($input['members']) && is_array($input['members'])) {
    foreach ($input['members'] as $mid) {
        $mid = (int)$mid;
        if ($mid > 0 && $mid !== $userId) $memberIds[] = $mid;
    }
    $memberIds = array_values(array_unique($memberIds));
}

// چک همکار بودن اعضا
if (!empty($memberIds)) {
    $validMembers = [];
    foreach ($memberIds as $mid) {
        try {
            $chk = $db->prepare("SELECT 1 FROM colleagues WHERE user_id = ? AND colleague_user_id = ? LIMIT 1");
            $chk->execute([$userId, $mid]);
            if ($chk->fetchColumn()) $validMembers[] = $mid;
        } catch (Throwable $e) {}
    }
    $memberIds = $validMembers;
}

$totalMembers = count($memberIds) + 1;
if ($totalMembers > $maxMembers) {
    apiError("تعداد اعضای انتخاب‌شده ({$totalMembers}) از حداکثر مجاز ({$maxMembers}) بیشتر است.", 400, 'TOO_MANY_MEMBERS');
}

try {
    $db->beginTransaction();

    // درج پروژه
    $stmt = $db->prepare("
        INSERT INTO projects (user_id, title, description, max_members, status, created_at)
        VALUES (?, ?, ?, ?, 1, NOW())
    ");
    $stmt->execute([$userId, $title, $description ?: null, $maxMembers]);
    $projectId = (int)$db->lastInsertId();

    $ALL_PERMS = ['edit_project', 'add_member', 'remove_member', 'manage_permissions'];
    $creatorPermsJson = json_encode($ALL_PERMS, JSON_UNESCAPED_UNICODE);

    // افزودن سازنده
    $mInsert = $db->prepare("INSERT INTO project_members (project_id, user_id, permissions, added_at) VALUES (?, ?, ?, NOW())");
    $mInsert->execute([$projectId, $userId, $creatorPermsJson]);

    // افزودن اعضا
    $emptyPerms = json_encode([], JSON_UNESCAPED_UNICODE);
    foreach ($memberIds as $mid) {
        $mInsert->execute([$projectId, $mid, $emptyPerms]);
    }

    $db->commit();

    apiSuccess([
        'id'            => $projectId,
        'title'         => $title,
        'description'   => $description ?: null,
        'max_members'   => $maxMembers,
        'members_count' => count($memberIds) + 1,
        'members'       => array_merge([$userId], $memberIds),
        'created_at'    => date('Y-m-d H:i:s'),
    ], 'پروژه با موفقیت ایجاد شد.');
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    error_log('[API projects/create] ' . $e->getMessage());
    apiError('خطا در ایجاد پروژه.', 500);
}