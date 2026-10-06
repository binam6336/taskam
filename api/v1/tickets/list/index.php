<?php
/**
 * GET /api/v1/tickets/list/
 * لیست تیکت‌ها
 * 
 * Query params (اختیاری):
 *   ?status=all|open|done
 *   ?priority=low|medium|high|very_high
 *   ?vip=1
 *   ?q=search
 *   ?limit=100
 *   ?offset=0
 */
require_once dirname(__DIR__, 2) . '/_bootstrap.php';

requireMethod('GET');

$status   = $_GET['status']   ?? 'all';
$priority = $_GET['priority'] ?? 'all';
$vip      = isset($_GET['vip']) ? (int)$_GET['vip'] : -1;
$q        = trim((string)($_GET['q'] ?? ''));
$limit    = min(200, max(1, (int)($_GET['limit'] ?? 100)));
$offset   = max(0, (int)($_GET['offset'] ?? 0));

if (!in_array($status, ['all', 'open', 'done'], true)) $status = 'all';
if (!in_array($priority, ['all', 'low', 'medium', 'high', 'very_high'], true)) $priority = 'all';

$sql = "
    SELECT 
        t.id,
        t.ticket_number,
        t.subject,
        t.task_link,
        t.priority,
        t.status,
        t.is_vip,
        t.text,
        t.created_at,
        t.completed_at
    FROM tickets t
    WHERE 1=1
";

$params = [];

if ($status === 'open') {
    $sql .= " AND t.status = 0";
} elseif ($status === 'done') {
    $sql .= " AND t.status = 1";
}

if ($priority !== 'all') {
    $sql .= " AND t.priority = ?";
    $params[] = $priority;
}

if ($vip === 1) {
    $sql .= " AND t.is_vip = 1";
} elseif ($vip === 0) {
    $sql .= " AND t.is_vip = 0";
}

if ($q !== '') {
    $sql .= " AND (t.ticket_number LIKE ? OR t.subject LIKE ?)";
    $params[] = '%' . $q . '%';
    $params[] = '%' . $q . '%';
}

$sql .= " ORDER BY t.created_at DESC LIMIT " . (int)$limit . " OFFSET " . (int)$offset;

try {
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($tickets as &$t) {
        $t['id']           = (int)$t['id'];
        $t['status']       = (int)$t['status'];
        $t['is_vip']       = (bool)$t['is_vip'];
        $t['is_done']      = ($t['status'] === 1);
    }
    unset($t);

    apiSuccess([
        'count'    => count($tickets),
        'limit'    => $limit,
        'offset'   => $offset,
        'status'   => $status,
        'priority' => $priority,
        'vip'      => $vip,
        'query'    => $q,
        'tickets'  => $tickets,
    ]);
} catch (Throwable $e) {
    error_log('[API tickets/list] ' . $e->getMessage());
    apiError('خطا در واکشی تیکت‌ها.', 500);
}