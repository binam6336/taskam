<?php
/**
 * GET /api/v1/projects/show/?id=N
 * جزئیات یک پروژه + لیست اعضا
 */
require_once dirname(__DIR__, 2) . '/_bootstrap.php';

requireMethod('GET');

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) apiError('پارامتر id الزامی است.', 400, 'INVALID_ID');

try {
    $stmt = $db->prepare("
        SELECT p.*, 
               (p.user_id = ?) AS is_creator
        FROM projects p
        WHERE p.id = ?
          AND (
              p.user_id = ?
              OR EXISTS (SELECT 1 FROM project_members pm WHERE pm.project_id = p.id AND pm.user_id = ?)
          )
        LIMIT 1
    ");
    $stmt->execute([$userId, $id, $userId, $userId]);
    $project = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$project) apiError('پروژه یافت نشد یا دسترسی ندارید.', 404, 'PROJECT_NOT_FOUND');

    $isCreator = (int)$project['user_id'] === $userId;

    // واکشی اعضا
    $m = $db->prepare("
        SELECT 
            pm.id AS member_row_id,
            pm.user_id,
            pm.permissions,
            pm.added_at,
            u.first_name,
            u.last_name,
            u.mobile,
            u.email,
            u.avatar,
            u.status AS user_status
        FROM project_members pm
        INNER JOIN users u ON u.id = pm.user_id
        WHERE pm.project_id = ?
        ORDER BY pm.added_at ASC
    ");
    $m->execute([$id]);
    $memberRows = $m->fetchAll(PDO::FETCH_ASSOC);

    $ALL_PERMS = ['edit_project', 'add_member', 'remove_member', 'manage_permissions'];

    $members = [];
    foreach ($memberRows as $mr) {
        $memberPerms = ((int)$mr['user_id'] === (int)$project['user_id'])
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
            'first_name'    => $mr['first_name'],
            'last_name'     => $mr['last_name'],
            'mobile'        => $mr['mobile'],
            'email'         => $mr['email'],
            'avatar_url'    => $avatarUrl,
            'permissions'   => $memberPerms,
            'is_creator'    => ((int)$mr['user_id'] === (int)$project['user_id']),
            'added_at'      => $mr['added_at'],
            'user_status'   => $mr['user_status'],
        ];
    }

    $myPerms = $isCreator
        ? $ALL_PERMS
        : (json_decode($myPerm ?: '[]', true) ?: []);

    // واکشی دسترسی خودم
    $myPermQuery = $db->prepare("SELECT permissions FROM project_members WHERE project_id = ? AND user_id = ? LIMIT 1");
    $myPermQuery->execute([$id, $userId]);
    $myPermRaw = $myPermQuery->fetchColumn();
    $myPerms = $isCreator ? $ALL_PERMS : (json_decode($myPermRaw ?: '[]', true) ?: []);

    $avatarUrl = null;
    if (!empty($project['profile_image'])) {
        $imgBase = basename($project['profile_image']);
        if (preg_match('/^[A-Za-z0-9_.\-]+$/', $imgBase)
            && file_exists(dirname(__DIR__, 4) . '/uploads/projects/' . $imgBase)) {
            $avatarUrl = '/uploads/projects/' . rawurlencode($imgBase);
        }
    }

    apiSuccess([
        'id'            => (int)$project['id'],
        'title'         => $project['title'],
        'description'   => $project['description'],
        'max_members'   => (int)$project['max_members'],
        'profile_image' => $avatarUrl,
        'status'        => (int)$project['status'],
        'is_creator'    => $isCreator,
        'my_permissions' => $myPerms,
        'members_count' => count($members),
        'created_at'    => $project['created_at'],
        'members'       => $members,
    ]);
} catch (Throwable $e) {
    error_log('[API projects/show] ' . $e->getMessage());
    apiError('خطا در واکشی پروژه.', 500);
}