<?php
// tasks/notif_api.php
// API سبک برای بررسی وظایف سررسیدشده — سراسری برای همه صفحات
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../database/Database.php';

date_default_timezone_set('Asia/Tehran');
header('Content-Type: application/json; charset=utf-8');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'unauthorized'], JSON_UNESCAPED_UNICODE);
    exit;
}

$userId = (int)$_SESSION['user_id'];
$db = Database::getInstance();

try {
    $db->exec("SET NAMES 'utf8mb4'");
    $db->exec("SET time_zone = '+03:30'");
} catch (PDOException $e) {
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

if ($action !== 'check_due') {
    echo json_encode(['status' => 'error', 'message' => 'action نامعتبر'], JSON_UNESCAPED_UNICODE);
    exit;
}

/* اطمینان از وجود ستون‌های لازم */
foreach (
    [
        'due_time'    => "ALTER TABLE tasks ADD COLUMN due_time TIME DEFAULT NULL AFTER due_date",
        'notified_at' => "ALTER TABLE tasks ADD COLUMN notified_at DATETIME DEFAULT NULL AFTER due_time",
        'share_token' => "ALTER TABLE tasks ADD COLUMN share_token VARCHAR(64) DEFAULT NULL",
    ] as $colName => $alterSql
) {
    try {
        $chk = $db->query("SHOW COLUMNS FROM tasks LIKE " . $db->quote($colName));
        if ($chk && $chk->rowCount() === 0) {
            $db->exec($alterSql);
            if ($colName === 'share_token') {
                try {
                    $db->exec("ALTER TABLE tasks ADD UNIQUE INDEX idx_tasks_share_token (share_token)");
                } catch (PDOException $e) {
                }
            }
        }
    } catch (PDOException $e) {
    }
}

$found = [];
try {
    $stmt = $db->prepare("
        SELECT
            t.id,
            t.title,
            t.description,
            t.due_date,
            t.due_time,
            t.share_token,
            p.title AS project_title
        FROM tasks t
        LEFT JOIN projects p ON t.project_id = p.id
        WHERE t.assignee_id = ?
          AND t.is_completed = 0
          AND t.notified_at IS NULL
          AND t.due_date IS NOT NULL
          AND t.due_time IS NOT NULL
          AND STR_TO_DATE(CONCAT(t.due_date, ' ', t.due_time), '%Y-%m-%d %H:%i:%s') <= NOW()
          AND STR_TO_DATE(CONCAT(t.due_date, ' ', t.due_time), '%Y-%m-%d %H:%i:%s') >= DATE_SUB(NOW(), INTERVAL 30 MINUTE)
    ");
    $stmt->execute([$userId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($rows)) {
        $updNotif = $db->prepare("UPDATE tasks SET notified_at = NOW() WHERE id = ?");
        $updToken = $db->prepare("UPDATE tasks SET share_token = ? WHERE id = ?");

        foreach ($rows as $r) {
            $taskId = (int)$r['id'];
            $token  = (string)($r['share_token'] ?? '');

            /* اگر توکن اشتراکی ندارد، تولید کن تا لینک مستقیم کار کند */
            if ($token === '') {
                $token = bin2hex(random_bytes(16));
                try {
                    $updToken->execute([$token, $taskId]);
                } catch (PDOException $e) {
                    $token = '';
                }
            }

            try {
                $updNotif->execute([$taskId]);
            } catch (PDOException $e) {
            }

            $found[] = [
                'id'            => $taskId,
                'title'         => (string)$r['title'],
                'description'   => (string)($r['description'] ?? ''),
                'project_title' => (string)($r['project_title'] ?? ''),
                'share_token'   => $token,
            ];
        }
    }
} catch (PDOException $e) {
    error_log('check_due error: ' . $e->getMessage());
}

echo json_encode(['status' => 'success', 'tasks' => $found], JSON_UNESCAPED_UNICODE);
