<?php
/**
 * =============================================================
 * API Installer — Projects
 * =============================================================
 * نصب‌کننده API پروژه‌ها
 * 
 * نحوه استفاده:
 *   1. این فایل رو در پوشه /api/ بذار
 *   2. از مرورگر باز کن: https://your-domain.com/.../api/install-projects.php
 *   3. بعد از اتمام، این فایل رو حذف کن!
 * =============================================================
 */

$CONFIRM_DELETE = $_GET['done'] ?? false;

if ($CONFIRM_DELETE === 'yes') {
    @unlink(__FILE__);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Projects Installer</title>
    <style>body{font-family:Tahoma;padding:40px;background:#f0fdf4;text-align:center;}
    h1{color:#10b981;}a{color:#2563eb;text-decoration:none;font-weight:bold;}</style></head>
    <body><h1>✅ فایل نصب حذف شد</h1>
    <p>می‌توانید به <a href="v1/projects/list/">API Projects</a> بروید.</p></body></html>';
    exit;
}

$API_DIR = __DIR__;
$V1_DIR  = $API_DIR . '/v1';
$CREATED = [];
$ERRORS  = [];

function makeFile(string $path, string $content, array &$created, array &$errors): void {
    $dir = dirname($path);
    if (!is_dir($dir)) {
        if (!@mkdir($dir, 0755, true)) {
            $errors[] = "خطا در ساخت پوشه: $dir";
            return;
        }
    }
    if (@file_put_contents($path, $content) === false) {
        $errors[] = "خطا در نوشتن فایل: $path";
        return;
    }
    $created[] = str_replace($GLOBALS['API_DIR'], 'api/', $path);
}

// ==================== پوشه‌ها ====================
$dirs = [
    $V1_DIR . '/projects/list',
    $V1_DIR . '/projects/show',
    $V1_DIR . '/projects/stats',
    $V1_DIR . '/projects/members',
    $V1_DIR . '/projects/create',
    $V1_DIR . '/projects/update',
    $V1_DIR . '/projects/delete',
    $V1_DIR . '/projects/member-add',
    $V1_DIR . '/projects/member-remove',
    $V1_DIR . '/projects/member-permissions',
    $V1_DIR . '/projects/leave',
    $V1_DIR . '/projects/block',
];

foreach ($dirs as $d) {
    if (!is_dir($d)) @mkdir($d, 0755, true);
}

// ========================================================================
// ==================== PROJECTS — list ===================================
// ========================================================================
makeFile($V1_DIR . '/projects/list/index.php', <<<'PHP'
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
PHP, $CREATED, $ERRORS);

// ========================================================================
// ==================== PROJECTS — show ===================================
// ========================================================================
makeFile($V1_DIR . '/projects/show/index.php', <<<'PHP'
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
PHP, $CREATED, $ERRORS);

// ========================================================================
// ==================== PROJECTS — stats ==================================
// ========================================================================
makeFile($V1_DIR . '/projects/stats/index.php', <<<'PHP'
<?php
/**
 * GET /api/v1/projects/stats/
 * آمار پروژه‌های کاربر
 */
require_once dirname(__DIR__, 2) . '/_bootstrap.php';

requireMethod('GET');

try {
    $stmt = $db->prepare("
        SELECT 
            (SELECT COUNT(*) FROM projects WHERE user_id = ?) AS created_count,
            (SELECT COUNT(*) FROM project_members WHERE user_id = ?) AS total_memberships,
            (SELECT COUNT(DISTINCT p.id) FROM projects p
                WHERE p.user_id = ?
                   OR EXISTS (SELECT 1 FROM project_members pm WHERE pm.project_id = p.id AND pm.user_id = ?)) AS total_projects,
            (SELECT COUNT(*) FROM project_blocks WHERE user_id = ?) AS blocked_count
    ");
    $stmt->execute([$userId, $userId, $userId, $userId, $userId]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);

    apiSuccess([
        'created'         => (int)($r['created_count'] ?? 0),
        'total_projects'  => (int)($r['total_projects'] ?? 0),
        'total_members'   => (int)($r['total_memberships'] ?? 0),
        'blocked'         => (int)($r['blocked_count'] ?? 0),
    ]);
} catch (Throwable $e) {
    error_log('[API projects/stats] ' . $e->getMessage());
    apiError('خطا در محاسبه آمار.', 500);
}
PHP, $CREATED, $ERRORS);

// ========================================================================
// ==================== PROJECTS — members ================================
// ========================================================================
makeFile($V1_DIR . '/projects/members/index.php', <<<'PHP'
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
PHP, $CREATED, $ERRORS);

// ========================================================================
// ==================== PROJECTS — create =================================
// ========================================================================
makeFile($V1_DIR . '/projects/create/index.php', <<<'PHP'
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
PHP, $CREATED, $ERRORS);

// ========================================================================
// ==================== PROJECTS — update =================================
// ========================================================================
makeFile($V1_DIR . '/projects/update/index.php', <<<'PHP'
<?php
/**
 * POST /api/v1/projects/update/?id=N
 * ویرایش پروژه
 * 
 * Body (JSON):
 *   {
 *     "title": "string",
 *     "description": "string",
 *     "max_members": 15
 *   }
 */
require_once dirname(__DIR__, 2) . '/_bootstrap.php';

requireMethod('POST');

$input = $body;
$id = (int)($_GET['id'] ?? $input['id'] ?? 0);
if ($id <= 0) apiError('پارامتر id الزامی است.', 400, 'INVALID_ID');

// چک پروژه
try {
    $chk = $db->prepare("SELECT * FROM projects WHERE id = ? LIMIT 1");
    $chk->execute([$id]);
    $project = $chk->fetch(PDO::FETCH_ASSOC);
    if (!$project) apiError('پروژه یافت نشد.', 404, 'PROJECT_NOT_FOUND');
} catch (Throwable $e) {
    apiError('خطا در بررسی پروژه.', 500);
}

$isCreator = ((int)$project['user_id'] === $userId);

// چک دسترسی ویرایش
$canEdit = $isCreator;
if (!$canEdit) {
    try {
        $p = $db->prepare("SELECT permissions FROM project_members WHERE project_id = ? AND user_id = ? LIMIT 1");
        $p->execute([$id, $userId]);
        $perms = json_decode($p->fetchColumn() ?: '[]', true);
        if (is_array($perms) && in_array('edit_project', $perms, true)) $canEdit = true;
    } catch (Throwable $e) {}
}

if (!$canEdit) apiError('شما دسترسی ویرایش این پروژه را ندارید.', 403, 'FORBIDDEN');

// استخراج مقادیر جدید
$title = trim((string)($input['title'] ?? $project['title']));
if ($title === '') apiError('عنوان پروژه الزامی است.', 400, 'TITLE_REQUIRED');
if (mb_strlen($title) > 255) apiError('عنوان بسیار طولانی است.', 400, 'TITLE_TOO_LONG');

$description = trim((string)($input['description'] ?? $project['description'] ?? ''));

$maxMembers = array_key_exists('max_members', $input)
    ? (int)$input['max_members']
    : (int)$project['max_members'];

if ($maxMembers < 1 || $maxMembers > 100) {
    apiError('حداکثر اعضا باید بین ۱ تا ۱۰۰ باشد.', 400, 'INVALID_MAX_MEMBERS');
}

// چک تعداد فعلی اعضا
$countStmt = $db->prepare("SELECT COUNT(*) FROM project_members WHERE project_id = ?");
$countStmt->execute([$id]);
$currentCount = (int)$countStmt->fetchColumn();

if ($currentCount > $maxMembers) {
    apiError("تعداد اعضای فعلی ({$currentCount}) از حداکثر جدید ({$maxMembers}) بیشتر است.", 400, 'MAX_MEMBERS_TOO_SMALL');
}

try {
    $stmt = $db->prepare("
        UPDATE projects 
        SET title = ?, description = ?, max_members = ?
        WHERE id = ?
    ");
    $stmt->execute([$title, $description ?: null, $maxMembers, $id]);

    apiSuccess([
        'id'          => $id,
        'title'       => $title,
        'description' => $description ?: null,
        'max_members' => $maxMembers,
        'updated_at'  => date('Y-m-d H:i:s'),
    ], 'پروژه با موفقیت ویرایش شد.');
} catch (Throwable $e) {
    error_log('[API projects/update] ' . $e->getMessage());
    apiError('خطا در ویرایش پروژه.', 500);
}
PHP, $CREATED, $ERRORS);

// ========================================================================
// ==================== PROJECTS — delete =================================
// ========================================================================
makeFile($V1_DIR . '/projects/delete/index.php', <<<'PHP'
<?php
/**
 * POST /api/v1/projects/delete/?id=N
 * حذف پروژه (فقط سازنده)
 */
require_once dirname(__DIR__, 2) . '/_bootstrap.php';

requireMethod('POST');

$input = $body;
$id = (int)($_GET['id'] ?? $input['id'] ?? 0);
if ($id <= 0) apiError('پارامتر id الزامی است.', 400, 'INVALID_ID');

try {
    $chk = $db->prepare("SELECT user_id, profile_image FROM projects WHERE id = ? LIMIT 1");
    $chk->execute([$id]);
    $row = $chk->fetch(PDO::FETCH_ASSOC);

    if (!$row) apiError('پروژه یافت نشد.', 404, 'PROJECT_NOT_FOUND');
    if ((int)$row['user_id'] !== $userId) {
        apiError('فقط سازنده می‌تواند پروژه را حذف کند.', 403, 'FORBIDDEN');
    }

    // حذف تصویر
    if (!empty($row['profile_image'])) {
        $imgPath = dirname(__DIR__, 4) . '/uploads/projects/' . basename($row['profile_image']);
        if (file_exists($imgPath)) @unlink($imgPath);
    }

    $db->beginTransaction();
    $db->prepare("DELETE FROM project_members WHERE project_id = ?")->execute([$id]);
    $db->prepare("DELETE FROM projects WHERE id = ?")->execute([$id]);
    $db->commit();

    apiSuccess(['id' => $id], 'پروژه با موفقیت حذف شد.');
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    error_log('[API projects/delete] ' . $e->getMessage());
    apiError('خطا در حذف پروژه.', 500);
}
PHP, $CREATED, $ERRORS);

// ========================================================================
// ==================== PROJECTS — member-add =============================
// ========================================================================
makeFile($V1_DIR . '/projects/member-add/index.php', <<<'PHP'
<?php
/**
 * POST /api/v1/projects/member-add/?id=N
 * افزودن عضو به پروژه
 * 
 * Body (JSON):
 *   {
 *     "user_id": 5
 *   }
 */
require_once dirname(__DIR__, 2) . '/_bootstrap.php';

requireMethod('POST');

$input = $body;
$projectId = (int)($_GET['id'] ?? $input['project_id'] ?? 0);
$newUserId = (int)($input['user_id'] ?? 0);

if ($projectId <= 0) apiError('پارامتر id پروژه الزامی است.', 400, 'INVALID_ID');
if ($newUserId <= 0) apiError('پارامتر user_id الزامی است.', 400, 'INVALID_USER_ID');

try {
    $chk = $db->prepare("SELECT user_id, max_members FROM projects WHERE id = ? LIMIT 1");
    $chk->execute([$projectId]);
    $project = $chk->fetch(PDO::FETCH_ASSOC);
    if (!$project) apiError('پروژه یافت نشد.', 404, 'PROJECT_NOT_FOUND');

    $creatorId = (int)$project['user_id'];
    $isCreator = ($creatorId === $userId);

    // چک دسترسی
    $canAdd = $isCreator;
    if (!$canAdd) {
        $p = $db->prepare("SELECT permissions FROM project_members WHERE project_id = ? AND user_id = ? LIMIT 1");
        $p->execute([$projectId, $userId]);
        $perms = json_decode($p->fetchColumn() ?: '[]', true);
        if (is_array($perms) && in_array('add_member', $perms, true)) $canAdd = true;
    }

    if (!$canAdd) apiError('شما دسترسی افزودن کاربر ندارید.', 403, 'FORBIDDEN');

    // چک وجود کاربر
    $u = $db->prepare("SELECT id, status FROM users WHERE id = ? LIMIT 1");
    $u->execute([$newUserId]);
    $user = $u->fetch(PDO::FETCH_ASSOC);
    if (!$user) apiError('کاربر یافت نشد.', 404, 'USER_NOT_FOUND');
    if ($user['status'] !== 'active') apiError('کاربر فعال نیست.', 400, 'USER_NOT_ACTIVE');

    // چک همکار بودن
    $c = $db->prepare("SELECT 1 FROM colleagues WHERE user_id = ? AND colleague_user_id = ? LIMIT 1");
    $c->execute([$userId, $newUserId]);
    if (!$c->fetchColumn()) {
        apiError('این کاربر در لیست همکاران شما نیست.', 403, 'NOT_A_COLLEAGUE');
    }

    // چک تکراری
    $d = $db->prepare("SELECT 1 FROM project_members WHERE project_id = ? AND user_id = ? LIMIT 1");
    $d->execute([$projectId, $newUserId]);
    if ($d->fetchColumn()) apiError('این کاربر قبلاً عضو پروژه است.', 400, 'ALREADY_MEMBER');

    // چک ظرفیت
    $cnt = $db->prepare("SELECT COUNT(*) FROM project_members WHERE project_id = ?");
    $cnt->execute([$projectId]);
    $currentCount = (int)$cnt->fetchColumn();

    if ($currentCount >= (int)$project['max_members']) {
        apiError('ظرفیت پروژه تکمیل است.', 400, 'PROJECT_FULL');
    }

    // درج
    $emptyPerms = json_encode([], JSON_UNESCAPED_UNICODE);
    $stmt = $db->prepare("INSERT INTO project_members (project_id, user_id, permissions, added_at) VALUES (?, ?, ?, NOW())");
    $stmt->execute([$projectId, $newUserId, $emptyPerms]);

    apiSuccess([
        'project_id' => $projectId,
        'user_id'    => $newUserId,
        'added_at'   => date('Y-m-d H:i:s'),
    ], 'کاربر با موفقیت به پروژه اضافه شد.');
} catch (Throwable $e) {
    error_log('[API projects/member-add] ' . $e->getMessage());
    apiError('خطا در افزودن عضو.', 500);
}
PHP, $CREATED, $ERRORS);

// ========================================================================
// ==================== PROJECTS — member-remove ==========================
// ========================================================================
makeFile($V1_DIR . '/projects/member-remove/index.php', <<<'PHP'
<?php
/**
 * POST /api/v1/projects/member-remove/?id=N
 * حذف عضو از پروژه
 * 
 * Body (JSON):
 *   {
 *     "user_id": 5
 *   }
 */
require_once dirname(__DIR__, 2) . '/_bootstrap.php';

requireMethod('POST');

$input = $body;
$projectId = (int)($_GET['id'] ?? $input['project_id'] ?? 0);
$targetUserId = (int)($input['user_id'] ?? 0);

if ($projectId <= 0) apiError('پارامتر id پروژه الزامی است.', 400, 'INVALID_ID');
if ($targetUserId <= 0) apiError('پارامتر user_id الزامی است.', 400, 'INVALID_USER_ID');

try {
    $chk = $db->prepare("SELECT user_id FROM projects WHERE id = ? LIMIT 1");
    $chk->execute([$projectId]);
    $creatorId = (int)$chk->fetchColumn();
    if (!$creatorId) apiError('پروژه یافت نشد.', 404, 'PROJECT_NOT_FOUND');

    if ($targetUserId === $creatorId) {
        apiError('نمی‌توانید سازنده پروژه را حذف کنید.', 400, 'CANNOT_REMOVE_CREATOR');
    }

    $isCreator = ($creatorId === $userId);

    // چک دسترسی
    $canRemove = $isCreator;
    if (!$canRemove) {
        $p = $db->prepare("SELECT permissions FROM project_members WHERE project_id = ? AND user_id = ? LIMIT 1");
        $p->execute([$projectId, $userId]);
        $perms = json_decode($p->fetchColumn() ?: '[]', true);
        if (is_array($perms) && in_array('remove_member', $perms, true)) $canRemove = true;
    }

    if (!$canRemove) apiError('شما دسترسی حذف کاربر ندارید.', 403, 'FORBIDDEN');

    // چک عضویت
    $d = $db->prepare("SELECT id FROM project_members WHERE project_id = ? AND user_id = ? LIMIT 1");
    $d->execute([$projectId, $targetUserId]);
    if (!$d->fetchColumn()) apiError('این کاربر عضو پروژه نیست.', 404, 'NOT_A_MEMBER');

    // حذف
    $del = $db->prepare("DELETE FROM project_members WHERE project_id = ? AND user_id = ?");
    $del->execute([$projectId, $targetUserId]);

    apiSuccess([
        'project_id' => $projectId,
        'user_id'    => $targetUserId,
    ], 'کاربر از پروژه حذف شد.');
} catch (Throwable $e) {
    error_log('[API projects/member-remove] ' . $e->getMessage());
    apiError('خطا در حذف عضو.', 500);
}
PHP, $CREATED, $ERRORS);

// ========================================================================
// ==================== PROJECTS — member-permissions =====================
// ========================================================================
makeFile($V1_DIR . '/projects/member-permissions/index.php', <<<'PHP'
<?php
/**
 * POST /api/v1/projects/member-permissions/?id=N
 * ویرایش دسترسی‌های یک عضو پروژه
 * 
 * Body (JSON):
 *   {
 *     "user_id": 5,
 *     "permissions": ["edit_project", "add_member"]
 *   }
 * 
 * دسترسی‌های مجاز:
 *   - edit_project
 *   - add_member
 *   - remove_member
 *   - manage_permissions
 */
require_once dirname(__DIR__, 2) . '/_bootstrap.php';

requireMethod('POST');

$ALLOWED_PERMS = ['edit_project', 'add_member', 'remove_member', 'manage_permissions'];

$input = $body;
$projectId = (int)($_GET['id'] ?? $input['project_id'] ?? 0);
$targetUserId = (int)($input['user_id'] ?? 0);

if ($projectId <= 0) apiError('پارامتر id پروژه الزامی است.', 400, 'INVALID_ID');
if ($targetUserId <= 0) apiError('پارامتر user_id الزامی است.', 400, 'INVALID_USER_ID');

try {
    $chk = $db->prepare("SELECT user_id FROM projects WHERE id = ? LIMIT 1");
    $chk->execute([$projectId]);
    $creatorId = (int)$chk->fetchColumn();
    if (!$creatorId) apiError('پروژه یافت نشد.', 404, 'PROJECT_NOT_FOUND');

    if ($targetUserId === $creatorId) {
        apiError('مجوزهای سازنده پروژه قابل تغییر نیست.', 400, 'CANNOT_CHANGE_CREATOR_PERMS');
    }

    $isCreator = ($creatorId === $userId);

    // چک دسترسی
    $canManage = $isCreator;
    $grantable = $ALLOWED_PERMS;
    if (!$canManage) {
        $p = $db->prepare("SELECT permissions FROM project_members WHERE project_id = ? AND user_id = ? LIMIT 1");
        $p->execute([$projectId, $userId]);
        $myPerms = json_decode($p->fetchColumn() ?: '[]', true) ?: [];
        if (in_array('manage_permissions', $myPerms, true)) {
            $canManage = true;
            $grantable = array_values(array_intersect($myPerms, $ALLOWED_PERMS));
        }
    }

    if (!$canManage) apiError('شما دسترسی اعطای دسترسی ندارید.', 403, 'FORBIDDEN');

    // چک عضویت
    $d = $db->prepare("SELECT id FROM project_members WHERE project_id = ? AND user_id = ? LIMIT 1");
    $d->execute([$projectId, $targetUserId]);
    if (!$d->fetchColumn()) apiError('این کاربر عضو پروژه نیست.', 404, 'NOT_A_MEMBER');

    // اعتبارسنجی permissions
    $permsIn = $input['permissions'] ?? [];
    if (!is_array($permsIn)) $permsIn = [];

    $validPerms = [];
    foreach ($permsIn as $p) {
        $p = (string)$p;
        if (in_array($p, $grantable, true)) $validPerms[] = $p;
    }
    $validPerms = array_values(array_unique($validPerms));

    // اگر کاربر خودش رو ادیت می‌کنه، manage_permissions رو نگیره
    if ($targetUserId === $userId && !in_array('manage_permissions', $validPerms, true)) {
        if (in_array('manage_permissions', $grantable, true)) {
            $validPerms[] = 'manage_permissions';
        }
    }

    // به‌روزرسانی
    $json = json_encode($validPerms, JSON_UNESCAPED_UNICODE);
    $stmt = $db->prepare("UPDATE project_members SET permissions = ? WHERE project_id = ? AND user_id = ?");
    $stmt->execute([$json, $projectId, $targetUserId]);

    apiSuccess([
        'project_id'  => $projectId,
        'user_id'     => $targetUserId,
        'permissions' => $validPerms,
    ], 'دسترسی‌های عضو با موفقیت بروزرسانی شد.');
} catch (Throwable $e) {
    error_log('[API projects/member-permissions] ' . $e->getMessage());
    apiError('خطا در ویرایش دسترسی‌ها.', 500);
}
PHP, $CREATED, $ERRORS);

// ========================================================================
// ==================== PROJECTS — leave ==================================
// ========================================================================
makeFile($V1_DIR . '/projects/leave/index.php', <<<'PHP'
<?php
/**
 * POST /api/v1/projects/leave/?id=N
 * خروج از پروژه (خود کاربر)
 * 
 * نکته: سازنده نمی‌تواند از پروژه خودش خارج شود
 */
require_once dirname(__DIR__, 2) . '/_bootstrap.php';

requireMethod('POST');

$input = $body;
$id = (int)($_GET['id'] ?? $input['id'] ?? 0);
if ($id <= 0) apiError('پارامتر id الزامی است.', 400, 'INVALID_ID');

try {
    $chk = $db->prepare("SELECT user_id, title, profile_image FROM projects WHERE id = ? LIMIT 1");
    $chk->execute([$id]);
    $project = $chk->fetch(PDO::FETCH_ASSOC);
    if (!$project) apiError('پروژه یافت نشد.', 404, 'PROJECT_NOT_FOUND');

    if ((int)$project['user_id'] === $userId) {
        apiError('سازنده نمی‌تواند از پروژه خودش خارج شود.', 400, 'CREATOR_CANNOT_LEAVE');
    }

    // چک عضویت
    $d = $db->prepare("SELECT id FROM project_members WHERE project_id = ? AND user_id = ? LIMIT 1");
    $d->execute([$id, $userId]);
    if (!$d->fetchColumn()) apiError('شما عضو این پروژه نیستید.', 404, 'NOT_A_MEMBER');

    $db->beginTransaction();

    // ثبت تاریخچه
    $hist = $db->prepare("
        INSERT INTO project_leave_history (project_id, user_id, project_title, project_image, left_at)
        VALUES (?, ?, ?, ?, NOW())
    ");
    $hist->execute([$id, $userId, $project['title'], $project['profile_image']]);

    // حذف از اعضا
    $db->prepare("DELETE FROM project_members WHERE project_id = ? AND user_id = ?")->execute([$id, $userId]);

    $db->commit();

    apiSuccess([
        'project_id' => $id,
        'left_at'    => date('Y-m-d H:i:s'),
    ], 'با موفقیت از پروژه خارج شدید.');
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    error_log('[API projects/leave] ' . $e->getMessage());
    apiError('خطا در خروج از پروژه.', 500);
}
PHP, $CREATED, $ERRORS);

// ========================================================================
// ==================== PROJECTS — block ==================================
// ========================================================================
makeFile($V1_DIR . '/projects/block/index.php', <<<'PHP'
<?php
/**
 * POST /api/v1/projects/block/?id=N
 * مسدود کردن پروژه (پروژه از لیست کاربر مخفی می‌شه)
 */
require_once dirname(__DIR__, 2) . '/_bootstrap.php';

requireMethod('POST');

$input = $body;
$id = (int)($_GET['id'] ?? $input['id'] ?? 0);
if ($id <= 0) apiError('پارامتر id الزامی است.', 400, 'INVALID_ID');

try {
    // چک وجود پروژه
    $chk = $db->prepare("SELECT id FROM projects WHERE id = ? LIMIT 1");
    $chk->execute([$id]);
    if (!$chk->fetchColumn()) apiError('پروژه یافت نشد.', 404, 'PROJECT_NOT_FOUND');

    // چک تکراری
    $dup = $db->prepare("SELECT id FROM project_blocks WHERE project_id = ? AND user_id = ? LIMIT 1");
    $dup->execute([$id, $userId]);
    if ($dup->fetchColumn()) apiError('این پروژه قبلاً مسدود شده است.', 400, 'ALREADY_BLOCKED');

    $stmt = $db->prepare("INSERT INTO project_blocks (project_id, user_id, created_at) VALUES (?, ?, NOW())");
    $stmt->execute([$id, $userId]);

    apiSuccess([
        'project_id' => $id,
        'blocked_at' => date('Y-m-d H:i:s'),
    ], 'پروژه مسدود شد.');
} catch (Throwable $e) {
    error_log('[API projects/block] ' . $e->getMessage());
    apiError('خطا در مسدودسازی.', 500);
}
PHP, $CREATED, $ERRORS);

// ========================================================================
// ==================== به‌روزرسانی index.php =============================
// ========================================================================
$indexFile = $V1_DIR . '/index.php';

if (file_exists($indexFile)) {
    $current = file_get_contents($indexFile);
    $projectsBlock = <<<'PHPCODE'
        'projects' => [
            ['method' => 'GET',  'url' => '/api/v1/projects/list/',                             'desc' => 'لیست پروژه‌ها'],
            ['method' => 'GET',  'url' => '/api/v1/projects/show/?id=N',                        'desc' => 'جزئیات پروژه'],
            ['method' => 'GET',  'url' => '/api/v1/projects/stats/',                            'desc' => 'آمار پروژه‌ها'],
            ['method' => 'GET',  'url' => '/api/v1/projects/members/?id=N',                     'desc' => 'لیست اعضا'],
            ['method' => 'POST', 'url' => '/api/v1/projects/create/',                           'desc' => 'ایجاد پروژه'],
            ['method' => 'POST', 'url' => '/api/v1/projects/update/?id=N',                      'desc' => 'ویرایش پروژه'],
            ['method' => 'POST', 'url' => '/api/v1/projects/delete/?id=N',                      'desc' => 'حذف پروژه'],
            ['method' => 'POST', 'url' => '/api/v1/projects/member-add/?id=N',                  'desc' => 'افزودن عضو'],
            ['method' => 'POST', 'url' => '/api/v1/projects/member-remove/?id=N',               'desc' => 'حذف عضو'],
            ['method' => 'POST', 'url' => '/api/v1/projects/member-permissions/?id=N',          'desc' => 'ویرایش دسترسی عضو'],
            ['method' => 'POST', 'url' => '/api/v1/projects/leave/?id=N',                       'desc' => 'خروج از پروژه'],
            ['method' => 'POST', 'url' => '/api/v1/projects/block/?id=N',                       'desc' => 'مسدود کردن پروژه'],
        ],
PHPCODE;

    // اضافه کردن قبل از ] نهایی endpoints
    $current = preg_replace(
        "/(\s*)\],\s*\n\s*'auth_method'/",
        "\n" . $projectsBlock . "\n$1],\n        'auth_method'",
        $current,
        1
    );

    if (@file_put_contents($indexFile, $current) !== false) {
        $CREATED[] = 'api/v1/index.php (به‌روزرسانی)';
    } else {
        $ERRORS[] = 'خطا در به‌روزرسانی index.php';
    }
} else {
    $ERRORS[] = 'فایل index.php یافت نشد — ابتدا install.php اصلی رو اجرا کن.';
}

?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>Projects API Installer</title>
    <style>
        body { font-family: Tahoma, sans-serif; background: #f8fafc; padding: 30px; color: #1e293b; }
        .container { max-width: 900px; margin: 0 auto; background: #fff; border-radius: 16px; padding: 30px; box-shadow: 0 4px 20px rgba(0,0,0,0.06); }
        h1 { color: #1e293b; margin: 0 0 10px; font-size: 1.5rem; }
        h2 { color: #10b981; margin: 24px 0 12px; font-size: 1.1rem; display: flex; align-items: center; gap: 8px; }
        h2.error { color: #dc2626; }
        p.intro { color: #64748b; line-height: 1.8; margin-bottom: 20px; }
        ul { list-style: none; padding: 0; }
        li { padding: 8px 12px; background: #f1f5f9; border-radius: 8px; margin-bottom: 6px; font-family: 'Courier New', monospace; font-size: 0.85rem; direction: ltr; text-align: left; }
        li.error { background: #fee2e2; color: #991b1b; }
        .actions { margin-top: 30px; padding-top: 20px; border-top: 1px solid #e2e8f0; display: flex; gap: 10px; flex-wrap: wrap; }
        .btn { display: inline-flex; align-items: center; gap: 6px; padding: 10px 20px; border-radius: 10px; text-decoration: none; font-weight: bold; font-size: 0.9rem; }
        .btn-primary { background: #2563eb; color: #fff; }
        .btn-danger { background: #dc2626; color: #fff; }
        .btn-ghost { background: #f1f5f9; color: #475569; }
        .btn:hover { opacity: 0.9; }
    </style>
</head>
<body>
<div class="container">
    <h1>📦 Projects API Installer</h1>
    <p class="intro">
        نصب خودکار فایل‌های API برای بخش <strong>Projects (پروژه‌ها)</strong>.
    </p>

    <?php if (!empty($CREATED)): ?>
        <h2>✅ فایل‌های ساخته‌شده (<?= count($CREATED) ?>)</h2>
        <ul>
            <?php foreach ($CREATED as $f): ?>
                <li><?= htmlspecialchars($f) ?></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <?php if (!empty($ERRORS)): ?>
        <h2 class="error">❌ خطاها (<?= count($ERRORS) ?>)</h2>
        <ul>
            <?php foreach ($ERRORS as $e): ?>
                <li class="error"><?= htmlspecialchars($e) ?></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <div class="actions">
        <a href="v1/projects/list/" class="btn btn-primary">📁 تست لیست پروژه‌ها</a>
        <a href="v1/projects/stats/" class="btn btn-primary">📊 آمار</a>
        <a href="v1/" class="btn btn-ghost">📋 اطلاعات API</a>
        <a href="?done=yes" class="btn btn-danger" onclick="return confirm('فایل نصب حذف شود؟');">🗑 حذف فایل نصب</a>
    </div>
</div>
</body>
</html>