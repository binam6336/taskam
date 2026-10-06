<?php
// texts/api.php - API مدیریت متون
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../database/Database.php';

header('Content-Type: application/json; charset=utf-8');

// بررسی لاگین بودن
if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['error' => 'ابتدا وارد شوید']);
    exit;
}

$db = Database::getInstance();

// رفع مشکل انکودینگ
try {
    $db->exec("SET NAMES 'utf8mb4'");
    $db->exec("SET CHARACTER SET utf8mb4");
    $db->exec("SET character_set_connection = utf8mb4");
} catch (PDOException $e) {}

$userId = (int)$_SESSION['user_id'];
$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true) ?? [];

try {
    switch ($method) {
        case 'GET':
            handleGet($db, $userId);
            break;
        case 'POST':
            handlePost($db, $userId, $input);
            break;
        case 'PUT':
            handlePut($db, $userId, $input);
            break;
        case 'DELETE':
            handleDelete($db, $userId, $input);
            break;
        case 'OPTIONS':
            http_response_code(200);
            break;
        default:
            http_response_code(405);
            echo json_encode(['error' => 'متد مجاز نیست']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

// ==================== توابع ====================

function handleGet($db, $userId) {
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';

    if (!empty($search)) {
        $stmt = $db->prepare("
            SELECT * FROM texts
            WHERE user_id = ? AND (title LIKE ? OR content LIKE ?)
            ORDER BY sort_order ASC, created_at DESC
        ");
        $like = '%' . $search . '%';
        $stmt->execute([$userId, $like, $like]);
    } else {
        $stmt = $db->prepare("
            SELECT * FROM texts
            WHERE user_id = ?
            ORDER BY sort_order ASC, created_at DESC
        ");
        $stmt->execute([$userId]);
    }

    $texts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['success' => true, 'data' => $texts, 'total' => count($texts)]);
}

function handlePost($db, $userId, $input) {
    $title   = trim($input['title'] ?? '');
    $content = trim($input['content'] ?? '');

    if (empty($title) || empty($content)) {
        http_response_code(400);
        echo json_encode(['error' => 'عنوان و متن نمی‌توانند خالی باشند']);
        return;
    }

    $id = uniqid() . '_' . bin2hex(random_bytes(4));

    // sort_order = بیشترین مقدار + 1 (برای قرارگیری در بالای لیست)
    $minStmt = $db->prepare("SELECT COALESCE(MIN(sort_order), 0) FROM texts WHERE user_id = ?");
    $minStmt->execute([$userId]);
    $newOrder = (int)$minStmt->fetchColumn() - 1;

    $stmt = $db->prepare("
        INSERT INTO texts (id, user_id, title, content, sort_order)
        VALUES (?, ?, ?, ?, ?)
    ");
    $stmt->execute([$id, $userId, $title, $content, $newOrder]);

    $stmt = $db->prepare("SELECT * FROM texts WHERE id = ? AND user_id = ?");
    $stmt->execute([$id, $userId]);
    $newText = $stmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'data' => $newText, 'message' => 'متن با موفقیت افزوده شد']);
}

function handlePut($db, $userId, $input) {
    $id      = $input['id'] ?? '';
    $title   = trim($input['title'] ?? '');
    $content = trim($input['content'] ?? '');

    if (empty($id) || empty($title) || empty($content)) {
        http_response_code(400);
        echo json_encode(['error' => 'شناسه، عنوان و متن الزامی هستند']);
        return;
    }

    // بررسی وجود و مالکیت
    $check = $db->prepare("SELECT id FROM texts WHERE id = ? AND user_id = ?");
    $check->execute([$id, $userId]);
    if (!$check->fetch()) {
        http_response_code(404);
        echo json_encode(['error' => 'متن مورد نظر یافت نشد']);
        return;
    }

    $stmt = $db->prepare("UPDATE texts SET title = ?, content = ? WHERE id = ? AND user_id = ?");
    $stmt->execute([$title, $content, $id, $userId]);

    $stmt = $db->prepare("SELECT * FROM texts WHERE id = ? AND user_id = ?");
    $stmt->execute([$id, $userId]);
    $updatedText = $stmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'data' => $updatedText, 'message' => 'متن با موفقیت ویرایش شد']);
}

function handleDelete($db, $userId, $input) {
    $id = $input['id'] ?? '';

    if (empty($id)) {
        http_response_code(400);
        echo json_encode(['error' => 'شناسه متن الزامی است']);
        return;
    }

    $check = $db->prepare("SELECT id FROM texts WHERE id = ? AND user_id = ?");
    $check->execute([$id, $userId]);
    if (!$check->fetch()) {
        http_response_code(404);
        echo json_encode(['error' => 'متن مورد نظر یافت نشد']);
        return;
    }

    $stmt = $db->prepare("DELETE FROM texts WHERE id = ? AND user_id = ?");
    $stmt->execute([$id, $userId]);

    echo json_encode(['success' => true, 'message' => 'متن با موفقیت حذف شد']);
}