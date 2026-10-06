<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../database/Database.php';
require_once __DIR__ . '/../includes/sidebar.php';
require_once __DIR__ . '/../includes/quick_access.php';
require_once __DIR__ . '/../includes/chat_notifications.php';

if (!Auth::check()) {
    header('Location: ../auth/login.php');
    exit;
}

$db = Database::getInstance();

try {
    $db->exec("SET NAMES 'utf8mb4'");
} catch (PDOException $e) {}

$userId = (int)$_SESSION['user_id'];
$page = 'webservice';

// ================== CSRF ==================
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

function verifyCsrf(): bool {
    if (!isset($_POST['csrf_token'], $_SESSION['csrf_token'])) return false;
    return hash_equals($_SESSION['csrf_token'], (string)$_POST['csrf_token']);
}

function redirectSelf(string $msg = '', string $type = 'success'): void {
    if ($msg !== '') {
        $_SESSION['flash_msg'] = $msg;
        $_SESSION['flash_type'] = $type;
    }
    header('Location: index.php');
    exit;
}

// ================== توکن جدید ==================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_token'])) {
    if (!verifyCsrf()) redirectSelf('درخواست نامعتبر است.', 'danger');

    $name = trim((string)($_POST['token_name'] ?? ''));
    if ($name === '') $name = 'بدون نام';
    if (mb_strlen($name) > 100) $name = mb_substr($name, 0, 100);

    // حداکثر ۵ توکن فعال برای هر کاربر
    $countStmt = $db->prepare("SELECT COUNT(*) FROM api_tokens WHERE user_id = ? AND is_active = 1");
    $countStmt->execute([$userId]);
    if ((int)$countStmt->fetchColumn() >= 5) {
        redirectSelf('حداکثر ۵ توکن فعال مجاز است. ابتدا یکی را حذف کنید.', 'warning');
    }

    $token = 'tk_' . bin2hex(random_bytes(24)); // ~51 کاراکتر

    try {
        $stmt = $db->prepare("INSERT INTO api_tokens (user_id, token, name) VALUES (?, ?, ?)");
        $stmt->execute([$userId, $token, $name]);

        $_SESSION['new_token_shown'] = $token;
        redirectSelf('توکن با موفقیت ساخته شد. همین حالا کپی کنید — بعداً نمایش داده نمی‌شود!');
    } catch (PDOException $e) {
        redirectSelf('خطا در ساخت توکن.', 'danger');
    }
}

// ================== حذف توکن ==================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_token'])) {
    if (!verifyCsrf()) redirectSelf('درخواست نامعتبر است.', 'danger');
    $tokenId = (int)$_POST['token_id'];
    $stmt = $db->prepare("DELETE FROM api_tokens WHERE id = ? AND user_id = ?");
    $stmt->execute([$tokenId, $userId]);
    redirectSelf('توکن حذف شد.');
}

// ================== فعال/غیرفعال ==================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_token'])) {
    if (!verifyCsrf()) redirectSelf('درخواست نامعتبر است.', 'danger');
    $tokenId = (int)$_POST['token_id'];
    $stmt = $db->prepare("UPDATE api_tokens SET is_active = 1 - is_active WHERE id = ? AND user_id = ?");
    $stmt->execute([$tokenId, $userId]);
    redirectSelf('وضعیت توکن تغییر کرد.');
}

// ================== واکشی توکن‌ها ==================
$tokens = [];
try {
    $stmt = $db->prepare("
        SELECT id, token, name, last_used_at, last_ip, is_active, created_at
        FROM api_tokens
        WHERE user_id = ?
        ORDER BY is_active DESC, created_at DESC
    ");
    $stmt->execute([$userId]);
    $tokens = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('tokens load error: ' . $e->getMessage());
}

// پیام فلش
$msg = '';
$msgType = 'success';
if (!empty($_SESSION['flash_msg'])) {
    $msg = (string)$_SESSION['flash_msg'];
    $msgType = (string)($_SESSION['flash_type'] ?? 'success');
    unset($_SESSION['flash_msg'], $_SESSION['flash_type']);
}

// توکنی که همین الان ساخته شده
$newToken = '';
if (!empty($_SESSION['new_token_shown'])) {
    $newToken = (string)$_SESSION['new_token_shown'];
    unset($_SESSION['new_token_shown']);
}

$displayUser = $_SESSION['user_mobile'] ?? $_SESSION['mobile'] ?? $_SESSION['username'] ?? 'کاربر';

require_once __DIR__ . '/../includes/sidebar.php';
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <title>توکن‌های API</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="icon" href="https://img.icons8.com/color/48/dashboard-layout.png" type="image/png">
<style>
    :root { --primary: #1e293b; --accent: #2563eb; --bg: #f8fafc; --card: #ffffff; --text: #334155; --border: #e2e8f0; --success: #10b981; --warning: #f59e0b; --danger: #ef4444; }
    * { box-sizing: border-box; }
    html, body { max-width: 100%; overflow-x: hidden; }
    body { background: var(--bg); color: var(--text); font-family: Tahoma, sans-serif; margin: 0; display: flex; height: 100vh; overflow: hidden; }

    .sidebar { width: 260px; background: var(--primary); color: #fff; display: flex; flex-direction: column; padding: 20px; gap: 15px; z-index: 10; flex-shrink: 0; height: 100vh; overflow-y: auto; }
    .sidebar-brand { font-size: 1.1rem; font-weight: bold; display: flex; align-items: center; gap: 10px; padding-bottom: 15px; border-bottom: 1px solid rgba(255,255,255,0.1); color: #f8fafc; }
    .menu-item { display: flex; align-items: center; gap: 12px; padding: 12px 15px; color: #94a3b8; text-decoration: none; border-radius: 8px; font-weight: bold; font-size: 0.9rem; transition: background-color 0.15s ease, color 0.15s ease; }
    .menu-item:hover, .menu-item.active { background: rgba(255,255,255,0.08); color: #fff; }
    .submenu { padding-right: 25px; display: flex; flex-direction: column; gap: 5px; }
    .submenu a { color: #94a3b8; text-decoration: none; font-size: 0.85rem; padding: 8px 12px; border-radius: 6px; transition: background-color 0.15s ease, color 0.15s ease; display: flex; align-items: center; gap: 8px; }
    .submenu a:hover, .submenu a.active { color: #fff; background: rgba(255,255,255,0.05); }

    .main-wrapper { flex: 1; display: flex; flex-direction: column; overflow: hidden; min-width: 0; }
    .topbar { background: var(--card); padding: 15px 30px; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; gap: 12px; }
    .topbar h1 { margin: 0; font-size: 1.2rem; color: var(--primary); }
    .content-area { flex: 1; padding: 25px; overflow-y: auto; }

    .card { background: var(--card); border: 1px solid #e2e8f0; border-radius: 20px; padding: 24px; margin-bottom: 20px; }
    .card h3 { margin-top: 0; font-size: 1.05rem; color: var(--primary); margin-bottom: 18px; display: flex; align-items: center; gap: 8px; }

    .alert { padding: 12px 16px; border-radius: 12px; margin-bottom: 18px; font-weight: bold; font-size: 0.9rem; display: flex; align-items: center; gap: 10px; }
    .alert-success { background: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; }
    .alert-warning { background: #fef3c7; color: #92400e; border: 1px solid #fde68a; }
    .alert-danger { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }

    .form-group { margin-bottom: 18px; }
    label { display: block; margin-bottom: 8px; font-weight: 600; font-size: 0.9rem; color: #475569; }

    input[type="text"] {
        width: 100%; padding: 12px 18px; background: #f8fafc;
        border: 2px solid #e2e8f0; border-radius: 14px;
        font-size: 0.95rem; font-family: Tahoma, sans-serif; color: #1e293b;
        transition: border-color 0.2s ease, box-shadow 0.2s ease, background-color 0.2s ease;
    }
    input[type="text"]:focus { outline: none; border-color: var(--accent); background: #fff; box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.12); }

    button.btn { background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%); color: #fff; border: none; padding: 11px 22px; border-radius: 12px; font-weight: bold; cursor: pointer; font-family: Tahoma, sans-serif; transition: transform 0.15s; }
    button.btn:hover { background: linear-gradient(135deg, #1d4ed8 0%, #1e40af 100%); }
    button.btn:active { transform: scale(0.98); }
    button.btn-danger { background: #fef2f2; color: var(--danger); border: 1px solid #fee2e2; }
    button.btn-danger:hover { background: var(--danger); color: #fff; }
    button.btn-sm { padding: 6px 12px; font-size: 0.78rem; border-radius: 9px; }
    button.btn-ghost { background: #f1f5f9; color: #64748b; }
    button.btn-ghost:hover { background: #e2e8f0; color: #334155; }

    .token-card { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 16px; padding: 16px 18px; margin-bottom: 12px; display: flex; align-items: center; justify-content: space-between; gap: 14px; flex-wrap: wrap; }
    .token-card.inactive { opacity: 0.6; background: #f1f5f9; }
    .token-card__main { flex: 1; min-width: 240px; }
    .token-card__name { font-weight: bold; font-size: 0.95rem; color: #1e293b; margin-bottom: 6px; display: flex; align-items: center; gap: 8px; }
    .token-card__token { font-family: 'Courier New', monospace; font-size: 0.8rem; color: #64748b; direction: ltr; word-break: break-all; background: #fff; padding: 8px 12px; border-radius: 8px; border: 1px solid #e2e8f0; margin-bottom: 6px; }
    .token-card__meta { font-size: 0.72rem; color: #94a3b8; display: flex; gap: 14px; flex-wrap: wrap; }
    .token-card__meta span { display: inline-flex; align-items: center; gap: 4px; }
    .token-card__actions { display: flex; gap: 6px; flex-shrink: 0; }

    .status-badge { font-size: 0.7rem; font-weight: bold; padding: 3px 9px; border-radius: 6px; }
    .status-badge.active { background: #d1fae5; color: #065f46; }
    .status-badge.inactive { background: #f1f5f9; color: #64748b; }

    .new-token-box { background: linear-gradient(135deg, #d1fae5, #a7f3d0); border: 2px solid var(--success); border-radius: 16px; padding: 20px; margin-bottom: 20px; }
    .new-token-box__title { font-weight: bold; color: #065f46; margin-bottom: 10px; display: flex; align-items: center; gap: 8px; font-size: 0.95rem; }
    .new-token-box__warn { color: #92400e; font-size: 0.8rem; margin-top: 8px; display: flex; align-items: center; gap: 6px; }
    .new-token-value { background: #fff; border: 2px dashed #059669; border-radius: 12px; padding: 14px; font-family: 'Courier New', monospace; font-size: 0.9rem; color: #065f46; direction: ltr; word-break: break-all; display: flex; align-items: center; justify-content: space-between; gap: 10px; }
    .copy-btn { background: var(--success); color: #fff; border: none; padding: 8px 14px; border-radius: 9px; font-weight: bold; cursor: pointer; font-family: Tahoma, sans-serif; font-size: 0.8rem; display: inline-flex; align-items: center; gap: 6px; flex-shrink: 0; }
    .copy-btn:hover { background: #059669; }

    .empty-state { text-align: center; padding: 40px 20px; color: #94a3b8; font-size: 0.9rem; }
    .empty-state i { font-size: 2.5rem; opacity: 0.5; display: block; margin-bottom: 12px; }

    .docs-box { background: #0f172a; color: #e2e8f0; border-radius: 14px; padding: 16px 18px; font-family: 'Courier New', monospace; font-size: 0.82rem; direction: ltr; overflow-x: auto; line-height: 1.7; }
    .docs-box .key { color: #7dd3fc; }
    .docs-box .val { color: #fcd34d; }
    .docs-box .cmt { color: #64748b; }
    .docs-box .hdr { color: #a5b4fc; font-weight: bold; }

    .hamburger-btn { display: none; width: 44px; height: 44px; border: none; background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%); border-radius: 13px; cursor: pointer; flex-shrink: 0; align-items: center; justify-content: center; padding: 0; }
    .hamburger-btn .hamburger-lines { width: 22px; height: 16px; display: flex; flex-direction: column; justify-content: space-between; }
    .hamburger-btn .hamburger-lines span { display: block; height: 2.5px; width: 100%; background: #fff; border-radius: 3px; transition: transform 0.3s, opacity 0.2s; transform-origin: center; }
    .hamburger-btn .hamburger-lines span:nth-child(2) { width: 70%; }
    .hamburger-btn.active .hamburger-lines span:nth-child(1) { transform: translateY(6.75px) rotate(45deg); }
    .hamburger-btn.active .hamburger-lines span:nth-child(2) { opacity: 0; transform: translateX(-10px); }
    .hamburger-btn.active .hamburger-lines span:nth-child(3) { transform: translateY(-6.75px) rotate(-45deg); }

    .sidebar-overlay { display: none; position: fixed; inset: 0; background: rgba(15, 23, 42, 0.6); z-index: 998; opacity: 0; transition: opacity 0.25s ease; }
    .sidebar-overlay.active { display: block; opacity: 1; }

    @media (max-width: 900px) {
        .hamburger-btn { display: flex; }
        .sidebar { position: fixed; top: 0; right: 0; bottom: 0; width: 280px; max-width: 85vw; transform: translateX(105%); transition: transform 0.35s cubic-bezier(0.65, 0, 0.35, 1); z-index: 999; overflow-y: auto; padding: 20px; gap: 12px; height: 100vh; }
        .sidebar.open { transform: translateX(0); }
        .main-wrapper { width: 100%; }
        .topbar { padding: 12px 18px; }
        .topbar h1 { font-size: 0.95rem; flex: 1; margin: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .topbar .topbar-user { font-size: 0.8rem !important; max-width: 120px; }
        .content-area { padding: 16px; }
        .card { padding: 18px; border-radius: 16px; }
        .token-card { flex-direction: column; align-items: stretch; }
        .token-card__actions { width: 100%; justify-content: flex-end; }
    }
</style>
</head>
<body>

<?php sidebar(); ?>

<div class="main-wrapper">
    <div class="topbar">
        <button class="hamburger-btn" id="hamburgerBtn" onclick="toggleSidebar()">
            <div class="hamburger-lines">
                <span></span>
                <span></span>
                <span></span>
            </div>
        </button>
        <h1><i class="fas fa-key" style="color: var(--accent); margin-left: 8px;"></i>توکن‌های API</h1>
        <div class="topbar-user" style="font-size: 0.9rem; font-weight: bold;">کاربر: <?= htmlspecialchars($displayUser, ENT_QUOTES, 'UTF-8') ?></div>
    </div>

    <div class="content-area">
        <?php if (!empty($msg)): ?>
            <div class="alert alert-<?= htmlspecialchars($msgType, ENT_QUOTES, 'UTF-8') ?>">
                <i class="fas fa-<?= $msgType === 'danger' ? 'exclamation-circle' : ($msgType === 'warning' ? 'exclamation-triangle' : 'check-circle') ?>"></i>
                <?= htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($newToken)): ?>
            <div class="new-token-box">
                <div class="new-token-box__title">
                    <i class="fas fa-check-circle"></i>
                    توکن شما با موفقیت ساخته شد — همین حالا کپی کنید!
                </div>
                <div class="new-token-value">
                    <span id="newTokenValue"><?= htmlspecialchars($newToken, ENT_QUOTES, 'UTF-8') ?></span>
                    <button type="button" class="copy-btn" onclick="copyNewToken()">
                        <i class="fas fa-copy"></i> کپی
                    </button>
                </div>
                <div class="new-token-box__warn">
                    <i class="fas fa-exclamation-triangle"></i>
                    این توکن بعد از رفرش صفحه دیگر نمایش داده نمی‌شود. حتماً آن را در جای امن ذخیره کنید.
                </div>
            </div>
        <?php endif; ?>

        <!-- ساخت توکن جدید -->
        <div class="card">
            <h3><i class="fas fa-plus-circle" style="color: var(--accent);"></i> ساخت توکن جدید</h3>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                <div class="form-group">
                    <label>نام توکن (برای تشخیص بهتر)</label>
                    <input type="text" name="token_name" placeholder="مثلاً: Postman، پلاگین فروشگاه، ..." maxlength="100">
                </div>
                <button type="submit" name="create_token" class="btn">
                    <i class="fas fa-key" style="margin-left: 6px;"></i>
                    ساخت توکن
                </button>
            </form>
        </div>

        <!-- لیست توکن‌ها -->
        <div class="card">
            <h3>
                <i class="fas fa-list" style="color: var(--accent);"></i>
                توکن‌های من
                <span style="background: #e0f2fe; color: #0284c7; font-size: 0.72rem; padding: 3px 10px; border-radius: 8px;"><?= count($tokens) ?></span>
            </h3>

            <?php if (empty($tokens)): ?>
                <div class="empty-state">
                    <i class="fas fa-key"></i>
                    هنوز هیچ توکنی نساخته‌اید.
                </div>
            <?php else: ?>
                <?php foreach ($tokens as $t): ?>
                    <div class="token-card <?= $t['is_active'] ? '' : 'inactive' ?>">
                        <div class="token-card__main">
                            <div class="token-card__name">
                                <?= htmlspecialchars($t['name'], ENT_QUOTES, 'UTF-8') ?>
                                <span class="status-badge <?= $t['is_active'] ? 'active' : 'inactive' ?>">
                                    <?= $t['is_active'] ? 'فعال' : 'غیرفعال' ?>
                                </span>
                            </div>
                            <div class="token-card__token"><?= htmlspecialchars($t['token'], ENT_QUOTES, 'UTF-8') ?></div>
                            <div class="token-card__meta">
                                <span><i class="fas fa-calendar"></i> ساخته شده: <?= htmlspecialchars($t['created_at'], ENT_QUOTES, 'UTF-8') ?></span>
                                <?php if (!empty($t['last_used_at'])): ?>
                                    <span><i class="fas fa-clock"></i> آخرین استفاده: <?= htmlspecialchars($t['last_used_at'], ENT_QUOTES, 'UTF-8') ?></span>
                                <?php else: ?>
                                    <span><i class="fas fa-clock"></i> هنوز استفاده نشده</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="token-card__actions">
                            <button type="button" class="btn btn-sm btn-ghost" onclick="copyToken('<?= htmlspecialchars($t['token'], ENT_QUOTES, 'UTF-8') ?>')" title="کپی توکن">
                                <i class="fas fa-copy"></i>
                            </button>
                            <form method="POST" style="display: inline;">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="token_id" value="<?= (int)$t['id'] ?>">
                                <button type="submit" name="toggle_token" class="btn btn-sm btn-ghost" title="<?= $t['is_active'] ? 'غیرفعال کردن' : 'فعال کردن' ?>">
                                    <i class="fas fa-<?= $t['is_active'] ? 'pause' : 'play' ?>"></i>
                                </button>
                            </form>
                            <form method="POST" style="display: inline;" onsubmit="return confirm('این توکن حذف شود؟');">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="token_id" value="<?= (int)$t['id'] ?>">
                                <button type="submit" name="delete_token" class="btn btn-sm btn-danger" title="حذف">
                                    <i class="fas fa-trash-alt"></i>
                                </button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- راهنما -->
        <div class="card">
            <h3><i class="fas fa-book" style="color: var(--accent);"></i> راهنمای استفاده</h3>
            <p style="font-size: 0.85rem; color: #64748b; line-height: 1.8; margin-top: 0;">
                توکن رو در Header درخواست به این شکل بفرستید:
            </p>

            <div class="docs-box">
<span class="hdr">GET</span> <?= htmlspecialchars((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . dirname(dirname($_SERVER['PHP_SELF'])) . '/api/tasks.php?action=list', ENT_QUOTES, 'UTF-8') ?>
<span class="hdr">Authorization:</span> <span class="val">Bearer tk_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx</span>
<span class="hdr">Accept:</span> <span class="val">application/json</span>
            </div>

            <p style="font-size: 0.82rem; color: #64748b; margin: 16px 0 8px 0;">یا در Postman، در تب <strong>Authorization</strong>:</p>
            <ul style="font-size: 0.82rem; color: #475569; line-height: 1.9; padding-right: 20px; margin: 0;">
                <li>Type را روی <code style="background: #f1f5f9; padding: 2px 6px; border-radius: 4px;">Bearer Token</code> بذارید</li>
                <li>توکن رو در فیلد Token کپی کنید</li>
                <li>آدرس رو با <code style="background: #f1f5f9; padding: 2px 6px; border-radius: 4px;">?action=list</code> صدا بزنید</li>
            </ul>
        </div>
    </div>
</div>

<script>
    function copyToken(token) {
        navigator.clipboard.writeText(token).then(function() {
            alert('توکن کپی شد!');
        }).catch(function() {
            // fallback
            const ta = document.createElement('textarea');
            ta.value = token;
            document.body.appendChild(ta);
            ta.select();
            document.execCommand('copy');
            document.body.removeChild(ta);
            alert('توکن کپی شد!');
        });
    }

    function copyNewToken() {
        const el = document.getElementById('newTokenValue');
        if (el) copyToken(el.textContent.trim());
    }

    function toggleSidebar() {
        const sidebar = document.getElementById('appSidebar');
        const overlay = document.getElementById('sidebarOverlay');
        const btn = document.getElementById('hamburgerBtn');
        if (sidebar.classList.contains('open')) {
            sidebar.classList.remove('open');
            overlay.classList.remove('active');
            btn.classList.remove('active');
            document.body.style.overflow = '';
        } else {
            sidebar.classList.add('open');
            overlay.classList.add('active');
            btn.classList.add('active');
            document.body.style.overflow = 'hidden';
        }
    }

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            const sidebar = document.getElementById('appSidebar');
            if (sidebar && sidebar.classList.contains('open')) toggleSidebar();
        }
    });
</script>
</body>
</html>