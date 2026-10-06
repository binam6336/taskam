<?php
ob_start();

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../database/Database.php';
require_once __DIR__ . '/../includes/impersonate_banner.php';
require_once __DIR__ . '/../includes/sidebar.php';
require_once __DIR__ . '/../includes/quick_access.php';
require_once __DIR__ . '/../includes/chat_notifications.php';


if (!Auth::check()) {
    header('Location: ../auth/login.php');
    exit;
}

$db = Database::getInstance();

try {
    $db->exec("SET NAMES 'utf8mb4'");
} catch (PDOException $e) {}

$userId = (int)$_SESSION['user_id'];
$page = 'projects';

// ================== تعریف مجوزهای پروژه ==================
$PROJECT_PERMISSIONS = [
    'edit_project' => [
        'label' => 'ویرایش پروژه',
        'desc'  => 'ویرایش نام، توضیحات، تصویر پروفایل و تنظیمات کلی پروژه',
        'icon'  => 'fa-pen',
    ],
    'add_member' => [
        'label' => 'افزودن کاربر',
        'desc'  => 'افزودن همکاران جدید به پروژه',
        'icon'  => 'fa-user-plus',
    ],
    'remove_member' => [
        'label' => 'حذف کاربر',
        'desc'  => 'حذف اعضای فعلی از پروژه',
        'icon'  => 'fa-user-minus',
    ],
    'manage_permissions' => [
        'label' => 'اعطای دسترسی',
        'desc'  => 'امکان تغییر دسترسی‌های سایر اعضای پروژه (فقط می‌توانید مجوزهایی را بدهید که خودتان دارید)',
        'icon'  => 'fa-key',
    ],
    // ✅ جدید: مجوز مشاهده تحلیل‌ها
    'view_analytics' => [
        'label' => 'مشاهده تحلیل‌ها',
        'desc'  => 'دسترسی به صفحه تحلیل‌ها، نمودارها و آمار کامل پروژه',
        'icon'  => 'fa-chart-line',
    ],
];

$ALL_PROJECT_PERMISSION_KEYS = array_keys($PROJECT_PERMISSIONS);

// ================== اطمینان از وجود جدول‌ها (یک‌بار در session) ==================
if (empty($_SESSION['projects_tables_checked'])) {
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS projects (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            title VARCHAR(255) NOT NULL,
            description TEXT DEFAULT NULL,
            max_members INT NOT NULL DEFAULT 10,
            profile_image VARCHAR(255) DEFAULT NULL,
            status TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY user_id (user_id),
            KEY created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;");

        $db->exec("CREATE TABLE IF NOT EXISTS project_members (
            id INT AUTO_INCREMENT PRIMARY KEY,
            project_id INT NOT NULL,
            user_id INT NOT NULL,
            permissions TEXT DEFAULT NULL,
            added_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_member (project_id, user_id),
            KEY project_id (project_id),
            KEY user_id (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;");

        $db->exec("CREATE TABLE IF NOT EXISTS project_blocks (
            id INT AUTO_INCREMENT PRIMARY KEY,
            project_id INT NOT NULL,
            user_id INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_project_block (project_id, user_id),
            KEY project_id (project_id),
            KEY user_id (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;");

        $db->exec("CREATE TABLE IF NOT EXISTS project_leave_history (
            id INT AUTO_INCREMENT PRIMARY KEY,
            project_id INT NOT NULL,
            user_id INT NOT NULL,
            project_title VARCHAR(255) DEFAULT NULL,
            project_image VARCHAR(255) DEFAULT NULL,
            left_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY project_id (project_id),
            KEY user_id (user_id),
            KEY left_at (left_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;");

        $db->exec("CREATE TABLE IF NOT EXISTS project_join_requests (
            id INT AUTO_INCREMENT PRIMARY KEY,
            project_id INT NOT NULL,
            user_id INT NOT NULL,
            invited_by INT NOT NULL,
            status ENUM('pending','accepted','rejected') NOT NULL DEFAULT 'pending',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            resolved_at TIMESTAMP NULL DEFAULT NULL,
            UNIQUE KEY unique_pending_request (project_id, user_id, status),
            KEY project_id (project_id),
            KEY user_id (user_id),
            KEY status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;");

        $colCheck = $db->query("SHOW COLUMNS FROM project_members LIKE 'permissions'");
        if ($colCheck && $colCheck->rowCount() === 0) {
            $db->exec("ALTER TABLE project_members ADD COLUMN permissions TEXT DEFAULT NULL AFTER user_id");
        }

        $taskColCheck = $db->query("SHOW COLUMNS FROM tasks LIKE 'project_id'");
        if ($taskColCheck && $taskColCheck->rowCount() === 0) {
            $db->exec("ALTER TABLE tasks ADD COLUMN project_id INT DEFAULT NULL AFTER subject_id");
            $db->exec("ALTER TABLE tasks ADD INDEX idx_tasks_project_id (project_id)");
        }

        $userColCheck = $db->query("SHOW COLUMNS FROM users LIKE 'project_join_requires_approval'");
        if ($userColCheck && $userColCheck->rowCount() === 0) {
            $db->exec("ALTER TABLE users ADD COLUMN project_join_requires_approval TINYINT(1) NOT NULL DEFAULT 0 AFTER allow_colleague_requests");
        }

        $_SESSION['projects_tables_checked'] = 1;
    } catch (PDOException $e) {}
}

// ================== توابع کمکی ==================
function getMemberPermissions(PDO $db, int $projectId, int $memberId): array {
    global $ALL_PROJECT_PERMISSION_KEYS;
    try {
        $stmt = $db->prepare("SELECT permissions FROM project_members WHERE project_id = ? AND user_id = ? LIMIT 1");
        $stmt->execute([$projectId, $memberId]);
        $raw = $stmt->fetchColumn();
        if (!$raw) return [];
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) return [];
        return array_values(array_intersect($decoded, $ALL_PROJECT_PERMISSION_KEYS));
    } catch (PDOException $e) {
        return [];
    }
}

function hasProjectPermission(PDO $db, int $projectId, int $userId, string $permission): bool {
    global $ALL_PROJECT_PERMISSION_KEYS;
    if (!in_array($permission, $ALL_PROJECT_PERMISSION_KEYS, true)) return false;

    try {
        $stmt = $db->prepare("SELECT user_id FROM projects WHERE id = ? LIMIT 1");
        $stmt->execute([$projectId]);
        $creatorId = (int)$stmt->fetchColumn();

        if ($creatorId === $userId) return true;

        $perms = getMemberPermissions($db, $projectId, $userId);
        return in_array($permission, $perms, true);
    } catch (PDOException $e) {
        return false;
    }
}

function saveMemberPermissions(PDO $db, int $projectId, int $memberId, array $permissions): bool {
    global $ALL_PROJECT_PERMISSION_KEYS;
    $clean = array_values(array_intersect(array_unique($permissions), $ALL_PROJECT_PERMISSION_KEYS));
    $json  = json_encode($clean, JSON_UNESCAPED_UNICODE);
    try {
        $stmt = $db->prepare("UPDATE project_members SET permissions = ? WHERE project_id = ? AND user_id = ?");
        $stmt->execute([$json, $projectId, $memberId]);
        return $stmt->rowCount() > 0;
    } catch (PDOException $e) {
        return false;
    }
}

function getAllowedGrantablePermissions(PDO $db, int $projectId, int $userId): array {
    global $ALL_PROJECT_PERMISSION_KEYS;
    try {
        $stmt = $db->prepare("SELECT user_id FROM projects WHERE id = ? LIMIT 1");
        $stmt->execute([$projectId]);
        $creatorId = (int)$stmt->fetchColumn();

        if ($creatorId === $userId) return $ALL_PROJECT_PERMISSION_KEYS;

        $myPerms = getMemberPermissions($db, $projectId, $userId);
        if (!in_array('manage_permissions', $myPerms, true)) return [];

        return array_values(array_intersect($myPerms, $ALL_PROJECT_PERMISSION_KEYS));
    } catch (PDOException $e) {
        return [];
    }
}

function isProjectBlockedByUser(PDO $db, int $projectId, int $userId): bool {
    try {
        $stmt = $db->prepare("SELECT 1 FROM project_blocks WHERE project_id = ? AND user_id = ? LIMIT 1");
        $stmt->execute([$projectId, $userId]);
        return (bool)$stmt->fetchColumn();
    } catch (PDOException $e) {
        return false;
    }
}

function userNeedsProjectApproval(PDO $db, int $userId): bool {
    try {
        $stmt = $db->prepare("SELECT project_join_requires_approval FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$userId]);
        $val = $stmt->fetchColumn();
        return ((int)$val === 1);
    } catch (PDOException $e) {
        return false;
    }
}

// ================== شمارش پیام‌های خوانده‌نشده ==================
$sidebarUnread = 0;
try {
    $stmt = $db->prepare("SELECT COUNT(*) FROM colleague_messages WHERE receiver_id = ? AND is_read = 0");
    $stmt->execute([$userId]);
    $sidebarUnread = (int)$stmt->fetchColumn();
} catch (PDOException $e) {}

// ================== مسیر آپلود ==================
$PROJECT_UPLOAD_DIR = __DIR__ . '/../uploads/projects/';
if (!is_dir($PROJECT_UPLOAD_DIR)) {
    @mkdir($PROJECT_UPLOAD_DIR, 0755, true);
}

$makeProjectImageUrl = function($fileName) use ($PROJECT_UPLOAD_DIR) {
    if (!empty($fileName) && file_exists($PROJECT_UPLOAD_DIR . $fileName)) {
        return '../uploads/projects/' . $fileName;
    }
    return null;
};

// ================== پیام Flash ==================
$msg = '';
$msgType = 'success';

if (isset($_SESSION['project_flash'])) {
    $msg = $_SESSION['project_flash']['message'] ?? '';
    $msgType = $_SESSION['project_flash']['type'] ?? 'success';
    unset($_SESSION['project_flash']);
}

// ================== واکشی لیست همکاران ==================
$colleaguesList = [];
try {
    $colleaguesMap = [];

    $colleaguesListStmt = $db->prepare("
        SELECT u.id, u.first_name, u.last_name, u.mobile, u.avatar, u.project_join_requires_approval
        FROM colleagues c
        INNER JOIN users u ON u.id = c.colleague_user_id
        WHERE c.user_id = ?
          AND u.status = 'active'
          AND u.allow_colleague_requests = 1
          AND NOT EXISTS (
              SELECT 1 FROM colleague_blocks cb
              WHERE cb.blocker_user_id = u.id AND cb.blocked_user_id = c.user_id
          )
          AND NOT EXISTS (
              SELECT 1 FROM colleague_blocks cb
              WHERE cb.blocker_user_id = c.user_id AND cb.blocked_user_id = u.id
          )
        ORDER BY u.first_name ASC, u.last_name ASC
    ");
    $colleaguesListStmt->execute([$userId]);
    $directColleagues = $colleaguesListStmt->fetchAll(PDO::FETCH_ASSOC);

    $directColleagueIds = [];
    foreach ($directColleagues as $c) {
        $cid = (int)$c['id'];
        $colleaguesMap[$cid] = $c;
        $directColleagueIds[$cid] = true;
    }

    $projectMembersStmt = $db->prepare("
        SELECT DISTINCT u.id, u.first_name, u.last_name, u.mobile, u.avatar, u.project_join_requires_approval
        FROM project_members pm
        INNER JOIN users u ON u.id = pm.user_id
        INNER JOIN projects p ON p.id = pm.project_id
        WHERE (
            p.user_id = ?
            OR EXISTS (
                SELECT 1 FROM project_members pm2
                WHERE pm2.project_id = p.id AND pm2.user_id = ?
            )
        )
        AND u.status = 'active'
    ");
    $projectMembersStmt->execute([$userId, $userId]);
    $projectMembers = $projectMembersStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($projectMembers as $c) {
        $cid = (int)$c['id'];
        if (!isset($colleaguesMap[$cid])) {
            $colleaguesMap[$cid] = $c;
        }
    }

    $colleaguesList = array_values($colleaguesMap);

    usort($colleaguesList, function($a, $b) {
        $na = trim(($a['first_name'] ?? '') . ' ' . ($a['last_name'] ?? ''));
        $nb = trim(($b['first_name'] ?? '') . ' ' . ($b['last_name'] ?? ''));
        if ($na === '') $na = $a['mobile'] ?? '';
        if ($nb === '') $nb = $b['mobile'] ?? '';
        return strcmp($na, $nb);
    });

    foreach ($colleaguesList as &$c) {
        $fullName = trim(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? ''));
        if ($fullName === '') $fullName = $c['mobile'];
        $c['full_name'] = $fullName;
        $c['initial'] = mb_substr(trim($c['first_name'] ?: $c['mobile']), 0, 1, 'UTF-8');
        $c['avatar_url'] = (!empty($c['avatar']) && file_exists(__DIR__ . '/../uploads/avatars/' . $c['avatar']))
            ? '../uploads/avatars/' . $c['avatar'] : null;
        $c['is_self'] = ((int)$c['id'] === $userId);
        $c['is_direct_colleague'] = isset($directColleagueIds[(int)$c['id']]);
        $c['needs_approval'] = ((int)($c['project_join_requires_approval'] ?? 0) === 1);
    }
    unset($c);
} catch (PDOException $e) {}

$colleaguesById = [];
foreach ($colleaguesList as $c) {
    $colleaguesById[(int)$c['id']] = $c;
}

// ================== افزودن پروژه ==================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_project'])) {
    $title = trim($_POST['project_title'] ?? '');
    $description = trim($_POST['project_description'] ?? '');
    $maxMembers = (int)($_POST['max_members'] ?? 10);
    $selectedMembers = $_POST['members'] ?? [];

    $formError = '';

    if ($title === '') {
        $formError = 'عنوان پروژه را وارد کنید.';
    } elseif ($maxMembers < 1 || $maxMembers > 100) {
        $formError = 'حداکثر اعضا باید بین ۱ تا ۱۰۰ باشد.';
    } else {
        $validMembers = [];
        if (is_array($selectedMembers)) {
            foreach ($selectedMembers as $mid) {
                $mid = (int)$mid;
                if (isset($colleaguesById[$mid]) && $mid !== $userId) {
                    $validMembers[] = $mid;
                }
            }
        }
        $validMembers = array_values(array_unique($validMembers));

        $totalMembers = count($validMembers) + 1;
        if ($totalMembers > $maxMembers) {
            $formError = "تعداد اعضای انتخابی ({$totalMembers}) از حداکثر مجاز ({$maxMembers}) بیشتر است.";
        } else {
            $profileImageName = null;
            $imageError = '';

            if (isset($_FILES['project_image']) && $_FILES['project_image']['error'] === UPLOAD_ERR_OK) {
                $file = $_FILES['project_image'];

                if ($file['size'] > 5 * 1024 * 1024) {
                    $imageError = 'حجم تصویر نباید بیشتر از ۵ مگابایت باشد.';
                } else {
                    $allowedTypes = [
                        'image/jpeg' => 'jpg',
                        'image/png'  => 'png',
                        'image/webp' => 'webp',
                        'image/gif'  => 'gif',
                    ];
                    $finfo = new finfo(FILEINFO_MIME_TYPE);
                    $mimeType = $finfo->file($file['tmp_name']);

                    if (!isset($allowedTypes[$mimeType])) {
                        $imageError = 'فرمت تصویر باید JPG، PNG، WEBP یا GIF باشد.';
                    } else {
                        $ext = $allowedTypes[$mimeType];
                        $uniqueName = 'project_' . $userId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                        $destPath = $PROJECT_UPLOAD_DIR . $uniqueName;

                        if (move_uploaded_file($file['tmp_name'], $destPath)) {
                            $profileImageName = $uniqueName;
                        } else {
                            $imageError = 'خطا در ذخیره تصویر.';
                        }
                    }
                }
            }

            if ($imageError !== '') {
                $formError = $imageError;
            } else {
                try {
                    $db->beginTransaction();

                    $stmt = $db->prepare("
                        INSERT INTO projects (user_id, title, description, max_members, profile_image)
                        VALUES (?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([$userId, $title, $description ?: null, $maxMembers, $profileImageName]);
                    $projectId = (int)$db->lastInsertId();

                    $creatorPermsJson = json_encode($ALL_PROJECT_PERMISSION_KEYS, JSON_UNESCAPED_UNICODE);
                    $stmtMember = $db->prepare("INSERT INTO project_members (project_id, user_id, permissions) VALUES (?, ?, ?)");
                    $stmtMember->execute([$projectId, $userId, $creatorPermsJson]);

                    $emptyPermsJson = json_encode([], JSON_UNESCAPED_UNICODE);
                    foreach ($validMembers as $mid) {
                        if ($mid === $userId) continue;

                        if (userNeedsProjectApproval($db, $mid)) {
                            $reqStmt = $db->prepare("
                                INSERT IGNORE INTO project_join_requests (project_id, user_id, invited_by, status)
                                VALUES (?, ?, ?, 'pending')
                            ");
                            $reqStmt->execute([$projectId, $mid, $userId]);
                        } else {
                            $stmtMember->execute([$projectId, $mid, $emptyPermsJson]);
                        }
                    }

                    $db->commit();

                    $_SESSION['project_flash'] = ['message' => 'پروژه با موفقیت ایجاد شد.', 'type' => 'success'];
                    header('Location: index.php');
                    exit;

                } catch (PDOException $e) {
                    $db->rollBack();
                    if ($profileImageName && file_exists($PROJECT_UPLOAD_DIR . $profileImageName)) {
                        @unlink($PROJECT_UPLOAD_DIR . $profileImageName);
                    }
                    $formError = 'خطا در ایجاد پروژه.';
                }
            }
        }
    }

    if ($formError !== '') {
        $_SESSION['project_flash'] = ['message' => $formError, 'type' => 'danger'];
        header('Location: index.php');
        exit;
    }
}

// ================== ویرایش پروژه ==================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_project'])) {
    $projectId = (int)($_POST['project_id'] ?? 0);
    $title = trim($_POST['project_title'] ?? '');
    $description = trim($_POST['project_description'] ?? '');
    $maxMembers = (int)($_POST['max_members'] ?? 10);
    $selectedMembers = $_POST['members'] ?? [];
    $removeImage = !empty($_POST['remove_project_image']);

    $formError = '';

    $chkProject = $db->prepare("SELECT * FROM projects WHERE id = ? LIMIT 1");
    $chkProject->execute([$projectId]);
    $existingProject = $chkProject->fetch(PDO::FETCH_ASSOC);

    if (!$existingProject) {
        $_SESSION['project_flash'] = ['message' => 'پروژه یافت نشد.', 'type' => 'danger'];
        header('Location: index.php');
        exit;
    }

    $isCreator = ((int)$existingProject['user_id'] === $userId);
    $canEditProject  = hasProjectPermission($db, $projectId, $userId, 'edit_project');
    $canAddMember    = hasProjectPermission($db, $projectId, $userId, 'add_member');
    $canRemoveMember = hasProjectPermission($db, $projectId, $userId, 'remove_member');

    if (!$canEditProject) {
        $_SESSION['project_flash'] = ['message' => 'شما دسترسی ویرایش این پروژه را ندارید.', 'type' => 'danger'];
        header('Location: index.php');
        exit;
    }

    if ($title === '') {
        $formError = 'عنوان پروژه را وارد کنید.';
    } elseif ($maxMembers < 1 || $maxMembers > 100) {
        $formError = 'حداکثر اعضا باید بین ۱ تا ۱۰۰ باشد.';
    } else {
        $currentMembersStmt = $db->prepare("SELECT user_id, permissions FROM project_members WHERE project_id = ?");
        $currentMembersStmt->execute([$projectId]);
        $currentMembersRows = $currentMembersStmt->fetchAll(PDO::FETCH_ASSOC);
        $currentMemberIds = array_map(fn($r) => (int)$r['user_id'], $currentMembersRows);

        $pendingStmt = $db->prepare("SELECT user_id FROM project_join_requests WHERE project_id = ? AND status = 'pending'");
        $pendingStmt->execute([$projectId]);
        $pendingIds = array_map('intval', $pendingStmt->fetchAll(PDO::FETCH_COLUMN));

        $validMembers = [];
        if (is_array($selectedMembers)) {
            foreach ($selectedMembers as $mid) {
                $mid = (int)$mid;
                if (isset($colleaguesById[$mid]) && $mid !== $userId) {
                    $validMembers[] = $mid;
                }
            }
        }
        $validMembers = array_values(array_unique($validMembers));

        $creatorId = (int)$existingProject['user_id'];

        $newMemberIds    = array_values(array_diff($validMembers, $currentMemberIds, $pendingIds));
        $removedMemberIds = array_values(array_diff(
            $currentMemberIds,
            array_merge($validMembers, [$userId, $creatorId])
        ));

        $removedMemberIds = array_values(array_filter($removedMemberIds, fn($id) => $id !== $creatorId));

        $removedPendingIds = array_values(array_diff($pendingIds, $validMembers));

        if (!$isCreator && !$canAddMember && !empty($newMemberIds)) {
            $_SESSION['project_flash'] = ['message' => 'شما دسترسی افزودن کاربر به این پروژه را ندارید.', 'type' => 'danger'];
            header('Location: index.php');
            exit;
        }

        if (!$isCreator && !$canRemoveMember && (!empty($removedMemberIds) || !empty($removedPendingIds))) {
            $_SESSION['project_flash'] = ['message' => 'شما دسترسی حذف کاربر از این پروژه را ندارید.', 'type' => 'danger'];
            header('Location: index.php');
            exit;
        }

        $finalMemberIds = array_merge([$userId], $validMembers);
        if (!in_array($creatorId, $finalMemberIds, true)) {
            $finalMemberIds[] = $creatorId;
        }
        $finalMemberIds = array_values(array_unique($finalMemberIds));
        $totalMembers = count($finalMemberIds);

        if ($totalMembers > $maxMembers) {
            $formError = "تعداد اعضای انتخابی ({$totalMembers}) از حداکثر مجاز ({$maxMembers}) بیشتر است.";
        } else {
            $profileImageName = $existingProject['profile_image'];
            $newImageUploaded = false;
            $imageError = '';

            if ($removeImage && !empty($existingProject['profile_image'])) {
                $oldPath = $PROJECT_UPLOAD_DIR . basename($existingProject['profile_image']);
                if (file_exists($oldPath)) @unlink($oldPath);
                $profileImageName = null;
            }

            if (isset($_FILES['project_image']) && $_FILES['project_image']['error'] === UPLOAD_ERR_OK) {
                $file = $_FILES['project_image'];

                if ($file['size'] > 5 * 1024 * 1024) {
                    $imageError = 'حجم تصویر نباید بیشتر از ۵ مگابایت باشد.';
                } else {
                    $allowedTypes = [
                        'image/jpeg' => 'jpg',
                        'image/png'  => 'png',
                        'image/webp' => 'webp',
                        'image/gif'  => 'gif',
                    ];
                    $finfo = new finfo(FILEINFO_MIME_TYPE);
                    $mimeType = $finfo->file($file['tmp_name']);

                    if (!isset($allowedTypes[$mimeType])) {
                        $imageError = 'فرمت تصویر باید JPG، PNG، WEBP یا GIF باشد.';
                    } else {
                        $ext = $allowedTypes[$mimeType];
                        $uniqueName = 'project_' . $userId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                        $destPath = $PROJECT_UPLOAD_DIR . $uniqueName;

                        if (move_uploaded_file($file['tmp_name'], $destPath)) {
                            if (!empty($existingProject['profile_image'])) {
                                $oldPath = $PROJECT_UPLOAD_DIR . basename($existingProject['profile_image']);
                                if (file_exists($oldPath)) @unlink($oldPath);
                            }
                            $profileImageName = $uniqueName;
                            $newImageUploaded = true;
                        } else {
                            $imageError = 'خطا در ذخیره تصویر.';
                        }
                    }
                }
            }

            if ($imageError !== '') {
                $formError = $imageError;
            } else {
                try {
                    $db->beginTransaction();

                    $stmt = $db->prepare("
                        UPDATE projects
                        SET title = ?, description = ?, max_members = ?, profile_image = ?
                        WHERE id = ?
                    ");
                    $stmt->execute([$title, $description ?: null, $maxMembers, $profileImageName, $projectId]);

                    if (!empty($removedMemberIds)) {
                        $placeholders = implode(',', array_fill(0, count($removedMemberIds), '?'));
                        $del = $db->prepare("DELETE FROM project_members WHERE project_id = ? AND user_id IN ($placeholders) AND user_id != ?");
                        $del->execute(array_merge([$projectId], $removedMemberIds, [$creatorId]));
                    }

                    if (!empty($removedPendingIds)) {
                        $placeholders = implode(',', array_fill(0, count($removedPendingIds), '?'));
                        $delReq = $db->prepare("DELETE FROM project_join_requests WHERE project_id = ? AND user_id IN ($placeholders) AND status = 'pending'");
                        $delReq->execute(array_merge([$projectId], $removedPendingIds));
                    }

                    $emptyPermsJson = json_encode([], JSON_UNESCAPED_UNICODE);
                    $stmtMember = $db->prepare("INSERT IGNORE INTO project_members (project_id, user_id, permissions) VALUES (?, ?, ?)");

                    $creatorPermsJson = json_encode($ALL_PROJECT_PERMISSION_KEYS, JSON_UNESCAPED_UNICODE);
                    $stmtMember->execute([$projectId, $creatorId, $creatorPermsJson]);

                    foreach ($newMemberIds as $mid) {
                        if (userNeedsProjectApproval($db, $mid)) {
                            $reqStmt = $db->prepare("
                                INSERT IGNORE INTO project_join_requests (project_id, user_id, invited_by, status)
                                VALUES (?, ?, ?, 'pending')
                            ");
                            $reqStmt->execute([$projectId, $mid, $userId]);
                        } else {
                            $stmtMember->execute([$projectId, $mid, $emptyPermsJson]);
                        }
                    }

                    $db->commit();

                    $_SESSION['project_flash'] = ['message' => 'پروژه با موفقیت ویرایش شد.', 'type' => 'success'];
                    header('Location: index.php');
                    exit;

                } catch (PDOException $e) {
                    $db->rollBack();
                    if ($newImageUploaded && $profileImageName && file_exists($PROJECT_UPLOAD_DIR . $profileImageName)) {
                        @unlink($PROJECT_UPLOAD_DIR . $profileImageName);
                    }
                    $formError = 'خطا در ویرایش پروژه.';
                }
            }
        }
    }

    if ($formError !== '') {
        $_SESSION['project_flash'] = ['message' => $formError, 'type' => 'danger'];
        header('Location: index.php');
        exit;
    }
}

// ================== ذخیره مجوزهای عضو ==================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_member_permissions'])) {
    $projectId = (int)($_POST['project_id'] ?? 0);
    $memberId  = (int)($_POST['member_id'] ?? 0);
    $permsIn   = $_POST['permissions'] ?? [];

    $chk = $db->prepare("SELECT user_id FROM projects WHERE id = ? LIMIT 1");
    $chk->execute([$projectId]);
    $creatorId = (int)$chk->fetchColumn();

    if (!$creatorId) {
        $_SESSION['project_flash'] = ['message' => 'پروژه یافت نشد.', 'type' => 'danger'];
        header('Location: index.php');
        exit;
    }

    $isCreator = ($creatorId === $userId);

    if (!$isCreator && !hasProjectPermission($db, $projectId, $userId, 'manage_permissions')) {
        $_SESSION['project_flash'] = ['message' => 'شما دسترسی اعطای دسترسی را ندارید.', 'type' => 'danger'];
        header('Location: index.php');
        exit;
    }

    if ($memberId === $creatorId) {
        $_SESSION['project_flash'] = ['message' => 'مجوزهای سازنده پروژه قابل تغییر نیست.', 'type' => 'danger'];
        header('Location: index.php');
        exit;
    }

    $memberChk = $db->prepare("SELECT 1 FROM project_members WHERE project_id = ? AND user_id = ? LIMIT 1");
    $memberChk->execute([$projectId, $memberId]);
    if (!$memberChk->fetchColumn()) {
        $_SESSION['project_flash'] = ['message' => 'این کاربر عضو پروژه نیست.', 'type' => 'danger'];
        header('Location: index.php');
        exit;
    }

    if (!is_array($permsIn)) $permsIn = [];
    $cleanPerms = array_values(array_intersect(array_unique($permsIn), $ALL_PROJECT_PERMISSION_KEYS));

    if (!$isCreator) {
        $grantable = getAllowedGrantablePermissions($db, $projectId, $userId);
        $cleanPerms = array_values(array_intersect($cleanPerms, $grantable));
    }

    if ($memberId === $userId && $userId !== $creatorId) {
        if (!in_array('manage_permissions', $cleanPerms, true)) {
            if (hasProjectPermission($db, $projectId, $userId, 'manage_permissions')) {
                $cleanPerms[] = 'manage_permissions';
            }
        }
    }

    saveMemberPermissions($db, $projectId, $memberId, $cleanPerms);

    $_SESSION['project_flash'] = ['message' => 'دسترسی‌های کاربر با موفقیت به‌روزرسانی شد.', 'type' => 'success'];
    header('Location: index.php');
    exit;
}

// ================== حذف پروژه ==================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_project'])) {
    $projectId = (int)($_POST['project_id'] ?? 0);

    $chk = $db->prepare("SELECT id, user_id, profile_image FROM projects WHERE id = ? LIMIT 1");
    $chk->execute([$projectId]);
    $projectRow = $chk->fetch(PDO::FETCH_ASSOC);

    $flashMsg = '';
    $flashType = 'success';

    if ($projectRow) {
        $isCreator = ((int)$projectRow['user_id'] === $userId);
        if (!$isCreator) {
            $flashMsg = 'فقط سازنده پروژه می‌تواند آن را حذف کند.';
            $flashType = 'danger';
        } else {
            try {
                if (!empty($projectRow['profile_image'])) {
                    $imgPath = $PROJECT_UPLOAD_DIR . basename($projectRow['profile_image']);
                    if (file_exists($imgPath)) @unlink($imgPath);
                }

                $db->prepare("DELETE FROM project_members WHERE project_id = ?")->execute([$projectId]);
                $db->prepare("DELETE FROM project_blocks WHERE project_id = ?")->execute([$projectId]);
                $db->prepare("DELETE FROM project_join_requests WHERE project_id = ?")->execute([$projectId]);
                $db->prepare("DELETE FROM projects WHERE id = ?")->execute([$projectId]);

                $flashMsg = 'پروژه با موفقیت حذف شد.';
            } catch (PDOException $e) {
                $flashMsg = 'خطا در حذف پروژه.';
                $flashType = 'danger';
            }
        }
    } else {
        $flashMsg = 'پروژه یافت نشد.';
        $flashType = 'danger';
    }

    $_SESSION['project_flash'] = ['message' => $flashMsg, 'type' => $flashType];
    header('Location: index.php');
    exit;
}

// ================== خروج از پروژه ==================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['leave_project'])) {
    $projectId = (int)($_POST['project_id'] ?? 0);

    $chk = $db->prepare("SELECT id, user_id, title, profile_image FROM projects WHERE id = ? LIMIT 1");
    $chk->execute([$projectId]);
    $projectRow = $chk->fetch(PDO::FETCH_ASSOC);

    $flashMsg = '';
    $flashType = 'success';

    if (!$projectRow) {
        $flashMsg = 'پروژه یافت نشد.';
        $flashType = 'danger';
    } elseif ((int)$projectRow['user_id'] === $userId) {
        $flashMsg = 'سازنده پروژه نمی‌تواند از آن خارج شود.';
        $flashType = 'danger';
    } else {
        try {
            $memChk = $db->prepare("SELECT 1 FROM project_members WHERE project_id = ? AND user_id = ? LIMIT 1");
            $memChk->execute([$projectId, $userId]);
            if (!$memChk->fetchColumn()) {
                $flashMsg = 'شما عضو این پروژه نیستید.';
                $flashType = 'danger';
            } else {
                $db->beginTransaction();

                $histStmt = $db->prepare("
                    INSERT INTO project_leave_history (project_id, user_id, project_title, project_image)
                    VALUES (?, ?, ?, ?)
                ");
                $histStmt->execute([
                    $projectId,
                    $userId,
                    $projectRow['title'] ?? null,
                    $projectRow['profile_image'] ?? null
                ]);

                $db->prepare("DELETE FROM project_members WHERE project_id = ? AND user_id = ?")
                   ->execute([$projectId, $userId]);

                $db->prepare("DELETE FROM project_blocks WHERE project_id = ? AND user_id = ?")
                   ->execute([$projectId, $userId]);

                $db->commit();

                $flashMsg = 'با موفقیت از پروژه خارج شدید.';
            }
        } catch (PDOException $e) {
            if ($db->inTransaction()) $db->rollBack();
            $flashMsg = 'خطا در خروج از پروژه.';
            $flashType = 'danger';
        }
    }

    $_SESSION['project_flash'] = ['message' => $flashMsg, 'type' => $flashType];
    header('Location: index.php');
    exit;
}

// ================== مسدود کردن پروژه ==================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['block_project'])) {
    $projectId = (int)($_POST['project_id'] ?? 0);

    $chk = $db->prepare("SELECT id, user_id FROM projects WHERE id = ? LIMIT 1");
    $chk->execute([$projectId]);
    $projectRow = $chk->fetch(PDO::FETCH_ASSOC);

    $flashMsg = '';
    $flashType = 'success';

    if (!$projectRow) {
        $flashMsg = 'پروژه یافت نشد.';
        $flashType = 'danger';
    } elseif ((int)$projectRow['user_id'] === $userId) {
        $flashMsg = 'سازنده پروژه نمی‌تواند پروژه خود را مسدود کند.';
        $flashType = 'danger';
    } else {
        try {
            $memChk = $db->prepare("SELECT 1 FROM project_members WHERE project_id = ? AND user_id = ? LIMIT 1");
            $memChk->execute([$projectId, $userId]);
            if (!$memChk->fetchColumn()) {
                $flashMsg = 'شما عضو این پروژه نیستید.';
                $flashType = 'danger';
            } else {
                $ins = $db->prepare("INSERT IGNORE INTO project_blocks (project_id, user_id) VALUES (?, ?)");
                $ins->execute([$projectId, $userId]);
                $flashMsg = 'پروژه مسدود شد و از لیست شما مخفی گردید.';
            }
        } catch (PDOException $e) {
            $flashMsg = 'خطا در مسدود کردن پروژه.';
            $flashType = 'danger';
        }
    }

    $_SESSION['project_flash'] = ['message' => $flashMsg, 'type' => $flashType];
    header('Location: index.php');
    exit;
}

// ================== رفع مسدودی پروژه ==================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['unblock_project'])) {
    $projectId = (int)($_POST['project_id'] ?? 0);

    $flashMsg = '';
    $flashType = 'success';

    try {
        $del = $db->prepare("DELETE FROM project_blocks WHERE project_id = ? AND user_id = ?");
        $del->execute([$projectId, $userId]);

        if ($del->rowCount() > 0) {
            $flashMsg = 'مسدودی پروژه برداشته شد.';
        } else {
            $flashMsg = 'این پروژه توسط شما مسدود نشده بود.';
            $flashType = 'danger';
        }
    } catch (PDOException $e) {
        $flashMsg = 'خطا در رفع مسدودی پروژه.';
        $flashType = 'danger';
    }

    $_SESSION['project_flash'] = ['message' => $flashMsg, 'type' => $flashType];
    header('Location: index.php');
    exit;
}

// ================== پاسخ به درخواست عضویت (تایید/رد) ==================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['respond_join_request'])) {
    $requestId = (int)($_POST['request_id'] ?? 0);
    $action    = (string)($_POST['action'] ?? '');

    $flashMsg = '';
    $flashType = 'success';

    if (!in_array($action, ['accept', 'reject'], true)) {
        $flashMsg = 'عملیات نامعتبر است.';
        $flashType = 'danger';
    } else {
        try {
            $reqStmt = $db->prepare("
                SELECT r.*, p.title AS project_title, p.profile_image AS project_image,
                       p.user_id AS project_creator, p.max_members
                FROM project_join_requests r
                INNER JOIN projects p ON p.id = r.project_id
                WHERE r.id = ? AND r.user_id = ? AND r.status = 'pending'
                LIMIT 1
            ");
            $reqStmt->execute([$requestId, $userId]);
            $request = $reqStmt->fetch(PDO::FETCH_ASSOC);

            if (!$request) {
                $flashMsg = 'درخواست یافت نشد یا قبلاً پاسخ داده شده است.';
                $flashType = 'danger';
            } else {
                $projectId = (int)$request['project_id'];

                if ($action === 'accept') {
                    $cntStmt = $db->prepare("SELECT COUNT(*) FROM project_members WHERE project_id = ?");
                    $cntStmt->execute([$projectId]);
                    $currentCount = (int)$cntStmt->fetchColumn();
                    $maxMembers = (int)$request['max_members'];

                    if ($currentCount >= $maxMembers) {
                        $flashMsg = 'ظرفیت پروژه تکمیل است. با سازنده پروژه تماس بگیرید.';
                        $flashType = 'danger';
                    } else {
                        $db->beginTransaction();

                        $emptyPermsJson = json_encode([], JSON_UNESCAPED_UNICODE);
                        $insMember = $db->prepare("
                            INSERT IGNORE INTO project_members (project_id, user_id, permissions)
                            VALUES (?, ?, ?)
                        ");
                        $insMember->execute([$projectId, $userId, $emptyPermsJson]);

                        $updReq = $db->prepare("
                            UPDATE project_join_requests
                            SET status = 'accepted', resolved_at = NOW()
                            WHERE id = ?
                        ");
                        $updReq->execute([$requestId]);

                        $db->commit();
                        $flashMsg = 'عضویت شما در پروژه تایید شد.';
                    }
                } else {
                    $db->beginTransaction();

                    $updReq = $db->prepare("
                        UPDATE project_join_requests
                        SET status = 'rejected', resolved_at = NOW()
                        WHERE id = ?
                    ");
                    $updReq->execute([$requestId]);

                    $histStmt = $db->prepare("
                        INSERT INTO project_leave_history (project_id, user_id, project_title, project_image)
                        VALUES (?, ?, ?, ?)
                    ");
                    $histStmt->execute([
                        $projectId,
                        $userId,
                        $request['project_title'] ?? null,
                        $request['project_image'] ?? null
                    ]);

                    $db->commit();
                    $flashMsg = 'درخواست عضویت رد شد و به پروژه‌های سابق منتقل گردید.';
                }
            }
        } catch (PDOException $e) {
            if ($db->inTransaction()) $db->rollBack();
            $flashMsg = 'خطا در پردازش درخواست.';
            $flashType = 'danger';
        }
    }

    $_SESSION['project_flash'] = ['message' => $flashMsg, 'type' => $flashType];
    header('Location: index.php');
    exit;
}

// ================== پاک کردن تاریخچه خروج ==================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clear_leave_history'])) {
    try {
        $del = $db->prepare("DELETE FROM project_leave_history WHERE user_id = ?");
        $del->execute([$userId]);
        $_SESSION['project_flash'] = ['message' => 'تاریخچه پروژه‌های سابق پاک شد.', 'type' => 'success'];
    } catch (PDOException $e) {
        $_SESSION['project_flash'] = ['message' => 'خطا در پاک کردن تاریخچه.', 'type' => 'danger'];
    }
    header('Location: index.php');
    exit;
}

// ================== واکشی درخواست‌های در انتظار تایید برای کاربر جاری ==================
$pendingRequestsList = [];
try {
    $pendStmt = $db->prepare("
        SELECT r.id AS request_id, r.project_id, r.invited_by, r.created_at AS requested_at,
               p.title, p.description, p.max_members, p.profile_image, p.user_id AS creator_id,
               u.first_name AS inviter_first, u.last_name AS inviter_last, u.mobile AS inviter_mobile, u.avatar AS inviter_avatar
        FROM project_join_requests r
        INNER JOIN projects p ON p.id = r.project_id
        INNER JOIN users u ON u.id = r.invited_by
        WHERE r.user_id = ? AND r.status = 'pending'
        ORDER BY r.created_at DESC
    ");
    $pendStmt->execute([$userId]);
    $pendingRequestsList = $pendStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($pendingRequestsList as &$pr) {
        $pr['profile_image_url'] = $makeProjectImageUrl($pr['profile_image']);
        $pr['creator_name'] = trim(($pr['inviter_first'] ?? '') . ' ' . ($pr['inviter_last'] ?? ''));
        if ($pr['creator_name'] === '') $pr['creator_name'] = $pr['inviter_mobile'] ?? '';
        $pr['creator_initial'] = mb_substr(trim($pr['inviter_first'] ?: $pr['inviter_mobile']), 0, 1, 'UTF-8');
        $pr['creator_avatar_url'] = (!empty($pr['inviter_avatar']) && file_exists(__DIR__ . '/../uploads/avatars/' . $pr['inviter_avatar']))
            ? '../uploads/avatars/' . $pr['inviter_avatar'] : null;
    }
    unset($pr);
} catch (PDOException $e) {
    $pendingRequestsList = [];
}

$totalPendingRequests = count($pendingRequestsList);

// ================== واکشی لیست پروژه‌ها ==================
$projectsList = [];
try {
    $stmt = $db->prepare("
        SELECT 
            p.*,
            (SELECT COUNT(*) FROM project_members pm WHERE pm.project_id = p.id) AS members_count
        FROM projects p
        WHERE (
            p.user_id = ?
            OR EXISTS (
                SELECT 1 FROM project_members pm
                WHERE pm.project_id = p.id AND pm.user_id = ?
            )
        )
        AND NOT EXISTS (
            SELECT 1 FROM project_blocks pb
            WHERE pb.project_id = p.id AND pb.user_id = ?
        )
        ORDER BY p.created_at DESC
    ");
    $stmt->execute([$userId, $userId, $userId]);
    $projectsList = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $allMembers = [];
    if (!empty($projectsList)) {
        $projectIds = array_map(fn($p) => (int)$p['id'], $projectsList);
        $placeholders = implode(',', array_fill(0, count($projectIds), '?'));
        
        $memStmt = $db->prepare("
            SELECT pm.project_id, u.id, u.first_name, u.last_name, u.mobile, u.avatar, pm.permissions, pm.added_at
            FROM project_members pm
            INNER JOIN users u ON u.id = pm.user_id
            WHERE pm.project_id IN ($placeholders)
            ORDER BY pm.added_at ASC
        ");
        $memStmt->execute($projectIds);
        $memberRows = $memStmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($memberRows as $m) {
            $allMembers[(int)$m['project_id']][] = $m;
        }
    }

    foreach ($projectsList as &$proj) {
        $pid = (int)$proj['id'];
        $proj['profile_image_url'] = $makeProjectImageUrl($proj['profile_image']);

        $proj['is_creator'] = ((int)$proj['user_id'] === $userId);
        if ($proj['is_creator']) {
            $proj['my_permissions'] = $ALL_PROJECT_PERMISSION_KEYS;
        } else {
            $proj['my_permissions'] = getMemberPermissions($db, $pid, $userId);
        }

        $proj['can_edit_project']       = $proj['is_creator'] || in_array('edit_project', $proj['my_permissions'], true);
        $proj['can_add_member']         = $proj['is_creator'] || in_array('add_member', $proj['my_permissions'], true);
        $proj['can_remove_member']      = $proj['is_creator'] || in_array('remove_member', $proj['my_permissions'], true);
        $proj['can_manage_permissions'] = $proj['is_creator'] || in_array('manage_permissions', $proj['my_permissions'], true);
        $proj['can_delete']             = $proj['is_creator'];
        // ✅ جدید: دسترسی مشاهده تحلیل‌ها
        $proj['can_view_analytics']     = $proj['is_creator'] || in_array('view_analytics', $proj['my_permissions'], true);

        $proj['is_member_not_creator']  = !$proj['is_creator'];
        $proj['can_leave']              = $proj['is_member_not_creator'];
        $proj['can_block']              = $proj['is_member_not_creator'];

        $proj['grantable_permissions'] = getAllowedGrantablePermissions($db, $pid, $userId);

        $members = $allMembers[$pid] ?? [];

        foreach ($members as &$m) {
            $fullName = trim(($m['first_name'] ?? '') . ' ' . ($m['last_name'] ?? ''));
            if ($fullName === '') $fullName = $m['mobile'];
            $m['full_name'] = $fullName;
            $m['initial'] = mb_substr(trim($m['first_name'] ?: $m['mobile']), 0, 1, 'UTF-8');
            $m['avatar_url'] = (!empty($m['avatar']) && file_exists(__DIR__ . '/../uploads/avatars/' . $m['avatar']))
                ? '../uploads/avatars/' . $m['avatar'] : null;
            $m['is_creator'] = ((int)$m['id'] === (int)$proj['user_id']);

            $mPerms = [];
            if ($m['is_creator']) {
                $mPerms = $ALL_PROJECT_PERMISSION_KEYS;
            } else {
                $decoded = json_decode($m['permissions'] ?? '[]', true);
                if (is_array($decoded)) {
                    $mPerms = array_values(array_intersect($decoded, $ALL_PROJECT_PERMISSION_KEYS));
                }
            }
            $m['permission_keys'] = $mPerms;
        }
        unset($m);

        $proj['members'] = $members;
        $proj['member_ids'] = array_map(fn($m) => (int)$m['id'], $members);
    }
    unset($proj);
} catch (PDOException $e) {
    $projectsList = [];
}

// ================== واکشی پروژه‌های مسدودشده ==================
$blockedProjectsList = [];
try {
    $blkStmt = $db->prepare("
        SELECT p.*, pb.created_at AS blocked_at,
               (SELECT COUNT(*) FROM project_members pm WHERE pm.project_id = p.id) AS members_count
        FROM project_blocks pb
        INNER JOIN projects p ON p.id = pb.project_id
        WHERE pb.user_id = ?
        ORDER BY pb.created_at DESC
    ");
    $blkStmt->execute([$userId]);
    $blockedProjectsList = $blkStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($blockedProjectsList as &$bp) {
        $bp['profile_image_url'] = $makeProjectImageUrl($bp['profile_image']);
    }
    unset($bp);
} catch (PDOException $e) {
    $blockedProjectsList = [];
}

// ================== واکشی پروژه‌های سابق (تاریخچه خروج) ==================
$leftProjectsList = [];
try {
    $leftStmt = $db->prepare("
        SELECT id, project_id, project_title, project_image, left_at
        FROM project_leave_history
        WHERE user_id = ?
        ORDER BY left_at DESC
        LIMIT 50
    ");
    $leftStmt->execute([$userId]);
    $leftProjectsList = $leftStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($leftProjectsList as &$lp) {
        $lp['profile_image_url'] = $makeProjectImageUrl($lp['project_image']);
        $lp['is_member_again'] = false;
        try {
            $mChk = $db->prepare("SELECT 1 FROM project_members WHERE project_id = ? AND user_id = ? LIMIT 1");
            $mChk->execute([(int)$lp['project_id'], $userId]);
            $lp['is_member_again'] = (bool)$mChk->fetchColumn();
        } catch (PDOException $e) {}
    }
    unset($lp);
} catch (PDOException $e) {
    $leftProjectsList = [];
}

// ================== آمار وظایف هر پروژه ==================
$projectTaskStats = [];
try {
    $statsStmt = $db->prepare("
        SELECT 
            project_id,
            COUNT(*) AS total_tasks,
            SUM(CASE WHEN is_completed = 0 THEN 1 ELSE 0 END) AS pending_tasks,
            SUM(CASE WHEN is_completed = 1 THEN 1 ELSE 0 END) AS completed_tasks
        FROM tasks
        WHERE project_id IS NOT NULL
          AND (user_id = ? OR assignee_id = ?)
        GROUP BY project_id
    ");
    $statsStmt->execute([$userId, $userId]);
    $statsRows = $statsStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($statsRows as $r) {
        $projectTaskStats[(int)$r['project_id']] = [
            'total'     => (int)$r['total_tasks'],
            'pending'   => (int)$r['pending_tasks'],
            'completed' => (int)$r['completed_tasks'],
        ];
    }
} catch (PDOException $e) {}

foreach ($projectsList as &$proj) {
    $pid = (int)$proj['id'];
    $proj['task_stats'] = $projectTaskStats[$pid] ?? ['total' => 0, 'pending' => 0, 'completed' => 0];
}
unset($proj);

$totalProjects = count($projectsList);
$totalMembersAll = 0;
foreach ($projectsList as $p) $totalMembersAll += (int)$p['members_count'];
$totalBlocked = count($blockedProjectsList);
$totalLeft = count($leftProjectsList);

$displayUser = $_SESSION['user_mobile'] ?? $_SESSION['mobile'] ?? $_SESSION['username'] ?? 'کاربر';

$projectsJson = json_encode($projectsList, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
$permissionsMetaJson = json_encode($PROJECT_PERMISSIONS, JSON_UNESCAPED_UNICODE);
$allPermissionKeysJson = json_encode($ALL_PROJECT_PERMISSION_KEYS, JSON_UNESCAPED_UNICODE);
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <title>مدیریت پروژه‌ها</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="icon" href="https://img.icons8.com/color/48/dashboard-layout.png" type="image/png">
<style>
    :root {
        --primary: #1e293b;
        --accent: #2563eb;
        --accent-hover: #1d4ed8;
        --bg: #f8fafc;
        --card: #ffffff;
        --text: #334155;
        --border: #e2e8f0;
        --success: #10b981;
        --warning: #f59e0b;
        --danger: #ef4444;
    }
    * { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
    html, body { max-width: 100%; overflow-x: hidden; }

    body {
        background: var(--bg);
        color: var(--text);
        font-family: Tahoma, sans-serif;
        margin: 0;
        display: flex;
        height: 100vh;
        height: 100dvh;
        overflow: hidden;
        -webkit-font-smoothing: antialiased;
    }

    .sidebar {
        background: linear-gradient(180deg, #4c8bf5 0%, #2f6bdc 100%) !important;
        color: #fff !important;
        box-shadow: -6px 0 28px rgba(76, 139, 245, 0.18);
        overflow: visible !important;
        scrollbar-width: none !important;
        -ms-overflow-style: none !important;
    }
    .sidebar::-webkit-scrollbar { display: none !important; width: 0 !important; height: 0 !important; }
    .sidebar::-webkit-scrollbar-track,
    .sidebar::-webkit-scrollbar-thumb { display: none !important; background: transparent !important; }

    body > .sidebar,
    body .sidebar {
        height: 100vh !important;
        height: 100dvh !important;
        max-height: 100vh !important;
        max-height: 100dvh !important;
        align-self: flex-start !important;
    }

    .sidebar > nav,
    .sidebar > ul,
    .sidebar > .sidebar-menu,
    .sidebar > .sidebar-nav,
    .sidebar > .menu-wrapper,
    .sidebar > div[class*="nav"],
    .sidebar > div[class*="menu"] {
        overflow-y: auto !important;
        overflow-x: visible !important;
        scrollbar-width: none !important;
        -ms-overflow-style: none !important;
    }
    .sidebar > nav::-webkit-scrollbar,
    .sidebar > ul::-webkit-scrollbar,
    .sidebar > .sidebar-menu::-webkit-scrollbar,
    .sidebar > .sidebar-nav::-webkit-scrollbar,
    .sidebar > .menu-wrapper::-webkit-scrollbar,
    .sidebar > div[class*="nav"]::-webkit-scrollbar,
    .sidebar > div[class*="menu"]::-webkit-scrollbar {
        display: none !important;
        width: 0 !important;
    }

    .sidebar-brand { border-bottom: 1px solid rgba(255,255,255,0.15) !important; }
    .sidebar .menu-item { color: rgba(255,255,255,0.85) !important; }
    .sidebar .menu-item:hover,
    .sidebar .menu-item.active {
        background: rgba(255,255,255,0.18) !important;
        color: #fff !important;
    }
    .sidebar .submenu a { color: rgba(255,255,255,0.75) !important; }
    .sidebar .submenu a:hover,
    .sidebar .submenu a.active {
        color: #fff !important;
        background: rgba(255,255,255,0.12) !important;
    }
    .menu-parent { color: rgba(255,255,255,0.82) !important; }
    .menu-parent:hover,
    .menu-parent.is-open,
    .menu-parent.is-current {
        background: rgba(255,255,255,0.14) !important;
        color: #fff !important;
    }
    .submenu-inner a { color: rgba(255,255,255,0.78) !important; }
    .submenu-inner a:hover,
    .submenu-inner a.active {
        color: #fff !important;
        background: rgba(255,255,255,0.14) !important;
    }

    .sidebar-overlay {
        display: none;
        position: fixed;
        inset: 0;
        background: rgba(20, 30, 60, 0.5);
        z-index: 998;
        opacity: 0;
        transition: opacity 0.25s ease;
    }
    .sidebar-overlay.active { display: block; opacity: 1; }

    .main-wrapper {
        flex: 1;
        display: flex;
        flex-direction: column;
        overflow: hidden;
        min-width: 0;
    }

    .topbar {
        background: var(--card);
        padding: 15px clamp(16px, 2vw, 30px);
        border-bottom: 1px solid var(--border);
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 12px;
        flex-shrink: 0;
    }
    .topbar h1 {
        margin: 0;
        font-size: clamp(1rem, 1.4vw, 1.2rem);
        color: var(--primary);
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        display: flex;
        align-items: center;
    }
    .topbar-user {
        font-size: 0.9rem;
        font-weight: bold;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        max-width: 220px;
        flex-shrink: 0;
    }

    .content-area {
        flex: 1;
        padding: clamp(12px, 2vw, 25px);
        overflow-y: auto;
        -webkit-overflow-scrolling: touch;
    }

    .analytics-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 16px;
        margin-bottom: 25px;
    }
    .analytic-card {
        background: var(--card);
        border: 1px solid var(--border);
        padding: 20px;
        border-radius: 16px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        min-width: 0;
        transition: border-color 0.15s ease, box-shadow 0.15s ease;
    }
    .analytic-card:hover { border-color: #cbd5e1; box-shadow: 0 4px 12px rgba(0,0,0,0.04); }
    .analytic-card .info { min-width: 0; }
    .analytic-card .info div:first-child { font-size: 0.8rem; color: #64748b; margin-bottom: 5px; }
    .analytic-card .info div:last-child { font-size: 1.4rem; font-weight: bold; color: var(--primary); }
    .analytic-icon {
        width: 48px; height: 48px;
        border-radius: 12px;
        display: flex; align-items: center; justify-content: center;
        background: #e0f2fe; color: #0284c7;
        flex-shrink: 0;
    }

    .card {
        background: var(--card);
        border: 1px solid var(--border);
        border-radius: 20px;
        padding: clamp(14px, 2vw, 24px);
        margin-bottom: 20px;
    }

    button.btn {
        background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
        color: #fff;
        border: none;
        padding: 11px 22px;
        border-radius: 12px;
        font-weight: bold;
        cursor: pointer;
        transition: background-color 0.2s ease, transform 0.15s ease;
        font-family: Tahoma, sans-serif;
        font-size: 0.9rem;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 5px;
        white-space: nowrap;
    }
    button.btn:hover { background: linear-gradient(135deg, #1d4ed8 0%, #1e40af 100%); }
    button.btn:active { transform: scale(0.98); }
    button.btn:disabled { opacity: 0.5; cursor: not-allowed; transform: none; }
    button.btn-ghost { background: #f1f5f9; color: #64748b; box-shadow: none; }
    button.btn-ghost:hover { background: #e2e8f0; color: #334155; }
    button.btn-sm { padding: 9px 16px; font-size: 0.82rem; }

    .alert {
        padding: 12px 16px;
        border-radius: 12px;
        margin-bottom: 18px;
        font-weight: bold;
        font-size: 0.9rem;
        display: flex;
        align-items: center;
        gap: 10px;
    }
    .alert-success { background: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; }
    .alert-danger  { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
    .alert-warning { background: #fef3c7; color: #92400e; border: 1px solid #fde68a; }

    .pending-requests-section {
        background: linear-gradient(135deg, #fef2f2, #fee2e2);
        border: 2px solid #fecaca;
        border-radius: 20px;
        padding: 20px;
        margin-bottom: 22px;
    }
    .pending-requests-header {
        display: flex; align-items: center; justify-content: space-between;
        margin-bottom: 16px; padding-bottom: 14px;
        border-bottom: 2px dashed #fecaca;
        gap: 10px; flex-wrap: wrap;
    }
    .pending-requests-header h3 {
        margin: 0; font-size: 1.05rem; color: #991b1b;
        display: flex; align-items: center; gap: 8px;
    }
    .pending-requests-badge {
        background: #dc2626; color: #fff;
        font-size: 0.72rem; font-weight: bold;
        padding: 4px 12px; border-radius: 10px;
        animation: pulseBadge 1.6s ease-in-out infinite;
    }
    @keyframes pulseBadge {
        0%, 100% { transform: scale(1); box-shadow: 0 0 0 0 rgba(220, 38, 38, 0.7); }
        50% { transform: scale(1.04); box-shadow: 0 0 0 6px rgba(220, 38, 38, 0); }
    }

    .pending-requests-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(340px, 1fr));
        gap: 14px;
    }

    .pending-request-card {
        background: #ffffff;
        border: 2px solid #fca5a5;
        border-radius: 16px;
        padding: 16px 18px;
        display: flex;
        flex-direction: column;
        gap: 12px;
        position: relative;
        overflow: hidden;
        transition: border-color 0.15s ease, box-shadow 0.15s ease;
    }
    .pending-request-card::before {
        content: '';
        position: absolute;
        top: 0; right: 0;
        width: 5px; height: 100%;
        background: linear-gradient(180deg, #dc2626, #ef4444);
    }
    .pending-request-card:hover {
        border-color: #ef4444;
        box-shadow: 0 6px 20px rgba(220, 38, 38, 0.12);
    }

    .pending-request-card__top {
        display: flex; align-items: center; gap: 12px;
        padding-right: 8px;
    }

    .pending-request-card__avatar {
        width: 52px; height: 52px; border-radius: 14px;
        background: linear-gradient(135deg, #dc2626, #ef4444);
        color: #fff; display: flex; align-items: center; justify-content: center;
        font-weight: bold; font-size: 1.2rem; flex-shrink: 0;
        overflow: hidden;
        box-shadow: 0 4px 12px rgba(220, 38, 38, 0.25);
    }
    .pending-request-card__avatar img { width: 100%; height: 100%; object-fit: cover; }

    .pending-request-card__info { flex: 1; min-width: 0; }
    .pending-request-card__title {
        font-weight: bold; font-size: 0.95rem; color: #7f1d1d;
        margin-bottom: 4px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    }
    .pending-request-card__meta {
        font-size: 0.72rem; color: #b91c1c;
        display: flex; align-items: center; gap: 5px;
    }

    .pending-request-card__inviter {
        display: flex; align-items: center; gap: 8px;
        padding: 10px 12px;
        background: #fef2f2;
        border: 1px solid #fecaca;
        border-radius: 10px;
        font-size: 0.78rem;
        color: #7f1d1d;
    }
    .pending-request-card__inviter-avatar {
        width: 26px; height: 26px; border-radius: 50%;
        background: linear-gradient(135deg, #f87171, #dc2626);
        color: #fff; display: flex; align-items: center; justify-content: center;
        font-weight: bold; font-size: 0.7rem; flex-shrink: 0;
        overflow: hidden;
    }
    .pending-request-card__inviter-avatar img { width: 100%; height: 100%; object-fit: cover; }
    .pending-request-card__inviter-name { font-weight: bold; color: #991b1b; }

    .pending-request-card__actions {
        display: flex; gap: 8px;
        padding-right: 8px;
    }

    .btn-accept {
        flex: 1;
        background: linear-gradient(135deg, #10b981, #059669);
        color: #fff; border: none;
        padding: 10px 14px; border-radius: 10px;
        font-family: Tahoma, sans-serif; font-weight: bold;
        font-size: 0.82rem; cursor: pointer;
        display: inline-flex; align-items: center; justify-content: center; gap: 6px;
        transition: transform 0.15s ease, box-shadow 0.15s ease;
        box-shadow: 0 4px 12px rgba(16, 185, 129, 0.25);
    }
    .btn-accept:hover { transform: translateY(-1px); box-shadow: 0 6px 16px rgba(16, 185, 129, 0.35); }
    .btn-accept:active { transform: scale(0.98); }

    .btn-reject {
        flex: 1;
        background: linear-gradient(135deg, #ef4444, #dc2626);
        color: #fff; border: none;
        padding: 10px 14px; border-radius: 10px;
        font-family: Tahoma, sans-serif; font-weight: bold;
        font-size: 0.82rem; cursor: pointer;
        display: inline-flex; align-items: center; justify-content: center; gap: 6px;
        transition: transform 0.15s ease, box-shadow 0.15s ease;
        box-shadow: 0 4px 12px rgba(239, 68, 68, 0.25);
    }
    .btn-reject:hover { transform: translateY(-1px); box-shadow: 0 6px 16px rgba(239, 68, 68, 0.35); }
    .btn-reject:active { transform: scale(0.98); }

    .projects-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(340px, 1fr)); gap: 18px; }

    .project-card {
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 18px;
        overflow: hidden;
        transition: border-color 0.15s ease, box-shadow 0.15s ease;
        display: flex;
        flex-direction: column;
        contain: layout style;
    }
    .project-card:hover { border-color: #cbd5e1; box-shadow: 0 4px 16px rgba(0,0,0,0.06); }

    .project-card__header {
        display: flex; align-items: center; gap: 14px;
        padding: 18px 20px;
        background: linear-gradient(135deg, #f8fafc, #eef2ff);
        border-bottom: 1px solid #e2e8f0;
        position: relative;
    }

    .project-card__avatar {
        width: 60px; height: 60px; border-radius: 16px;
        background: linear-gradient(135deg, #6366f1, #4f46e5);
        color: #fff; display: flex; align-items: center; justify-content: center;
        font-weight: bold; font-size: 1.4rem; flex-shrink: 0; overflow: hidden;
    }
    .project-card__avatar img { width: 100%; height: 100%; object-fit: cover; display: block; }

    .project-card__info { flex: 1; min-width: 0; }
    .project-card__title {
        font-weight: bold; font-size: 1rem; color: #1e293b;
        margin-bottom: 4px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    }
    .project-card__date { font-size: 0.72rem; color: #94a3b8; display: flex; align-items: center; gap: 5px; }

    .project-card__badge-role {
        position: absolute;
        top: 10px; left: 10px;
        font-size: 0.65rem;
        font-weight: bold;
        padding: 3px 8px;
        border-radius: 6px;
        display: inline-flex;
        align-items: center;
        gap: 4px;
    }
    .project-card__badge-role.creator { background: #fef3c7; color: #92400e; border: 1px solid #fde68a; }
    .project-card__badge-role.member  { background: #e0e7ff; color: #4f46e5; border: 1px solid #c7d2fe; }
    .project-card__badge-role.viewer  { background: #f1f5f9; color: #64748b; border: 1px solid #e2e8f0; }
    .project-card__badge-role.blocked { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
    .project-card__badge-role.left    { background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; }
    .project-card__badge-role.rejoined { background: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; }
    .project-card__badge-role.rejected { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }

    .project-card__body { padding: 16px 20px; flex: 1; }

    .project-card__description {
        font-size: 0.82rem; color: #64748b; line-height: 1.7; margin-bottom: 14px;
        display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;
    }

    .project-card__stats {
        display: flex; gap: 14px;
        margin-top: 14px;
        padding-top: 14px;
        border-top: 1px dashed #e2e8f0;
        font-size: 0.78rem; color: #64748b;
        flex-wrap: wrap;
    }
    .project-card__stat { display: flex; align-items: center; gap: 6px; }
    .project-card__stat i { color: #94a3b8; font-size: 0.8rem; }

    .project-card__task-stats {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 8px;
        margin-top: 16px;
        padding-top: 16px;
        border-top: 1px dashed #e2e8f0;
    }

    .project-card__task-stat {
        display: flex;
        flex-direction: row;
        align-items: center;
        gap: 8px;
        padding: 8px 10px;
        border-radius: 10px;
        min-width: 0;
    }

    .project-card__task-stat-icon {
        width: 26px;
        height: 26px;
        border-radius: 8px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 0.72rem;
        flex-shrink: 0;
    }

    .project-card__task-stat.total { background: linear-gradient(135deg, #eff6ff, #dbeafe); border: 1px solid #bfdbfe; }
    .project-card__task-stat.total .project-card__task-stat-icon { background: #2563eb; color: #fff; }
    .project-card__task-stat.total .project-card__task-stat-value { color: #1e40af; }

    .project-card__task-stat.pending { background: linear-gradient(135deg, #fffbeb, #fef3c7); border: 1px solid #fde68a; }
    .project-card__task-stat.pending .project-card__task-stat-icon { background: #f59e0b; color: #fff; }
    .project-card__task-stat.pending .project-card__task-stat-value { color: #92400e; }

    .project-card__task-stat.completed { background: linear-gradient(135deg, #ecfdf5, #d1fae5); border: 1px solid #a7f3d0; }
    .project-card__task-stat.completed .project-card__task-stat-icon { background: #10b981; color: #fff; }
    .project-card__task-stat.completed .project-card__task-stat-value { color: #065f46; }

    .project-card__task-stat-info { text-align: right; min-width: 0; flex: 1; }
    .project-card__task-stat-label { font-size: 0.65rem; color: #64748b; font-weight: 600; margin-bottom: 1px; white-space: nowrap; line-height: 1.2; }
    .project-card__task-stat-value { font-size: 0.95rem; font-weight: bold; line-height: 1.2; }

    .project-card__members {
        display: flex;
        align-items: center;
        padding: 16px 20px 4px;
        margin-bottom: 8px;
    }

    .member-avatars-stack { display: flex; flex-direction: row-reverse; align-items: center; }

    .member-avatar-stack {
        width: 34px; height: 34px; border-radius: 50%;
        background: linear-gradient(135deg, #94a3b8, #64748b);
        color: #fff; display: flex; align-items: center; justify-content: center;
        font-weight: bold; font-size: 0.75rem; overflow: hidden;
        border: 2px solid #fff; margin-right: -10px;
        transition: transform 0.15s ease; position: relative; cursor: pointer;
    }
    .member-avatar-stack:first-child { margin-right: 0; }
    .member-avatar-stack:hover { transform: translateY(-2px) scale(1.08); z-index: 10; }
    .member-avatar-stack img { width: 100%; height: 100%; object-fit: cover; }
    .member-avatar-stack.is-creator { box-shadow: 0 0 0 2px #f59e0b, 0 0 0 4px #fff; }

    .member-more {
        width: 34px; height: 34px; border-radius: 50%;
        background: #f1f5f9; color: #475569; display: flex; align-items: center; justify-content: center;
        font-weight: bold; font-size: 0.7rem; border: 2px solid #fff; margin-right: -10px; flex-shrink: 0;
    }

    .project-card__footer {
        padding: 12px 20px 16px;
        display: flex; justify-content: space-between; align-items: center;
        border-top: 1px solid #f1f5f9; margin-top: auto;
        gap: 8px; flex-wrap: wrap;
    }

    .project-card__max { font-size: 0.75rem; color: #94a3b8; display: flex; align-items: center; gap: 5px; }
    .project-card__max strong { color: #475569; font-size: 0.82rem; }

    .project-card__actions { display: flex; gap: 6px; align-items: center; flex-wrap: wrap; }

    /* ✅ جدید: دکمه تحلیل - بنفش متمایز */
    .project-card__analytics {
        background: linear-gradient(135deg, #f3e8ff, #ede9fe);
        color: #7c3aed;
        border: 1px solid #ddd6fe;
        border-radius: 9px;
        padding: 7px 12px;
        font-size: 0.75rem;
        font-weight: bold;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        transition: background-color 0.15s ease, color 0.15s ease, border-color 0.15s ease, box-shadow 0.15s ease;
        font-family: Tahoma, sans-serif;
        text-decoration: none;
    }
    .project-card__analytics:hover {
        background: linear-gradient(135deg, #7c3aed, #6d28d9);
        color: #fff;
        border-color: #7c3aed;
        box-shadow: 0 4px 12px rgba(124, 58, 237, 0.3);
    }
    .project-card__analytics i { font-size: 0.8rem; }

    .project-card__edit {
        background: #eff6ff; color: #2563eb;
        border: 1px solid #dbeafe; border-radius: 9px;
        padding: 7px 12px; font-size: 0.75rem; font-weight: bold;
        cursor: pointer; display: inline-flex; align-items: center; gap: 6px;
        transition: background-color 0.15s ease, color 0.15s ease;
        font-family: Tahoma, sans-serif;
    }
    .project-card__edit:hover { background: #2563eb; color: #fff; border-color: #2563eb; }

    .project-card__delete {
        background: #fef2f2; color: #dc2626;
        border: 1px solid #fee2e2; border-radius: 9px;
        padding: 7px 12px; font-size: 0.75rem; font-weight: bold;
        cursor: pointer; display: inline-flex; align-items: center; gap: 6px;
        transition: background-color 0.15s ease, color 0.15s ease;
        font-family: Tahoma, sans-serif;
    }
    .project-card__delete:hover { background: #dc2626; color: #fff; border-color: #dc2626; }

    .project-card__leave {
        background: #f0f9ff; color: #0284c7;
        border: 1px solid #bae6fd; border-radius: 9px;
        padding: 7px 12px; font-size: 0.75rem; font-weight: bold;
        cursor: pointer; display: inline-flex; align-items: center; gap: 6px;
        transition: background-color 0.15s ease, color 0.15s ease;
        font-family: Tahoma, sans-serif;
    }
    .project-card__leave:hover { background: #0284c7; color: #fff; border-color: #0284c7; }

    .project-card__block {
        background: #fff7ed; color: #ea580c;
        border: 1px solid #fed7aa; border-radius: 9px;
        padding: 7px 12px; font-size: 0.75rem; font-weight: bold;
        cursor: pointer; display: inline-flex; align-items: center; gap: 6px;
        transition: background-color 0.15s ease, color 0.15s ease;
        font-family: Tahoma, sans-serif;
    }
    .project-card__block:hover { background: #ea580c; color: #fff; border-color: #ea580c; }

    .project-card__unblock {
        background: #f0fdf4; color: #16a34a;
        border: 1px solid #bbf7d0; border-radius: 9px;
        padding: 7px 12px; font-size: 0.75rem; font-weight: bold;
        cursor: pointer; display: inline-flex; align-items: center; gap: 6px;
        transition: background-color 0.15s ease, color 0.15s ease;
        font-family: Tahoma, sans-serif;
    }
    .project-card__unblock:hover { background: #16a34a; color: #fff; border-color: #16a34a; }

    .empty-state { grid-column: 1 / -1; text-align: center; padding: 60px 20px; color: #94a3b8; }
    .empty-state i { font-size: 3rem; margin-bottom: 16px; opacity: 0.4; display: block; }

    .projects-tabs {
        display: flex;
        gap: 8px;
        margin-bottom: 20px;
        padding: 6px;
        background: #f1f5f9;
        border-radius: 14px;
        flex-wrap: wrap;
    }
    .projects-tab {
        flex: 1;
        min-width: 140px;
        padding: 12px 16px;
        border-radius: 10px;
        border: none;
        background: transparent;
        color: #64748b;
        font-family: Tahoma, sans-serif;
        font-weight: bold;
        font-size: 0.85rem;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        transition: background-color 0.15s ease, color 0.15s ease, box-shadow 0.15s ease;
    }
    .projects-tab:hover { background: rgba(255,255,255,0.6); color: #334155; }
    .projects-tab.active {
        background: #ffffff;
        color: var(--accent);
        box-shadow: 0 2px 6px rgba(0,0,0,0.06);
    }
    .projects-tab .tab-count {
        background: #e2e8f0;
        color: #475569;
        font-size: 0.7rem;
        padding: 2px 8px;
        border-radius: 8px;
        font-weight: bold;
    }
    .projects-tab.active .tab-count {
        background: #dbeafe;
        color: #1d4ed8;
    }

    .projects-panel { display: none; }
    .projects-panel.active { display: block; }

    .project-card.is-blocked { opacity: 0.85; }
    .project-card.is-blocked .project-card__header {
        background: linear-gradient(135deg, #fef2f2, #fee2e2);
    }

    .project-card.is-left { opacity: 0.85; }
    .project-card.is-left .project-card__header {
        background: linear-gradient(135deg, #f8fafc, #e2e8f0);
    }

    .modal-overlay {
        position: fixed;
        inset: 0;
        background: rgba(15, 23, 42, 0.7);
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 15px;
        z-index: 1000;
        opacity: 0;
        visibility: hidden;
        pointer-events: none;
        transition: opacity 0.15s ease, visibility 0.15s;
        overflow-y: auto;
        -webkit-overflow-scrolling: touch;
    }
    .modal-overlay.active {
        opacity: 1;
        visibility: visible;
        pointer-events: auto;
    }

    .modal-box {
        background: #ffffff;
        width: 100%;
        max-width: 620px;
        border-radius: 24px;
        padding: 30px;
        max-height: 92vh;
        max-height: 92dvh;
        overflow-y: auto;
        -webkit-overflow-scrolling: touch;
    }

    .modal-header {
        display: flex; justify-content: space-between; align-items: center;
        margin-bottom: 20px; border-bottom: 1px solid #f1f5f9; padding-bottom: 14px;
        gap: 10px;
    }
    .modal-header h3 {
        margin: 0; font-size: 1.15rem; color: var(--primary);
        display: flex; align-items: center; gap: 10px;
    }
    .modal-header h3 i { color: var(--accent); }
    .modal-close {
        background: #f1f5f9; border: none; width: 32px; height: 32px;
        border-radius: 50%; display: flex; align-items: center; justify-content: center;
        font-size: 1.2rem; cursor: pointer; color: #64748b; font-weight: bold;
        transition: background-color 0.15s ease;
        flex-shrink: 0;
    }
    .modal-close:hover { background: #e2e8f0; color: #1e293b; }

    .form-group { margin-bottom: 18px; }
    label { display: block; margin-bottom: 8px; font-weight: 600; font-size: 0.9rem; color: #475569; }
    label .required-star { color: var(--danger); margin-right: 3px; }

    input[type="text"], input[type="number"], textarea, select {
        width: 100%; padding: 12px 18px; background: #f8fafc;
        border: 2px solid #e2e8f0; border-radius: 14px;
        font-size: 0.95rem; font-family: Tahoma, sans-serif;
        color: #1e293b; transition: border-color 0.2s ease, box-shadow 0.2s ease, background-color 0.2s ease;
    }
    input:focus, textarea:focus, select:focus {
        outline: none; border-color: var(--accent); background: #ffffff;
        box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.12);
    }
    textarea { resize: vertical; min-height: 80px; }

    .form-hint { font-size: 0.72rem; color: #94a3b8; margin-top: 6px; line-height: 1.6; }

    .project-image-upload {
        display: flex; align-items: center; gap: 16px;
        padding: 16px; background: #f8fafc;
        border: 2px dashed #cbd5e1; border-radius: 16px;
        transition: border-color 0.15s ease;
    }
    .project-image-upload:hover { border-color: var(--accent); }

    .project-image-preview {
        width: 80px; height: 80px; border-radius: 18px;
        background: linear-gradient(135deg, #6366f1, #4f46e5);
        color: #fff; display: flex; align-items: center; justify-content: center;
        font-size: 1.8rem; flex-shrink: 0; overflow: hidden;
    }
    .project-image-preview img { width: 100%; height: 100%; object-fit: cover; }

    .project-image-actions { flex: 1; min-width: 0; }
    .project-image-actions p { margin: 0 0 8px; font-size: 0.78rem; color: #64748b; line-height: 1.6; }

    .file-input-wrapper { position: relative; display: inline-block; }
    .file-input-wrapper input[type="file"] {
        position: absolute; inset: 0; opacity: 0; cursor: pointer; width: 100%; height: 100%;
    }

    .image-action-buttons { display: flex; gap: 6px; flex-wrap: wrap; align-items: center; }

    .members-selector-header {
        display: flex; justify-content: space-between; align-items: center;
        margin-bottom: 8px; flex-wrap: wrap; gap: 8px;
    }

    .members-count-badge {
        background: #e0f2fe; color: #0284c7;
        font-size: 0.72rem; font-weight: bold;
        padding: 4px 10px; border-radius: 8px;
    }
    .members-count-badge.warning { background: #fef3c7; color: #d97706; }
    .members-count-badge.danger  { background: #fee2e2; color: #dc2626; }
    .members-count-badge.success { background: #d1fae5; color: #059669; }

    .members-select-all {
        background: none; border: none; color: var(--accent);
        font-size: 0.78rem; font-weight: bold; cursor: pointer;
        font-family: Tahoma, sans-serif; padding: 4px 8px; border-radius: 6px;
        transition: background-color 0.15s ease;
    }
    .members-select-all:hover { background: #eff6ff; }

    .members-grid {
        display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
        gap: 10px; max-height: 300px; overflow-y: auto;
        padding: 4px; border: 2px solid #e2e8f0;
        border-radius: 14px; background: #f8fafc;
    }
    .members-grid::-webkit-scrollbar { width: 6px; }
    .members-grid::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 3px; }

    .member-option {
        display: flex; align-items: center; gap: 10px;
        padding: 10px 12px; background: #ffffff;
        border: 2px solid #e2e8f0; border-radius: 12px;
        cursor: pointer; transition: border-color 0.15s ease, background-color 0.15s ease;
        user-select: none; position: relative;
    }
    .member-option:hover { border-color: #cbd5e1; }
    .member-option.checked {
        border-color: var(--accent);
        background: linear-gradient(135deg, rgba(37, 99, 235, 0.06), rgba(37, 99, 235, 0.02));
    }
    .member-option.disabled { opacity: 0.6; cursor: not-allowed; }

    .member-option__check {
        width: 20px; height: 20px; border-radius: 6px;
        border: 2px solid #cbd5e1; background: #ffffff;
        display: flex; align-items: center; justify-content: center;
        color: #fff; font-size: 0.6rem; flex-shrink: 0;
        transition: background-color 0.15s ease, border-color 0.15s ease;
    }
    .member-option.checked .member-option__check { background: var(--accent); border-color: var(--accent); }

    .member-option__avatar {
        width: 36px; height: 36px; border-radius: 50%;
        background: linear-gradient(135deg, #6366f1, #4f46e5);
        color: #fff; display: flex; align-items: center; justify-content: center;
        font-weight: bold; font-size: 0.82rem; flex-shrink: 0; overflow: hidden;
    }
    .member-option__avatar img { width: 100%; height: 100%; object-fit: cover; }

    .member-option__info { flex: 1; min-width: 0; }
    .member-option__name {
        font-weight: bold; font-size: 0.82rem; color: #1e293b;
        white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    }
    .member-option__mobile { font-size: 0.68rem; color: #94a3b8; direction: ltr; text-align: right; }

    .member-option__pending-badge {
        position: absolute;
        top: 4px; left: 4px;
        background: #fef3c7; color: #92400e;
        font-size: 0.6rem; font-weight: bold;
        padding: 2px 6px; border-radius: 5px;
        border: 1px solid #fde68a;
        display: inline-flex; align-items: center; gap: 3px;
    }

    .no-colleagues-note {
        padding: 24px 16px; text-align: center; color: #94a3b8;
        font-size: 0.82rem; background: #ffffff; border-radius: 10px;
    }
    .no-colleagues-note a {
        color: var(--accent); text-decoration: none; font-weight: bold;
        display: inline-flex; align-items: center; gap: 5px; margin-top: 8px;
    }

    .current-members-list {
        display: flex;
        flex-direction: column;
        gap: 10px;
        margin-bottom: 16px;
    }

    .current-member-row {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 12px 14px;
        background: #ffffff;
        border: 2px solid #e2e8f0;
        border-radius: 14px;
        transition: border-color 0.15s ease;
    }
    .current-member-row:hover { border-color: #cbd5e1; }
    .current-member-row.is-creator {
        background: linear-gradient(135deg, #fffbeb, #fef3c7);
        border-color: #fde68a;
    }
    .current-member-row.is-pending {
        background: linear-gradient(135deg, #fffbeb, #fef3c7);
        border-color: #fcd34d;
        border-style: dashed;
    }

    .current-member-row__avatar {
        width: 44px; height: 44px; border-radius: 50%;
        background: linear-gradient(135deg, #6366f1, #4f46e5);
        color: #fff; display: flex; align-items: center; justify-content: center;
        font-weight: bold; font-size: 0.95rem; flex-shrink: 0; overflow: hidden;
    }
    .current-member-row__avatar img { width: 100%; height: 100%; object-fit: cover; }
    .current-member-row.is-creator .current-member-row__avatar { box-shadow: 0 0 0 2px #f59e0b; }
    .current-member-row.is-pending .current-member-row__avatar { box-shadow: 0 0 0 2px #f59e0b; opacity: 0.75; }

    .current-member-row__info { flex: 1; min-width: 0; }
    .current-member-row__name {
        font-weight: bold; font-size: 0.88rem; color: #1e293b;
        display: flex; align-items: center; gap: 6px;
        white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    }
    .current-member-row__name .crown-icon { color: #f59e0b; font-size: 0.9rem; }
    .current-member-row__name .pending-icon { color: #d97706; font-size: 0.85rem; }
    .current-member-row__mobile { font-size: 0.72rem; color: #94a3b8; direction: ltr; text-align: right; margin-top: 2px; }

    .current-member-row__actions { display: flex; gap: 6px; align-items: center; flex-shrink: 0; }

    .member-action-btn {
        width: 34px; height: 34px; border-radius: 9px; border: 1px solid;
        display: inline-flex; align-items: center; justify-content: center;
        cursor: pointer; transition: background-color 0.15s ease, color 0.15s ease, border-color 0.15s ease;
        font-size: 0.85rem; background: none; padding: 0;
    }

    .member-action-btn.info { background: #eff6ff; color: #2563eb; border-color: #dbeafe; }
    .member-action-btn.info:hover { background: #2563eb; color: #fff; border-color: #2563eb; }

    .member-action-btn.key { background: #eef2ff; color: #4f46e5; border-color: #e0e7ff; }
    .member-action-btn.key:hover { background: #4f46e5; color: #fff; border-color: #4f46e5; }
    .member-action-btn.key:disabled {
        opacity: 0.4; cursor: not-allowed;
        background: #f1f5f9; color: #94a3b8; border-color: #e2e8f0;
    }

    .member-action-btn.crown {
        background: #fef3c7; color: #f59e0b; border-color: #fde68a; cursor: default;
    }

    .member-action-btn.pending {
        background: #fef3c7; color: #92400e; border-color: #fde68a; cursor: default;
    }

    .member-details-list { display: flex; flex-direction: column; gap: 12px; }
    .member-details-row {
        display: flex; align-items: center; gap: 12px;
        padding: 12px 14px; background: #f8fafc;
        border: 1px solid #e2e8f0; border-radius: 12px;
    }
    .member-details-row__icon {
        width: 36px; height: 36px; border-radius: 9px;
        background: #e0f2fe; color: #0284c7;
        display: flex; align-items: center; justify-content: center;
        font-size: 0.9rem; flex-shrink: 0;
    }
    .member-details-row__body { flex: 1; min-width: 0; }
    .member-details-row__label { font-size: 0.72rem; color: #94a3b8; margin-bottom: 3px; }
    .member-details-row__value { font-weight: bold; font-size: 0.88rem; color: #1e293b; word-break: break-word; }
    .member-details-row__value.ltr { direction: ltr; text-align: right; }

    .member-permissions-list { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 6px; }
    .member-permission-chip {
        background: #eef2ff; color: #4f46e5; border: 1px solid #e0e7ff;
        font-size: 0.7rem; font-weight: bold; padding: 4px 10px; border-radius: 8px;
        display: inline-flex; align-items: center; gap: 5px;
    }
    .member-permission-chip.creator-chip { background: #fef3c7; color: #92400e; border-color: #fde68a; }
    .member-permission-chip.empty { background: #f1f5f9; color: #64748b; border-color: #e2e8f0; }
    .member-permission-chip.pending-chip { background: #fef3c7; color: #92400e; border-color: #fde68a; }
    .member-permission-chip.analytics-chip { background: #f3e8ff; color: #7c3aed; border-color: #ddd6fe; }

    .permissions-grid { display: flex; flex-direction: column; gap: 10px; }

    .permission-item {
        display: flex; align-items: flex-start; gap: 12px;
        padding: 14px 16px; background: #f8fafc;
        border: 2px solid #e2e8f0; border-radius: 14px;
        cursor: pointer; transition: border-color 0.15s ease, background-color 0.15s ease;
        user-select: none;
    }
    .permission-item:hover { border-color: #cbd5e1; background: #ffffff; }
    .permission-item.checked {
        border-color: var(--accent);
        background: linear-gradient(135deg, rgba(37, 99, 235, 0.06), rgba(37, 99, 235, 0.02));
    }
    .permission-item.disabled { opacity: 0.55; cursor: not-allowed; }

    .permission-item__check {
        width: 22px; height: 22px; border-radius: 7px;
        border: 2px solid #cbd5e1; background: #ffffff;
        display: flex; align-items: center; justify-content: center;
        color: #fff; font-size: 0.65rem; flex-shrink: 0;
        margin-top: 2px; transition: background-color 0.15s ease, border-color 0.15s ease;
    }
    .permission-item.checked .permission-item__check { background: var(--accent); border-color: var(--accent); }

    .permission-item__body { flex: 1; min-width: 0; }
    .permission-item__title {
        font-weight: bold; font-size: 0.9rem; color: #1e293b;
        margin-bottom: 3px; display: flex; align-items: center; gap: 7px;
    }
    .permission-item__title i { color: var(--accent); font-size: 0.85rem; }
    .permission-item__desc { font-size: 0.75rem; color: #64748b; line-height: 1.6; }

    .permission-user-header {
        display: flex; align-items: center; gap: 12px;
        padding: 14px; background: #eff6ff;
        border: 1px solid #dbeafe; border-radius: 14px; margin-bottom: 18px;
    }
    .permission-user-header__avatar {
        width: 46px; height: 46px; border-radius: 50%;
        background: linear-gradient(135deg, #6366f1, #4f46e5);
        color: #fff; display: flex; align-items: center; justify-content: center;
        font-weight: bold; font-size: 1rem; flex-shrink: 0; overflow: hidden;
    }
    .permission-user-header__avatar img { width: 100%; height: 100%; object-fit: cover; }
    .permission-user-header__info { flex: 1; min-width: 0; }
    .permission-user-header__name { font-weight: bold; font-size: 0.95rem; color: #1e293b; margin-bottom: 2px; }
    .permission-user-header__mobile { font-size: 0.75rem; color: #64748b; direction: ltr; text-align: right; }

    .permission-note {
        padding: 12px 14px; border-radius: 12px;
        font-size: 0.8rem; font-weight: bold;
        display: flex; align-items: center; gap: 8px; margin-bottom: 14px;
    }
    .permission-note.warning { background: #fef3c7; border: 1px solid #fde68a; color: #92400e; }
    .permission-note.info { background: #eff6ff; border: 1px solid #dbeafe; color: #1e40af; }
    .permission-note.analytics { background: #f3e8ff; border: 1px solid #ddd6fe; color: #6d28d9; }

    .hamburger-btn {
        display: none;
        width: 44px; height: 44px;
        border: none;
        background: linear-gradient(135deg, #4c8bf5 0%, #2f6bdc 100%);
        border-radius: 13px;
        cursor: pointer;
        flex-shrink: 0;
        align-items: center;
        justify-content: center;
        padding: 0;
        transition: transform 0.15s ease, box-shadow 0.2s ease;
        box-shadow: 0 6px 16px rgba(76, 139, 245, 0.25);
    }
    .hamburger-btn:hover { transform: translateY(-1px); box-shadow: 0 8px 20px rgba(76, 139, 245, 0.35); }
    .hamburger-btn:active { transform: scale(0.95); }
    .hamburger-btn .hamburger-lines {
        width: 22px; height: 16px;
        display: flex; flex-direction: column; justify-content: space-between;
    }
    .hamburger-btn .hamburger-lines span {
        display: block;
        height: 2.5px;
        width: 100%;
        background: #fff;
        border-radius: 3px;
        transition: transform 0.3s, opacity 0.2s;
        transform-origin: center;
    }
    .hamburger-btn .hamburger-lines span:nth-child(2) { width: 70%; }
    .hamburger-btn.active .hamburger-lines span:nth-child(1) { transform: translateY(6.75px) rotate(45deg); }
    .hamburger-btn.active .hamburger-lines span:nth-child(2) { opacity: 0; transform: translateX(-10px); }
    .hamburger-btn.active .hamburger-lines span:nth-child(3) { transform: translateY(-6.75px) rotate(-45deg); }

    @media (max-width: 900px) {
        .hamburger-btn { display: flex; }

        body .sidebar {
            position: fixed !important;
            top: 0 !important;
            right: 0 !important;
            bottom: 0 !important;
            left: auto !important;

            width: 280px !important;
            min-width: 280px !important;
            max-width: 85vw !important;

            height: 100vh !important;
            height: 100dvh !important;
            max-height: 100vh !important;
            max-height: 100dvh !important;

            transform: translateX(105%) !important;
            align-self: auto !important;

            transition: transform 0.35s cubic-bezier(0.65, 0, 0.35, 1) !important;
            will-change: transform;

            z-index: 999 !important;
            box-shadow: -20px 0 50px rgba(0, 0, 0, 0.25) !important;

            padding-top: env(safe-area-inset-top) !important;
            padding-bottom: env(safe-area-inset-bottom) !important;
        }
        body .sidebar.open { transform: translateX(0) !important; }
        body .sidebar.is-collapsed { width: 280px !important; min-width: 280px !important; }

        .main-wrapper { width: 100%; }

        .topbar {
            padding: 12px 16px;
            padding-top: max(12px, env(safe-area-inset-top));
            gap: 12px;
            flex-wrap: nowrap;
        }
        .topbar h1 { font-size: 0.95rem; flex: 1; margin: 0; text-align: center; justify-content: center; }
        .topbar h1 i { display: none; }
        .topbar-user { font-size: 0.78rem !important; max-width: 110px; }

        .content-area { padding: 14px; }
        .card { padding: 16px; border-radius: 16px; }
    }

    @media (max-width: 700px) {
        .content-area { padding: 12px; }
        .topbar { padding: 10px 14px; padding-top: max(10px, env(safe-area-inset-top)); }
        .topbar h1 { font-size: 0.88rem; }
        .topbar-user { display: none; }

        .hamburger-btn { width: 40px; height: 40px; border-radius: 11px; }
        .hamburger-btn .hamburger-lines { width: 20px; height: 14px; }
        .hamburger-btn .hamburger-lines span { height: 2.2px; }
        .hamburger-btn.active .hamburger-lines span:nth-child(1) { transform: translateY(5.9px) rotate(45deg); }
        .hamburger-btn.active .hamburger-lines span:nth-child(3) { transform: translateY(-5.9px) rotate(-45deg); }

        .card { padding: 14px; border-radius: 14px; }

        .analytics-grid { grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 18px; }
        .analytic-card {
            padding: 14px;
            flex-direction: column;
            align-items: flex-start;
            gap: 8px;
            position: relative;
        }
        .analytic-card .info div:first-child { font-size: 0.72rem; }
        .analytic-card .info div:last-child { font-size: 1.15rem; }
        .analytic-icon {
            width: 34px; height: 34px;
            font-size: 0.85rem;
            align-self: flex-end;
            position: absolute;
            top: 12px; left: 12px;
        }

        .projects-grid { grid-template-columns: 1fr; }
        .pending-requests-grid { grid-template-columns: 1fr; }

        .modal-overlay { padding: 10px; align-items: flex-start; padding-top: 20px; }
        .modal-box {
            max-width: 100%;
            padding: 20px;
            border-radius: 18px;
            max-height: calc(100dvh - 40px);
        }
        .modal-header h3 { font-size: 1rem; }
        .modal-close { width: 28px; height: 28px; font-size: 1rem; }

        input[type="text"], input[type="number"], textarea, select {
            padding: 10px 14px;
            font-size: 0.9rem;
            border-radius: 12px;
        }
        label { font-size: 0.85rem; margin-bottom: 6px; }
        button.btn { padding: 10px 18px; font-size: 0.88rem; }

        .members-grid { grid-template-columns: 1fr; max-height: 260px; }

        .project-card__footer { flex-direction: column; align-items: stretch; gap: 10px; }
        .project-card__actions { width: 100%; justify-content: flex-end; }
        .project-card__task-stats { gap: 6px; margin-top: 12px; padding-top: 12px; }
        .project-card__task-stat { padding: 8px 4px; gap: 4px; border-radius: 10px; }
        .project-card__task-stat-icon { width: 26px; height: 26px; font-size: 0.72rem; border-radius: 8px; }
        .project-card__task-stat-label { font-size: 0.62rem; }
        .project-card__task-stat-value { font-size: 0.95rem; }

        .projects-tab { min-width: 100px; padding: 10px 10px; font-size: 0.78rem; }
        .projects-tab .tab-count { font-size: 0.65rem; padding: 2px 6px; }

        .project-image-upload { flex-direction: column; align-items: stretch; text-align: center; }
        .project-image-preview { margin: 0 auto; }
    }

    @media (max-width: 400px) {
        .analytics-grid { grid-template-columns: 1fr; }
        .analytic-card { flex-direction: row; align-items: center; }
        .analytic-icon { position: static; align-self: center; }

        .project-card__actions { flex-direction: column; align-items: stretch; }
        .project-card__analytics,
        .project-card__edit,
        .project-card__delete,
        .project-card__leave,
        .project-card__block,
        .project-card__unblock {
            justify-content: center;
        }
        .pending-request-card__actions { flex-direction: column; }
    }

    @media (prefers-reduced-motion: reduce) {
        .sidebar,
        .sidebar-overlay,
        .hamburger-btn,
        .hamburger-btn .hamburger-lines span,
        .project-card,
        .pending-requests-badge {
            transition: none !important;
            animation: none !important;
        }
    }

    body, .topbar, .card, .project-card, .pending-request-card, .current-member-row,
    .permission-item, .member-option, .member-details-row, .permission-user-header,
    button, a, .project-card__info, .project-card__body, .project-card__description,
    .project-card__stats, .member-avatar-stack, .permission-note, .alert,
    .pending-request-card__info, .pending-request-card__inviter,
    .pending-request-card__meta, .modal-header h3, label {
        -webkit-user-select: none;
        -moz-user-select: none;
        -ms-user-select: none;
        user-select: none;
    }
    input, textarea, select {
        -webkit-user-select: text;
        -moz-user-select: text;
        -ms-user-select: text;
        user-select: text;
    }
    img, a {
        -webkit-user-drag: none;
        -khtml-user-drag: none;
        -moz-user-drag: none;
        -o-user-drag: none;
        user-drag: none;
    }
</style>
</head>
<body>

<div class="sidebar-overlay" id="sidebarOverlay" onclick="closeSidebar()"></div>

<?php sidebar(); ?>

<div class="main-wrapper">
    <div class="topbar">
        <button class="hamburger-btn" id="hamburgerBtn" onclick="toggleSidebar()" aria-label="باز کردن منو">
            <div class="hamburger-lines">
                <span></span>
                <span></span>
                <span></span>
            </div>
        </button>
        <h1><i class="fas fa-diagram-project" style="color: var(--accent); margin-left: 8px;"></i>مدیریت پروژه‌ها</h1>
        <div class="topbar-user" style="font-size: 0.9rem; font-weight: bold;">کاربر: <?= htmlspecialchars($displayUser) ?></div>
    </div>

    <div class="content-area">

        <?php if (!empty($msg)): ?>
            <div class="alert alert-<?= htmlspecialchars($msgType) ?>">
                <i class="fas fa-<?= $msgType === 'danger' ? 'exclamation-circle' : ($msgType === 'warning' ? 'exclamation-triangle' : 'check-circle') ?>"></i>
                <?= htmlspecialchars($msg) ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($pendingRequestsList)): ?>
            <div class="pending-requests-section">
                <div class="pending-requests-header">
                    <h3>
                        <i class="fas fa-bell"></i>
                        درخواست‌های عضویت در انتظار تایید شما
                    </h3>
                    <span class="pending-requests-badge">
                        <i class="fas fa-exclamation-circle" style="margin-left: 4px;"></i>
                        <?= $totalPendingRequests ?> درخواست
                    </span>
                </div>

                <div class="pending-requests-grid">
                    <?php foreach ($pendingRequestsList as $pr): ?>
                        <?php
                            $pInitial = mb_substr(trim($pr['title'] ?? '?'), 0, 1, 'UTF-8');
                        ?>
                        <div class="pending-request-card">
                            <div class="pending-request-card__top">
                                <div class="pending-request-card__avatar">
                                    <?php if ($pr['profile_image_url']): ?>
                                        <img src="<?= htmlspecialchars($pr['profile_image_url']) ?>" alt="">
                                    <?php else: ?>
                                        <?= htmlspecialchars($pInitial) ?>
                                    <?php endif; ?>
                                </div>
                                <div class="pending-request-card__info">
                                    <div class="pending-request-card__title" title="<?= htmlspecialchars($pr['title']) ?>">
                                        <?= htmlspecialchars($pr['title']) ?>
                                    </div>
                                    <div class="pending-request-card__meta">
                                        <i class="far fa-clock"></i>
                                        <?= htmlspecialchars($pr['requested_at']) ?>
                                    </div>
                                </div>
                            </div>

                            <?php if (!empty($pr['description'])): ?>
                                <div style="font-size: 0.78rem; color: #7f1d1d; line-height: 1.6; padding: 8px 10px; background: #fff1f2; border-radius: 8px;">
                                    <?= nl2br(htmlspecialchars(mb_substr($pr['description'], 0, 140, 'UTF-8'))) ?><?= mb_strlen($pr['description']) > 140 ? '...' : '' ?>
                                </div>
                            <?php endif; ?>

                            <div class="pending-request-card__inviter">
                                <div class="pending-request-card__inviter-avatar">
                                    <?php if ($pr['creator_avatar_url']): ?>
                                        <img src="<?= htmlspecialchars($pr['creator_avatar_url']) ?>" alt="">
                                    <?php else: ?>
                                        <?= htmlspecialchars($pr['creator_initial']) ?>
                                    <?php endif; ?>
                                </div>
                                <div>
                                    دعوت‌کننده:
                                    <span class="pending-request-card__inviter-name">
                                        <?= htmlspecialchars($pr['creator_name']) ?>
                                    </span>
                                </div>
                            </div>

                            <div class="pending-request-card__actions">
                                <form method="POST" style="flex: 1; margin: 0;" onsubmit="return confirm('آیا از تایید عضویت در این پروژه مطمئن هستید؟');">
                                    <input type="hidden" name="respond_join_request" value="1">
                                    <input type="hidden" name="request_id" value="<?= (int)$pr['request_id'] ?>">
                                    <input type="hidden" name="action" value="accept">
                                    <button type="submit" class="btn-accept" style="width: 100%;">
                                        <i class="fas fa-check"></i>
                                        تایید
                                    </button>
                                </form>
                                <form method="POST" style="flex: 1; margin: 0;" onsubmit="return confirm('آیا از رد این درخواست مطمئن هستید؟ این پروژه به «پروژه‌های سابق» منتقل می‌شود.');">
                                    <input type="hidden" name="respond_join_request" value="1">
                                    <input type="hidden" name="request_id" value="<?= (int)$pr['request_id'] ?>">
                                    <input type="hidden" name="action" value="reject">
                                    <button type="submit" class="btn-reject" style="width: 100%;">
                                        <i class="fas fa-times"></i>
                                        رد
                                    </button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <div class="analytics-grid">
            <div class="analytic-card">
                <div class="info">
                    <div>پروژه‌های فعال من</div>
                    <div><?= $totalProjects ?></div>
                </div>
                <div class="analytic-icon"><i class="fas fa-diagram-project"></i></div>
            </div>
            <div class="analytic-card">
                <div class="info">
                    <div>کل اعضای پروژه‌ها</div>
                    <div style="color: #6366f1;"><?= $totalMembersAll ?></div>
                </div>
                <div class="analytic-icon" style="background:#e0e7ff; color:#4f46e5;"><i class="fas fa-users"></i></div>
            </div>
            <div class="analytic-card">
                <div class="info">
                    <div>در انتظار تایید</div>
                    <div style="color: #dc2626;"><?= $totalPendingRequests ?></div>
                </div>
                <div class="analytic-icon" style="background:#fee2e2; color:#dc2626;"><i class="fas fa-bell"></i></div>
            </div>
            <div class="analytic-card">
                <div class="info">
                    <div>پروژه‌های مسدودشده</div>
                    <div style="color: #ea580c;"><?= $totalBlocked ?></div>
                </div>
                <div class="analytic-icon" style="background:#ffedd5; color:#ea580c;"><i class="fas fa-ban"></i></div>
            </div>
            <div class="analytic-card">
                <div class="info">
                    <div>پروژه‌های سابق</div>
                    <div style="color: #64748b;"><?= $totalLeft ?></div>
                </div>
                <div class="analytic-icon" style="background:#f1f5f9; color:#64748b;"><i class="fas fa-history"></i></div>
            </div>
        </div>

        <div class="card">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; padding-bottom: 15px; border-bottom: 2px solid #f1f5f9; flex-wrap: wrap; gap: 10px;">
                <h3 style="margin: 0; font-size: 1.05rem; color: var(--primary); display: flex; align-items: center; gap: 8px;">
                    <i class="fas fa-folder-open" style="color: var(--accent);"></i>
                    مدیریت پروژه‌ها
                </h3>
                <button type="button" class="btn" onclick="openCreateModal()">
                    <i class="fas fa-plus" style="margin-left: 5px;"></i>
                    ایجاد پروژه جدید
                </button>
            </div>

            <div class="projects-tabs" role="tablist">
                <button type="button" class="projects-tab active" data-tab="active" onclick="switchProjectsTab('active')">
                    <i class="fas fa-diagram-project"></i>
                    پروژه‌های من
                    <span class="tab-count"><?= $totalProjects ?></span>
                </button>
                <button type="button" class="projects-tab" data-tab="blocked" onclick="switchProjectsTab('blocked')">
                    <i class="fas fa-ban"></i>
                    مسدودشده
                    <span class="tab-count"><?= $totalBlocked ?></span>
                </button>
                <button type="button" class="projects-tab" data-tab="left" onclick="switchProjectsTab('left')">
                    <i class="fas fa-history"></i>
                    پروژه‌های سابق
                    <span class="tab-count"><?= $totalLeft ?></span>
                </button>
            </div>

            <div class="projects-panel active" id="panel-active">
                <?php if (empty($projectsList)): ?>
                    <div class="empty-state">
                        <i class="fas fa-diagram-project"></i>
                        <div style="font-size: 1rem; font-weight: bold; color: #64748b; margin-bottom: 6px;">هنوز پروژه‌ای ایجاد نکرده‌اید</div>
                        <div style="font-size: 0.85rem;">با کلیک روی «ایجاد پروژه جدید»، اولین پروژه خود را بسازید.</div>
                    </div>
                <?php else: ?>
                    <div class="projects-grid">
                        <?php foreach ($projectsList as $proj): ?>
                            <?php
                                $initial = mb_substr(trim($proj['title']), 0, 1, 'UTF-8');
                                $members = $proj['members'];
                                $memberCount = count($members);
                                $maxMembers = (int)$proj['max_members'];
                                $isFull = $memberCount >= $maxMembers;

                                $shownMembers = array_slice($members, 0, 5);
                                $extraCount = max(0, $memberCount - 5);

                                $canEditThis   = !empty($proj['can_edit_project']);
                                $canDeleteThis = !empty($proj['can_delete']);
                                $canLeaveThis  = !empty($proj['can_leave']);
                                $canBlockThis  = !empty($proj['can_block']);
                                $canAnalytics  = !empty($proj['can_view_analytics']); // ✅ جدید
                                $isCreator     = !empty($proj['is_creator']);
                                $hasAnyPerm    = !empty($proj['my_permissions']);

                                $tStats = $proj['task_stats'] ?? ['total' => 0, 'pending' => 0, 'completed' => 0];
                                $totalT = (int)$tStats['total'];
                                $pendingT = (int)$tStats['pending'];
                                $completedT = (int)$tStats['completed'];
                            ?>
                            <div class="project-card">
                                <div class="project-card__header">
                                    <?php if ($isCreator): ?>
                                        <span class="project-card__badge-role creator">
                                            <i class="fas fa-crown"></i> سازنده
                                        </span>
                                    <?php elseif ($hasAnyPerm): ?>
                                        <span class="project-card__badge-role member">
                                            <i class="fas fa-user-check"></i> عضو
                                        </span>
                                    <?php else: ?>
                                        <span class="project-card__badge-role viewer">
                                            <i class="fas fa-eye"></i> بازدیدکننده
                                        </span>
                                    <?php endif; ?>

                                    <div class="project-card__avatar">
                                        <?php if ($proj['profile_image_url']): ?>
                                            <img src="<?= htmlspecialchars($proj['profile_image_url']) ?>" alt="<?= htmlspecialchars($proj['title']) ?>">
                                        <?php else: ?>
                                            <?= htmlspecialchars($initial) ?>
                                        <?php endif; ?>
                                    </div>
                                    <div class="project-card__info">
                                        <div class="project-card__title" title="<?= htmlspecialchars($proj['title']) ?>">
                                            <?= htmlspecialchars($proj['title']) ?>
                                        </div>
                                        <div class="project-card__date">
                                            <i class="far fa-calendar"></i>
                                            <?= htmlspecialchars($proj['created_at']) ?>
                                        </div>
                                    </div>
                                </div>

                                <div class="project-card__body">
                                    <?php if (!empty($proj['description'])): ?>
                                        <div class="project-card__description">
                                            <?= nl2br(htmlspecialchars($proj['description'])) ?>
                                        </div>
                                    <?php else: ?>
                                        <div class="project-card__description" style="font-style: italic; color: #cbd5e1;">بدون توضیحات</div>
                                    <?php endif; ?>

                                    <?php if (!empty($members)): ?>
                                        <div class="project-card__members" style="padding: 0; margin-top: 10px;">
                                            <div class="member-avatars-stack">
                                                <?php foreach (array_reverse($shownMembers) as $m): ?>
                                                    <div class="member-avatar-stack <?= $m['is_creator'] ? 'is-creator' : '' ?>"
                                                         title="<?= htmlspecialchars($m['full_name']) ?><?= $m['is_creator'] ? ' (سازنده)' : '' ?>">
                                                        <?php if ($m['avatar_url']): ?>
                                                            <img src="<?= htmlspecialchars($m['avatar_url']) ?>" alt="">
                                                        <?php else: ?>
                                                            <?= htmlspecialchars($m['initial']) ?>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php endforeach; ?>
                                                <?php if ($extraCount > 0): ?>
                                                    <div class="member-more">+<?= $extraCount ?></div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>

                                    <div class="project-card__stats">
                                        <div class="project-card__stat">
                                            <i class="fas fa-users"></i>
                                            <span><?= $memberCount ?> عضو</span>
                                        </div>
                                        <div class="project-card__stat">
                                            <i class="fas fa-user-friends"></i>
                                            <span>حداکثر <?= $maxMembers ?> نفر</span>
                                        </div>
                                        <?php if ($isFull): ?>
                                            <div class="project-card__stat" style="color: #dc2626;">
                                                <i class="fas fa-check-circle"></i>
                                                <span>تکمیل</span>
                                            </div>
                                        <?php endif; ?>
                                    </div>

                                    <div class="project-card__task-stats">
                                        <div class="project-card__task-stat total">
                                            <div class="project-card__task-stat-icon"><i class="fas fa-list-check"></i></div>
                                            <div class="project-card__task-stat-info">
                                                <div class="project-card__task-stat-label">کل وظایف</div>
                                                <div class="project-card__task-stat-value"><?= $totalT ?></div>
                                            </div>
                                        </div>
                                        <div class="project-card__task-stat pending">
                                            <div class="project-card__task-stat-icon"><i class="fas fa-clock"></i></div>
                                            <div class="project-card__task-stat-info">
                                                <div class="project-card__task-stat-label">انجام نشده</div>
                                                <div class="project-card__task-stat-value"><?= $pendingT ?></div>
                                            </div>
                                        </div>
                                        <div class="project-card__task-stat completed">
                                            <div class="project-card__task-stat-icon"><i class="fas fa-check-circle"></i></div>
                                            <div class="project-card__task-stat-info">
                                                <div class="project-card__task-stat-label">انجام شده</div>
                                                <div class="project-card__task-stat-value"><?= $completedT ?></div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="project-card__footer">
                                    <div class="project-card__max">
                                        <i class="fas fa-user-check"></i>
                                        ظرفیت: <strong><?= $memberCount ?>/<?= $maxMembers ?></strong>
                                    </div>

                                    <div class="project-card__actions">
                                        <!-- ✅ دکمه تحلیل - برای سازنده و اعضای دارای مجوز -->
                                        <?php if ($canAnalytics): ?>
                                            <a href="../analytics/index.php?project_id=<?= (int)$proj['id'] ?>"
                                               class="project-card__analytics"
                                               title="مشاهده تحلیل‌ها و آمار کامل پروژه">
                                                <i class="fas fa-chart-line"></i>
                                                تحلیل
                                            </a>
                                        <?php endif; ?>

                                        <?php if ($canEditThis): ?>
                                            <button type="button" class="project-card__edit"
                                                    onclick="openEditModal(<?= (int)$proj['id'] ?>)" title="ویرایش پروژه">
                                                <i class="fas fa-pen"></i>
                                                ویرایش
                                            </button>
                                        <?php endif; ?>

                                        <?php if ($canLeaveThis): ?>
                                            <form method="POST" onsubmit="return confirm('آیا مطمئن هستید که می‌خواهید از این پروژه خارج شوید؟ این پروژه به بخش «پروژه‌های سابق» منتقل می‌شود.');" style="margin: 0;">
                                                <input type="hidden" name="project_id" value="<?= (int)$proj['id'] ?>">
                                                <button type="submit" name="leave_project" class="project-card__leave" title="خروج از پروژه">
                                                    <i class="fas fa-sign-out-alt"></i>
                                                    خروج
                                                </button>
                                            </form>
                                        <?php endif; ?>

                                        <?php if ($canBlockThis): ?>
                                            <form method="POST" onsubmit="return confirm('آیا مطمئن هستید که می‌خواهید این پروژه را مسدود کنید؟ پروژه از لیست شما مخفی می‌شود و می‌توانید از بخش «مسدودشده» رفع مسدودی کنید.');" style="margin: 0;">
                                                <input type="hidden" name="project_id" value="<?= (int)$proj['id'] ?>">
                                                <button type="submit" name="block_project" class="project-card__block" title="مسدود کردن پروژه">
                                                    <i class="fas fa-ban"></i>
                                                    مسدود
                                                </button>
                                            </form>
                                        <?php endif; ?>

                                        <?php if ($canDeleteThis): ?>
                                            <form method="POST" onsubmit="return confirm('آیا مطمئن هستید که این پروژه و تمام اعضای آن حذف شوند؟');" style="margin: 0;">
                                                <input type="hidden" name="project_id" value="<?= (int)$proj['id'] ?>">
                                                <button type="submit" name="delete_project" class="project-card__delete" title="حذف پروژه">
                                                    <i class="fas fa-trash-alt"></i>
                                                    حذف
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="projects-panel" id="panel-blocked">
                <?php if (empty($blockedProjectsList)): ?>
                    <div class="empty-state">
                        <i class="fas fa-ban"></i>
                        <div style="font-size: 1rem; font-weight: bold; color: #64748b; margin-bottom: 6px;">هیچ پروژه‌ای را مسدود نکرده‌اید</div>
                        <div style="font-size: 0.85rem;">پروژه‌هایی که مسدود می‌کنید، اینجا نمایش داده می‌شوند.</div>
                    </div>
                <?php else: ?>
                    <div class="projects-grid">
                        <?php foreach ($blockedProjectsList as $bp): ?>
                            <?php
                                $bInitial = mb_substr(trim($bp['title']), 0, 1, 'UTF-8');
                            ?>
                            <div class="project-card is-blocked">
                                <div class="project-card__header">
                                    <span class="project-card__badge-role blocked">
                                        <i class="fas fa-ban"></i> مسدود شده
                                    </span>
                                    <div class="project-card__avatar">
                                        <?php if ($bp['profile_image_url']): ?>
                                            <img src="<?= htmlspecialchars($bp['profile_image_url']) ?>" alt="<?= htmlspecialchars($bp['title']) ?>">
                                        <?php else: ?>
                                            <?= htmlspecialchars($bInitial) ?>
                                        <?php endif; ?>
                                    </div>
                                    <div class="project-card__info">
                                        <div class="project-card__title" title="<?= htmlspecialchars($bp['title']) ?>">
                                            <?= htmlspecialchars($bp['title']) ?>
                                        </div>
                                        <div class="project-card__date">
                                            <i class="fas fa-ban"></i>
                                            مسدود شده در: <?= htmlspecialchars($bp['blocked_at']) ?>
                                        </div>
                                    </div>
                                </div>

                                <div class="project-card__body">
                                    <?php if (!empty($bp['description'])): ?>
                                        <div class="project-card__description">
                                            <?= nl2br(htmlspecialchars($bp['description'])) ?>
                                        </div>
                                    <?php else: ?>
                                        <div class="project-card__description" style="font-style: italic; color: #cbd5e1;">بدون توضیحات</div>
                                    <?php endif; ?>

                                    <div class="project-card__stats">
                                        <div class="project-card__stat">
                                            <i class="fas fa-users"></i>
                                            <span><?= (int)$bp['members_count'] ?> عضو</span>
                                        </div>
                                        <div class="project-card__stat">
                                            <i class="fas fa-user-friends"></i>
                                            <span>حداکثر <?= (int)$bp['max_members'] ?> نفر</span>
                                        </div>
                                    </div>
                                </div>

                                <div class="project-card__footer">
                                    <div class="project-card__max">
                                        <i class="fas fa-ban"></i>
                                        <span style="color: #ea580c; font-weight: bold;">مسدود</span>
                                    </div>
                                    <div class="project-card__actions">
                                        <form method="POST" style="margin: 0;">
                                            <input type="hidden" name="project_id" value="<?= (int)$bp['id'] ?>">
                                            <button type="submit" name="unblock_project" class="project-card__unblock" title="رفع مسدودی">
                                                <i class="fas fa-unlock"></i>
                                                رفع مسدودی
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="projects-panel" id="panel-left">
                <?php if (empty($leftProjectsList)): ?>
                    <div class="empty-state">
                        <i class="fas fa-history"></i>
                        <div style="font-size: 1rem; font-weight: bold; color: #64748b; margin-bottom: 6px;">هنوز از هیچ پروژه‌ای خارج نشده‌اید</div>
                        <div style="font-size: 0.85rem;">پروژه‌هایی که از آن‌ها خارج می‌شوید، اینجا برای مراجعه بعدی نمایش داده می‌شوند.</div>
                    </div>
                <?php else: ?>
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px; flex-wrap: wrap; gap: 10px;">
                        <div style="font-size: 0.82rem; color: #64748b;">
                            <i class="fas fa-info-circle" style="color: var(--accent); margin-left: 5px;"></i>
                            لیست پروژه‌هایی که قبلاً از آن‌ها خارج شده یا درخواست عضویتشان را رد کرده‌اید.
                        </div>
                        <form method="POST" onsubmit="return confirm('آیا مطمئن هستید که می‌خواهید کل تاریخچه پروژه‌های سابق را پاک کنید؟');" style="margin: 0;">
                            <button type="submit" name="clear_leave_history" class="btn btn-sm btn-ghost" style="color: #dc2626; border: 1px solid #fee2e2;">
                                <i class="fas fa-trash" style="margin-left: 4px;"></i>
                                پاک کردن تاریخچه
                            </button>
                        </form>
                    </div>

                    <div class="projects-grid">
                        <?php foreach ($leftProjectsList as $lp): ?>
                            <?php
                                $lInitial = mb_substr(trim($lp['project_title'] ?? '?'), 0, 1, 'UTF-8');
                            ?>
                            <div class="project-card is-left">
                                <div class="project-card__header">
                                    <?php if (!empty($lp['is_member_again'])): ?>
                                        <span class="project-card__badge-role rejoined">
                                            <i class="fas fa-redo"></i> دوباره عضو شده‌اید
                                        </span>
                                    <?php else: ?>
                                        <span class="project-card__badge-role left">
                                            <i class="fas fa-sign-out-alt"></i> خارج شده
                                        </span>
                                    <?php endif; ?>
                                    <div class="project-card__avatar" style="background: linear-gradient(135deg, #94a3b8, #64748b);">
                                        <?php if ($lp['profile_image_url']): ?>
                                            <img src="<?= htmlspecialchars($lp['profile_image_url']) ?>" alt="<?= htmlspecialchars($lp['project_title'] ?? '') ?>">
                                        <?php else: ?>
                                            <?= htmlspecialchars($lInitial) ?>
                                        <?php endif; ?>
                                    </div>
                                    <div class="project-card__info">
                                        <div class="project-card__title" title="<?= htmlspecialchars($lp['project_title'] ?? 'پروژه بدون نام') ?>">
                                            <?= htmlspecialchars($lp['project_title'] ?? 'پروژه بدون نام') ?>
                                        </div>
                                        <div class="project-card__date">
                                            <i class="fas fa-sign-out-alt"></i>
                                            تاریخ خروج: <?= htmlspecialchars($lp['left_at']) ?>
                                        </div>
                                    </div>
                                </div>

                                <div class="project-card__body">
                                    <div style="font-size: 0.8rem; color: #64748b; line-height: 1.8; padding: 10px 12px; background: #f8fafc; border-radius: 10px; border: 1px dashed #e2e8f0;">
                                        <i class="fas fa-info-circle" style="color: #94a3b8; margin-left: 5px;"></i>
                                        <?php if (!empty($lp['is_member_again'])): ?>
                                            شما دوباره به این پروژه اضافه شده‌اید و می‌توانید از تب «پروژه‌های من» به آن دسترسی داشته باشید.
                                        <?php else: ?>
                                            شما از این پروژه خارج شده‌اید. برای عضویت مجدد باید توسط سازنده یا یکی از اعضای دارای دسترسی، دوباره اضافه شوید.
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <div class="project-card__footer">
                                    <div class="project-card__max">
                                        <i class="fas fa-history"></i>
                                        <span style="color: #64748b;">آرشیو</span>
                                    </div>
                                    <div class="project-card__actions">
                                        <?php if (!empty($lp['is_member_again'])): ?>
                                            <span style="font-size: 0.75rem; color: #16a34a; font-weight: bold;">
                                                <i class="fas fa-check-circle"></i>
                                                عضو فعال
                                            </span>
                                        <?php else: ?>
                                            <span style="font-size: 0.75rem; color: #94a3b8; font-weight: bold;">
                                                <i class="fas fa-lock"></i>
                                                غیرفعال
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- ================== MODAL: ایجاد پروژه ================== -->
<div id="createProjectModal" class="modal-overlay">
    <div class="modal-box">
        <div class="modal-header">
            <h3><i class="fas fa-diagram-project"></i> ایجاد پروژه جدید</h3>
            <button class="modal-close" onclick="closeCreateModal()">&times;</button>
        </div>

        <form method="POST" enctype="multipart/form-data" id="createProjectForm" autocomplete="off">
            <input type="hidden" name="add_project" value="1">

            <div class="form-group">
                <label>نام پروژه <span class="required-star">*</span></label>
                <input type="text" name="project_title" id="projectTitle" required maxlength="255" placeholder="مثلاً: پروژه فروشگاه آنلاین">
            </div>

            <div class="form-group">
                <label>توضیحات (اختیاری)</label>
                <textarea name="project_description" id="projectDescription" rows="3" maxlength="1000" placeholder="توضیح کوتاه درباره هدف پروژه..."></textarea>
                <div class="form-hint">حداکثر ۱۰۰۰ کاراکتر</div>
            </div>

            <div class="form-group">
                <label>حداکثر اعضای پروژه <span class="required-star">*</span></label>
                <input type="number" name="max_members" id="maxMembers" required min="1" max="100" value="10">
                <div class="form-hint">این عدد شامل خود شما هم می‌شود. بین ۱ تا ۱۰۰ نفر.</div>
            </div>

            <div class="form-group">
                <label>تصویر پروفایل پروژه (اختیاری)</label>
                <div class="project-image-upload">
                    <div class="project-image-preview" id="projectImagePreview">
                        <i class="fas fa-image"></i>
                    </div>
                    <div class="project-image-actions">
                        <p>یک تصویر برای شناسایی پروژه انتخاب کنید.</p>
                        <div class="file-input-wrapper">
                            <button type="button" class="btn btn-sm btn-ghost" style="pointer-events: none;">
                                <i class="fas fa-upload" style="margin-left: 5px;"></i>
                                انتخاب تصویر
                            </button>
                            <input type="file" name="project_image" id="projectImageInput"
                                   accept=".png,.jpg,.jpeg,.webp,.gif,image/png,image/jpeg,image/webp,image/gif"
                                   onchange="previewProjectImage(this, 'projectImagePreview')">
                        </div>
                        <div class="form-hint" style="margin-top: 6px;">فرمت‌های مجاز: JPG، PNG، WEBP، GIF | حداکثر ۵ مگابایت</div>
                    </div>
                </div>
            </div>

            <div class="form-group">
                <div class="members-selector-header">
                    <label style="margin-bottom: 0;">
                        <i class="fas fa-users" style="color: var(--accent); margin-left: 5px;"></i>
                        اعضای پروژه
                    </label>
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <span class="members-count-badge" id="createMembersCountBadge">خودم (۱ نفر)</span>
                        <?php if (!empty($colleaguesList)): ?>
                            <button type="button" class="members-select-all" onclick="toggleSelectAll('create')">
                                <i class="fas fa-check-double" style="margin-left: 4px;"></i>
                                انتخاب همه
                            </button>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="permission-note info" style="margin-bottom: 10px;">
                    <i class="fas fa-info-circle"></i>
                    <span>اگر کاربری گزینه «عضویت در پروژه نیازمند تایید» را فعال کرده باشد، به‌جای عضویت مستقیم، درخواست تایید برای او ارسال می‌شود.</span>
                </div>

                <div class="permission-note analytics" style="margin-bottom: 10px;">
                    <i class="fas fa-chart-line"></i>
                    <span>دسترسی به «تحلیل‌ها» به‌صورت پیش‌فرض فقط برای سازنده فعال است. پس از ایجاد پروژه، می‌توانید از طریق ویرایش پروژه به سایر اعضا نیز این دسترسی را بدهید.</span>
                </div>

                <?php $visibleColleagues = array_filter($colleaguesList, fn($c) => (int)$c['id'] !== $userId); ?>
                <?php if (empty($visibleColleagues)): ?>
                    <div class="members-grid">
                        <div class="no-colleagues-note" style="grid-column: 1 / -1;">
                            <i class="fas fa-user-slash" style="font-size: 1.5rem; display: block; margin-bottom: 8px; opacity: 0.5;"></i>
                            هنوز همکاری برای افزودن ندارید.
                            <br>
                            <a href="../colleagues/index.php"><i class="fas fa-user-plus"></i> افزودن همکار</a>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="members-grid" id="createMembersGrid">
                        <?php foreach ($visibleColleagues as $c): ?>
                            <label class="member-option" data-member-id="<?= (int)$c['id'] ?>">
                                <input type="checkbox" name="members[]" value="<?= (int)$c['id'] ?>"
                                       class="member-checkbox-create" style="display: none;"
                                       onchange="onMemberToggle(this, 'create')">
                                <span class="member-option__check"><i class="fas fa-check"></i></span>
                                <div class="member-option__avatar">
                                    <?php if ($c['avatar_url']): ?>
                                        <img src="<?= htmlspecialchars($c['avatar_url']) ?>" alt="">
                                    <?php else: ?>
                                        <?= htmlspecialchars($c['initial']) ?>
                                    <?php endif; ?>
                                </div>
                                <div class="member-option__info">
                                    <div class="member-option__name" title="<?= htmlspecialchars($c['full_name']) ?>">
                                        <?= htmlspecialchars($c['full_name']) ?>
                                    </div>
                                    <div class="member-option__mobile"><?= htmlspecialchars($c['mobile']) ?></div>
                                </div>
                                <?php if (!empty($c['needs_approval'])): ?>
                                    <span class="member-option__pending-badge">
                                        <i class="fas fa-user-clock"></i>
                                        نیاز به تایید
                                    </span>
                                <?php endif; ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <div style="display: flex; gap: 10px; justify-content: flex-end; margin-top: 22px; padding-top: 16px; border-top: 1px solid #f1f5f9;">
                <button type="button" class="btn btn-ghost" onclick="closeCreateModal()">
                    <i class="fas fa-times" style="margin-left: 5px;"></i>
                    انصراف
                </button>
                <button type="submit" class="btn" id="createProjectSubmitBtn">
                    <i class="fas fa-check" style="margin-left: 5px;"></i>
                    ایجاد پروژه
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ================== MODAL: ویرایش پروژه ================== -->
<div id="editProjectModal" class="modal-overlay">
    <div class="modal-box">
        <div class="modal-header">
            <h3><i class="fas fa-pen"></i> ویرایش پروژه</h3>
            <button class="modal-close" onclick="closeEditModal()">&times;</button>
        </div>

        <form method="POST" enctype="multipart/form-data" id="editProjectForm" autocomplete="off">
            <input type="hidden" name="update_project" value="1">
            <input type="hidden" name="project_id" id="editProjectId">
            <input type="hidden" name="remove_project_image" id="editRemoveImage" value="0">

            <div class="form-group">
                <label>نام پروژه <span class="required-star">*</span></label>
                <input type="text" name="project_title" id="editProjectTitle" required maxlength="255">
            </div>

            <div class="form-group">
                <label>توضیحات (اختیاری)</label>
                <textarea name="project_description" id="editProjectDescription" rows="3" maxlength="1000"></textarea>
                <div class="form-hint">حداکثر ۱۰۰۰ کاراکتر</div>
            </div>

            <div class="form-group">
                <label>حداکثر اعضای پروژه <span class="required-star">*</span></label>
                <input type="number" name="max_members" id="editMaxMembers" required min="1" max="100">
                <div class="form-hint">این عدد شامل خود شما هم می‌شود. بین ۱ تا ۱۰۰ نفر.</div>
            </div>

            <div class="form-group">
                <label>تصویر پروفایل پروژه</label>
                <div class="project-image-upload">
                    <div class="project-image-preview" id="editProjectImagePreview">
                        <i class="fas fa-image"></i>
                    </div>
                    <div class="project-image-actions">
                        <p id="editImageHintText">تصویر فعلی پروژه. می‌توانید آن را تغییر دهید یا حذف کنید.</p>
                        <div class="image-action-buttons">
                            <div class="file-input-wrapper">
                                <button type="button" class="btn btn-sm btn-ghost" style="pointer-events: none;">
                                    <i class="fas fa-upload" style="margin-left: 5px;"></i>
                                    تغییر تصویر
                                </button>
                                <input type="file" name="project_image" id="editProjectImageInput"
                                       accept=".png,.jpg,.jpeg,.webp,.gif,image/png,image/jpeg,image/webp,image/gif"
                                       onchange="previewProjectImage(this, 'editProjectImagePreview')">
                            </div>
                            <button type="button" class="btn btn-sm btn-ghost" id="editRemoveImageBtn"
                                    style="color: #dc2626; border: 1px solid #fee2e2;"
                                    onclick="removeProjectImage()">
                                <i class="fas fa-trash" style="margin-left: 5px;"></i>
                                حذف تصویر
                            </button>
                        </div>
                        <div class="form-hint" style="margin-top: 6px;">فرمت‌های مجاز: JPG، PNG، WEBP، GIF | حداکثر ۵ مگابایت</div>
                    </div>
                </div>
            </div>

            <div class="form-group">
                <label>
                    <i class="fas fa-users" style="color: var(--accent); margin-left: 5px;"></i>
                    اعضای فعلی پروژه
                </label>
                <div class="permission-note analytics" style="margin-bottom: 10px;">
                    <i class="fas fa-chart-line"></i>
                    <span>برای اعطای دسترسی «مشاهده تحلیل‌ها» به هر عضو، روی آیکون <i class="fas fa-key"></i> کلیک کنید و گزینه «مشاهده تحلیل‌ها» را فعال نمایید.</span>
                </div>
                <div id="editCurrentMembersList" class="current-members-list"></div>
            </div>

            <div class="form-group">
                <div class="members-selector-header">
                    <label style="margin-bottom: 0;">
                        <i class="fas fa-user-plus" style="color: var(--accent); margin-left: 5px;"></i>
                        مدیریت اعضا (افزودن/حذف)
                    </label>
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <span class="members-count-badge" id="editMembersCountBadge">خودم (۱ نفر)</span>
                        <?php if (!empty($visibleColleagues)): ?>
                            <button type="button" class="members-select-all" id="editSelectAllBtn" onclick="toggleSelectAll('edit')">
                                <i class="fas fa-check-double" style="margin-left: 4px;"></i>
                                انتخاب همه
                            </button>
                        <?php endif; ?>
                    </div>
                </div>

                <div id="editMembersPermissionAlert" class="permission-note warning" style="display: none;">
                    <i class="fas fa-exclamation-triangle"></i>
                    <span id="editMembersPermissionAlertText"></span>
                </div>

                <?php if (empty($visibleColleagues)): ?>
                    <div class="members-grid">
                        <div class="no-colleagues-note" style="grid-column: 1 / -1;">
                            <i class="fas fa-user-slash" style="font-size: 1.5rem; display: block; margin-bottom: 8px; opacity: 0.5;"></i>
                            هنوز همکاری برای افزودن ندارید.
                        </div>
                    </div>
                <?php else: ?>
                    <div class="members-grid" id="editMembersGrid">
                        <?php foreach ($visibleColleagues as $c): ?>
                            <label class="member-option" data-member-id="<?= (int)$c['id'] ?>">
                                <input type="checkbox" name="members[]" value="<?= (int)$c['id'] ?>"
                                       class="member-checkbox-edit" style="display: none;"
                                       onchange="onMemberToggle(this, 'edit')">
                                <span class="member-option__check"><i class="fas fa-check"></i></span>
                                <div class="member-option__avatar">
                                    <?php if ($c['avatar_url']): ?>
                                        <img src="<?= htmlspecialchars($c['avatar_url']) ?>" alt="">
                                    <?php else: ?>
                                        <?= htmlspecialchars($c['initial']) ?>
                                    <?php endif; ?>
                                </div>
                                <div class="member-option__info">
                                    <div class="member-option__name" title="<?= htmlspecialchars($c['full_name']) ?>">
                                        <?= htmlspecialchars($c['full_name']) ?>
                                    </div>
                                    <div class="member-option__mobile"><?= htmlspecialchars($c['mobile']) ?></div>
                                </div>
                                <?php if (!empty($c['needs_approval'])): ?>
                                    <span class="member-option__pending-badge">
                                        <i class="fas fa-user-clock"></i>
                                        نیاز به تایید
                                    </span>
                                <?php endif; ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <div style="display: flex; gap: 10px; justify-content: flex-end; margin-top: 22px; padding-top: 16px; border-top: 1px solid #f1f5f9;">
                <button type="button" class="btn btn-ghost" onclick="closeEditModal()">
                    <i class="fas fa-times" style="margin-left: 5px;"></i>
                    انصراف
                </button>
                <button type="submit" class="btn" id="editProjectSubmitBtn">
                    <i class="fas fa-check" style="margin-left: 5px;"></i>
                    ذخیره تغییرات
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ================== MODAL: ویرایش دسترسی‌های عضو ================== -->
<div id="memberPermissionsModal" class="modal-overlay">
    <div class="modal-box" style="max-width: 520px;">
        <div class="modal-header">
            <h3><i class="fas fa-key"></i> ویرایش دسترسی‌ها</h3>
            <button class="modal-close" onclick="closePermissionsModal()">&times;</button>
        </div>

        <form method="POST" id="memberPermissionsForm" autocomplete="off">
            <input type="hidden" name="update_member_permissions" value="1">
            <input type="hidden" name="project_id" id="permProjectId">
            <input type="hidden" name="member_id" id="permMemberId">

            <div class="permission-user-header">
                <div class="permission-user-header__avatar" id="permUserAvatar"><i class="fas fa-user"></i></div>
                <div class="permission-user-header__info">
                    <div class="permission-user-header__name" id="permUserName">—</div>
                    <div class="permission-user-header__mobile" id="permUserMobile">—</div>
                </div>
            </div>

            <div id="permSelfNote" class="permission-note info" style="display: none;">
                <i class="fas fa-info-circle"></i>
                <span>شما نمی‌توانید دسترسی «اعطای دسترسی» خودتان را حذف کنید.</span>
            </div>

            <div id="permLimitedNote" class="permission-note warning" style="display: none;">
                <i class="fas fa-exclamation-triangle"></i>
                <span>شما فقط می‌توانید مجوزهایی را اعطا کنید که خودتان دارای آن هستید.</span>
            </div>

            <div class="permissions-grid" id="permissionsGrid"></div>

            <div style="display: flex; gap: 10px; justify-content: flex-end; margin-top: 22px; padding-top: 16px; border-top: 1px solid #f1f5f9;">
                <button type="button" class="btn btn-ghost" onclick="closePermissionsModal()">
                    <i class="fas fa-times" style="margin-left: 5px;"></i>
                    انصراف
                </button>
                <button type="submit" class="btn" id="permSubmitBtn">
                    <i class="fas fa-check" style="margin-left: 5px;"></i>
                    ذخیره دسترسی‌ها
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ================== MODAL: جزئیات کاربر ================== -->
<div id="memberDetailsModal" class="modal-overlay">
    <div class="modal-box" style="max-width: 480px;">
        <div class="modal-header">
            <h3><i class="fas fa-info-circle"></i> جزئیات کاربر</h3>
            <button class="modal-close" onclick="closeDetailsModal()">&times;</button>
        </div>

        <div class="permission-user-header">
            <div class="permission-user-header__avatar" id="detUserAvatar"><i class="fas fa-user"></i></div>
            <div class="permission-user-header__info">
                <div class="permission-user-header__name" id="detUserName">—</div>
                <div class="permission-user-header__mobile" id="detUserMobile">—</div>
            </div>
        </div>

        <div class="member-details-list">
            <div class="member-details-row">
                <div class="member-details-row__icon"><i class="fas fa-user"></i></div>
                <div class="member-details-row__body">
                    <div class="member-details-row__label">نام و نام خانوادگی</div>
                    <div class="member-details-row__value" id="detFullName">—</div>
                </div>
            </div>

            <div class="member-details-row">
                <div class="member-details-row__icon"><i class="fas fa-mobile-alt"></i></div>
                <div class="member-details-row__body">
                    <div class="member-details-row__label">شماره موبایل</div>
                    <div class="member-details-row__value ltr" id="detMobile">—</div>
                </div>
            </div>

            <div class="member-details-row" style="flex-direction: column; align-items: stretch;">
                <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 8px;">
                    <div class="member-details-row__icon" style="background:#eef2ff; color:#4f46e5;"><i class="fas fa-key"></i></div>
                    <div class="member-details-row__body">
                        <div class="member-details-row__label" style="margin-bottom: 0;">دسترسی‌های این کاربر</div>
                    </div>
                </div>
                <div class="member-permissions-list" id="detPermissionsList"></div>
            </div>
        </div>

        <div style="display: flex; gap: 10px; justify-content: flex-end; margin-top: 22px; padding-top: 16px; border-top: 1px solid #f1f5f9;">
            <button type="button" class="btn btn-ghost" onclick="closeDetailsModal()">
                <i class="fas fa-times" style="margin-left: 5px;"></i>
                بستن
            </button>
        </div>
    </div>
</div>

<script>
    const PROJECTS = <?= $projectsJson ?>;
    const PERMISSIONS_META = <?= $permissionsMetaJson ?>;
    const ALL_PERMISSION_KEYS = <?= $allPermissionKeysJson ?>;
    const CURRENT_USER_ID = <?= (int)$userId ?>;

    let createSelectedMemberIds = new Set();
    let editSelectedMemberIds = new Set();
    let createAllSelected = false;
    let editAllSelected = false;
    let currentPermProject = null;
    let currentPermMemberId = null;

    function switchProjectsTab(tabName) {
        document.querySelectorAll('.projects-tab').forEach(tab => {
            tab.classList.toggle('active', tab.getAttribute('data-tab') === tabName);
        });
        document.querySelectorAll('.projects-panel').forEach(panel => {
            panel.classList.toggle('active', panel.id === 'panel-' + tabName);
        });
    }

    let savedScrollY = 0;
    let bodyLockCount = 0;

    function lockBodyScroll() {
        if (bodyLockCount === 0) {
            savedScrollY = window.scrollY || window.pageYOffset || 0;
            document.body.style.position = 'fixed';
            document.body.style.top = `-${savedScrollY}px`;
            document.body.style.width = '100%';
            document.body.style.overflow = 'hidden';
        }
        bodyLockCount++;
    }

    function unlockBodyScroll() {
        bodyLockCount = Math.max(0, bodyLockCount - 1);
        if (bodyLockCount === 0) {
            document.body.style.position = '';
            document.body.style.top = '';
            document.body.style.width = '';
            document.body.style.overflow = '';
            window.scrollTo(0, savedScrollY);
        }
    }

    function openCreateModal() {
        document.getElementById('createProjectModal').classList.add('active');
        lockBodyScroll();

        document.getElementById('createProjectForm').reset();
        createSelectedMemberIds.clear();
        createAllSelected = false;

        document.querySelectorAll('#createMembersGrid .member-option').forEach(label => {
            label.classList.remove('checked');
            const cb = label.querySelector('input[type="checkbox"]');
            if (cb) cb.checked = false;
        });

        document.getElementById('projectImagePreview').innerHTML = '<i class="fas fa-image"></i>';
        document.getElementById('createMembersCountBadge').textContent = 'خودم (۱ نفر)';
        document.getElementById('createMembersCountBadge').classList.remove('warning', 'danger', 'success');
        document.getElementById('maxMembers').value = 10;

        const btn = document.querySelector('#createProjectModal .members-select-all');
        if (btn) btn.innerHTML = '<i class="fas fa-check-double" style="margin-left: 4px;"></i> انتخاب همه';

        setTimeout(() => document.getElementById('projectTitle').focus(), 200);
    }

    function closeCreateModal() {
        document.getElementById('createProjectModal').classList.remove('active');
        unlockBodyScroll();
    }

    function openEditModal(projectId) {
        const project = PROJECTS.find(p => Number(p.id) === Number(projectId));
        if (!project) return;

        currentPermProject = project;

        if (!project.can_edit_project) {
            alert('شما دسترسی ویرایش این پروژه را ندارید.');
            return;
        }

        document.getElementById('editProjectId').value = project.id;
        document.getElementById('editProjectTitle').value = project.title || '';
        document.getElementById('editProjectDescription').value = project.description || '';
        document.getElementById('editMaxMembers').value = project.max_members || 10;
        document.getElementById('editRemoveImage').value = '0';

        const preview = document.getElementById('editProjectImagePreview');
        const hintText = document.getElementById('editImageHintText');
        const removeBtn = document.getElementById('editRemoveImageBtn');

        if (project.profile_image_url) {
            preview.innerHTML = `<img src="${escapeHtml(project.profile_image_url)}?v=${Date.now()}" alt="">`;
            hintText.textContent = 'تصویر فعلی پروژه. می‌توانید آن را تغییر دهید یا حذف کنید.';
            removeBtn.style.display = 'inline-flex';
        } else {
            preview.innerHTML = '<i class="fas fa-image"></i>';
            hintText.textContent = 'این پروژه تصویری ندارد. می‌توانید یکی اضافه کنید.';
            removeBtn.style.display = 'none';
        }

        document.getElementById('editProjectImageInput').value = '';

        editSelectedMemberIds.clear();
        editAllSelected = false;

        const memberIds = (project.member_ids || []).map(id => Number(id));
        const creatorId = Number(project.user_id);
        const canAddMember = !!project.can_add_member;
        const canRemoveMember = !!project.can_remove_member;

        const alertBox = document.getElementById('editMembersPermissionAlert');
        const alertText = document.getElementById('editMembersPermissionAlertText');
        if (!canAddMember && !canRemoveMember) {
            alertBox.style.display = 'flex';
            alertText.textContent = 'شما دسترسی افزودن یا حذف کاربر در این پروژه را ندارید.';
        } else if (!canAddMember) {
            alertBox.style.display = 'flex';
            alertText.textContent = 'شما فقط می‌توانید اعضا را حذف کنید. افزودن کاربر مجاز نیست.';
        } else if (!canRemoveMember) {
            alertBox.style.display = 'flex';
            alertText.textContent = 'شما فقط می‌توانید اعضا را اضافه کنید. حذف کاربر مجاز نیست.';
        } else {
            alertBox.style.display = 'none';
        }

        const selectAllBtn = document.getElementById('editSelectAllBtn');
        if (selectAllBtn) selectAllBtn.style.display = canAddMember ? 'inline-flex' : 'none';

        renderCurrentMembersList(project);

        document.querySelectorAll('#editMembersGrid .member-option').forEach(label => {
            const memberId = Number(label.getAttribute('data-member-id'));
            const cb = label.querySelector('input[type="checkbox"]');

            if (memberId === CURRENT_USER_ID || memberId === creatorId) {
                label.style.display = 'none';
                return;
            }

            const isMember = memberIds.includes(memberId);
            cb.checked = isMember;
            cb.disabled = false;
            label.classList.remove('disabled');

            if (isMember) {
                label.classList.add('checked');
                editSelectedMemberIds.add(memberId);
                if (!canRemoveMember) {
                    cb.disabled = true;
                    label.classList.add('disabled');
                }
            } else {
                label.classList.remove('checked');
                if (!canAddMember) {
                    cb.disabled = true;
                    label.classList.add('disabled');
                }
            }
        });

        updateMembersCount('edit');

        document.getElementById('editProjectModal').classList.add('active');
        lockBodyScroll();
        setTimeout(() => document.getElementById('editProjectTitle').focus(), 200);
    }

    function renderCurrentMembersList(project) {
        const list = document.getElementById('editCurrentMembersList');
        list.innerHTML = '';

        const members = project.members || [];
        const canManagePermissions = !!project.can_manage_permissions;

        if (members.length === 0) {
            list.innerHTML = '<div style="text-align:center; color:#94a3b8; font-size:0.82rem; padding:16px;">عضوی وجود ندارد.</div>';
            return;
        }

        const sorted = [...members].sort((a, b) => {
            if (a.is_creator && !b.is_creator) return -1;
            if (!a.is_creator && b.is_creator) return 1;
            return 0;
        });

        sorted.forEach(member => {
            const row = document.createElement('div');
            row.className = 'current-member-row' + (member.is_creator ? ' is-creator' : '');

            const avatarHTML = member.avatar_url
                ? `<img src="${escapeHtml(member.avatar_url)}" alt="">`
                : escapeHtml(member.initial || '?');

            let actionsHTML = '';
            if (member.is_creator) {
                actionsHTML = `<button type="button" class="member-action-btn crown" disabled title="سازنده پروژه"><i class="fas fa-crown"></i></button>`;
            } else {
                actionsHTML += `<button type="button" class="member-action-btn info" onclick="openMemberDetails(${Number(member.id)})" title="مشاهده جزئیات"><i class="fas fa-info"></i></button>`;
                if (canManagePermissions) {
                    actionsHTML += `<button type="button" class="member-action-btn key" onclick="openPermissionsModal(${Number(member.id)})" title="ویرایش دسترسی‌ها"><i class="fas fa-key"></i></button>`;
                } else {
                    actionsHTML += `<button type="button" class="member-action-btn key" disabled title="شما دسترسی اعطای دسترسی ندارید"><i class="fas fa-key"></i></button>`;
                }
            }

            const crownIcon = member.is_creator ? '<i class="fas fa-crown crown-icon"></i>' : '';

            // ✅ نشانگر وجود دسترسی تحلیل
            const hasAnalytics = (member.permission_keys || []).includes('view_analytics');
            const analyticsBadge = (!member.is_creator && hasAnalytics)
                ? '<span class="member-permission-chip analytics-chip" style="margin-right: 6px;"><i class="fas fa-chart-line"></i> تحلیل</span>'
                : '';

            row.innerHTML = `
                <div class="current-member-row__avatar">${avatarHTML}</div>
                <div class="current-member-row__info">
                    <div class="current-member-row__name">${crownIcon}${escapeHtml(member.full_name)}${analyticsBadge}</div>
                    <div class="current-member-row__mobile">${escapeHtml(member.mobile)}</div>
                </div>
                <div class="current-member-row__actions">${actionsHTML}</div>
            `;

            list.appendChild(row);
        });
    }

    function closeEditModal() {
        document.getElementById('editProjectModal').classList.remove('active');
        unlockBodyScroll();
    }

    function openMemberDetails(memberId) {
        const project = currentPermProject;
        if (!project) return;

        const member = (project.members || []).find(m => Number(m.id) === Number(memberId));
        if (!member) return;

        const avatarEl = document.getElementById('detUserAvatar');
        avatarEl.innerHTML = member.avatar_url
            ? `<img src="${escapeHtml(member.avatar_url)}" alt="">`
            : escapeHtml(member.initial || '?');

        document.getElementById('detUserName').textContent = member.full_name || '—';
        document.getElementById('detUserMobile').textContent = member.mobile || '—';
        document.getElementById('detFullName').textContent = member.full_name || '—';
        document.getElementById('detMobile').textContent = member.mobile || '—';

        const permsList = document.getElementById('detPermissionsList');
        permsList.innerHTML = '';

        if (member.is_creator) {
            permsList.innerHTML = `<span class="member-permission-chip creator-chip"><i class="fas fa-crown"></i>سازنده پروژه - تمام دسترسی‌ها</span>`;
        } else {
            const perms = member.permission_keys || [];
            if (perms.length === 0) {
                permsList.innerHTML = `<span class="member-permission-chip empty"><i class="fas fa-ban"></i> بدون دسترسی</span>`;
            } else {
                perms.forEach(key => {
                    const meta = PERMISSIONS_META[key] || { label: key, icon: 'fa-key' };
                    const chipClass = key === 'view_analytics' ? 'member-permission-chip analytics-chip' : 'member-permission-chip';
                    const chip = document.createElement('span');
                    chip.className = chipClass;
                    chip.innerHTML = `<i class="fas ${escapeHtml(meta.icon || 'fa-key')}"></i> ${escapeHtml(meta.label || key)}`;
                    permsList.appendChild(chip);
                });
            }
        }

        document.getElementById('memberDetailsModal').classList.add('active');
        lockBodyScroll();
    }

    function closeDetailsModal() {
        document.getElementById('memberDetailsModal').classList.remove('active');
        unlockBodyScroll();
    }

    function openPermissionsModal(memberId) {
        const project = currentPermProject;
        if (!project) return;

        if (!project.can_manage_permissions) {
            alert('شما دسترسی اعطای دسترسی را ندارید.');
            return;
        }

        const member = (project.members || []).find(m => Number(m.id) === Number(memberId));
        if (!member || member.is_creator) return;

        currentPermMemberId = memberId;

        document.getElementById('permProjectId').value = project.id;
        document.getElementById('permMemberId').value = memberId;

        const avatarEl = document.getElementById('permUserAvatar');
        avatarEl.innerHTML = member.avatar_url
            ? `<img src="${escapeHtml(member.avatar_url)}" alt="">`
            : escapeHtml(member.initial || '?');

        document.getElementById('permUserName').textContent = member.full_name || '—';
        document.getElementById('permUserMobile').textContent = member.mobile || '—';

        const selfNote = document.getElementById('permSelfNote');
        const limitedNote = document.getElementById('permLimitedNote');
        const isSelf = (Number(memberId) === CURRENT_USER_ID);
        const isCreator = !!project.is_creator;

        const isSelfAndHasManage = isSelf && !isCreator;
        selfNote.style.display = isSelfAndHasManage ? 'flex' : 'none';
        limitedNote.style.display = (!isCreator) ? 'flex' : 'none';

        const grid = document.getElementById('permissionsGrid');
        grid.innerHTML = '';

        const memberPerms = new Set(member.permission_keys || []);
        const grantable = new Set(project.grantable_permissions || []);

        ALL_PERMISSION_KEYS.forEach(key => {
            const meta = PERMISSIONS_META[key] || { label: key, desc: '', icon: 'fa-key' };
            const isChecked = memberPerms.has(key);

            const notGrantable = !isCreator && !grantable.has(key);
            const isSelfManage = isSelf && key === 'manage_permissions' && isSelfAndHasManage;

            const isDisabled = notGrantable || isSelfManage;

            const item = document.createElement('label');
            item.className = 'permission-item' + (isChecked ? ' checked' : '') + (isDisabled ? ' disabled' : '');
            item.dataset.permKey = key;

            // ✅ استایل متمایز برای گزینه تحلیل
            const isAnalytics = (key === 'view_analytics');

            item.innerHTML = `
                <input type="checkbox" name="permissions[]" value="${escapeHtml(key)}"
                       style="display: none;"
                       ${isChecked ? 'checked' : ''}
                       ${isDisabled ? 'disabled' : ''}
                       onchange="onPermissionToggle(this)">
                <span class="permission-item__check"><i class="fas fa-check"></i></span>
                <div class="permission-item__body">
                    <div class="permission-item__title">
                        <i class="fas ${escapeHtml(meta.icon || 'fa-key')}" ${isAnalytics ? 'style="color: #7c3aed;"' : ''}></i>
                        ${escapeHtml(meta.label || key)}
                        ${notGrantable ? '<span style="font-size:0.65rem; color:#94a3b8; font-weight:normal;">(شما این مجوز را ندارید)</span>' : ''}
                        ${isAnalytics && !notGrantable ? '<span style="font-size:0.62rem; color:#7c3aed; background:#f3e8ff; padding:2px 6px; border-radius:5px; font-weight:bold;">ویژه</span>' : ''}
                    </div>
                    <div class="permission-item__desc">${escapeHtml(meta.desc || '')}</div>
                </div>
            `;

            grid.appendChild(item);
        });

        document.getElementById('memberPermissionsModal').classList.add('active');
        lockBodyScroll();
    }

    function closePermissionsModal() {
        document.getElementById('memberPermissionsModal').classList.remove('active');
        unlockBodyScroll();
        currentPermMemberId = null;
    }

    function onPermissionToggle(checkbox) {
        const item = checkbox.closest('.permission-item');
        item.classList.toggle('checked', checkbox.checked);
    }

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeCreateModal();
            closeEditModal();
            closePermissionsModal();
            closeDetailsModal();
        }
    });

    document.getElementById('createProjectModal')?.addEventListener('click', function(e) {
        if (e.target === this) closeCreateModal();
    });
    document.getElementById('editProjectModal')?.addEventListener('click', function(e) {
        if (e.target === this) closeEditModal();
    });
    document.getElementById('memberPermissionsModal')?.addEventListener('click', function(e) {
        if (e.target === this) closePermissionsModal();
    });
    document.getElementById('memberDetailsModal')?.addEventListener('click', function(e) {
        if (e.target === this) closeDetailsModal();
    });

    function previewProjectImage(input, previewId) {
        if (!input.files || !input.files[0]) return;
        const file = input.files[0];
        if (file.size > 5 * 1024 * 1024) {
            alert('حجم تصویر نباید بیشتر از ۵ مگابایت باشد.');
            input.value = '';
            return;
        }

        const reader = new FileReader();
        reader.onload = function(e) {
            document.getElementById(previewId).innerHTML = `<img src="${e.target.result}" alt="">`;
        };
        reader.readAsDataURL(file);

        if (previewId === 'editProjectImagePreview') {
            document.getElementById('editRemoveImage').value = '0';
        }
    }

    function removeProjectImage() {
        if (!confirm('آیا از حذف تصویر پروژه مطمئن هستید؟')) return;
        document.getElementById('editRemoveImage').value = '1';
        document.getElementById('editProjectImagePreview').innerHTML = '<i class="fas fa-image"></i>';
        document.getElementById('editImageHintText').textContent = 'تصویر حذف خواهد شد. می‌توانید تصویر جدید انتخاب کنید.';
        document.getElementById('editRemoveImageBtn').style.display = 'none';
        document.getElementById('editProjectImageInput').value = '';
    }

    function onMemberToggle(checkbox, mode) {
        const label = checkbox.closest('.member-option');
        const memberId = parseInt(label.getAttribute('data-member-id'));

        if (mode === 'create') {
            label.classList.toggle('checked', checkbox.checked);
            if (checkbox.checked) createSelectedMemberIds.add(memberId);
            else createSelectedMemberIds.delete(memberId);
        } else {
            label.classList.toggle('checked', checkbox.checked);
            if (checkbox.checked) editSelectedMemberIds.add(memberId);
            else editSelectedMemberIds.delete(memberId);
        }

        updateMembersCount(mode);
    }

    function updateMembersCount(mode) {
        const selectedSet = mode === 'create' ? createSelectedMemberIds : editSelectedMemberIds;

        let count;
        if (mode === 'create') {
            count = selectedSet.size + 1;
        } else {
            const project = currentPermProject;
            const isCreatorMe = project && Number(project.user_id) === CURRENT_USER_ID;
            const creatorCount = isCreatorMe ? 0 : 1;
            count = selectedSet.size + 1 + creatorCount;
        }

        const badgeId = mode === 'create' ? 'createMembersCountBadge' : 'editMembersCountBadge';
        const maxId = mode === 'create' ? 'maxMembers' : 'editMaxMembers';
        const badge = document.getElementById(badgeId);
        const maxMembers = parseInt(document.getElementById(maxId).value) || 10;

        badge.textContent = count + ' نفر انتخاب شده (شامل سازنده و خودم)';
        badge.classList.remove('warning', 'danger', 'success');

        if (count > maxMembers) {
            badge.classList.add('danger');
            badge.textContent = count + ' نفر انتخاب شده (بیش از حد مجاز!)';
        } else if (count === maxMembers) {
            badge.classList.add('success');
            badge.textContent = count + ' نفر (پر شد)';
        } else if (count > maxMembers * 0.7) {
            badge.classList.add('warning');
        }
    }

    document.getElementById('maxMembers')?.addEventListener('input', () => updateMembersCount('create'));
    document.getElementById('editMaxMembers')?.addEventListener('input', () => updateMembersCount('edit'));

    function toggleSelectAll(mode) {
        const checkboxClass = mode === 'create' ? '.member-checkbox-create' : '.member-checkbox-edit';
        const checkboxes = document.querySelectorAll(checkboxClass);

        let allSelected = mode === 'create' ? createAllSelected : editAllSelected;
        allSelected = !allSelected;

        if (mode === 'create') {
            createAllSelected = allSelected;
            createSelectedMemberIds.clear();
        } else {
            editAllSelected = allSelected;
            editSelectedMemberIds.clear();
        }

        const project = currentPermProject;
        const creatorId = project ? Number(project.user_id) : 0;

        checkboxes.forEach(cb => {
            if (cb.disabled) return;
            const label = cb.closest('.member-option');
            const memberId = parseInt(label.getAttribute('data-member-id'));
            if (mode === 'edit' && (memberId === creatorId || memberId === CURRENT_USER_ID)) return;

            cb.checked = allSelected;
            label.classList.toggle('checked', allSelected);

            if (allSelected) {
                if (mode === 'create') createSelectedMemberIds.add(memberId);
                else editSelectedMemberIds.add(memberId);
            }
        });

        const btn = document.querySelector(`#${mode === 'create' ? 'createProjectModal' : 'editProjectModal'} .members-select-all`);
        if (btn) {
            btn.innerHTML = allSelected
                ? '<i class="fas fa-times" style="margin-left: 4px;"></i> لغو انتخاب'
                : '<i class="fas fa-check-double" style="margin-left: 4px;"></i> انتخاب همه';
        }

        updateMembersCount(mode);
    }

    document.getElementById('createProjectForm')?.addEventListener('submit', function(e) {
        const title = document.getElementById('projectTitle').value.trim();
        const maxMembers = parseInt(document.getElementById('maxMembers').value) || 0;
        const memberCount = createSelectedMemberIds.size + 1;

        if (!title) {
            e.preventDefault();
            alert('عنوان پروژه را وارد کنید.');
            document.getElementById('projectTitle').focus();
            return;
        }

        if (maxMembers < 1 || maxMembers > 100) {
            e.preventDefault();
            alert('حداکثر اعضا باید بین ۱ تا ۱۰۰ باشد.');
            document.getElementById('maxMembers').focus();
            return;
        }

        if (memberCount > maxMembers) {
            e.preventDefault();
            alert('تعداد اعضای انتخاب‌شده (' + memberCount + ') از حداکثر مجاز (' + maxMembers + ') بیشتر است.');
            return;
        }

        const btn = document.getElementById('createProjectSubmitBtn');
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin" style="margin-left: 5px;"></i> در حال ایجاد...';
    });

    document.getElementById('editProjectForm')?.addEventListener('submit', function(e) {
        const title = document.getElementById('editProjectTitle').value.trim();
        const maxMembers = parseInt(document.getElementById('editMaxMembers').value) || 0;

        const project = currentPermProject;
        const isCreatorMe = project && Number(project.user_id) === CURRENT_USER_ID;
        const creatorCount = isCreatorMe ? 0 : 1;
        const memberCount = editSelectedMemberIds.size + 1 + creatorCount;

        if (!title) {
            e.preventDefault();
            alert('عنوان پروژه را وارد کنید.');
            document.getElementById('editProjectTitle').focus();
            return;
        }

        if (maxMembers < 1 || maxMembers > 100) {
            e.preventDefault();
            alert('حداکثر اعضا باید بین ۱ تا ۱۰۰ باشد.');
            document.getElementById('editMaxMembers').focus();
            return;
        }

        if (memberCount > maxMembers) {
            e.preventDefault();
            alert('تعداد اعضای انتخاب‌شده (' + memberCount + ') از حداکثر مجاز (' + maxMembers + ') بیشتر است.');
            return;
        }

        const btn = document.getElementById('editProjectSubmitBtn');
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin" style="margin-left: 5px;"></i> در حال ذخیره...';
    });

    document.getElementById('memberPermissionsForm')?.addEventListener('submit', function(e) {
        const btn = document.getElementById('permSubmitBtn');
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin" style="margin-left: 5px;"></i> در حال ذخیره...';
    });

    function toggleSidebar() {
        const sidebar = document.getElementById('appSidebar');
        const overlay = document.getElementById('sidebarOverlay');
        const btn = document.getElementById('hamburgerBtn');

        if (sidebar.classList.contains('open')) {
            closeSidebar();
        } else {
            sidebar.classList.add('open');
            overlay.classList.add('active');
            btn.classList.add('active');
            document.body.style.overflow = 'hidden';
        }
    }

    function closeSidebar() {
        const sidebar = document.getElementById('appSidebar');
        const overlay = document.getElementById('sidebarOverlay');
        const btn = document.getElementById('hamburgerBtn');
        if (!sidebar || !overlay || !btn) return;

        sidebar.classList.remove('open');
        overlay.classList.remove('active');
        btn.classList.remove('active');

        const isModalOpen =
            document.getElementById('createProjectModal')?.classList.contains('active') ||
            document.getElementById('editProjectModal')?.classList.contains('active') ||
            document.getElementById('memberPermissionsModal')?.classList.contains('active') ||
            document.getElementById('memberDetailsModal')?.classList.contains('active');

        if (!isModalOpen) document.body.style.overflow = '';
    }

    let resizeTimer;
    window.addEventListener('resize', function() {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(function() {
            if (window.innerWidth > 900) closeSidebar();
        }, 200);
    });

    function escapeHtml(text) {
        if (text === null || text === undefined) return '';
        const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
        return String(text).replace(/[&<>"']/g, function(m) { return map[m]; });
    }

    document.addEventListener('contextmenu', function(e) {
        const tag = (e.target.tagName || '').toLowerCase();
        if (tag === 'input' || tag === 'textarea') return true;
        e.preventDefault();
        return false;
    });

    document.addEventListener('keydown', function(e) {
        if ((e.ctrlKey || e.metaKey) && (e.key === 'a' || e.key === 'A')) {
            const tag = (e.target.tagName || '').toLowerCase();
            if (tag !== 'input' && tag !== 'textarea') {
                e.preventDefault();
                return false;
            }
        }

        if ((e.ctrlKey || e.metaKey) && (e.key === 'u' || e.key === 'U')) {
            e.preventDefault();
            return false;
        }

        if ((e.ctrlKey || e.metaKey) && (e.key === 'p' || e.key === 'P')) {
            e.preventDefault();
            return false;
        }
    });

    document.addEventListener('dragstart', function(e) {
        if (e.target.tagName === 'IMG' || e.target.tagName === 'A') {
            e.preventDefault();
        }
    });

    document.addEventListener('keydown', function(e) {
        if ((e.ctrlKey || e.metaKey) && (e.key === 's' || e.key === 'S')) {
            e.preventDefault();
            e.stopPropagation();

            const createModal = document.getElementById('createProjectModal');
            const editModal = document.getElementById('editProjectModal');
            const permModal = document.getElementById('memberPermissionsModal');
            const detailsModal = document.getElementById('memberDetailsModal');

            const isCreateOpen = createModal && createModal.classList.contains('active');
            const isEditOpen = editModal && editModal.classList.contains('active');
            const isPermOpen = permModal && permModal.classList.contains('active');
            const isDetailsOpen = detailsModal && detailsModal.classList.contains('active');

            if (isCreateOpen) {
                const form = document.getElementById('createProjectForm');
                if (form) {
                    if (typeof form.requestSubmit === 'function') {
                        form.requestSubmit();
                    } else {
                        form.dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }));
                    }
                }
                return false;
            }

            if (isEditOpen) {
                const form = document.getElementById('editProjectForm');
                if (form) {
                    if (typeof form.requestSubmit === 'function') {
                        form.requestSubmit();
                    } else {
                        form.dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }));
                    }
                }
                return false;
            }

            if (isPermOpen) {
                const form = document.getElementById('memberPermissionsForm');
                if (form) {
                    if (typeof form.requestSubmit === 'function') {
                        form.requestSubmit();
                    } else {
                        form.dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }));
                    }
                }
                return false;
            }

            if (isDetailsOpen) {
                if (typeof closeDetailsModal === 'function') closeDetailsModal();
                return false;
            }

            const activeEl = document.activeElement;
            if (activeEl && (activeEl.tagName === 'INPUT' || activeEl.tagName === 'TEXTAREA')) {
                activeEl.blur();
            }

            return false;
        }
    }, true);

</script>
</body>
</html>
<?php ob_end_flush(); ?>