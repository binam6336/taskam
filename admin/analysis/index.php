<?php


error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../core/Auth.php';
require_once __DIR__ . '/../../database/Database.php';

$db = Database::getInstance();

try {
    $db->exec("SET NAMES 'utf8mb4'");
    $db->exec("SET CHARACTER SET utf8mb4");
    $db->exec("SET character_set_connection = utf8mb4");
} catch (PDOException $e) {}

// ✅ چک ادمین بودن
if (!Auth::check() || ($_SESSION['user_role'] ?? '') !== 'admin') {
    header('Location: ../auth/login.php');
    exit;
}

$currentUserId = (int)$_SESSION['user_id'];

$firstAdminStmt = $db->query("SELECT id FROM users WHERE role = 'admin' ORDER BY id ASC LIMIT 1");
$firstAdminId = (int)$firstAdminStmt->fetchColumn();
$isSuperAdmin = ($currentUserId === $firstAdminId);

$currentUserStmt = $db->prepare("SELECT * FROM users WHERE id = ?");
$currentUserStmt->execute([$currentUserId]);
$currentUser = $currentUserStmt->fetch(PDO::FETCH_ASSOC);

$currentPermissions = [];
if ($isSuperAdmin) {
    $currentPermissions = ['add_user', 'delete_user', 'toggle_status', 'edit_user', 'view_analytics'];
} else {
    $perms = json_decode($currentUser['admin_permissions'] ?? '[]', true);
    if (!is_array($perms)) $perms = [];
    $currentPermissions = $perms;
}
function hasAdminPermission($p) {
    global $currentPermissions;
    return in_array($p, $currentPermissions, true);
}

// ================== واکشی آمار کلی ==================
$uTotal   = (int)$db->query("SELECT COUNT(*) FROM users")->fetchColumn();
$uActive  = (int)$db->query("SELECT COUNT(*) FROM users WHERE status = 'active'")->fetchColumn();
$uPending = (int)$db->query("SELECT COUNT(*) FROM users WHERE status = 'pending'")->fetchColumn();
$uBlocked = (int)$db->query("SELECT COUNT(*) FROM users WHERE status = 'blocked'")->fetchColumn();
$uAdmin   = (int)$db->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();

$tTotal   = (int)$db->query("SELECT COUNT(*) FROM tasks")->fetchColumn();
$tDone    = (int)$db->query("SELECT COUNT(*) FROM tasks WHERE is_completed = 1")->fetchColumn();
$tPending = (int)$db->query("SELECT COUNT(*) FROM tasks WHERE is_completed = 0")->fetchColumn();
$tHigh    = (int)$db->query("SELECT COUNT(*) FROM tasks WHERE priority = 'high'")->fetchColumn();
$tMedium  = (int)$db->query("SELECT COUNT(*) FROM tasks WHERE priority = 'medium'")->fetchColumn();
$tLow     = (int)$db->query("SELECT COUNT(*) FROM tasks WHERE priority = 'low'")->fetchColumn();

$tkTotal   = (int)$db->query("SELECT COUNT(*) FROM tickets")->fetchColumn();
$tkDone    = (int)$db->query("SELECT COUNT(*) FROM tickets WHERE status = 1")->fetchColumn();
$tkPending = (int)$db->query("SELECT COUNT(*) FROM tickets WHERE status = 0")->fetchColumn();
$tkVIP     = (int)$db->query("SELECT COUNT(*) FROM tickets WHERE is_vip = 1")->fetchColumn();

$nTotal = (int)$db->query("SELECT COUNT(*) FROM task_notes")->fetchColumn();
$txTotal = (int)$db->query("SELECT COUNT(*) FROM texts")->fetchColumn();

$crTotal = (int)$db->query("SELECT COUNT(*) FROM call_requests")->fetchColumn();
$crNew   = (int)$db->query("SELECT COUNT(*) FROM call_requests WHERE status = 0")->fetchColumn();
$crDone  = (int)$db->query("SELECT COUNT(*) FROM call_requests WHERE status = 1")->fetchColumn();

$pTotal    = (int)$db->query("SELECT COUNT(*) FROM projects")->fetchColumn();
$pmTotal   = (int)$db->query("SELECT COUNT(*) FROM project_members")->fetchColumn();

$cmTotal = (int)$db->query("SELECT COUNT(*) FROM colleague_messages")->fetchColumn();
$cmUnread = (int)$db->query("SELECT COUNT(*) FROM colleague_messages WHERE is_read = 0")->fetchColumn();

// ================== آمار روزانه (۷ روز اخیر) ==================
$trendDays = [];
$trendTasks = [];
$trendTickets = [];
$trendUsers = [];

for ($i = 6; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $trendDays[] = date('m/d', strtotime("-$i days"));

    $stmt = $db->prepare("SELECT COUNT(*) FROM tasks WHERE DATE(created_at) = ?");
    $stmt->execute([$date]);
    $trendTasks[] = (int)$stmt->fetchColumn();

    $stmt = $db->prepare("SELECT COUNT(*) FROM tickets WHERE DATE(created_at) = ?");
    $stmt->execute([$date]);
    $trendTickets[] = (int)$stmt->fetchColumn();

    $stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE DATE(created_at) = ?");
    $stmt->execute([$date]);
    $trendUsers[] = (int)$stmt->fetchColumn();
}

// ================== آمار ماهانه (۶ ماه اخیر) ==================
$monthLabels = [];
$monthTasks = [];
for ($i = 5; $i >= 0; $i--) {
    $start = date('Y-m-01', strtotime("-$i months"));
    $end   = date('Y-m-t', strtotime("-$i months"));
    $monthLabels[] = date('m/Y', strtotime("-$i months"));

    $stmt = $db->prepare("SELECT COUNT(*) FROM tasks WHERE DATE(created_at) BETWEEN ? AND ?");
    $stmt->execute([$start, $end]);
    $monthTasks[] = (int)$stmt->fetchColumn();
}

// ================== ۱۰ کاربر برتر ==================
$topUsers = [];
try {
    $stmt = $db->query("
        SELECT u.id, u.first_name, u.last_name, u.mobile, u.avatar,
               COUNT(t.id) AS done_count
        FROM users u
        LEFT JOIN tasks t ON t.assignee_id = u.id AND t.is_completed = 1
        GROUP BY u.id
        ORDER BY done_count DESC, u.id ASC
        LIMIT 10
    ");
    $topUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}

// ================== ۱۰ پروژه برتر ==================
$topProjects = [];
try {
    $stmt = $db->query("
        SELECT p.id, p.title, p.profile_image,
               COUNT(t.id) AS task_count
        FROM projects p
        LEFT JOIN tasks t ON t.project_id = p.id
        GROUP BY p.id
        ORDER BY task_count DESC, p.id ASC
        LIMIT 10
    ");
    $topProjects = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}

// ================== توزیع موضوعات ==================
$topSubjects = [];
try {
    $stmt = $db->query("
        SELECT s.title, COUNT(t.id) AS cnt
        FROM subjects s
        LEFT JOIN tasks t ON t.subject_id = s.id
        GROUP BY s.id
        ORDER BY cnt DESC
        LIMIT 8
    ");
    $topSubjects = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}

$subjectLabels = array_column($topSubjects, 'title');
$subjectCounts = array_map('intval', array_column($topSubjects, 'cnt'));

$displayUser = $_SESSION['user_mobile'] ?? $_SESSION['mobile'] ?? $_SESSION['username'] ?? 'ادمین';

// درصدها
$taskDoneRate = $tTotal > 0 ? round(($tDone / $tTotal) * 100) : 0;
$tkDoneRate   = $tkTotal > 0 ? round(($tkDone / $tkTotal) * 100) : 0;
$uActiveRate  = $uTotal > 0 ? round(($uActive / $uTotal) * 100) : 0;
$crDoneRate   = $crTotal > 0 ? round(($crDone / $crTotal) * 100) : 0;
$cmReadRate   = $cmTotal > 0 ? round((($cmTotal - $cmUnread) / $cmTotal) * 100) : 0;
$uAdminRate   = $uTotal > 0 ? round(($uAdmin / $uTotal) * 100) : 0;
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <title>داشبورد — Admin Console</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="icon" href="https://img.icons8.com/color/48/dashboard-layout.png" type="image/png">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <style>
        :root {
            /* پس‌زمینه تیره با ته‌رنگ بنفش */
            --bg: #0a0714;
            --bg-2: #0f0b1d;
            --surface: #161127;
            --surface-2: #1c1631;
            --surface-3: #241c3d;

            --border: rgba(168, 85, 247, 0.10);
            --border-2: rgba(168, 85, 247, 0.22);

            --text: #f5f3ff;
            --text-2: #b8aede;
            --text-3: #6b6288;

            --violet: #a855f7;
            --violet-2: #8b5cf6;
            --violet-3: #c084fc;
            --pink: #ec4899;
            --pink-2: #f472b6;
            --teal: #2dd4bf;
            --green: #34d399;
            --amber: #fbbf24;
            --red: #f87171;
            --blue: #60a5fa;

            --grad-1: linear-gradient(135deg, #a855f7 0%, #ec4899 100%);
            --grad-2: linear-gradient(135deg, #8b5cf6 0%, #ec4899 100%);
            --grad-3: linear-gradient(135deg, #a78bfa 0%, #f472b6 100%);

            --radius: 18px;
            --radius-lg: 24px;
            --radius-sm: 12px;

            --shadow: 0 12px 32px -12px rgba(0,0,0,0.7);
            --glow-violet: 0 0 30px -8px rgba(168, 85, 247, 0.55);
            --glow-pink: 0 0 30px -8px rgba(236, 72, 153, 0.5);

            --t: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }
        html, body { max-width: 100%; overflow-x: hidden; }

        body {
            background:
                radial-gradient(ellipse 80% 50% at 50% -10%, rgba(168, 85, 247, 0.12), transparent 60%),
                radial-gradient(ellipse 60% 40% at 100% 100%, rgba(236, 72, 153, 0.06), transparent 60%),
                var(--bg);
            color: var(--text);
            font-family: Tahoma, "Segoe UI", sans-serif;
            display: flex;
            min-height: 100vh;
            direction: rtl;
            font-size: 14px;
        }

        /* ============ ICON SIDEBAR ============ */
        .sidebar {
            width: 76px;
            background: var(--bg-2);
            display: flex; flex-direction: column;
            align-items: center;
            padding: 20px 0;
            gap: 6px;
            border-left: 1px solid var(--border);
            flex-shrink: 0;
            z-index: 5;
        }

        .sidebar__logo {
            width: 44px; height: 44px;
            border-radius: 14px;
            background: var(--grad-2);
            display: flex; align-items: center; justify-content: center;
            font-size: 1rem; color: #fff;
            box-shadow: var(--glow-violet);
            margin-bottom: 22px;
        }

        .sidebar__nav {
            display: flex; flex-direction: column;
            gap: 8px;
            flex: 1;
        }

        .nav-icon {
            width: 46px; height: 46px;
            border-radius: 14px;
            display: flex; align-items: center; justify-content: center;
            color: var(--text-3);
            font-size: 1.05rem;
            text-decoration: none;
            transition: var(--t);
            position: relative;
        }
        .nav-icon:hover {
            background: var(--surface);
            color: var(--violet-3);
        }
        .nav-icon.active {
            background: var(--grad-2);
            color: #fff;
            box-shadow: 0 8px 20px -6px rgba(168, 85, 247, 0.6);
        }
        .nav-icon.active::after {
            content: '';
            position: absolute;
            inset: -1px;
            border-radius: 14px;
            background: linear-gradient(135deg, rgba(255,255,255,0.2), transparent);
            pointer-events: none;
        }

        .nav-icon.logout { color: var(--red); }
        .nav-icon.logout:hover { background: rgba(248, 113, 113, 0.1); }

        /* ============ MAIN ============ */
        .main {
            flex: 1;
            display: flex;
            flex-direction: column;
            min-width: 0;
            overflow: hidden;
        }

        /* ============ TOPBAR ============ */
        .topbar {
            padding: 22px 32px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            flex-shrink: 0;
        }

        .topbar__title {
            display: flex; flex-direction: column; gap: 4px;
        }
        .topbar__title h1 {
            font-size: 1.35rem; font-weight: bold; color: #fff;
            letter-spacing: -0.3px;
        }
        .topbar__title span {
            font-size: 0.75rem; color: var(--text-3);
        }

        .topbar__actions {
            display: flex; align-items: center; gap: 12px;
        }

        .search-box {
            display: flex; align-items: center;
            gap: 10px;
            background: var(--surface);
            border: 1px solid var(--border);
            padding: 10px 16px;
            border-radius: 30px;
            min-width: 240px;
            transition: var(--t);
        }
        .search-box:focus-within {
            border-color: var(--border-2);
            box-shadow: 0 0 0 4px rgba(168, 85, 247, 0.08);
        }
        .search-box i { color: var(--text-3); font-size: 0.85rem; }
        .search-box input {
            background: transparent; border: none; outline: none;
            color: var(--text); font-family: inherit; font-size: 0.82rem;
            flex: 1; min-width: 0;
        }
        .search-box input::placeholder { color: var(--text-3); }

        .icon-btn {
            width: 42px; height: 42px;
            border-radius: 12px;
            background: var(--surface);
            border: 1px solid var(--border);
            color: var(--text-2);
            display: flex; align-items: center; justify-content: center;
            cursor: pointer;
            transition: var(--t);
            font-size: 0.9rem;
        }
        .icon-btn:hover { color: var(--violet-3); border-color: var(--border-2); }

        .avatar-btn {
            width: 42px; height: 42px;
            border-radius: 12px;
            background: var(--grad-2);
            display: flex; align-items: center; justify-content: center;
            color: #fff; font-weight: bold; font-size: 0.9rem;
            border: none; cursor: pointer;
            box-shadow: 0 6px 18px -6px rgba(168, 85, 247, 0.7);
            transition: var(--t);
            position: relative;
        }
        .avatar-btn::after {
            content: ''; position: absolute; bottom: -1px; left: -1px;
            width: 12px; height: 12px;
            background: var(--green);
            border: 2px solid var(--bg);
            border-radius: 50%;
        }
        .avatar-btn:hover { transform: translateY(-2px); }

        /* ============ CONTENT ============ */
        .content {
            flex: 1;
            padding: 0 32px 32px;
            overflow-y: auto;
        }
        .content::-webkit-scrollbar { width: 6px; }
        .content::-webkit-scrollbar-thumb { background: var(--surface-3); border-radius: 3px; }

        /* ============ DASHBOARD GRID ============ */
        .dash {
            display: grid;
            grid-template-columns: 1fr 340px;
            gap: 20px;
            align-items: start;
        }

        .dash__main {
            display: flex;
            flex-direction: column;
            gap: 20px;
            min-width: 0;
        }

        .dash__side {
            display: flex;
            flex-direction: column;
            gap: 20px;
            position: sticky;
            top: 0;
        }

        /* ============ CARD ============ */
        .card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            padding: 22px 24px;
            transition: var(--t);
        }
        .card:hover { border-color: var(--border-2); }

        /* ============ KPI ROW ============ */
        .kpi-row {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 16px;
        }

        .kpi {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            padding: 22px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            transition: var(--t);
            position: relative;
            overflow: hidden;
        }
        .kpi:hover { border-color: var(--border-2); transform: translateY(-2px); }

        .kpi__info { display: flex; flex-direction: column; gap: 8px; min-width: 0; }

        .kpi__label {
            font-size: 0.72rem; color: var(--text-3);
            font-weight: bold; letter-spacing: 0.4px;
            display: flex; align-items: center; gap: 6px;
        }
        .kpi__label i { color: var(--violet-3); font-size: 0.7rem; }

        .kpi__value {
            font-size: 1.9rem;
            font-weight: bold;
            color: #fff;
            line-height: 1;
            letter-spacing: -1px;
        }

        .kpi__meta {
            font-size: 0.7rem;
            color: var(--text-3);
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .kpi__meta .pill {
            padding: 2px 8px; border-radius: 6px;
            font-weight: bold; font-size: 0.66rem;
        }
        .kpi__meta .up {
            background: rgba(52, 211, 153, 0.12);
            color: var(--green);
        }
        .kpi__meta .down {
            background: rgba(248, 113, 113, 0.12);
            color: var(--red);
        }

        /* Donut ring */
        .ring {
            width: 72px; height: 72px;
            position: relative;
            flex-shrink: 0;
        }
        .ring svg { transform: rotate(-90deg); width: 100%; height: 100%; }
        .ring__bg { fill: none; stroke: rgba(255,255,255,0.06); stroke-width: 7; }
        .ring__fg {
            fill: none; stroke-width: 7; stroke-linecap: round;
            transition: stroke-dashoffset 1.2s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .ring__label {
            position: absolute; inset: 0;
            display: flex; align-items: center; justify-content: center;
            font-size: 0.85rem; font-weight: bold; color: #fff;
        }

        /* ============ MAIN CHART ============ */
        .chart-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            padding: 24px 26px 18px;
            transition: var(--t);
        }
        .chart-card:hover { border-color: var(--border-2); }

        .chart-card__head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        .chart-card__title {
            display: flex; align-items: center; gap: 10px;
            font-size: 0.95rem; font-weight: bold; color: #fff;
        }
        .chart-card__title-icon {
            width: 34px; height: 34px;
            border-radius: 10px;
            background: rgba(168, 85, 247, 0.12);
            color: var(--violet-3);
            display: flex; align-items: center; justify-content: center;
            font-size: 0.85rem;
        }

        .chart-card__legend {
            display: flex; gap: 16px; align-items: center;
            font-size: 0.72rem; color: var(--text-2);
            flex-wrap: wrap;
        }
        .legend-item {
            display: flex; align-items: center; gap: 6px;
        }
        .legend-dot {
            width: 8px; height: 8px; border-radius: 50%;
        }
        .legend-dot.violet { background: var(--violet); box-shadow: 0 0 8px var(--violet); }
        .legend-dot.pink { background: var(--pink); box-shadow: 0 0 8px var(--pink); }
        .legend-dot.teal { background: var(--teal); box-shadow: 0 0 8px var(--teal); }

        .chart-wrap { position: relative; height: 280px; }

        /* ============ BOTTOM ROW (progress cards) ============ */
        .bottom-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }

        .progress-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            padding: 22px;
            transition: var(--t);
        }
        .progress-card:hover { border-color: var(--border-2); }

        .progress-card__head {
            display: flex; align-items: center; justify-content: space-between;
            margin-bottom: 14px;
        }
        .progress-card__label {
            font-size: 0.75rem; color: var(--text-3);
            font-weight: bold; letter-spacing: 0.3px;
            display: flex; align-items: center; gap: 8px;
        }
        .progress-card__label i { color: var(--violet-3); }
        .progress-card__percent {
            font-size: 0.8rem; font-weight: bold; color: var(--violet-3);
        }

        .progress-card__value {
            font-size: 1.9rem; font-weight: bold; color: #fff;
            line-height: 1; letter-spacing: -1px;
            display: flex; align-items: baseline; gap: 8px;
        }
        .progress-card__value small {
            font-size: 0.7rem; color: var(--text-3); font-weight: normal;
            letter-spacing: 0;
        }

        .progress-card__bar {
            margin-top: 16px;
            height: 6px;
            border-radius: 3px;
            background: rgba(255,255,255,0.06);
            overflow: hidden;
        }
        .progress-card__bar > div {
            height: 100%;
            border-radius: 3px;
            transition: width 1.2s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .bar-violet { background: linear-gradient(90deg, #a855f7, #ec4899); }
        .bar-teal   { background: linear-gradient(90deg, #2dd4bf, #34d399); }
        .bar-pink   { background: linear-gradient(90deg, #ec4899, #f472b6); }
        .bar-amber  { background: linear-gradient(90deg, #fbbf24, #fb923c); }

        /* ============ GRADIENT CARD (side) ============ */
        .grad-card {
            background: linear-gradient(135deg, #a855f7 0%, #7c3aed 45%, #ec4899 100%);
            border-radius: var(--radius-lg);
            padding: 24px;
            position: relative;
            overflow: hidden;
            box-shadow: 0 20px 40px -18px rgba(168, 85, 247, 0.6);
        }
        .grad-card::before {
            content: ''; position: absolute; top: -50%; right: -30%;
            width: 260px; height: 260px;
            background: radial-gradient(circle, rgba(255,255,255,0.25), transparent 65%);
            pointer-events: none;
        }
        .grad-card::after {
            content: ''; position: absolute; bottom: -30%; left: -20%;
            width: 220px; height: 220px;
            background: radial-gradient(circle, rgba(255,255,255,0.15), transparent 65%);
            pointer-events: none;
        }

        .grad-card__head {
            display: flex; align-items: center; justify-content: space-between;
            position: relative; z-index: 1;
            margin-bottom: 22px;
        }
        .grad-card__label {
            font-size: 0.75rem; color: rgba(255,255,255,0.85);
            font-weight: bold; letter-spacing: 0.4px;
        }
        .grad-card__head i {
            font-size: 0.9rem; color: rgba(255,255,255,0.9);
        }

        .grad-card__value {
            font-size: 2.4rem;
            font-weight: bold;
            color: #fff;
            line-height: 1;
            letter-spacing: -1.5px;
            position: relative; z-index: 1;
            margin-bottom: 22px;
        }
        .grad-card__value small {
            font-size: 0.75rem; font-weight: normal;
            letter-spacing: 0; opacity: 0.85;
            margin-right: 8px;
        }

        .grad-card__stats {
            display: grid; grid-template-columns: 1fr 1fr;
            gap: 12px;
            position: relative; z-index: 1;
            padding-top: 18px;
            border-top: 1px solid rgba(255,255,255,0.18);
        }
        .grad-card__stat {
            display: flex; flex-direction: column; gap: 3px;
        }
        .grad-card__stat span:first-child {
            font-size: 0.66rem; color: rgba(255,255,255,0.75);
            font-weight: bold; letter-spacing: 0.3px;
        }
        .grad-card__stat span:last-child {
            font-size: 1.1rem; color: #fff; font-weight: bold;
        }

        /* ============ ACTIVITY LIST ============ */
        .activity-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            padding: 22px;
        }
        .activity-card__head {
            display: flex; align-items: center; justify-content: space-between;
            margin-bottom: 16px;
        }
        .activity-card__title {
            font-size: 0.9rem; font-weight: bold; color: #fff;
            display: flex; align-items: center; gap: 8px;
        }
        .activity-card__title i { color: var(--violet-3); }
        .activity-card__more {
            font-size: 0.72rem; color: var(--text-3);
            text-decoration: none; transition: var(--t);
        }
        .activity-card__more:hover { color: var(--violet-3); }

        .activity-list { display: flex; flex-direction: column; gap: 4px; }

        .activity-item {
            display: flex; align-items: center; gap: 12px;
            padding: 10px 10px;
            border-radius: var(--radius-sm);
            transition: var(--t);
        }
        .activity-item:hover { background: var(--surface-2); }

        .activity-item__avatar {
            width: 38px; height: 38px;
            border-radius: 11px;
            background: var(--surface-2);
            border: 1px solid var(--border);
            display: flex; align-items: center; justify-content: center;
            font-weight: bold; font-size: 0.85rem;
            color: var(--violet-3);
            flex-shrink: 0;
            overflow: hidden;
        }
        .activity-item__avatar img {
            width: 100%; height: 100%; object-fit: cover;
        }
        .activity-item__avatar.rank-1 {
            background: linear-gradient(135deg, #fbbf24, #f59e0b);
            color: #fff; border-color: transparent;
            box-shadow: 0 4px 12px -4px rgba(251, 191, 36, 0.6);
        }
        .activity-item__avatar.rank-2 {
            background: linear-gradient(135deg, #cbd5e1, #94a3b8);
            color: #1e293b; border-color: transparent;
        }
        .activity-item__avatar.rank-3 {
            background: linear-gradient(135deg, #fb923c, #c2410c);
            color: #fff; border-color: transparent;
        }

        .activity-item__info { flex: 1; min-width: 0; }
        .activity-item__name {
            font-size: 0.82rem; font-weight: bold; color: #fff;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .activity-item__meta {
            font-size: 0.68rem; color: var(--text-3);
            margin-top: 2px;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .activity-item__value {
            font-size: 0.8rem;
            font-weight: bold;
            color: var(--violet-3);
            padding: 4px 10px;
            background: rgba(168, 85, 247, 0.1);
            border-radius: 8px;
            flex-shrink: 0;
        }

        /* ============ SECONDARY CHARTS ============ */
        .charts-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }
        .chart-mini {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            padding: 22px;
            transition: var(--t);
        }
        .chart-mini:hover { border-color: var(--border-2); }
        .chart-mini__title {
            display: flex; align-items: center; gap: 10px;
            font-size: 0.88rem; font-weight: bold; color: #fff;
            margin-bottom: 16px;
            padding-bottom: 14px;
            border-bottom: 1px solid var(--border);
        }
        .chart-mini__title i {
            color: var(--violet-3);
            font-size: 0.85rem;
        }
        .chart-mini__wrap { position: relative; height: 220px; }

        /* ============ EMPTY STATE ============ */
        .empty {
            text-align: center; padding: 40px 20px;
            color: var(--text-3); font-size: 0.8rem;
        }

        /* ============ RESPONSIVE ============ */
        @media (max-width: 1200px) {
            .dash { grid-template-columns: 1fr; }
            .dash__side { position: static; }
        }
        @media (max-width: 900px) {
            .sidebar { width: 64px; }
            .nav-icon { width: 42px; height: 42px; }
            .topbar { padding: 18px 20px; }
            .content { padding: 0 20px 24px; }
            .search-box { min-width: auto; }
            .search-box input { display: none; }
            .kpi-row { grid-template-columns: 1fr 1fr; }
            .kpi-row .kpi:last-child { grid-column: span 2; }
            .charts-grid, .bottom-row { grid-template-columns: 1fr; }
        }
        @media (max-width: 600px) {
            .sidebar { width: 58px; padding: 14px 0; }
            .sidebar__logo { width: 38px; height: 38px; margin-bottom: 16px; }
            .nav-icon { width: 38px; height: 38px; font-size: 0.92rem; border-radius: 12px; }
            .topbar { padding: 14px 16px; }
            .topbar__title h1 { font-size: 1.05rem; }
            .topbar__title span { display: none; }
            .icon-btn { width: 38px; height: 38px; }
            .avatar-btn { width: 38px; height: 38px; }
            .content { padding: 0 14px 20px; }
            .kpi-row { grid-template-columns: 1fr; gap: 12px; }
            .kpi-row .kpi:last-child { grid-column: span 1; }
            .kpi { padding: 18px; }
            .kpi__value { font-size: 1.5rem; }
            .chart-card, .card { padding: 18px; }
            .chart-wrap { height: 220px; }
            .grad-card { padding: 20px; }
            .grad-card__value { font-size: 2rem; }
        }
        @media (prefers-reduced-motion: reduce) {
            * { transition: none !important; animation: none !important; }
        }
    </style>
</head>
<body>

<!-- ============ SIDEBAR ============ -->
<aside class="sidebar">
    <div class="sidebar__logo">
        <i class="fas fa-crown"></i>
    </div>

    <nav class="sidebar__nav">
        <a href="../admin/index.php" class="nav-icon" title="مدیریت کاربران">
            <i class="fas fa-users-gear"></i>
        </a>
        <a href="index.php" class="nav-icon active" title="داشبورد">
            <i class="fas fa-chart-pie"></i>
        </a>
        <a href="#" class="nav-icon" title="وظایف">
            <i class="fas fa-list-check"></i>
        </a>
        <a href="#" class="nav-icon" title="تیکت‌ها">
            <i class="fas fa-ticket"></i>
        </a>
        <a href="#" class="nav-icon" title="پروژه‌ها">
            <i class="fas fa-diagram-project"></i>
        </a>
        <a href="#" class="nav-icon" title="تنظیمات">
            <i class="fas fa-sliders"></i>
        </a>
    </nav>

    <a href="../auth/login.php?logout=1" class="nav-icon logout" title="خروج">
        <i class="fas fa-sign-out-alt"></i>
    </a>
</aside>

<div class="main">

    <!-- ============ TOPBAR ============ -->
    <header class="topbar">
        <div class="topbar__title">
            <h1>داشبورد</h1>
            <span>خلاصه‌ای از آمار و اطلاعات سیستم</span>
        </div>

        <div class="topbar__actions">
            <div class="search-box">
                <i class="fas fa-search"></i>
                <input type="text" placeholder="جستجو...">
            </div>
            <button class="icon-btn" title="اعلان‌ها">
                <i class="fas fa-bell"></i>
            </button>
            <button class="avatar-btn" title="<?= htmlspecialchars($displayUser) ?>">
                <?php if ($isSuperAdmin): ?>
                    <i class="fas fa-shield-halved"></i>
                <?php else: ?>
                    <?= htmlspecialchars(mb_substr($displayUser, 0, 1, 'UTF-8')) ?>
                <?php endif; ?>
            </button>
        </div>
    </header>

    <!-- ============ CONTENT ============ -->
    <main class="content">
        <div class="dash">

            <!-- ========== MAIN COLUMN ========== -->
            <div class="dash__main">

                <!-- KPI ROW -->
                <div class="kpi-row">

                    <!-- کاربران -->
                    <div class="kpi">
                        <div class="kpi__info">
                            <div class="kpi__label">
                                <i class="fas fa-users"></i>
                                کاربران
                            </div>
                            <div class="kpi__value"><?= number_format($uTotal) ?></div>
                            <div class="kpi__meta">
                                <span class="pill up"><?= $uActive ?> فعال</span>
                                <span><?= $uPending ?> در انتظار</span>
                            </div>
                        </div>
                        <div class="ring">
                            <svg viewBox="0 0 100 100">
                                <circle class="ring__bg" cx="50" cy="50" r="42"></circle>
                                <circle class="ring__fg" cx="50" cy="50" r="42"
                                        stroke="url(#g1)"
                                        stroke-dasharray="263.89"
                                        stroke-dashoffset="<?= 263.89 - (263.89 * $uActiveRate / 100) ?>"></circle>
                                <defs>
                                    <linearGradient id="g1" x1="0" y1="0" x2="1" y2="1">
                                        <stop offset="0%" stop-color="#a855f7"/>
                                        <stop offset="100%" stop-color="#ec4899"/>
                                    </linearGradient>
                                </defs>
                            </svg>
                            <div class="ring__label"><?= $uActiveRate ?>%</div>
                        </div>
                    </div>

                    <!-- وظایف -->
                    <div class="kpi">
                        <div class="kpi__info">
                            <div class="kpi__label">
                                <i class="fas fa-list-check"></i>
                                وظایف
                            </div>
                            <div class="kpi__value"><?= number_format($tTotal) ?></div>
                            <div class="kpi__meta">
                                <span class="pill up"><?= $tDone ?> انجام</span>
                                <span><?= $tPending ?> باقی</span>
                            </div>
                        </div>
                        <div class="ring">
                            <svg viewBox="0 0 100 100">
                                <circle class="ring__bg" cx="50" cy="50" r="42"></circle>
                                <circle class="ring__fg" cx="50" cy="50" r="42"
                                        stroke="url(#g2)"
                                        stroke-dasharray="263.89"
                                        stroke-dashoffset="<?= 263.89 - (263.89 * $taskDoneRate / 100) ?>"></circle>
                                <defs>
                                    <linearGradient id="g2" x1="0" y1="0" x2="1" y2="1">
                                        <stop offset="0%" stop-color="#2dd4bf"/>
                                        <stop offset="100%" stop-color="#34d399"/>
                                    </linearGradient>
                                </defs>
                            </svg>
                            <div class="ring__label"><?= $taskDoneRate ?>%</div>
                        </div>
                    </div>

                    <!-- تیکت‌ها -->
                    <div class="kpi">
                        <div class="kpi__info">
                            <div class="kpi__label">
                                <i class="fas fa-ticket"></i>
                                تیکت‌ها
                            </div>
                            <div class="kpi__value"><?= number_format($tkTotal) ?></div>
                            <div class="kpi__meta">
                                <span class="pill up"><?= $tkDone ?> بسته</span>
                                <span><?= $tkPending ?> باز</span>
                            </div>
                        </div>
                        <div class="ring">
                            <svg viewBox="0 0 100 100">
                                <circle class="ring__bg" cx="50" cy="50" r="42"></circle>
                                <circle class="ring__fg" cx="50" cy="50" r="42"
                                        stroke="url(#g3)"
                                        stroke-dasharray="263.89"
                                        stroke-dashoffset="<?= 263.89 - (263.89 * $tkDoneRate / 100) ?>"></circle>
                                <defs>
                                    <linearGradient id="g3" x1="0" y1="0" x2="1" y2="1">
                                        <stop offset="0%" stop-color="#fbbf24"/>
                                        <stop offset="100%" stop-color="#f472b6"/>
                                    </linearGradient>
                                </defs>
                            </svg>
                            <div class="ring__label"><?= $tkDoneRate ?>%</div>
                        </div>
                    </div>

                </div>

                <!-- MAIN CHART -->
                <div class="chart-card">
                    <div class="chart-card__head">
                        <div class="chart-card__title">
                            <div class="chart-card__title-icon">
                                <i class="fas fa-chart-area"></i>
                            </div>
                            روند ۷ روز اخیر
                        </div>
                        <div class="chart-card__legend">
                            <div class="legend-item">
                                <span class="legend-dot violet"></span> وظایف
                            </div>
                            <div class="legend-item">
                                <span class="legend-dot pink"></span> تیکت‌ها
                            </div>
                            <div class="legend-item">
                                <span class="legend-dot teal"></span> کاربران
                            </div>
                        </div>
                    </div>
                    <div class="chart-wrap">
                        <canvas id="mainChart"></canvas>
                    </div>
                </div>

                <!-- BOTTOM PROGRESS CARDS -->
                <div class="bottom-row">

                    <div class="progress-card">
                        <div class="progress-card__head">
                            <div class="progress-card__label">
                                <i class="fas fa-phone-volume"></i>
                                درخواست تماس
                            </div>
                            <div class="progress-card__percent"><?= $crDoneRate ?>%</div>
                        </div>
                        <div class="progress-card__value">
                            <?= number_format($crTotal) ?>
                            <small>مجموع</small>
                        </div>
                        <div class="progress-card__bar">
                            <div class="bar-violet" style="width: <?= $crDoneRate ?>%"></div>
                        </div>
                    </div>

                    <div class="progress-card">
                        <div class="progress-card__head">
                            <div class="progress-card__label">
                                <i class="fas fa-comments"></i>
                                پیام‌های همکاران
                            </div>
                            <div class="progress-card__percent"><?= $cmReadRate ?>%</div>
                        </div>
                        <div class="progress-card__value">
                            <?= number_format($cmTotal) ?>
                            <small>مجموع</small>
                        </div>
                        <div class="progress-card__bar">
                            <div class="bar-teal" style="width: <?= $cmReadRate ?>%"></div>
                        </div>
                    </div>

                </div>

                <!-- SECONDARY CHARTS -->
                <div class="charts-grid">

                    <div class="chart-mini">
                        <div class="chart-mini__title">
                            <i class="fas fa-chart-pie"></i>
                            وضعیت وظایف
                        </div>
                        <div class="chart-mini__wrap">
                            <canvas id="statusChart"></canvas>
                        </div>
                    </div>

                    <div class="chart-mini">
                        <div class="chart-mini__title">
                            <i class="fas fa-bars-progress"></i>
                            اولویت وظایف
                        </div>
                        <div class="chart-mini__wrap">
                            <canvas id="priorityChart"></canvas>
                        </div>
                    </div>

                </div>

                <div class="charts-grid">

                    <div class="chart-mini">
                        <div class="chart-mini__title">
                            <i class="fas fa-chart-line"></i>
                            روند ۶ ماه اخیر
                        </div>
                        <div class="chart-mini__wrap">
                            <canvas id="monthlyChart"></canvas>
                        </div>
                    </div>

                    <div class="chart-mini">
                        <div class="chart-mini__title">
                            <i class="fas fa-tags"></i>
                            توزیع موضوعات
                        </div>
                        <div class="chart-mini__wrap">
                            <canvas id="subjectChart"></canvas>
                        </div>
                    </div>

                </div>

            </div>

            <!-- ========== SIDE COLUMN ========== -->
            <div class="dash__side">

                <!-- GRADIENT CARD -->
                <div class="grad-card">
                    <div class="grad-card__head">
                        <span class="grad-card__label">وظایف انجام‌شده</span>
                        <i class="fas fa-check-double"></i>
                    </div>
                    <div class="grad-card__value">
                        <?= number_format($tDone) ?>
                        <small>وظیفه</small>
                    </div>
                    <div class="grad-card__stats">
                        <div class="grad-card__stat">
                            <span>کل وظایف</span>
                            <span><?= number_format($tTotal) ?></span>
                        </div>
                        <div class="grad-card__stat">
                            <span>نرخ تکمیل</span>
                            <span><?= $taskDoneRate ?>%</span>
                        </div>
                    </div>
                </div>

                <!-- TOP USERS ACTIVITY -->
                <div class="activity-card">
                    <div class="activity-card__head">
                        <div class="activity-card__title">
                            <i class="fas fa-trophy"></i>
                            کاربران برتر
                        </div>
                        <a href="#" class="activity-card__more">مشاهده همه</a>
                    </div>
                    <div class="activity-list">
                        <?php if (empty($topUsers)): ?>
                            <div class="empty">داده‌ای موجود نیست</div>
                        <?php else: ?>
                            <?php foreach (array_slice($topUsers, 0, 6) as $idx => $u): ?>
                                <?php
                                    $fullName = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''));
                                    if ($fullName === '') $fullName = $u['mobile'];
                                    $initial = mb_substr(trim($u['first_name'] ?: $u['mobile']), 0, 1, 'UTF-8');
                                    $avatarUrl = (!empty($u['avatar']) && file_exists(__DIR__ . '/../uploads/avatars/' . $u['avatar']))
                                        ? '../uploads/avatars/' . $u['avatar'] : null;
                                    $rankClass = $idx < 3 ? 'rank-' . ($idx + 1) : '';
                                ?>
                                <div class="activity-item">
                                    <div class="activity-item__avatar <?= $rankClass ?>">
                                        <?php if ($avatarUrl): ?>
                                            <img src="<?= htmlspecialchars($avatarUrl) ?>" alt="">
                                        <?php else: ?>
                                            <?= htmlspecialchars($initial) ?>
                                        <?php endif; ?>
                                    </div>
                                    <div class="activity-item__info">
                                        <div class="activity-item__name"><?= htmlspecialchars($fullName) ?></div>
                                        <div class="activity-item__meta"><?= htmlspecialchars($u['mobile']) ?></div>
                                    </div>
                                    <div class="activity-item__value"><?= (int)$u['done_count'] ?></div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- TOP PROJECTS -->
                <div class="activity-card">
                    <div class="activity-card__head">
                        <div class="activity-card__title">
                            <i class="fas fa-folder-open"></i>
                            پروژه‌های برتر
                        </div>
                        <a href="#" class="activity-card__more">مشاهده همه</a>
                    </div>
                    <div class="activity-list">
                        <?php if (empty($topProjects)): ?>
                            <div class="empty">داده‌ای موجود نیست</div>
                        <?php else: ?>
                            <?php foreach (array_slice($topProjects, 0, 5) as $idx => $p): ?>
                                <?php
                                    $initial = mb_substr(trim($p['title']), 0, 1, 'UTF-8');
                                    $imgUrl = (!empty($p['profile_image']) && file_exists(__DIR__ . '/../uploads/projects/' . $p['profile_image']))
                                        ? '../uploads/projects/' . $p['profile_image'] : null;
                                    $rankClass = $idx < 3 ? 'rank-' . ($idx + 1) : '';
                                ?>
                                <div class="activity-item">
                                    <div class="activity-item__avatar <?= $rankClass ?>">
                                        <?php if ($imgUrl): ?>
                                            <img src="<?= htmlspecialchars($imgUrl) ?>" alt="">
                                        <?php else: ?>
                                            <?= htmlspecialchars($initial) ?>
                                        <?php endif; ?>
                                    </div>
                                    <div class="activity-item__info">
                                        <div class="activity-item__name"><?= htmlspecialchars($p['title']) ?></div>
                                        <div class="activity-item__meta">پروژه</div>
                                    </div>
                                    <div class="activity-item__value"><?= (int)$p['task_count'] ?></div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

            </div>

        </div>
    </main>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        if (typeof Chart === 'undefined') return;

        Chart.defaults.font.family = 'Tahoma, sans-serif';
        Chart.defaults.font.size = 11;
        Chart.defaults.color = '#6b6288';
        Chart.defaults.borderColor = 'rgba(168, 85, 247, 0.06)';

        // Gradient helper
        function areaGrad(ctx, color) {
            const g = ctx.createLinearGradient(0, 0, 0, 300);
            g.addColorStop(0, color + '55');
            g.addColorStop(0.5, color + '18');
            g.addColorStop(1, color + '00');
            return g;
        }

        const tooltipStyle = {
            backgroundColor: '#1c1631',
            borderColor: 'rgba(168, 85, 247, 0.35)',
            borderWidth: 1,
            padding: 12,
            cornerRadius: 12,
            titleColor: '#f5f3ff',
            bodyColor: '#b8aede',
            titleFont: { size: 12, weight: 'bold' },
            bodyFont: { size: 12 },
            boxPadding: 6,
            usePointStyle: true,
            displayColors: true,
        };

        const gridStyle = {
            color: 'rgba(168, 85, 247, 0.06)',
            drawBorder: false,
        };

        // ===== MAIN CHART =====
        const mainCanvas = document.getElementById('mainChart');
        if (mainCanvas) {
            const ctx = mainCanvas.getContext('2d');
            new Chart(mainCanvas, {
                type: 'line',
                data: {
                    labels: <?= json_encode($trendDays) ?>,
                    datasets: [
                        {
                            label: 'وظایف',
                            data: <?= json_encode($trendTasks) ?>,
                            borderColor: '#a855f7',
                            backgroundColor: areaGrad(ctx, '#a855f7'),
                            borderWidth: 3,
                            tension: 0.45,
                            fill: true,
                            pointBackgroundColor: '#a855f7',
                            pointBorderColor: '#0a0714',
                            pointBorderWidth: 3,
                            pointRadius: 0,
                            pointHoverRadius: 7,
                            pointHoverBorderWidth: 3,
                        },
                        {
                            label: 'تیکت‌ها',
                            data: <?= json_encode($trendTickets) ?>,
                            borderColor: '#ec4899',
                            backgroundColor: areaGrad(ctx, '#ec4899'),
                            borderWidth: 3,
                            tension: 0.45,
                            fill: true,
                            pointBackgroundColor: '#ec4899',
                            pointBorderColor: '#0a0714',
                            pointBorderWidth: 3,
                            pointRadius: 0,
                            pointHoverRadius: 7,
                            pointHoverBorderWidth: 3,
                        },
                        {
                            label: 'کاربران',
                            data: <?= json_encode($trendUsers) ?>,
                            borderColor: '#2dd4bf',
                            backgroundColor: areaGrad(ctx, '#2dd4bf'),
                            borderWidth: 3,
                            tension: 0.45,
                            fill: true,
                            pointBackgroundColor: '#2dd4bf',
                            pointBorderColor: '#0a0714',
                            pointBorderWidth: 3,
                            pointRadius: 0,
                            pointHoverRadius: 7,
                            pointHoverBorderWidth: 3,
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: { mode: 'index', intersect: false },
                    plugins: {
                        legend: { display: false },
                        tooltip: tooltipStyle,
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                stepSize: 1,
                                precision: 0,
                                color: '#6b6288',
                                font: { size: 10 },
                                padding: 8,
                            },
                            grid: gridStyle,
                            border: { display: false },
                        },
                        x: {
                            grid: { display: false },
                            border: { display: false },
                            ticks: {
                                color: '#6b6288',
                                font: { size: 10, weight: 'bold' },
                                padding: 6,
                            }
                        }
                    }
                }
            });
        }

        // ===== STATUS DOUGHNUT =====
        const statusCanvas = document.getElementById('statusChart');
        if (statusCanvas) {
            new Chart(statusCanvas, {
                type: 'doughnut',
                data: {
                    labels: ['انجام شده', 'انجام نشده'],
                    datasets: [{
                        data: [<?= $tDone ?>, <?= $tPending ?>],
                        backgroundColor: ['#a855f7', '#ec4899'],
                        borderColor: '#161127',
                        borderWidth: 4,
                        hoverOffset: 10,
                        hoverBorderColor: '#161127',
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '72%',
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                padding: 14,
                                color: '#b8aede',
                                font: { size: 11, weight: 'bold' },
                                usePointStyle: true,
                                pointStyle: 'circle',
                                boxWidth: 8,
                            }
                        },
                        tooltip: tooltipStyle,
                    }
                }
            });
        }

        // ===== PRIORITY BAR =====
        const priorityCanvas = document.getElementById('priorityChart');
        if (priorityCanvas) {
            const pctx = priorityCanvas.getContext('2d');
            function barGrad(c1, c2) {
                const g = pctx.createLinearGradient(0, 0, 0, 200);
                g.addColorStop(0, c1);
                g.addColorStop(1, c2);
                return g;
            }
            new Chart(priorityCanvas, {
                type: 'bar',
                data: {
                    labels: ['کم', 'متوسط', 'زیاد'],
                    datasets: [{
                        data: [<?= $tLow ?>, <?= $tMedium ?>, <?= $tHigh ?>],
                        backgroundColor: [
                            barGrad('#34d399', '#10b981'),
                            barGrad('#fbbf24', '#f59e0b'),
                            barGrad('#f87171', '#ef4444'),
                        ],
                        borderRadius: 10,
                        borderSkipped: false,
                        barThickness: 42,
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: tooltipStyle,
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: { stepSize: 1, precision: 0, color: '#6b6288', font: { size: 10 } },
                            grid: gridStyle,
                            border: { display: false },
                        },
                        x: {
                            grid: { display: false },
                            border: { display: false },
                            ticks: { color: '#b8aede', font: { size: 11, weight: 'bold' } }
                        }
                    }
                }
            });
        }

        // ===== MONTHLY =====
        const monthlyCanvas = document.getElementById('monthlyChart');
        if (monthlyCanvas) {
            const mctx = monthlyCanvas.getContext('2d');
            new Chart(monthlyCanvas, {
                type: 'line',
                data: {
                    labels: <?= json_encode($monthLabels) ?>,
                    datasets: [{
                        label: 'وظایف',
                        data: <?= json_encode($monthTasks) ?>,
                        borderColor: '#c084fc',
                        backgroundColor: areaGrad(mctx, '#c084fc'),
                        borderWidth: 3,
                        tension: 0.45,
                        fill: true,
                        pointBackgroundColor: '#c084fc',
                        pointBorderColor: '#0a0714',
                        pointBorderWidth: 3,
                        pointRadius: 0,
                        pointHoverRadius: 8,
                        pointHoverBorderWidth: 3,
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: tooltipStyle,
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: { stepSize: 1, precision: 0, color: '#6b6288', font: { size: 10 } },
                            grid: gridStyle,
                            border: { display: false },
                        },
                        x: {
                            grid: { display: false },
                            border: { display: false },
                            ticks: { color: '#6b6288', font: { size: 10, weight: 'bold' } }
                        }
                    }
                }
            });
        }

        // ===== SUBJECT DOUGHNUT =====
        const subjectCanvas = document.getElementById('subjectChart');
        if (subjectCanvas) {
            const sLabels = <?= json_encode($subjectLabels, JSON_UNESCAPED_UNICODE) ?>;
            const sCounts = <?= json_encode($subjectCounts) ?>;

            if (sLabels.length > 0) {
                new Chart(subjectCanvas, {
                    type: 'doughnut',
                    data: {
                        labels: sLabels,
                        datasets: [{
                            data: sCounts,
                            backgroundColor: [
                                '#a855f7', '#ec4899', '#2dd4bf', '#fbbf24',
                                '#60a5fa', '#f472b6', '#c084fc', '#34d399',
                            ],
                            borderColor: '#161127',
                            borderWidth: 3,
                            hoverOffset: 10,
                            hoverBorderColor: '#161127',
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        cutout: '65%',
                        plugins: {
                            legend: {
                                position: 'bottom',
                                labels: {
                                    padding: 10,
                                    color: '#b8aede',
                                    font: { size: 10, weight: 'bold' },
                                    usePointStyle: true,
                                    pointStyle: 'circle',
                                    boxWidth: 7,
                                }
                            },
                            tooltip: tooltipStyle,
                        }
                    }
                });
            } else {
                subjectCanvas.parentElement.innerHTML = '<div class="empty">داده‌ای موجود نیست</div>';
            }
        }
    });
</script>
</body>
</html>