<?php
if (isset($_SESSION['impersonator']) && !empty($_SESSION['impersonator'])):
    $impName = $_SESSION['impersonated_user_name'] ?? 'کاربر';
    $adminName = trim(($_SESSION['impersonator']['first_name'] ?? '') . ' ' . ($_SESSION['impersonator']['last_name'] ?? ''));
    if ($adminName === '') $adminName = 'مدیر';

    // محاسبه BASE_URL اگه تعریف نشده باشه
    if (!defined('BASE_URL')) {
        $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
        // اگه داخل admin هستیم، یه پله بالا
        if (preg_match('#/admin$#', $scriptDir)) {
            $scriptDir = dirname($scriptDir);
        }
        define('BASE_URL', rtrim($scriptDir, '/'));
    }

    $exitUrl = BASE_URL . '/admin/index.php?exit_impersonate=1';
?>
<div id="impersonateBanner" style="
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    background: linear-gradient(135deg, #ef4444, #dc2626);
    color: #fff;
    padding: 10px 20px;
    z-index: 99999;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    font-family: Tahoma, sans-serif;
    font-size: 0.85rem;
    font-weight: bold;
    box-shadow: 0 4px 16px rgba(239, 68, 68, 0.4);
    animation: impSlideDown 0.4s ease;
">
    <div style="display: flex; align-items: center; gap: 10px;">
        <i class="fas fa-user-secret" style="font-size: 1.1rem;"></i>
        <span>
            در حال مشاهده پنل <strong><?= htmlspecialchars($impName) ?></strong>
            <span style="opacity:0.85; font-size: 0.78rem; margin-right: 6px;">(ورود توسط: <?= htmlspecialchars($adminName) ?>)</span>
        </span>
    </div>
    <a href="<?= htmlspecialchars($exitUrl) ?>" style="
        background: rgba(255,255,255,0.22);
        color: #fff;
        text-decoration: none;
        padding: 7px 14px;
        border-radius: 8px;
        font-weight: bold;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        font-size: 0.82rem;
        border: 1px solid rgba(255,255,255,0.35);
        transition: all 0.2s ease;
    " onmouseover="this.style.background='rgba(255,255,255,0.35)'" onmouseout="this.style.background='rgba(255,255,255,0.22)'">
        <i class="fas fa-arrow-left"></i>
        بازگشت به پنل مدیریت
    </a>
</div>
<style>
    @keyframes impSlideDown {
        from { transform: translateY(-100%); }
        to { transform: translateY(0); }
    }
    body { padding-top: 48px !important; }
</style>
<?php endif; ?>