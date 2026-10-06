<?php
/**
 * GET /api/v1/tasks/list/
 * لیست تسک‌های کاربر
 * 
 * Query params (اختیاری):
 *   ?status=all|pending|completed
 *   ?limit=100
 *   ?offset=0
 */
require_once dirname(__DIR__, 2) . '/_bootstrap.php';

requireMethod('GET');

$status = $_GET['status'] ?? 'all';
$limit  = min(200, max(1, (int)($_GET['limit'] ?? 100)));
$offset = max(0, (int)($_GET['offset'] ?? 0));

if (!in_array($status, ['all', 'pending', 'completed'], true)) $status = 'all';

$hasProjectCol = true;
try {
    $c = $db->query("SHOW COLUMNS FROM tasks LIKE 'project_id'");
    if ($c->rowCount() === 0) $hasProjectCol = false;
} catch (Throwable $e) { $hasProjectCol = false; }

$projectSelect = $hasProjectCol
    ? "t.project_id, p.title AS project_title,"
    : "NULL AS project_id, NULL AS project_title,";
$projectJoin = $hasProjectCol ? "LEFT JOIN projects p ON t.project_id = p.id" : "";

$sql = "
    SELECT 
        t.id, t.title, t.description, t.priority, t.is_completed,
        t.due_date, t.created_at, t.completed_at,
        t.user_id, t.assignee_id, t.subject_id,
        $projectSelect
        s.title AS subject_title,
        ua.first_name AS assignee_first_name,
        ua.last_name  AS assignee_last_name,
        ua.mobile     AS assignee_mobile,
        uc.first_name AS creator_first_name,
        uc.last_name  AS creator_last_name,
        (SELECT COUNT(*) FROM task_notes tn WHERE tn.task_id = t.id) AS notes_count
    FROM tasks t
    LEFT JOIN subjects s ON t.subject_id = s.id
    $projectJoin
    LEFT JOIN users ua ON ua.id = t.assignee_id
    LEFT JOIN users uc ON uc.id = t.user_id
    WHERE (t.user_id = ? OR t.assignee_id = ?)
";

$params = [$userId, $userId];

if ($status === 'pending')   $sql .= " AND t.is_completed = 0";
elseif ($status === 'completed') $sql .= " AND t.is_completed = 1";

$sql .= " ORDER BY t.created_at DESC LIMIT " . (int)$limit . " OFFSET " . (int)$offset;

try {
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($tasks as &$t) {
        $t['id']           = (int)$t['id'];
        $t['user_id']      = (int)$t['user_id'];
        $t['assignee_id']  = $t['assignee_id'] !== null ? (int)$t['assignee_id'] : null;
        $t['subject_id']   = $t['subject_id']  !== null ? (int)$t['subject_id']  : null;
        $t['project_id']   = $t['project_id']  !== null ? (int)$t['project_id']  : null;
        $t['is_completed'] = (bool)$t['is_completed'];
        $t['notes_count']  = (int)$t['notes_count'];
    }
    unset($t);

    apiSuccess([
        'count'  => count($tasks),
        'limit'  => $limit,
        'offset' => $offset,
        'status' => $status,
        'tasks'  => $tasks,
    ]);
} catch (Throwable $e) {
    error_log('[API tasks/list] ' . $e->getMessage());
    apiError('خطا در واکشی تسک‌ها.', 500);
}