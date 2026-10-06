<?php
// ==========================================================
// ⏰ تنظیمات سشن با طول عمر 10 روز
// ==========================================================
$sessionLifetime = 60 * 60 * 24 * 10;

ini_set('session.gc_maxlifetime', $sessionLifetime);
ini_set('session.cookie_lifetime', $sessionLifetime);
ini_set('session.use_strict_mode', 1);
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);

session_set_cookie_params([
    'lifetime' => $sessionLifetime,
    'path'     => '/',
    'domain'   => '',
    'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    'httponly' => true,
    'samesite' => 'Lax',
]);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../database/Database.php';

// اگه کاربر لاگین نکرده، بفرست به لاگین
if (!Auth::check()) {
    header('Location: ../auth/login.php');
    exit;
}

$db = Database::getInstance();
$userId = (int)$_SESSION['user_id'];

// واکشی اطلاعات کاربر برای نمایش
try {
    $stmt = $db->prepare("SELECT first_name, last_name, mobile, status FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$userId]);
    $currentUser = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $currentUser = null;
}

// اگه کاربر پیدا نشد، خارج شو
if (!$currentUser) {
    header('Location: ../auth/login.php?logout=1');
    exit;
}

$status = $currentUser['status'] ?? 'pending';

// اگه وضعیت کاربر فعال شد، بفرست به تسک‌ها
if ($status === 'active') {
    if (($_SESSION['user_role'] ?? '') === 'admin') {
        header('Location: ../admin/index.php');
    } else {
        header('Location: ../tasks/index.php');
    }
    exit;
}

// اگه ادمین بود، بفرست به پنل ادمین
if (($_SESSION['user_role'] ?? '') === 'admin') {
    header('Location: ../admin/index.php');
    exit;
}

$fullName = trim(($currentUser['first_name'] ?? '') . ' ' . ($currentUser['last_name'] ?? ''));
if ($fullName === '') $fullName = $currentUser['mobile'] ?? 'کاربر';

$displayUser = $_SESSION['user_mobile'] ?? $_SESSION['mobile'] ?? $_SESSION['username'] ?? $fullName;

// متن پیام بر اساس وضعیت
$statusMessages = [
    'pending' => [
        'icon'  => 'fa-hourglass-half',
        'color' => '#f59e0b',
        'bg'    => '#fef3c7',
        'title' => 'در انتظار تأیید',
        'text'  => 'فروشگاه شما در انتظار فعال‌سازی توسط پشتیبانی می‌باشد.',
        'desc'  => 'پس از بررسی اطلاعات توسط تیم پشتیبانی، حساب کاربری شما فعال خواهد شد. این فرآیند معمولاً کمتر از ۲۴ ساعت طول می‌کشد.'
    ],
    'deactivated' => [
        'icon'  => 'fa-circle-pause',
        'color' => '#64748b',
        'bg'    => '#f1f5f9',
        'title' => 'حساب کاربری غیرفعال',
        'text'  => 'حساب کاربری شما غیرفعال شده است.',
        'desc'  => 'برای فعال‌سازی مجدد، لطفاً با پشتیبانی تماس بگیرید.'
    ],
    'blocked' => [
        'icon'  => 'fa-ban',
        'color' => '#ef4444',
        'bg'    => '#fee2e2',
        'title' => 'حساب کاربری مسدود',
        'text'  => 'حساب کاربری شما مسدود شده است.',
        'desc'  => 'برای پیگیری، لطفاً با پشتیبانی تماس بگیرید.'
    ],
];

$info = $statusMessages[$status] ?? $statusMessages['pending'];
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <title>در انتظار تأیید — مدیریت تسک‌ها</title>
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
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            margin: 0;
            padding: 20px;
            background-image:
                radial-gradient(circle at 15% 20%, rgba(37,99,235,0.05) 0%, transparent 45%),
                radial-gradient(circle at 85% 80%, rgba(245,158,11,0.06) 0%, transparent 45%);
        }

        .pending-container {
            width: 100%;
            max-width: 520px;
            background: var(--card);
            padding: 40px 35px;
            border-radius: 24px;
            box-shadow: 0 12px 48px rgba(0,0,0,0.08);
            border: 1px solid var(--border);
            text-align: center;
            animation: fadeInUp 0.5s ease;
        }

        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .status-icon-wrap {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            background: <?= htmlspecialchars($info['bg']) ?>;
            color: <?= htmlspecialchars($info['color']) ?>;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 24px;
            font-size: 2.8rem;
            position: relative;
            animation: pulseIcon 2.5s ease-in-out infinite;
        }

        @keyframes pulseIcon {
            0%, 100% { transform: scale(1); box-shadow: 0 0 0 0 <?= htmlspecialchars($info['color']) ?>25; }
            50% { transform: scale(1.03); box-shadow: 0 0 0 20px <?= htmlspecialchars($info['color']) ?>00; }
        }

        .status-icon-wrap::before {
            content: '';
            position: absolute;
            inset: -8px;
            border-radius: 50%;
            border: 2px dashed <?= htmlspecialchars($info['color']) ?>40;
            animation: rotateRing 20s linear infinite;
        }

        @keyframes rotateRing {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: <?= htmlspecialchars($info['bg']) ?>;
            color: <?= htmlspecialchars($info['color']) ?>;
            padding: 6px 16px;
            border-radius: 20px;
            font-size: 0.82rem;
            font-weight: bold;
            margin-bottom: 18px;
        }
        .status-badge i { font-size: 0.75rem; }

        h1 {
            color: var(--primary);
            font-size: 1.5rem;
            font-weight: 700;
            margin: 0 0 12px;
        }

        .main-message {
            font-size: 1.05rem;
            font-weight: 600;
            color: #475569;
            line-height: 1.7;
            margin: 0 0 16px;
            padding: 16px 18px;
            background: #f8fafc;
            border-radius: 14px;
            border-right: 4px solid <?= htmlspecialchars($info['color']) ?>;
            text-align: right;
        }

        .main-message i {
            color: <?= htmlspecialchars($info['color']) ?>;
            margin-left: 6px;
        }

        .description {
            font-size: 0.88rem;
            color: #64748b;
            line-height: 1.9;
            margin: 0 0 26px;
            text-align: right;
        }

        .user-info {
            background: linear-gradient(135deg, #f8fafc, #f1f5f9);
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 14px 18px;
            margin-bottom: 26px;
            display: flex;
            align-items: center;
            gap: 12px;
            text-align: right;
        }

        .user-avatar {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            background: linear-gradient(135deg, #64748b, #475569);
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
            font-weight: bold;
            flex-shrink: 0;
        }

        .user-info-text {
            flex: 1;
            min-width: 0;
        }

        .user-info-name {
            font-weight: bold;
            font-size: 0.92rem;
            color: #1e293b;
            margin-bottom: 3px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .user-info-mobile {
            font-size: 0.78rem;
            color: #64748b;
            direction: ltr;
            text-align: right;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            justify-content: center;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 12px 24px;
            border-radius: 12px;
            font-size: 0.92rem;
            font-weight: bold;
            font-family: Tahoma, sans-serif;
            border: none;
            cursor: pointer;
            transition: all 0.25s ease;
            text-decoration: none;
            flex: 1;
            min-width: 140px;
        }

        .btn-primary {
            background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
            color: #fff;
            box-shadow: 0 4px 14px rgba(37, 99, 235, 0.28);
        }
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(37, 99, 235, 0.38);
        }

        .btn-ghost {
            background: #f1f5f9;
            color: #475569;
            border: 1px solid var(--border);
        }
        .btn-ghost:hover {
            background: #e2e8f0;
            color: #1e293b;
            transform: translateY(-2px);
        }

        .support-hint {
            margin-top: 24px;
            padding-top: 18px;
            border-top: 1px dashed var(--border);
            font-size: 0.78rem;
            color: #94a3b8;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        .support-hint i { color: var(--accent); }

        /* ============================================================
           RESPONSIVE
           ============================================================ */
        @media (max-width: 600px) {
            body { padding: 14px; align-items: flex-start; padding-top: 30px; }
            .pending-container {
                padding: 30px 22px;
                border-radius: 20px;
            }
            .status-icon-wrap {
                width: 84px;
                height: 84px;
                font-size: 2.3rem;
                margin-bottom: 20px;
            }
            h1 { font-size: 1.25rem; }
            .main-message {
                font-size: 0.95rem;
                padding: 14px 16px;
            }
            .description { font-size: 0.82rem; line-height: 1.8; }
            .actions { flex-direction: column; }
            .btn { width: 100%; }
        }

        @media (max-width: 400px) {
            body { padding: 10px; padding-top: 20px; }
            .pending-container { padding: 24px 16px; border-radius: 16px; }
            .status-icon-wrap { width: 72px; height: 72px; font-size: 2rem; }
            h1 { font-size: 1.1rem; }
            .main-message { font-size: 0.88rem; padding: 12px 14px; }
            .user-avatar { width: 38px; height: 38px; font-size: 0.9rem; }
            .user-info-name { font-size: 0.86rem; }
        }

        @media (prefers-reduced-motion: reduce) {
            .pending-container,
            .status-icon-wrap,
            .status-icon-wrap::before,
            .btn {
                animation: none !important;
                transition: none !important;
            }
        }
    </style>
</head>
<body>
    <div class="pending-container">

        <div class="status-icon-wrap">
            <i class="fas <?= htmlspecialchars($info['icon']) ?>"></i>
        </div>

        <div class="status-badge">
            <i class="fas fa-circle" style="font-size: 0.5rem;"></i>
            <?= htmlspecialchars($info['title']) ?>
        </div>

        <h1><?= htmlspecialchars($fullName) ?> عزیز، خوش آمدید</h1>

        <div class="main-message">
            <i class="fas fa-info-circle"></i>
            <?= htmlspecialchars($info['text']) ?>
        </div>

        <p class="description">
            <?= htmlspecialchars($info['desc']) ?>
        </p>

        <div class="user-info">
            <div class="user-avatar">
                <?= htmlspecialchars(mb_substr($fullName, 0, 1, 'UTF-8')) ?>
            </div>
            <div class="user-info-text">
                <div class="user-info-name"><?= htmlspecialchars($fullName) ?></div>
                <div class="user-info-mobile">
                    <i class="fas fa-mobile-alt"></i>
                    <?= htmlspecialchars($currentUser['mobile'] ?? '') ?>
                </div>
            </div>
        </div>

        <div class="actions">
            <a href="check_status.php" class="btn btn-primary">
                <i class="fas fa-sync-alt"></i>
                بررسی مجدد وضعیت
            </a>
            <a href="../auth/login.php?logout=1" class="btn btn-ghost">
                <i class="fas fa-sign-out-alt"></i>
                خروج از حساب
            </a>
        </div>

        <div class="support-hint">
            <i class="fas fa-headset"></i>
            در صورت نیاز، با پشتیبانی تماس بگیرید
        </div>

    </div>

    <script>
        // بررسی خودکار وضعیت هر 30 ثانیه
        let autoCheckTimer = null;

        function startAutoCheck() {
            if (autoCheckTimer) clearInterval(autoCheckTimer);
            autoCheckTimer = setInterval(() => {
                fetch('check_status.php?ajax=1', {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                })
                .then(r => r.json())
                .then(data => {
                    if (data.status === 'active') {
                        // اگه فعال شد، برو به تسک‌ها
                        window.location.href = '../tasks/index.php';
                    } else if (data.status === 'admin') {
                        window.location.href = '../admin/index.php';
                    }
                })
                .catch(() => {});
            }, 30000);
        }

        startAutoCheck();
    </script>
</body>
</html>