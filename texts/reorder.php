<?php
// texts/reorder.php - ذخیره ترتیب جدید
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../database/Database.php';

header('Content-Type: application/json; charset=utf-8');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['error' => 'ابتدا وارد شوید']);
    exit;
}

$db = Database::getInstance();
$userId = (int)$_SESSION['user_id'];

$input = json_decode(file_get_contents('php://input'), true);
$order = $input['order'] ?? [];

if (empty($order) || !is_array($order)) {
    http_response_code(400);
    echo json_encode(['error' => 'ترتیب ارسالی نامعتبر است']);
    exit;
}

try {
    $db->beginTransaction();
    $stmt = $db->prepare("UPDATE texts SET sort_order = ? WHERE id = ? AND user_id = ?");
    foreach ($order as $index => $id) {
        $stmt->execute([$index, $id, $userId]);
    }
    $db->commit();
    echo json_encode(['success' => true, 'message' => 'ترتیب ذخیره شد']);
} catch (Exception $e) {
    $db->rollBack();
    http_response_code(500);
    echo json_encode(['error' => 'خطا در ذخیره ترتیب: ' . $e->getMessage()]);
}