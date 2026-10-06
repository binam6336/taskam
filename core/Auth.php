<?php
require_once __DIR__ . '/../database/Database.php';

class Auth {
    public static function login($mobile, $password) {
        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT * FROM users WHERE mobile = ?");
        $stmt->execute([$mobile]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            if ($user['status'] === 'deactivated') {
                return ['success' => false, 'message' => 'حساب کاربری شما غیرفعال شده است.'];
            }
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_mobile'] = $user['mobile'];
            $_SESSION['first_name'] = $user['first_name'];
            $_SESSION['last_name'] = $user['last_name'];
            $_SESSION['user_role'] = $user['role'];
            $_SESSION['user_status'] = $user['status'];
            return ['success' => true, 'role' => $user['role'], 'status' => $user['status']];
        }
        return ['success' => false, 'message' => 'شماره موبایل یا رمز عبور اشتباه است.'];
    }

    public static function register($mobile, $password, $email = null, $firstName = '', $lastName = '') {
        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT id FROM users WHERE mobile = ?");
        $stmt->execute([$mobile]);
        if ($stmt->fetch()) {
            return ['success' => false, 'message' => 'این شماره موبایل قبلاً ثبت‌نام کرده است.'];
        }
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
        $insert = $db->prepare("INSERT INTO users (mobile, first_name, last_name, password, email, status, role) VALUES (?, ?, ?, ?, ?, 'pending', 'user')");
        if ($insert->execute([$mobile, $firstName, $lastName, $hashedPassword, $email])) {
            return ['success' => true, 'message' => 'ثبت‌نام با موفقیت انجام شد. حساب شما پس از تایید مدیر فعال خواهد شد.'];
        }
        return ['success' => false, 'message' => 'خطایی در ثبت‌نام رخ داد.'];
    }

    public static function check() {
        return isset($_SESSION['user_id']);
    }

    public static function logout() {
        session_unset();
        session_destroy();
    }
}