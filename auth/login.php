<?php
// ==========================================================
// ⏰ تنظیمات سشن با طول عمر 10 روز
// این بلاک باید قبل از هر require_once و session_start اجرا شود
// ==========================================================
$sessionLifetime = 60 * 60 * 24 * 10; // 10 روز به ثانیه

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

// فقط اگر سشنی شروع نشده باشد
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
// ==========================================================

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../database/Database.php';

// اطمینان از اینکه اگر config.php سشن را ری‌استارت نکرده، همچنان تنظیمات ما پابرجاست
if (session_status() === PHP_SESSION_ACTIVE) {
    if (!isset($_COOKIE[session_name()]) || (int)ini_get('session.cookie_lifetime') < $sessionLifetime) {
        setcookie(session_name(), session_id(), [
            'expires'  => time() + $sessionLifetime,
            'path'     => '/',
            'domain'   => '',
            'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}

$error = '';
$success_msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // ================== ورود ==================
    if (isset($_POST['action_login'])) {
        $mobile = trim($_POST['mobile']);
        $password = $_POST['password'];

        // اعتبارسنجی سرور برای شماره موبایل
        if (!preg_match('/^09\d{9}$/', $mobile)) {
            $error = 'شماره موبایل باید با 09 شروع شده و 11 رقم باشد.';
        } else {
            $result = Auth::login($mobile, $password);
            if ($result['success']) {
                // 🔄 بازسازی شناسه سشن برای امنیت بیشتر (بعد از لاگین موفق)
                $oldSessionData = $_SESSION;
                session_regenerate_id(true);
                $_SESSION = $oldSessionData;

                // 🍪 پس از regenerate، کوکی را مجدداً با طول عمر 10 روز ست می‌کنیم
                setcookie(session_name(), session_id(), [
                    'expires'  => time() + $sessionLifetime,
                    'path'     => '/',
                    'domain'   => '',
                    'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
                    'httponly' => true,
                    'samesite' => 'Lax',
                ]);

                // 🚦 بررسی وضعیت کاربر
                $db = Database::getInstance();
                try {
                    $stmt = $db->prepare("SELECT status, role FROM users WHERE id = ? LIMIT 1");
                    $stmt->execute([(int)$_SESSION['user_id']]);
                    $userRow = $stmt->fetch(PDO::FETCH_ASSOC);
                    $userStatus = $userRow['status'] ?? 'pending';
                    $userRole = $userRow['role'] ?? 'user';
                } catch (PDOException $e) {
                    $userStatus = 'pending';
                    $userRole = 'user';
                }

                // 🎯 ریدایرکت بر اساس نقش و وضعیت
                if ($userRole === 'admin') {
                    // ادمین → پنل ادمین (فارغ از وضعیت)
                    header('Location: ../admin/index.php');
                    exit;
                }

                if ($userStatus === 'active') {
                    // کاربر فعال → تسک‌ها
                    header('Location: ../tasks/index.php');
                    exit;
                }

                // کاربر غیرفعال (pending / blocked / deactivated) → Pending
                header('Location: ../pending/index.php');
                exit;
            } else {
                $error = $result['message'];
            }
        }
    }
    // ================== ثبت‌نام ==================
    elseif (isset($_POST['action_register'])) {
        $mobile = trim($_POST['mobile']);
        $password = $_POST['password'];
        $email = trim($_POST['email'] ?? '');
        $firstName = trim($_POST['first_name'] ?? '');
        $lastName = trim($_POST['last_name'] ?? '');

        // اعتبارسنجی سرور برای شماره موبایل
        if (!preg_match('/^09\d{9}$/', $mobile)) {
            $error = 'شماره موبایل باید با 09 شروع شده و 11 رقم باشد.';
        } elseif (strlen($password) < 6) {
            $error = 'رمز عبور باید حداقل ۶ کاراکتر باشد.';
        } elseif (empty($firstName) || empty($lastName)) {
            $error = 'لطفاً نام و نام خانوادگی خود را وارد کنید.';
        } else {
            $result = Auth::register($mobile, $password, $email, $firstName, $lastName);
            if ($result['success']) {
                // 🔄 بازسازی شناسه سشن بعد از ثبت‌نام (برای امنیت)
                session_regenerate_id(true);

                // ============================================================
                // 🎯 پر کردن سشن کاربر تازه ثبت‌نام شده
                // ============================================================
                $db = Database::getInstance();
                try {
                    $stmt = $db->prepare("SELECT * FROM users WHERE mobile = ? LIMIT 1");
                    $stmt->execute([$mobile]);
                    $newUser = $stmt->fetch(PDO::FETCH_ASSOC);

                    if ($newUser) {
                        $_SESSION['user_id'] = (int)$newUser['id'];
                        $_SESSION['user_mobile'] = $newUser['mobile'];
                        $_SESSION['user_role'] = $newUser['role'] ?? 'user';
                        $_SESSION['user_status'] = $newUser['status'] ?? 'pending';

                        // کوکی را با طول عمر 10 روز ست کن
                        setcookie(session_name(), session_id(), [
                            'expires'  => time() + $sessionLifetime,
                            'path'     => '/',
                            'domain'   => '',
                            'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
                            'httponly' => true,
                            'samesite' => 'Lax',
                        ]);

                        // 🚀 ریدایرکت به صفحه Pending
                        header('Location: ../pending/index.php');
                        exit;
                    } else {
                        $error = 'خطا در بارگذاری اطلاعات کاربر.';
                    }
                } catch (PDOException $e) {
                    $error = 'خطا در سیستم. لطفاً دوباره تلاش کنید.';
                }
            } else {
                $error = $result['message'];
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <title>ورود / ثبت‌نام - مدیریت تسک‌ها</title>
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
        }
        .auth-container {
            width: 100%;
            max-width: 420px;
            background: var(--card);
            padding: 35px 30px;
            border-radius: 16px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.06);
            border: 1px solid var(--border);
            transition: all 0.3s ease;
        }
        .auth-container:hover {
            box-shadow: 0 12px 40px rgba(0,0,0,0.08);
        }
        h2 {
            color: var(--primary);
            text-align: center;
            margin-bottom: 25px;
            font-size: 1.5rem;
            font-weight: 700;
        }
        .form-group {
            margin-bottom: 18px;
        }
        label {
            display: block;
            margin-bottom: 6px;
            font-weight: 600;
            font-size: 0.9rem;
            color: #475569;
            letter-spacing: -0.2px;
        }
        input {
            width: 100%;
            padding: 12px 16px;
            background: #ffffff;
            border: 2px solid var(--border);
            border-radius: 12px;
            font-size: 0.95rem;
            font-family: Tahoma, sans-serif;
            color: #1e293b;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: inset 0 2px 4px rgba(0,0,0,0.01);
        }
        input:focus {
            outline: none;
            border-color: var(--accent);
            background: #ffffff;
            box-shadow: 0 0 0 4px rgba(37,99,235,0.12), inset 0 2px 4px rgba(0,0,0,0.01);
            transform: translateY(-1px);
        }

        /* =========================
           Mobile Input — Fixed 09 prefix
           ========================= */
        .mobile-input-wrap {
            position: relative;
        }
        .mobile-input-wrap input {
            direction: ltr;
            text-align: left;
            font-weight: 600;
            letter-spacing: 1.5px;
            font-family: Tahoma, sans-serif;
            padding-left: 16px;
        }
        .mobile-input-wrap input.valid {
            border-color: var(--success);
            background: #f0fdf4;
        }
        .mobile-input-wrap input.valid:focus {
            box-shadow: 0 0 0 4px rgba(16,185,129,0.12), inset 0 2px 4px rgba(0,0,0,0.01);
        }

        button {
            background: var(--accent);
            color: #fff;
            border: none;
            padding: 12px 20px;
            font-size: 1rem;
            border-radius: 12px;
            cursor: pointer;
            width: 100%;
            font-weight: 700;
            transition: all 0.2s ease;
            font-family: Tahoma, sans-serif;
            margin-top: 10px;
        }
        button:hover {
            background: var(--accent-hover);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(37,99,235,0.25);
        }
        button:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }
        .alert {
            padding: 12px 15px;
            border-radius: 10px;
            margin-bottom: 20px;
            font-weight: bold;
            font-size: 0.9rem;
            border: 1px solid transparent;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .alert.error {
            background: #fef2f2;
            color: #b91c1c;
            border-color: #fecaca;
        }
        .alert.success {
            background: #ecfdf5;
            color: #047857;
            border-color: #a7f3d0;
        }
        .toggle-link {
            text-align: center;
            margin-top: 20px;
            font-size: 0.9rem;
            color: #64748b;
            cursor: pointer;
            font-weight: 500;
            transition: 0.2s;
        }
        .toggle-link:hover {
            color: var(--primary);
        }
        .toggle-link span {
            color: var(--accent);
            font-weight: 700;
        }
        .toggle-link span:hover {
            text-decoration: underline;
        }
        .hidden {
            display: none !important;
        }
        .auth-container .form-group label i {
            margin-left: 6px;
            color: var(--accent);
        }
        .session-hint {
            text-align: center;
            font-size: 0.78rem;
            color: #94a3b8;
            margin-top: 15px;
        }
        .session-hint i {
            color: #64748b;
            margin-left: 4px;
        }
        .field-hint {
            font-size: 0.72rem;
            color: #94a3b8;
            margin-top: 5px;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        .field-hint i { color: var(--accent); }

        /* ============================================================
           RESPONSIVE
           ============================================================ */
        @media (max-width: 700px) {
            body { padding: 16px; align-items: flex-start; padding-top: 30px; }
            .auth-container {
                max-width: 100%;
                padding: 28px 22px;
                border-radius: 16px;
            }
            h2 { font-size: 1.3rem; margin-bottom: 20px; }
            label { font-size: 0.85rem; }
            input { padding: 11px 14px; font-size: 0.92rem; border-radius: 11px; }
            .mobile-input-wrap input { padding-left: 14px; }
            button { padding: 12px 18px; font-size: 0.95rem; }
            .alert { font-size: 0.85rem; padding: 11px 13px; }
            .toggle-link { font-size: 0.85rem; }
            .session-hint { font-size: 0.72rem; }
        }

        @media (max-width: 400px) {
            body { padding: 10px; padding-top: 20px; }
            .auth-container { padding: 22px 16px; border-radius: 14px; }
            h2 { font-size: 1.1rem; margin-bottom: 16px; }
            label { font-size: 0.82rem; margin-bottom: 5px; }
            input { padding: 10px 12px; font-size: 0.88rem; border-radius: 10px; }
            .mobile-input-wrap input { padding-left: 12px; letter-spacing: 1px; }
            button { padding: 11px 16px; font-size: 0.9rem; border-radius: 10px; }
            .form-group { margin-bottom: 14px; }
            .alert { font-size: 0.8rem; padding: 10px 12px; }
        }

        @media (prefers-reduced-motion: reduce) {
            .auth-container,
            input,
            button {
                transition: none !important;
            }
        }
    </style>
</head>
<body>
    <div class="auth-container">
        <h2 id="form-title">ورود به حساب کاربری</h2>
        <?php if (!empty($error)): ?>
            <div class="alert error">
                <i class="fas fa-exclamation-circle"></i>
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>
        <?php if (!empty($success_msg)): ?>
            <div class="alert success">
                <i class="fas fa-check-circle"></i>
                <?= htmlspecialchars($success_msg) ?>
            </div>
        <?php endif; ?>

        <!-- فرم ورود -->
        <form method="POST" id="login-form" autocomplete="off">
            <div class="form-group">
                <label><i class="fas fa-mobile-alt"></i> شماره موبایل</label>
                <div class="mobile-input-wrap">
                    <input type="tel"
                           name="mobile"
                           id="login-mobile"
                           value="09"
                           required
                           autocomplete="username"
                           inputmode="numeric"
                           maxlength="11"
                           data-mobile-input>
                </div>
                <div class="field-hint">
                    <i class="fas fa-info-circle"></i>
                    شماره باید با 09 شروع شده و 11 رقم باشد
                </div>
            </div>
            <div class="form-group">
                <label><i class="fas fa-lock"></i> رمز عبور</label>
                <input type="password" name="password" required autocomplete="current-password">
            </div>
            <button type="submit" name="action_login"><i class="fas fa-sign-in-alt"></i> ورود</button>
        </form>

        <!-- فرم ثبت‌نام -->
        <form method="POST" id="register-form" class="hidden" autocomplete="off">
            <div class="form-group">
                <label><i class="fas fa-user"></i> نام</label>
                <input type="text" name="first_name" placeholder="نام خود را وارد کنید" required>
            </div>
            <div class="form-group">
                <label><i class="fas fa-user"></i> نام خانوادگی</label>
                <input type="text" name="last_name" placeholder="نام خانوادگی خود را وارد کنید" required>
            </div>
            <div class="form-group">
                <label><i class="fas fa-mobile-alt"></i> شماره موبایل (نام کاربری)</label>
                <div class="mobile-input-wrap">
                    <input type="tel"
                           name="mobile"
                           id="register-mobile"
                           value="09"
                           required
                           autocomplete="username"
                           inputmode="numeric"
                           maxlength="11"
                           data-mobile-input>
                </div>
                <div class="field-hint">
                    <i class="fas fa-info-circle"></i>
                    شماره باید با 09 شروع شده و 11 رقم باشد
                </div>
            </div>
            <div class="form-group">
                <label><i class="fas fa-envelope"></i> ایمیل (اختیاری)</label>
                <input type="email" name="email" placeholder="example@domain.com" autocomplete="email">
            </div>
            <div class="form-group">
                <label><i class="fas fa-lock"></i> رمز عبور</label>
                <input type="password" name="password" required autocomplete="new-password" minlength="6">
                <div class="field-hint">
                    <i class="fas fa-info-circle"></i>
                    حداقل ۶ کاراکتر
                </div>
            </div>
            <button type="submit" name="action_register"><i class="fas fa-user-plus"></i> ثبت‌نام</button>
        </form>

        <div class="toggle-link" id="toggle-btn" onclick="toggleForms()">
            حساب کاربری ندارید؟ <span>ثبت‌نام کنید</span>
        </div>

        <div class="session-hint">
            <i class="fas fa-shield-alt"></i>
            نشست شما به مدت ۱۰ روز فعال باقی می‌ماند
        </div>
    </div>

    <script>
        /* ============================================================
           Mobile Input — Fixed 09 prefix (LTR)
           - پیشوند 09 قابل حذف نیست
           - چپ‌چین
           - فقط 11 رقم
           ============================================================ */
        const PERSIAN_DIGITS = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];
        const ARABIC_DIGITS  = ['٠','١','٢','٣','٤','٥','٦','٧','٨','٩'];
        const PREFIX = '09';

        function toEnglishDigits(str) {
            if (!str) return '';
            let result = String(str);
            PERSIAN_DIGITS.forEach((d, i) => {
                result = result.replace(new RegExp(d, 'g'), String(i));
            });
            ARABIC_DIGITS.forEach((d, i) => {
                result = result.replace(new RegExp(d, 'g'), String(i));
            });
            return result;
        }

        function setupMobileInput(input) {
            if (!input) return;

            const enforce = () => {
                let val = toEnglishDigits(input.value);

                // فقط اعداد
                val = val.replace(/\D/g, '');

                // همیشه با 09 شروع بشه
                if (!val.startsWith(PREFIX)) {
                    // اگه کاربر 9 رو تایپ کرد (بدون 0)
                    if (val.startsWith('9')) {
                        val = PREFIX + val.substring(1);
                    }
                    // اگه با 0 شروع شد ولی 09 نبود
                    else if (val.startsWith('0')) {
                        val = PREFIX + val.substring(1).replace(/^9/, '');
                    }
                    // اگه اصلاً هیچی نبود
                    else {
                        val = PREFIX + val;
                    }
                }

                // محدودیت 11 رقم
                if (val.length > 11) {
                    val = val.substring(0, 11);
                }

                // اعمال
                if (input.value !== val) {
                    input.value = val;
                    try {
                        const len = val.length;
                        input.setSelectionRange(len, len);
                    } catch (e) {}
                }

                // استایل valid وقتی 11 رقم کامل شد
                if (val.length === 11) {
                    input.classList.add('valid');
                } else {
                    input.classList.remove('valid');
                }
            };

            // input event
            input.addEventListener('input', enforce);

            // کلیدها
            input.addEventListener('keydown', function(e) {
                const allowedControlKeys = [
                    'Backspace', 'Delete', 'ArrowLeft', 'ArrowRight',
                    'Tab', 'Home', 'End', 'Enter'
                ];

                // اگر کلید کنترلی نیست و عدد هم نیست → بلاک
                if (!allowedControlKeys.includes(e.key) && !e.ctrlKey && !e.metaKey) {
                    if (!/^\d$/.test(e.key)) {
                        e.preventDefault();
                        return;
                    }
                }

                // 🚫 جلوگیری از حذف پیشوند 09 با Backspace
                if (e.key === 'Backspace') {
                    const selStart = input.selectionStart;
                    const selEnd = input.selectionEnd;

                    if (selStart === selEnd && selStart <= PREFIX.length) {
                        e.preventDefault();
                        return;
                    }

                    if (selStart !== selEnd && selStart < PREFIX.length) {
                        e.preventDefault();
                        return;
                    }
                }

                // 🚫 جلوگیری از حذف پیشوند با Delete
                if (e.key === 'Delete') {
                    const selStart = input.selectionStart;
                    const selEnd = input.selectionEnd;

                    if (selStart === selEnd && selStart < PREFIX.length) {
                        e.preventDefault();
                        return;
                    }

                    if (selStart !== selEnd && selStart < PREFIX.length) {
                        e.preventDefault();
                        return;
                    }
                }
            });

            // 🚫 جلوگیری از paste نامعتبر
            input.addEventListener('paste', function(e) {
                e.preventDefault();
                const pasted = (e.clipboardData || window.clipboardData).getData('text');
                const cleaned = toEnglishDigits(pasted).replace(/\D/g, '');
                if (!cleaned) return;

                // حذف پیشوند اضافی از pasted
                let digits = cleaned;
                if (digits.startsWith(PREFIX)) {
                    digits = digits.substring(2);
                } else if (digits.startsWith('9')) {
                    digits = digits.substring(1);
                } else if (digits.startsWith('0')) {
                    digits = digits.substring(1);
                }

                const currentVal = input.value || PREFIX;
                const newVal = (currentVal + digits).substring(0, 11);
                input.value = newVal;
                enforce();

                try {
                    const len = input.value.length;
                    input.setSelectionRange(len, len);
                } catch (err) {}
            });

            // 🚫 جلوگیری از drag & drop متن
            input.addEventListener('drop', function(e) {
                e.preventDefault();
            });

            // انتخاب کرسر: اگر کاربر قبل از پیشوند کلیک کرد، ببر انتهای متن
            input.addEventListener('click', function() {
                setTimeout(() => {
                    if (input.selectionStart < PREFIX.length) {
                        const len = input.value.length;
                        try {
                            input.setSelectionRange(len, len);
                        } catch (e) {}
                    }
                }, 0);
            });

            // مقدار اولیه
            if (!input.value || !input.value.startsWith(PREFIX)) {
                input.value = PREFIX;
            }
            enforce();
        }

        // اعمال روی همه‌ی فیلدهای موبایل
        document.querySelectorAll('[data-mobile-input]').forEach(setupMobileInput);

        /* ============================================================
           Toggle Forms
           ============================================================ */
        function toggleForms() {
            const loginForm = document.getElementById('login-form');
            const regForm = document.getElementById('register-form');
            const title = document.getElementById('form-title');
            const toggleBtn = document.getElementById('toggle-btn');

            if (loginForm.classList.contains('hidden')) {
                loginForm.classList.remove('hidden');
                regForm.classList.add('hidden');
                title.innerText = 'ورود به حساب کاربری';
                toggleBtn.innerHTML = 'حساب کاربری ندارید؟ <span>ثبت‌نام کنید</span>';
            } else {
                loginForm.classList.add('hidden');
                regForm.classList.remove('hidden');
                title.innerText = 'ثبت‌نام در سیستم';
                toggleBtn.innerHTML = 'قبلاً ثبت‌نام کرده‌اید؟ <span>وارد شوید</span>';
            }
        }

        /* ============================================================
           اعتبارسنجی نهایی قبل از submit
           ============================================================ */
        document.querySelectorAll('form').forEach(form => {
            form.addEventListener('submit', function(e) {
                const mobileInput = form.querySelector('[data-mobile-input]');
                if (mobileInput) {
                    const val = mobileInput.value;
                    if (!/^09\d{9}$/.test(val)) {
                        e.preventDefault();
                        alert('شماره موبایل باید با 09 شروع شده و 11 رقم باشد.');
                        mobileInput.focus();
                        return false;
                    }
                }
            });
        });
    </script>
</body>
</html>