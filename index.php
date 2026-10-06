<?php
/**
 * Task Manager - Main Entry Point & Router
 */

// راه‌اندازی بافر خروجی و مدیریت کامل خطاها برای جلوگیری از خطای headers already sent
ob_start();
error_reporting(0);
ini_set('display_errors', 0);

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/core/Auth.php';

// بررسی اینکه آیا کاربر لاگین کرده است یا خیر
if (!Auth::check()) {
    ob_end_clean();
    header('Location: ' . BASE_URL . '/auth/login.php');
    exit;
}

// امن‌سازی کامل دریافت نقش کاربر با بررسی کلیدهای مختلف سشن
$userRole = 'user';
if (isset($_SESSION['user_role'])) {
    $userRole = $_SESSION['user_role'];
} elseif (isset($_SESSION['role'])) {
    $userRole = $_SESSION['role'];
}

ob_end_clean();

// هدایت بر اساس نقش کاربر
if ($userRole === 'admin') {
    header('Location: ' . BASE_URL . '/admin/index.php');
    exit;
} else {
    header('Location: ' . BASE_URL . '/tasks/index.php');
    exit;
}