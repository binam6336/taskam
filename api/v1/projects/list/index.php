<?php
/**
 * GET /api/v1/projects/list/
 * لیست پروژه‌های کاربر (سازنده یا عضو)
 * 
 * Query params (اختیاری):
 *   ?q=search          جستجو در عنوان
 *   ?role=creator|member|all
 *   ?limit=100
 *   ?offset=0
 */
require_once dirname(__DIR__, 2) . '/_bootstrap.php';

requireMethod('GET');

$q      = trim((string)($_GET['q'] ?? ''));
$role   = $_GET['role'] ?? 'all';
$limit  = min(200, max(1, (int)($_GET['limit'] ?? 100)));
$offset = max(0, (int)($_GET['offset'] ?? 0));

if (!in_array($role, ['all', 'creator', 'member'], true)) $role = 'all';

$sql = "
    SELECT 
        p.id,
        p.user_id,
        p.title,
        p.description,
        p.max_members,
        p.profile_image,
        p.status,
        p.created_at,
        (p.user_id = ?) AS is_creator,
        (SELECT COUNT(*) FROM project_members pm WHERE pm.project_id = p.id) AS members_count,
        (SELECT COUNT(*) FROM project_blocks pb WHERE pb.project_id = p.id AND pb.user_id = ?) AS is_blocked_by_me,
        (SELECT permissions FROM project_members pm2 WHERE pm2.project_id = p.id AND pm2.user_id = ? LIMIT 1) AS my_permissions
    FROM projects p
    WHERE (
        p.user_id = ?
        OR EXISTS (
            SELECT 1 FROM project_members pm
            WHERE pm.project_id = p.id AND pm.user_id = ?
        )
    )
";

$params = [$userId, $userId, $userId, $userId, $userId];

if ($q !== '') {
    $sql .= " AND p.title LIKE ?";
    $params[] = '%' . $q . '%';
}

if ($role === 'creator') {
    $sql .= " AND p.user_id = ?";
    $params[] = $userId;
} elseif ($role === 'member') {
    $sql .= " AND p.user_id != ?";
    $params[] = $userId;
}

$sql .= " ORDER BY p.created_at DESC LIMIT " . (int)$limit . " OFFSET " . (int)$offset;

try {
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $ALL_PERMS = ['edit_project', 'add_member', 'remove_member', 'manage_permissions'];

    $projects = [];
    foreach ($rows as $p) {
        $isCreator = (int)$p['user_id'] === $userId;
        $myPerms = $isCreator ? $ALL_PERMS : (json_decode($p['my_permissions'] ?? '[]', true) ?: []);

        $avatarUrl = null;
        if (!empty($p['profile_image'])) {
            $imgBase = basename($p['profile_image']);
            if (preg_match('/^[A-Za-z0-9_.\-]+$/', $imgBase)
                && file_exists(dirname(__DIR__, 4) . '/uploads/projects/' . $imgBase)) {
                $avatarUrl = '/uploads/projects/' . rawurlencode($imgBase);
            }
        }

        $projects[] = [
            'id'              => (int)$p['id'],
            'title'           => $p['title'],
            'description'     => $p['description'],
            'max_members'     => (int)$p['max_members'],
            'profile_image'   => $avatarUrl,
            'status'          => (int)$p['status'],
            'is_creator'      => $isCreator,
            'is_blocked_by_me' => (int)$p['is_blocked_by_me'] > 0,
            'members_count'   => (int)$p['members_count'],
            'my_permissions'  => $myPerms,
            'created_at'      => $p['created_at'],
        ];
    }

    apiSuccess([
        'count'  => count($projects),
        'limit'  => $limit,
        'offset' => $offset,
        'query'  => $q,
        'role'   => $role,
        'projects' => $projects,
    ]);
} catch (Throwable $e) {
    error_log('[API projects/list] ' . $e->getMessage());
    apiError('خطا در واکشی پروژه‌ها.', 500);
}