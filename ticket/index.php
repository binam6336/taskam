<?php
// ================================================================
// ⚡ CRITICAL: Start output buffering IMMEDIATELY
// ================================================================
ob_start();

// ================================================================
// Load core (no output)
// ================================================================
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../database/Database.php';
require_once __DIR__ . '/../includes/chat_notifications.php';
// ================================================================
// AJAX detection
// ================================================================
$isAjax = (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest');

// ================================================================
// Auth check
// ================================================================
if (!Auth::check()) {
    if ($isAjax) {
        if (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => 'error', 'message' => 'نشست منقضی شده است.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    header('Location: ../auth/login.php');
    exit;
}

$db = Database::getInstance();
$userId = (int)$_SESSION['user_id'];

// ================================================================
// ⭐ تنظیمات صفحه‌بندی تیکت‌ها
// برای تغییر تعداد تیکت‌ها در هر صفحه، فقط این عدد را ویرایش کنید
// ================================================================
$TICKETS_PER_PAGE = 10;

// ================================================================
// Charset
// ================================================================
try {
    $db->exec("SET NAMES 'utf8mb4'");
    $db->exec("SET CHARACTER SET utf8mb4");
    $db->exec("SET character_set_connection = utf8mb4");
} catch (PDOException $e) {}

// ================================================================
// Helper functions
// ================================================================
function priorityLabel($priority) {
    $map = ['low' => 'کم', 'medium' => 'متوسط', 'high' => 'زیاد', 'very_high' => 'خیلی زیاد'];
    return $map[$priority] ?? $priority;
}
function priorityColor($priority) {
    $map = ['low' => '#2ebc8a', 'medium' => '#ff8a3d', 'high' => '#f97316', 'very_high' => '#f54e7a'];
    return $map[$priority] ?? '#8a94ad';
}
function buildUrl($tab, $sort) {
    return "?tab=$tab&sort=$sort";
}

function getTicketOwner(PDO $db, int $ticketId): ?int {
    $stmt = $db->prepare("SELECT user_id FROM tickets WHERE id = ? LIMIT 1");
    $stmt->execute([$ticketId]);
    $owner = $stmt->fetchColumn();
    return $owner === false ? null : (int)$owner;
}

function getCommentOwnership(PDO $db, int $commentId): ?array {
    $stmt = $db->prepare("
        SELECT tc.id AS comment_id, tc.ticket_id, t.user_id AS owner_id
        FROM ticket_comments tc
        INNER JOIN tickets t ON t.id = tc.ticket_id
        WHERE tc.id = ?
        LIMIT 1
    ");
    $stmt->execute([$commentId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;
    return [
        'comment_id' => (int)$row['comment_id'],
        'ticket_id'  => (int)$row['ticket_id'],
        'owner_id'   => (int)$row['owner_id'],
    ];
}

function jsonExit($data) {
    if (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// ================================================================
// ⭐ صفحه‌بندی تیکت‌ها - تابع کمکی
// ================================================================
function renderTicketsPagination(int $currentPage, int $totalPages, int $totalCount, string $paramKey, string $activeTab, string $sort): string {
    if ($totalPages <= 1) return '';

    $buildLink = function(int $page) use ($paramKey, $activeTab, $sort) {
        return '?tab=' . urlencode($activeTab) . '&sort=' . urlencode($sort) . '&' . $paramKey . '=' . $page;
    };

    $html = '<div class="tickets-pagination-inner">';

    // دکمه قبلی (سمت راست در RTL)
    if ($currentPage > 1) {
        $html .= '<a class="pag-btn" href="' . htmlspecialchars($buildLink($currentPage - 1), ENT_QUOTES, 'UTF-8') . '" title="صفحه قبلی"><i class="fas fa-chevron-right"></i></a>';
    } else {
        $html .= '<span class="pag-btn disabled"><i class="fas fa-chevron-right"></i></span>';
    }

    // شماره صفحات
    $pages = [1];
    for ($i = $currentPage - 1; $i <= $currentPage + 1; $i++) {
        if ($i > 1 && $i < $totalPages) $pages[] = $i;
    }
    if ($totalPages > 1) $pages[] = $totalPages;
    $pages = array_values(array_unique($pages));
    sort($pages);

    $prev = 0;
    foreach ($pages as $p) {
        if ($p > $prev + 1) {
            $html .= '<span class="pag-dots">…</span>';
        }
        if ($p === $currentPage) {
            $html .= '<span class="pag-btn active">' . $p . '</span>';
        } else {
            $html .= '<a class="pag-btn" href="' . htmlspecialchars($buildLink($p), ENT_QUOTES, 'UTF-8') . '">' . $p . '</a>';
        }
        $prev = $p;
    }

    // دکمه بعدی (سمت چپ در RTL)
    if ($currentPage < $totalPages) {
        $html .= '<a class="pag-btn" href="' . htmlspecialchars($buildLink($currentPage + 1), ENT_QUOTES, 'UTF-8') . '" title="صفحه بعدی"><i class="fas fa-chevron-left"></i></a>';
    } else {
        $html .= '<span class="pag-btn disabled"><i class="fas fa-chevron-left"></i></span>';
    }

    $html .= '<span class="pag-info">صفحه ' . $currentPage . ' از ' . $totalPages . ' (کل: ' . $totalCount . ')</span>';
    $html .= '</div>';
    return $html;
}

// ================================================================
// Backup JSON — فقط تیکت‌های خود کاربر
// ================================================================
if (isset($_GET['action']) && $_GET['action'] === 'backup') {
    try {
        $stmt = $db->prepare("SELECT * FROM tickets WHERE user_id = ? ORDER BY id ASC");
        $stmt->execute([$userId]);
        $tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $allComments = [];
        foreach ($tickets as $ticket) {
            $stmt2 = $db->prepare("SELECT * FROM ticket_comments WHERE ticket_id = ? ORDER BY created_at ASC");
            $stmt2->execute([$ticket['id']]);
            $allComments[$ticket['id']] = $stmt2->fetchAll(PDO::FETCH_ASSOC);
        }

        $backupData = [
            'exported_at' => date('Y-m-d H:i:s'),
            'user_id' => $userId,
            'total_tickets' => count($tickets),
            'tickets' => $tickets,
            'comments' => $allComments
        ];
        $json = json_encode($backupData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $filename = 'tickets_backup_user' . $userId . '_' . date('Y-m-d_H-i-s') . '.json';

        if (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($json));
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        echo $json;
        exit;
    } catch (PDOException $e) {
        die("خطا در گرفتن بکاپ: " . $e->getMessage());
    }
}

// ================================================================
// POST Actions — همه کاربرمحور
// ================================================================
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    // -------- افزودن تیکت --------
    if ($action === 'add_ticket') {
        $ticket_number = trim($_POST['ticket_number'] ?? '');
        $subject = trim($_POST['subject'] ?? '');
        $task_link = trim($_POST['task_link'] ?? '');
        $priority = $_POST['priority'] ?? 'medium';

        if (!in_array($priority, ['low','medium','high','very_high'], true)) {
            $priority = 'medium';
        }

        if (empty($ticket_number)) {
            $message = 'شماره تیکت الزامی است!';
            $message_type = 'error';
        } else {
            try {
                $stmt = $db->prepare("INSERT INTO tickets (user_id, ticket_number, subject, task_link, priority) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$userId, $ticket_number, $subject, $task_link, $priority]);
                $ticket_id = (int)$db->lastInsertId();

                $initial_comment = trim($_POST['initial_comment'] ?? '');
                if (!empty($initial_comment)) {
                    $stmt2 = $db->prepare("INSERT INTO ticket_comments (ticket_id, comment) VALUES (?, ?)");
                    $stmt2->execute([$ticket_id, $initial_comment]);
                }

                if (ob_get_level()) ob_end_clean();
                header('Location: ?tab=pending&msg=added');
                exit;
            } catch (PDOException $e) {
                $message = 'خطا در ثبت تیکت: ' . $e->getMessage();
                $message_type = 'error';
            }
        }
    }

    // -------- تغییر اولویت --------
    if ($action === 'update_priority') {
        $id = (int)($_POST['id'] ?? 0);
        $priority = $_POST['priority'] ?? 'medium';

        if (!in_array($priority, ['low','medium','high','very_high'], true)) {
            jsonExit(['status' => 'error', 'message' => 'سطح اهمیت نامعتبر است.']);
        }

        $owner = getTicketOwner($db, $id);
        if ($owner === null || $owner !== $userId) {
            jsonExit(['status' => 'error', 'message' => 'تیکت مورد نظر یافت نشد.']);
        }

        try {
            $stmt = $db->prepare("UPDATE tickets SET priority = ? WHERE id = ? AND user_id = ?");
            $stmt->execute([$priority, $id, $userId]);
            jsonExit([
                'status' => 'success',
                'ticket_id' => $id,
                'priority' => $priority,
                'label' => priorityLabel($priority),
                'color' => priorityColor($priority),
            ]);
        } catch (PDOException $e) {
            jsonExit(['status' => 'error', 'message' => $e->getMessage()]);
        }
    }

    // -------- تغییر عنوان --------
    if ($action === 'update_subject') {
        $id = (int)($_POST['id'] ?? 0);
        $subject = trim($_POST['subject'] ?? '');

        $owner = getTicketOwner($db, $id);
        if ($owner === null || $owner !== $userId) {
            jsonExit(['status' => 'error', 'message' => 'تیکت مورد نظر یافت نشد.']);
        }

        try {
            $stmt = $db->prepare("UPDATE tickets SET subject = ? WHERE id = ? AND user_id = ?");
            $stmt->execute([$subject, $id, $userId]);
            jsonExit(['status' => 'success']);
        } catch (PDOException $e) {
            jsonExit(['status' => 'error', 'message' => $e->getMessage()]);
        }
    }

    // -------- تغییر لینک تسک --------
    if ($action === 'update_task_link') {
        $id = (int)($_POST['id'] ?? 0);
        $task_link = trim($_POST['task_link'] ?? '');

        $owner = getTicketOwner($db, $id);
        if ($owner === null || $owner !== $userId) {
            jsonExit(['status' => 'error', 'message' => 'تیکت مورد نظر یافت نشد.']);
        }

        try {
            $stmt = $db->prepare("UPDATE tickets SET task_link = ? WHERE id = ? AND user_id = ?");
            $stmt->execute([$task_link, $id, $userId]);
            jsonExit([
                'status' => 'success',
                'ticket_id' => $id,
                'task_link' => $task_link,
                'has_link' => !empty($task_link)
            ]);
        } catch (PDOException $e) {
            jsonExit(['status' => 'error', 'message' => $e->getMessage()]);
        }
    }

    // -------- افزودن کامنت --------
    if ($action === 'add_comment') {
        $ticket_id = (int)($_POST['ticket_id'] ?? 0);
        $comment = trim((string)($_POST['comment'] ?? ''));

        if ($ticket_id <= 0) {
            jsonExit(['status' => 'error', 'message' => 'شناسه تیکت نامعتبر است.']);
        }
        if ($comment === '') {
            jsonExit(['status' => 'error', 'message' => 'متن کامنت نمی‌تواند خالی باشد!']);
        }

        $owner = getTicketOwner($db, $ticket_id);
        if ($owner === null || $owner !== $userId) {
            jsonExit(['status' => 'error', 'message' => 'تیکت مورد نظر یافت نشد.']);
        }

        try {
            $stmt = $db->prepare("INSERT INTO ticket_comments (ticket_id, comment) VALUES (?, ?)");
            $stmt->execute([$ticket_id, $comment]);
            $newId = (int)$db->lastInsertId();

            $countStmt = $db->prepare("SELECT COUNT(*) FROM ticket_comments WHERE ticket_id = ?");
            $countStmt->execute([$ticket_id]);
            $count = (int)$countStmt->fetchColumn();

            jsonExit([
                'status' => 'success',
                'comment_id' => $newId,
                'ticket_id' => $ticket_id,
                'comment' => $comment,
                'time' => date('H:i'),
                'count' => $count
            ]);
        } catch (PDOException $e) {
            jsonExit(['status' => 'error', 'message' => 'خطای دیتابیس: ' . $e->getMessage()]);
        }
    }

    // -------- حذف کامنت --------
    if ($action === 'delete_comment') {
        $comment_id = (int)($_POST['comment_id'] ?? 0);

        $ownership = getCommentOwnership($db, $comment_id);
        if ($ownership === null || $ownership['owner_id'] !== $userId) {
            jsonExit(['status' => 'error', 'message' => 'کامنت مورد نظر یافت نشد.']);
        }

        try {
            $stmt = $db->prepare("DELETE FROM ticket_comments WHERE id = ?");
            $stmt->execute([$comment_id]);
            jsonExit(['status' => 'success', 'comment_id' => $comment_id]);
        } catch (PDOException $e) {
            jsonExit(['status' => 'error', 'message' => $e->getMessage()]);
        }
    }

    // -------- تغییر وضعیت --------
    if ($action === 'toggle_status') {
        $id = (int)($_POST['id'] ?? 0);
        $status = isset($_POST['status']) ? (int)$_POST['status'] : 0;

        $owner = getTicketOwner($db, $id);
        if ($owner === null || $owner !== $userId) {
            jsonExit(['status' => 'error', 'message' => 'تیکت مورد نظر یافت نشد.']);
        }

        try {
            $stmt = $db->prepare("UPDATE tickets SET status = ? WHERE id = ? AND user_id = ?");
            $stmt->execute([$status, $id, $userId]);
            jsonExit([
                'status' => 'success',
                'ticket_id' => $id,
                'new_status' => $status
            ]);
        } catch (PDOException $e) {
            jsonExit(['status' => 'error', 'message' => $e->getMessage()]);
        }
    }
}

// ================================================================
// ⚡ Now safe to load output-producing includes
// ================================================================
require_once __DIR__ . '/../includes/impersonate_banner.php';
require_once __DIR__ . '/../includes/sidebar.php';
require_once __DIR__ . '/../includes/quick_access.php';

// ================================================================
// GET params & queries
// ================================================================
$page = 'tickets';

if (isset($_GET['msg'])) {
    if ($_GET['msg'] === 'added') {
        $message = 'تیکت با موفقیت ثبت شد.';
        $message_type = 'success';
    }
}

$active_tab = $_GET['tab'] ?? 'pending';
if (!in_array($active_tab, ['pending', 'done', 'new'])) {
    $active_tab = 'pending';
}

$sort = $_GET['sort'] ?? 'created_at_desc';
$allowed_sorts = [
    'created_at_desc' => 'created_at DESC',
    'created_at_asc' => 'created_at ASC',
    'priority_desc' => "FIELD(priority, 'very_high', 'high', 'medium', 'low')",
    'priority_asc' => "FIELD(priority, 'low', 'medium', 'high', 'very_high')",
    'ticket_number_asc' => 'ticket_number ASC',
    'ticket_number_desc' => 'ticket_number DESC'
];
if (!array_key_exists($sort, $allowed_sorts)) {
    $sort = 'created_at_desc';
}
$order_by = $allowed_sorts[$sort];

$allTickets = [];
if ($active_tab !== 'new') {
    $stmt = $db->prepare("SELECT * FROM tickets WHERE user_id = ? ORDER BY $order_by");
    $stmt->execute([$userId]);
    $allTickets = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ================================================================
// ⭐ صفحه‌بندی — تقسیم به انجام‌نشده و انجام‌شده
// ================================================================
$pendingAll = array_values(array_filter($allTickets, fn($t) => !$t['status']));
$doneAll    = array_values(array_filter($allTickets, fn($t) => (bool)$t['status']));

$pagePending = max(1, (int)($_GET['p_p'] ?? 1));
$pageDone    = max(1, (int)($_GET['p_d'] ?? 1));

$totalPending    = count($pendingAll);
$totalDone       = count($doneAll);
$totalPagesPending = max(1, (int)ceil($totalPending / $TICKETS_PER_PAGE));
$totalPagesDone    = max(1, (int)ceil($totalDone    / $TICKETS_PER_PAGE));

if ($pagePending > $totalPagesPending) $pagePending = $totalPagesPending;
if ($pageDone > $totalPagesDone)       $pageDone = $totalPagesDone;

$pendingTickets = array_slice($pendingAll, ($pagePending - 1) * $TICKETS_PER_PAGE, $TICKETS_PER_PAGE);
$doneTickets    = array_slice($doneAll,    ($pageDone    - 1) * $TICKETS_PER_PAGE, $TICKETS_PER_PAGE);

$displayedTickets = array_merge($pendingTickets, $doneTickets);

// کامنت‌ها را فقط برای تیکت‌های نمایش‌داده‌شده بگیر (کارایی بهتر)
$comments = [];
foreach ($displayedTickets as $ticket) {
    $stmt = $db->prepare("SELECT * FROM ticket_comments WHERE ticket_id = ? ORDER BY created_at ASC");
    $stmt->execute([$ticket['id']]);
    $comments[$ticket['id']] = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$countPendingStmt = $db->prepare("SELECT COUNT(*) FROM tickets WHERE status = 0 AND user_id = ?");
$countPendingStmt->execute([$userId]);
$countPending = (int)$countPendingStmt->fetchColumn();

$countDoneStmt = $db->prepare("SELECT COUNT(*) FROM tickets WHERE status = 1 AND user_id = ?");
$countDoneStmt->execute([$userId]);
$countDone = (int)$countDoneStmt->fetchColumn();

$countTotalStmt = $db->prepare("SELECT COUNT(*) FROM tickets WHERE user_id = ?");
$countTotalStmt->execute([$userId]);
$countTotal = (int)$countTotalStmt->fetchColumn();

$displayUser = $_SESSION['user_mobile'] ?? $_SESSION['mobile'] ?? $_SESSION['username'] ?? 'کاربر';
$currentUserInitial = mb_substr(trim($displayUser), 0, 1, 'UTF-8');

if (ob_get_level()) {
    ob_end_flush();
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <title>مدیریت تیکت‌ها</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="icon" href="https://img.icons8.com/color/48/dashboard-layout.png" type="image/png">
<style>
    :root {
        --primary: #2b3452;
        --accent: #4c8bf5;
        --accent-hover: #2f6bdc;
        --bg: #f4f6fb;
        --card: #ffffff;
        --text: #2b3452;
        --text-soft: #8a94ad;
        --border: #eef1f8;
        --success: #2ebc8a;
        --warning: #ff8a3d;
        --danger: #f54e7a;
        --radius: 18px;
        --shadow: 0 8px 24px rgba(76, 108, 200, 0.06);
    }
    * { box-sizing: border-box; margin: 0; padding: 0; }
    html, body { max-width: 100%; overflow-x: hidden; }
    body {
        background: var(--bg);
        color: var(--text);
        font-family: 'Segoe UI', Tahoma, sans-serif;
        margin: 0;
        display: flex;
        height: 100vh;
        overflow: hidden;
        -webkit-font-smoothing: antialiased;
    }

    /* ================= SIDEBAR OVERLAY ================= */
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

    /* ================= LAYOUT ================= */
    .main-wrapper { flex: 1; display: flex; flex-direction: column; overflow: hidden; min-width: 0; }

    .topbar {
        background: transparent;
        padding: 22px 32px 8px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 12px;
    }
    .topbar h1 {
        margin: 0;
        font-size: 1.2rem;
        font-weight: 700;
        color: var(--text);
        line-height: 1.4;
    }
    .topbar h1 small {
        display: block;
        font-size: 0.78rem;
        color: var(--text-soft);
        font-weight: 400;
        margin-top: 4px;
    }
    .topbar-user {
        display: flex;
        align-items: center;
        gap: 10px;
        background: #fff;
        padding: 6px 14px 6px 6px;
        border-radius: 30px;
        box-shadow: var(--shadow);
        font-size: 0.85rem;
        font-weight: 600;
        color: var(--text);
    }
    .topbar-user .avatar {
        width: 34px; height: 34px;
        border-radius: 50%;
        background: linear-gradient(135deg, #4c8bf5, #2f6bdc);
        color: #fff;
        display: flex; align-items: center; justify-content: center;
        font-weight: 700;
        font-size: 0.85rem;
        overflow: hidden;
        flex-shrink: 0;
    }

    .content-area {
        flex: 1;
        padding: 18px 32px 32px;
        overflow-y: auto;
        overflow-x: hidden;
        -webkit-overflow-scrolling: touch;
        overscroll-behavior: contain;
        transform: translateZ(0);
    }

    /* ================= ALERT ================= */
    .flash {
        padding: 12px 16px;
        border-radius: 12px;
        margin-bottom: 18px;
        font-weight: 600;
        font-size: 0.88rem;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .flash-success { background: #e3f8ef; color: #0e7a55; border: 1px solid #a7f3d0; }
    .flash-error { background: #ffe4ec; color: #991b1b; border: 1px solid #fecaca; }

    /* ================= PAGE HEADER ================= */
    .page-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 12px;
        margin-bottom: 18px;
        flex-wrap: wrap;
    }

    .search-box { flex: 1; min-width: 260px; max-width: 500px; }
    .search-wrapper { position: relative; }
    .search-wrapper i {
        position: absolute;
        right: 16px;
        top: 50%;
        transform: translateY(-50%);
        color: #94a3b8;
        font-size: 0.9rem;
        pointer-events: none;
    }
    .search-wrapper input {
        width: 100%;
        padding: 11px 44px 11px 18px;
        border-radius: 14px;
        border: 2px solid var(--border);
        background: #fff;
        font-size: 0.88rem;
        transition: all 0.2s ease;
        outline: none;
        color: var(--text);
        font-family: inherit;
    }
    .search-wrapper input:focus {
        border-color: var(--accent);
        box-shadow: 0 0 0 4px rgba(76, 139, 245, 0.12);
    }

    .btn-new-ticket {
        background: linear-gradient(135deg, #4c8bf5 0%, #2f6bdc 100%);
        color: #fff;
        border: none;
        padding: 11px 22px;
        border-radius: 14px;
        font-weight: 700;
        font-size: 0.88rem;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        box-shadow: 0 6px 16px rgba(76, 139, 245, 0.25);
        transition: all 0.2s ease;
        white-space: nowrap;
    }
    .btn-new-ticket:hover {
        transform: translateY(-1px);
        box-shadow: 0 8px 20px rgba(76, 139, 245, 0.35);
    }
    .btn-new-ticket i { font-size: 0.85rem; }

    /* ================= TABS ================= */
    .tabs-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 12px;
        margin-bottom: 16px;
        flex-wrap: wrap;
    }

    .tabs {
        display: flex;
        gap: 8px;
        background: #fff;
        padding: 6px;
        border-radius: 14px;
        border: 1px solid var(--border);
        box-shadow: var(--shadow);
    }

    .tab-btn {
        background: transparent;
        border: none;
        padding: 9px 18px;
        font-size: 0.85rem;
        font-weight: 700;
        color: var(--text-soft);
        border-radius: 10px;
        cursor: pointer;
        transition: all 0.2s ease;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        font-family: inherit;
    }
    .tab-btn i { font-size: 0.85rem; }
    .tab-btn:hover { background: #f1f4fb; color: var(--accent); }
    .tab-btn.active {
        background: linear-gradient(135deg, #4c8bf5 0%, #2f6bdc 100%);
        color: #fff;
        box-shadow: 0 4px 12px rgba(76, 139, 245, 0.25);
    }
    .tab-btn .count {
        background: rgba(0,0,0,0.06);
        border-radius: 20px;
        padding: 1px 9px;
        font-size: 0.72rem;
        font-weight: 700;
        line-height: 18px;
        min-width: 22px;
        text-align: center;
    }
    .tab-btn.active .count {
        background: rgba(255,255,255,0.28);
        color: #fff;
    }

    .sort-bar {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 8px 14px;
        background: #fff;
        border: 1px solid var(--border);
        border-radius: 12px;
        box-shadow: var(--shadow);
    }
    .sort-bar .sort-label {
        display: flex;
        align-items: center;
        gap: 6px;
        color: var(--text-soft);
        font-size: 0.78rem;
        font-weight: 600;
        white-space: nowrap;
    }
    .sort-bar select {
        padding: 6px 30px 6px 12px;
        border-radius: 8px;
        border: 1px solid var(--border);
        background: #fff url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 12 12"><path fill="%238a94ad" d="M6 8L1 3h10z"/></svg>') no-repeat left 10px center;
        background-size: 11px;
        font-size: 0.82rem;
        font-weight: 500;
        color: var(--text);
        outline: none;
        cursor: pointer;
        appearance: none;
        -webkit-appearance: none;
        font-family: inherit;
    }
    .sort-bar select:focus {
        border-color: var(--accent);
        box-shadow: 0 0 0 3px rgba(76, 139, 245, 0.12);
    }

    .btn-backup {
        background: #fff;
        color: var(--accent);
        border: 1.5px solid var(--border);
        padding: 8px 14px;
        border-radius: 10px;
        font-weight: 700;
        font-size: 0.78rem;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        transition: all 0.2s ease;
        white-space: nowrap;
    }
    .btn-backup:hover {
        background: #f1f4fb;
        border-color: var(--accent);
        color: var(--accent-hover);
    }

    /* ================= TICKET LIST ================= */
    .ticket-list {
        display: flex;
        flex-direction: column;
        gap: 14px;
    }
    .ticket-item {
        background: #fff;
        border-radius: 18px;
        padding: 20px;
        box-shadow: 0 2px 10px rgba(76, 108, 200, 0.04);
        border: 1px solid var(--border);
        display: flex;
        flex-direction: column;
        gap: 14px;
        transition: box-shadow 0.2s ease, opacity 0.3s ease;
        contain: layout style paint;
        position: relative;
    }
    .ticket-item:hover {
        box-shadow: 0 6px 20px rgba(76, 108, 200, 0.09);
    }
    .ticket-item.status-pending { border-right: 4px solid var(--warning); }
    .ticket-item.status-done { border-right: 4px solid var(--success); }

    .ticket-item.updating { opacity: 0.5; pointer-events: none; }

    @keyframes searchMatch {
        0% { background: #fff7d6; box-shadow: 0 0 0 3px rgba(255, 167, 81, 0.25); }
        60% { background: #fff7d6; box-shadow: 0 0 0 3px rgba(255, 167, 81, 0.25); }
        100% { background: #fff; box-shadow: 0 2px 10px rgba(76, 108, 200, 0.04); }
    }
    .ticket-item.search-highlight {
        animation: searchMatch 1.8s ease;
    }

    .ticket-main {
        display: flex;
        justify-content: space-between;
        align-items: stretch;
        flex-wrap: wrap;
        gap: 14px;
    }
    .ticket-info {
        flex: 1;
        min-width: 220px;
        display: flex;
        flex-direction: column;
        gap: 10px;
    }
    .ticket-header {
        display: flex;
        align-items: center;
        flex-wrap: wrap;
        gap: 10px;
    }
    .ticket-number {
        font-weight: 800;
        font-size: 0.95rem;
        color: var(--accent);
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        user-select: none;
        padding: 5px 10px;
        border-radius: 9px;
        background: rgba(76, 139, 245, 0.08);
        transition: background 0.2s ease;
    }
    .ticket-number:hover { background: rgba(76, 139, 245, 0.15); }
    .ticket-number:active { transform: scale(0.97); }

    .ticket-status-badge {
        font-size: 0.72rem;
        font-weight: 700;
        padding: 5px 12px;
        border-radius: 20px;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        cursor: pointer;
        transition: all 0.2s ease;
        border: none;
        font-family: inherit;
    }
    .status-pending .ticket-status-badge {
        background: #fff3e0;
        color: #b45309;
    }
    .status-done .ticket-status-badge {
        background: #e3f8ef;
        color: #0e7a55;
    }
    .ticket-status-badge:hover { filter: brightness(0.96); }

    .priority-selector { position: relative; display: inline-block; }
    .priority-badge {
        font-size: 0.72rem;
        font-weight: 700;
        padding: 5px 12px;
        border-radius: 20px;
        color: #fff;
        background: #8a94ad;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        cursor: pointer;
        transition: all 0.2s ease;
        border: none;
        font-family: inherit;
    }
    .priority-badge:hover { filter: brightness(0.95); }
    .priority-dropdown {
        display: none;
        position: absolute;
        top: calc(100% + 6px);
        right: 0;
        background: #fff;
        border-radius: 12px;
        box-shadow: 0 12px 30px rgba(20, 30, 60, 0.12);
        min-width: 150px;
        z-index: 100;
        overflow: hidden;
        border: 1px solid var(--border);
        padding: 6px;
    }
    .priority-dropdown.show { display: block; }
    .priority-dropdown-item {
        padding: 8px 12px;
        cursor: pointer;
        transition: background 0.15s ease;
        font-size: 0.82rem;
        border: none;
        background: transparent;
        width: 100%;
        text-align: right;
        font-family: inherit;
        display: flex;
        align-items: center;
        gap: 8px;
        color: var(--text);
        border-radius: 8px;
    }
    .priority-dropdown-item:hover { background: #f1f4fb; color: var(--accent); }
    .priority-dropdown-item .color-dot {
        display: inline-block;
        width: 8px;
        height: 8px;
        border-radius: 50%;
        flex-shrink: 0;
    }

    .subject-container { margin: 2px 0; }
    .ticket-subject {
        font-size: 0.92rem;
        font-weight: 700;
        color: var(--text);
        padding: 6px 10px;
        border-radius: 8px;
        transition: background 0.2s ease, border-color 0.2s ease;
        border: 1px solid transparent;
        outline: none;
        background: transparent;
        font-family: inherit;
        width: 100%;
    }
    .ticket-subject:hover { background: #fafbfe; border-color: var(--border); }
    .ticket-subject:focus {
        border-color: var(--accent);
        background: #fff;
        box-shadow: 0 0 0 4px rgba(76, 139, 245, 0.12);
    }

    .task-link-wrapper {
        display: flex;
        align-items: center;
        gap: 8px;
        margin-top: 2px;
        flex-wrap: wrap;
    }
    .task-link-label {
        font-size: 0.78rem;
        color: var(--text-soft);
        font-weight: 600;
        white-space: nowrap;
    }
    .task-link-input {
        flex: 1;
        padding: 7px 12px;
        border-radius: 9px;
        border: 2px solid var(--border);
        font-size: 0.82rem;
        outline: none;
        transition: all 0.2s ease;
        font-family: inherit;
        background: #fafbfe;
        min-width: 150px;
    }
    .task-link-input:focus {
        border-color: var(--accent);
        box-shadow: 0 0 0 4px rgba(76, 139, 245, 0.12);
        background: #fff;
    }
    .task-link-display {
        font-size: 0.78rem;
        color: var(--accent);
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        font-weight: 600;
        padding: 5px 10px;
        border-radius: 8px;
        background: rgba(76, 139, 245, 0.08);
        transition: background 0.2s ease;
        word-break: break-all;
        max-width: 400px;
    }
    .task-link-display:hover {
        background: rgba(76, 139, 245, 0.15);
        text-decoration: underline;
    }
    .task-link-edit-btn {
        background: none;
        border: none;
        color: #94a3b8;
        cursor: pointer;
        font-size: 0.8rem;
        padding: 5px 8px;
        border-radius: 6px;
        transition: all 0.2s ease;
    }
    .task-link-edit-btn:hover { background: #f1f4fb; color: var(--accent); }
    .task-link-save-btn {
        background: linear-gradient(135deg, #4c8bf5 0%, #2f6bdc 100%);
        color: #fff;
        border: none;
        border-radius: 8px;
        padding: 7px 14px;
        font-size: 0.78rem;
        font-weight: 700;
        cursor: pointer;
        transition: all 0.2s ease;
        font-family: inherit;
        box-shadow: 0 4px 10px rgba(76, 139, 245, 0.22);
    }
    .task-link-save-btn:hover { transform: translateY(-1px); box-shadow: 0 6px 14px rgba(76, 139, 245, 0.3); }
    .task-link-cancel-btn {
        background: transparent;
        color: var(--text-soft);
        border: 1px solid var(--border);
        border-radius: 8px;
        padding: 6px 12px;
        font-size: 0.78rem;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.2s ease;
        font-family: inherit;
    }
    .task-link-cancel-btn:hover { background: #f1f4fb; }
    .task-link-empty {
        font-size: 0.78rem;
        color: #94a3b8;
        font-style: italic;
        cursor: pointer;
        padding: 5px 10px;
        border-radius: 8px;
        transition: all 0.2s ease;
    }
    .task-link-empty:hover { background: #fafbfe; color: var(--accent); }

    .ticket-meta {
        display: flex;
        flex-wrap: wrap;
        gap: 14px;
        font-size: 0.75rem;
        color: var(--text-soft);
        align-items: center;
    }
    .ticket-meta .date { display: flex; align-items: center; gap: 6px; }

    .ticket-actions {
        display: flex;
        align-items: center;
        gap: 8px;
        flex-shrink: 0;
        flex-wrap: wrap;
    }

    .btn {
        display: inline-flex;
        align-items: center;
        gap: 7px;
        padding: 8px 16px;
        border-radius: 10px;
        font-weight: 700;
        font-size: 0.8rem;
        border: none;
        cursor: pointer;
        transition: all 0.2s ease;
        text-decoration: none;
        white-space: nowrap;
        font-family: inherit;
    }
    .btn i { font-size: 0.85rem; }
    .btn:hover { transform: translateY(-1px); }
    .btn-success { background: #e3f8ef; color: #0e7a55; }
    .btn-success:hover { background: #2ebc8a; color: #fff; }
    .btn-danger { background: #ffe4ec; color: #c81e4a; }
    .btn-danger:hover { background: #f54e7a; color: #fff; }
    .btn-sm { padding: 7px 14px; font-size: 0.78rem; }
    .btn-primary {
        background: linear-gradient(135deg, #4c8bf5 0%, #2f6bdc 100%);
        color: #fff;
        box-shadow: 0 6px 16px rgba(76, 139, 245, 0.25);
    }
    .btn-primary:hover { box-shadow: 0 8px 20px rgba(76, 139, 245, 0.35); }
    .btn-outline {
        background: #fff;
        border: 1.5px solid var(--border);
        color: var(--text);
    }
    .btn-outline:hover { background: #f1f4fb; border-color: var(--accent); color: var(--accent); }

    /* ================= PAGINATION ================= */
    .tickets-pagination {
        display: flex;
        justify-content: center;
        margin-top: 22px;
        margin-bottom: 10px;
    }
    .tickets-pagination-inner {
        display: flex;
        gap: 6px;
        align-items: center;
        flex-wrap: wrap;
        padding: 8px 14px;
        background: #fff;
        border-radius: 14px;
        border: 1px solid var(--border);
        box-shadow: var(--shadow);
    }
    .pag-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 38px;
        height: 38px;
        padding: 0 10px;
        border-radius: 10px;
        background: #f1f4fb;
        color: var(--text);
        font-weight: 700;
        font-size: 0.85rem;
        text-decoration: none;
        border: 1px solid var(--border);
        transition: all 0.15s ease;
        font-family: inherit;
        cursor: pointer;
    }
    .pag-btn:hover {
        background: #e7f0ff;
        color: var(--accent);
        border-color: #c7d7ff;
    }
    .pag-btn.active {
        background: linear-gradient(135deg, #4c8bf5 0%, #2f6bdc 100%);
        color: #fff;
        border-color: #4c8bf5;
        box-shadow: 0 4px 12px rgba(76, 139, 245, 0.25);
    }
    .pag-btn.disabled {
        opacity: 0.4;
        cursor: not-allowed;
        pointer-events: none;
    }
    .pag-dots {
        color: var(--text-soft);
        padding: 0 6px;
        font-weight: 700;
        user-select: none;
    }
    .pag-info {
        color: var(--text-soft);
        font-size: 0.72rem;
        font-weight: 600;
        padding: 0 8px;
    }

    /* ================= COMMENTS ================= */
    .comments-section {
        margin-top: 4px;
        padding-top: 14px;
        border-top: 1px dashed var(--border);
    }
    .comments-title {
        font-size: 0.78rem;
        font-weight: 700;
        color: var(--text-soft);
        margin-bottom: 10px;
        display: flex;
        align-items: center;
        gap: 6px;
    }
    .comments-title .count-badge {
        font-weight: 500;
        color: #94a3b8;
    }
    .comments-list {
        display: flex;
        flex-direction: column;
        gap: 8px;
        margin-bottom: 10px;
    }
    .comment-item {
        background: #fafbfe;
        padding: 10px 14px;
        border-radius: 11px;
        font-size: 0.82rem;
        color: var(--text);
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 10px;
        border: 1px solid var(--border);
        border-right: 3px solid var(--accent);
        transition: all 0.3s ease;
    }
    .comment-item.newly-added {
        animation: commentIn 0.4s ease;
    }
    @keyframes commentIn {
        from { opacity: 0; transform: translateX(-10px); }
        to { opacity: 1; transform: translateX(0); }
    }
    .comment-item .comment-text { flex: 1; word-break: break-word; line-height: 1.7; }
    .comment-item .comment-meta {
        font-size: 0.7rem;
        color: #94a3b8;
        white-space: nowrap;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .comment-item .comment-meta .delete-comment {
        color: var(--danger);
        cursor: pointer;
        transition: all 0.2s ease;
        background: #ffe4ec;
        border: none;
        font-size: 0.72rem;
        padding: 3px 7px;
        border-radius: 6px;
        font-weight: 700;
        font-family: inherit;
    }
    .comment-item .comment-meta .delete-comment:hover { background: var(--danger); color: #fff; }

    .comment-input-wrapper {
        display: flex;
        gap: 8px;
        align-items: center;
    }
    .comment-input-wrapper input {
        flex: 1;
        padding: 10px 14px;
        border-radius: 11px;
        border: 2px solid var(--border);
        font-size: 0.82rem;
        outline: none;
        transition: all 0.2s ease;
        font-family: inherit;
        background: #fafbfe;
    }
    .comment-input-wrapper input:focus {
        border-color: var(--accent);
        box-shadow: 0 0 0 4px rgba(76, 139, 245, 0.12);
        background: #fff;
    }
    .comment-input-wrapper .add-comment-btn {
        background: linear-gradient(135deg, #4c8bf5 0%, #2f6bdc 100%);
        color: #fff;
        border: none;
        border-radius: 11px;
        width: 42px;
        height: 42px;
        cursor: pointer;
        transition: all 0.2s ease;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 0.9rem;
        flex-shrink: 0;
        box-shadow: 0 4px 10px rgba(76, 139, 245, 0.22);
    }
    .comment-input-wrapper .add-comment-btn:hover {
        transform: translateY(-1px);
        box-shadow: 0 6px 14px rgba(76, 139, 245, 0.3);
    }
    .comment-input-wrapper .add-comment-btn:disabled {
        opacity: 0.6;
        cursor: not-allowed;
        transform: none;
    }

    /* ================= FORM NEW TICKET ================= */
    .form-card {
        background: #fff;
        border-radius: 20px;
        padding: 28px;
        border: 1px solid var(--border);
        box-shadow: var(--shadow);
    }
    .form-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 22px;
        padding-bottom: 16px;
        border-bottom: 1px solid var(--border);
    }
    .form-header h3 {
        margin: 0;
        font-size: 1.05rem;
        font-weight: 700;
        color: var(--text);
        display: flex;
        align-items: center;
        gap: 10px;
    }
    .form-header h3 .form-header-icon {
        width: 38px; height: 38px;
        border-radius: 12px;
        background: linear-gradient(135deg, #4c8bf5, #2f6bdc);
        color: #fff;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 0.9rem;
    }

    .form-row {
        display: flex;
        flex-wrap: wrap;
        gap: 16px;
        margin-bottom: 16px;
    }
    .form-group {
        flex: 1 0 200px;
        display: flex;
        flex-direction: column;
        gap: 8px;
    }
    .form-group label {
        font-weight: 600;
        font-size: 0.82rem;
        color: var(--text);
        display: flex;
        align-items: center;
        gap: 7px;
    }
    .form-group label i { color: var(--accent); font-size: 0.78rem; }
    .form-group label .required { color: var(--danger); margin-right: 2px; }
    .form-group input,
    .form-group textarea,
    .form-group select {
        padding: 11px 16px;
        border-radius: 12px;
        border: 2px solid var(--border);
        font-size: 0.88rem;
        background: #fafbfe;
        transition: all 0.2s ease;
        width: 100%;
        font-family: inherit;
        color: var(--text);
    }
    .form-group input:focus,
    .form-group textarea:focus,
    .form-group select:focus {
        outline: none;
        border-color: var(--accent);
        background: #fff;
        box-shadow: 0 0 0 4px rgba(76, 139, 245, 0.12);
    }
    .form-group textarea { min-height: 100px; resize: vertical; line-height: 1.7; }
    .form-actions {
        display: flex;
        gap: 12px;
        margin-top: 10px;
        flex-wrap: wrap;
        padding-top: 18px;
        border-top: 1px solid var(--border);
    }

    .empty-state {
        text-align: center;
        padding: 60px 20px;
        color: var(--text-soft);
        font-size: 0.9rem;
    }
    .empty-state i {
        font-size: 44px;
        display: block;
        margin-bottom: 12px;
        color: #cbd5e1;
    }

    /* ================= TOAST ================= */
    .toast {
        position: fixed;
        bottom: 30px;
        left: 50%;
        transform: translateX(-50%) translateY(20px);
        background: var(--primary);
        color: #fff;
        padding: 12px 24px;
        border-radius: 14px;
        font-size: 0.85rem;
        font-weight: 600;
        box-shadow: 0 15px 35px rgba(0,0,0,0.18);
        z-index: 1000;
        display: flex;
        align-items: center;
        gap: 10px;
        opacity: 0;
        pointer-events: none;
        transition: all 0.3s ease;
        max-width: 90vw;
    }
    .toast.show {
        opacity: 1;
        transform: translateX(-50%) translateY(0);
    }
    .toast i { color: var(--success); font-size: 1rem; }
    .toast.error i { color: var(--danger); }

    /* ================= HAMBURGER ================= */
    .hamburger-btn {
        display: none;
        width: 44px; height: 44px;
        border: none;
        background: linear-gradient(135deg, #4c8bf5 0%, #2f6bdc 100%);
        border-radius: 13px;
        cursor: pointer;
        flex-shrink: 0;
        align-items: center;
        justify-content: center;
        padding: 0;
        transition: transform 0.15s ease;
        box-shadow: 0 6px 16px rgba(76, 139, 245, 0.25);
    }
    .hamburger-btn:active { transform: scale(0.95); }
    .hamburger-btn .hamburger-lines {
        width: 22px; height: 16px;
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
        transition: transform 0.3s, opacity 0.2s;
        transform-origin: center;
    }
    .hamburger-btn .hamburger-lines span:nth-child(2) { width: 70%; }
    .hamburger-btn.active .hamburger-lines span:nth-child(1) { transform: translateY(6.75px) rotate(45deg); }
    .hamburger-btn.active .hamburger-lines span:nth-child(2) { opacity: 0; transform: translateX(-10px); }
    .hamburger-btn.active .hamburger-lines span:nth-child(3) { transform: translateY(-6.75px) rotate(-45deg); }

    /* ================= RESPONSIVE ================= */
    @media (max-width: 900px) {
        .hamburger-btn { display: flex; }

        .sidebar {
            position: fixed !important;
            top: 0; right: 0; bottom: 0;
            width: 280px;
            max-width: 85vw;
            transform: translateX(105%);
            transition: transform 0.35s cubic-bezier(0.65, 0, 0.35, 1);
            z-index: 999 !important;
            height: 100vh;
        }
        .sidebar.open { transform: translateX(0); }

        .main-wrapper { width: 100%; }
        .topbar { padding: 14px 18px 6px; gap: 12px; }
        .topbar h1 { font-size: 1rem; flex: 1; }
        .content-area { padding: 12px 18px 24px; }
        .page-header { flex-direction: column; align-items: stretch; }
        .search-box { max-width: 100%; }
        .btn-new-ticket { justify-content: center; }
        .tabs-row { flex-direction: column; align-items: stretch; }
        .tabs { justify-content: center; }
        .sort-bar { justify-content: space-between; }
        .ticket-item { padding: 16px; }
        .ticket-main { flex-direction: column; }
        .ticket-actions { justify-content: flex-end; }
        .ticket-info { min-width: 0; width: 100%; }
        .task-link-display { max-width: 100%; }
    }
    @media (max-width: 600px) {
        .content-area { padding: 10px 14px 20px; }
        .topbar { padding: 12px 14px 4px; }
        .topbar h1 { font-size: 0.9rem; }
        .topbar-user span:not(.avatar) { display: none; }
        .topbar-user { padding: 4px; }
        .form-card { padding: 18px; }
        .form-row { flex-direction: column; gap: 12px; }
        .form-actions { flex-direction: column; }
        .form-actions .btn { width: 100%; justify-content: center; }
        .tabs { padding: 4px; }
        .tab-btn { padding: 8px 12px; font-size: 0.78rem; }
        .comment-input-wrapper { flex-direction: column; }
        .comment-input-wrapper input { width: 100%; }
        .comment-input-wrapper .add-comment-btn { width: 100%; height: 40px; }
        .task-link-wrapper { flex-direction: column; align-items: stretch; }

        .pag-btn { min-width: 34px; height: 34px; font-size: 0.78rem; padding: 0 8px; }
        .pag-info { font-size: 0.68rem; width: 100%; text-align: center; padding: 4px 0 0; }
        .tickets-pagination-inner { justify-content: center; }
    }
    @media (max-width: 400px) {
        .ticket-header { gap: 8px; }
        .ticket-number { font-size: 0.85rem; padding: 4px 8px; }
        .ticket-status-badge, .priority-badge { font-size: 0.68rem; padding: 4px 9px; }
    }
    @media (prefers-reduced-motion: reduce) {
        * { transition: none !important; animation: none !important; }
    }

    /* ================= غیرفعال کردن انتخاب متن ================= */
    body, .topbar, .ticket-item, .form-card, .comments-section, button, a, .ticket-number, .ticket-subject, .task-link-display {
        -webkit-user-select: none;
        -moz-user-select: none;
        -ms-user-select: none;
        user-select: none;
    }
    input, textarea {
        -webkit-user-select: text;
        -moz-user-select: text;
        -ms-user-select: text;
        user-select: text;
    }
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

<!-- ⭐ overlay سایدبار موبایل -->
<div class="sidebar-overlay" id="sidebarOverlay" onclick="closeSidebar()"></div>

<div class="main-wrapper">
    <div class="topbar">
        <button class="hamburger-btn" id="hamburgerBtn" onclick="toggleSidebar()" aria-label="باز کردن منو">
            <div class="hamburger-lines">
                <span></span><span></span><span></span>
            </div>
        </button>
        <h1>
            مدیریت تیکت‌ها
            <small>پیگیری و مدیریت درخواست‌های من</small>
        </h1>
        <div class="topbar-user">
            <div class="avatar"><?= htmlspecialchars($currentUserInitial, ENT_QUOTES, 'UTF-8') ?></div>
            <span><?= htmlspecialchars($displayUser, ENT_QUOTES, 'UTF-8') ?></span>
        </div>
    </div>

    <div class="content-area">
        <?php if (!empty($message)): ?>
            <div class="flash flash-<?= htmlspecialchars($message_type, ENT_QUOTES, 'UTF-8') ?>">
                <i class="fas fa-<?= $message_type === 'success' ? 'check-circle' : 'exclamation-circle' ?>"></i>
                <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <?php if ($active_tab !== 'new'): ?>
            <div class="page-header">
                <div class="search-box">
                    <div class="search-wrapper">
                        <i class="fas fa-search"></i>
                        <input type="text"
                               placeholder="جستجو در تیکت‌های من (شماره، عنوان، متن کامنت)..."
                               id="searchInput"
                               autocomplete="off">
                    </div>
                </div>
                <a href="?tab=new" class="btn-new-ticket">
                    <i class="fas fa-plus"></i>
                    ثبت تیکت جدید
                </a>
            </div>

            <div class="tabs-row">
                <div class="tabs">
                    <button type="button" class="tab-btn <?= $active_tab === 'pending' ? 'active' : '' ?>" data-tab="pending" onclick="switchTab('pending')">
                        <i class="fas fa-hourglass-half"></i>
                        انجام نشده
                        <span class="count" id="pendingCount"><?= (int)$countPending ?></span>
                    </button>
                    <button type="button" class="tab-btn <?= $active_tab === 'done' ? 'active' : '' ?>" data-tab="done" onclick="switchTab('done')">
                        <i class="fas fa-check-circle"></i>
                        انجام شده
                        <span class="count" id="doneCount"><?= (int)$countDone ?></span>
                    </button>
                </div>

                <div class="sort-bar">
                    <span class="sort-label"><i class="fas fa-arrow-up-wide-short"></i> مرتب‌سازی:</span>
                    <select id="sortSelect" onchange="changeSort(this.value)">
                        <option value="created_at_desc" <?= $sort === 'created_at_desc' ? 'selected' : '' ?>>جدیدترین</option>
                        <option value="created_at_asc" <?= $sort === 'created_at_asc' ? 'selected' : '' ?>>قدیمی‌ترین</option>
                        <option value="priority_desc" <?= $sort === 'priority_desc' ? 'selected' : '' ?>>اهمیت (بیشترین)</option>
                        <option value="priority_asc" <?= $sort === 'priority_asc' ? 'selected' : '' ?>>اهمیت (کمترین)</option>
                        <option value="ticket_number_asc" <?= $sort === 'ticket_number_asc' ? 'selected' : '' ?>>شماره (صعودی)</option>
                        <option value="ticket_number_desc" <?= $sort === 'ticket_number_desc' ? 'selected' : '' ?>>شماره (نزولی)</option>
                    </select>
                    <a href="?action=backup" class="btn-backup" title="دانلود بکاپ JSON">
                        <i class="fas fa-download"></i>
                        بکاپ
                    </a>
                </div>
            </div>

            <?php if (count($allTickets) > 0): ?>
                <div class="ticket-list" id="ticketList">
                    <?php
                    // نمایش ابتدا تیکت‌های انجام‌نشده صفحه فعلی، سپس انجام‌شده‌های صفحه فعلی
                    foreach (array_merge($pendingTickets, $doneTickets) as $ticket):
                        $ticketComments = $comments[$ticket['id']] ?? [];
                        $statusKey = $ticket['status'] ? 'done' : 'pending';
                        $searchData = strtolower($ticket['ticket_number'] . ' ' . ($ticket['subject'] ?? '') . ' ' . ($ticket['task_link'] ?? ''));
                    ?>
                        <div class="ticket-item status-<?= $statusKey ?>"
                             id="ticket-<?= (int)$ticket['id'] ?>"
                             data-id="<?= (int)$ticket['id'] ?>"
                             data-status="<?= $statusKey ?>"
                             data-search="<?= htmlspecialchars($searchData, ENT_QUOTES, 'UTF-8') ?>">

                            <div class="ticket-main">
                                <div class="ticket-info">
                                    <div class="ticket-header">
                                        <span class="ticket-number"
                                              onclick="copyTicketNumber('<?= htmlspecialchars($ticket['ticket_number'], ENT_QUOTES, 'UTF-8') ?>')"
                                              title="برای کپی کلیک کنید">
                                            <i class="fas fa-hashtag"></i> <?= htmlspecialchars($ticket['ticket_number'], ENT_QUOTES, 'UTF-8') ?>
                                        </span>

                                        <button type="button" class="ticket-status-badge"
                                                onclick="toggleStatus(<?= (int)$ticket['id'] ?>, <?= $ticket['status'] ? 0 : 1 ?>)">
                                            <i class="fas fa-<?= $ticket['status'] ? 'check-circle' : 'clock' ?>"></i>
                                            <span class="status-text"><?= $ticket['status'] ? 'انجام شده' : 'انجام نشده' ?></span>
                                        </button>

                                        <div class="priority-selector">
                                            <button type="button" class="priority-badge"
                                                    style="background:<?= priorityColor($ticket['priority']) ?>"
                                                    onclick="togglePriorityDropdown(<?= (int)$ticket['id'] ?>)">
                                                <i class="fas fa-flag"></i>
                                                <span class="priority-text"><?= priorityLabel($ticket['priority']) ?></span>
                                                <i class="fas fa-chevron-down" style="font-size:9px;opacity:0.75;"></i>
                                            </button>
                                            <div class="priority-dropdown" id="priority-dropdown-<?= (int)$ticket['id'] ?>">
                                                <button type="button" class="priority-dropdown-item" onclick="updatePriority(<?= (int)$ticket['id'] ?>, 'low')">
                                                    <span class="color-dot" style="background:#2ebc8a;"></span> کم
                                                </button>
                                                <button type="button" class="priority-dropdown-item" onclick="updatePriority(<?= (int)$ticket['id'] ?>, 'medium')">
                                                    <span class="color-dot" style="background:#ff8a3d;"></span> متوسط
                                                </button>
                                                <button type="button" class="priority-dropdown-item" onclick="updatePriority(<?= (int)$ticket['id'] ?>, 'high')">
                                                    <span class="color-dot" style="background:#f97316;"></span> زیاد
                                                </button>
                                                <button type="button" class="priority-dropdown-item" onclick="updatePriority(<?= (int)$ticket['id'] ?>, 'very_high')">
                                                    <span class="color-dot" style="background:#f54e7a;"></span> خیلی زیاد
                                                </button>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="subject-container">
                                        <input type="text" class="ticket-subject"
                                               id="subject-<?= (int)$ticket['id'] ?>"
                                               value="<?= htmlspecialchars($ticket['subject'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                               placeholder="بدون عنوان"
                                               onblur="saveSubject(<?= (int)$ticket['id'] ?>)"
                                               onkeydown="if(event.key==='Enter'){this.blur();} if(event.key==='Escape'){this.value=this.dataset.original;this.blur();}"
                                               data-original="<?= htmlspecialchars($ticket['subject'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                                    </div>

                                    <div class="task-link-wrapper" id="task-link-wrapper-<?= (int)$ticket['id'] ?>">
                                        <span class="task-link-label"><i class="fas fa-link"></i> تسک:</span>

                                        <?php if (!empty($ticket['task_link'])): ?>
                                            <a href="<?= htmlspecialchars($ticket['task_link'], ENT_QUOTES, 'UTF-8') ?>"
                                               target="_blank"
                                               class="task-link-display"
                                               id="task-link-display-<?= (int)$ticket['id'] ?>">
                                                <i class="fas fa-external-link-alt"></i> <?= htmlspecialchars($ticket['task_link'], ENT_QUOTES, 'UTF-8') ?>
                                            </a>
                                            <button type="button" class="task-link-edit-btn"
                                                    onclick="editTaskLink(<?= (int)$ticket['id'] ?>)"
                                                    title="ویرایش لینک">
                                                <i class="fas fa-pen"></i>
                                            </button>
                                        <?php else: ?>
                                            <span class="task-link-empty" onclick="editTaskLink(<?= (int)$ticket['id'] ?>)">
                                                <i class="fas fa-plus-circle"></i> افزودن لینک تسک
                                            </span>
                                        <?php endif; ?>

                                        <div class="task-link-edit-form"
                                             id="task-link-edit-<?= (int)$ticket['id'] ?>"
                                             style="display:none;flex:1;align-items:center;gap:8px;flex-wrap:wrap;">
                                            <input type="url" class="task-link-input"
                                                   id="task-link-input-<?= (int)$ticket['id'] ?>"
                                                   value="<?= htmlspecialchars($ticket['task_link'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                                   placeholder="https://example.com/task/123">
                                            <button type="button" class="task-link-save-btn" onclick="saveTaskLink(<?= (int)$ticket['id'] ?>)">
                                                <i class="fas fa-check"></i> ذخیره
                                            </button>
                                            <button type="button" class="task-link-cancel-btn" onclick="cancelTaskLink(<?= (int)$ticket['id'] ?>)">
                                                انصراف
                                            </button>
                                        </div>
                                    </div>

                                    <div class="ticket-meta">
                                        <span class="date">
                                            <i class="far fa-calendar-alt"></i>
                                            <?= date('Y/m/d H:i', strtotime($ticket['created_at'])) ?>
                                        </span>
                                    </div>
                                </div>

                                <div class="ticket-actions">
                                    <?php if (!$ticket['status']): ?>
                                        <button type="button" class="btn btn-success btn-sm"
                                                onclick="toggleStatus(<?= (int)$ticket['id'] ?>, 1)">
                                            <i class="fas fa-check"></i> انجام شد
                                        </button>
                                    <?php else: ?>
                                        <button type="button" class="btn btn-danger btn-sm"
                                                onclick="toggleStatus(<?= (int)$ticket['id'] ?>, 0)">
                                            <i class="fas fa-undo-alt"></i> بازگشت
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="comments-section">
                                <div class="comments-title">
                                    <i class="fas fa-comment-dots"></i> نظرات
                                    <span class="count-badge" id="comments-count-<?= (int)$ticket['id'] ?>">
                                        (<?= count($ticketComments) ?>)
                                    </span>
                                </div>

                                <?php if (count($ticketComments) > 0): ?>
                                    <div class="comments-list" id="comments-<?= (int)$ticket['id'] ?>">
                                        <?php foreach ($ticketComments as $comment): ?>
                                            <div class="comment-item" id="comment-<?= (int)$comment['id'] ?>">
                                                <span class="comment-text"><?= nl2br(htmlspecialchars($comment['comment'], ENT_QUOTES, 'UTF-8')) ?></span>
                                                <span class="comment-meta">
                                                    <?= date('H:i', strtotime($comment['created_at'])) ?>
                                                    <button type="button" class="delete-comment"
                                                            onclick="deleteComment(<?= (int)$comment['id'] ?>, <?= (int)$ticket['id'] ?>)"
                                                            title="حذف کامنت">
                                                        <i class="fas fa-times"></i>
                                                    </button>
                                                </span>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <div class="comments-list" id="comments-<?= (int)$ticket['id'] ?>" style="display:none;"></div>
                                <?php endif; ?>

                                <div class="comment-input-wrapper">
                                    <input type="text"
                                           id="comment-input-<?= (int)$ticket['id'] ?>"
                                           placeholder="کامنت جدید بنویسید..."
                                           onkeydown="if(event.key==='Enter'){event.preventDefault();addComment(<?= (int)$ticket['id'] ?>);}">
                                    <button type="button" class="add-comment-btn"
                                            id="add-comment-btn-<?= (int)$ticket['id'] ?>"
                                            onclick="addComment(<?= (int)$ticket['id'] ?>)">
                                        <i class="fas fa-plus"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- ============================================================ -->
                <!-- ⭐ صفحه‌بندی — برای هر تب مستقل -->
                <!-- ============================================================ -->
                <div class="tickets-pagination" data-tab="pending" style="<?= $active_tab === 'pending' ? '' : 'display:none;' ?>">
                    <?= renderTicketsPagination($pagePending, $totalPagesPending, $totalPending, 'p_p', 'pending', $sort) ?>
                </div>
                <div class="tickets-pagination" data-tab="done" style="<?= $active_tab === 'done' ? '' : 'display:none;' ?>">
                    <?= renderTicketsPagination($pageDone, $totalPagesDone, $totalDone, 'p_d', 'done', $sort) ?>
                </div>

                <div class="empty-state" id="emptyState" style="display:none;">
                    <i class="fas fa-search"></i>
                    <p>نتیجه‌ای برای جستجوی شما یافت نشد.</p>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-inbox"></i>
                    <p>هیچ تیکتی برای شما ثبت نشده است. اولین تیکت خود را ثبت کنید.</p>
                </div>
            <?php endif; ?>

        <?php elseif ($active_tab === 'new'): ?>
            <div class="form-card">
                <div class="form-header">
                    <h3>
                        <span class="form-header-icon"><i class="fas fa-plus"></i></span>
                        ثبت تیکت جدید
                    </h3>
                    <a href="<?= buildUrl('pending', $sort) ?>" class="btn btn-outline btn-sm">
                        <i class="fas fa-arrow-right"></i> بازگشت
                    </a>
                </div>

                <form method="POST" action="">
                    <input type="hidden" name="action" value="add_ticket">

                    <div class="form-row">
                        <div class="form-group">
                            <label><i class="fas fa-hashtag"></i> شماره تیکت <span class="required">*</span></label>
                            <input type="text" name="ticket_number" placeholder="مثلاً T-1234" required autofocus>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-tag"></i> عنوان</label>
                            <input type="text" name="subject" placeholder="عنوان تیکت (اختیاری)">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label><i class="fas fa-link"></i> لینک تسک</label>
                            <input type="url" name="task_link" placeholder="https://example.com/task/123 (اختیاری)">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-flag"></i> سطح اهمیت <span class="required">*</span></label>
                            <select name="priority" required>
                                <option value="low">کم</option>
                                <option value="medium" selected>متوسط</option>
                                <option value="high">زیاد</option>
                                <option value="very_high">خیلی زیاد</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label><i class="fas fa-comment"></i> کامنت اولیه</label>
                            <textarea name="initial_comment" placeholder="توضیحات اولیه (اختیاری)"></textarea>
                        </div>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i>
                            ثبت تیکت
                        </button>
                        <a href="<?= buildUrl('pending', $sort) ?>" class="btn btn-outline">
                            <i class="fas fa-times"></i>
                            انصراف
                        </a>
                    </div>
                </form>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="toast" id="toast">
    <i class="fas fa-check-circle"></i>
    <span id="toastMessage">عملیات با موفقیت انجام شد!</span>
</div>

<script>

    /* ================== غیرفعال کردن راست کلیک ================== */
    document.addEventListener('contextmenu', function(e) {
        const tag = (e.target.tagName || '').toLowerCase();
        if (tag === 'input' || tag === 'textarea') return true;
        e.preventDefault();
        return false;
    });

    /* ================== غیرفعال کردن Select All (Ctrl+A) ================== */
    document.addEventListener('keydown', function(e) {
        if ((e.ctrlKey || e.metaKey) && (e.key === 'a' || e.key === 'A')) {
            const tag = (e.target.tagName || '').toLowerCase();
            if (tag !== 'input' && tag !== 'textarea') {
                e.preventDefault();
                return false;
            }
        }
        if ((e.ctrlKey || e.metaKey) && (e.key === 'u' || e.key === 'U')) {
            e.preventDefault();
            return false;
        }
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

    /* ================== Ctrl+S برای ثبت/ذخیره ================== */
    document.addEventListener('keydown', function(e) {
        if ((e.ctrlKey || e.metaKey) && (e.key === 's' || e.key === 'S')) {
            e.preventDefault();
            e.stopPropagation();

            const newTicketForm = document.querySelector('.form-card form[method="POST"]');
            if (newTicketForm && document.querySelector('.form-card')) {
                if (typeof newTicketForm.requestSubmit === 'function') {
                    newTicketForm.requestSubmit();
                } else {
                    newTicketForm.dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }));
                }
                return false;
            }

            const activeEl = document.activeElement;
            if (activeEl && activeEl.classList.contains('ticket-subject')) {
                activeEl.blur();
                return false;
            }
            if (activeEl && activeEl.classList.contains('task-link-input')) {
                const ticketId = activeEl.id.replace('task-link-input-', '');
                if (typeof saveTaskLink === 'function') {
                    saveTaskLink(ticketId);
                }
                return false;
            }
            if (activeEl && activeEl.id && activeEl.id.startsWith('comment-input-')) {
                const ticketId = activeEl.id.replace('comment-input-', '');
                if (typeof addComment === 'function') {
                    addComment(ticketId);
                }
                return false;
            }
            if (activeEl && (activeEl.tagName === 'INPUT' || activeEl.tagName === 'TEXTAREA')) {
                activeEl.blur();
            }
            return false;
        }
    }, true);

    /* ============================================================
       STATE
    ============================================================ */
    let currentTab = '<?= htmlspecialchars($active_tab, ENT_QUOTES, 'UTF-8') ?>';
    if (currentTab === 'new') currentTab = 'pending';

    /* ============================================================
       AJAX HELPER
    ============================================================ */
    function ajax(url, data) {
        const endpoint = url || window.location.href;
        return fetch(endpoint, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: data
        }).then(r => r.text()).then(text => {
            try {
                return JSON.parse(text);
            } catch (e) {
                console.error('❌ Response is not valid JSON.\nRaw text:', text);
                throw new Error('پاسخ سرور معتبر نیست');
            }
        });
    }

    /* ============================================================
       TOAST
    ============================================================ */
    function showToast(message, type = 'success') {
        const toast = document.getElementById('toast');
        const msg = document.getElementById('toastMessage');
        const icon = toast.querySelector('i');
        msg.textContent = message;
        toast.classList.toggle('error', type === 'error');
        icon.className = type === 'error' ? 'fas fa-exclamation-circle' : 'fas fa-check-circle';
        toast.classList.add('show');
        clearTimeout(toast._t);
        toast._t = setTimeout(() => toast.classList.remove('show'), 3000);
    }

    /* ============================================================
       TABS
    ============================================================ */
    function switchTab(tab) {
        currentTab = tab;
        document.querySelectorAll('.tab-btn').forEach(btn => {
            btn.classList.toggle('active', btn.dataset.tab === tab);
        });

        // ⭐ نمایش/مخفی کردن صفحه‌بندی مربوط به هر تب
        document.querySelectorAll('.tickets-pagination').forEach(pag => {
            const ptab = pag.getAttribute('data-tab');
            pag.style.display = (ptab === tab) ? '' : 'none';
        });

        applyVisibility();
    }

    /* ============================================================
       VISIBILITY
    ============================================================ */
    function applyVisibility() {
        const query = (document.getElementById('searchInput')?.value || '').toLowerCase().trim();
        const isSearching = query.length > 0;
        const items = document.querySelectorAll('.ticket-item');
        let visibleCount = 0;

        items.forEach(item => {
            const status = item.getAttribute('data-status');
            const searchData = (item.getAttribute('data-search') || '').toLowerCase();
            let visible;

            if (isSearching) {
                visible = searchData.includes(query);
            } else {
                visible = status === currentTab;
            }

            if (visible) {
                item.style.display = '';
                visibleCount++;
            } else {
                item.style.display = 'none';
            }
        });

        // ⭐ در حالت جستجو، صفحه‌بندی مخفی می‌شود
        document.querySelectorAll('.tickets-pagination').forEach(pag => {
            const ptab = pag.getAttribute('data-tab');
            pag.style.display = (!isSearching && ptab === currentTab) ? '' : 'none';
        });

        const emptyState = document.getElementById('emptyState');
        if (emptyState) {
            emptyState.style.display = (isSearching && visibleCount === 0) ? 'block' : 'none';
        }
    }

    /* ============================================================
       SEARCH
    ============================================================ */
    const searchInput = document.getElementById('searchInput');
    if (searchInput) {
        let lastQuery = '';
        searchInput.addEventListener('input', function () {
            const query = this.value.toLowerCase().trim();
            applyVisibility();

            if (query.length >= 2 && query !== lastQuery) {
                lastQuery = query;
                document.querySelectorAll('.ticket-item').forEach(item => {
                    if (item.style.display === 'none') return;
                    const searchData = (item.getAttribute('data-search') || '').toLowerCase();
                    if (searchData.includes(query)) {
                        item.classList.remove('search-highlight');
                        void item.offsetWidth;
                        item.classList.add('search-highlight');
                    }
                });
            } else if (query.length === 0) {
                lastQuery = '';
            }
        });
    }

    /* ============================================================
       SORT
    ============================================================ */
    function changeSort(value) {
        const url = new URL(window.location.href);
        url.searchParams.set('sort', value);
        // ⭐ ریست صفحه‌بندی هنگام تغییر مرتب‌سازی
        url.searchParams.delete('p_p');
        url.searchParams.delete('p_d');
        if (currentTab !== 'new') {
            url.searchParams.set('tab', currentTab);
        }
        window.location.href = url.toString();
    }

    /* ============================================================
       COPY
    ============================================================ */
    function copyTicketNumber(text) {
        const done = () => showToast('شماره تیکت کپی شد: ' + text);
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(done).catch(() => fallbackCopy(text, done));
        } else {
            fallbackCopy(text, done);
        }
    }
    function fallbackCopy(text, cb) {
        const ta = document.createElement('textarea');
        ta.value = text;
        ta.style.position = 'fixed';
        ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.select();
        try { document.execCommand('copy'); cb(); }
        catch (e) { showToast('خطا در کپی کردن', 'error'); }
        document.body.removeChild(ta);
    }

    /* ============================================================
       PRIORITY
    ============================================================ */
    function togglePriorityDropdown(ticketId) {
        const dropdown = document.getElementById('priority-dropdown-' + ticketId);
        document.querySelectorAll('.priority-dropdown.show').forEach(el => {
            if (el.id !== 'priority-dropdown-' + ticketId) el.classList.remove('show');
        });
        dropdown.classList.toggle('show');
    }
    document.addEventListener('click', function (e) {
        if (!e.target.closest('.priority-selector')) {
            document.querySelectorAll('.priority-dropdown.show').forEach(el => el.classList.remove('show'));
        }
    });

    function updatePriority(ticketId, priority) {
        const item = document.getElementById('ticket-' + ticketId);
        if (item) item.classList.add('updating');

        ajax('', 'action=update_priority&id=' + ticketId + '&priority=' + priority)
        .then(data => {
            if (data.status === 'success') {
                const badge = document.querySelector('#ticket-' + ticketId + ' .priority-badge');
                if (badge) {
                    badge.style.background = data.color;
                    const label = badge.querySelector('.priority-text');
                    if (label) label.textContent = data.label;
                }
                document.querySelectorAll('.priority-dropdown.show').forEach(el => el.classList.remove('show'));
                showToast('اولویت تغییر کرد به ' + data.label);
            } else {
                showToast(data.message || 'خطا', 'error');
            }
        })
        .catch(err => showToast(err.message || 'خطا در بروزرسانی اولویت', 'error'))
        .finally(() => { if (item) item.classList.remove('updating'); });
    }

    /* ============================================================
       STATUS
    ============================================================ */
    function toggleStatus(ticketId, newStatus) {
        const item = document.getElementById('ticket-' + ticketId);
        if (!item) return;
        item.classList.add('updating');

        ajax('', 'action=toggle_status&id=' + ticketId + '&status=' + newStatus)
        .then(data => {
            if (data.status === 'success') {
                const statusKey = data.new_status === 1 ? 'done' : 'pending';
                item.setAttribute('data-status', statusKey);
                item.classList.remove('status-pending', 'status-done');
                item.classList.add('status-' + statusKey);

                const statusBadge = item.querySelector('.ticket-status-badge');
                if (statusBadge) {
                    statusBadge.innerHTML = data.new_status
                        ? '<i class="fas fa-check-circle"></i><span class="status-text">انجام شده</span>'
                        : '<i class="fas fa-clock"></i><span class="status-text">انجام نشده</span>';
                    statusBadge.setAttribute('onclick', 'toggleStatus(' + ticketId + ', ' + (data.new_status ? 0 : 1) + ')');
                }

                const actions = item.querySelector('.ticket-actions');
                if (actions) {
                    if (data.new_status) {
                        actions.innerHTML = '<button type="button" class="btn btn-danger btn-sm" onclick="toggleStatus(' + ticketId + ', 0)"><i class="fas fa-undo-alt"></i> بازگشت</button>';
                    } else {
                        actions.innerHTML = '<button type="button" class="btn btn-success btn-sm" onclick="toggleStatus(' + ticketId + ', 1)"><i class="fas fa-check"></i> انجام شد</button>';
                    }
                }

                updateCounters();
                applyVisibility();
                showToast(data.new_status ? 'تیکت انجام شد' : 'تیکت به لیست انجام نشده بازگشت');
            } else {
                showToast(data.message || 'خطا', 'error');
            }
        })
        .catch(err => showToast(err.message || 'خطا در تغییر وضعیت', 'error'))
        .finally(() => { if (item) item.classList.remove('updating'); });
    }

    function updateCounters() {
        const pending = document.querySelectorAll('.ticket-item[data-status="pending"]').length;
        const done = document.querySelectorAll('.ticket-item[data-status="done"]').length;
        const pc = document.getElementById('pendingCount');
        const dc = document.getElementById('doneCount');
        if (pc) pc.textContent = pending;
        if (dc) dc.textContent = done;
    }

    /* ============================================================
       SUBJECT
    ============================================================ */
    function saveSubject(ticketId) {
        const input = document.getElementById('subject-' + ticketId);
        const subject = input.value.trim();
        const original = input.dataset.original || '';

        if (subject === original) return;

        ajax('', 'action=update_subject&id=' + ticketId + '&subject=' + encodeURIComponent(subject))
        .then(data => {
            if (data.status === 'success') {
                input.dataset.original = subject;
                const item = document.getElementById('ticket-' + ticketId);
                if (item) {
                    const number = (item.querySelector('.ticket-number')?.textContent || '').trim();
                    const link = (item.querySelector('.task-link-display')?.textContent || '').trim();
                    item.setAttribute('data-search', (number + ' ' + subject + ' ' + link).toLowerCase());
                }
                showToast('عنوان ذخیره شد');
            } else {
                showToast(data.message || 'خطا', 'error');
                input.value = original;
            }
        })
        .catch(err => {
            showToast(err.message || 'خطا در ذخیره عنوان', 'error');
            input.value = original;
        });
    }

    /* ============================================================
       TASK LINK
    ============================================================ */
    function editTaskLink(ticketId) {
        const wrapper = document.getElementById('task-link-wrapper-' + ticketId);
        const displayElements = wrapper.querySelectorAll('.task-link-display, .task-link-empty, .task-link-edit-btn');
        const editForm = document.getElementById('task-link-edit-' + ticketId);
        const input = document.getElementById('task-link-input-' + ticketId);
        displayElements.forEach(el => el.style.display = 'none');
        editForm.style.display = 'flex';
        input.focus();
        input.select();
    }

    function saveTaskLink(ticketId) {
        const input = document.getElementById('task-link-input-' + ticketId);
        const taskLink = input.value.trim();

        ajax('', 'action=update_task_link&id=' + ticketId + '&task_link=' + encodeURIComponent(taskLink))
        .then(data => {
            if (data.status === 'success') {
                const wrapper = document.getElementById('task-link-wrapper-' + ticketId);
                wrapper.querySelectorAll('.task-link-display, .task-link-empty, .task-link-edit-btn').forEach(el => el.remove());

                const editForm = document.getElementById('task-link-edit-' + ticketId);
                const label = wrapper.querySelector('.task-link-label');

                if (data.has_link) {
                    const displayHtml = `
                        <a href="${escapeHtml(data.task_link)}" target="_blank" class="task-link-display" id="task-link-display-${ticketId}">
                            <i class="fas fa-external-link-alt"></i> ${escapeHtml(data.task_link)}
                        </a>
                        <button type="button" class="task-link-edit-btn" onclick="editTaskLink(${ticketId})" title="ویرایش لینک">
                            <i class="fas fa-pen"></i>
                        </button>
                    `;
                    label.insertAdjacentHTML('afterend', displayHtml);
                } else {
                    const emptyHtml = `
                        <span class="task-link-empty" onclick="editTaskLink(${ticketId})">
                            <i class="fas fa-plus-circle"></i> افزودن لینک تسک
                        </span>
                    `;
                    label.insertAdjacentHTML('afterend', emptyHtml);
                }

                editForm.style.display = 'none';

                const item = document.getElementById('ticket-' + ticketId);
                if (item) {
                    const number = (item.querySelector('.ticket-number')?.textContent || '').trim();
                    const subject = item.querySelector('.ticket-subject')?.value || '';
                    item.setAttribute('data-search', (number + ' ' + subject + ' ' + taskLink).toLowerCase());
                }

                showToast('لینک تسک ذخیره شد');
            } else {
                showToast(data.message || 'خطا', 'error');
            }
        })
        .catch(err => showToast(err.message || 'خطا در ذخیره لینک', 'error'));
    }

    function cancelTaskLink(ticketId) {
        const wrapper = document.getElementById('task-link-wrapper-' + ticketId);
        const displayElements = wrapper.querySelectorAll('.task-link-display, .task-link-empty, .task-link-edit-btn');
        const editForm = document.getElementById('task-link-edit-' + ticketId);
        editForm.style.display = 'none';
        displayElements.forEach(el => el.style.display = '');
    }

    /* ============================================================
       COMMENTS
    ============================================================ */
    function addComment(ticketId) {
        const input = document.getElementById('comment-input-' + ticketId);
        const btn = document.getElementById('add-comment-btn-' + ticketId);
        const comment = input.value.trim();
        if (!comment) { showToast('متن کامنت نمی‌تواند خالی باشد!', 'error'); return; }

        input.disabled = true;
        if (btn) btn.disabled = true;

        ajax('', 'action=add_comment&ticket_id=' + ticketId + '&comment=' + encodeURIComponent(comment))
        .then(data => {
            if (data.status === 'success') {
                let list = document.getElementById('comments-' + ticketId);
                if (!list) {
                    const section = document.querySelector('#ticket-' + ticketId + ' .comments-section');
                    list = document.createElement('div');
                    list.className = 'comments-list';
                    list.id = 'comments-' + ticketId;
                    section.insertBefore(list, section.querySelector('.comment-input-wrapper'));
                } else {
                    list.style.display = '';
                }

                const commentEl = document.createElement('div');
                commentEl.className = 'comment-item newly-added';
                commentEl.id = 'comment-' + data.comment_id;
                commentEl.innerHTML = `
                    <span class="comment-text">${escapeHtml(data.comment).replace(/\n/g, '<br>')}</span>
                    <span class="comment-meta">
                        ${escapeHtml(data.time)}
                        <button type="button" class="delete-comment" onclick="deleteComment(${data.comment_id}, ${ticketId})" title="حذف کامنت">
                            <i class="fas fa-times"></i>
                        </button>
                    </span>
                `;
                list.appendChild(commentEl);

                const countEl = document.getElementById('comments-count-' + ticketId);
                if (countEl) countEl.textContent = '(' + data.count + ')';

                input.value = '';
                input.focus();
                showToast('کامنت ثبت شد');
            } else {
                showToast(data.message || 'خطا در ثبت کامنت', 'error');
            }
        })
        .catch(err => showToast(err.message || 'خطا در افزودن کامنت', 'error'))
        .finally(() => {
            input.disabled = false;
            if (btn) btn.disabled = false;
        });
    }

    function deleteComment(commentId, ticketId) {
        if (!confirm('آیا از حذف این کامنت مطمئن هستید؟')) return;

        ajax('', 'action=delete_comment&comment_id=' + commentId)
        .then(data => {
            if (data.status === 'success') {
                const commentEl = document.getElementById('comment-' + commentId);
                if (commentEl) {
                    commentEl.style.opacity = '0';
                    commentEl.style.transform = 'translateX(-20px)';
                    setTimeout(() => {
                        commentEl.remove();
                        const list = document.getElementById('comments-' + ticketId);
                        if (list) {
                            const count = list.querySelectorAll('.comment-item').length;
                            const countEl = document.getElementById('comments-count-' + ticketId);
                            if (countEl) countEl.textContent = '(' + count + ')';
                            if (count === 0) list.style.display = 'none';
                        }
                    }, 250);
                }
                showToast('کامنت حذف شد');
            } else {
                showToast(data.message || 'خطا', 'error');
            }
        })
        .catch(err => showToast(err.message || 'خطا در حذف کامنت', 'error'));
    }

    /* ============================================================
       UTILS
    ============================================================ */
    function escapeHtml(text) {
        if (text === null || text === undefined) return '';
        const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
        return String(text).replace(/[&<>"']/g, m => map[m]);
    }

    /* ============================================================
       KEYBOARD
    ============================================================ */
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            document.querySelectorAll('.priority-dropdown.show').forEach(el => el.classList.remove('show'));
            closeSidebar();
        }
        if (e.key === 'Enter' && e.target.matches('.task-link-input')) {
            const ticketId = e.target.id.replace('task-link-input-', '');
            saveTaskLink(ticketId);
        }
        if (e.key === 'Escape' && e.target.matches('.task-link-input')) {
            const ticketId = e.target.id.replace('task-link-input-', '');
            cancelTaskLink(ticketId);
        }
    });

    /* ============================================================
       SIDEBAR
    ============================================================ */
    function toggleSidebar() {
        const sidebar = document.getElementById('appSidebar');
        const overlay = document.getElementById('sidebarOverlay');
        const btn = document.getElementById('hamburgerBtn');
        if (!sidebar) return;

        if (sidebar.classList.contains('open')) {
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
        if (!sidebar) return;
        sidebar.classList.remove('open');
        if (overlay) overlay.classList.remove('active');
        if (btn) btn.classList.remove('active');
        document.body.style.overflow = '';
    }

    let resizeTimer;
    window.addEventListener('resize', function () {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(function () {
            if (window.innerWidth > 900) closeSidebar();
        }, 150);
    });

    /* ============================================================
       INIT
    ============================================================ */
    document.addEventListener('DOMContentLoaded', function () {
        applyVisibility();
    });
</script>

</body>
</html>