<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../database/Database.php';
require_once __DIR__ . '/../includes/sidebar.php';
require_once __DIR__ . '/../includes/chat_notifications.php';

// ================== هدرهای امنیتی ==================
if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data: https:; style-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com; font-src 'self' https://cdnjs.cloudflare.com data:; script-src 'self' 'unsafe-inline'");
}

if (!Auth::check()) {
    header('Location: ../auth/login.php');
    exit;
}

$bannerPath = __DIR__ . '/../includes/impersonate_banner.php';
if (file_exists($bannerPath) && is_readable($bannerPath)) {
    require_once $bannerPath;
}

$db = Database::getInstance();

try {
    $db->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
} catch (PDOException $e) {
    error_log('DB encoding error: ' . $e->getMessage());
}

if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit;
}

$userId = (int)$_SESSION['user_id'];
$msg = '';
$error = '';

$page = 'profile';

// ================== Session Fingerprint ==================
$fingerprint = hash('sha256', ($_SERVER['HTTP_USER_AGENT'] ?? '') . '|' . ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''));
if (!isset($_SESSION['fingerprint'])) {
    $_SESSION['fingerprint'] = $fingerprint;
} elseif (!hash_equals($_SESSION['fingerprint'], $fingerprint)) {
    session_unset();
    session_destroy();
    header('Location: ../auth/login.php');
    exit;
}

// ================== CSRF Token ==================
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

function verifyCsrf(): bool {
    if (!isset($_POST['csrf_token'], $_SESSION['csrf_token'])) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], (string)$_POST['csrf_token']);
}

// ================== Rate Limiter ==================
function checkRateLimit(string $action, int $maxAttempts, int $windowSeconds): bool {
    $now = time();
    $key = 'rl_' . $action;

    if (!isset($_SESSION[$key]) || !is_array($_SESSION[$key])) {
        $_SESSION[$key] = [];
    }

    $_SESSION[$key] = array_values(array_filter(
        $_SESSION[$key],
        fn($t) => ($now - $t) < $windowSeconds
    ));

    if (count($_SESSION[$key]) >= $maxAttempts) {
        return false;
    }

    $_SESSION[$key][] = $now;
    return true;
}

function resetRateLimit(string $action): void {
    unset($_SESSION['rl_' . $action]);
}

// ================== Audit Log ==================
function auditLog(PDO $db, int $userId, string $action, array $details = []): void {
    try {
        static $tableChecked = false;
        if (!$tableChecked) {
            $db->exec("
                CREATE TABLE IF NOT EXISTS audit_log (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    user_id INT UNSIGNED NOT NULL,
                    action VARCHAR(64) NOT NULL,
                    details TEXT NULL,
                    ip VARCHAR(45) NULL,
                    user_agent VARCHAR(255) NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_user_action (user_id, action),
                    INDEX idx_created (created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            $tableChecked = true;
        }

        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $ua = mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255, 'UTF-8');
        $detailsJson = !empty($details) ? json_encode($details, JSON_UNESCAPED_UNICODE) : null;

        $stmt = $db->prepare("
            INSERT INTO audit_log (user_id, action, details, ip, user_agent)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([$userId, $action, $detailsJson, $ip, $ua]);
    } catch (Throwable $e) {
        error_log('auditLog error: ' . $e->getMessage());
    }
}

// ================== مسیر پوشه آپلود ==================
$uploadDir = realpath(__DIR__ . '/../uploads/avatars/');
if ($uploadDir === false) {
    $uploadDir = __DIR__ . '/../uploads/avatars/';
    if (!is_dir($uploadDir)) {
        @mkdir($uploadDir, 0755, true);
    }
    $uploadDir = realpath($uploadDir) ?: $uploadDir;
}
$uploadDir = rtrim($uploadDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

if (!is_writable($uploadDir)) {
    error_log('upload dir not writable: ' . $uploadDir);
}

$uploadUrl = '../uploads/avatars/';

$gdAvailable = function_exists('imagecreatetruecolor')
    && function_exists('imagejpeg')
    && function_exists('imagepng')
    && function_exists('imagegif');

// ================== اطمینان از وجود ستون‌ها ==================
if (empty($_SESSION['profile_columns_checked'])) {
    try {
        $colCheck = $db->query("SHOW COLUMNS FROM users LIKE 'project_join_requires_approval'");
        if ($colCheck && $colCheck->rowCount() === 0) {
            $db->exec("ALTER TABLE users ADD COLUMN project_join_requires_approval TINYINT(1) NOT NULL DEFAULT 0 AFTER allow_colleague_requests");
        }

        $colCheck2 = $db->query("SHOW COLUMNS FROM users LIKE 'quick_links'");
        if ($colCheck2 && $colCheck2->rowCount() === 0) {
            $db->exec("ALTER TABLE users ADD COLUMN quick_links TEXT NULL DEFAULT NULL AFTER project_join_requires_approval");
        }

        $_SESSION['profile_columns_checked'] = 1;
    } catch (PDOException $e) {
        error_log('profile column check error: ' . $e->getMessage());
    }
}

// ============================================================
//  ★ افزودن ویجت دسترسی سریع (FAB) از فایل include
//  این include:
//    - هندلر AJAX ذخیره‌سازی را مدیریت می‌کند (باید قبل از هر خروجی باشد)
//    - CSS و HTML و JS مربوط به FAB را تزریق می‌کند
//    - خودش از $db و $userId و $csrfToken استفاده می‌کند
// ============================================================
require_once __DIR__ . '/../includes/quick_access.php';

// ================== تغییر وضعیت دریافت درخواست همکاری ==================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_colleague_requests'])) {
    if (!verifyCsrf()) {
        $error = 'درخواست نامعتبر است. لطفاً دوباره تلاش کنید.';
    } elseif (!checkRateLimit('toggle_privacy', 20, 3600)) {
        $error = 'تعداد درخواست‌ها بیش از حد مجاز. لطفاً بعداً تلاش کنید.';
    } else {
        $newState = (int)($_POST['allow_colleague_requests'] ?? 1) === 1 ? 1 : 0;
        $stmt = $db->prepare("UPDATE users SET allow_colleague_requests = ? WHERE id = ?");
        $stmt->execute([$newState, $userId]);

        auditLog($db, $userId, 'privacy_toggle', ['allow' => $newState]);

        $msg = $newState === 1
            ? 'دریافت درخواست همکاری فعال شد.'
            : 'دریافت درخواست همکاری مسدود شد.';
    }
}

// ================== تغییر وضعیت تایید عضویت در پروژه ==================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_project_join_approval'])) {
    if (!verifyCsrf()) {
        $error = 'درخواست نامعتبر است. لطفاً دوباره تلاش کنید.';
    } elseif (!checkRateLimit('toggle_project_join_approval', 20, 3600)) {
        $error = 'تعداد درخواست‌ها بیش از حد مجاز. لطفاً بعداً تلاش کنید.';
    } else {
        $newState = (int)($_POST['project_join_requires_approval'] ?? 0) === 1 ? 1 : 0;
        $stmt = $db->prepare("UPDATE users SET project_join_requires_approval = ? WHERE id = ?");
        $stmt->execute([$newState, $userId]);

        auditLog($db, $userId, 'project_join_approval_toggle', ['requires_approval' => $newState]);

        $msg = $newState === 0
            ? 'عضویت در پروژه و درخواست تماس نیاز به تایید شما فعال شد.'
            : 'عضویت در پروژه و درخواست همکاری نیازمند تایید شما شد.';
    }
}

// ================== آپلود تصویر پروفایل ==================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_avatar'])) {
    if (!verifyCsrf()) {
        $error = 'درخواست نامعتبر است. لطفاً دوباره تلاش کنید.';
    } elseif (!checkRateLimit('avatar_upload', 10, 3600)) {
        $error = 'تعداد آپلودهای شما بیش از حد مجاز است. لطفاً بعداً تلاش کنید.';
    } elseif (!$gdAvailable) {
        $error = 'قابلیت پردازش تصویر روی سرور فعال نیست. لطفاً با مدیر تماس بگیرید.';
        error_log('avatar upload rejected: GD not available');
    } elseif (!isset($_FILES['avatar']) || $_FILES['avatar']['error'] !== UPLOAD_ERR_OK) {
        $error = 'لطفاً یک تصویر انتخاب کنید.';
    } else {
        $file = $_FILES['avatar'];

        if ($file['size'] > 2 * 1024 * 1024) {
            $error = 'حجم تصویر نباید بیشتر از ۲ مگابایت باشد.';
        } elseif ($file['size'] === 0) {
            $error = 'فایل خالی است.';
        } elseif (!is_uploaded_file($file['tmp_name'])) {
            $error = 'خطا در آپلود فایل.';
        } else {
            $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mimeType = $finfo->file($file['tmp_name']);

            if (!in_array($mimeType, $allowedTypes, true)) {
                $error = 'فرمت تصویر باید JPG، PNG، GIF یا WebP باشد.';
            } else {
                $imageInfo = @getimagesize($file['tmp_name']);
                if ($imageInfo === false) {
                    $error = 'فایل انتخاب‌شده یک تصویر معتبر نیست.';
                } else {
                    [$width, $height] = $imageInfo;
                    if ($width > 4000 || $height > 4000 || $width < 10 || $height < 10) {
                        $error = 'ابعاد تصویر باید بین ۱۰ و ۴۰۰۰ پیکسل باشد.';
                    } elseif (($width * $height) > 16_000_000) {
                        $error = 'ابعاد تصویر بسیار بزرگ است.';
                    } else {
                        $extMap = [
                            'image/jpeg' => 'jpg',
                            'image/png'  => 'png',
                            'image/gif'  => 'gif',
                            'image/webp' => 'webp',
                        ];
                        $ext = $extMap[$mimeType] ?? 'jpg';

                        $newFileName = 'avatar_' . $userId . '_' . bin2hex(random_bytes(12)) . '.' . $ext;
                        $destPath = $uploadDir . $newFileName;

                        if (!is_dir($uploadDir) || !is_writable($uploadDir)) {
                            $error = 'خطا در دسترسی به پوشه آپلود.';
                        } else {
                            $processed = false;
                            $srcImage = null;

                            switch ($mimeType) {
                                case 'image/jpeg':
                                    $srcImage = @imagecreatefromjpeg($file['tmp_name']);
                                    break;
                                case 'image/png':
                                    $srcImage = @imagecreatefrompng($file['tmp_name']);
                                    break;
                                case 'image/gif':
                                    $srcImage = @imagecreatefromgif($file['tmp_name']);
                                    break;
                                case 'image/webp':
                                    if (function_exists('imagecreatefromwebp')) {
                                        $srcImage = @imagecreatefromwebp($file['tmp_name']);
                                    }
                                    break;
                            }

                            if ($srcImage === false || $srcImage === null) {
                                $error = 'خطا در پردازش تصویر. لطفاً فایل دیگری امتحان کنید.';
                            } else {
                                if (in_array($mimeType, ['image/png', 'image/gif', 'image/webp'], true)) {
                                    imagealphablending($srcImage, false);
                                    imagesavealpha($srcImage, true);
                                }

                                switch ($ext) {
                                    case 'jpg':
                                        $processed = @imagejpeg($srcImage, $destPath, 88);
                                        break;
                                    case 'png':
                                        $processed = @imagepng($srcImage, $destPath, 8);
                                        break;
                                    case 'gif':
                                        $processed = @imagegif($srcImage, $destPath);
                                        break;
                                    case 'webp':
                                        if (function_exists('imagewebp')) {
                                            $processed = @imagewebp($srcImage, $destPath, 88);
                                        } else {
                                            $destPath = preg_replace('/\.webp$/', '.jpg', $destPath);
                                            $newFileName = preg_replace('/\.webp$/', '.jpg', $newFileName);
                                            $processed = @imagejpeg($srcImage, $destPath, 88);
                                        }
                                        break;
                                }

                                imagedestroy($srcImage);

                                if (!$processed) {
                                    $error = 'خطا در ذخیره تصویر. لطفاً دوباره تلاش کنید.';
                                } else {
                                    @chmod($destPath, 0644);

                                    $oldStmt = $db->prepare("SELECT avatar FROM users WHERE id = ?");
                                    $oldStmt->execute([$userId]);
                                    $oldAvatar = $oldStmt->fetchColumn();

                                    if (!empty($oldAvatar) && is_string($oldAvatar)) {
                                        $oldBase = basename($oldAvatar);
                                        if (preg_match('/^[A-Za-z0-9_.\-]+$/', $oldBase)) {
                                            $oldFile = $uploadDir . $oldBase;
                                            $realOld = realpath($oldFile);
                                            $realUploadDir = realpath($uploadDir);
                                            if ($realOld !== false && $realUploadDir !== false
                                                && strpos($realOld, $realUploadDir . DIRECTORY_SEPARATOR) === 0
                                                && is_file($realOld)) {
                                                @unlink($realOld);
                                            }
                                        }
                                    }

                                    $updateStmt = $db->prepare("UPDATE users SET avatar = ? WHERE id = ?");
                                    $updateStmt->execute([$newFileName, $userId]);

                                    auditLog($db, $userId, 'avatar_upload', [
                                        'file' => $newFileName,
                                        'size' => $file['size'],
                                        'mime' => $mimeType,
                                    ]);

                                    $msg = 'تصویر پروفایل با موفقیت بروزرسانی شد.';
                                }
                            }
                        }
                    }
                }
            }
        }
    }
}

// ================== حذف تصویر پروفایل ==================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['remove_avatar'])) {
    if (!verifyCsrf()) {
        $error = 'درخواست نامعتبر است. لطفاً دوباره تلاش کنید.';
    } elseif (!checkRateLimit('avatar_remove', 10, 3600)) {
        $error = 'تعداد درخواست‌ها بیش از حد مجاز.';
    } else {
        $oldStmt = $db->prepare("SELECT avatar FROM users WHERE id = ?");
        $oldStmt->execute([$userId]);
        $oldAvatar = $oldStmt->fetchColumn();

        if (!empty($oldAvatar) && is_string($oldAvatar)) {
            $oldBase = basename($oldAvatar);
            if (preg_match('/^[A-Za-z0-9_.\-]+$/', $oldBase)) {
                $oldFile = $uploadDir . $oldBase;
                $realOld = realpath($oldFile);
                $realUploadDir = realpath($uploadDir);
                if ($realOld !== false && $realUploadDir !== false
                    && strpos($realOld, $realUploadDir . DIRECTORY_SEPARATOR) === 0
                    && is_file($realOld)) {
                    @unlink($realOld);
                }
            }
            $db->prepare("UPDATE users SET avatar = NULL WHERE id = ?")->execute([$userId]);
            auditLog($db, $userId, 'avatar_remove');
            $msg = 'تصویر پروفایل حذف شد.';
        }
    }
}

// ================== بروزرسانی پروفایل (ایمیل) ==================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    if (!verifyCsrf()) {
        $error = 'درخواست نامعتبر است. لطفاً دوباره تلاش کنید.';
    } elseif (!checkRateLimit('profile_update', 20, 3600)) {
        $error = 'تعداد درخواست‌ها بیش از حد مجاز. لطفاً بعداً تلاش کنید.';
    } else {
        $email = trim((string)($_POST['email'] ?? ''));

        if (mb_strlen($email) > 190) {
            $error = 'ایمیل وارد شده بسیار طولانی است.';
        } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'فرمت ایمیل وارد شده معتبر نیست.';
        } else {
            if ($email !== '') {
                $checkStmt = $db->prepare("SELECT id FROM users WHERE email = ? AND id != ? LIMIT 1");
                $checkStmt->execute([$email, $userId]);
                if ($checkStmt->fetch()) {
                    $error = 'امکان استفاده از این ایمیل وجود ندارد.';
                    auditLog($db, $userId, 'email_duplicate_attempt', ['email' => $email]);
                }
            }

            if (empty($error)) {
                $emailValue = $email !== '' ? $email : null;
                $stmt = $db->prepare("UPDATE users SET email = ? WHERE id = ?");
                $stmt->execute([$emailValue, $userId]);

                auditLog($db, $userId, 'profile_update', ['email' => $emailValue]);
                $msg = 'اطلاعات پروفایل با موفقیت بروزرسانی شد.';
            }
        }
    }
}

// ================== تغییر رمز عبور ==================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    if (!verifyCsrf()) {
        $error = 'درخواست نامعتبر است. لطفاً دوباره تلاش کنید.';
    } elseif (!checkRateLimit('password_change', 5, 900)) {
        $error = 'تلاش‌های بیش از حد. لطفاً ۱۵ دقیقه دیگر دوباره تلاش کنید.';
    } else {
        $currentPass = (string)($_POST['current_password'] ?? '');
        $newPass = (string)($_POST['new_password'] ?? '');

        if (strlen($currentPass) > 200 || strlen($newPass) > 200) {
            $error = 'طول رمز عبور نامعتبر است.';
        } else {
            $stmt = $db->prepare("SELECT password FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $user = $stmt->fetch();

            if ($user && isset($user['password']) && password_verify($currentPass, $user['password'])) {
                if (strlen($newPass) < 8) {
                    $error = 'رمز عبور جدید باید حداقل ۸ کاراکتر باشد.';
                } elseif (!preg_match('/[A-Za-z]/', $newPass) || !preg_match('/[0-9]/', $newPass)) {
                    $error = 'رمز عبور جدید باید شامل حرف و عدد باشد.';
                } elseif ($currentPass === $newPass) {
                    $error = 'رمز عبور جدید نباید با رمز فعلی یکسان باشد.';
                } else {
                    $hashed = password_hash($newPass, PASSWORD_DEFAULT);
                    $update = $db->prepare("UPDATE users SET password = ? WHERE id = ?");
                    $update->execute([$hashed, $userId]);

                    resetRateLimit('password_change');

                    if (session_status() === PHP_SESSION_ACTIVE) {
                        session_regenerate_id(true);
                    }

                    auditLog($db, $userId, 'password_change');
                    $msg = 'رمز عبور با موفقیت تغییر کرد.';
                }
            } else {
                auditLog($db, $userId, 'password_change_failed');
                $error = 'رمز عبور فعلی اشتباه است.';
            }
        }
    }
}

// ================== دریافت اطلاعات کاربر ==================
$stmt = $db->prepare("
    SELECT id, first_name, last_name, mobile, email, avatar,
           allow_colleague_requests, project_join_requires_approval
    FROM users WHERE id = ?
");
$stmt->execute([$userId]);
$currentUser = $stmt->fetch();

if (!$currentUser) {
    header('Location: ../auth/login.php');
    exit;
}

$allowColleagueRequests = isset($currentUser['allow_colleague_requests'])
    ? (int)$currentUser['allow_colleague_requests']
    : 1;

$projectJoinRequiresApproval = isset($currentUser['project_join_requires_approval'])
    ? (int)$currentUser['project_join_requires_approval']
    : 0;

$projectJoinNoApproval = ($projectJoinRequiresApproval === 0);

// ساخت URL عکس پروفایل
$avatarUrl = null;
if (!empty($currentUser['avatar']) && is_string($currentUser['avatar'])) {
    $avatarBase = basename($currentUser['avatar']);
    if (preg_match('/^[A-Za-z0-9_.\-]+$/', $avatarBase)) {
        $candidate = $uploadDir . $avatarBase;
        $realCandidate = realpath($candidate);
        $realUploadDir = realpath($uploadDir);
        if ($realCandidate !== false && $realUploadDir !== false
            && strpos($realCandidate, $realUploadDir . DIRECTORY_SEPARATOR) === 0
            && is_file($realCandidate)) {
            $mtime = @filemtime($realCandidate) ?: time();
            $avatarUrl = $uploadUrl . rawurlencode($avatarBase) . '?v=' . $mtime;
        }
    }
}

$fullName = trim((string)($currentUser['first_name'] ?? '') . ' ' . (string)($currentUser['last_name'] ?? ''));
if ($fullName === '') $fullName = 'کاربر';
$firstForInitial = trim((string)($currentUser['first_name'] ?? ''));
if ($firstForInitial === '') $firstForInitial = trim((string)($currentUser['mobile'] ?? ''));
$userInitial = $firstForInitial !== '' ? mb_substr($firstForInitial, 0, 1, 'UTF-8') : '?';

// ================== آمار تسک‌ها ==================
$statsStmt = $db->prepare("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN is_completed = 1 THEN 1 ELSE 0 END) as completed,
        SUM(CASE WHEN is_completed = 0 THEN 1 ELSE 0 END) as pending
    FROM tasks 
    WHERE user_id = ?
");
$statsStmt->execute([$userId]);
$stats = $statsStmt->fetch() ?: ['total' => 0, 'completed' => 0, 'pending' => 0];
$stats['total'] = (int)($stats['total'] ?? 0);
$stats['completed'] = (int)($stats['completed'] ?? 0);
$stats['pending'] = (int)($stats['pending'] ?? 0);
?>

<!DOCTYPE html>
<html lang="fa" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <title>پروفایل کاربری - مدیریت تسک‌ها</title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="icon" href="https://img.icons8.com/color/48/dashboard-layout.png" type="image/png">

<style>
    :root {
        --primary: #1e293b;
        --accent: #2563eb;
        --accent-hover: #1d4ed8;
        --bg: #f8fafc;
        --card: #ffffff;
        --text: #334155;
        --border: #e2e8f0;
        --success: #10b981;
        --warning: #f59e0b;
        --danger: #ef4444;
    }

    * { box-sizing: border-box; }

    html, body { max-width: 100%; overflow-x: hidden; }

    body {
        background: var(--bg);
        color: var(--text);
        font-family: Tahoma, sans-serif;
        margin: 0;
        display: flex;
        height: 100vh;
        overflow: hidden;
    }

    /* =========================
       SIDEBAR
    ========================= */
    .sidebar {
        width: 260px;
        background: var(--primary);
        color: #fff;
        display: flex;
        flex-direction: column;
        padding: 20px;
        gap: 15px;
        box-shadow: 2px 0 10px rgba(0,0,0,0.05);
        z-index: 10;
        flex-shrink: 0;
        height: 100vh;
        overflow-y: auto;
    }
    .sidebar-brand {
        font-size: 1.1rem;
        font-weight: bold;
        display: flex;
        align-items: center;
        gap: 10px;
        padding-bottom: 15px;
        border-bottom: 1px solid rgba(255,255,255,0.1);
        color: #f8fafc;
    }
    .menu-item {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 12px 15px;
        color: #94a3b8;
        text-decoration: none;
        border-radius: 8px;
        font-weight: bold;
        font-size: 0.9rem;
        transition: 0.2s;
    }
    .menu-item:hover, .menu-item.active {
        background: rgba(255,255,255,0.08);
        color: #fff;
    }
    .submenu {
        padding-right: 25px;
        display: flex;
        flex-direction: column;
        gap: 5px;
        margin-top: -5px;
        margin-bottom: 5px;
    }
    .submenu a {
        color: #94a3b8;
        text-decoration: none;
        font-size: 0.85rem;
        padding: 8px 12px;
        border-radius: 6px;
        transition: 0.2s;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .submenu a:hover, .submenu a.active {
        color: #fff;
        background: rgba(255,255,255,0.05);
    }

    /* =========================
       MAIN
    ========================= */
    .main-wrapper {
        flex: 1;
        display: flex;
        flex-direction: column;
        overflow: hidden;
        min-width: 0;
    }

    .topbar {
        background: var(--card);
        padding: 15px 30px;
        border-bottom: 1px solid var(--border);
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 12px;
    }
    .topbar h1 { margin: 0; font-size: 1.2rem; color: var(--primary); }
    .back-link {
        color: var(--accent);
        text-decoration: none;
        font-size: 0.85rem;
        font-weight: bold;
        transition: 0.2s;
        white-space: nowrap;
    }
    .back-link:hover { color: var(--accent-hover); }

    .content-area { flex: 1; padding: 25px; overflow-y: auto; }

    /* =========================
       ANALYTICS
    ========================= */
    .analytics-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
        gap: 15px;
        margin-bottom: 25px;
    }
    .analytic-card {
        background: var(--card);
        border: 1px solid var(--border);
        padding: 20px;
        border-radius: 12px;
        box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05), 0 2px 4px -1px rgba(0,0,0,0.03);
        display: flex;
        align-items: center;
        justify-content: space-between;
    }
    .analytic-card .info div:first-child { font-size: 0.8rem; color: #64748b; margin-bottom: 5px; }
    .analytic-card .info div:last-child { font-size: 1.4rem; font-weight: bold; color: var(--primary); }
    .analytic-icon {
        width: 45px;
        height: 45px;
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        background: #e0f2fe;
        color: #0284c7;
        font-size: 1.2rem;
    }
    .analytic-card.completed .analytic-icon { background: #d1fae5; color: var(--success); }
    .analytic-card.pending .analytic-icon { background: #fef3c7; color: var(--warning); }

    /* =========================
       CARDS
    ========================= */
    .card {
        background: var(--card);
        border: 1px solid var(--border);
        border-radius: 12px;
        padding: 20px;
        box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05), 0 2px 4px -1px rgba(0,0,0,0.03);
        margin-bottom: 20px;
    }
    .card-header {
        display: flex;
        align-items: center;
        gap: 10px;
        margin-bottom: 20px;
        padding-bottom: 12px;
        border-bottom: 1px solid var(--border);
    }
    .card-header h3 { margin: 0; font-size: 1rem; color: var(--primary); }
    .card-header-icon {
        width: 35px;
        height: 35px;
        border-radius: 8px;
        display: flex;
        align-items: center;
        justify-content: center;
        background: #eff6ff;
        color: var(--accent);
    }

    /* =========================
       FORM
    ========================= */
    .form-group { margin-bottom: 15px; }
    label {
        display: block;
        margin-bottom: 8px;
        font-weight: 600;
        font-size: 0.9rem;
        color: #475569;
        letter-spacing: -0.2px;
    }

    input[type="text"], input[type="email"], input[type="password"] {
        width: 100%;
        padding: 12px 16px;
        background: #ffffff;
        border: 2px solid #e2e8f0;
        border-radius: 12px;
        font-size: 0.95rem;
        font-family: Tahoma, sans-serif;
        color: #1e293b;
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        box-shadow: inset 0 2px 4px rgba(0,0,0,0.01);
    }
    input[type="text"]:focus, input[type="email"]:focus, input[type="password"]:focus {
        outline: none;
        border-color: var(--accent);
        background: #ffffff;
        box-shadow: 0 0 0 4px rgba(37,99,235,0.12), inset 0 2px 4px rgba(0,0,0,0.01);
        transform: translateY(-1px);
    }
    input:disabled { background: #f1f5f9; color: #94a3b8; cursor: not-allowed; }

    /* =========================
       BUTTON
    ========================= */
    button.btn {
        background: var(--accent);
        color: #fff;
        border: none;
        padding: 12px 20px;
        border-radius: 10px;
        font-weight: bold;
        cursor: pointer;
        transition: all 0.2s ease;
        font-family: Tahoma, sans-serif;
        box-shadow: 0 4px 6px -1px rgba(37, 99, 235, 0.2);
    }
    button.btn:hover {
        background: var(--accent-hover);
        transform: translateY(-1px);
        box-shadow: 0 6px 8px -1px rgba(37, 99, 235, 0.3);
    }

    .btn-secondary { background: #f1f5f9; color: #475569; box-shadow: none; }
    .btn-secondary:hover { background: #e2e8f0; color: #1e293b; box-shadow: none; }

    .btn-danger {
        background: #fef2f2;
        color: #dc2626;
        border: 1px solid #fecaca;
        box-shadow: none;
    }
    .btn-danger:hover { background: #dc2626; color: #fff; border-color: #dc2626; }

    /* =========================
       ALERT
    ========================= */
    .alert {
        padding: 12px 15px;
        border-radius: 10px;
        margin-bottom: 20px;
        font-weight: bold;
        font-size: 0.9rem;
        border: 1px solid transparent;
    }
    .alert-success { background: #ecfdf5; color: #047857; border-color: #a7f3d0; }
    .alert-error { background: #fef2f2; color: #b91c1c; border-color: #fecaca; }

    /* =========================
       AVATAR SECTION
    ========================= */
    .avatar-section {
        display: flex;
        align-items: center;
        gap: 24px;
        padding: 20px;
        background: linear-gradient(135deg, #f0f9ff, #e0f2fe);
        border: 2px dashed #93c5fd;
        border-radius: 16px;
        transition: all 0.3s ease;
    }
    .avatar-section:hover {
        border-color: #60a5fa;
        background: linear-gradient(135deg, #eff6ff, #dbeafe);
    }

    .avatar-preview {
        width: 120px;
        height: 120px;
        border-radius: 50%;
        background: #ffffff;
        border: 4px solid #ffffff;
        box-shadow: 0 8px 24px rgba(37, 99, 235, 0.15);
        display: flex;
        align-items: center;
        justify-content: center;
        overflow: hidden;
        flex-shrink: 0;
        position: relative;
        transition: all 0.3s ease;
    }
    .avatar-preview:hover { transform: scale(1.03); box-shadow: 0 12px 32px rgba(37, 99, 235, 0.25); }
    .avatar-preview img { width: 100%; height: 100%; object-fit: cover; }

    .avatar-placeholder {
        width: 100%;
        height: 100%;
        background: linear-gradient(135deg, #94a3b8, #64748b);
        color: #fff;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 2.8rem;
        font-weight: bold;
    }
    .avatar-placeholder i { font-size: 3.2rem; opacity: 0.9; }

    .avatar-actions { flex: 1; min-width: 0; }
    .avatar-actions h4 { margin: 0 0 6px; font-size: 1rem; color: #1e293b; }
    .avatar-actions p { margin: 0 0 14px; font-size: 0.8rem; color: #64748b; line-height: 1.6; }

    .avatar-btn-group { display: flex; gap: 8px; flex-wrap: wrap; }

    .file-input-wrapper { position: relative; display: inline-block; }
    .file-input-wrapper input[type="file"] {
        position: absolute;
        inset: 0;
        opacity: 0;
        cursor: pointer;
        width: 100%;
        height: 100%;
    }

    /* =========================
       PROFILE LAYOUT
    ========================= */
    .profile-grid { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 20px; }

    .password-hint { margin-top: 8px; color: #94a3b8; font-size: 0.75rem; }

    /* =========================
       PRIVACY
    ========================= */
    .privacy-row {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 16px;
        padding: 16px 18px;
        background: #f8fafc;
        border: 2px solid #e2e8f0;
        border-radius: 14px;
        transition: all 0.25s ease;
    }
    .privacy-row.is-blocked { background: #fef2f2; border-color: #fecaca; }
    .privacy-row.is-info { background: #eff6ff; border-color: #bfdbfe; }
    .privacy-info { display: flex; align-items: center; gap: 12px; flex: 1; min-width: 0; }
    .privacy-icon {
        width: 42px;
        height: 42px;
        border-radius: 12px;
        background: #d1fae5;
        color: #059669;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1rem;
        flex-shrink: 0;
        transition: all 0.25s ease;
    }
    .privacy-row.is-blocked .privacy-icon { background: #fee2e2; color: #dc2626; }
    .privacy-row.is-info .privacy-icon { background: #dbeafe; color: #2563eb; }
    .privacy-text { min-width: 0; }
    .privacy-title { font-weight: bold; font-size: 0.9rem; color: #1e293b; margin-bottom: 3px; }
    .privacy-desc { font-size: 0.75rem; color: #64748b; line-height: 1.5; }

    .toggle-switch { position: relative; width: 56px; height: 30px; flex-shrink: 0; cursor: pointer; }
    .toggle-switch input { opacity: 0; width: 0; height: 0; position: absolute; }
    .toggle-slider {
        position: absolute;
        inset: 0;
        background: #cbd5e1;
        border-radius: 30px;
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        box-shadow: inset 0 2px 4px rgba(0,0,0,0.06);
    }
    .toggle-slider::before {
        content: '';
        position: absolute;
        height: 22px;
        width: 22px;
        right: 4px;
        top: 4px;
        background: #ffffff;
        border-radius: 50%;
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        box-shadow: 0 2px 6px rgba(0,0,0,0.2);
    }
    .toggle-switch input:checked + .toggle-slider {
        background: linear-gradient(135deg, #10b981, #059669);
        box-shadow: inset 0 2px 4px rgba(0,0,0,0.06), 0 0 0 4px rgba(16,185,129,0.12);
    }
    .toggle-switch input:checked + .toggle-slider::before { transform: translateX(-26px); }
    .toggle-switch input:not(:checked) + .toggle-slider {
        background: linear-gradient(135deg, #ef4444, #dc2626);
    }

    .info-box {
        display: flex;
        align-items: flex-start;
        gap: 10px;
        padding: 12px 14px;
        margin-top: 12px;
        background: #eff6ff;
        border: 1px solid #bfdbfe;
        border-radius: 12px;
        font-size: 0.78rem;
        color: #1e40af;
        line-height: 1.7;
    }
    .info-box i { color: #2563eb; margin-top: 3px; flex-shrink: 0; }

    /* ============================================================
       HAMBURGER
       ============================================================ */
    .hamburger-btn {
        display: none;
        width: 44px;
        height: 44px;
        border: none;
        background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
        border-radius: 13px;
        cursor: pointer;
        box-shadow: 0 6px 16px rgba(37, 99, 235, 0.35);
        transition: transform 0.2s ease;
        flex-shrink: 0;
        align-items: center;
        justify-content: center;
        padding: 0;
    }
    .hamburger-btn:hover { transform: translateY(-2px); }
    .hamburger-btn .hamburger-lines {
        width: 22px;
        height: 16px;
        position: relative;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
    }
    .hamburger-btn .hamburger-lines span {
        display: block;
        height: 2.5px;
        width: 100%;
        background: #fff;
        border-radius: 3px;
        transition: transform 0.35s, opacity 0.25s, width 0.3s;
        transform-origin: center;
    }
    .hamburger-btn .hamburger-lines span:nth-child(2) { width: 70%; }
    .hamburger-btn.active .hamburger-lines span:nth-child(1) { transform: translateY(6.75px) rotate(45deg); }
    .hamburger-btn.active .hamburger-lines span:nth-child(2) { opacity: 0; transform: translateX(-10px); }
    .hamburger-btn.active .hamburger-lines span:nth-child(3) { transform: translateY(-6.75px) rotate(-45deg); }

    .sidebar-overlay {
        display: none;
        position: fixed;
        inset: 0;
        background: rgba(15, 23, 42, 0.55);
        backdrop-filter: blur(4px);
        z-index: 998;
        opacity: 0;
        transition: opacity 0.3s ease;
    }
    .sidebar-overlay.active { display: block; opacity: 1; }

    /* ============================================================
       RESPONSIVE
       ============================================================ */
    @media (max-width: 900px) {
        .hamburger-btn { display: flex; }
        .sidebar {
            position: fixed;
            top: 0;
            right: 0;
            bottom: 0;
            width: 280px;
            max-width: 85vw;
            transform: translateX(105%);
            transition: transform 0.4s cubic-bezier(0.65, 0, 0.35, 1);
            z-index: 999;
            overflow-y: auto;
            box-shadow: -20px 0 50px rgba(0, 0, 0, 0.25);
        }
        .sidebar.open { transform: translateX(0); }
        .main-wrapper { width: 100%; }
        .topbar { padding: 12px 18px; gap: 12px; flex-wrap: nowrap; }
        .topbar h1 { font-size: 0.95rem; flex: 1; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .topbar .back-link { font-size: 0.78rem; max-width: 130px; }
        .content-area { padding: 16px; }
        .profile-grid { grid-template-columns: 1fr; }
    }

    @media (max-width: 700px) {
        .content-area { padding: 12px; }
        .topbar { padding: 10px 14px; }
        .topbar h1 { font-size: 0.88rem; }
        .topbar .back-link { display: none; }
        .hamburger-btn { width: 40px; height: 40px; }
        .analytics-grid { grid-template-columns: 1fr 1fr; gap: 10px; }
        .analytic-card { padding: 14px; flex-direction: column; align-items: flex-start; gap: 8px; position: relative; }
        .analytic-card .info div:last-child { font-size: 1.15rem; }
        .analytic-icon { width: 34px; height: 34px; font-size: 0.85rem; position: absolute; top: 12px; left: 12px; }

        .card { padding: 16px; }
        button.btn { padding: 11px 18px; font-size: 0.88rem; width: 100%; }

        .avatar-section { flex-direction: column; text-align: center; gap: 16px; padding: 20px 16px; }
        .avatar-preview { width: 100px; height: 100px; }
        .avatar-placeholder { font-size: 2.2rem; }
        .avatar-placeholder i { font-size: 2.5rem; }
        .avatar-btn-group { justify-content: center; }

        .privacy-row { padding: 14px; gap: 12px; }
        .privacy-icon { width: 38px; height: 38px; font-size: 0.9rem; }
        .privacy-title { font-size: 0.85rem; }
        .privacy-desc { font-size: 0.7rem; }
        .toggle-switch { width: 50px; height: 28px; }
        .toggle-slider::before { height: 20px; width: 20px; }
        .toggle-switch input:checked + .toggle-slider::before { transform: translateX(-22px); }
    }

    @media (max-width: 400px) {
        .content-area { padding: 10px; }
        .analytics-grid { grid-template-columns: 1fr; }
        .analytic-card { flex-direction: row; align-items: center; }
        .analytic-icon { position: static; align-self: center; }
        .privacy-row { flex-direction: column; align-items: stretch; gap: 12px; }
        .privacy-row .toggle-switch { align-self: flex-end; }
    }

    @media (prefers-reduced-motion: reduce) {
        .sidebar, .sidebar-overlay, .hamburger-btn, .hamburger-btn .hamburger-lines span,
        .toggle-slider, .toggle-slider::before, .avatar-preview {
            transition: none !important;
        }
    }
    
    /* ================= غیرفعال کردن انتخاب متن ================= */
body, .topbar, .card, .colleague-card, .follower-card, .blocked-card, .perm-option,
.search-result-card, button, a, .user-info, .colleague-info, .follower-info, .blocked-info {
    -webkit-user-select: none;
    -moz-user-select: none;
    -ms-user-select: none;
    user-select: none;
}

/* اجازه انتخاب متن در فیلدهای ورودی */
input, textarea {
    -webkit-user-select: text;
    -moz-user-select: text;
    -ms-user-select: text;
    user-select: text;
}

/* غیرفعال کردن درگ تصاویر و لینک‌ها */
img, a {
    -webkit-user-drag: none;
    -khtml-user-drag: none;
    -moz-user-drag: none;
    -o-user-drag: none;
    user-drag: none;
}
</style>
</head>

<body>

<?php sidebar(); ?>

<div class="main-wrapper">

    <header class="topbar">
        <button class="hamburger-btn" id="hamburgerBtn" onclick="toggleSidebar()" aria-label="باز کردن منو">
            <div class="hamburger-lines">
                <span></span><span></span><span></span>
            </div>
        </button>
        <h1>تنظیمات پروفایل کاربری</h1>
        <a href="../tasks/index.php" class="back-link">← بازگشت به مدیریت تسک‌ها</a>
    </header>

    <main class="content-area">

        <?php if (!empty($msg)): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle" style="margin-left:6px;"></i>
                <?= htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($error)): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle" style="margin-left:6px;"></i>
                <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <div class="analytics-grid">
            <div class="analytic-card">
                <div class="info">
                    <div>کل تسک‌ها</div>
                    <div><?= (int)$stats['total'] ?></div>
                </div>
                <div class="analytic-icon"><i class="fas fa-clipboard-list"></i></div>
            </div>
            <div class="analytic-card completed">
                <div class="info">
                    <div>انجام‌شده</div>
                    <div><?= (int)$stats['completed'] ?></div>
                </div>
                <div class="analytic-icon"><i class="fas fa-check"></i></div>
            </div>
            <div class="analytic-card pending">
                <div class="info">
                    <div>در انتظار</div>
                    <div><?= (int)$stats['pending'] ?></div>
                </div>
                <div class="analytic-icon"><i class="fas fa-hourglass-half"></i></div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <div class="card-header-icon" style="background:#f0fdf4; color:#059669;">
                    <i class="fas fa-user-circle"></i>
                </div>
                <h3>تصویر پروفایل</h3>
            </div>

            <div class="avatar-section">
                <div class="avatar-preview">
                    <?php if ($avatarUrl): ?>
                        <img src="<?= htmlspecialchars($avatarUrl, ENT_QUOTES, 'UTF-8') ?>" alt="تصویر پروفایل" id="avatarPreview">
                    <?php else: ?>
                        <div class="avatar-placeholder" id="avatarPreview">
                            <i class="fas fa-user"></i>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="avatar-actions">
                    <h4><?= htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8') ?></h4>
                    <p>
                        یک تصویر انتخاب کنید. حجم حداکثر <strong>۲ مگابایت</strong> و فرمت‌های مجاز
                        <strong>JPG</strong>، <strong>PNG</strong>، <strong>GIF</strong>، <strong>WebP</strong> می‌باشد.
                    </p>

                    <form method="POST" enctype="multipart/form-data" id="avatarForm" style="display: contents;">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                        <div class="avatar-btn-group">
                            <div class="file-input-wrapper">
                                <button type="button" class="btn" style="pointer-events:none;">
                                    <i class="fas fa-upload" style="margin-left:6px;"></i>
                                    انتخاب تصویر
                                </button>
                                <input type="file" name="avatar" accept="image/jpeg,image/png,image/gif,image/webp" onchange="previewAndSubmit(this)">
                            </div>

                            <?php if ($avatarUrl): ?>
                                <button type="submit" name="remove_avatar" value="1" class="btn btn-danger"
                                        onclick="return confirm('تصویر پروفایل حذف شود؟');">
                                    <i class="fas fa-trash" style="margin-left:6px;"></i>
                                    حذف تصویر
                                </button>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="profile-grid">

            <div class="card">
                <div class="card-header">
                    <div class="card-header-icon"><i class="fas fa-user-edit"></i></div>
                    <h3>ویرایش اطلاعات پایه</h3>
                </div>

                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                    <div class="form-group">
                        <label>شماره موبایل</label>
                        <input type="text" value="<?= htmlspecialchars((string)($currentUser['mobile'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" disabled>
                        <div class="password-hint">شماره موبایل قابل تغییر نیست.</div>
                    </div>

                    <div class="form-group">
                        <label>ایمیل</label>
                        <input type="email" name="email" value="<?= htmlspecialchars((string)($currentUser['email'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" maxlength="190">
                    </div>

                    <button type="submit" name="update_profile" class="btn">ذخیره تغییرات</button>
                </form>
            </div>

            <div class="card">
                <div class="card-header">
                    <div class="card-header-icon"><i class="fas fa-lock"></i></div>
                    <h3>تغییر رمز عبور</h3>
                </div>

                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                    <div class="form-group">
                        <label>رمز عبور فعلی</label>
                        <input type="password" name="current_password" required maxlength="200" autocomplete="current-password">
                    </div>

                    <div class="form-group">
                        <label>رمز عبور جدید</label>
                        <input type="password" name="new_password" required minlength="8" maxlength="200" autocomplete="new-password">
                        <div class="password-hint">پیشنهاد می‌شود از یک رمز عبور قوی استفاده کنید.</div>
                    </div>

                    <button type="submit" name="change_password" class="btn">تغییر رمز عبور</button>
                </form>
            </div>

            <div class="card" style="grid-column: 1 / -1;">
                <div class="card-header">
                    <div class="card-header-icon" style="background:#f0fdf4; color:#059669;">
                        <i class="fas fa-user-shield"></i>
                    </div>
                    <h3>حریم خصوصی و تنظیمات پروژه</h3>
                </div>

                <form method="POST" id="privacyForm">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="toggle_colleague_requests" value="1">
                    <input type="hidden" name="allow_colleague_requests" id="allowColleagueInput" value="<?= $allowColleagueRequests ?>">

                    <div class="privacy-row <?= $allowColleagueRequests ? '' : 'is-blocked' ?>" id="privacyRow">
                        <div class="privacy-info">
                            <div class="privacy-icon" id="privacyIcon">
                                <i class="fas <?= $allowColleagueRequests ? 'fa-user-check' : 'fa-user-slash' ?>"></i>
                            </div>
                            <div class="privacy-text">
                                <div class="privacy-title" id="privacyTitle">
                                    <?= $allowColleagueRequests ? 'دریافت درخواست همکاری فعال است' : 'دریافت درخواست همکاری مسدود است' ?>
                                </div>
                                <div class="privacy-desc" id="privacyDesc">
                                    <?= $allowColleagueRequests
                                        ? 'سایر کاربران می‌توانند با جستجوی شماره موبایل شما، شما را به عنوان همکار اضافه کنند.'
                                        : 'هیچ‌کس نمی‌تواند شما را به عنوان همکار اضافه کند. برای فعال‌سازی مجدد، کلید را روشن کنید.' ?>
                                </div>
                            </div>
                        </div>

                        <label class="toggle-switch">
                            <input type="checkbox" id="colleagueToggle" <?= $allowColleagueRequests ? 'checked' : '' ?> onchange="submitPrivacyToggle(this.checked)">
                            <span class="toggle-slider"></span>
                        </label>
                    </div>
                </form>

                <form method="POST" id="projectJoinForm" style="margin-top: 14px;">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="toggle_project_join_approval" value="1">
                    <input type="hidden" name="project_join_requires_approval" id="projectJoinRequiresInput" value="<?= $projectJoinRequiresApproval ?>">

                    <div class="privacy-row <?= $projectJoinNoApproval ? '' : 'is-info' ?>" id="projectJoinRow">
                        <div class="privacy-info">
                            <div class="privacy-icon" id="projectJoinIcon">
                                <i class="fas <?= $projectJoinNoApproval ? 'fa-user-plus' : 'fa-user-clock' ?>"></i>
                            </div>
                            <div class="privacy-text">
                                <div class="privacy-title" id="projectJoinTitle">
                                    <?= $projectJoinNoApproval
                                        ? 'عضویت در پروژه و درخواست همکاری بدون نیاز به تایید'
                                        : 'عضویت در پروژه و درخواست همکاری نیازمند تایید شماست' ?>
                                </div>
                                <div class="privacy-desc" id="projectJoinDesc">
                                    <?= $projectJoinNoApproval
                                        ? 'زمانی که یک کاربر شما را به پروژه اضافه می‌کند، بلافاصله عضو می‌شوید و نیازی به تایید شما نیست.'
                                        : 'زمانی که یک کاربر شما را به پروژه اضافه می‌کند، ابتدا باید درخواست عضویت را در صفحه پروژه‌ها تایید کنید تا عضو شوید.' ?>
                                </div>
                            </div>
                        </div>

                        <label class="toggle-switch">
                            <input type="checkbox" id="projectJoinToggle" <?= $projectJoinNoApproval ? 'checked' : '' ?> onchange="submitProjectJoinToggle(this.checked)">
                            <span class="toggle-slider"></span>
                        </label>
                    </div>

                    <div class="info-box">
                        <i class="fas fa-info-circle"></i>
                        <span>
                            وقتی این گزینه <strong>روشن</strong> باشد، هر کاربری که شما را به پروژه اضافه کند،
                            بلافاصله عضو می‌شوید. اگر <strong>خاموش</strong> باشد، ابتدا یک درخواست عضویت
                            در صفحه پروژه‌ها برای شما نمایش داده می‌شود و باید آن را تایید یا رد کنید.
                        </span>
                    </div>
                </form>
            </div>

        </div>

    </main>
</div>

<script>
    function submitPrivacyToggle(isChecked) {
        document.getElementById('allowColleagueInput').value = isChecked ? '1' : '0';
        document.getElementById('privacyForm').submit();
    }

    function submitProjectJoinToggle(isNoApproval) {
        document.getElementById('projectJoinRequiresInput').value = isNoApproval ? '0' : '1';
        document.getElementById('projectJoinForm').submit();
    }

    function previewAndSubmit(input) {
        if (!input.files || !input.files[0]) return;
        const file = input.files[0];

        if (file.size > 2 * 1024 * 1024) {
            alert('حجم تصویر نباید بیشتر از ۲ مگابایت باشد.');
            input.value = '';
            return;
        }

        const allowedClientTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        if (!allowedClientTypes.includes(file.type)) {
            alert('فرمت تصویر باید JPG، PNG، GIF یا WebP باشد.');
            input.value = '';
            return;
        }

        const reader = new FileReader();
        reader.onload = function(e) {
            const oldPreview = document.getElementById('avatarPreview');
            if (!oldPreview) return;
            const parent = oldPreview.parentNode;
            const img = document.createElement('img');
            img.id = 'avatarPreview';
            img.alt = 'پیش‌نمایش';
            img.style.width = '100%';
            img.style.height = '100%';
            img.style.objectFit = 'cover';
            img.src = e.target.result;
            parent.replaceChild(img, oldPreview);
        };
        reader.readAsDataURL(file);

        const form = document.getElementById('avatarForm');
        const oldAction = form.querySelector('input[name="upload_avatar"]');
        if (oldAction) oldAction.remove();

        const actionInput = document.createElement('input');
        actionInput.type = 'hidden';
        actionInput.name = 'upload_avatar';
        actionInput.value = '1';
        form.appendChild(actionInput);

        setTimeout(() => form.submit(), 400);
    }

    function toggleSidebar() {
        const sidebar = document.getElementById('appSidebar');
        const overlay = document.getElementById('sidebarOverlay');
        const btn = document.getElementById('hamburgerBtn');
        if (!sidebar || !overlay || !btn) return;
        const isOpen = sidebar.classList.contains('open');
        if (isOpen) closeSidebar();
        else {
            sidebar.classList.add('open');
            overlay.classList.add('active');
            btn.classList.add('active');
            document.body.style.overflow = 'hidden';
        }
    }

    function closeSidebar() {
        const sidebar = document.getElementById('appSidebar');
        const overlay = document.getElementById('sidebarOverlay');
        const btn = document.getElementById('hamburgerBtn');
        if (!sidebar || !overlay || !btn) return;
        sidebar.classList.remove('open');
        overlay.classList.remove('active');
        btn.classList.remove('active');
        document.body.style.overflow = '';
    }

    let resizeTimer;
    window.addEventListener('resize', function() {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(function() {
            if (window.innerWidth > 900) closeSidebar();
        }, 150);
    });
    
     /* ================== غیرفعال کردن راست کلیک ================== */
    document.addEventListener('contextmenu', function(e) {
        const tag = (e.target.tagName || '').toLowerCase();
        // اجازه دادن راست کلیک داخل input/textarea برای paste
        if (tag === 'input' || tag === 'textarea') return true;
        e.preventDefault();
        return false;
    });

    /* ================== غیرفعال کردن Select All (Ctrl+A) ================== */
    document.addEventListener('keydown', function(e) {
        // Ctrl+A فقط بیرون input/textarea غیرفعال بشه
        if ((e.ctrlKey || e.metaKey) && (e.key === 'a' || e.key === 'A')) {
            const tag = (e.target.tagName || '').toLowerCase();
            if (tag !== 'input' && tag !== 'textarea') {
                e.preventDefault();
                return false;
            }
        }

        // غیرفعال کردن Ctrl+U (View Source)
        if ((e.ctrlKey || e.metaKey) && (e.key === 'u' || e.key === 'U')) {
            e.preventDefault();
            return false;
        }

        // غیرفعال کردن Ctrl+P (Print)
        if ((e.ctrlKey || e.metaKey) && (e.key === 'p' || e.key === 'P')) {
            e.preventDefault();
            return false;
        }
    });

    /* ================== غیرفعال کردن درگ ================== */
    document.addEventListener('dragstart', function(e) {
        if (e.target.tagName === 'IMG' || e.target.tagName === 'A') {
            e.preventDefault();
        }
    });

    /* ================== Ctrl+S برای ذخیره‌سازی هوشمند ================== */
    document.addEventListener('keydown', function(e) {
        if ((e.ctrlKey || e.metaKey) && (e.key === 's' || e.key === 'S')) {
            e.preventDefault();
            e.stopPropagation();

            const activeEl = document.activeElement;

            // حالت ۱: focus روی فیلد جستجوی شماره موبایل → جستجو کن
            if (activeEl && activeEl.id === 'mobileSearchInput') {
                if (typeof searchUser === 'function') {
                    searchUser();
                }
                return false;
            }

            // حالت ۲: یک permissions panel باز هست → فرم ذخیره دسترسی‌ها رو submit کن
            const openPanel = document.querySelector('.permissions-panel.open');
            if (openPanel) {
                const saveForm = openPanel.querySelector('form');
                if (saveForm) {
                    if (typeof saveForm.requestSubmit === 'function') {
                        saveForm.requestSubmit();
                    } else {
                        saveForm.dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }));
                    }
                }
                return false;
            }

            // حالت ۳: اگر input/textarea دیگه‌ای focus داره، blur بشه
            if (activeEl && (activeEl.tagName === 'INPUT' || activeEl.tagName === 'TEXTAREA')) {
                activeEl.blur();
            }

            return false;
        }
    }, true);
</script>

</body>
</html>