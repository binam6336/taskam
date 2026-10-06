<?php
/**
 * GET /api/v1/colleagues/list/
 * لیست همکاران کاربر (کسانی که کاربر آن‌ها را به عنوان همکار اضافه کرده)
 * 
 * Query params (اختیاری):
 *   ?q=search          جستجو در نام/موبایل
 *   ?only_active=1     فقط همکاران بدون بلاک
 *   ?limit=100
 *   ?offset=0
 */
require_once dirname(__DIR__, 2) . '/_bootstrap.php';

requireMethod('GET');

$q          = trim((string)($_GET['q'] ?? ''));
$onlyActive = !empty($_GET['only_active']) ? 1 : 0;
$limit      = min(200, max(1, (int)($_GET['limit'] ?? 100)));
$offset     = max(0, (int)($_GET['offset'] ?? 0));

$sql = "
    SELECT
        c.id AS row_id,
        c.permissions,
        c.created_at AS added_at,
        u.id AS user_id,
        u.first_name,
        u.last_name,
        u.mobile,
        u.email,
        u.avatar,
        (SELECT COUNT(*) FROM colleague_blocks cb WHERE cb.blocker_user_id = ? AND cb.blocked_user_id = u.id) AS is_blocked_by_me,
        (SELECT COUNT(*) FROM colleague_blocks cb WHERE cb.blocker_user_id = u.id AND cb.blocked_user_id = ?) AS has_blocked_me
    FROM colleagues c
    INNER JOIN users u ON u.id = c.colleague_user_id
    WHERE c.user_id = ? AND u.status = 'active'
";

$params = [$userId, $userId, $userId];

if ($q !== '') {
    $sql .= " AND (u.first_name LIKE ? OR u.last_name LIKE ? OR u.mobile LIKE ?)";
    $params[] = '%' . $q . '%';
    $params[] = '%' . $q . '%';
    $params[] = '%' . $q . '%';
}

$sql .= " ORDER BY c.created_at DESC LIMIT " . (int)$limit . " OFFSET " . (int)$offset;

try {
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $colleagues = [];
    foreach ($rows as $c) {
        $perms = json_decode($c['permissions'] ?? '[]', true);
        if (!is_array($perms)) $perms = [];

        $fullName = trim(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? ''));
        if ($fullName === '') $fullName = $c['mobile'];

        $isBlockedByMe = (int)$c['is_blocked_by_me'] > 0;
        $hasBlockedMe = (int)$c['has_blocked_me'] > 0;

        if ($onlyActive && ($isBlockedByMe || $hasBlockedMe)) continue;

        $avatarUrl = null;
        if (!empty($c['avatar'])) {
            $avBase = basename($c['avatar']);
            if (preg_match('/^[A-Za-z0-9_.\-]+$/', $avBase)
                && file_exists(dirname(__DIR__, 4) . '/uploads/avatars/' . $avBase)) {
                $avatarUrl = '/uploads/avatars/' . rawurlencode($avBase);
            }
        }

        $colleagues[] = [
            'row_id'         => (int)$c['row_id'],
            'user_id'        => (int)$c['user_id'],
            'first_name'     => $c['first_name'],
            'last_name'      => $c['last_name'],
            'full_name'      => $fullName,
            'mobile'         => $c['mobile'],
            'email'          => $c['email'],
            'avatar_url'     => $avatarUrl,
            'permissions'    => $perms,
            'added_at'       => $c['added_at'],
            'is_blocked_by_me' => $isBlockedByMe,
            'has_blocked_me'   => $hasBlockedMe,
        ];
    }

    apiSuccess([
        'count'  => count($colleagues),
        'limit'  => $limit,
        'offset' => $offset,
        'query'  => $q,
        'colleagues' => $colleagues,
    ]);
} catch (Throwable $e) {
    error_log('[API colleagues/list] ' . $e->getMessage());
    apiError('خطا در واکشی همکاران.', 500);
}