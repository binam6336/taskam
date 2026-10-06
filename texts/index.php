<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../database/Database.php';
require_once __DIR__ . '/../includes/impersonate_banner.php';
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
    $db->exec("SET CHARACTER SET utf8mb4");
    $db->exec("SET character_set_connection = utf8mb4");
} catch (PDOException $e) {}

$userId = (int)$_SESSION['user_id'];

$countStmt = $db->prepare("SELECT COUNT(*) FROM texts WHERE user_id = ?");
$countStmt->execute([$userId]);
$initialCount = (int)$countStmt->fetchColumn();

$displayUser = $_SESSION['user_mobile'] ?? $_SESSION['mobile'] ?? $_SESSION['username'] ?? 'کاربر';
$page = 'texts';
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, viewport-fit=cover">
    <meta name="theme-color" content="#2f6bdc">
    <title>مدیریت متن</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="icon" href="https://img.icons8.com/color/48/dashboard-layout.png" type="image/png">
<style>
    /* =========================================================
       ⚠️ SIDEBAR — استایل‌های رنگی دقیقا مطابق صفحه تسک
          + اسکرول مخفی + قفل ارتفاع + حفظ دکمه جمع/باز
       ========================================================= */

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

        --radius-lg: 20px;
        --radius-md: 14px;
        --radius-sm: 10px;

        --space-page: clamp(12px, 2vw, 25px);
        --space-card: clamp(14px, 2vw, 24px);
    }

    * { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
    html, body { max-width: 100%; overflow-x: hidden; }

    body {
        background: var(--bg);
        color: var(--text);
        font-family: Tahoma, "Segoe UI", sans-serif;
        margin: 0;
        display: flex;
        height: 100vh;
        height: 100dvh;
        overflow: hidden;
        font-size: clamp(13px, 1.4vw, 15px);
        line-height: 1.6;
        -webkit-font-smoothing: antialiased;
    }

    /* =========================================================
       ⭐ SIDEBAR — رنگ، اسکرول مخفی، قفل ارتفاع
       ========================================================= */
    .sidebar {
        background: linear-gradient(180deg, #4c8bf5 0%, #2f6bdc 100%) !important;
        color: #fff !important;
        box-shadow: -6px 0 28px rgba(76, 139, 245, 0.18);

        /* ✅ اسکرول مخفی بدون بُردن دکمه جمع/باز */
        overflow: visible !important;
        scrollbar-width: none !important;
        -ms-overflow-style: none !important;
    }
    .sidebar::-webkit-scrollbar {
        display: none !important;
        width: 0 !important;
        height: 0 !important;
    }
    .sidebar::-webkit-scrollbar-track,
    .sidebar::-webkit-scrollbar-thumb {
        display: none !important;
        background: transparent !important;
    }

    /* ✅ ارتفاع سایدبار قفل شود */
    body > .sidebar,
    body .sidebar {
        height: 100vh !important;
        height: 100dvh !important;
        max-height: 100vh !important;
        max-height: 100dvh !important;
        align-self: flex-start !important;
    }

    /* ✅ اگر داخل سایدبار wrapper اسکرول‌شونده وجود دارد، فقط آن اسکرول داشته باشد */
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

    /* رنگ‌های منو */
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
       OVERLAY — مطابق صفحه تسک
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
       MAIN
       ========================================================= */
    .main-wrapper {
        flex: 1;
        display: flex;
        flex-direction: column;
        overflow: hidden;
        min-width: 0;
    }

    .topbar {
        background: var(--card);
        padding: 15px clamp(16px, 2vw, 30px);
        border-bottom: 1px solid var(--border);
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 12px;
        flex-shrink: 0;
    }
    .topbar h1 {
        margin: 0;
        font-size: clamp(1rem, 1.4vw, 1.2rem);
        color: var(--primary);
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .topbar-user {
        font-size: 0.9rem;
        font-weight: bold;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        max-width: 220px;
    }

    .content-area {
        flex: 1;
        padding: var(--space-page);
        overflow-y: auto;
        -webkit-overflow-scrolling: touch;
    }

    /* =========================================================
       CARD
       ========================================================= */
    .card {
        background: var(--card);
        border: 1px solid var(--border);
        border-radius: var(--radius-lg);
        padding: var(--space-card);
        box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.04);
        margin-bottom: 20px;
        max-width: 1400px;
        margin-inline: auto;
        width: 100%;
    }

    /* =========================================================
       FORM
       ========================================================= */
    .form-group { margin-bottom: 18px; }
    label {
        display: block;
        margin-bottom: 8px;
        font-weight: 600;
        font-size: 0.9rem;
        color: #475569;
    }

    input[type="text"],
    input[type="email"],
    textarea {
        width: 100%;
        padding: 12px 18px;
        background: #f8fafc;
        border: 2px solid #e2e8f0;
        border-radius: var(--radius-md);
        font-size: 0.95rem;
        font-family: Tahoma, sans-serif;
        color: #1e293b;
        transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
    }
    input:focus, textarea:focus {
        outline: none;
        border-color: var(--accent);
        background: #ffffff;
        box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.12);
    }

    button.btn {
        background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
        color: #fff;
        border: none;
        padding: 11px 22px;
        border-radius: 12px;
        font-weight: bold;
        cursor: pointer;
        box-shadow: 0 4px 12px rgba(37, 99, 235, 0.25);
        transition: all 0.25s ease;
        font-family: Tahoma, sans-serif;
        font-size: 0.9rem;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
    }
    button.btn:hover {
        background: linear-gradient(135deg, #1d4ed8 0%, #1e40af 100%);
        transform: translateY(-2px);
        box-shadow: 0 6px 16px rgba(37, 99, 235, 0.35);
    }
    button.btn:active { transform: translateY(0); }

    /* =========================================================
       TABS
       ========================================================= */
    .nav-tabs {
        display: flex;
        gap: 10px;
        margin-bottom: 20px;
        padding-bottom: 12px;
        border-bottom: 2px solid #f1f5f9;
        overflow-x: auto;
        flex-wrap: nowrap;
        -webkit-overflow-scrolling: touch;
        scrollbar-width: none;
    }
    .nav-tabs::-webkit-scrollbar { display: none; }

    .tab-btn {
        background: #f1f5f9;
        border: none;
        padding: 9px 18px;
        font-weight: bold;
        font-size: 0.9rem;
        cursor: pointer;
        color: #64748b;
        border-radius: 12px;
        transition: all 0.2s ease;
        font-family: Tahoma, sans-serif;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        white-space: nowrap;
        flex-shrink: 0;
    }
    .tab-btn.active {
        background: var(--accent);
        color: #fff;
        box-shadow: 0 4px 12px rgba(37, 99, 235, 0.25);
    }
    .tab-content { display: none; }
    .tab-content.active { display: block; animation: fadeIn 0.25s ease; }
    @keyframes fadeIn { from { opacity: 0; transform: translateY(4px); } to { opacity: 1; transform: translateY(0); } }

    /* =========================================================
       SEARCH
       ========================================================= */
    .search-section { display: flex; gap: 12px; margin-bottom: 20px; }
    .search-section input { flex: 1; min-width: 0; }
    .search-clear {
        background: #f1f5f9;
        border: none;
        border-radius: 12px;
        padding: 0 20px;
        color: #64748b;
        cursor: pointer;
        font-size: 1rem;
        transition: 0.2s;
        flex-shrink: 0;
    }
    .search-clear:hover { background: #e2e8f0; color: #1e293b; }

    /* =========================================================
       ADD TOGGLE
       ========================================================= */
    .add-toggle-btn {
        width: 100%;
        background: #f8fafc;
        border: 2px dashed #cbd5e1;
        border-radius: 16px;
        padding: 15px;
        color: #64748b;
        font-size: 0.95rem;
        font-weight: 600;
        cursor: pointer;
        transition: 0.25s;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 10px;
        margin-bottom: 20px;
        font-family: Tahoma, sans-serif;
    }
    .add-toggle-btn:hover {
        background: #eff6ff;
        border-color: var(--accent);
        color: var(--accent);
        transform: translateY(-2px);
    }
    .add-toggle-btn i { font-size: 1.2rem; }

    .add-section {
        display: none;
        flex-direction: column;
        gap: 12px;
        padding: 20px;
        background: #f8fafc;
        border-radius: 16px;
        border: 1px solid #e2e8f0;
        margin-bottom: 20px;
    }
    .add-section.active { display: flex; animation: slideDown 0.3s ease; }
    @keyframes slideDown {
        from { opacity: 0; transform: translateY(-10px); }
        to { opacity: 1; transform: translateY(0); }
    }
    .add-section .add-actions { display: flex; gap: 10px; justify-content: flex-end; flex-wrap: wrap; }

    /* =========================================================
       TEXT CARDS
       ========================================================= */
    .text-cards {
        display: flex;
        flex-direction: column;
        gap: 12px;
        max-height: min(600px, 65vh);
        overflow-y: auto;
        padding: 4px;
        min-height: 200px;
    }
    .text-cards::-webkit-scrollbar { width: 6px; }
    .text-cards::-webkit-scrollbar-track { background: #f1f5f9; border-radius: 20px; }
    .text-cards::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 20px; }

    .text-card {
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 16px;
        padding: 16px 20px;
        transition: all 0.25s ease;
        box-shadow: 0 2px 4px rgba(0,0,0,0.01);
    }
    .text-card:hover {
        border-color: #cbd5e1;
        box-shadow: 0 6px 16px rgba(0, 0, 0, 0.04);
        transform: translateY(-2px);
    }
    .text-card.dragging { opacity: 0.4; transform: scale(0.98); }
    .text-card.drag-over {
        border-color: var(--accent);
        box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.15);
    }

    .text-card-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 8px;
        margin-bottom: 10px;
    }
    .text-card-title {
        font-weight: bold;
        font-size: 1rem;
        color: #1e293b;
        display: flex;
        align-items: center;
        gap: 8px;
        min-width: 0;
        word-break: break-word;
    }
    .text-card-title i { color: var(--accent); flex-shrink: 0; }
    .badge {
        background: #e0f2fe;
        color: #0284c7;
        padding: 4px 12px;
        border-radius: 20px;
        font-size: 0.72rem;
        font-weight: bold;
        flex-shrink: 0;
    }

    .text-card-body {
        color: #475569;
        font-size: 0.9rem;
        white-space: pre-wrap;
        word-break: break-word;
        border-top: 1px dashed #e2e8f0;
        padding-top: 10px;
        margin-bottom: 12px;
        line-height: 1.7;
    }

    .text-card-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        align-items: center;
    }
    .text-card-actions button {
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        color: #475569;
        padding: 6px 14px;
        border-radius: 10px;
        font-size: 0.8rem;
        font-weight: 600;
        cursor: pointer;
        transition: 0.2s;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        font-family: Tahoma, sans-serif;
    }
    .text-card-actions button:hover { transform: translateY(-1px); }
    .text-card-actions .edit-btn:hover { background: #dbeafe; color: #1e40af; border-color: #93c5fd; }
    .text-card-actions .delete-btn:hover { background: #fee2e2; color: #991b1b; border-color: #fecaca; }
    .text-card-actions .copy-btn:hover { background: #d1fae5; color: #065f46; border-color: #a7f3d0; }
    .text-card-actions .move-top-btn:hover { background: #fef3c7; color: #92400e; border-color: #fde68a; }

    .drag-handle {
        margin-right: auto;
        color: #94a3b8;
        cursor: grab;
        padding: 6px 12px;
        border-radius: 10px;
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        transition: 0.15s;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        font-size: 0.8rem;
        user-select: none;
        touch-action: none;
    }
    .drag-handle:hover { color: var(--accent); background: #eff6ff; border-color: #bfdbfe; }
    .drag-handle:active { cursor: grabbing; }

    /* =========================================================
       MODAL
       ========================================================= */
    .modal-overlay {
        position: fixed;
        inset: 0;
        background: rgba(15, 23, 42, 0.6);
        backdrop-filter: blur(5px);
        -webkit-backdrop-filter: blur(5px);
        display: none;
        align-items: center;
        justify-content: center;
        z-index: 1000;
        opacity: 0;
        transition: opacity 0.3s ease;
        padding: 15px;
    }
    .modal-overlay.active { display: flex; opacity: 1; }
    .modal-box {
        background: #fff;
        width: 100%;
        max-width: 550px;
        border-radius: 24px;
        padding: 30px;
        box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
        transform: translateY(20px);
        transition: transform 0.3s ease;
        max-height: 90vh;
        max-height: 90dvh;
        overflow-y: auto;
    }
    .modal-overlay.active .modal-box { transform: translateY(0); }
    .modal-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 20px;
        border-bottom: 1px solid #f1f5f9;
        padding-bottom: 14px;
    }
    .modal-header h3 {
        margin: 0;
        font-size: 1.15rem;
        color: var(--primary);
        display: flex;
        align-items: center;
        gap: 10px;
    }
    .modal-header h3 i { color: var(--accent); }
    .modal-close {
        background: #f1f5f9;
        border: none;
        width: 32px;
        height: 32px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.2rem;
        cursor: pointer;
        color: #64748b;
        font-weight: bold;
        transition: 0.2s;
        flex-shrink: 0;
    }
    .modal-close:hover { background: #e2e8f0; color: #1e293b; }

    /* =========================================================
       BACKUP PAGE
       ========================================================= */
    .backup-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 20px;
    }
    .backup-box {
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 20px;
        padding: 25px;
        text-align: center;
    }
    .backup-box h3 {
        margin: 0 0 10px;
        color: var(--primary);
        font-size: 1rem;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
    }
    .backup-box h3 i { color: var(--accent); }
    .backup-box p { color: #64748b; font-size: 0.85rem; margin: 0 0 18px; }

    .file-label {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        background: #fff;
        border: 2px dashed #cbd5e1;
        border-radius: 12px;
        padding: 14px 24px;
        color: #64748b;
        cursor: pointer;
        transition: 0.2s;
        font-weight: 600;
        font-size: 0.9rem;
        margin-bottom: 14px;
    }
    .file-label:hover { border-color: var(--accent); color: var(--accent); background: #eff6ff; }
    .file-input-wrapper input[type="file"] { display: none; }

    .restore-options {
        display: flex;
        gap: 16px;
        justify-content: center;
        margin-bottom: 14px;
        font-size: 0.85rem;
        color: #475569;
        flex-wrap: wrap;
    }
    .restore-options label { display: flex; align-items: center; gap: 6px; cursor: pointer; }
    .restore-options input[type="radio"] { accent-color: var(--accent); }

    .backup-info { color: #94a3b8; font-size: 0.8rem; margin-top: 10px; }

    /* =========================================================
       EMPTY / LOADING
       ========================================================= */
    .empty-state {
        text-align: center;
        color: #94a3b8;
        padding: 40px 20px;
        font-size: 0.95rem;
    }
    .empty-state i { font-size: 2.5rem; color: #cbd5e1; display: block; margin-bottom: 12px; }

    .loading-indicator { text-align: center; padding: 40px; color: #94a3b8; }
    .loading-indicator i { font-size: 1.8rem; margin-bottom: 10px; display: block; animation: spin 1s linear infinite; }
    @keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }

    /* =========================================================
       TOAST
       ========================================================= */
    .toast-message {
        position: fixed;
        bottom: 30px;
        left: 50%;
        transform: translateX(-50%) translateY(0);
        background: #1e293b;
        color: #f1f5f9;
        padding: 12px 24px;
        border-radius: 12px;
        box-shadow: 0 12px 40px rgba(0, 0, 0, 0.2);
        display: flex;
        align-items: center;
        gap: 12px;
        z-index: 1100;
        transition: 0.3s;
        font-size: 0.9rem;
        font-weight: 500;
        pointer-events: none;
        opacity: 1;
        max-width: calc(100vw - 30px);
        text-align: center;
    }
    .toast-message.hidden-toast { opacity: 0; transform: translateX(-50%) translateY(20px); }
    .toast-message i { font-size: 1.1rem; flex-shrink: 0; }
    .toast-message.success i { color: #6ee7b7; }
    .toast-message.error i { color: #fca5a5; }

    /* =========================================================
       HAMBURGER — مطابق صفحه تسک
       ========================================================= */
    .hamburger-btn {
        display: none;
        width: 44px;
        height: 44px;
        border: none;
        background: linear-gradient(135deg, #4c8bf5 0%, #2f6bdc 100%);
        border-radius: 13px;
        cursor: pointer;
        box-shadow: 0 6px 16px rgba(76, 139, 245, 0.25);
        transition: transform 0.15s ease, box-shadow 0.2s ease;
        flex-shrink: 0;
        align-items: center;
        justify-content: center;
        padding: 0;
        z-index: 1001;
    }
    .hamburger-btn:hover { transform: translateY(-1px); box-shadow: 0 8px 20px rgba(76, 139, 245, 0.35); }
    .hamburger-btn:active { transform: scale(0.95); }

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
        transition: transform 0.35s cubic-bezier(0.65, 0, 0.35, 1),
                    opacity 0.25s ease,
                    width 0.3s ease;
        transform-origin: center;
    }
    .hamburger-btn .hamburger-lines span:nth-child(2) { width: 70%; }
    .hamburger-btn .hamburger-lines span:nth-child(3) { width: 100%; }

    .hamburger-btn.active .hamburger-lines span:nth-child(1) { transform: translateY(6.75px) rotate(45deg); }
    .hamburger-btn.active .hamburger-lines span:nth-child(2) { opacity: 0; transform: translateX(-10px); }
    .hamburger-btn.active .hamburger-lines span:nth-child(3) { transform: translateY(-6.75px) rotate(-45deg); }

    /* =========================================================
       BREAKPOINT: 1440px+
       ========================================================= */
    @media (min-width: 1440px) {
        .content-area { padding: 30px 40px; }
        .text-cards { max-height: min(700px, 68vh); }
    }

    /* =========================================================
       BREAKPOINT: 1920px+
       ========================================================= */
    @media (min-width: 1920px) {
        .content-area { padding: 35px 60px; }
        .card { max-width: 1600px; }
        .text-cards { max-height: min(800px, 70vh); }
        body { font-size: 15px; }
    }

    /* =========================================================
       BREAKPOINT: 1100px
       ========================================================= */
    @media (max-width: 1100px) {
        .backup-grid { grid-template-columns: 1fr; }
    }

    /* =========================================================
       📱 BREAKPOINT: 900px — سایدبار off-canvas (مطابق صفحه تسک)
       ========================================================= */
    @media (max-width: 900px) {
        .hamburger-btn { display: flex; }

        body .sidebar {
            position: fixed !important;
            top: 0 !important;
            right: 0 !important;
            bottom: 0 !important;
            left: auto !important;

            width: 280px !important;
            min-width: 280px !important;
            max-width: 85vw !important;

            height: 100vh !important;
            height: 100dvh !important;
            max-height: 100vh !important;
            max-height: 100dvh !important;

            transform: translateX(105%) !important;
            align-self: auto !important;

            transition: transform 0.35s cubic-bezier(0.65, 0, 0.35, 1) !important;
            will-change: transform;

            z-index: 999 !important;
            box-shadow: -20px 0 50px rgba(0, 0, 0, 0.25) !important;

            padding-top: env(safe-area-inset-top) !important;
            padding-bottom: env(safe-area-inset-bottom) !important;
        }

        body .sidebar.open {
            transform: translateX(0) !important;
        }

        body .sidebar.is-collapsed {
            width: 280px !important;
            min-width: 280px !important;
        }

        .main-wrapper { width: 100%; }

        .topbar {
            padding: 12px 16px;
            padding-top: max(12px, env(safe-area-inset-top));
            gap: 12px;
            flex-wrap: nowrap;
        }
        .topbar h1 {
            font-size: 0.95rem;
            flex: 1;
            margin: 0;
            text-align: center;
        }
        .topbar-user {
            font-size: 0.78rem !important;
            max-width: 110px;
        }

        .content-area { padding: 14px; }
        .card { padding: 16px; border-radius: 16px; }
    }

    /* =========================================================
       BREAKPOINT: 700px
       ========================================================= */
    @media (max-width: 700px) {
        .content-area { padding: 12px; }
        .topbar { padding: 10px 14px; padding-top: max(10px, env(safe-area-inset-top)); }
        .topbar h1 { font-size: 0.88rem; }
        .topbar-user { display: none; }

        .hamburger-btn { width: 40px; height: 40px; border-radius: 11px; }
        .hamburger-btn .hamburger-lines { width: 20px; height: 14px; }
        .hamburger-btn .hamburger-lines span { height: 2.2px; }
        .hamburger-btn.active .hamburger-lines span:nth-child(1) { transform: translateY(5.9px) rotate(45deg); }
        .hamburger-btn.active .hamburger-lines span:nth-child(3) { transform: translateY(-5.9px) rotate(-45deg); }

        .card { padding: 14px; border-radius: 14px; }

        .nav-tabs {
            gap: 6px;
            padding-bottom: 10px;
            margin-bottom: 16px;
        }
        .tab-btn { padding: 8px 14px; font-size: 0.82rem; }

        .search-section { flex-direction: column; gap: 8px; }
        .search-section input { width: 100%; }
        .search-clear { padding: 12px; width: 100%; }

        .add-section { padding: 14px; }
        .add-section .add-actions { flex-direction: column-reverse; }
        .add-section .add-actions .btn { width: 100%; }

        .text-cards { max-height: none; }
        .text-card { padding: 14px 16px; }
        .text-card-title { font-size: 0.92rem; }
        .text-card-body { font-size: 0.85rem; }
        .text-card-actions button { padding: 6px 12px; font-size: 0.78rem; flex: 1 1 auto; min-width: 0; justify-content: center; }
        .drag-handle { padding: 6px 12px; font-size: 0.78rem; margin-right: 0; width: 100%; justify-content: center; }

        .modal-box { max-width: 96vw; padding: 20px; border-radius: 18px; max-height: 92dvh; }
        .modal-header h3 { font-size: 1rem; }

        .backup-box { padding: 18px; }
        .file-label { padding: 12px 20px; font-size: 0.85rem; width: 100%; justify-content: center; }
        .restore-options { flex-direction: column; gap: 10px; align-items: stretch; }
        .restore-options label { justify-content: flex-start; }

        .toast-message { bottom: 20px; padding: 10px 18px; font-size: 0.85rem; }
    }

    /* =========================================================
       BREAKPOINT: 480px
       ========================================================= */
    @media (max-width: 480px) {
        body { font-size: 13px; }

        .content-area { padding: 10px; }
        .card { padding: 12px; border-radius: 12px; }

        .tab-btn { padding: 7px 12px; font-size: 0.78rem; }
        .tab-btn i { font-size: 0.85rem; }

        input[type="text"], textarea { padding: 10px 14px; font-size: 0.9rem; }
        button.btn { padding: 10px 18px; font-size: 0.85rem; }

        .text-card { padding: 12px 14px; }
        .text-card-actions { gap: 6px; }
        .text-card-actions button { padding: 5px 10px; font-size: 0.72rem; }
        .drag-handle { padding: 5px 10px; font-size: 0.72rem; }
        .badge { font-size: 0.65rem; padding: 3px 9px; }
    }

    /* =========================================================
       BREAKPOINT: 360px
       ========================================================= */
    @media (max-width: 360px) {
        .card { padding: 10px; }
        .text-card-actions button { padding: 4px 8px; font-size: 0.7rem; gap: 3px; }
        .text-card-actions button i { font-size: 0.75rem; }
    }

    /* =========================================================
       LANDSCAPE
       ========================================================= */
    @media (max-height: 500px) and (orientation: landscape) {
        .topbar { padding: 8px 14px; }
        .content-area { padding: 10px; }
        .text-cards { max-height: 55vh; }
    }

    /* =========================================================
       REDUCED MOTION
       ========================================================= */
    @media (prefers-reduced-motion: reduce) {
        .hamburger-btn,
        .hamburger-btn .hamburger-lines span,
        .modal-box,
        .tab-content.active,
        body .sidebar,
        .sidebar-overlay {
            transition: none !important;
            animation: none !important;
        }
    }

    /* =========================================================
       PRINT
       ========================================================= */
    @media print {
        .topbar, .hamburger-btn, .sidebar-overlay,
        .text-card-actions, .add-toggle-btn, .nav-tabs { display: none !important; }
        body { overflow: visible; height: auto; }
        .content-area { overflow: visible; padding: 0; }
        .text-cards { max-height: none; overflow: visible; }
        .card { box-shadow: none; border: 1px solid #ccc; }
    }
</style>
</head>
<body>

<?php sidebar(); ?>
<!-- ⚠️ overlay داخل sidebar.php رندر می‌شود؛ اینجا تکرار نکن -->

<div class="main-wrapper">
    <div class="topbar">
        <button class="hamburger-btn" id="hamburgerBtn" onclick="toggleSidebar()" aria-label="باز کردن منو" type="button">
            <div class="hamburger-lines">
                <span></span>
                <span></span>
                <span></span>
            </div>
        </button>
        <h1>مدیریت متن</h1>
        <div class="topbar-user">کاربر: <?= htmlspecialchars($displayUser) ?></div>
    </div>

    <div class="content-area">
        <div class="card">
            <!-- تب‌ها -->
            <div class="nav-tabs">
                <button class="tab-btn active" data-page="main" type="button">
                    <i class="fas fa-pen-fancy"></i> مدیریت متن
                </button>
                <button class="tab-btn" data-page="backup" type="button">
                    <i class="fas fa-archive"></i> بکاپ و بازیابی
                </button>
            </div>

            <!-- تب اصلی -->
            <div id="page-main" class="tab-content active">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 10px;">
                    <div style="color: #64748b; font-size: 0.9rem;">
                        <i class="fas fa-database"></i>
                        <span id="textCount"><?= $initialCount ?></span> متن ثبت شده
                    </div>
                </div>

                <div class="search-section">
                    <input type="text" id="searchInput" placeholder="جستجو در عنوان و متن...">
                    <button class="search-clear" id="searchClear" title="پاک کردن" type="button">
                        <i class="fas fa-times"></i>
                    </button>
                </div>

                <button class="add-toggle-btn" id="toggleAddBtn" type="button">
                    <i class="fas fa-plus-circle"></i>
                    افزودن متن جدید
                </button>

                <div class="add-section" id="addSection">
                    <input type="text" id="titleInput" placeholder="عنوان متن...">
                    <textarea id="contentInput" rows="4" placeholder="متن اصلی..."></textarea>
                    <div class="add-actions">
                        <button class="btn" id="addBtn" type="button">
                            <i class="fas fa-save"></i> ذخیره
                        </button>
                        <button class="btn" style="background:#f1f5f9; color:#475569; box-shadow:none;" id="cancelAddBtn" type="button">
                            <i class="fas fa-times"></i> انصراف
                        </button>
                    </div>
                </div>

                <div id="textCardsContainer" class="text-cards">
                    <div class="loading-indicator">
                        <i class="fas fa-spinner"></i>
                        در حال بارگذاری...
                    </div>
                </div>
            </div>

            <!-- تب بکاپ -->
            <div id="page-backup" class="tab-content">
                <div class="backup-grid">
                    <div class="backup-box">
                        <h3><i class="fas fa-download"></i> گرفتن بکاپ</h3>
                        <p>همه متن‌ها را به صورت فایل JSON دانلود کن</p>
                        <button id="backupBtn" class="btn" type="button">
                            <i class="fas fa-file-export"></i> دانلود بکاپ
                        </button>
                        <div class="backup-info">
                            <i class="fas fa-info-circle"></i>
                            شامل <span id="backupCount"><?= $initialCount ?></span> متن
                        </div>
                    </div>

                    <div class="backup-box">
                        <h3><i class="fas fa-upload"></i> بازیابی بکاپ</h3>
                        <p>فایل بکاپ را انتخاب کن تا متن‌ها بازیابی شوند</p>

                        <div class="file-input-wrapper">
                            <label class="file-label" for="fileInput">
                                <i class="fas fa-folder-open"></i> انتخاب فایل
                            </label>
                            <input type="file" id="fileInput" accept=".json">

                            <div class="restore-options">
                                <label><input type="radio" name="restoreMode" value="replace" checked> جایگزینی</label>
                                <label><input type="radio" name="restoreMode" value="merge"> اضافه کردن</label>
                            </div>

                            <button id="restoreBtn" class="btn" style="background:#f1f5f9; color:#475569; box-shadow:none;" type="button">
                                <i class="fas fa-undo-alt"></i> بازیابی
                            </button>
                        </div>
                        <div id="restoreStatus" style="margin-top: 12px; color: #64748b; font-size: 0.85rem;"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal ویرایش -->
<div class="modal-overlay" id="editModal">
    <div class="modal-box">
        <div class="modal-header">
            <h3><i class="fas fa-edit"></i> ویرایش متن</h3>
            <button class="modal-close" id="editCancel" type="button">&times;</button>
        </div>
        <div class="form-group">
            <label>عنوان</label>
            <input type="text" id="editTitle" placeholder="عنوان متن...">
        </div>
        <div class="form-group">
            <label>متن</label>
            <textarea id="editContent" rows="6" placeholder="متن را وارد کنید..."></textarea>
        </div>
        <div style="display: flex; gap: 10px; justify-content: flex-end; margin-top: 10px; flex-wrap: wrap;">
            <button class="btn" id="editCancelBtn" style="background:#f1f5f9; color:#475569; box-shadow:none;" type="button">
                <i class="fas fa-times"></i> انصراف
            </button>
            <button class="btn" id="editSave" type="button">
                <i class="fas fa-save"></i> ذخیره
            </button>
        </div>
    </div>
</div>

<!-- Toast -->
<div id="toast" class="toast-message hidden-toast success">
    <i class="fas fa-check-circle"></i>
    <span id="toastText">پیام</span>
</div>

<script>
/* ============================================================
   توابع باز/بسته کردن سایدبار در موبایل
   (سایدبار خودش در HTML خودش onclick="closeSidebar()"
   روی overlay قرار داده، پس اینجا فقط تعریف می‌کنیم)
   ============================================================ */
function toggleSidebar() {
    const sidebar = document.getElementById('appSidebar');
    const overlay = document.getElementById('sidebarOverlay');
    const btn = document.getElementById('hamburgerBtn');
    if (!sidebar) return;

    const isOpen = sidebar.classList.contains('open');

    if (isOpen) {
        closeSidebar();
    } else {
        sidebar.classList.add('open');
        if (overlay) overlay.classList.add('active');
        if (btn) btn.classList.add('active');
        document.body.style.overflow = 'hidden';
    }
}

function closeSidebar() {
    const sidebar = document.getElementById('appSidebar');
    const overlay = document.getElementById('sidebarOverlay');
    const btn = document.getElementById('hamburgerBtn');

    if (sidebar) sidebar.classList.remove('open');
    if (overlay) overlay.classList.remove('active');
    if (btn) btn.classList.remove('active');
    document.body.style.overflow = '';
}

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeSidebar();
});

let __sbResizeTimer;
window.addEventListener('resize', function() {
    clearTimeout(__sbResizeTimer);
    __sbResizeTimer = setTimeout(function() {
        if (window.innerWidth > 768) {
            closeSidebar();
        }
    }, 150);
});

/* ============================================================
   منطق صفحهٔ متون
   ============================================================ */
(function() {
    'use strict';

    const API_URL = 'api.php';
    const REORDER_URL = 'reorder.php';

    let texts = [];
    let editId = null;
    let searchTerm = '';
    let searchTimer = null;

    // ========== Toast ==========
    let toastTimer = null;
    function showToast(message, isSuccess = true) {
        const toast = document.getElementById('toast');
        const toastText = document.getElementById('toastText');
        const icon = toast.querySelector('i');
        toastText.textContent = message;
        toast.classList.remove('hidden-toast');
        toast.classList.toggle('success', isSuccess);
        toast.classList.toggle('error', !isSuccess);
        icon.className = isSuccess ? 'fas fa-check-circle' : 'fas fa-exclamation-triangle';
        clearTimeout(toastTimer);
        toastTimer = setTimeout(() => toast.classList.add('hidden-toast'), 2500);
    }

    // ========== API ==========
    async function callAPI(method, data = null) {
        try {
            const options = {
                method: method,
                headers: { 'Content-Type': 'application/json' }
            };
            if (data) options.body = JSON.stringify(data);

            let url = API_URL;
            if (method === 'GET' && searchTerm) {
                url += '?search=' + encodeURIComponent(searchTerm);
            }

            const response = await fetch(url, options);
            const result = await response.json();

            if (!response.ok) throw new Error(result.error || 'خطا در ارتباط با سرور');
            return result;
        } catch (error) {
            showToast('خطا: ' + error.message, false);
            return null;
        }
    }

    // ========== بارگذاری ==========
    async function loadTexts() {
        const container = document.getElementById('textCardsContainer');
        container.innerHTML = `
            <div class="loading-indicator">
                <i class="fas fa-spinner"></i>
                در حال بارگذاری...
            </div>
        `;

        const result = await callAPI('GET');
        if (result && result.success) {
            texts = result.data || [];
            updateCounts();
            renderCards();
        } else {
            container.innerHTML = `
                <div class="empty-state">
                    <i class="fas fa-exclamation-triangle"></i>
                    خطا در بارگذاری داده‌ها
                </div>
            `;
        }
    }

    // ========== CRUD ==========
    async function addText(title, content) {
        if (!title.trim() || !content.trim()) {
            showToast('عنوان و متن نباید خالی باشند', false);
            return false;
        }
        const result = await callAPI('POST', { title: title.trim(), content: content.trim() });
        if (result && result.success) {
            texts.unshift(result.data);
            updateCounts();
            renderCards();
            hideAddForm();
            showToast('متن با موفقیت افزوده شد');
            return true;
        }
        return false;
    }

    async function deleteText(id) {
        const result = await callAPI('DELETE', { id: id });
        if (result && result.success) {
            texts = texts.filter(t => t.id !== id);
            updateCounts();
            renderCards();
            showToast('متن حذف شد');
        }
    }

    async function updateText(id, title, content) {
        const result = await callAPI('PUT', { id, title: title.trim(), content: content.trim() });
        if (result && result.success) {
            const index = texts.findIndex(t => t.id === id);
            if (index !== -1) texts[index] = result.data;
            updateCounts();
            renderCards();
            showToast('متن ویرایش شد');
            return true;
        }
        return false;
    }

    function copyText(id) {
        const item = texts.find(t => t.id === id);
        if (!item) { showToast('متن یافت نشد', false); return; }
        const text = item.content || '';
        if (navigator.clipboard?.writeText) {
            navigator.clipboard.writeText(text).then(() => showToast('متن کپی شد')).catch(() => fallbackCopy(text));
        } else {
            fallbackCopy(text);
        }
    }

    function fallbackCopy(text) {
        try {
            const ta = document.createElement('textarea');
            ta.value = text;
            ta.style.position = 'fixed';
            ta.style.opacity = '0';
            document.body.appendChild(ta);
            ta.select();
            document.execCommand('copy');
            document.body.removeChild(ta);
            showToast('متن کپی شد');
        } catch (e) {
            showToast('کپی ناموفق', false);
        }
    }

    async function moveToTop(id) {
        const index = texts.findIndex(t => t.id === id);
        if (index === -1 || index === 0) {
            showToast('این متن در بالاترین جایگاه است', false);
            return;
        }
        const [item] = texts.splice(index, 1);
        texts.unshift(item);

        await saveOrder();

        updateCounts();
        renderCards();
        showToast('متن به بالا منتقل شد');
    }

    async function saveOrder() {
        const order = texts.map(t => t.id);
        try {
            await fetch(REORDER_URL, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ order })
            });
        } catch (e) {}
    }

    function updateCounts() {
        const tc = document.getElementById('textCount');
        const bc = document.getElementById('backupCount');
        if (tc) tc.textContent = texts.length;
        if (bc) bc.textContent = texts.length;
    }

    // ========== فرم افزودن ==========
    function showAddForm() {
        document.getElementById('addSection').classList.add('active');
        document.getElementById('toggleAddBtn').style.display = 'none';
        setTimeout(() => {
            const el = document.getElementById('titleInput');
            el.focus();
            if (window.innerWidth <= 768) {
                el.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        }, 100);
    }

    function hideAddForm() {
        document.getElementById('addSection').classList.remove('active');
        document.getElementById('toggleAddBtn').style.display = 'flex';
        document.getElementById('titleInput').value = '';
        document.getElementById('contentInput').value = '';
    }

    // ========== رندر ==========
    function renderCards() {
        const container = document.getElementById('textCardsContainer');
        if (!container) return;

        if (texts.length === 0) {
            container.innerHTML = `
                <div class="empty-state">
                    <i class="fas fa-inbox"></i>
                    هیچ متنی وجود ندارد
                </div>
            `;
            return;
        }

        let html = '';
        for (let i = 0; i < texts.length; i++) {
            const item = texts[i];
            if (!item) continue;
            const safeTitle = (item.title || 'بدون عنوان').replace(/</g, '&lt;').replace(/>/g, '&gt;');
            const safeContent = (item.content || '').replace(/</g, '&lt;').replace(/>/g, '&gt;');
            const isFirst = (i === 0);

            html += `
                <div class="text-card" data-id="${item.id}">
                    <div class="text-card-header">
                        <span class="text-card-title"><i class="fas fa-tag"></i> ${safeTitle}</span>
                        <span class="badge">${item.id.slice(0, 8)}</span>
                    </div>
                    <div class="text-card-body">${safeContent}</div>
                    <div class="text-card-actions">
                        <button class="edit-btn" data-id="${item.id}" type="button"><i class="fas fa-edit"></i> ویرایش</button>
                        <button class="delete-btn" data-id="${item.id}" type="button"><i class="fas fa-trash-alt"></i> حذف</button>
                        <button class="copy-btn" data-id="${item.id}" type="button"><i class="fas fa-copy"></i> کپی</button>
                        ${!isFirst ? `<button class="move-top-btn" data-id="${item.id}" title="انتقال به بالا" type="button"><i class="fas fa-arrow-up"></i></button>` : ''}
                        <span class="drag-handle" draggable="true" data-id="${item.id}" title="جابه‌جایی">
                            <i class="fas fa-grip-vertical"></i> جابه‌جایی
                        </span>
                    </div>
                </div>
            `;
        }

        container.innerHTML = html;
        initDragDrop();
    }

    // ========== Drag & Drop ==========
    let dragData = null;
    let dragTimeout = null;

    function initDragDrop() {
        const handles = document.querySelectorAll('.drag-handle');
        handles.forEach(handle => {
            handle.removeEventListener('dragstart', handleDragStart);
            handle.removeEventListener('dragend', handleDragEnd);
            handle.addEventListener('dragstart', handleDragStart);
            handle.addEventListener('dragend', handleDragEnd);
        });

        const cards = document.querySelectorAll('.text-card');
        cards.forEach(card => {
            card.removeEventListener('dragover', handleDragOver);
            card.removeEventListener('dragenter', handleDragEnter);
            card.removeEventListener('dragleave', handleDragLeave);
            card.removeEventListener('drop', handleDrop);
            card.addEventListener('dragover', handleDragOver);
            card.addEventListener('dragenter', handleDragEnter);
            card.addEventListener('dragleave', handleDragLeave);
            card.addEventListener('drop', handleDrop);
        });
    }

    function handleDragStart(e) {
        const handle = e.target.closest('.drag-handle');
        if (!handle) { e.preventDefault(); return; }
        const id = handle.dataset.id;
        if (!id) { e.preventDefault(); return; }
        const card = handle.closest('.text-card');
        if (card) card.classList.add('dragging');

        dragData = { id, index: texts.findIndex(t => t.id === id) };
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/plain', id);
        e.dataTransfer.dropEffect = 'move';
    }

    function handleDragEnd(e) {
        const handle = e.target.closest('.drag-handle');
        if (handle) {
            const card = handle.closest('.text-card');
            if (card) card.classList.remove('dragging');
        }
        document.querySelectorAll('.text-card').forEach(c => c.classList.remove('drag-over'));
        if (dragTimeout) clearTimeout(dragTimeout);
        dragTimeout = setTimeout(() => { dragData = null; }, 50);
    }

    function handleDragOver(e) {
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';
        const card = e.target.closest('.text-card');
        if (card && !card.classList.contains('dragging')) card.classList.add('drag-over');
    }

    function handleDragEnter(e) {
        e.preventDefault();
        const card = e.target.closest('.text-card');
        if (card && !card.classList.contains('dragging')) card.classList.add('drag-over');
    }

    function handleDragLeave(e) {
        const card = e.target.closest('.text-card');
        if (card) card.classList.remove('drag-over');
    }

    async function handleDrop(e) {
        e.preventDefault();
        const targetCard = e.target.closest('.text-card');
        if (!targetCard) return;
        targetCard.classList.remove('drag-over');

        const draggedId = e.dataTransfer.getData('text/plain');
        if (!draggedId) return;
        const targetId = targetCard.dataset.id;
        if (draggedId === targetId) return;
        if (!dragData) return;

        const draggedIndex = dragData.index;
        const targetIndex = texts.findIndex(t => t.id === targetId);
        if (draggedIndex === -1 || targetIndex === -1) return;

        const [draggedItem] = texts.splice(draggedIndex, 1);
        texts.splice(targetIndex, 0, draggedItem);

        await saveOrder();

        updateCounts();
        renderCards();
        showToast('ترتیب تغییر کرد');
    }

    // ========== ویرایش ==========
    function openEditModal(id) {
        const item = texts.find(t => t.id === id);
        if (!item) { showToast('متن یافت نشد', false); return; }
        editId = id;
        document.getElementById('editTitle').value = item.title || '';
        document.getElementById('editContent').value = item.content || '';
        document.getElementById('editModal').classList.add('active');
        document.body.style.overflow = 'hidden';
        setTimeout(() => document.getElementById('editContent').focus(), 100);
    }

    function closeEditModal() {
        document.getElementById('editModal').classList.remove('active');
        document.body.style.overflow = '';
        editId = null;
    }

    async function saveEditModal() {
        if (!editId) return;
        const title = document.getElementById('editTitle').value;
        const content = document.getElementById('editContent').value;
        if (!title.trim() || !content.trim()) {
            showToast('عنوان و متن نمی‌توانند خالی باشند', false);
            return;
        }
        await updateText(editId, title, content);
        closeEditModal();
    }

    // ========== بکاپ ==========
    function downloadBackup() {
        if (texts.length === 0) {
            showToast('هیچ متنی برای بکاپ وجود ندارد', false);
            return;
        }
        const backupData = {
            version: '1.0',
            exportedAt: new Date().toISOString(),
            total: texts.length,
            texts: texts
        };
        const blob = new Blob([JSON.stringify(backupData, null, 2)], { type: 'application/json' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        const date = new Date().toISOString().slice(0, 10);
        a.download = `backup_texts_${date}.json`;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
        showToast(`بکاپ با ${texts.length} متن دانلود شد`);
    }

    function restoreBackup(file) {
        const reader = new FileReader();
        reader.onload = async function(e) {
            try {
                const data = JSON.parse(e.target.result);
                let backupTexts = [];
                if (Array.isArray(data)) backupTexts = data;
                else if (data.texts && Array.isArray(data.texts)) backupTexts = data.texts;
                else { showToast('فرمت فایل معتبر نیست', false); return; }

                if (backupTexts.length === 0) { showToast('فایل بکاپ خالی است', false); return; }

                const isValid = backupTexts.every(item => item.title !== undefined && item.content !== undefined);
                if (!isValid) { showToast('ساختار بکاپ صحیح نیست', false); return; }

                const mode = document.querySelector('input[name="restoreMode"]:checked').value;
                let restoredCount = 0;

                if (mode === 'replace') {
                    for (const item of texts) await callAPI('DELETE', { id: item.id });
                    texts = [];
                    for (const item of backupTexts) {
                        const r = await callAPI('POST', { title: item.title, content: item.content });
                        if (r && r.success) restoredCount++;
                    }
                } else {
                    for (const item of backupTexts) {
                        const r = await callAPI('POST', { title: item.title, content: item.content });
                        if (r && r.success) restoredCount++;
                    }
                }

                await loadTexts();
                showToast(`${restoredCount} متن بازیابی شد`);
                document.getElementById('restoreStatus').textContent = `✅ ${restoredCount} متن بازیابی شد`;
            } catch (error) {
                showToast('خطا در خواندن فایل: ' + error.message, false);
                document.getElementById('restoreStatus').textContent = '❌ خطا';
            }
        };
        reader.onerror = function() { showToast('خطا در خواندن فایل', false); };
        reader.readAsText(file);
    }

    // ========== رویدادها ==========
    document.querySelectorAll('.nav-tabs .tab-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            document.querySelectorAll('.nav-tabs .tab-btn').forEach(b => b.classList.remove('active'));
            this.classList.add('active');
            const page = this.dataset.page;
            document.querySelectorAll('.tab-content').forEach(p => p.classList.remove('active'));
            document.getElementById(`page-${page}`).classList.add('active');
        });
    });

    document.getElementById('toggleAddBtn').addEventListener('click', showAddForm);
    document.getElementById('cancelAddBtn').addEventListener('click', hideAddForm);

    document.getElementById('addBtn').addEventListener('click', async function() {
        const title = document.getElementById('titleInput').value;
        const content = document.getElementById('contentInput').value;
        await addText(title, content);
    });

    document.getElementById('titleInput').addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            document.getElementById('contentInput').focus();
        }
    });

    document.getElementById('searchInput').addEventListener('input', function() {
        searchTerm = this.value;
        clearTimeout(searchTimer);
        searchTimer = setTimeout(() => loadTexts(), 350);
    });

    document.getElementById('searchClear').addEventListener('click', function() {
        document.getElementById('searchInput').value = '';
        searchTerm = '';
        loadTexts();
        document.getElementById('searchInput').focus();
    });

    document.getElementById('textCardsContainer').addEventListener('click', function(e) {
        const target = e.target.closest('button');
        if (!target) return;
        const id = target.dataset.id;
        if (!id) return;

        if (target.classList.contains('edit-btn')) openEditModal(id);
        else if (target.classList.contains('delete-btn')) {
            if (confirm('آیا از حذف این متن اطمینان دارید؟')) deleteText(id);
        }
        else if (target.classList.contains('copy-btn')) copyText(id);
        else if (target.classList.contains('move-top-btn')) moveToTop(id);
    });

    document.getElementById('editCancel').addEventListener('click', closeEditModal);
    document.getElementById('editCancelBtn').addEventListener('click', closeEditModal);
    document.getElementById('editSave').addEventListener('click', saveEditModal);
    document.getElementById('editModal').addEventListener('click', function(e) {
        if (e.target === this) closeEditModal();
    });
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') closeEditModal();
        if (e.key === 'Enter' && document.getElementById('editModal').classList.contains('active')) {
            if (e.target === document.getElementById('editContent') || e.target === document.getElementById('editTitle')) {
                e.preventDefault();
                saveEditModal();
            }
        }
    });

    document.getElementById('backupBtn').addEventListener('click', downloadBackup);
    document.getElementById('fileInput').addEventListener('change', function() {
        const file = this.files[0];
        if (file) {
            document.getElementById('restoreStatus').textContent = `📁 ${file.name} (${(file.size / 1024).toFixed(1)} KB)`;
        }
    });
    document.getElementById('restoreBtn').addEventListener('click', function() {
        const fileInput = document.getElementById('fileInput');
        const file = fileInput.files[0];
        if (!file) { showToast('لطفاً یک فایل بکاپ انتخاب کنید', false); return; }
        restoreBackup(file);
    });

    loadTexts();
})();
</script>
</body>
</html>