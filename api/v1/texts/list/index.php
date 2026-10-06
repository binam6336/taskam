<?php
/**
 * GET /api/v1/texts/list/
 * لیست متن‌های آماده کاربر
 * 
 * Query params (اختیاری):
 *   ?q=search
 *   ?limit=100
 *   ?offset=0
 */
require_once dirname(__DIR__, 2) . '/_bootstrap.php';

requireMethod('GET');

$q      = trim((string)($_GET['q'] ?? ''));
$limit  = min(200, max(1, (int)($_GET['limit'] ?? 100)));
$offset = max(0, (int)($_GET['offset'] ?? 0));

$sql = "
    SELECT id, title, content, sort_order, created_at, updated_at
    FROM texts
    WHERE user_id = ?
";
$params = [$userId];

if ($q !== '') {
    $sql .= " AND (title LIKE ? OR content LIKE ?)";
    $params[] = '%' . $q . '%';
    $params[] = '%' . $q . '%';
}

$sql .= " ORDER BY sort_order ASC, created_at DESC LIMIT " . (int)$limit . " OFFSET " . (int)$offset;

try {
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $texts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($texts as &$t) {
        $t['sort_order'] = (int)$t['sort_order'];
    }
    unset($t);

    apiSuccess([
        'count'  => count($texts),
        'limit'  => $limit,
        'offset' => $offset,
        'query'  => $q,
        'texts'  => $texts,
    ]);
} catch (Throwable $e) {
    error_log('[API texts/list] ' . $e->getMessage());
    apiError('خطا در واکشی متن‌ها.', 500);
}