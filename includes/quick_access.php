<?php
if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest') {
    return;
}
/**
 * Quick Access FAB Widget
 * ─────────────────────────────────────────────────────────
 * فایل مستقل دسترسی سریع (FAB گوشه پایین چپ + کشو + مودال)
 *
 * استفاده در هر صفحه:
 *   require_once __DIR__ . '/../includes/quick_access.php';
 *
 * نکته مهم:
 *   این include هرجای صفحه (حتی بعد از <!DOCTYPE html>) قابل استفاده است،
 *   چون خروجی HTML/CSS/JS خود را در بافر ذخیره می‌کند و در انتهای صفحه
 *   (بعد از </body>، از طریق register_shutdown_function) چاپ می‌کند.
 *   به این ترتیب <head> صفحه سالم می‌ماند و فاویکون درست لود می‌شود.
 */

// ========== گارد در برابر include مجدد ==========
if (defined('QUICK_ACCESS_INCLUDED')) {
    return;
}
define('QUICK_ACCESS_INCLUDED', true);

// ========== بافر خروجی ویجت ==========
if (empty($GLOBALS['__QA_WIDGET_HTML'])) {
    $GLOBALS['__QA_WIDGET_HTML'] = '';
}

// ========== Auto-init وابستگی‌ها ==========
if (!isset($userId) || !$userId) {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        @session_start();
    }
    $userId = (int)($_SESSION['user_id'] ?? 0);
}

if (!isset($db) || !($db instanceof PDO)) {
    if (!class_exists('Database')) {
        $dbFile = __DIR__ . '/../database/Database.php';
        if (file_exists($dbFile)) {
            require_once $dbFile;
        }
    }
    if (class_exists('Database')) {
        try { $db = Database::getInstance(); } catch (Throwable $e) { $db = null; }
    }
}

if (!isset($csrfToken) || $csrfToken === '') {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        @session_start();
    }
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    $csrfToken = $_SESSION['csrf_token'];
}

// اگر وابستگی حیاتی موجود نیست، چیزی رندر نکن
if (!$db || !$userId) {
    return;
}

// ========== مسیر فایل JSON (قابل override) ==========
$qaJsonPath = isset($qaJsonPath) && is_string($qaJsonPath) && $qaJsonPath !== ''
    ? $qaJsonPath
    : __DIR__ . '/quick_accessibility.json';

// ========== تابع کمکی: بارگذاری لیست لینک‌های آماده ==========
if (!function_exists('qa_loadAvailableLinks')) {
    function qa_loadAvailableLinks(string $jsonPath): array {
        $links = [];
        if (!file_exists($jsonPath) || !is_readable($jsonPath)) {
            return $links;
        }
        try {
            $content = file_get_contents($jsonPath);
            $decoded = json_decode($content, true);
            if (!is_array($decoded) || !isset($decoded['links']) || !is_array($decoded['links'])) {
                return $links;
            }
            foreach ($decoded['links'] as $item) {
                if (!is_array($item)) continue;
                if (empty($item['id']) || empty($item['url'])) continue;

                $id = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)$item['id']);
                if ($id === '') continue;

                $url = trim((string)$item['url']);
                if ($url === '' || mb_strlen($url) > 500) continue;

                $isAbsolute = false;
                if (filter_var($url, FILTER_VALIDATE_URL)) {
                    $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
                    if (in_array($scheme, ['http', 'https'], true)) {
                        $isAbsolute = true;
                    }
                }
                if (!$isAbsolute) {
                    if (preg_match('/^[a-zA-Z][a-zA-Z0-9+.\-]*:/', $url)) continue;
                    if (preg_match('/[\x00-\x1F<>"\']/', $url)) continue;
                }

                $icon = preg_replace('/[^a-zA-Z0-9\-]/', '', (string)($item['icon'] ?? 'fa-link'));
                if ($icon === '') $icon = 'fa-link';

                $color = (string)($item['color'] ?? '');
                if (!preg_match('/^#[0-9a-fA-F]{3,8}$/', $color)) {
                    $color = '#2563eb';
                }

                $links[$id] = [
                    'id'    => $id,
                    'title' => mb_substr(trim((string)($item['title'] ?? $id)), 0, 60, 'UTF-8'),
                    'url'   => $url,
                    'icon'  => $icon,
                    'color' => $color,
                ];
            }
        } catch (Throwable $e) {
            error_log('quick_access.json parse error: ' . $e->getMessage());
        }
        return $links;
    }
}

// ============================================================
// هندلر AJAX ذخیره‌سازی — باید قبل از هر خروجی اجرا شود
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['quick_access_save'])) {
    while (ob_get_level() > 0) { @ob_end_clean(); }

    header('Content-Type: application/json; charset=utf-8');

    $received = $_POST['csrf_token'] ?? '';
    if (!is_string($received) || !hash_equals($csrfToken, $received)) {
        echo json_encode(['success' => false, 'message' => 'درخواست نامعتبر است.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $now = time();
    $rlKey = 'qa_save_rl';
    if (!isset($_SESSION[$rlKey]) || !is_array($_SESSION[$rlKey])) {
        $_SESSION[$rlKey] = [];
    }
    $_SESSION[$rlKey] = array_values(array_filter($_SESSION[$rlKey], fn($t) => ($now - $t) < 3600));
    if (count($_SESSION[$rlKey]) >= 60) {
        echo json_encode(['success' => false, 'message' => 'تعداد درخواست‌ها بیش از حد مجاز.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $_SESSION[$rlKey][] = $now;

    $valid = qa_loadAvailableLinks($qaJsonPath);

    $idsIn = $_POST['selected_ids'] ?? [];
    if (!is_array($idsIn)) $idsIn = [];

    $clean = [];
    $seen = [];
    foreach ($idsIn as $rawId) {
        if (is_array($rawId)) continue;
        $id = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)$rawId);
        if ($id === '' || isset($seen[$id])) continue;
        if (!isset($valid[$id])) continue;
        $seen[$id] = true;
        $clean[] = $id;
    }

    try {
        $stmt = $db->prepare("UPDATE users SET quick_links = ? WHERE id = ?");
        $stmt->execute([json_encode($clean, JSON_UNESCAPED_UNICODE), $userId]);

        echo json_encode([
            'success' => true,
            'message' => 'دسترسی‌های سریع ذخیره شد.',
            'ids'     => $clean,
        ], JSON_UNESCAPED_UNICODE);
    } catch (PDOException $e) {
        error_log('quick_access save error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'خطا در ذخیره‌سازی.'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// ============================================================
// بارگذاری داده برای رندر
// ============================================================
$qaAvailableLinks = qa_loadAvailableLinks($qaJsonPath);
$qaSelectedIds = [];

try {
    $stmt = $db->prepare("SELECT quick_links FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $raw = $stmt->fetchColumn();

    if (is_array($raw)) {
        $decoded = $raw;
    } elseif (is_string($raw) && $raw !== '') {
        $decoded = json_decode($raw, true);
    } else {
        $decoded = [];
    }

    if (is_array($decoded)) {
        foreach ($decoded as $item) {
            if (is_array($item)) {
                if (isset($item['id']) && !is_array($item['id'])) {
                    $rawId = $item['id'];
                } elseif (isset($item['url']) && is_string($item['url'])) {
                    $rawId = null;
                    foreach ($qaAvailableLinks as $avId => $avLink) {
                        if ($avLink['url'] === $item['url']) { $rawId = $avId; break; }
                    }
                    if ($rawId === null) continue;
                } else {
                    continue;
                }
            } else {
                $rawId = $item;
            }
            if (!is_string($rawId) && !is_int($rawId)) continue;
            $id = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)$rawId);
            if ($id === '' || !isset($qaAvailableLinks[$id])) continue;
            if (in_array($id, $qaSelectedIds, true)) continue;
            $qaSelectedIds[] = $id;
        }
    }
} catch (PDOException $e) {
    error_log('quick_access load error: ' . $e->getMessage());
}

$qaAvailableLinksJson = json_encode(array_values($qaAvailableLinks), JSON_UNESCAPED_UNICODE);
$qaSelectedIdsJson    = json_encode($qaSelectedIds, JSON_UNESCAPED_UNICODE);
$qaCsrfTokenJson      = json_encode($csrfToken);

// ============================================================
// ★ شروع بافر کردن خروجی ویجت
// ============================================================
ob_start();
?>

<!-- ═══════════════════════════════════════════════════════
     QUICK ACCESS FAB — استایل
     ═══════════════════════════════════════════════════════ -->
<style>
    /* ---------- Container (گوشه پایین چپ) ---------- */
    #qlFabContainer {
        position: fixed !important;
        top: auto !important;
        right: auto !important;
        bottom: 24px !important;
        left: 24px !important;
        margin: 0 !important;
        padding: 0 !important;
        transform: none !important;
        z-index: 999999 !important;
        display: flex !important;
        flex-direction: column !important;
        align-items: flex-start !important;
        gap: 12px !important;
        width: auto !important;
        height: auto !important;
        max-width: none !important;
        max-height: none !important;
        direction: ltr;
        pointer-events: none;
        font-family: Tahoma, sans-serif;

        /* ✨ بهینه‌سازی پرفورمنس */
        contain: layout style paint;
        isolation: isolate;

        /* 🔒 قفل انتخاب متن */
        -webkit-user-select: none !important;
        -moz-user-select: none !important;
        -ms-user-select: none !important;
        user-select: none !important;
        -webkit-touch-callout: none !important;
        -webkit-tap-highlight-color: transparent !important;
    }
    #qlFabContainer > * {
        pointer-events: auto;
        direction: rtl;
    }

    /* 🔒 قفل انتخاب متن روی همه عناصر داخلی */
    #qlFabContainer,
    #qlFabContainer * {
        -webkit-user-select: none !important;
        -moz-user-select: none !important;
        -ms-user-select: none !important;
        user-select: none !important;
        -webkit-touch-callout: none !important;
    }

    /* 🔒 ورودی جستجو در مودال باید قابل تایپ باشد */
    #qlFabContainer input,
    #qlFabContainer textarea {
        -webkit-user-select: text !important;
        -moz-user-select: text !important;
        -ms-user-select: text !important;
        user-select: text !important;
    }

    /* ---------- Drawer ---------- */
    #qlFabContainer .ql-drawer {
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 18px;
        box-shadow: 0 12px 40px rgba(15, 23, 42, 0.18), 0 4px 12px rgba(15, 23, 42, 0.08);
        padding: 14px;
        width: 320px;
        max-width: calc(100vw - 48px);
        max-height: 70vh;
        overflow-y: auto;
        transform: translateY(12px) scale(0.95);
        opacity: 0;
        visibility: hidden;
        pointer-events: none;
        transition: transform 0.28s cubic-bezier(0.34, 1.56, 0.64, 1),
                    opacity 0.22s ease,
                    visibility 0.22s;
        transform-origin: bottom left;

        -webkit-overflow-scrolling: touch;
        overscroll-behavior: contain;
        scrollbar-width: thin;
        scrollbar-color: #cbd5e1 transparent;
        contain: content;
    }
    #qlFabContainer .ql-drawer::-webkit-scrollbar { width: 6px; }
    #qlFabContainer .ql-drawer::-webkit-scrollbar-thumb {
        background: #cbd5e1;
        border-radius: 3px;
    }
    #qlFabContainer .ql-drawer.open {
        transform: translateY(0) scale(1);
        opacity: 1;
        visibility: visible;
        pointer-events: auto;
    }

    #qlFabContainer .ql-drawer-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 4px 6px 10px;
        border-bottom: 1px solid #f1f5f9;
        margin-bottom: 10px;
    }
    #qlFabContainer .ql-drawer-title {
        font-size: 0.85rem;
        font-weight: bold;
        color: #1e293b;
        display: flex;
        align-items: center;
        gap: 6px;
    }
    #qlFabContainer .ql-drawer-title i { color: #2563eb; }
    #qlFabContainer .ql-drawer-actions { display: flex; gap: 6px; align-items: center; }
    #qlFabContainer .ql-manage-btn {
        background: #eff6ff;
        color: #2563eb;
        border: 1px solid #dbeafe;
        padding: 5px 10px;
        border-radius: 8px;
        font-size: 0.72rem;
        font-weight: bold;
        font-family: Tahoma, sans-serif;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        gap: 4px;
        transition: all 0.15s ease;
    }
    #qlFabContainer .ql-manage-btn:hover {
        background: #2563eb;
        color: #fff;
        border-color: #2563eb;
    }

    /* ---------- Slot (لینک‌های انتخابی) ---------- */
    #qlFabContainer .ql-slot {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 10px 12px;
        background: #f8fafc;
        border: 2px solid #e2e8f0;
        border-radius: 12px;
        cursor: pointer;
        transition: all 0.2s ease;
        margin-bottom: 8px;
        text-decoration: none;
        color: inherit;
    }
    #qlFabContainer .ql-slot:last-child { margin-bottom: 0; }
    #qlFabContainer .ql-slot:hover {
        border-color: #2563eb;
        background: #eff6ff;
        transform: translateX(-2px);
    }
    #qlFabContainer .ql-slot__icon {
        width: 36px;
        height: 36px;
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        color: #fff;
        font-size: 0.95rem;
        flex-shrink: 0;
        box-shadow: 0 4px 10px rgba(37, 99, 235, 0.18);
        transition: transform 0.2s ease;
    }
    #qlFabContainer .ql-slot:hover .ql-slot__icon { transform: scale(1.06); }
    #qlFabContainer .ql-slot__body { flex: 1; min-width: 0; }
    #qlFabContainer .ql-slot__title {
        font-size: 0.82rem;
        font-weight: bold;
        color: #1e293b;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    #qlFabContainer .ql-slot__url {
        font-size: 0.68rem;
        color: #94a3b8;
        direction: ltr;
        text-align: right;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        margin-top: 2px;
    }
    #qlFabContainer .ql-slot__remove {
        width: 28px;
        height: 28px;
        border-radius: 8px;
        border: 1px solid #e2e8f0;
        background: #ffffff;
        color: #94a3b8;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 0.75rem;
        padding: 0;
        transition: all 0.15s ease;
        flex-shrink: 0;
    }
    #qlFabContainer .ql-slot__remove:hover {
        background: #fef2f2;
        color: #dc2626;
        border-color: #fecaca;
    }

    #qlFabContainer .ql-empty-state {
        padding: 24px 12px;
        text-align: center;
        color: #94a3b8;
        font-size: 0.78rem;
        line-height: 1.7;
    }
    #qlFabContainer .ql-empty-state i {
        font-size: 1.6rem;
        margin-bottom: 8px;
        opacity: 0.5;
        display: block;
    }

    /* ---------- FAB button ---------- */
    #qlFabContainer .ql-fab {
        width: 56px;
        height: 56px;
        border-radius: 50%;
        border: none;
        background: linear-gradient(135deg, #2563eb, #1d4ed8);
        color: #fff;
        font-size: 1.4rem;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        box-shadow: 0 8px 24px rgba(37, 99, 235, 0.4), 0 4px 8px rgba(37, 99, 235, 0.2);
        transition: transform 0.3s cubic-bezier(0.34, 1.56, 0.64, 1),
                    box-shadow 0.25s ease,
                    background 0.25s ease;
        position: relative;
        padding: 0;
        font-family: Tahoma, sans-serif;

        will-change: transform;
        backface-visibility: hidden;
        -webkit-backface-visibility: hidden;
        transform: translateZ(0);
    }
    #qlFabContainer .ql-fab:hover {
        transform: translateZ(0) scale(1.06);
        box-shadow: 0 12px 32px rgba(37, 99, 235, 0.5), 0 6px 12px rgba(37, 99, 235, 0.25);
    }
    #qlFabContainer .ql-fab:active { transform: translateZ(0) scale(0.96); }
    #qlFabContainer .ql-fab.open {
        background: linear-gradient(135deg, #dc2626, #b91c1c);
        box-shadow: 0 8px 24px rgba(220, 38, 38, 0.4), 0 4px 8px rgba(220, 38, 38, 0.2);
        transform: translateZ(0) rotate(135deg);
    }
    #qlFabContainer .ql-fab.open:hover {
        transform: translateZ(0) rotate(135deg) scale(1.06);
        box-shadow: 0 12px 32px rgba(220, 38, 38, 0.5), 0 6px 12px rgba(220, 38, 38, 0.25);
    }
    #qlFabContainer .ql-fab__icon {
        transition: transform 0.3s ease;
        line-height: 1;
        display: block;
        font-weight: 300;
    }

    /* ---------- Modal انتخاب ---------- */
    .ql-modal-overlay {
        position: fixed;
        inset: 0;
        background: rgba(15, 23, 42, 0.75);
        z-index: 1000000;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 16px;
        opacity: 0;
        visibility: hidden;
        pointer-events: none;
        transition: opacity 0.2s ease, visibility 0.2s;
        direction: rtl;
        font-family: Tahoma, sans-serif;
        contain: layout style paint;

        /* 🔒 قفل انتخاب متن روی مودال */
        -webkit-user-select: none !important;
        -moz-user-select: none !important;
        -ms-user-select: none !important;
        user-select: none !important;
        -webkit-touch-callout: none !important;
        -webkit-tap-highlight-color: transparent !important;
    }
    .ql-modal-overlay,
    .ql-modal-overlay * {
        -webkit-user-select: none !important;
        -moz-user-select: none !important;
        -ms-user-select: none !important;
        user-select: none !important;
        -webkit-touch-callout: none !important;
    }
    /* 🔒 ورودی‌های متنی داخل مودال قابل انتخاب بمونن */
    .ql-modal-overlay input[type="text"],
    .ql-modal-overlay input[type="search"],
    .ql-modal-overlay textarea {
        -webkit-user-select: text !important;
        -moz-user-select: text !important;
        -ms-user-select: text !important;
        user-select: text !important;
    }
    .ql-modal-overlay.open {
        opacity: 1;
        visibility: visible;
        pointer-events: auto;
    }
    .ql-modal-box {
        background: #ffffff;
        border-radius: 18px;
        padding: 22px;
        width: 100%;
        max-width: 520px;
        max-height: 85vh;
        display: flex;
        flex-direction: column;
        box-shadow: 0 24px 60px rgba(15, 23, 42, 0.3);
        transform: scale(0.94);
        transition: transform 0.25s cubic-bezier(0.34, 1.56, 0.64, 1);
        will-change: transform;
    }
    .ql-modal-overlay.open .ql-modal-box { transform: scale(1); }

    .ql-modal-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 16px;
        padding-bottom: 12px;
        border-bottom: 1px solid #f1f5f9;
        flex-shrink: 0;
    }
    .ql-modal-header h4 {
        margin: 0;
        font-size: 1rem;
        color: #1e293b;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .ql-modal-header h4 i { color: #2563eb; }
    .ql-modal-close {
        width: 30px;
        height: 30px;
        border-radius: 50%;
        border: none;
        background: #f1f5f9;
        color: #64748b;
        cursor: pointer;
        font-size: 1.1rem;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: all 0.15s ease;
        padding: 0;
    }
    .ql-modal-close:hover { background: #e2e8f0; color: #1e293b; }

    .ql-modal-search {
        position: relative;
        margin-bottom: 14px;
        flex-shrink: 0;
    }
    .ql-modal-search input {
        width: 100%;
        padding: 10px 14px 10px 38px;
        border: 2px solid #e2e8f0;
        border-radius: 10px;
        font-size: 0.85rem;
        font-family: Tahoma, sans-serif;
        background: #f8fafc;
        transition: all 0.2s ease;
        box-sizing: border-box;
    }
    .ql-modal-search input:focus {
        outline: none;
        border-color: #2563eb;
        background: #ffffff;
        box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.12);
    }
    .ql-modal-search i {
        position: absolute;
        left: 12px;
        top: 50%;
        transform: translateY(-50%);
        color: #94a3b8;
        font-size: 0.8rem;
        pointer-events: none;
    }

    .ql-options-list {
        display: flex;
        flex-direction: column;
        gap: 6px;
        overflow-y: auto;
        flex: 1;
        min-height: 0;
        padding-left: 4px;
        margin-bottom: 14px;

        -webkit-overflow-scrolling: touch;
        overscroll-behavior: contain;
        contain: content;
        scrollbar-width: thin;
        scrollbar-color: #cbd5e1 transparent;
    }
    .ql-options-list::-webkit-scrollbar { width: 6px; }
    .ql-options-list::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 3px; }

    .ql-option {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 10px 12px;
        background: #ffffff;
        border: 2px solid #e2e8f0;
        border-radius: 12px;
        cursor: pointer;
        transition: all 0.18s ease;
        user-select: none;
    }
    .ql-option:hover { border-color: #cbd5e1; background: #f8fafc; }
    .ql-option.selected {
        border-color: #2563eb;
        background: linear-gradient(135deg, rgba(37, 99, 235, 0.06), rgba(37, 99, 235, 0.02));
    }
    .ql-option.hidden { display: none !important; }
    .ql-option__icon {
        width: 38px;
        height: 38px;
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        color: #fff;
        font-size: 0.95rem;
        flex-shrink: 0;
        box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
    }
    .ql-option__body { flex: 1; min-width: 0; }
    .ql-option__title {
        font-size: 0.85rem;
        font-weight: bold;
        color: #1e293b;
        margin-bottom: 2px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .ql-option__url {
        font-size: 0.7rem;
        color: #94a3b8;
        direction: ltr;
        text-align: right;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .ql-option__check {
        width: 22px;
        height: 22px;
        border-radius: 6px;
        border: 2px solid #cbd5e1;
        background: #ffffff;
        color: #fff;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 0.65rem;
        flex-shrink: 0;
        transition: all 0.18s ease;
    }
    .ql-option.selected .ql-option__check {
        background: #2563eb;
        border-color: #2563eb;
    }

    .ql-modal-footer {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 10px;
        padding-top: 14px;
        border-top: 1px solid #f1f5f9;
        flex-shrink: 0;
    }
    .ql-modal-footer__info { font-size: 0.75rem; color: #64748b; }
    .ql-modal-footer__info strong { color: #2563eb; font-size: 0.85rem; }
    .ql-modal-actions { display: flex; gap: 8px; }
    .ql-modal-actions button {
        padding: 10px 18px;
        border-radius: 10px;
        font-family: Tahoma, sans-serif;
        font-weight: bold;
        font-size: 0.85rem;
        cursor: pointer;
        border: none;
        transition: all 0.2s ease;
    }
    .ql-btn-cancel { background: #f1f5f9; color: #475569; }
    .ql-btn-cancel:hover { background: #e2e8f0; color: #1e293b; }
    .ql-btn-save {
        background: linear-gradient(135deg, #2563eb, #1d4ed8);
        color: #fff;
        box-shadow: 0 4px 12px rgba(37, 99, 235, 0.25);
    }
    .ql-btn-save:hover { transform: translateY(-1px); box-shadow: 0 6px 16px rgba(37, 99, 235, 0.35); }
    .ql-btn-save:disabled { opacity: 0.5; cursor: not-allowed; transform: none; }

    /* ---------- Toast ---------- */
    .ql-toast {
        position: fixed;
        bottom: 100px;
        left: 24px;
        background: #1e293b;
        color: #fff;
        padding: 12px 18px;
        border-radius: 12px;
        font-size: 0.82rem;
        font-weight: bold;
        box-shadow: 0 8px 24px rgba(15, 23, 42, 0.25);
        z-index: 1000001;
        opacity: 0;
        transform: translateY(12px);
        transition: opacity 0.25s ease, transform 0.25s ease;
        pointer-events: none;
        max-width: 280px;
        display: flex;
        align-items: center;
        gap: 8px;
        direction: rtl;
        font-family: Tahoma, sans-serif;

        will-change: opacity, transform;
        contain: layout style paint;

        /* 🔒 قفل انتخاب متن روی toast */
        -webkit-user-select: none !important;
        -moz-user-select: none !important;
        -ms-user-select: none !important;
        user-select: none !important;
        -webkit-touch-callout: none !important;
    }
    .ql-toast.show { opacity: 1; transform: translateY(0); }
    .ql-toast.success { background: #059669; }
    .ql-toast.error { background: #dc2626; }
    .ql-toast i { font-size: 0.9rem; }

    /* ---------- Responsive ---------- */
    @media (max-width: 700px) {
        #qlFabContainer {
            bottom: 16px !important;
            left: 16px !important;
            right: auto !important;
            top: auto !important;
            gap: 10px !important;
        }
        #qlFabContainer .ql-fab { width: 50px; height: 50px; font-size: 1.25rem; }
        #qlFabContainer .ql-drawer { width: 290px; padding: 12px; }
        .ql-modal-box { padding: 18px; }
        .ql-toast { bottom: 80px; left: 16px; }
    }

    @media (prefers-reduced-motion: reduce) {
        #qlFabContainer .ql-fab,
        #qlFabContainer .ql-drawer,
        .ql-modal-overlay,
        .ql-modal-box,
        .ql-toast {
            transition: none !important;
        }
    }
</style>

<!-- ═══════════════════════════════════════════════════════
     QUICK ACCESS FAB — HTML
     ═══════════════════════════════════════════════════════ -->
<div id="qlFabContainer" class="ql-fab-container">
    <div class="ql-drawer" id="qlDrawer">
        <div class="ql-drawer-header">
            <div class="ql-drawer-title">
                <i class="fas fa-bolt"></i>
                دسترسی سریع
            </div>
            <div class="ql-drawer-actions">
                <button type="button" class="ql-manage-btn" onclick="qaOpenManager()">
                    <i class="fas fa-sliders-h"></i>
                    مدیریت
                </button>
            </div>
        </div>
        <div id="qlSlotsContainer"></div>
    </div>

    <button type="button" class="ql-fab" id="qlFab" onclick="qaToggleDrawer()" aria-label="دسترسی سریع">
        <span class="ql-fab__icon">+</span>
    </button>
</div>

<div class="ql-modal-overlay" id="qlModalOverlay">
    <div class="ql-modal-box">
        <div class="ql-modal-header">
            <h4><i class="fas fa-list-check"></i> انتخاب دسترسی‌های سریع</h4>
            <button type="button" class="ql-modal-close" onclick="qaCloseModal()">&times;</button>
        </div>

        <div class="ql-modal-search">
            <i class="fas fa-search"></i>
            <input type="text" id="qlSearchInput" placeholder="جستجو در لینک‌های موجود..." oninput="qaFilterOptions(this.value)">
        </div>

        <div class="ql-options-list" id="qlOptionsList"></div>

        <div class="ql-modal-footer">
            <div class="ql-modal-footer__info">
                انتخاب شده: <strong id="qlSelectedCount">0</strong> از <span id="qlTotalCount">0</span>
            </div>
            <div class="ql-modal-actions">
                <button type="button" class="ql-btn-cancel" onclick="qaCloseModal()">انصراف</button>
                <button type="button" class="ql-btn-save" id="qlSaveBtn" onclick="qaSaveSelection()">
                    <i class="fas fa-check" style="margin-left: 4px;"></i>
                    ذخیره
                </button>
            </div>
        </div>
    </div>
</div>

<div class="ql-toast" id="qlToast">
    <i class="fas fa-check-circle"></i>
    <span id="qlToastText"></span>
</div>

<!-- ═══════════════════════════════════════════════════════
     QUICK ACCESS FAB — JS
     ═══════════════════════════════════════════════════════ -->
<script>
(function() {
    'use strict';

    if (window.__qaInitialized) return;
    window.__qaInitialized = true;

    const QA_AVAILABLE_LINKS = <?= $qaAvailableLinksJson ?>;
    const QA_SELECTED_IDS_INIT = <?= $qaSelectedIdsJson ?>;
    const QA_CSRF_TOKEN = <?= $qaCsrfTokenJson ?>;

    let qaSelectedIds = new Set(Array.isArray(QA_SELECTED_IDS_INIT) ? QA_SELECTED_IDS_INIT : []);
    let qaToastTimer = null;

    function qaEscape(text) {
        if (text === null || text === undefined) return '';
        const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
        return String(text).replace(/[&<>"']/g, m => map[m]);
    }

    function qaGetEl(id) { return document.getElementById(id); }

    /* ============================================================
       🔒 قفل راست‌کلیک + انتخاب متن + کپی/برش + کشیدن
    ============================================================ */
    function qaLockSelectionAndContextMenu() {
        const targets = [
            document.getElementById('qlFabContainer'),
            document.getElementById('qlModalOverlay'),
            document.getElementById('qlToast')
        ].filter(Boolean);

        targets.forEach(function(el) {
            // ۱. راست‌کلیک
            el.addEventListener('contextmenu', function(e) {
                e.preventDefault();
                return false;
            });

            // ۲. شروع انتخاب متن
            el.addEventListener('selectstart', function(e) {
                // اگر روی input یا textarea بود، اجازه بده
                const tag = (e.target && e.target.tagName || '').toLowerCase();
                if (tag === 'input' || tag === 'textarea') return true;
                e.preventDefault();
                return false;
            });

            // ۳. drag
            el.addEventListener('dragstart', function(e) {
                e.preventDefault();
                return false;
            });

            // ۴. کپی
            el.addEventListener('copy', function(e) {
                const tag = (e.target && e.target.tagName || '').toLowerCase();
                if (tag === 'input' || tag === 'textarea') return true;
                e.preventDefault();
                return false;
            });

            // ۵. برش
            el.addEventListener('cut', function(e) {
                const tag = (e.target && e.target.tagName || '').toLowerCase();
                if (tag === 'input' || tag === 'textarea') return true;
                e.preventDefault();
                return false;
            });

            // ۶. دابل‌کلیک برای انتخاب کلمه (در بعضی مرورگرها)
            el.addEventListener('mousedown', function(e) {
                if (e.detail > 1) {
                    const tag = (e.target && e.target.tagName || '').toLowerCase();
                    if (tag === 'input' || tag === 'textarea') return true;
                    e.preventDefault();
                    return false;
                }
            });
        });

        // ۷. جلوگیری سراسری از راست‌کلیک روی modal که ممکنه بعداً به DOM اضافه شود
        document.addEventListener('contextmenu', function(e) {
            if (e.target && e.target.closest &&
                (e.target.closest('#qlFabContainer') ||
                 e.target.closest('.ql-modal-overlay') ||
                 e.target.closest('.ql-toast'))) {
                e.preventDefault();
                return false;
            }
        }, true);

        // ۸. جلوگیری سراسری از selectstart
        document.addEventListener('selectstart', function(e) {
            if (e.target && e.target.closest &&
                (e.target.closest('#qlFabContainer') ||
                 e.target.closest('.ql-modal-overlay') ||
                 e.target.closest('.ql-toast'))) {
                const tag = (e.target.tagName || '').toLowerCase();
                if (tag === 'input' || tag === 'textarea') return true;
                e.preventDefault();
                return false;
            }
        }, true);
    }

    // ---------- Render slots in drawer ----------
    function qaRenderSlots() {
        const container = qaGetEl('qlSlotsContainer');
        if (!container) return;
        container.innerHTML = '';

        const selected = QA_AVAILABLE_LINKS.filter(l => qaSelectedIds.has(l.id));

        if (selected.length === 0) {
            container.innerHTML = `
                <div class="ql-empty-state">
                    <i class="fas fa-link-slash"></i>
                    هنوز لینکی اضافه نکرده‌اید.
                    <br>
                    روی «مدیریت» بزنید و از لیست موجود انتخاب کنید.
                </div>
            `;
            return;
        }

        selected.forEach(link => {
            const slot = document.createElement('div');
            slot.className = 'ql-slot';
            slot.innerHTML = `
                <div class="ql-slot__icon" style="background:${qaEscape(link.color)};">
                    <i class="fas ${qaEscape(link.icon)}"></i>
                </div>
                <div class="ql-slot__body">
                    <div class="ql-slot__title" title="${qaEscape(link.title)}">${qaEscape(link.title)}</div>
                    <div class="ql-slot__url" title="${qaEscape(link.url)}">${qaEscape(link.url)}</div>
                </div>
                <button type="button" class="ql-slot__remove" title="حذف">
                    <i class="fas fa-times"></i>
                </button>
            `;

            slot.addEventListener('click', function(e) {
                if (e.target.closest('.ql-slot__remove')) return;
                qaOpenLink(link.url);
            });

            slot.querySelector('.ql-slot__remove').addEventListener('click', function(e) {
                e.stopPropagation();
                qaRemoveLink(link.id);
            });

            container.appendChild(slot);
        });
    }

    // ---------- Open link (in same page) ----------
    function qaOpenLink(url) {
        if (!url || typeof url !== 'string') return;
        const t = url.trim();
        if (t === '') return;

        if (/^[a-zA-Z][a-zA-Z0-9+.\-]*:/.test(t) && !/^https?:/i.test(t)) {
            console.warn('[QuickAccess] blocked unsafe URL:', t);
            return;
        }

        window.location.href = t;
    }

    // ---------- Remove link ----------
    function qaRemoveLink(id) {
        if (!qaSelectedIds.has(id)) return;
        qaSelectedIds.delete(id);
        qaRenderSlots();
        qaSaveToServer(() => qaToast('لینک حذف شد.', 'success'));
    }

    // ---------- Toggle drawer ----------
    function qaToggleDrawer() {
        const drawer = qaGetEl('qlDrawer');
        const fab = qaGetEl('qlFab');
        if (!drawer || !fab) return;
        const isOpen = drawer.classList.contains('open');
        if (isOpen) {
            drawer.classList.remove('open');
            fab.classList.remove('open');
        } else {
            drawer.classList.add('open');
            fab.classList.add('open');
            qaRenderSlots();
        }
    }

    function qaCloseDrawer() {
        const drawer = qaGetEl('qlDrawer');
        const fab = qaGetEl('qlFab');
        if (drawer) drawer.classList.remove('open');
        if (fab) fab.classList.remove('open');
    }

    // ---------- Open manager modal ----------
    function qaOpenManager() {
        qaCloseDrawer();
        qaRenderOptions();
        const search = qaGetEl('qlSearchInput');
        if (search) search.value = '';
        const overlay = qaGetEl('qlModalOverlay');
        if (overlay) overlay.classList.add('open');
        setTimeout(() => { if (search) search.focus(); }, 200);
    }

    function qaCloseModal() {
        const overlay = qaGetEl('qlModalOverlay');
        if (overlay) overlay.classList.remove('open');
    }

    // ---------- Render options in modal ----------
    function qaRenderOptions() {
        const list = qaGetEl('qlOptionsList');
        if (!list) return;
        list.innerHTML = '';

        if (QA_AVAILABLE_LINKS.length === 0) {
            list.innerHTML = `
                <div class="ql-empty-state">
                    <i class="fas fa-exclamation-triangle"></i>
                    فایل quick_accessibility.json خالی یا یافت نشد.
                </div>
            `;
            qaUpdateCount();
            return;
        }

        QA_AVAILABLE_LINKS.forEach(link => {
            const isSelected = qaSelectedIds.has(link.id);

            const option = document.createElement('label');
            option.className = 'ql-option' + (isSelected ? ' selected' : '');
            option.setAttribute('data-id', link.id);
            option.setAttribute('data-search', ((link.title || '') + ' ' + (link.url || '') + ' ' + (link.id || '')).toLowerCase());

            option.innerHTML = `
                <input type="checkbox" style="display:none;" ${isSelected ? 'checked' : ''}>
                <div class="ql-option__icon" style="background:${qaEscape(link.color)};">
                    <i class="fas ${qaEscape(link.icon)}"></i>
                </div>
                <div class="ql-option__body">
                    <div class="ql-option__title">${qaEscape(link.title)}</div>
                    <div class="ql-option__url">${qaEscape(link.url)}</div>
                </div>
                <span class="ql-option__check"><i class="fas fa-check"></i></span>
            `;

            option.addEventListener('click', function(e) {
                if (e.target.tagName === 'INPUT') return;
                e.preventDefault();
                const cb = this.querySelector('input[type="checkbox"]');
                cb.checked = !cb.checked;
                qaToggleOption(cb, link.id);
            });

            list.appendChild(option);
        });

        qaUpdateCount();
    }

    function qaToggleOption(checkbox, id) {
        const option = checkbox.closest('.ql-option');
        if (!option) return;

        if (checkbox.checked) {
            qaSelectedIds.add(id);
            option.classList.add('selected');
        } else {
            qaSelectedIds.delete(id);
            option.classList.remove('selected');
        }
        qaUpdateCount();
    }

    function qaUpdateCount() {
        const sel = qaGetEl('qlSelectedCount');
        const tot = qaGetEl('qlTotalCount');
        if (sel) sel.textContent = qaSelectedIds.size;
        if (tot) tot.textContent = QA_AVAILABLE_LINKS.length;
    }

    function qaFilterOptions(value) {
        const term = (value || '').trim().toLowerCase();
        document.querySelectorAll('#qlOptionsList .ql-option').forEach(option => {
            const haystack = option.getAttribute('data-search') || '';
            option.classList.toggle('hidden', term !== '' && !haystack.includes(term));
        });
    }

    // ---------- Save selection ----------
    function qaSaveSelection() {
        qaSaveToServer(() => {
            qaCloseModal();
            qaRenderSlots();
            qaToast('دسترسی‌های سریع ذخیره شد.', 'success');
        });
    }

    function qaSaveToServer(onSuccess) {
        const btn = qaGetEl('qlSaveBtn');
        const originalText = btn ? btn.innerHTML : 'ذخیره';

        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin" style="margin-left:4px;"></i> در حال ذخیره...';
        }

        const fd = new FormData();
        fd.append('quick_access_save', '1');
        fd.append('csrf_token', QA_CSRF_TOKEN);
        QA_AVAILABLE_LINKS.forEach(link => {
            if (qaSelectedIds.has(link.id)) {
                fd.append('selected_ids[]', link.id);
            }
        });

        fetch(window.location.pathname + window.location.search, {
            method: 'POST',
            body: fd,
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                if (onSuccess) onSuccess();
            } else {
                qaToast(data.message || 'خطا در ذخیره‌سازی.', 'error');
            }
        })
        .catch(() => qaToast('خطای شبکه.', 'error'))
        .finally(() => {
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = originalText;
            }
        });
    }

    // ---------- Toast ----------
    function qaToast(msg, type) {
        const toast = qaGetEl('qlToast');
        const text = qaGetEl('qlToastText');
        if (!toast || !text) return;
        const icon = toast.querySelector('i');

        toast.classList.remove('success', 'error');
        toast.classList.add(type || 'success');
        if (icon) icon.className = type === 'error' ? 'fas fa-exclamation-circle' : 'fas fa-check-circle';
        text.textContent = msg;

        toast.classList.add('show');
        clearTimeout(qaToastTimer);
        qaToastTimer = setTimeout(() => toast.classList.remove('show'), 2500);
    }

    // ---------- Global handlers ----------
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            qaCloseModal();
            const drawer = qaGetEl('qlDrawer');
            if (drawer && drawer.classList.contains('open')) qaCloseDrawer();
        }
    });

    document.addEventListener('click', function(e) {
        const container = qaGetEl('qlFabContainer');
        if (!container) return;
        if (container.contains(e.target)) return;
        const drawer = qaGetEl('qlDrawer');
        if (drawer && drawer.classList.contains('open')) qaCloseDrawer();
    });

    const overlay = qaGetEl('qlModalOverlay');
    if (overlay) {
        overlay.addEventListener('click', function(e) {
            if (e.target === this) qaCloseModal();
        });
    }

    // ---------- ✨ جلوگیری از انتقال اسکرول به صفحه ----------
    ['qlDrawer', 'qlOptionsList'].forEach(function(id) {
        const el = document.getElementById(id);
        if (!el) return;
        el.addEventListener('wheel', function(e) {
            const atTop    = el.scrollTop === 0;
            const atBottom = el.scrollTop + el.clientHeight >= el.scrollHeight - 1;
            if ((atTop && e.deltaY < 0) || (atBottom && e.deltaY > 0)) {
                e.preventDefault();
            }
        }, { passive: false });
    });

    // ---------- Expose API to window ----------
    window.qaToggleDrawer  = qaToggleDrawer;
    window.qaCloseDrawer   = qaCloseDrawer;
    window.qaOpenManager   = qaOpenManager;
    window.qaCloseModal    = qaCloseModal;
    window.qaSaveSelection = qaSaveSelection;
    window.qaFilterOptions = qaFilterOptions;

    // ---------- Initial render ----------
    qaRenderSlots();

    // 🔒 فعال‌سازی قفل انتخاب متن و راست‌کلیک
    qaLockSelectionAndContextMenu();
})();
</script>

<?php
// ============================================================
// ★ پایان بافر: HTML ویجت را ذخیره می‌کنیم و در انتهای صفحه چاپ می‌کنیم
// ============================================================
$GLOBALS['__QA_WIDGET_HTML'] = ob_get_clean();

register_shutdown_function(function () {
    if (!empty($GLOBALS['__QA_WIDGET_HTML'])) {
        echo $GLOBALS['__QA_WIDGET_HTML'];
        $GLOBALS['__QA_WIDGET_HTML'] = '';
    }
});