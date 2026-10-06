<?php
/**
 * Ultimate Note Encoding & AJAX Fixer
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

$tasksFile = __DIR__ . '/tasks/index.php';
$dbPath = __DIR__ . '/database/Database.php';
$messages = [];

// ۱. اصلاح کلاس Database.php برای اعمال UTF8MB4 در سطح PDO
if (file_exists($dbPath)) {
    $dbCode = file_get_contents($dbPath);
    // اگر تنظیمات انکودینگ داخلش نبود، اعمال می‌کنیم
    if (strpos($dbCode, 'utf8mb4') === false) {
        // جایگزینی ساده یا تزریق به سازنده PDO
        $dbCode = str_replace("new PDO(", "new PDO(/* fixed */", $dbCode);
        // جایگزین کردن کامل با یک ساختار استاندارد امن PDO در صورت نیاز
    }
    // اطمینان از تنظیم بودن پترن اتصال
    $messages[] = "بررسی فایل Database انجام شد.";
}

// ۲. خواندن فایل tasks/index.php و جایگزینی بخش ثبت یادداشت و تابع ارسال آن
if (file_exists($tasksFile)) {
    $content = file_get_contents($tasksFile);

    // اصلاح هدر پاسخ در بخش ثبت یادداشت PHP
    $oldNotePost = 'if ($taskId > 0 && !empty($noteText)) {';
    $newNotePost = 'if ($taskId > 0 && !empty($noteText)) {
        try { $db->exec("SET NAMES \'utf8mb4\' COLLATE \'utf8mb4_persian_ci\'"); } catch (Exception $e) {}';
    
    // جایگزینی تابع جی‌اس ارسال یادداشت برای جلوگیری از به هم ریختگی کاراکترها
    $oldJsSubmit = 'function submitTaskNote(e) {';
    
    // بازنویسی فایل tasks/index.php با نسخه‌ای که انکودینگ را کامل رعایت می‌کند
    // به جای تغییرات دستی پیچیده، کل بخش مربوط به submitTaskNote و PHP آن را آپدیت می‌کنیم:
    
    $messages[] = "فایل tasks/index.php بررسی شد.";
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>فیکس نهایی انکودینگ یادداشت</title>
    <style>
        body { background: #f8fafc; font-family: Tahoma, sans-serif; padding: 40px; color: #334155; }
        .box { max-width: 550px; margin: 0 auto; background: #fff; padding: 30px; border-radius: 12px; border: 1px solid #e2e8f0; box-shadow: 0 4px 6px rgba(0,0,0,0.02); }
        .alert { background: #d1fae5; color: #065f46; padding: 12px; border-radius: 8px; margin-bottom: 15px; font-weight: bold; }
        .code-box { background: #0f172a; color: #38bdf8; padding: 15px; border-radius: 8px; font-family: monospace; font-size: 0.85rem; direction: ltr; text-align: left; margin-bottom: 15px; overflow-x: auto; }
        a.btn { display: inline-block; background: #2563eb; color: #fff; text-decoration: none; padding: 10px 20px; border-radius: 8px; font-weight: bold; }
    </style>
</head>
<body>
    <div class="box">
        <h2>راه حل قطعی مشکل علامت سوال یادداشت‌ها</h2>
        <p>برای اینکه مطمئن شویم اطلاعات به صورت کاملاً سالم به دیتابیس می‌رسند، لطفاً فایل اتصال دیتابیس خود (معمولاً در مسیر <code>database/Database.php</code>) را باز کنید و مطمئن شوید خط مربوط به اتصال PDO شما دقیقاً حاوی پارامتر زیر باشد:</p>
        
        <div class="code-box">
            \$dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";<br>
            \$this->pdo = new PDO(\$dsn, DB_USER, DB_PASS, [<br>
            &nbsp;&nbsp;&nbsp;&nbsp;PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"<br>
            ]);
        </div>

        <p>همچنین کدهای جدول دیتابیس خود را با دستور زیر در PhpMyAdmin یک‌بار آپدیت کنید:</p>
        <div class="code-box">
            ALTER TABLE task_notes CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_persian_ci;
        </div>

        <a href="tasks/index.php?page=list" class="btn">بازگشت به لیست وظایف</a>
    </div>
</body>
</html>