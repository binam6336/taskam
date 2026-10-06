<?php
/**
 * GET /api/v1/projects/members/?id=N
 * لیست اعضای یک پروژه
 */
require_once dirname(__DIR__, 2) . '/_bootstrap.php';

requireMethod('GET');

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) apiError('پارامتر id الزامی است.', 400, 'INVALID_ID');

// چک دسترسی
try {
    $chk = $db->prepare("
        SELECT p.user_id FROM projects p
        WHERE p.id = ?
          AND (p.user_id = ? OR EXISTS (SELECT 1 FROM project_members pm WHERE pm.project_id = p.id AND pm.user_id = ?))
        LIMIT 1
    ");
    $chk->execute([$id, $userId, $userId]);
    $creatorId = (int)$chk->fetchColumn();

    if (!$creatorId) apiError('پروژه یافت نشد یا دسترسی ندارید.', 404, 'PROJECT_NOT_FOUND');
} catch (Throwable $e) {
    apiError('خطا در بررسی دسترسی.', 500);
}

$limit  = min(200, max(1, (int)($_GET['limit'] ?? 100)));
$offset = max(0, (int)($_GET['offset'] ?? 0));

try {
    $stmt = $db->prepare("
        SELECT 
            pm.id AS member_row_id,
            pm.user_id,
            pm.permissions,
            pm.added_at,
            u.first_name,
            u.last_name,
            u.mobile,
            u.email,
            u.avatar
        FROM project_members pm
        INNER JOIN users u ON u.id = pm.user_id
        WHERE pm.project_id = ?
        ORDER BY pm.added_at ASC
        LIMIT " . (int)$limit . " OFFSET " . (int)$offset
    );
    $stmt->execute([$id]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $ALL_PERMS = ['edit_project', 'add_member', 'remove_member', 'manage_permissions'];

    $members = [];
    foreach ($rows as $mr) {
        $isCreator = ((int)$mr['user_id'] === $creatorId);
        $memberPerms = $isCreator
            ? $ALL_PERMS
            : (json_decode($mr['permissions'] ?? '[]', true) ?: []);

        $fullName = trim(($mr['first_name'] ?? '') . ' ' . ($mr['last_name'] ?? ''));
        if ($fullName === '') $fullName = $mr['mobile'];

        $avatarUrl = null;
        if (!empty($mr['avatar'])) {
            $avBase = basename($mr['avatar']);
            if (preg_match('/^[A-Za-z0-9_.\-]+$/', $avBase)
                && file_exists(dirname(__DIR__, 4) . '/uploads/avatars/' . $avBase)) {
                $avatarUrl = '/uploads/avatars/' . rawurlencode($avBase);
            }
        }

        $members[] = [
            'member_row_id' => (int)$mr['member_row_id'],
            'user_id'       => (int)$mr['user_id'],
            'full_name'     => $fullName,
            'mobile'        => $mr['mobile'],
            'email'         => $mr['email'],
            'avatar_url'    => $avatarUrl,
            'permissions'   => $memberPerms,
            'is_creator'    => $isCreator,
            'added_at'      => $mr['added_at'],
        ];
    }

    apiSuccess([
        'project_id' => $id,
        'count'      => count($members),
        'members'    => $members,
    ]);
} catch (Throwable $e) {
    error_log('[API projects/members] ' . $e->getMessage());
    apiError('خطا در واکشی اعضا.', 500);
}