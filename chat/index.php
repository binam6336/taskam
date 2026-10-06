<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../database/Database.php';
require_once __DIR__ . '/../includes/impersonate_banner.php';
require_once __DIR__ . '/../includes/sidebar.php';

if (!Auth::check()) { header('Location: ../auth/login.php'); exit; }

$db = Database::getInstance();
try {
    $db->exec("SET NAMES 'utf8mb4'");
    $db->exec("SET CHARACTER SET utf8mb4");
    $db->exec("SET character_set_connection = utf8mb4");
} catch (PDOException $e) {}

$userId = (int)$_SESSION['user_id'];
$page = 'chat';

// ⭐ اگر از طریق نوتیف مرورگر وارد شده باشد (chat.php?chat=USER_ID)
$autoOpenChatId = (int)($_GET['chat'] ?? 0);

try { $db->prepare("UPDATE users SET last_seen=? WHERE id=?")->execute([time(), $userId]); } catch (PDOException $e) {}

try {
    $db->exec("CREATE TABLE IF NOT EXISTS colleague_messages (
        id INT AUTO_INCREMENT PRIMARY KEY,
        sender_id INT NOT NULL,
        receiver_id INT NOT NULL,
        message TEXT NOT NULL,
        attachment VARCHAR(255) DEFAULT NULL,
        attachment_name VARCHAR(255) DEFAULT NULL,
        attachment_size INT UNSIGNED DEFAULT NULL,
        attachment_type VARCHAR(50) DEFAULT NULL,
        is_read TINYINT(1) NOT NULL DEFAULT 0,
        read_at TIMESTAMP NULL DEFAULT NULL,
        is_edited TINYINT(1) NOT NULL DEFAULT 0,
        edited_at TIMESTAMP NULL DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY sender_id (sender_id), KEY receiver_id (receiver_id),
        KEY conversation (sender_id, receiver_id), KEY created_at (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;");
} catch (PDOException $e) {}

// ⭐ لیست چت: همکاران + کاربرانی که مکالمه قبلی با آن‌ها وجود دارد (برای ربات تسکام)
$chatListStmt = $db->prepare("
    SELECT DISTINCT u.id AS user_id, u.first_name, u.last_name, u.mobile, u.avatar,
        (SELECT COUNT(*) FROM colleague_blocks cb WHERE cb.blocker_user_id=? AND cb.blocked_user_id=u.id) AS is_blocked_by_me,
        (SELECT COUNT(*) FROM colleague_blocks cb WHERE cb.blocker_user_id=u.id AND cb.blocked_user_id=?) AS has_blocked_me,
        (SELECT COUNT(*) FROM colleague_messages cm WHERE cm.sender_id=u.id AND cm.receiver_id=? AND cm.is_read=0) AS unread_count,
        (SELECT MAX(cm.created_at) FROM colleague_messages cm WHERE (cm.sender_id=u.id AND cm.receiver_id=?) OR (cm.sender_id=? AND cm.receiver_id=u.id)) AS last_msg_time
    FROM users u
    WHERE u.id != ? AND u.status = 'active'
      AND (
        EXISTS (SELECT 1 FROM colleagues c WHERE c.user_id=? AND c.colleague_user_id=u.id)
        OR EXISTS (SELECT 1 FROM colleagues c WHERE c.user_id=u.id AND c.colleague_user_id=?)
        OR EXISTS (SELECT 1 FROM colleague_messages cm2 WHERE (cm2.sender_id=u.id AND cm2.receiver_id=?) OR (cm2.sender_id=? AND cm2.receiver_id=u.id))
      )
    ORDER BY (last_msg_time IS NULL) ASC, last_msg_time DESC, u.first_name ASC");
$chatListStmt->execute([$userId, $userId, $userId, $userId, $userId, $userId, $userId, $userId, $userId, $userId]);
$chatList = $chatListStmt->fetchAll(PDO::FETCH_ASSOC);

$makeAvatarUrl = function($avatarFile) {
    if (!empty($avatarFile) && file_exists(__DIR__ . '/../uploads/avatars/' . $avatarFile)) return '../uploads/avatars/' . $avatarFile;
    return null;
};

$savedLastStmt = $db->prepare("SELECT id, message, attachment, attachment_name, attachment_type, attachment_size, created_at FROM colleague_messages WHERE sender_id=? AND receiver_id=? ORDER BY id DESC LIMIT 1");
$savedLastStmt->execute([$userId, $userId]);
$savedLastMsg = $savedLastStmt->fetch(PDO::FETCH_ASSOC);

$savedCountStmt = $db->prepare("SELECT COUNT(*) FROM colleague_messages WHERE sender_id=? AND receiver_id=?");
$savedCountStmt->execute([$userId, $userId]);
$savedTotalCount = (int)$savedCountStmt->fetchColumn();

$savedItem = [
    'user_id' => $userId, 'first_name' => 'پیام های ذخیره شده', 'last_name' => '', 'mobile' => '',
    'avatar' => null, 'is_blocked_by_me' => false, 'has_blocked_me' => false, 'unread_count' => 0,
    'last_msg_time' => $savedLastMsg['created_at'] ?? null, 'avatar_url' => null,
    'full_name' => 'پیام های ذخیره شده', 'initial' => '📌', 'is_saved' => true, 'total_count' => $savedTotalCount,
];
array_unshift($chatList, $savedItem);

foreach ($chatList as &$cl) {
    $cl['user_id'] = (int)$cl['user_id'];
    $cl['is_blocked_by_me'] = (int)($cl['is_blocked_by_me'] ?? 0) === 1;
    $cl['has_blocked_me'] = (int)($cl['has_blocked_me'] ?? 0) === 1;
    $cl['unread_count'] = (int)($cl['unread_count'] ?? 0);
    $cl['avatar_url'] = $makeAvatarUrl($cl['avatar'] ?? null);
    $cl['is_saved'] = !empty($cl['is_saved']);
    $cl['total_count'] = (int)($cl['total_count'] ?? 0);
    $fullName = trim(($cl['first_name'] ?? '') . ' ' . ($cl['last_name'] ?? ''));
    if ($fullName === '') $fullName = $cl['mobile'] ?? '';
    $cl['full_name'] = $fullName;
    if ($cl['is_saved']) $cl['initial'] = '📌';
    else $cl['initial'] = mb_substr(trim($cl['first_name'] ?: $cl['mobile']), 0, 1, 'UTF-8');
}
unset($cl);

$totalUnreadStmt = $db->prepare("SELECT COUNT(*) FROM colleague_messages WHERE receiver_id=? AND is_read=0 AND sender_id != receiver_id");
$totalUnreadStmt->execute([$userId]);
$totalUnread = (int)$totalUnreadStmt->fetchColumn();
$sidebarUnread = $totalUnread;

$displayUser = $_SESSION['user_mobile'] ?? $_SESSION['mobile'] ?? $_SESSION['username'] ?? 'کاربر';

$chatListJson = json_encode(array_map(function($cl) {
    return ['user_id'=>(int)$cl['user_id'],'name'=>$cl['full_name'],'initial'=>$cl['initial'],'avatar_url'=>$cl['avatar_url'],'is_saved'=>!empty($cl['is_saved'])];
}, $chatList), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <title>گفتگو با همکاران</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="icon" href="https://img.icons8.com/color/48/dashboard-layout.png" type="image/png">
  <style>
    /* =========================================================
       ROOT
       ========================================================= */
    :root {
        --primary:#1e293b;--primary-dark:#0f172a;--accent:#2563eb;--accent-hover:#1d4ed8;
        --accent-light:#dbeafe;--accent-glow:rgba(37,99,235,0.35);--bg:#f8fafc;--card:#ffffff;
        --text:#334155;--text-dark:#1e293b;--text-soft:#94a3b8;--border:#e2e8f0;
        --success:#10b981;--warning:#f59e0b;--danger:#ef4444;
        --bubble-sent:linear-gradient(135deg,#dbeafe,#bfdbfe);
        --bubble-received:rgba(255,255,255,0.96);
        --bubble-saved:linear-gradient(135deg,#fef3c7,#fde68a);
        --shadow-sm:0 1px 3px rgba(15,23,42,0.08);
        --shadow-md:0 4px 12px rgba(37,99,235,0.12);
        --shadow-lg:0 12px 32px rgba(37,99,235,0.18);
    }
    *{box-sizing:border-box;-webkit-tap-highlight-color:transparent}
    html,body{max-width:100%;overflow-x:hidden}
    body{background:var(--bg);color:var(--text);font-family:Tahoma,sans-serif;margin:0;display:flex;height:100vh;height:100dvh;overflow:hidden;-webkit-font-smoothing:antialiased}

    /* =========================================================
       SIDEBAR
       ========================================================= */
    .sidebar {
        background: linear-gradient(180deg, #4c8bf5 0%, #2f6bdc 100%) !important;
        color: #fff !important;
        box-shadow: -6px 0 28px rgba(76, 139, 245, 0.18);
        overflow: visible !important;
        scrollbar-width: none !important;
        -ms-overflow-style: none !important;
    }
    .sidebar::-webkit-scrollbar { display: none !important; width: 0 !important; height: 0 !important; }
    .sidebar::-webkit-scrollbar-track,
    .sidebar::-webkit-scrollbar-thumb { display: none !important; background: transparent !important; }

    body > .sidebar,
    body .sidebar {
        height: 100vh !important;
        height: 100dvh !important;
        max-height: 100vh !important;
        max-height: 100dvh !important;
        align-self: flex-start !important;
    }

    .sidebar > nav,
    .sidebar > ul,
    .sidebar > .sidebar-menu,
    .sidebar > .sidebar-nav,
    .sidebar > .menu-wrapper,
    .sidebar > div[class*="nav"],
    .sidebar > div[class*="menu"] {
        overflow-y: auto !important;
        overflow-x: visible !important;
        scrollbar-width: none !important;
        -ms-overflow-style: none !important;
    }
    .sidebar > nav::-webkit-scrollbar,
    .sidebar > ul::-webkit-scrollbar,
    .sidebar > .sidebar-menu::-webkit-scrollbar,
    .sidebar > .sidebar-nav::-webkit-scrollbar,
    .sidebar > .menu-wrapper::-webkit-scrollbar,
    .sidebar > div[class*="nav"]::-webkit-scrollbar,
    .sidebar > div[class*="menu"]::-webkit-scrollbar {
        display: none !important;
        width: 0 !important;
    }

    .sidebar-brand { border-bottom: 1px solid rgba(255,255,255,0.15) !important; }
    .sidebar .menu-item { color: rgba(255,255,255,0.85) !important; }
    .sidebar .menu-item:hover,
    .sidebar .menu-item.active {
        background: rgba(255,255,255,0.18) !important;
        color: #fff !important;
    }
    .sidebar .submenu a { color: rgba(255,255,255,0.75) !important; }
    .sidebar .submenu a:hover,
    .sidebar .submenu a.active {
        color: #fff !important;
        background: rgba(255,255,255,0.12) !important;
    }
    .menu-parent { color: rgba(255,255,255,0.82) !important; }
    .menu-parent:hover,
    .menu-parent.is-open,
    .menu-parent.is-current {
        background: rgba(255,255,255,0.14) !important;
        color: #fff !important;
    }
    .submenu-inner a { color: rgba(255,255,255,0.78) !important; }
    .submenu-inner a:hover,
    .submenu-inner a.active {
        color: #fff !important;
        background: rgba(255,255,255,0.14) !important;
    }

    /* =========================================================
       OVERLAY
       ========================================================= */
    .sidebar-overlay {
        display: none;
        position: fixed;
        inset: 0;
        background: rgba(20, 30, 60, 0.5);
        z-index: 998;
        opacity: 0;
        transition: opacity 0.25s ease;
    }
    .sidebar-overlay.active { display: block; opacity: 1; }

    /* =========================================================
       MAIN WRAPPER
       ========================================================= */
    .main-wrapper{flex:1;display:flex;flex-direction:column;overflow:hidden;min-width:0}
    .topbar{background:var(--card);padding:15px clamp(16px,2vw,30px);border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;flex-shrink:0;gap:12px}
    .topbar h1{margin:0;font-size:clamp(1rem,1.4vw,1.2rem);color:var(--primary);display:flex;align-items:center;gap:10px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    .topbar-user{font-size:0.9rem;font-weight:bold;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:220px;flex-shrink:0}
    .content-area{flex:1;padding:clamp(12px,2vw,25px);overflow:hidden;display:flex;min-height:0}

    /* =========================================================
       CHAT CONTAINER
       ========================================================= */
    .chat-container{display:flex;flex:1;border-radius:22px;overflow:hidden;background:#ffffff;box-shadow:0 12px 32px rgba(15,23,42,0.12);min-height:0}

    .chat-sidebar{width:320px;background:linear-gradient(180deg,#ffffff,#f8fafc);border-left:1px solid rgba(37,99,235,0.08);display:flex;flex-direction:column;flex-shrink:0;position:relative;z-index:2}
    .chat-sidebar__header{padding:20px 20px 14px;border-bottom:1px solid rgba(37,99,235,0.06)}
    .chat-sidebar__title{font-size:1rem;font-weight:800;color:var(--text-dark);display:flex;align-items:center;gap:8px;margin-bottom:4px}
    .chat-sidebar__subtitle{font-size:0.72rem;color:var(--text-soft)}
    .chat-sidebar__search{padding:12px 16px;border-bottom:1px solid rgba(37,99,235,0.05)}
    .chat-sidebar__search input{width:100%;padding:11px 16px;background:#f1f5f9;border:1.5px solid transparent;border-radius:22px;font-size:0.84rem;font-family:Tahoma,sans-serif;color:var(--text-dark);transition:all 0.2s;outline:none}
    .chat-sidebar__search input:focus{background:#ffffff;border-color:var(--accent);box-shadow:0 0 0 3px rgba(37,99,235,0.12)}

    .chat-list{flex:1;overflow-y:auto;padding:8px}
    .chat-list::-webkit-scrollbar{width:5px}
    .chat-list::-webkit-scrollbar-thumb{background:rgba(37,99,235,0.2);border-radius:3px}

    .chat-item{display:flex;align-items:center;gap:12px;padding:12px;border-radius:14px;cursor:pointer;transition:all 0.2s ease;margin-bottom:4px;position:relative}
    .chat-item:hover{background:rgba(37,99,235,0.06);transform:translateX(-2px)}
    .chat-item.active{background:linear-gradient(135deg,#dbeafe,#bfdbfe);box-shadow:0 4px 12px rgba(37,99,235,0.15)}
    .chat-item.active .chat-item__name{color:var(--text-dark)}
    .chat-item.is-saved{background:linear-gradient(135deg,#fef3c7,#fde68a);border:1px solid #fbbf24;margin-bottom:10px;box-shadow:0 4px 12px rgba(245,158,11,0.15)}
    .chat-item.is-saved:hover{background:linear-gradient(135deg,#fde68a,#fcd34d);transform:translateX(-2px)}
    .chat-item.is-saved.active{background:linear-gradient(135deg,#fcd34d,#f59e0b)}
    .chat-item.is-saved .chat-item__name{color:#92400e}
    .chat-item.is-saved .chat-item__last{color:#b45309}
    .chat-item__avatar{width:48px;height:48px;border-radius:50%;background:linear-gradient(135deg,#6366f1,#4f46e5);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:1.05rem;flex-shrink:0;position:relative;overflow:hidden;box-shadow:var(--shadow-sm)}
    .chat-item__avatar img{width:100%;height:100%;object-fit:cover;display:block}
    .chat-item__avatar.is-saved-avatar{background:linear-gradient(135deg,#f59e0b,#d97706);font-size:1.2rem}
    .chat-item__avatar::after{content:'';position:absolute;bottom:0;left:0;width:14px;height:14px;border-radius:50%;background:#cbd5e1;border:2.5px solid #fff;transition:background 0.3s;z-index:2}
    .chat-item__avatar.online::after{background:#10b981}
    .chat-item__avatar.offline::after{background:#ef4444}
    .chat-item__avatar.is-saved-avatar::after{display:none}
    .chat-item__avatar.is-bot-avatar{background:linear-gradient(135deg,#8b5cf6,#7c3aed);font-size:1.1rem}
    .chat-item__avatar.is-bot-avatar::after{display:none}
    .chat-item__info{flex:1;min-width:0}
    .chat-item__name{font-weight:700;font-size:0.88rem;color:var(--text-dark);margin-bottom:4px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    .chat-item__last{font-size:0.72rem;color:var(--text-soft);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    .saved-count-badge{display:inline-block;background:#f59e0b;color:#fff;font-size:0.65rem;padding:1px 7px;border-radius:10px;margin-right:6px;font-weight:bold;vertical-align:middle}
    .chat-item__badge{background:linear-gradient(135deg,#2563eb,#1d4ed8);color:#fff;min-width:22px;height:22px;padding:0 7px;border-radius:11px;display:flex;align-items:center;justify-content:center;font-size:0.7rem;font-weight:800;flex-shrink:0;box-shadow:0 3px 8px rgba(37,99,235,0.4)}
    .chat-item.is-blocked{opacity:0.55;pointer-events:none}

    /* ============ CHAT WINDOW ============ */
    .chat-window{flex:1;display:flex;flex-direction:column;position:relative;min-width:0;background:#1e3a8a;overflow:hidden;isolation:isolate}
    .chat-window::before{
        content:'';
        position:absolute;
        inset:-8px;
        background-image:url('../assets/img/chat-bg.jpg');
        background-size:cover;
        background-position:center;
        background-repeat:no-repeat;
        filter:blur(4px) brightness(0.92) saturate(1.05);
        transform:scale(1.04);
        z-index:0;
        pointer-events:none;
    }
    .chat-window::after{
        content:'';
        position:absolute;
        inset:0;
        background:linear-gradient(180deg,rgba(30,58,138,0.28) 0%,rgba(30,58,138,0.10) 50%,rgba(30,58,138,0.28) 100%);
        z-index:0;
        pointer-events:none;
    }
    .chat-window > *{position:relative;z-index:1}

    .chat-header{display:flex;align-items:center;gap:14px;padding:14px 22px;background:rgba(255,255,255,0.96);backdrop-filter:blur(12px);-webkit-backdrop-filter:blur(12px);border-bottom:1px solid rgba(37,99,235,0.12);flex-shrink:0;position:relative;overflow:hidden}
    .chat-header::after{content:'';position:absolute;bottom:0;left:0;right:0;height:2px;background:linear-gradient(90deg,transparent,#2563eb,#60a5fa,#2563eb,transparent);background-size:200% 100%;animation:wave 3s linear infinite}
    @keyframes wave{0%{background-position:0% 0}100%{background-position:200% 0}}

    .chat-header__avatar{width:44px;height:44px;border-radius:50%;background:linear-gradient(135deg,#6366f1,#4f46e5);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:1.05rem;flex-shrink:0;position:relative;overflow:hidden;box-shadow:0 4px 12px rgba(99,102,241,0.35)}
    .chat-header__avatar img{width:100%;height:100%;object-fit:cover;display:block}
    .chat-header__avatar::after{content:'';position:absolute;bottom:0;left:0;width:13px;height:13px;border-radius:50%;background:#10b981;border:2.5px solid #fff;animation:pulse-online 2s infinite;z-index:2}
    @keyframes pulse-online{0%,100%{box-shadow:0 0 0 0 rgba(16,185,129,0.5)}50%{box-shadow:0 0 0 6px rgba(16,185,129,0)}}
    .chat-header__avatar.is-saved-avatar{background:linear-gradient(135deg,#f59e0b,#d97706);font-size:1.15rem;box-shadow:0 4px 12px rgba(245,158,11,0.35)}
    .chat-header__avatar.is-saved-avatar::after{display:none}
    .chat-header__avatar.is-bot-avatar{background:linear-gradient(135deg,#8b5cf6,#7c3aed);font-size:1.1rem;box-shadow:0 4px 12px rgba(139,92,246,0.35)}
    .chat-header__avatar.is-bot-avatar::after{display:none}

    .chat-header__back{display:none;width:38px;height:38px;border-radius:50%;background:#f1f5f9;border:none;color:#475569;cursor:pointer;align-items:center;justify-content:center;font-size:0.95rem;flex-shrink:0;transition:all 0.2s ease}
    .chat-header__back:hover{background:#e2e8f0;color:var(--accent)}

    .chat-header__info{flex:1;min-width:0}
    .chat-header__name{font-weight:800;font-size:0.98rem;color:var(--text-dark);margin-bottom:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    .chat-header__status{font-size:0.72rem;display:flex;align-items:center;gap:5px;color:#10b981;font-weight:600}
    .chat-header__status i{font-size:0.55rem;animation:pulse-dot 2s infinite}
    .chat-header__status.offline{color:#ef4444}
    .chat-header__status.saved{color:#d97706}
    .chat-header__status.bot{color:#7c3aed}
    @keyframes pulse-dot{0%,100%{opacity:1}50%{opacity:0.4}}

    .chat-header__actions{display:flex;gap:6px;flex-shrink:0}
    .chat-header__action{width:38px;height:38px;border-radius:11px;background:rgba(37,99,235,0.08);border:none;color:var(--accent);cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:0.9rem;transition:all 0.2s}
    .chat-header__action:hover{background:var(--accent);color:#fff;transform:translateY(-2px);box-shadow:0 4px 12px var(--accent-glow)}

    /* ============ CHAT BODY ============ */
    .chat-body{flex:1;overflow-y:auto;padding:24px 26px;display:flex;flex-direction:column;gap:10px;scroll-behavior:auto;background:transparent;-webkit-overflow-scrolling:touch;overscroll-behavior:contain}
    .chat-body::-webkit-scrollbar{width:6px}
    .chat-body::-webkit-scrollbar-thumb{background:rgba(255,255,255,0.3);border-radius:3px}
    .chat-body::-webkit-scrollbar-thumb:hover{background:rgba(255,255,255,0.5)}

    .chat-empty{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;color:rgba(255,255,255,0.7);padding:40px;text-align:center}
    .chat-empty i{font-size:4rem;margin-bottom:16px;opacity:0.5;color:#fff}
    .chat-empty h3{margin:0 0 8px;font-size:1.1rem;color:#fff}
    .chat-empty p{margin:0;font-size:0.85rem;max-width:320px;line-height:1.7}

    .chat-day{text-align:center;margin:14px 0;display:flex;justify-content:center}
    .chat-day span{background:rgba(255,255,255,0.92);backdrop-filter:blur(8px);-webkit-backdrop-filter:blur(8px);padding:5px 16px;border-radius:14px;font-size:0.72rem;color:var(--text-dark);font-weight:700;box-shadow:0 2px 8px rgba(0,0,0,0.1)}

    .chat-load-more{display:flex;align-items:center;justify-content:center;gap:8px;padding:8px;margin-bottom:8px;font-size:0.72rem;color:rgba(255,255,255,0.85);min-height:32px;transition:opacity 0.3s;text-shadow:0 1px 2px rgba(0,0,0,0.3)}
    .chat-load-more.no-more{opacity:0.7}
    .chat-load-more__spinner{display:none;width:14px;height:14px;border:2px solid rgba(255,255,255,0.3);border-top-color:#fff;border-radius:50%;animation:spin-loader 0.75s linear infinite;flex-shrink:0}
    .chat-load-more.loading .chat-load-more__spinner{display:inline-block}
    .chat-load-more.loading .chat-load-more__text{display:none}
    @keyframes spin-loader{to{transform:rotate(360deg)}}

    .chat-bubble{
        max-width:62%;
        min-width:95px;
        padding:11px 16px 24px;
        border-radius:18px;
        font-size:0.9rem;
        line-height:1.6;
        word-wrap:break-word;
        position:relative;
        animation:msgIn 0.3s cubic-bezier(0.34,1.56,0.64,1);
        box-shadow:0 3px 10px rgba(0,0,0,0.12);
        user-select:text;
        transition:all 0.25s ease;
        scroll-margin-top:80px;
    }
    @keyframes msgIn{from{opacity:0;transform:translateY(10px) scale(0.96)}to{opacity:1;transform:translateY(0) scale(1)}}
    .chat-bubble:hover{transform:translateY(-1px);box-shadow:0 6px 18px rgba(0,0,0,0.18)}
    .chat-bubble.sent{align-self:flex-start;background:var(--bubble-sent);border-bottom-left-radius:6px;color:#1e40af;backdrop-filter:blur(10px);-webkit-backdrop-filter:blur(10px)}
    .chat-bubble.received{align-self:flex-end;background:var(--bubble-received);border-bottom-right-radius:6px;color:#1e293b;backdrop-filter:blur(10px);-webkit-backdrop-filter:blur(10px)}
    .chat-bubble.saved-self{align-self:flex-start;background:var(--bubble-saved);border-bottom-left-radius:6px;color:#78350f;backdrop-filter:blur(10px);-webkit-backdrop-filter:blur(10px)}

    .chat-bubble.task-notification{
        background:linear-gradient(135deg,#ede9fe 0%,#ddd6fe 100%);
        border:1px solid #c4b5fd;
        color:#4c1d95;
    }
    .chat-bubble.task-notification .task-notif-header{
        display:flex;align-items:center;gap:8px;
        padding-bottom:8px;margin-bottom:8px;
        border-bottom:1px dashed #c4b5fd;
        font-weight:800;font-size:0.85rem;color:#6d28d9;
    }
    .chat-bubble.task-notification .task-notif-header .tn-icon{
        width:24px;height:24px;border-radius:8px;
        background:linear-gradient(135deg,#8b5cf6,#7c3aed);
        color:#fff;display:inline-flex;align-items:center;justify-content:center;
        font-size:0.72rem;flex-shrink:0;
    }
    .chat-bubble.task-notification .task-notif-body{
        font-size:0.85rem;line-height:1.7;color:#5b21b6;
        white-space:pre-wrap;word-break:break-word;
    }
    .chat-bubble.task-notification .task-notif-actions{
        margin-top:10px;display:flex;gap:8px;flex-wrap:wrap;
    }
    .chat-task-action-btn{
        display:inline-flex;align-items:center;gap:7px;
        padding:9px 16px;
        background:linear-gradient(135deg,#7c3aed,#6d28d9);
        color:#fff !important;
        border-radius:11px;
        font-size:0.78rem;font-weight:800;
        text-decoration:none;
        transition:all 0.2s ease;
        box-shadow:0 4px 12px rgba(124,58,237,0.4);
        font-family:Tahoma,sans-serif;
        border:none;cursor:pointer;
    }
    .chat-task-action-btn i{font-size:0.8rem}
    .chat-task-action-btn:hover{
        transform:translateY(-2px);
        box-shadow:0 6px 18px rgba(124,58,237,0.55);
        color:#fff;
    }
    .chat-task-action-btn:active{transform:translateY(0) scale(0.98)}

    .chat-bubble.search-highlight{
        animation:highlightPulse 2s ease;
        box-shadow:0 0 0 3px #fbbf24, 0 8px 24px rgba(251,191,36,0.5) !important;
    }
    @keyframes highlightPulse{
        0%{transform:scale(1)}
        15%{transform:scale(1.05)}
        30%{transform:scale(1)}
        45%{transform:scale(1.03)}
        100%{transform:scale(1)}
    }

    .chat-bubble__time{position:absolute;bottom:6px;left:14px;font-size:0.66rem;color:var(--text-soft);display:flex;align-items:center;gap:5px;font-weight:600;white-space:nowrap}
    .chat-bubble.sent .chat-bubble__time{color:#2563eb}
    .chat-bubble.saved-self .chat-bubble__time{color:#92400e}
    .chat-bubble.task-notification .chat-bubble__time{color:#7c3aed}
    .chat-bubble__time .check{color:#2563eb;font-size:0.75rem;transition:color 0.3s}
    .chat-bubble__time .check.read{color:#3b82f6;animation:check-pop 0.4s ease}
    @keyframes check-pop{0%{transform:scale(0.7)}50%{transform:scale(1.2)}100%{transform:scale(1)}}
    .chat-bubble__edited{font-size:0.62rem;color:#94a3b8;margin-right:4px;font-style:italic}
    .chat-bubble.saved-self .chat-bubble__edited{color:#b45309}
    .chat-bubble.sent .chat-bubble__edited{color:#2563eb}
    .chat-bubble.context-active{box-shadow:0 0 0 2px var(--accent),0 6px 20px var(--accent-glow) !important}

    .chat-bubble__attachment{margin-bottom:8px}
    .chat-image-wrapper{position:relative;display:inline-block;border-radius:12px;overflow:hidden;cursor:zoom-in;line-height:0;box-shadow:0 4px 14px rgba(0,0,0,0.15)}
    .chat-image-preview{display:block;max-width:260px;max-height:260px;border-radius:12px;object-fit:cover;transition:transform 0.3s ease,filter 0.3s ease}
    .chat-image-wrapper:hover .chat-image-preview{transform:scale(1.04);filter:brightness(0.95)}
    .image-zoom-hint{position:absolute;top:50%;left:50%;transform:translate(-50%,-50%) scale(0.5);width:46px;height:46px;border-radius:50%;background:rgba(15,23,42,0.75);backdrop-filter:blur(8px);color:#fff;display:flex;align-items:center;justify-content:center;font-size:1.1rem;opacity:0;transition:all 0.3s ease;pointer-events:none}
    .chat-image-wrapper:hover .image-zoom-hint{opacity:1;transform:translate(-50%,-50%) scale(1)}

    .chat-file-card{display:flex;align-items:center;gap:12px;padding:12px 14px;background:rgba(255,255,255,0.7);backdrop-filter:blur(10px);border:1px solid rgba(37,99,235,0.1);border-radius:14px;text-decoration:none;color:inherit;transition:all 0.25s ease;margin-top:6px}
    .chat-file-card:hover{background:#ffffff;transform:translateY(-2px);box-shadow:0 6px 18px rgba(0,0,0,0.12);border-color:var(--accent)}
    .chat-file-card__icon{width:44px;height:44px;border-radius:12px;background:linear-gradient(135deg,#dbeafe,#bfdbfe);color:#2563eb;display:flex;align-items:center;justify-content:center;font-size:1.2rem;flex-shrink:0;box-shadow:0 3px 8px rgba(37,99,235,0.15)}
    .chat-file-card__icon.type-pdf{background:linear-gradient(135deg,#fee2e2,#fecaca);color:#dc2626}
    .chat-file-card__icon.type-zip{background:linear-gradient(135deg,#fef3c7,#fde68a);color:#d97706}
    .chat-file-card__icon.type-txt{background:linear-gradient(135deg,#f1f5f9,#e2e8f0);color:#475569}
    .chat-file-card__icon.type-png,.chat-file-card__icon.type-jpg{background:linear-gradient(135deg,#d1fae5,#a7f3d0);color:#059669}
    .chat-file-card__info{flex:1;min-width:0}
    .chat-file-card__name{font-weight:700;font-size:0.84rem;color:var(--text-dark);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-bottom:3px}
    .chat-file-card__size{font-size:0.7rem;color:var(--text-soft);display:flex;align-items:center;gap:6px}

    .typing-indicator{align-self:flex-end;background:rgba(255,255,255,0.96);padding:12px 18px;border-radius:18px;border-bottom-right-radius:6px;display:flex;align-items:center;gap:5px;box-shadow:0 3px 10px rgba(0,0,0,0.1);animation:msgIn 0.3s ease}
    .typing-indicator span{width:8px;height:8px;border-radius:50%;background:var(--accent);animation:typing-bounce 1.4s infinite}
    .typing-indicator span:nth-child(2){animation-delay:0.2s}
    .typing-indicator span:nth-child(3){animation-delay:0.4s}
    @keyframes typing-bounce{0%,60%,100%{transform:translateY(0);opacity:0.5}30%{transform:translateY(-6px);opacity:1}}

    .chat-footer{background:rgba(255,255,255,0.96);backdrop-filter:blur(12px);-webkit-backdrop-filter:blur(12px);padding:14px 20px;border-top:1px solid rgba(37,99,235,0.1);display:flex;align-items:flex-end;gap:10px;flex-shrink:0;position:relative}
    .chat-attach-btn{width:44px;height:44px;min-width:44px;min-height:44px;max-width:44px;max-height:44px;flex:0 0 44px;border-radius:50%;background:linear-gradient(135deg,#dbeafe,#bfdbfe);color:var(--accent);border:none;cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:1.05rem;transition:all 0.25s ease}
    .chat-attach-btn:hover{background:linear-gradient(135deg,#93c5fd,#60a5fa);color:#fff;transform:rotate(-15deg) scale(1.08);box-shadow:0 6px 16px var(--accent-glow)}
    .chat-attach-btn:disabled{opacity:0.5;cursor:not-allowed}
    .chat-footer__input-wrap{flex:1;background:#f1f5f9;border-radius:24px;padding:10px 18px;transition:all 0.25s ease;border:1.5px solid transparent;min-width:0;display:flex;align-items:center;box-sizing:border-box}
    .chat-footer__input-wrap:focus-within{background:#ffffff;border-color:var(--accent);box-shadow:0 0 0 4px rgba(37,99,235,0.1)}
    .chat-footer__input{width:100%;border:none;background:transparent;font-family:Tahoma,sans-serif;font-size:0.88rem;color:var(--text-dark);resize:none;max-height:100px;line-height:1.6;padding:4px 0;outline:none;display:block}
    .chat-footer__input::placeholder{color:#94a3b8}
    .chat-send-btn{width:48px;height:48px;min-width:48px;min-height:48px;max-width:48px;max-height:48px;flex:0 0 48px;border-radius:50%;background:linear-gradient(135deg,#2563eb,#1d4ed8);color:#fff;border:none;cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:1.1rem;box-shadow:0 6px 16px var(--accent-glow);transition:all 0.25s ease}
    .chat-send-btn:hover{transform:translateY(-2px) scale(1.05);box-shadow:0 8px 22px rgba(37,99,235,0.5)}
    .chat-send-btn:active{transform:scale(0.95)}
    .chat-send-btn:disabled{opacity:0.5;cursor:not-allowed;transform:none}

    .upload-progress{display:none;position:absolute;bottom:0;left:0;right:0;height:3px;background:rgba(37,99,235,0.15);z-index:100}
    .upload-progress.active{display:block}
    .upload-progress__bar{height:100%;width:0;background:linear-gradient(90deg,#2563eb,#60a5fa);transition:width 0.3s ease}

    .file-preview{display:none;padding:10px 14px;background:#f8fafc;border-top:1px solid #e2e8f0;align-items:center;gap:12px;font-size:0.85rem}
    .file-preview.active{display:flex}
    .file-preview__icon{width:36px;height:36px;border-radius:9px;background:#dbeafe;color:#2563eb;display:flex;align-items:center;justify-content:center;font-size:1rem;flex-shrink:0}
    .file-preview__icon.type-pdf{background:#fee2e2;color:#dc2626}
    .file-preview__icon.type-zip{background:#fef3c7;color:#d97706}
    .file-preview__icon.type-txt{background:#f1f5f9;color:#475569}
    .file-preview__icon.type-png,.file-preview__icon.type-jpg{background:#d1fae5;color:#059669}
    .file-preview__info{flex:1;min-width:0}
    .file-preview__name{font-weight:bold;color:var(--text-dark);font-size:0.82rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    .file-preview__size{font-size:0.7rem;color:#94a3b8;margin-top:2px}
    .file-preview__close{width:30px;height:30px;border-radius:50%;background:#fee2e2;color:#dc2626;border:none;cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:0.75rem;transition:all 0.2s ease;flex-shrink:0}
    .file-preview__close:hover{background:#dc2626;color:#fff}

    .msg-context-menu{position:fixed;z-index:99999;min-width:200px;background:#ffffff;border:1px solid var(--border);border-radius:14px;box-shadow:0 12px 40px rgba(15,23,42,0.18),0 2px 8px rgba(15,23,42,0.08);padding:6px;opacity:0;transform:scale(0.92) translateY(-4px);transform-origin:top right;transition:all 0.18s cubic-bezier(0.34,1.56,0.64,1);pointer-events:none;font-family:Tahoma,sans-serif;direction:rtl;visibility:hidden}
    .msg-context-menu.active{opacity:1;transform:scale(1) translateY(0);pointer-events:auto;visibility:visible}
    .msg-context-menu__item{display:flex;align-items:center;gap:12px;width:100%;padding:10px 14px;border:none;background:transparent;border-radius:9px;cursor:pointer;font-family:Tahoma,sans-serif;font-size:0.84rem;font-weight:600;color:#334155;text-align:right;transition:all 0.15s}
    .msg-context-menu__item:hover{background:#eff6ff;color:var(--accent)}
    .msg-context-menu__item i{width:18px;text-align:center;font-size:0.85rem;color:#64748b;flex-shrink:0;transition:color 0.15s}
    .msg-context-menu__item:hover i{color:var(--accent)}
    .msg-context-menu__item--danger{color:#dc2626}
    .msg-context-menu__item--danger i{color:#dc2626}
    .msg-context-menu__item--danger:hover{background:#fee2e2;color:#b91c1c}
    .msg-context-menu__item--danger:hover i{color:#b91c1c}
    .msg-context-menu__divider{height:1px;background:#f1f5f9;margin:4px 8px}

    /* ============ SEARCH MODAL ============ */
    .search-modal-overlay{position:fixed;inset:0;background:rgba(15,23,42,0.65);backdrop-filter:blur(6px);-webkit-backdrop-filter:blur(6px);z-index:10060;display:none;align-items:flex-start;justify-content:center;padding:60px 20px 20px;opacity:0;transition:opacity 0.25s}
    .search-modal-overlay.active{display:flex;opacity:1}
    .search-modal{background:#ffffff;border-radius:18px;max-width:640px;width:100%;max-height:80vh;display:flex;flex-direction:column;box-shadow:0 24px 64px rgba(15,23,42,0.4);transform:scale(0.94);transition:transform 0.3s cubic-bezier(0.34,1.56,0.64,1);direction:rtl;font-family:Tahoma,sans-serif;overflow:hidden}
    .search-modal-overlay.active .search-modal{transform:scale(1)}
    .search-modal__header{padding:18px 20px 12px;border-bottom:1px solid var(--border);display:flex;flex-direction:column;gap:12px}
    .search-modal__title-row{display:flex;align-items:center;justify-content:space-between;gap:12px}
    .search-modal__title{font-size:1rem;font-weight:800;color:var(--text-dark);display:flex;align-items:center;gap:8px}
    .search-modal__title i{color:var(--accent)}
    .search-modal__close{width:34px;height:34px;border-radius:50%;background:#f1f5f9;border:none;color:#475569;cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:0.85rem;transition:all 0.2s;flex-shrink:0}
    .search-modal__close:hover{background:#ef4444;color:#fff;transform:rotate(90deg)}
    .search-modal__input-wrap{position:relative;display:flex;align-items:center}
    .search-modal__input-wrap i{position:absolute;right:16px;color:var(--text-soft);font-size:0.9rem}
    .search-modal__input{width:100%;padding:13px 42px 13px 16px;background:#f1f5f9;border:1.5px solid transparent;border-radius:14px;font-family:Tahoma,sans-serif;font-size:0.9rem;color:var(--text-dark);outline:none;transition:all 0.2s}
    .search-modal__input:focus{background:#ffffff;border-color:var(--accent);box-shadow:0 0 0 4px rgba(37,99,235,0.1)}
    .search-modal__scope{display:flex;gap:8px;padding:0 4px;flex-wrap:wrap}
    .search-modal__scope-btn{padding:6px 14px;border-radius:20px;border:1.5px solid var(--border);background:#fff;color:#64748b;font-family:Tahoma,sans-serif;font-size:0.75rem;font-weight:600;cursor:pointer;transition:all 0.2s}
    .search-modal__scope-btn.active{background:linear-gradient(135deg,#2563eb,#1d4ed8);color:#fff;border-color:transparent;box-shadow:0 3px 10px var(--accent-glow)}
    .search-modal__scope-btn:disabled{opacity:0.4;cursor:not-allowed}
    .search-modal__body{flex:1;overflow-y:auto;padding:12px}
    .search-modal__body::-webkit-scrollbar{width:6px}
    .search-modal__body::-webkit-scrollbar-thumb{background:rgba(37,99,235,0.25);border-radius:3px}
    .search-modal__empty{text-align:center;padding:40px 20px;color:var(--text-soft);font-size:0.85rem}
    .search-modal__empty i{font-size:2.5rem;opacity:0.4;display:block;margin-bottom:14px}
    .search-modal__hint{font-size:0.78rem;color:#94a3b8;padding:10px 14px;text-align:center}
    .search-modal__info{font-size:0.75rem;color:var(--text-soft);padding:8px 14px 4px;font-weight:600}
    .search-result{display:flex;gap:12px;padding:12px;border-radius:12px;cursor:pointer;transition:background 0.18s;margin-bottom:4px;border:1px solid transparent}
    .search-result:hover{background:#eff6ff;border-color:rgba(37,99,235,0.15)}
    .search-result__avatar{width:40px;height:40px;border-radius:50%;background:linear-gradient(135deg,#6366f1,#4f46e5);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:0.9rem;flex-shrink:0}
    .search-result__avatar.is-saved-avatar{background:linear-gradient(135deg,#f59e0b,#d97706)}
    .search-result__info{flex:1;min-width:0}
    .search-result__top{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:4px}
    .search-result__name{font-weight:700;font-size:0.85rem;color:var(--text-dark);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    .search-result__time{font-size:0.68rem;color:var(--text-soft);flex-shrink:0;white-space:nowrap}
    .search-result__text{font-size:0.8rem;color:#475569;line-height:1.6;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;word-break:break-word}
    .search-result__text mark{background:#fef08a;color:#78350f;padding:0 3px;border-radius:3px;font-weight:700}
    .search-result__badge{display:inline-block;background:#dbeafe;color:#1e40af;font-size:0.62rem;padding:1px 7px;border-radius:8px;margin-right:6px;font-weight:700;vertical-align:middle}
    .search-result__badge.file{background:#fef3c7;color:#b45309}

    /* ============ MODALS ============ */
    .chat-modal-overlay{position:fixed;inset:0;background:rgba(15,23,42,0.55);backdrop-filter:blur(6px);-webkit-backdrop-filter:blur(6px);z-index:10050;display:none;align-items:center;justify-content:center;padding:20px;opacity:0;transition:opacity 0.25s}
    .chat-modal-overlay.active{display:flex;opacity:1}
    .chat-modal{background:#ffffff;border-radius:20px;max-width:460px;width:100%;box-shadow:0 24px 64px rgba(15,23,42,0.35);overflow:hidden;transform:scale(0.9);transition:transform 0.3s cubic-bezier(0.34,1.56,0.64,1);direction:rtl;font-family:Tahoma,sans-serif}
    .chat-modal-overlay.active .chat-modal{transform:scale(1)}
    .chat-modal__header{padding:22px 24px 14px;display:flex;align-items:center;gap:14px}
    .chat-modal__icon{width:48px;height:48px;border-radius:14px;display:flex;align-items:center;justify-content:center;font-size:1.2rem;flex-shrink:0}
    .chat-modal__icon--danger{background:linear-gradient(135deg,#fee2e2,#fecaca);color:#dc2626;box-shadow:0 4px 12px rgba(220,38,38,0.2)}
    .chat-modal__icon--info{background:linear-gradient(135deg,#dbeafe,#bfdbfe);color:var(--accent);box-shadow:0 4px 12px rgba(37,99,235,0.2)}
    .chat-modal__title{font-size:1.05rem;font-weight:800;color:var(--text-dark);margin:0}
    .chat-modal__subtitle{font-size:0.75rem;color:var(--text-soft);margin-top:3px}
    .chat-modal__body{padding:4px 24px 20px;color:var(--text);font-size:0.87rem;line-height:1.8}
    .chat-modal__body p{margin-bottom:10px}
    .chat-modal__body strong{color:var(--text-dark)}
    .chat-modal__textarea{width:100%;padding:14px 16px;border:1.5px solid var(--border);border-radius:14px;font-family:Tahoma,sans-serif;font-size:0.88rem;color:var(--text-dark);resize:vertical;min-height:100px;max-height:240px;line-height:1.7;transition:all 0.2s;outline:none;background:#f8fafc;box-sizing:border-box}
    .chat-modal__textarea:focus{border-color:var(--accent);background:#ffffff;box-shadow:0 0 0 4px rgba(37,99,235,0.1)}
    .chat-modal__footer{padding:14px 24px 22px;display:flex;gap:10px}
    .chat-modal__btn{padding:11px 24px;border-radius:12px;border:none;font-family:Tahoma,sans-serif;font-size:0.87rem;font-weight:700;cursor:pointer;transition:all 0.2s;display:inline-flex;align-items:center;gap:8px}
    .chat-modal__btn:active{transform:scale(0.97)}
    .chat-modal__btn:disabled{opacity:0.55;cursor:not-allowed}
    .chat-modal__btn--primary{background:linear-gradient(135deg,#2563eb,#1d4ed8);color:#fff;box-shadow:0 4px 12px var(--accent-glow)}
    .chat-modal__btn--primary:hover:not(:disabled){transform:translateY(-2px);box-shadow:0 6px 18px rgba(37,99,235,0.5)}
    .chat-modal__btn--danger{background:linear-gradient(135deg,#ef4444,#dc2626);color:#fff;box-shadow:0 4px 12px rgba(239,68,68,0.35)}
    .chat-modal__btn--danger:hover:not(:disabled){transform:translateY(-2px);box-shadow:0 6px 18px rgba(239,68,68,0.5)}
    .chat-modal__btn--ghost{background:#f1f5f9;color:#475569}
    .chat-modal__btn--ghost:hover:not(:disabled){background:#e2e8f0}

    .chat-toast{position:fixed;bottom:30px;left:50%;transform:translateX(-50%) translateY(80px);background:linear-gradient(135deg,#1e293b,#0f172a);color:#fff;padding:13px 26px;border-radius:30px;font-size:0.85rem;font-family:Tahoma,sans-serif;font-weight:700;display:flex;align-items:center;gap:10px;box-shadow:0 12px 32px rgba(15,23,42,0.4);z-index:10100;opacity:0;transition:all 0.35s cubic-bezier(0.34,1.56,0.64,1);pointer-events:none;direction:rtl;max-width:90vw}
    .chat-toast.active{opacity:1;transform:translateX(-50%) translateY(0)}
    .chat-toast i{font-size:1rem}
    .chat-toast--success i{color:#10b981}
    .chat-toast--error i{color:#ef4444}
    .chat-toast--info i{color:#60a5fa}

    /* =========================================================
       HAMBURGER
       ========================================================= */
    .hamburger-btn{
        display:none;
        width:44px;height:44px;
        border:none;
        background:linear-gradient(135deg,#4c8bf5 0%,#2f6bdc 100%);
        border-radius:13px;
        cursor:pointer;
        box-shadow:0 6px 16px rgba(76,139,245,0.25);
        transition:transform 0.15s ease,box-shadow 0.2s ease;
        flex-shrink:0;
        align-items:center;
        justify-content:center;
        padding:0;
    }
    .hamburger-btn:hover{transform:translateY(-1px);box-shadow:0 8px 20px rgba(76,139,245,0.35)}
    .hamburger-btn:active{transform:scale(0.95)}
    .hamburger-btn .hamburger-lines{width:22px;height:16px;position:relative;display:flex;flex-direction:column;justify-content:space-between}
    .hamburger-btn .hamburger-lines span{display:block;height:2.5px;width:100%;background:#fff;border-radius:3px;transition:all 0.35s cubic-bezier(0.65,0,0.35,1);transform-origin:center}
    .hamburger-btn .hamburger-lines span:nth-child(2){width:70%}
    .hamburger-btn .hamburger-lines span:nth-child(3){width:100%}
    .hamburger-btn.active .hamburger-lines span:nth-child(1){transform:translateY(6.75px) rotate(45deg)}
    .hamburger-btn.active .hamburger-lines span:nth-child(2){opacity:0;transform:translateX(-10px)}
    .hamburger-btn.active .hamburger-lines span:nth-child(3){transform:translateY(-6.75px) rotate(-45deg)}

    .image-lightbox{position:fixed;inset:0;background:rgba(0,0,0,0.95);display:none;align-items:center;justify-content:center;z-index:10000;padding:20px;user-select:none}
    .image-lightbox.active{display:flex}
    .image-lightbox__canvas{position:relative;max-width:100%;max-height:100%;display:flex;align-items:center;justify-content:center;overflow:hidden;cursor:grab}
    .image-lightbox__canvas.dragging{cursor:grabbing}
    .image-lightbox__img{max-width:92vw;max-height:85vh;border-radius:12px;box-shadow:0 20px 60px rgba(0,0,0,0.5);transform-origin:center center;transition:transform 0.2s ease;user-select:none;-webkit-user-drag:none;pointer-events:none}
    .image-lightbox__topbar{position:absolute;top:20px;left:50%;transform:translateX(-50%);background:rgba(15,23,42,0.85);color:#fff;padding:7px 16px;border-radius:30px;font-size:0.8rem;font-weight:bold;backdrop-filter:blur(8px);z-index:10;display:flex;align-items:center;gap:8px;box-shadow:0 6px 20px rgba(0,0,0,0.35)}
    .image-lightbox__topbar i{color:#93c5fd;font-size:0.85rem}
    .image-lightbox__controls{position:absolute;bottom:30px;left:50%;transform:translateX(-50%);background:rgba(15,23,42,0.9);border-radius:50px;padding:8px;display:flex;align-items:center;gap:4px;backdrop-filter:blur(10px);box-shadow:0 12px 36px rgba(0,0,0,0.5);z-index:10}
    .image-lightbox__btn{width:46px;height:46px;border-radius:50%;background:transparent;color:#e2e8f0;border:none;cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:1rem;transition:all 0.2s ease;position:relative}
    .image-lightbox__btn:hover{background:rgba(255,255,255,0.12);color:#fff;transform:scale(1.08)}
    .image-lightbox__btn:active{transform:scale(0.95)}
    .image-lightbox__btn--primary{background:linear-gradient(135deg,#2563eb,#1d4ed8);color:#fff;box-shadow:0 4px 14px rgba(37,99,235,0.45)}
    .image-lightbox__btn--primary:hover{background:linear-gradient(135deg,#1d4ed8,#1e40af);box-shadow:0 6px 20px rgba(37,99,235,0.55)}
    .image-lightbox__divider{width:1px;height:26px;background:rgba(255,255,255,0.15);margin:0 4px}
    .image-lightbox__close{position:absolute;top:20px;right:20px;width:46px;height:46px;border-radius:50%;background:rgba(255,255,255,0.15);color:#fff;border:none;cursor:pointer;font-size:1.2rem;display:flex;align-items:center;justify-content:center;backdrop-filter:blur(8px);transition:all 0.2s ease;z-index:10}
    .image-lightbox__close:hover{background:#ef4444;transform:rotate(90deg)}
    .image-lightbox__btn[data-tooltip]::before{content:attr(data-tooltip);position:absolute;bottom:calc(100% + 10px);left:50%;transform:translateX(-50%) scale(0.9);background:#0f172a;color:#fff;padding:6px 12px;border-radius:8px;font-size:0.72rem;font-weight:bold;white-space:nowrap;opacity:0;pointer-events:none;transition:all 0.2s ease;font-family:Tahoma,sans-serif;box-shadow:0 4px 12px rgba(0,0,0,0.3)}
    .image-lightbox__btn[data-tooltip]:hover::before{opacity:1;transform:translateX(-50%) scale(1)}

    /* =========================================================
       BREAKPOINT: 1100px
       ========================================================= */
    @media (max-width:1100px){
        .chat-sidebar{width:280px}
        .chat-bubble{max-width:75%}
    }

    /* =========================================================
       BREAKPOINT: 900px
       ========================================================= */
    @media (max-width:900px){
        .hamburger-btn{display:flex}

        body .sidebar{
            position:fixed !important;
            top:0 !important;
            right:0 !important;
            bottom:0 !important;
            left:auto !important;

            width:280px !important;
            min-width:280px !important;
            max-width:85vw !important;

            height:100vh !important;
            height:100dvh !important;
            max-height:100vh !important;
            max-height:100dvh !important;

            transform:translateX(105%) !important;
            align-self:auto !important;

            transition:transform 0.35s cubic-bezier(0.65,0,0.35,1) !important;
            will-change:transform;

            z-index:999 !important;
            box-shadow:-20px 0 50px rgba(0,0,0,0.25) !important;

            padding-top:env(safe-area-inset-top) !important;
            padding-bottom:env(safe-area-inset-bottom) !important;
        }
        body .sidebar.open{transform:translateX(0) !important}
        body .sidebar.is-collapsed{width:280px !important;min-width:280px !important}

        .main-wrapper{width:100%}

        .topbar{
            padding:12px 16px;
            padding-top:max(12px,env(safe-area-inset-top));
            gap:12px;
            flex-wrap:nowrap;
        }
        .topbar h1{font-size:0.95rem;flex:1;margin:0;text-align:center;justify-content:center}
        .topbar h1 i{display:none}
        .topbar-user{font-size:0.78rem !important;max-width:110px}

        .content-area{padding:14px}
        .chat-sidebar{width:260px}
    }

    /* =========================================================
       BREAKPOINT: 700px — Mobile chat + footer fix
       ========================================================= */
    @media (max-width:700px){
        .content-area{padding:10px}
        .topbar{padding:10px 14px;padding-top:max(10px,env(safe-area-inset-top))}
        .topbar h1{font-size:0.88rem}
        .topbar-user{display:none}

        .hamburger-btn{width:40px;height:40px;border-radius:11px}
        .hamburger-btn .hamburger-lines{width:20px;height:14px}
        .hamburger-btn .hamburger-lines span{height:2.2px}
        .hamburger-btn.active .hamburger-lines span:nth-child(1){transform:translateY(5.9px) rotate(45deg)}
        .hamburger-btn.active .hamburger-lines span:nth-child(3){transform:translateY(-5.9px) rotate(-45deg)}

        .chat-container{border-radius:16px;position:relative;height:100%}
        .chat-sidebar{
            width:100%;
            border-left:none;
            position:absolute;
            inset:0;
            z-index:2;
            transition:transform 0.3s ease,opacity 0.3s ease;
            border-radius:16px;
            overflow:hidden;
        }
        .chat-container.chat-open .chat-sidebar{transform:translateX(100%);opacity:0;pointer-events:none}
        .chat-window{position:absolute;inset:0;z-index:1;border-radius:16px;overflow:hidden}
        .chat-container.chat-open .chat-window{z-index:3}
        .chat-header__back{display:flex}
        .chat-header{padding:10px 14px;gap:10px}
        .chat-header__avatar{width:38px;height:38px;font-size:0.92rem}
        .chat-header__name{font-size:0.88rem}
        .chat-header__status{font-size:0.68rem}
        .chat-header__action{width:34px;height:34px;font-size:0.82rem}
        .chat-body{padding:14px;gap:8px}
        .chat-bubble{max-width:82%;font-size:0.86rem;padding:9px 13px 22px;min-width:85px}
        .chat-bubble__time{left:10px;bottom:4px;font-size:0.62rem}
        .chat-item{padding:10px;gap:10px}
        .chat-item__avatar{width:44px;height:44px;font-size:1rem}
        .chat-item__name{font-size:0.85rem}
        .chat-item__last{font-size:0.68rem}
        .chat-sidebar__search{padding:10px 12px}
        .chat-sidebar__search input{padding:9px 12px;font-size:0.82rem}
        .chat-sidebar__header{padding:14px 16px 10px}
        .chat-image-preview{max-width:200px;max-height:200px}
        .chat-file-card__icon{width:36px;height:36px;font-size:1rem}
        .image-lightbox__btn{width:42px;height:42px;font-size:0.9rem}
        .image-lightbox__controls{padding:6px;bottom:20px;gap:2px}
        .image-lightbox__close{width:40px;height:40px;font-size:1.05rem;top:15px;right:15px}
        .image-lightbox__topbar{top:15px;padding:6px 14px;font-size:0.72rem}
        .image-lightbox__img{max-width:94vw;max-height:78vh}
        .search-modal-overlay{padding:20px 10px}
        .search-modal{max-height:88vh}
        .chat-modal{padding:0;border-radius:18px}
        .chat-modal__header{padding:18px 20px 12px}
        .chat-modal__body{padding:4px 20px 16px}
        .chat-modal__footer{padding:12px 20px 18px;flex-direction:column-reverse}
        .chat-modal__btn{width:100%;justify-content:center}

        /* ⭐ FIX: footer buttons sized correctly on mobile */
        .chat-footer{
            padding:8px 10px;
            padding-bottom:max(8px,env(safe-area-inset-bottom));
            gap:6px;
            align-items:flex-end;
        }
        .chat-footer__input-wrap{
            padding:6px 14px;
            min-height:42px;
            border-radius:22px;
            display:flex;
            align-items:center;
            box-sizing:border-box;
        }
        .chat-footer__input{
            font-size:0.86rem;
            line-height:1.5;
            min-height:22px;
            max-height:80px;
            padding:0;
            display:block;
            width:100%;
        }
        .chat-attach-btn{
            width:42px;
            height:42px;
            min-width:42px;
            min-height:42px;
            max-width:42px;
            max-height:42px;
            flex:0 0 42px;
            font-size:0.9rem;
        }
        .chat-send-btn{
            width:42px;
            height:42px;
            min-width:42px;
            min-height:42px;
            max-width:42px;
            max-height:42px;
            flex:0 0 42px;
            font-size:0.98rem;
        }
    }

    /* =========================================================
       BREAKPOINT: 500px
       ========================================================= */
    @media (max-width:500px){
        .chat-container{border-radius:12px}
        .chat-bubble{max-width:88%}
        .chat-body{padding:12px 10px}

        .chat-footer{
            padding:6px 8px;
            padding-bottom:max(6px,env(safe-area-inset-bottom));
            gap:5px;
        }
        .chat-footer__input-wrap{
            padding:5px 12px;
            min-height:40px;
            border-radius:20px;
        }
        .chat-footer__input{
            font-size:0.84rem;
            line-height:1.45;
            min-height:20px;
            max-height:70px;
        }
        .chat-attach-btn{
            width:40px;
            height:40px;
            min-width:40px;
            min-height:40px;
            max-width:40px;
            max-height:40px;
            flex:0 0 40px;
            font-size:0.85rem;
        }
        .chat-send-btn{
            width:40px;
            height:40px;
            min-width:40px;
            min-height:40px;
            max-width:40px;
            max-height:40px;
            flex:0 0 40px;
            font-size:0.92rem;
        }
    }

    /* =========================================================
       REDUCED MOTION
       ========================================================= */
    @media (prefers-reduced-motion:reduce){
        .sidebar,
        .sidebar-overlay,
        .hamburger-btn,
        .hamburger-btn .hamburger-lines span,
        .chat-sidebar,
        .chat-bubble,
        .chat-header::after,
        .typing-indicator span,
        .chat-header__avatar::after{transition:none !important;animation:none !important}
    }
</style>
</head>
<body>

<?php sidebar(); ?>

<div class="main-wrapper">
    <div class="topbar">
        <button class="hamburger-btn" id="hamburgerBtn" onclick="toggleSidebar()" aria-label="باز کردن منو">
            <div class="hamburger-lines"><span></span><span></span><span></span></div>
        </button>
        <h1><i class="fas fa-comments" style="color: var(--accent);"></i> گفتگو با همکاران</h1>
        <div class="topbar-user" style="font-size: 0.9rem; font-weight: bold;">کاربر: <?= htmlspecialchars($displayUser) ?></div>
    </div>

    <div class="content-area">
        <div class="chat-container" id="chatContainer">

            <div class="chat-sidebar">
                <div class="chat-sidebar__header">
                    <div class="chat-sidebar__title"><i class="fas fa-inbox" style="color: var(--accent);"></i> صندوق پیام‌ها</div>
                    <div class="chat-sidebar__subtitle"><?= count($chatList) ?> مکالمه</div>
                </div>
                <div class="chat-sidebar__search">
                    <input type="text" id="chatSearchInput" placeholder="🔍 جستجو در همکاران...">
                </div>
                <div class="chat-list" id="chatListContainer">
                    <?php if (empty($chatList)): ?>
                        <div class="empty-state" style="text-align:center;padding:30px 20px;color:#94a3b8;">
                            <i class="fas fa-comment-slash" style="font-size:2.5rem;opacity:0.5;display:block;margin-bottom:12px;"></i>
                            <div style="font-size:0.82rem;">هنوز همکاری ندارید</div>
                        </div>
                    <?php else: ?>
                        <?php foreach ($chatList as $cl): ?>
                            <?php
                                $isBlocked = $cl['is_blocked_by_me'] || $cl['has_blocked_me'];
                                $isSaved = !empty($cl['is_saved']);
                                $isBot = ($cl['mobile'] ?? '') === '00000000000';
                            ?>
                            <div class="chat-item <?= $isBlocked ? 'is-blocked' : '' ?> <?= $isSaved ? 'is-saved' : '' ?>"
                                 data-user-id="<?= (int)$cl['user_id'] ?>"
                                 data-name="<?= htmlspecialchars($cl['full_name'], ENT_QUOTES) ?>"
                                 data-initial="<?= htmlspecialchars($cl['initial'], ENT_QUOTES) ?>"
                                 data-avatar-url="<?= htmlspecialchars($cl['avatar_url'] ?? '', ENT_QUOTES) ?>"
                                 data-is-saved="<?= $isSaved ? '1' : '0' ?>"
                                 data-is-bot="<?= $isBot ? '1' : '0' ?>"
                                 onclick="openChat(<?= (int)$cl['user_id'] ?>, this)">
                                <div class="chat-item__avatar <?= $isSaved ? 'is-saved-avatar' : '' ?> <?= $isBot ? 'is-bot-avatar' : '' ?>" data-avatar-for="<?= (int)$cl['user_id'] ?>">
                                    <?php if ($isSaved): ?><i class="fas fa-bookmark"></i>
                                    <?php elseif ($isBot): ?><i class="fas fa-robot"></i>
                                    <?php elseif ($cl['avatar_url']): ?><img src="<?= htmlspecialchars($cl['avatar_url']) ?>" alt="">
                                    <?php else: ?><?= htmlspecialchars($cl['initial']) ?><?php endif; ?>
                                </div>
                                <div class="chat-item__info">
                                    <div class="chat-item__name">
                                        <?= htmlspecialchars($cl['full_name']) ?>
                                        <?php if ($isSaved && $cl['total_count'] > 0): ?>
                                            <span class="saved-count-badge"><?= $cl['total_count'] ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="chat-item__last" data-status-for="<?= (int)$cl['user_id'] ?>">
                                        <?php if ($isSaved):
                                            if (!empty($savedLastMsg)):
                                                $previewText = !empty($savedLastMsg['message']) ? mb_substr($savedLastMsg['message'], 0, 40) : (!empty($savedLastMsg['attachment_name']) ? '📎 ' . mb_substr($savedLastMsg['attachment_name'], 0, 35) : 'فایل');
                                                echo htmlspecialchars($previewText);
                                            else: ?>یادداشت‌ها و فایل‌های شخصی شما
                                            <?php endif;
                                        elseif ($isBot): ?>اطلاع‌رسانی خودکار وظایف
                                        <?php elseif ($cl['has_blocked_me']): ?>🚫 شما را مسدود کرده
                                        <?php elseif ($cl['is_blocked_by_me']): ?>🚫 مسدود توسط شما
                                        <?php else: ?><?= htmlspecialchars($cl['mobile']) ?><?php endif; ?>
                                    </div>
                                </div>
                                <?php if (!$isSaved && $cl['unread_count'] > 0): ?>
                                    <div class="chat-item__badge" data-user-id="<?= (int)$cl['user_id'] ?>"><?= $cl['unread_count'] ?></div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <div class="chat-window">
                <div class="chat-empty" id="chatEmptyState">
                    <i class="fas fa-comments"></i>
                    <h3>یک گفتگو را انتخاب کنید</h3>
                    <p>از لیست سمت راست، یکی از همکاران خود را انتخاب کنید تا گفتگو را شروع کنید.</p>
                </div>

                <div id="chatActiveArea" style="display:none;flex-direction:column;height:100%;">
                    <div class="chat-header">
                        <button type="button" class="chat-header__back" onclick="closeChatOnMobile()" aria-label="بازگشت">
                            <i class="fas fa-arrow-right"></i>
                        </button>
                        <div class="chat-header__avatar" id="chatHeaderAvatar">?</div>
                        <div class="chat-header__info">
                            <div class="chat-header__name" id="chatHeaderName">...</div>
                            <div class="chat-header__status" id="chatHeaderStatus">
                                <i class="fas fa-circle"></i>
                                <span id="chatHeaderStatusText">در حال بررسی...</span>
                            </div>
                        </div>
                        <div class="chat-header__actions">
                            <button class="chat-header__action" title="جستجو در پیام‌ها" onclick="openSearchModal('conversation')">
                                <i class="fas fa-search"></i>
                            </button>
                            <button class="chat-header__action" title="اطلاعات" onclick="showToast('اطلاعات مکالمه', 'info')">
                                <i class="fas fa-info-circle"></i>
                            </button>
                            <button class="chat-header__action" title="منو" onclick="showToast('گزینه‌های بیشتر', 'info')">
                                <i class="fas fa-ellipsis-v"></i>
                            </button>
                        </div>
                    </div>

                    <div class="chat-body" id="chatBody"></div>

                    <form class="chat-footer" id="chatForm" onsubmit="sendMessage(event)">
                        <button type="button" class="chat-attach-btn" id="attachBtn" title="پیوست فایل" onclick="document.getElementById('fileInput').click()">
                            <i class="fas fa-paperclip"></i>
                        </button>
                        <input type="file" id="fileInput" style="display:none;"
                               accept=".png,.jpg,.jpeg,.pdf,.zip,.txt,image/png,image/jpeg,application/pdf,application/zip,text/plain"
                               onchange="handleFileSelect(this)">
                        <div class="chat-footer__input-wrap">
                            <textarea class="chat-footer__input" id="chatInput" placeholder="پیام خود را بنویسید..." rows="1"></textarea>
                        </div>
                        <button type="submit" class="chat-send-btn" id="chatSendBtn" title="ارسال">
                            <i class="fas fa-paper-plane"></i>
                        </button>
                        <div class="upload-progress" id="uploadProgress">
                            <div class="upload-progress__bar" id="uploadProgressBar"></div>
                        </div>
                    </form>

                    <div class="file-preview" id="filePreview">
                        <div class="file-preview__icon" id="filePreviewIcon"><i class="fas fa-file"></i></div>
                        <div class="file-preview__info">
                            <div class="file-preview__name" id="filePreviewName">فایل</div>
                            <div class="file-preview__size" id="filePreviewSize">0 KB</div>
                        </div>
                        <button type="button" class="file-preview__close" onclick="clearFilePreview()" title="حذف">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ============ SEARCH MODAL ============ -->
<div class="search-modal-overlay" id="searchModalOverlay" onclick="if(event.target === this) closeSearchModal()">
    <div class="search-modal" role="dialog" aria-modal="true">
        <div class="search-modal__header">
            <div class="search-modal__title-row">
                <div class="search-modal__title">
                    <i class="fas fa-search"></i>
                    جستجو در پیام‌ها
                </div>
                <button type="button" class="search-modal__close" onclick="closeSearchModal()" aria-label="بستن">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="search-modal__input-wrap">
                <i class="fas fa-search"></i>
                <input type="text" class="search-modal__input" id="searchQueryInput"
                       placeholder="کلمه یا عبارت موردنظر را تایپ کنید..." autocomplete="off">
            </div>
            <div class="search-modal__scope" id="searchScopeRow">
                <button type="button" class="search-modal__scope-btn active" data-scope="all" onclick="setSearchScope('all')">
                    <i class="fas fa-globe"></i> همه گفتگوها
                </button>
                <button type="button" class="search-modal__scope-btn" data-scope="conversation" id="scopeConversationBtn" onclick="setSearchScope('conversation')">
                    <i class="fas fa-comment"></i> این گفتگو
                </button>
            </div>
        </div>
        <div class="search-modal__body" id="searchResultsBody">
            <div class="search-modal__empty">
                <i class="fas fa-keyboard"></i>
                حداقل ۲ کاراکتر تایپ کنید تا جستجو شروع شود...
            </div>
        </div>
    </div>
</div>

<!-- ============ LIGHTBOX ============ -->
<div class="image-lightbox" id="imageLightbox">
    <button type="button" class="image-lightbox__close" onclick="closeLightbox()" title="بستن (Esc)"><i class="fas fa-times"></i></button>
    <div class="image-lightbox__topbar"><i class="fas fa-search-plus"></i><span id="zoomLevel">100%</span></div>
    <div class="image-lightbox__canvas" id="lightboxCanvas" onclick="if(event.target === this) closeLightbox()">
        <img src="" alt="" class="image-lightbox__img" id="lightboxImage" draggable="false">
    </div>
    <div class="image-lightbox__controls" onclick="event.stopPropagation()">
        <button type="button" class="image-lightbox__btn" data-tooltip="کوچک‌نمایی (-)" onclick="zoomOut()"><i class="fas fa-search-minus"></i></button>
        <button type="button" class="image-lightbox__btn" data-tooltip="بازنشانی (0)" onclick="resetZoom()"><i class="fas fa-compress-arrows-alt"></i></button>
        <button type="button" class="image-lightbox__btn" data-tooltip="بزرگ‌نمایی (+)" onclick="zoomIn()"><i class="fas fa-search-plus"></i></button>
        <div class="image-lightbox__divider"></div>
        <button type="button" class="image-lightbox__btn image-lightbox__btn--primary" data-tooltip="دانلود" onclick="downloadLightboxImage()"><i class="fas fa-download"></i></button>
    </div>
</div>

<!-- ============ CONTEXT MENU ============ -->
<div class="msg-context-menu" id="msgContextMenu">
    <button type="button" class="msg-context-menu__item" onclick="handleContextAction('copy', event)"><i class="fas fa-copy"></i><span>کپی متن</span></button>
    <button type="button" class="msg-context-menu__item" id="ctxDownloadBtn" onclick="handleContextAction('download', event)" style="display:none;"><i class="fas fa-download"></i><span>دانلود فایل</span></button>
    <div class="msg-context-menu__divider" id="ctxEditDivider" style="display:none;"></div>
    <button type="button" class="msg-context-menu__item" id="ctxEditBtn" onclick="handleContextAction('edit', event)" style="display:none;"><i class="fas fa-pen"></i><span>ویرایش</span></button>
    <div class="msg-context-menu__divider" id="ctxDeleteDivider" style="display:none;"></div>
    <button type="button" class="msg-context-menu__item msg-context-menu__item--danger" id="ctxDeleteBtn" onclick="handleContextAction('delete', event)" style="display:none;"><i class="fas fa-trash-alt"></i><span>حذف</span></button>
</div>

<!-- ============ DELETE MODAL ============ -->
<div class="chat-modal-overlay" id="deleteModal" onclick="if(event.target === this) closeDeleteModal()">
    <div class="chat-modal">
        <div class="chat-modal__header">
            <div class="chat-modal__icon chat-modal__icon--danger"><i class="fas fa-trash-alt"></i></div>
            <div>
                <h3 class="chat-modal__title">حذف پیام</h3>
                <div class="chat-modal__subtitle">این عملیات قابل بازگشت نیست</div>
            </div>
        </div>
        <div class="chat-modal__body">
            <p>آیا از حذف این پیام مطمئن هستید؟</p>
            <p style="color:#dc2626;font-size:0.82rem;">
                <i class="fas fa-exclamation-triangle" style="margin-left:4px;"></i>
                <strong>توجه:</strong> این پیام برای <strong>هر دو طرف</strong> حذف خواهد شد.
            </p>
        </div>
        <div class="chat-modal__footer">
            <button class="chat-modal__btn chat-modal__btn--danger" id="confirmDeleteBtn" onclick="confirmDeleteMessage()">
                <i class="fas fa-trash-alt"></i> بله، حذف کن
            </button>
            <button class="chat-modal__btn chat-modal__btn--ghost" onclick="closeDeleteModal()">انصراف</button>
        </div>
    </div>
</div>

<!-- ============ EDIT MODAL ============ -->
<div class="chat-modal-overlay" id="editModal" onclick="if(event.target === this) closeEditModal()">
    <div class="chat-modal">
        <div class="chat-modal__header">
            <div class="chat-modal__icon chat-modal__icon--info"><i class="fas fa-pen"></i></div>
            <div>
                <h3 class="chat-modal__title">ویرایش پیام</h3>
                <div class="chat-modal__subtitle">ویرایش تا ۱ ساعت پس از ارسال امکان‌پذیر است</div>
            </div>
        </div>
        <div class="chat-modal__body">
            <textarea class="chat-modal__textarea" id="editMessageTextarea" placeholder="متن پیام..."></textarea>
        </div>
        <div class="chat-modal__footer">
            <button class="chat-modal__btn chat-modal__btn--primary" id="confirmEditBtn" onclick="confirmEditMessage()"><i class="fas fa-check"></i> ذخیره تغییرات</button>
            <button class="chat-modal__btn chat-modal__btn--ghost" onclick="closeEditModal()">انصراف</button>
        </div>
    </div>
</div>

<div class="chat-toast" id="chatToast">
    <i class="fas fa-check-circle"></i>
    <span id="chatToastText"></span>
</div>

<script>
const CURRENT_USER_ID = <?= (int)$userId ?>;
const CHAT_LIST = <?= $chatListJson ?>;
const AUTO_OPEN_CHAT_ID = <?= (int)$autoOpenChatId ?>;

const MAX_FILE_SIZE = 50 * 1024 * 1024;
const ALLOWED_EXTENSIONS = ['png','jpg','jpeg','pdf','zip','txt'];
const FILE_BASE_URL = '../uploads/chat_files/';
const PAGE_SIZE = 30;

const FILE_ICONS = {'png':'fa-file-image','jpg':'fa-file-image','jpeg':'fa-file-image','pdf':'fa-file-pdf','zip':'fa-file-archive','txt':'fa-file-alt'};

let selectedFile = null;
let currentChatUserId = null;
let currentIsSaved = false;
let currentIsBot = false;
let pollTimer = null;
let statusTimer = null;
let statusRequestInFlight = false;
let sendingMessage = false;
let lastRenderedDay = null;
let loadedMessageIds = new Set();
let firstLoadedMsgId = 0;
let lastLoadedMsgId = 0;
let hasMoreBefore = false;
let hasMoreAfter = false;
let loadingOlder = false;
let isAtBottom = true;
let pendingHighlightMsgId = null;

// Lightbox
let lightboxZoom = 1;
const ZOOM_MIN = 0.25, ZOOM_MAX = 5, ZOOM_STEP = 0.25;
let lightboxCurrentUrl = '', lightboxCurrentName = '';
let lightboxPanX = 0, lightboxPanY = 0;
let isDragging = false, dragStartX = 0, dragStartY = 0;

const EDIT_DELETE_WINDOW_MS = 60 * 60 * 1000;
let contextMenuMsgData = null;
let pendingDeleteMsgId = null;
let editingMsgId = null;

// Search
let searchScope = 'all';
let searchTimer = null;
let searchAbortController = null;
let lastSearchResults = [];
let searchInputTouched = false;

function parseServerTime(s) {
    if (!s) return 0;
    const ts = Date.parse(String(s).replace(' ', 'T'));
    return isNaN(ts) ? 0 : ts;
}

function checkMessagePermissions(el) {
    const senderId = parseInt(el.getAttribute('data-sender-id'), 10);
    const createdMs = parseInt(el.getAttribute('data-created-ms'), 10);
    const currentUserId = parseInt(CURRENT_USER_ID, 10);
    const isMine = !isNaN(senderId) && !isNaN(currentUserId) && senderId === currentUserId;
    const now = Date.now();
    const elapsed = now - createdMs;
    const isWithinWindow = !isNaN(createdMs) && createdMs > 0 && elapsed >= 0 && elapsed <= EDIT_DELETE_WINDOW_MS;
    return {
        isMine, createdMs, elapsed, isWithinWindow,
        hasFile: !!el.querySelector('.chat-file-card, .chat-image-wrapper'),
        hasText: (() => {
            const divs = el.children;
            for (let i = 0; i < divs.length; i++) {
                const c = divs[i];
                if (c.classList.contains('chat-bubble__time')) continue;
                if (c.classList.contains('chat-bubble__attachment')) continue;
                if (c.classList.contains('task-notif-header')) continue;
                if (c.classList.contains('task-notif-actions')) continue;
                if (c.tagName === 'DIV' && (c.innerText || c.textContent || '').trim()) return true;
            }
            return false;
        })(),
    };
}

function extractMessageDataFromBubble(el) {
    const msgId = parseInt(el.getAttribute('data-msg-id'), 10) || 0;
    const senderId = parseInt(el.getAttribute('data-sender-id'), 10) || 0;
    const createdMs = parseInt(el.getAttribute('data-created-ms'), 10) || 0;
    let messageText = '';
    const divs = el.children;
    for (let i = 0; i < divs.length; i++) {
        const c = divs[i];
        if (c.classList.contains('chat-bubble__time')) continue;
        if (c.classList.contains('chat-bubble__attachment')) continue;
        if (c.classList.contains('task-notif-header')) continue;
        if (c.classList.contains('task-notif-actions')) continue;
        if (c.tagName === 'DIV') { messageText = c.innerText || c.textContent || ''; break; }
    }
    let attachmentUrl = null, attachmentName = null;
    const fileCard = el.querySelector('.chat-file-card');
    const imgWrap = el.querySelector('.chat-image-wrapper');
    if (fileCard) {
        attachmentUrl = fileCard.getAttribute('href');
        const nameEl = fileCard.querySelector('.chat-file-card__name');
        attachmentName = nameEl ? nameEl.textContent.trim() : 'file';
    } else if (imgWrap) {
        const img = imgWrap.querySelector('img');
        if (img) { attachmentUrl = img.getAttribute('src'); attachmentName = img.getAttribute('alt') || 'image'; }
    }
    return { id: msgId, sender_id: senderId, message: messageText, attachment: attachmentUrl, attachment_name: attachmentName, createdAtMs: createdMs };
}

function openContextMenu(e, bubbleEl) {
    e.preventDefault(); e.stopPropagation();
    const msgId = parseInt(bubbleEl.getAttribute('data-msg-id'), 10);
    if (!msgId) return;
    const perm = checkMessagePermissions(bubbleEl);
    contextMenuMsgData = extractMessageDataFromBubble(bubbleEl);
    document.querySelectorAll('.chat-bubble.context-active').forEach(el => el.classList.remove('context-active'));
    bubbleEl.classList.add('context-active');
    const menu = document.getElementById('msgContextMenu');
    document.getElementById('ctxDownloadBtn').style.display = perm.hasFile ? 'flex' : 'none';
    const showEdit = perm.isMine && perm.hasText && !perm.hasFile && perm.isWithinWindow;
    document.getElementById('ctxEditBtn').style.display = showEdit ? 'flex' : 'none';
    document.getElementById('ctxEditDivider').style.display = showEdit ? 'block' : 'none';
    const showDelete = perm.isMine && perm.isWithinWindow;
    document.getElementById('ctxDeleteBtn').style.display = showDelete ? 'flex' : 'none';
    document.getElementById('ctxDeleteDivider').style.display = showDelete ? 'block' : 'none';
    if (!perm.hasText && !perm.hasFile && !showEdit && !showDelete) {
        bubbleEl.classList.remove('context-active'); return;
    }
    menu.style.left = '0px'; menu.style.top = '0px';
    menu.classList.add('active');
    requestAnimationFrame(() => {
        const r = menu.getBoundingClientRect();
        let x = e.clientX, y = e.clientY;
        if (x + r.width > window.innerWidth - 10) x = window.innerWidth - r.width - 10;
        if (x < 10) x = 10;
        if (y + r.height > window.innerHeight - 10) y = window.innerHeight - r.height - 10;
        if (y < 10) y = 10;
        menu.style.left = x + 'px';
        menu.style.top = y + 'px';
    });
}

function closeContextMenu() {
    document.getElementById('msgContextMenu').classList.remove('active');
    document.querySelectorAll('.chat-bubble.context-active').forEach(el => el.classList.remove('context-active'));
    contextMenuMsgData = null;
}

function handleContextAction(action, e) {
    if (e) { e.preventDefault(); e.stopPropagation(); }
    if (!contextMenuMsgData) return;
    const data = { ...contextMenuMsgData };
    closeContextMenu();
    switch (action) {
        case 'copy': copyMessageText(data.message); break;
        case 'download': downloadAttachment(data.attachment, data.attachment_name); break;
        case 'edit': openEditModal(data); break;
        case 'delete': openDeleteModal(data.id); break;
    }
}

function copyMessageText(text) {
    if (!text || !text.trim()) { showToast('متنی برای کپی وجود ندارد', 'error'); return; }
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(() => showToast('متن پیام کپی شد', 'success')).catch(() => fallbackCopy(text));
    } else fallbackCopy(text);
}
function fallbackCopy(text) {
    const ta = document.createElement('textarea');
    ta.value = text; ta.style.cssText = 'position:fixed;opacity:0;top:0;left:0;';
    document.body.appendChild(ta); ta.focus(); ta.select();
    try { document.execCommand('copy'); showToast('متن پیام کپی شد', 'success'); }
    catch (e) { showToast('کپی نشد', 'error'); }
    document.body.removeChild(ta);
}

function downloadAttachment(url, name) {
    if (!url) { showToast('فایلی برای دانلود وجود ندارد', 'error'); return; }
    showToast('در حال آماده‌سازی دانلود...', 'info');
    fetch(url).then(r => { if (!r.ok) throw 0; return r.blob(); })
        .then(blob => {
            const bUrl = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = bUrl; a.download = name || 'file';
            document.body.appendChild(a); a.click(); document.body.removeChild(a);
            setTimeout(() => URL.revokeObjectURL(bUrl), 1000);
            showToast('دانلود شروع شد', 'success');
        })
        .catch(() => { window.open(url, '_blank'); showToast('فایل در تب جدید باز شد', 'info'); });
}

function openDeleteModal(msgId) {
    pendingDeleteMsgId = msgId;
    document.getElementById('deleteModal').classList.add('active');
    const btn = document.getElementById('confirmDeleteBtn');
    btn.disabled = false;
    btn.innerHTML = '<i class="fas fa-trash-alt"></i> بله، حذف کن';
}
function closeDeleteModal() {
    document.getElementById('deleteModal').classList.remove('active');
    pendingDeleteMsgId = null;
}

function confirmDeleteMessage() {
    if (!pendingDeleteMsgId) return;
    const btn = document.getElementById('confirmDeleteBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> در حال حذف...';
    const fd = new FormData();
    fd.append('action', 'delete');
    fd.append('message_id', pendingDeleteMsgId);
    fetch('api.php', { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.status === 'success') {
                const b = document.querySelector(`.chat-bubble[data-msg-id="${pendingDeleteMsgId}"]`);
                if (b) {
                    b.style.transition = 'all 0.3s ease';
                    b.style.opacity = '0';
                    b.style.transform = 'translateX(-30px) scale(0.9)';
                    setTimeout(() => b.remove(), 300);
                }
                loadedMessageIds.delete(Number(pendingDeleteMsgId));
                closeDeleteModal();
                showToast('پیام برای هر دو طرف حذف شد', 'success');
                refreshSavedBadge();
            } else {
                showToast(data.message || 'خطا در حذف پیام', 'error');
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-trash-alt"></i> بله، حذف کن';
            }
        })
        .catch(() => {
            showToast('خطا در ارتباط با سرور', 'error');
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-trash-alt"></i> بله، حذف کن';
        });
}

function openEditModal(data) {
    editingMsgId = data.id;
    document.getElementById('editMessageTextarea').value = data.message || '';
    document.getElementById('editModal').classList.add('active');
    setTimeout(() => {
        const ta = document.getElementById('editMessageTextarea');
        ta.focus();
        ta.setSelectionRange(ta.value.length, ta.value.length);
    }, 200);
    const btn = document.getElementById('confirmEditBtn');
    btn.disabled = false;
    btn.innerHTML = '<i class="fas fa-check"></i> ذخیره تغییرات';
}
function closeEditModal() {
    document.getElementById('editModal').classList.remove('active');
    editingMsgId = null;
}

function confirmEditMessage() {
    if (!editingMsgId) return;
    const newText = document.getElementById('editMessageTextarea').value.trim();
    if (!newText) { showToast('متن پیام نمی‌تواند خالی باشد', 'error'); return; }
    const btn = document.getElementById('confirmEditBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> در حال ذخیره...';
    const fd = new FormData();
    fd.append('action', 'edit');
    fd.append('message_id', editingMsgId);
    fd.append('message', newText);
    fetch('api.php', { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.status === 'success') {
                updateBubbleText(editingMsgId, newText);
                closeEditModal();
                showToast('پیام ویرایش شد', 'success');
            } else {
                showToast(data.message || 'خطا در ویرایش', 'error');
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-check"></i> ذخیره تغییرات';
            }
        })
        .catch(() => {
            showToast('خطا در ارتباط با سرور', 'error');
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-check"></i> ذخیره تغییرات';
        });
}

function updateBubbleText(msgId, newText) {
    const bubble = document.querySelector(`.chat-bubble[data-msg-id="${msgId}"]`);
    if (!bubble) return;
    const divs = bubble.children;
    for (let i = 0; i < divs.length; i++) {
        const c = divs[i];
        if (c.classList.contains('chat-bubble__time')) continue;
        if (c.classList.contains('chat-bubble__attachment')) continue;
        if (c.classList.contains('task-notif-header')) continue;
        if (c.classList.contains('task-notif-actions')) continue;
        if (c.tagName === 'DIV') { c.innerHTML = escapeHtml(newText).replace(/\n/g, '<br>'); break; }
    }
    const timeEl = bubble.querySelector('.chat-bubble__time');
    if (timeEl && !bubble.querySelector('.chat-bubble__edited')) {
        const sp = document.createElement('span');
        sp.className = 'chat-bubble__edited';
        sp.textContent = '(ویرایش‌شده)';
        timeEl.insertBefore(sp, timeEl.firstChild);
    }
    bubble.setAttribute('data-edited', '1');
}

let toastTimer = null;
function showToast(text, type = 'success') {
    const toast = document.getElementById('chatToast');
    document.getElementById('chatToastText').textContent = text;
    const icon = toast.querySelector('i');
    icon.className = 'fas';
    toast.classList.remove('chat-toast--success','chat-toast--error','chat-toast--info');
    if (type === 'success') { icon.classList.add('fa-check-circle'); toast.classList.add('chat-toast--success'); }
    else if (type === 'error') { icon.classList.add('fa-exclamation-circle'); toast.classList.add('chat-toast--error'); }
    else { icon.classList.add('fa-info-circle'); toast.classList.add('chat-toast--info'); }
    toast.classList.add('active');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => toast.classList.remove('active'), 2400);
}

/* ======================================================
   OPEN CHAT
   ====================================================== */
function openChat(userId, element) {
    if (currentChatUserId === userId && !pendingHighlightMsgId) return;

    // ⭐ اطلاع به سیستم نوتیف سراسری
    if (window.ChatNotifications) window.ChatNotifications.setActiveChat(userId);

    const isSaved = element && element.getAttribute('data-is-saved') === '1';
    const isBot = element && element.getAttribute('data-is-bot') === '1';
    currentChatUserId = userId;
    currentIsSaved = isSaved;
    currentIsBot = isBot;
    lastRenderedDay = null;
    loadedMessageIds = new Set();
    firstLoadedMsgId = 0;
    lastLoadedMsgId = 0;
    hasMoreBefore = false;
    hasMoreAfter = false;
    loadingOlder = false;

    document.querySelectorAll('.chat-item').forEach(el => el.classList.remove('active'));
    if (element) element.classList.add('active');

    let person = element ? {
        name: element.getAttribute('data-name') || '',
        initial: element.getAttribute('data-initial') || '?',
        avatar_url: element.getAttribute('data-avatar-url') || null,
    } : (CHAT_LIST.find(c => Number(c.user_id) === Number(userId)) || { name: '', initial: '?', avatar_url: null });
    if (!element) currentIsSaved = !!person.is_saved;

    document.getElementById('chatHeaderName').textContent = person.name;
    const headerAvatar = document.getElementById('chatHeaderAvatar');
    const headerStatus = document.getElementById('chatHeaderStatus');
    const headerStatusText = document.getElementById('chatHeaderStatusText');
    const chatInputEl = document.getElementById('chatInput');

    headerAvatar.classList.remove('is-saved-avatar', 'is-bot-avatar', 'online', 'offline');

    if (currentIsSaved) {
        headerAvatar.innerHTML = '<i class="fas fa-bookmark"></i>';
        headerAvatar.classList.add('is-saved-avatar');
        headerStatus.className = 'chat-header__status saved';
        headerStatusText.textContent = 'ذخیره‌سازی شخصی';
        chatInputEl.placeholder = 'یادداشت، لینک یا فایل خود را اینجا بنویسید...';
    } else if (currentIsBot) {
        headerAvatar.innerHTML = '<i class="fas fa-robot"></i>';
        headerAvatar.classList.add('is-bot-avatar');
        headerStatus.className = 'chat-header__status bot';
        headerStatusText.textContent = 'اطلاع‌رسانی خودکار وظایف';
        chatInputEl.placeholder = 'این حساب فقط اطلاع‌رسانی می‌کند';
    } else {
        if (person.avatar_url) headerAvatar.innerHTML = `<img src="${escapeHtml(person.avatar_url)}" alt="${escapeHtml(person.name)}">`;
        else headerAvatar.textContent = person.initial || '?';
        chatInputEl.placeholder = 'پیام خود را بنویسید...';
    }

    document.getElementById('chatEmptyState').style.display = 'none';
    document.getElementById('chatActiveArea').style.display = 'flex';
    document.getElementById('chatBody').innerHTML = '';

    const container = document.getElementById('chatContainer');
    if (container) container.classList.add('chat-open');

    const badge = document.querySelector(`.chat-item__badge[data-user-id="${userId}"]`);
    if (badge) badge.remove();

    if (pendingHighlightMsgId) {
        loadMessagesAround(pendingHighlightMsgId);
        pendingHighlightMsgId = null;
    } else {
        loadInitialMessages();
    }

    setTimeout(() => chatInputEl.focus(), 200);

    if (pollTimer) clearInterval(pollTimer);
    pollTimer = setInterval(pollNewMessages, 3000);

    if (statusTimer) clearInterval(statusTimer);
    if (!currentIsSaved && !currentIsBot) {
        fetchUserStatus();
        statusTimer = setInterval(fetchUserStatus, 5000);
    }
}

function closeChatOnMobile() {
    // ⭐ اطلاع به سیستم نوتیف سراسری که دیگه چتی باز نیست
    if (window.ChatNotifications) window.ChatNotifications.clearActiveChat();

    const c = document.getElementById('chatContainer');
    if (c) c.classList.remove('chat-open');
    currentChatUserId = null;
    currentIsSaved = false;
    currentIsBot = false;
    if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
    if (statusTimer) { clearInterval(statusTimer); statusTimer = null; }
    clearFilePreview();
    closeContextMenu();
}

/* ======================================================
   LAZY LOADING
   ====================================================== */
function loadInitialMessages() {
    if (!currentChatUserId) return;
    const body = document.getElementById('chatBody');
    body.innerHTML = '<div class="chat-load-more loading" id="loadMoreEl"><span class="chat-load-more__spinner"></span><span class="chat-load-more__text">در حال بارگذاری...</span></div>';

    fetch(`api.php?action=fetch&user_id=${currentChatUserId}&mode=initial&limit=${PAGE_SIZE}`, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(r => r.json())
        .then(data => {
            if (data.status !== 'success') { showToast('خطا در دریافت پیام‌ها', 'error'); return; }
            const messages = data.messages || [];
            hasMoreBefore = !!data.has_more_before;
            hasMoreAfter = !!data.has_more_after;

            body.innerHTML = '';
            lastRenderedDay = null;

            if (messages.length === 0) {
                if (currentIsSaved) {
                    body.innerHTML = `<div class="chat-empty"><i class="fas fa-bookmark" style="color:#fbbf24;opacity:0.6;"></i><h3>پیام‌های ذخیره شده</h3><p>اینجا فضای شخصی شماست. می‌توانید یادداشت‌ها، لینک‌ها و فایل‌های خود را ذخیره کنید.</p></div>`;
                } else if (currentIsBot) {
                    body.innerHTML = `<div class="chat-empty"><i class="fas fa-robot" style="color:#a78bfa;opacity:0.6;"></i><h3>اطلاع‌رسانی تسکام</h3><p>هر زمان شما در وظیفه‌ای منشن شوید، اطلاع‌رسانی آن در اینجا نمایش داده می‌شود.</p></div>`;
                } else {
                    body.innerHTML = '<div class="chat-day"><span>هنوز پیامی رد و بدل نشده</span></div>';
                }
                return;
            }

            const loader = document.createElement('div');
            loader.className = 'chat-load-more';
            loader.id = 'loadMoreEl';
            loader.innerHTML = '<span class="chat-load-more__spinner"></span><span class="chat-load-more__text">در حال بارگذاری...</span>';
            body.appendChild(loader);

            messages.forEach(msg => {
                appendMessage(msg);
                loadedMessageIds.add(Number(msg.id));
            });

            if (messages.length > 0) {
                firstLoadedMsgId = Number(messages[0].id);
                lastLoadedMsgId = Number(messages[messages.length - 1].id);
            }

            updateLoadMoreUI();
            requestAnimationFrame(() => { body.scrollTop = body.scrollHeight; });
        })
        .catch(() => showToast('خطا در ارتباط با سرور', 'error'));
}

function loadOlderMessages() {
    if (!currentChatUserId || loadingOlder || !hasMoreBefore || firstLoadedMsgId <= 0) return;
    loadingOlder = true;

    const body = document.getElementById('chatBody');
    const loader = document.getElementById('loadMoreEl');
    if (loader) loader.classList.add('loading');

    const prevScrollHeight = body.scrollHeight;
    const prevScrollTop = body.scrollTop;

    fetch(`api.php?action=fetch&user_id=${currentChatUserId}&mode=older&before_id=${firstLoadedMsgId}&limit=${PAGE_SIZE}`, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(r => r.json())
        .then(data => {
            if (data.status !== 'success') return;
            const messages = data.messages || [];
            hasMoreBefore = !!data.has_more_before;

            if (messages.length === 0) {
                updateLoadMoreUI();
                return;
            }

            firstLoadedMsgId = Number(messages[0].id);

            const frag = buildMessagesFragment(messages);
            messages.forEach(m => loadedMessageIds.add(Number(m.id)));

            const loaderEl = document.getElementById('loadMoreEl');
            if (loaderEl) {
                loaderEl.insertAdjacentElement('afterend', frag);
            } else {
                body.insertBefore(frag, body.firstChild);
            }

            requestAnimationFrame(() => {
                const newScrollHeight = body.scrollHeight;
                body.scrollTop = prevScrollTop + (newScrollHeight - prevScrollHeight);
            });

            updateLoadMoreUI();
        })
        .catch(() => {})
        .finally(() => { loadingOlder = false; });
}

function loadMessagesAround(targetId) {
    const body = document.getElementById('chatBody');
    body.innerHTML = '<div class="chat-load-more loading"><span class="chat-load-more__spinner"></span><span class="chat-load-more__text">در حال بارگذاری...</span></div>';

    fetch(`api.php?action=fetch&user_id=${currentChatUserId}&mode=around&target_id=${targetId}&limit=${PAGE_SIZE}`, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(r => r.json())
        .then(data => {
            if (data.status !== 'success') return;
            const messages = data.messages || [];
            hasMoreBefore = !!data.has_more_before;
            hasMoreAfter = !!data.has_more_after;

            body.innerHTML = '';
            lastRenderedDay = null;

            const loader = document.createElement('div');
            loader.className = 'chat-load-more';
            loader.id = 'loadMoreEl';
            loader.innerHTML = '<span class="chat-load-more__spinner"></span><span class="chat-load-more__text">در حال بارگذاری...</span>';
            body.appendChild(loader);

            messages.forEach(msg => {
                appendMessage(msg);
                loadedMessageIds.add(Number(msg.id));
            });
            if (messages.length > 0) {
                firstLoadedMsgId = Number(messages[0].id);
                lastLoadedMsgId = Number(messages[messages.length - 1].id);
            }

            updateLoadMoreUI();

            setTimeout(() => {
                const target = document.querySelector(`.chat-bubble[data-msg-id="${targetId}"]`);
                if (target) {
                    target.scrollIntoView({ block: 'center', behavior: 'auto' });
                    target.classList.add('search-highlight');
                    setTimeout(() => target.classList.remove('search-highlight'), 2400);
                }
            }, 150);
        })
        .catch(() => {});
}

function pollNewMessages() {
    if (!currentChatUserId) return;
    if (lastLoadedMsgId <= 0) return;
    fetch(`api.php?action=fetch&user_id=${currentChatUserId}&mode=newer&after_id=${lastLoadedMsgId}&limit=50`, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(r => r.json())
        .then(data => {
            if (data.status !== 'success') return;
            const messages = data.messages || [];
            if (messages.length === 0) return;

            const body = document.getElementById('chatBody');
            isAtBottom = (body.scrollHeight - body.scrollTop - body.clientHeight) < 80;

            let hasNewFromMe = false;
            messages.forEach(msg => {
                const msgId = Number(msg.id);
                if (!loadedMessageIds.has(msgId)) {
                    appendMessage(msg);
                    loadedMessageIds.add(msgId);
                    if (Number(msg.sender_id) === CURRENT_USER_ID) hasNewFromMe = true;
                } else {
                    updateMessageReadStatus(msgId, Number(msg.is_read));
                    updateMessageTextIfEdited(msgId, msg);
                }
            });
            lastLoadedMsgId = Math.max(lastLoadedMsgId, Number(messages[messages.length - 1].id));

            if (isAtBottom || hasNewFromMe) requestAnimationFrame(() => { body.scrollTop = body.scrollHeight; });
        })
        .catch(() => {});
}

function updateLoadMoreUI() {
    const loader = document.getElementById('loadMoreEl');
    if (!loader) return;
    loader.classList.remove('loading');
    if (!hasMoreBefore) {
        loader.classList.add('no-more');
        loader.querySelector('.chat-load-more__text').textContent = 'ابتدای گفتگو';
    } else {
        loader.querySelector('.chat-load-more__text').textContent = 'برای دیدن پیام‌های قدیمی‌تر اسکرول کنید';
    }
}

function updateMessageTextIfEdited(msgId, msg) {
    const b = document.querySelector(`.chat-bubble[data-msg-id="${msgId}"]`);
    if (!b) return;
    if (Number(msg.is_edited) === 1 && msg.message) {
        const divs = b.children;
        let textDiv = null;
        for (let i = 0; i < divs.length; i++) {
            const c = divs[i];
            if (c.classList.contains('chat-bubble__time')) continue;
            if (c.classList.contains('chat-bubble__attachment')) continue;
            if (c.classList.contains('task-notif-header')) continue;
            if (c.classList.contains('task-notif-actions')) continue;
            if (c.tagName === 'DIV') { textDiv = c; break; }
        }
        const cur = textDiv ? (textDiv.innerText || textDiv.textContent || '') : '';
        if (cur !== msg.message) {
            if (textDiv) textDiv.innerHTML = escapeHtml(msg.message).replace(/\n/g, '<br>');
            const timeEl = b.querySelector('.chat-bubble__time');
            if (timeEl && !b.querySelector('.chat-bubble__edited')) {
                const sp = document.createElement('span');
                sp.className = 'chat-bubble__edited';
                sp.textContent = '(ویرایش‌شده)';
                timeEl.insertBefore(sp, timeEl.firstChild);
            }
        }
    }
}

function updateMessageReadStatus(msgId, isRead) {
    const b = document.querySelector(`.chat-bubble[data-msg-id="${msgId}"]`);
    if (!b) return;
    const c = b.querySelector('.chat-bubble__time .check');
    if (!c) return;
    const wasRead = c.classList.contains('read');
    const nowRead = isRead === 1;
    if (wasRead === nowRead) return;
    if (nowRead) { c.classList.remove('fa-check'); c.classList.add('fa-check-double', 'read'); }
    else { c.classList.remove('fa-check-double', 'read'); c.classList.add('fa-check'); }
}

function getFileExtension(n) { return n.split('.').pop().toLowerCase(); }
function getFileIcon(e) { return FILE_ICONS[e] || 'fa-file'; }
function formatFileSize(b) {
    if (!b || b === 0) return '0 B';
    const u = ['B','KB','MB','GB'], i = Math.floor(Math.log(b) / Math.log(1024));
    return (b / Math.pow(1024, i)).toFixed(1) + ' ' + u[i];
}

function handleFileSelect(input) {
    if (!input.files || !input.files[0]) { clearFilePreview(); return; }
    const f = input.files[0];
    const ext = getFileExtension(f.name);
    if (f.size > MAX_FILE_SIZE) { alert('حجم فایل نباید بیشتر از ۵۰ مگابایت باشد.'); input.value = ''; clearFilePreview(); return; }
    if (!ALLOWED_EXTENSIONS.includes(ext)) { alert('فرمت فایل مجاز نیست.\nفرمت‌های مجاز: PNG, JPG, PDF, ZIP, TXT'); input.value = ''; clearFilePreview(); return; }
    selectedFile = f;
    const preview = document.getElementById('filePreview');
    const iconEl = document.getElementById('filePreviewIcon');
    const nameEl = document.getElementById('filePreviewName');
    const sizeEl = document.getElementById('filePreviewSize');
    iconEl.className = 'file-preview__icon';
    if (ext === 'pdf') iconEl.classList.add('type-pdf');
    else if (ext === 'zip') iconEl.classList.add('type-zip');
    else if (ext === 'txt') iconEl.classList.add('type-txt');
    else if (['png','jpg','jpeg'].includes(ext)) iconEl.classList.add('type-' + (ext === 'jpeg' ? 'jpg' : ext));
    iconEl.innerHTML = `<i class="fas ${getFileIcon(ext)}"></i>`;
    nameEl.textContent = f.name;
    sizeEl.textContent = formatFileSize(f.size);
    preview.classList.add('active');
    setTimeout(() => document.getElementById('chatInput').focus(), 100);
}

function clearFilePreview() {
    selectedFile = null;
    document.getElementById('fileInput').value = '';
    document.getElementById('filePreview').classList.remove('active');
}

function updateUploadProgress(p) {
    const w = document.getElementById('uploadProgress');
    const b = document.getElementById('uploadProgressBar');
    if (p > 0 && p < 100) { w.classList.add('active'); b.style.width = p + '%'; }
    else if (p >= 100) { b.style.width = '100%'; setTimeout(() => { w.classList.remove('active'); b.style.width = '0%'; }, 300); }
    else { w.classList.remove('active'); b.style.width = '0%'; }
}

function createMessageElement(msg) {
    const senderIdNum = Number(msg.sender_id);
    const isSent = senderIdNum === CURRENT_USER_ID;
    let cls;
    if (currentIsSaved) cls = 'saved-self';
    else cls = isSent ? 'sent' : 'received';

    const createdMs = parseServerTime(msg.created_at);
    const dateObj = createdMs ? new Date(createdMs) : new Date();

    let checkIcon = '';
    if (isSent && !currentIsSaved) {
        checkIcon = Number(msg.is_read) === 1 ? '<i class="fas fa-check-double check read"></i>' : '<i class="fas fa-check check"></i>';
    } else if (currentIsSaved) {
        checkIcon = '<i class="fas fa-bookmark" style="font-size:0.65rem;color:#d97706;"></i>';
    }

    let contentHtml = '';
    let isTaskNotification = false;
    let taskBtnHtml = '';

    if (msg.message && msg.message.trim() !== '') {
        let rawMsg = msg.message;

        rawMsg = rawMsg.replace(/\[\[task:([a-fA-F0-9]+)\]\]/g, function(m, token) {
            isTaskNotification = true;
            const url = '../tasks/index.php?task=' + encodeURIComponent(token);
            taskBtnHtml += '<a href="' + url + '" class="chat-task-action-btn" target="_blank" rel="noopener"><i class="fas fa-external-link-alt"></i> باز کردن وظیفه</a>';
            return '';
        });

        rawMsg = rawMsg.replace(/\[\[call:([a-fA-F0-9]+)\]\]/g, function(m, token) {
            isTaskNotification = true;
            const url = '../call_requests/index.php?call=' + encodeURIComponent(token);
            taskBtnHtml += '<a href="' + url + '" class="chat-task-action-btn" style="background:linear-gradient(135deg,#f59e0b,#d97706);box-shadow:0 4px 12px rgba(245,158,11,0.4);" target="_blank" rel="noopener"><i class="fas fa-phone-volume"></i> باز کردن درخواست تماس</a>';
            return '';
        });

        rawMsg = rawMsg.replace(/\s+$/, '').replace(/\n{3,}/g, '\n\n');

        if (isTaskNotification) {
            let headerLabel = 'اطلاع‌رسانی';
            let headerIcon = 'fa-bell';
            let headerColor = '';

            if (rawMsg.indexOf('📌') === 0 || rawMsg.indexOf('📌 وظیفه جدید') !== -1) {
                headerLabel = 'وظیفه جدید';
                headerIcon = 'fa-plus-circle';
                headerColor = 'style="color:#059669;"';
            } else if (rawMsg.indexOf('📞') === 0 || rawMsg.indexOf('📞 درخواست تماس') !== -1) {
                headerLabel = 'درخواست تماس جدید';
                headerIcon = 'fa-phone-volume';
                headerColor = 'style="color:#d97706;"';
            } else if (rawMsg.indexOf('📝') === 0 || rawMsg.indexOf('📝 یادداشت') !== -1) {
                headerLabel = 'یادداشت جدید';
                headerIcon = 'fa-comment-dots';
                headerColor = 'style="color:#7c3aed;"';
            }

            let headerHtml = '<div class="task-notif-header"><span class="tn-icon" ' + headerColor + '><i class="fas ' + headerIcon + '"></i></span><span>' + headerLabel + '</span></div>';
            let bodyHtml = rawMsg.trim() ? '<div class="task-notif-body">' + escapeHtml(rawMsg.trim()).replace(/\n/g, '<br>') + '</div>' : '';
            let actionsHtml = taskBtnHtml ? '<div class="task-notif-actions">' + taskBtnHtml + '</div>' : '';
            contentHtml = headerHtml + bodyHtml + actionsHtml;
        } else {
            if (rawMsg.trim()) {
                contentHtml += '<div>' + escapeHtml(rawMsg).replace(/\n/g, '<br>') + '</div>';
            }
            if (taskBtnHtml) {
                contentHtml += '<div class="task-notif-actions">' + taskBtnHtml + '</div>';
            }
        }
    }

    if (msg.attachment) {
        const ext = (msg.attachment_type || getFileExtension(msg.attachment)).toLowerCase();
        const fileUrl = FILE_BASE_URL + msg.attachment;
        const fileName = msg.attachment_name || msg.attachment;
        const fileSize = formatFileSize(msg.attachment_size || 0);
        const icon = getFileIcon(ext);
        if (['png','jpg','jpeg'].includes(ext)) {
            contentHtml += `<div class="chat-bubble__attachment"><div class="chat-image-wrapper" onclick="event.stopPropagation();openLightbox('${escapeHtml(fileUrl)}','${escapeHtml(fileName)}')"><img src="${escapeHtml(fileUrl)}" alt="${escapeHtml(fileName)}" class="chat-image-preview"><div class="image-zoom-hint"><i class="fas fa-expand"></i></div></div></div>`;
        } else {
            contentHtml += `<div class="chat-bubble__attachment"><a href="${escapeHtml(fileUrl)}" class="chat-file-card" download="${escapeHtml(fileName)}" target="_blank" onclick="event.stopPropagation();"><div class="chat-file-card__icon type-${ext}"><i class="fas ${icon}"></i></div><div class="chat-file-card__info"><div class="chat-file-card__name">${escapeHtml(fileName)}</div><div class="chat-file-card__size"><i class="fas fa-download"></i> ${escapeHtml(fileSize)}</div></div></a></div>`;
        }
    }

    const editedHtml = Number(msg.is_edited) === 1 ? '<span class="chat-bubble__edited">(ویرایش‌شده)</span>' : '';

    const bubble = document.createElement('div');
    let clsExtra = isTaskNotification ? ' task-notification' : '';
    bubble.className = 'chat-bubble ' + cls + clsExtra;
    bubble.setAttribute('data-msg-id', String(msg.id));
    bubble.setAttribute('data-created-ms', String(createdMs || Date.now()));
    bubble.setAttribute('data-sender-id', String(senderIdNum));
    if (Number(msg.is_edited) === 1) bubble.setAttribute('data-edited', '1');

    bubble.innerHTML = `
        ${contentHtml}
        <div class="chat-bubble__time">
            ${editedHtml}
            <span>${formatTime(dateObj)}</span>
            ${checkIcon}
        </div>
    `;
    return bubble;
}

function buildMessagesFragment(messages) {
    const frag = document.createDocumentFragment();
    let lastDay = null;
    messages.forEach(msg => {
        const createdMs = parseServerTime(msg.created_at);
        const dateObj = createdMs ? new Date(createdMs) : new Date();
        const dayLabel = formatDayLabel(dateObj);
        if (lastDay !== dayLabel) {
            const d = document.createElement('div');
            d.className = 'chat-day';
            d.innerHTML = `<span>${escapeHtml(dayLabel)}</span>`;
            frag.appendChild(d);
            lastDay = dayLabel;
        }
        frag.appendChild(createMessageElement(msg));
    });
    return frag;
}

function appendMessage(msg) {
    const body = document.getElementById('chatBody');
    const createdMs = parseServerTime(msg.created_at);
    const dateObj = createdMs ? new Date(createdMs) : new Date();
    const dayLabel = formatDayLabel(dateObj);
    if (lastRenderedDay !== dayLabel) {
        const d = document.createElement('div');
        d.className = 'chat-day';
        d.innerHTML = `<span>${escapeHtml(dayLabel)}</span>`;
        body.appendChild(d);
        lastRenderedDay = dayLabel;
    }
    body.appendChild(createMessageElement(msg));
}

function formatTime(d) {
    return String(d.getHours()).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0');
}
function formatDayLabel(d) {
    const t = new Date(), y = new Date();
    y.setDate(t.getDate() - 1);
    const s = d.toDateString();
    if (s === t.toDateString()) return 'امروز';
    if (s === y.toDateString()) return 'دیروز';
    const m = ['فروردین','اردیبهشت','خرداد','تیر','مرداد','شهریور','مهر','آبان','آذر','دی','بهمن','اسفند'];
    return d.getDate() + ' ' + m[d.getMonth()] + ' ' + d.getFullYear();
}

function sendHeartbeat() {
    fetch('api.php?action=heartbeat', { headers: { 'X-Requested-With': 'XMLHttpRequest' } }).catch(() => {});
}
function fetchUserStatus() {
    if (!currentChatUserId || currentIsSaved || currentIsBot) return;
    if (statusRequestInFlight) return;
    statusRequestInFlight = true;
    fetch('api.php?action=user_status&user_id=' + currentChatUserId, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(r => r.json())
        .then(data => { if (data.status === 'success') updateStatusUI(currentChatUserId, data.is_online, data.last_seen, data.last_seen_text); })
        .catch(() => {})
        .finally(() => { statusRequestInFlight = false; });
}

function updateStatusUI(targetUserId, isOnline, lastSeenTs, lastSeenText) {
    if (currentIsSaved && Number(targetUserId) === Number(CURRENT_USER_ID)) return;
    if (currentChatUserId && Number(currentChatUserId) === Number(targetUserId)) {
        if (currentIsBot) return;
        const s = document.getElementById('chatHeaderStatus');
        const t = document.getElementById('chatHeaderStatusText');
        const a = document.getElementById('chatHeaderAvatar');
        s.classList.remove('saved', 'bot');
        if (isOnline) {
            s.classList.remove('offline'); s.classList.add('online');
            t.textContent = 'آنلاین';
            if (a) { a.classList.remove('offline'); a.classList.add('online'); }
        } else {
            s.classList.remove('online'); s.classList.add('offline');
            t.textContent = 'آفلاین — ' + (lastSeenText || 'نامشخص');
            if (a) { a.classList.remove('online'); a.classList.add('offline'); }
        }
    }
    const la = document.querySelector(`.chat-item__avatar[data-avatar-for="${targetUserId}"]`);
    if (la && !la.classList.contains('is-saved-avatar') && !la.classList.contains('is-bot-avatar')) {
        if (isOnline) { la.classList.remove('offline'); la.classList.add('online'); }
        else { la.classList.remove('online'); la.classList.add('offline'); }
    }
}

function fetchAllUsersStatus() {
    const items = document.querySelectorAll('.chat-item');
    const ids = [];
    items.forEach(item => {
        if (item.getAttribute('data-is-saved') === '1') return;
        if (item.getAttribute('data-is-bot') === '1') return;
        const uid = item.getAttribute('data-user-id');
        if (uid && Number(uid) !== currentChatUserId) ids.push(Number(uid));
    });
    if (ids.length === 0) return;
    fetch('api.php?action=users_status&user_ids=' + ids.join(','), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(r => r.json())
        .then(data => {
            if (data.status !== 'success' || !data.users) return;
            Object.keys(data.users).forEach(uid => {
                const info = data.users[uid];
                updateStatusUI(Number(uid), info.is_online, info.last_seen, info.last_seen_text);
            });
        })
        .catch(() => {});
}

function sendFileWithMessage() {
    if (!currentChatUserId || !selectedFile || sendingMessage) return;
    const inputEl = document.getElementById('chatInput');
    const text = inputEl.value.trim();
    sendingMessage = true;
    document.getElementById('chatSendBtn').disabled = true;
    document.getElementById('attachBtn').disabled = true;
    const fd = new FormData();
    fd.append('action', 'send_file');
    fd.append('receiver_id', currentChatUserId);
    fd.append('message', text);
    fd.append('attachment', selectedFile);
    const xhr = new XMLHttpRequest();
    xhr.upload.addEventListener('progress', e => {
        if (e.lengthComputable) updateUploadProgress(Math.round((e.loaded / e.total) * 100));
    });
    xhr.addEventListener('load', () => {
        updateUploadProgress(100);
        sendingMessage = false;
        document.getElementById('chatSendBtn').disabled = false;
        document.getElementById('attachBtn').disabled = false;
        try {
            const data = JSON.parse(xhr.responseText);
            if (data.status === 'success') {
                inputEl.value = ''; autoResize(inputEl); clearFilePreview();
                pollNewMessages();
                refreshSavedBadge();
            } else alert(data.message || 'خطا در ارسال فایل');
        } catch (e) { alert('خطا در پاسخ سرور'); }
    });
    xhr.addEventListener('error', () => {
        updateUploadProgress(0);
        sendingMessage = false;
        document.getElementById('chatSendBtn').disabled = false;
        document.getElementById('attachBtn').disabled = false;
        alert('خطا در ارتباط با سرور');
    });
    xhr.open('POST', 'api.php');
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    xhr.send(fd);
}

function sendMessage(e) {
    e.preventDefault();
    if (!currentChatUserId || sendingMessage) return;
    if (currentIsBot) {
        showToast('این حساب ربات اطلاع‌رسانی است', 'info');
        return;
    }
    if (selectedFile) { sendFileWithMessage(); return; }
    const inputEl = document.getElementById('chatInput');
    const text = inputEl.value.trim();
    if (!text) return;
    sendingMessage = true;
    document.getElementById('chatSendBtn').disabled = true;
    const fd = new FormData();
    fd.append('action', 'send');
    fd.append('receiver_id', currentChatUserId);
    fd.append('message', text);
    fetch('api.php', { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.status === 'success') {
                inputEl.value = ''; autoResize(inputEl);
                pollNewMessages();
                refreshSavedBadge();
            } else alert(data.message || 'خطا در ارسال');
        })
        .catch(() => alert('خطا در ارتباط با سرور'))
        .finally(() => {
            sendingMessage = false;
            document.getElementById('chatSendBtn').disabled = false;
        });
}

const chatInputEl = document.getElementById('chatInput');
function autoResize(el) { el.style.height = 'auto'; el.style.height = Math.min(el.scrollHeight, 100) + 'px'; }
chatInputEl.addEventListener('input', function() { autoResize(this); });
chatInputEl.addEventListener('keydown', function(e) {
    if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        document.getElementById('chatForm').dispatchEvent(new Event('submit'));
    }
});

document.getElementById('chatSearchInput').addEventListener('input', function() {
    const q = this.value.trim().toLowerCase();
    document.querySelectorAll('.chat-item').forEach(item => {
        const name = (item.getAttribute('data-name') || '').toLowerCase();
        item.style.display = (!q || name.includes(q)) ? 'flex' : 'none';
    });
});

document.getElementById('chatBody').addEventListener('scroll', function() {
    closeContextMenu();
    if (this.scrollTop < 120 && hasMoreBefore && !loadingOlder) {
        loadOlderMessages();
    }
});

function pollUnreadCounts() {
    fetch('api.php?action=unread_counts', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(r => r.json())
        .then(data => {
            if (data.status !== 'success') return;
            const counts = data.counts || {};
            document.querySelectorAll('.chat-item').forEach(item => {
                if (item.getAttribute('data-is-saved') === '1') return;
                const uid = item.getAttribute('data-user-id');
                if (!uid) return;
                if (currentChatUserId && String(currentChatUserId) === uid && !currentIsSaved) return;
                const ex = item.querySelector('.chat-item__badge');
                const c = counts[uid] || 0;
                if (c > 0) {
                    if (ex) ex.textContent = c;
                    else {
                        const b = document.createElement('div');
                        b.className = 'chat-item__badge';
                        b.setAttribute('data-user-id', uid);
                        b.textContent = c;
                        item.appendChild(b);
                    }
                } else if (ex) ex.remove();
            });
        })
        .catch(() => {});
}

function refreshSavedBadge() {
    if (!currentIsSaved) return;
    fetch('api.php?action=saved_info', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(r => r.json())
        .then(data => {
            if (data.status !== 'success') return;
            const item = document.querySelector('.chat-item.is-saved');
            if (!item) return;
            let badge = item.querySelector('.saved-count-badge');
            if (data.total_count > 0) {
                if (!badge) {
                    badge = document.createElement('span');
                    badge.className = 'saved-count-badge';
                    const n = item.querySelector('.chat-item__name');
                    if (n) n.appendChild(badge);
                }
                badge.textContent = data.total_count;
            } else if (badge) badge.remove();
            const lastEl = item.querySelector('.chat-item__last');
            if (lastEl && data.last_message) {
                let p = '';
                if (data.last_message.message) p = data.last_message.message.substring(0, 40);
                else if (data.last_message.attachment_name) p = '📎 ' + data.last_message.attachment_name.substring(0, 35);
                else p = 'فایل';
                lastEl.textContent = p;
            }
        })
        .catch(() => {});
}

/* ======================================================
   SEARCH
   ====================================================== */
function openSearchModal(defaultScope) {
    const overlay = document.getElementById('searchModalOverlay');
    overlay.classList.add('active');
    document.body.style.overflow = 'hidden';

    const convoBtn = document.getElementById('scopeConversationBtn');
    if (currentChatUserId && !currentIsSaved) {
        convoBtn.disabled = false;
        if (defaultScope === 'conversation') {
            setSearchScope('conversation');
        } else {
            setSearchScope('all');
        }
    } else {
        convoBtn.disabled = true;
        setSearchScope('all');
    }

    setTimeout(() => {
        const inp = document.getElementById('searchQueryInput');
        inp.focus();
        inp.select();
    }, 150);
}

function closeSearchModal() {
    const overlay = document.getElementById('searchModalOverlay');
    overlay.classList.remove('active');
    document.body.style.overflow = '';
    if (searchAbortController) { searchAbortController.abort(); searchAbortController = null; }
    clearTimeout(searchTimer);
}

function setSearchScope(scope) {
    searchScope = scope;
    document.querySelectorAll('.search-modal__scope-btn').forEach(btn => {
        btn.classList.toggle('active', btn.getAttribute('data-scope') === scope);
    });
    if (searchInputTouched) triggerSearch();
}

document.getElementById('searchQueryInput').addEventListener('input', function() {
    searchInputTouched = true;
    clearTimeout(searchTimer);
    const q = this.value.trim();
    if (q.length < 2) {
        document.getElementById('searchResultsBody').innerHTML = '<div class="search-modal__empty"><i class="fas fa-keyboard"></i>حداقل ۲ کاراکتر تایپ کنید تا جستجو شروع شود...</div>';
        return;
    }
    document.getElementById('searchResultsBody').innerHTML = '<div class="search-modal__empty"><i class="fas fa-spinner fa-spin"></i>در حال جستجو...</div>';
    searchTimer = setTimeout(triggerSearch, 400);
});

function triggerSearch() {
    const q = document.getElementById('searchQueryInput').value.trim();
    if (q.length < 2) return;

    if (searchAbortController) searchAbortController.abort();
    searchAbortController = new AbortController();

    let url = `api.php?action=search&q=${encodeURIComponent(q)}&scope=${searchScope}`;
    if (searchScope === 'conversation' && currentChatUserId) url += `&user_id=${currentChatUserId}`;

    fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' }, signal: searchAbortController.signal })
        .then(r => r.json())
        .then(data => {
            if (data.status !== 'success') {
                document.getElementById('searchResultsBody').innerHTML = `<div class="search-modal__empty"><i class="fas fa-exclamation-circle"></i>${escapeHtml(data.message || 'خطا در جستجو')}</div>`;
                return;
            }
            renderSearchResults(data.results || [], q);
        })
        .catch(err => {
            if (err.name === 'AbortError') return;
            document.getElementById('searchResultsBody').innerHTML = '<div class="search-modal__empty"><i class="fas fa-exclamation-circle"></i>خطا در ارتباط با سرور</div>';
        });
}

function renderSearchResults(results, query) {
    lastSearchResults = results;
    const body = document.getElementById('searchResultsBody');
    if (results.length === 0) {
        body.innerHTML = `<div class="search-modal__empty"><i class="fas fa-search-minus"></i>نتیجه‌ای برای «${escapeHtml(query)}» یافت نشد</div>`;
        return;
    }
    let html = `<div class="search-modal__info">${results.length} نتیجه یافت شد</div>`;
    const qLower = query.toLowerCase();

    results.forEach(r => {
        const createdMs = parseServerTime(r.created_at);
        const dateObj = createdMs ? new Date(createdMs) : new Date();
        const timeStr = formatTime(dateObj);
        const dayLabel = formatDayLabel(dateObj);

        let avatar = '?';
        if (r.from_saved) avatar = '<i class="fas fa-bookmark"></i>';
        else avatar = escapeHtml(r.from_name ? r.from_name.charAt(0) : '?');

        let preview = '';
        if (r.message && r.message.trim()) preview = r.message.replace(/\[\[task:[a-fA-F0-9]+\]\]/g, '').trim();
        else if (r.attachment_name) preview = '📎 ' + r.attachment_name;

        const highlighted = highlightQuery(escapeHtml(preview), qLower);

        let badges = '';
        if (r.is_mine) badges += '<span class="search-result__badge">شما</span>';
        if (r.attachment_name) badges += '<span class="search-result__badge file">📎 فایل</span>';

        html += `
            <div class="search-result" onclick="jumpToResult(${r.id}, ${r.other_user_id}, ${r.from_saved ? 1 : 0})">
                <div class="search-result__avatar ${r.from_saved ? 'is-saved-avatar' : ''}">${avatar}</div>
                <div class="search-result__info">
                    <div class="search-result__top">
                        <div class="search-result__name">${escapeHtml(r.from_saved ? 'پیام های ذخیره شده' : (r.from_name || 'کاربر'))} ${badges}</div>
                        <div class="search-result__time">${escapeHtml(dayLabel)} • ${timeStr}</div>
                    </div>
                    <div class="search-result__text">${highlighted || '(بدون متن)'}</div>
                </div>
            </div>`;
    });
    body.innerHTML = html;
}

function highlightQuery(escapedText, qLower) {
    if (!escapedText) return '';
    const q = qLower.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    if (!q) return escapedText;
    try {
        const re = new RegExp('(' + q + ')', 'gi');
        return escapedText.replace(re, '<mark>$1</mark>');
    } catch (e) { return escapedText; }
}

function jumpToResult(msgId, otherUserId, fromSaved) {
    closeSearchModal();

    pendingHighlightMsgId = msgId;

    if (currentChatUserId === otherUserId && currentIsSaved === !!fromSaved) {
        loadMessagesAround(msgId);
        pendingHighlightMsgId = null;
        return;
    }

    const item = document.querySelector(`.chat-item[data-user-id="${otherUserId}"]`);
    openChat(otherUserId, item);
}

/* ======================================================
   LIGHTBOX
   ====================================================== */
const lightboxEl = document.getElementById('imageLightbox');
const lightboxImg = document.getElementById('lightboxImage');
const lightboxCanvas = document.getElementById('lightboxCanvas');
const zoomLevelEl = document.getElementById('zoomLevel');

function openLightbox(src, name) {
    lightboxCurrentUrl = src;
    lightboxCurrentName = name || 'image';
    lightboxImg.src = src;
    lightboxImg.alt = name || '';
    lightboxZoom = 1; lightboxPanX = 0; lightboxPanY = 0;
    updateLightboxTransform();
    lightboxEl.classList.add('active');
    document.body.style.overflow = 'hidden';
    lightboxImg.onload = function() {
        const w = lightboxImg.naturalWidth, h = lightboxImg.naturalHeight;
        const vw = window.innerWidth * 0.9, vh = window.innerHeight * 0.8;
        if (w > vw || h > vh) {
            const r = Math.min(vw / w, vh / h);
            if (r < 1) { lightboxZoom = r; updateLightboxTransform(); }
        }
    };
}
function closeLightbox() {
    lightboxEl.classList.remove('active');
    document.body.style.overflow = '';
    lightboxImg.src = '';
    lightboxCurrentUrl = ''; lightboxCurrentName = '';
}
function updateLightboxTransform() {
    lightboxImg.style.transform = `translate(${lightboxPanX}px, ${lightboxPanY}px) scale(${lightboxZoom})`;
    zoomLevelEl.textContent = Math.round(lightboxZoom * 100) + '%';
}
function zoomIn() { if (lightboxZoom < ZOOM_MAX) { lightboxZoom = Math.min(ZOOM_MAX, lightboxZoom + ZOOM_STEP); updateLightboxTransform(); } }
function zoomOut() { if (lightboxZoom > ZOOM_MIN) { lightboxZoom = Math.max(ZOOM_MIN, lightboxZoom - ZOOM_STEP); if (lightboxZoom <= 1) { lightboxPanX = 0; lightboxPanY = 0; } updateLightboxTransform(); } }
function resetZoom() { lightboxZoom = 1; lightboxPanX = 0; lightboxPanY = 0; updateLightboxTransform(); }
function downloadLightboxImage() {
    if (!lightboxCurrentUrl) return;
    fetch(lightboxCurrentUrl).then(r => r.blob()).then(blob => {
        const u = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = u; a.download = lightboxCurrentName || 'image';
        document.body.appendChild(a); a.click(); document.body.removeChild(a);
        setTimeout(() => URL.revokeObjectURL(u), 1000);
    }).catch(() => window.open(lightboxCurrentUrl, '_blank'));
}

lightboxCanvas.addEventListener('mousedown', e => {
    if (lightboxZoom <= 1) return;
    isDragging = true; lightboxCanvas.classList.add('dragging');
    dragStartX = e.clientX - lightboxPanX;
    dragStartY = e.clientY - lightboxPanY;
    e.preventDefault();
});
document.addEventListener('mousemove', e => {
    if (!isDragging) return;
    lightboxPanX = e.clientX - dragStartX;
    lightboxPanY = e.clientY - dragStartY;
    updateLightboxTransform();
});
document.addEventListener('mouseup', () => {
    if (isDragging) { isDragging = false; lightboxCanvas.classList.remove('dragging'); }
});
lightboxEl.addEventListener('wheel', e => {
    if (!lightboxEl.classList.contains('active')) return;
    e.preventDefault();
    if (e.deltaY < 0) zoomIn(); else zoomOut();
}, { passive: false });

document.addEventListener('contextmenu', e => {
    const b = e.target.closest('.chat-bubble');
    if (b && b.hasAttribute('data-msg-id')) {
        if (b.classList.contains('task-notification')) {
            e.preventDefault();
            const txt = (b.querySelector('.task-notif-body') || {}).innerText || '';
            if (txt.trim()) {
                if (navigator.clipboard) navigator.clipboard.writeText(txt).then(() => showToast('متن کپی شد', 'success')).catch(() => {});
            }
            return;
        }
        openContextMenu(e, b);
    }
});
document.addEventListener('click', e => {
    const m = document.getElementById('msgContextMenu');
    if (!m || !m.classList.contains('active')) return;
    if (e.target.closest('#msgContextMenu')) return;
    closeContextMenu();
});
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        closeContextMenu();
        closeSidebar();
        if (lightboxEl.classList.contains('active')) closeLightbox();
        closeDeleteModal();
        closeEditModal();
        const sm = document.getElementById('searchModalOverlay');
        if (sm.classList.contains('active')) closeSearchModal();
    }
    if ((e.ctrlKey || e.metaKey) && (e.key === 'f' || e.key === 'k')) {
        e.preventDefault();
        openSearchModal(currentChatUserId && !currentIsSaved ? 'conversation' : 'all');
    }
    if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) {
        const em = document.getElementById('editModal');
        if (em && em.classList.contains('active')) { e.preventDefault(); confirmEditMessage(); }
    }
    if (lightboxEl.classList.contains('active')) {
        switch (e.key) {
            case '+': case '=': e.preventDefault(); zoomIn(); break;
            case '-': case '_': e.preventDefault(); zoomOut(); break;
            case '0': e.preventDefault(); resetZoom(); break;
            case 'd': case 'D': e.preventDefault(); downloadLightboxImage(); break;
        }
    }
});

/* ======================================================
   INIT
   ====================================================== */
sendHeartbeat();
setInterval(sendHeartbeat, 10000);
setInterval(pollUnreadCounts, 8000);
setInterval(fetchAllUsersStatus, 5000);
setTimeout(fetchAllUsersStatus, 500);

// ⭐ باز کردن خودکار گفتگو از طریق ?chat=USER_ID (کلیک روی نوتیف)
if (AUTO_OPEN_CHAT_ID > 0) {
    const autoItem = document.querySelector(`.chat-item[data-user-id="${AUTO_OPEN_CHAT_ID}"]`);
    if (autoItem) {
        setTimeout(() => openChat(AUTO_OPEN_CHAT_ID, autoItem), 350);
    }
    // پاک‌سازی پارامتر chat از URL برای جلوگیری از رفرش تکراری
    try {
        const cleanUrl = new URL(window.location.href);
        cleanUrl.searchParams.delete('chat');
        window.history.replaceState({}, '', cleanUrl.toString());
    } catch (e) {}
}

function escapeHtml(t) {
    if (t === null || t === undefined) return '';
    const m = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
    return String(t).replace(/[&<>"']/g, c => m[c]);
}

function toggleSidebar() {
    const s = document.getElementById('appSidebar');
    const o = document.getElementById('sidebarOverlay');
    const b = document.getElementById('hamburgerBtn');
    if (s.classList.contains('open')) closeSidebar();
    else {
        s.classList.add('open');
        o.classList.add('active');
        b.classList.add('active');
        document.body.style.overflow = 'hidden';
    }
}
function closeSidebar() {
    const s = document.getElementById('appSidebar');
    const o = document.getElementById('sidebarOverlay');
    const b = document.getElementById('hamburgerBtn');
    if (!s || !o || !b) return;
    s.classList.remove('open');
    o.classList.remove('active');
    b.classList.remove('active');
    document.body.style.overflow = '';
}
let resizeTimer;
window.addEventListener('resize', () => {
    clearTimeout(resizeTimer);
    resizeTimer = setTimeout(() => { if (window.innerWidth > 900) closeSidebar(); }, 150);
});
</script>
</body>
</html>