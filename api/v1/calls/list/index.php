<?php
/**
 * GET /api/v1/calls/list/
 * لیست درخواست‌های تماس کاربر
 * 
 * Query params (اختیاری):
 *   ?status=all|new|done      (new=جدید، done=انجام‌شده)
 *   ?limit=100
 *   ?offset=0
 *   ?assignee=me|all          (me: فقط واگذارشده به من، all: شامل همه)
 */
require_once dirname(__DIR__, 2) . '/_bootstrap.php';

requireMethod('GET');

$status   = $_GET['status']   ?? 'all';
$assignee = $_GET['assignee'] ?? 'all';
$limit    = min(200, max(1, (int)($_GET['limit'] ?? 100)));
$offset   = max(0, (int)($_GET['offset'] ?? 0));

if (!in_array($status, ['all', 'new', 'done'], true)) $status = 'all';
if (!in_array($assignee, ['all', 'me'], true)) $assignee = 'all';

$sql = "
    SELECT 
        cr.id,
        cr.user_id,
        cr.assignee_id,
        cr.first_name,
        cr.last_name,
        cr.mobile,
        cr.email,
        cr.store,
        cr.website,
        cr.status,
        cr.created_at,
        cr.completed_at,
        ua.first_name AS assignee_first_name,
        ua.last_name  AS assignee_last_name,
        ua.mobile     AS assignee_mobile,
        uc.first_name AS creator_first_name,
        uc.last_name  AS creator_last_name,
        uc.mobile     AS creator_mobile
    FROM call_requests cr
    LEFT JOIN users ua ON ua.id = cr.assignee_id
    LEFT JOIN users uc ON uc.id = cr.user_id
    WHERE (cr.user_id = ? OR cr.assignee_id = ?)
";

$params = [$userId, $userId];

if ($assignee === 'me') {
    $sql = "
        SELECT 
            cr.id, cr.user_id, cr.assignee_id,
            cr.first_name, cr.last_name, cr.mobile, cr.email, cr.store, cr.website,
            cr.status, cr.created_at, cr.completed_at,
            ua.first_name AS assignee_first_name,
            ua.last_name  AS assignee_last_name,
            ua.mobile     AS assignee_mobile,
            uc.first_name AS creator_first_name,
            uc.last_name  AS creator_last_name,
            uc.mobile     AS creator_mobile
        FROM call_requests cr
        LEFT JOIN users ua ON ua.id = cr.assignee_id
        LEFT JOIN users uc ON uc.id = cr.user_id
        WHERE cr.assignee_id = ?
    ";
    $params = [$userId];
}

if ($status === 'new') {
    $sql .= " AND cr.status = 0";
} elseif ($status === 'done') {
    $sql .= " AND cr.status = 1";
}

$sql .= " ORDER BY cr.created_at DESC LIMIT " . (int)$limit . " OFFSET " . (int)$offset;

try {
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $calls = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($calls as &$c) {
        $c['id']           = (int)$c['id'];
        $c['user_id']      = (int)$c['user_id'];
        $c['assignee_id']  = $c['assignee_id'] !== null ? (int)$c['assignee_id'] : null;
        $c['status']       = (int)$c['status'];
        $c['is_done']      = ($c['status'] === 1);
    }
    unset($c);

    apiSuccess([
        'count'    => count($calls),
        'limit'    => $limit,
        'offset'   => $offset,
        'status'   => $status,
        'assignee' => $assignee,
        'calls'    => $calls,
    ]);
} catch (Throwable $e) {
    error_log('[API calls/list] ' . $e->getMessage());
    apiError('خطا در واکشی درخواست‌ها.', 500);
}