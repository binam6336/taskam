<?php
$sessionLifetime = 60 * 60 * 24 * 10;

ini_set('session.gc_maxlifetime', $sessionLifetime);
ini_set('session.cookie_lifetime', $sessionLifetime);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../database/Database.php';

header('Content-Type: application/json; charset=utf-8');

if (!Auth::check()) {
    echo json_encode(['status' => 'not_logged_in', 'message' => 'لطفاً وارد شوید.']);
    exit;
}

$db = Database::getInstance();
$userId = (int)$_SESSION['user_id'];

try {
    $stmt = $db->prepare("SELECT status, role FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $user = null;
}

if (!$user) {
    echo json_encode(['status' => 'not_found', 'message' => 'کاربر یافت نشد.']);
    exit;
}

// اگه درخواست AJAX نبود، ریدایرکت کن
$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) 
    && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

if (!$isAjax) {
    if ($user['status'] === 'active') {
        if ($user['role'] === 'admin') {
            header('Location: ../admin/index.php');
        } else {
            header('Location: ../tasks/index.php');
        }
    } else {
        header('Location: index.php');
    }
    exit;
}

// پاسخ JSON
if ($user['status'] === 'active') {
    if ($user['role'] === 'admin') {
        echo json_encode(['status' => 'admin']);
    } else {
        echo json_encode(['status' => 'active']);
    }
} else {
    echo json_encode(['status' => $user['status']]);
}