<?php
ob_start();
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../database/Database.php';
require_once __DIR__ . '/../includes/sidebar.php';

if (!Auth::check()) {
    header('Location: ../auth/login.php');
    exit;
}

$db = Database::getInstance();
try {
    $db->exec("SET NAMES 'utf8mb4'");
    $db->exec("SET time_zone = '+03:30'");
} catch (PDOException $e) {
}

$userId    = (int)$_SESSION['user_id'];
$projectId = (int)($_GET['project_id'] ?? 0);
if ($projectId <= 0) {
    header('Location: ../projects/index.php');
    exit;
}

// ================== Persian Date Helpers ==================
function gregorianToJalali($gy, $gm, $gd)
{
    $m = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
    $g = ($gm > 2) ? $gy + 1 : $gy;
    $d = 355666 + (365 * $gy) + ((int)(($g + 3) / 4)) - ((int)(($g + 99) / 100)) + ((int)(($g + 399) / 400)) + $gd + $m[$gm - 1];
    $jy = -1595 + (33 * ((int)($d / 12053)));
    $d %= 12053;
    $jy += 4 * ((int)($d / 1461));
    $d %= 1461;
    if ($d > 365) {
        $jy += (int)(($d - 1) / 365);
        $d = ($d - 1) % 365;
    }
    if ($d < 186) {
        $jm = 1 + (int)($d / 31);
        $jd = 1 + ($d % 31);
    } else {
        $jm = 7 + (int)(($d - 186) / 30);
        $jd = 1 + (($d - 186) % 30);
    }
    return [$jy, $jm, $jd];
}
function formatPersianDate($dt, $w = true)
{
    if (empty($dt)) return '';
    $ts = strtotime($dt);
    if (!$ts) return $dt;
    list($jy, $jm, $jd) = gregorianToJalali((int)date('Y', $ts), (int)date('n', $ts), (int)date('j', $ts));
    $mo = ['فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];
    $wd = ['شنبه', 'یکشنبه', 'دوشنبه', 'سه‌شنبه', 'چهارشنبه', 'پنجشنبه', 'جمعه'];
    $p = ((int)date('w', $ts) + 1) % 7;
    return ($w ? $wd[$p] . ' ' : '') . $jd . ' ' . $mo[$jm - 1] . ' ' . $jy . ' - ' . date('H:i', $ts);
}
function formatPersianDateOnly($dt)
{
    if (empty($dt)) return '';
    $ts = strtotime($dt);
    if (!$ts) return $dt;
    list($jy, $jm, $jd) = gregorianToJalali((int)date('Y', $ts), (int)date('n', $ts), (int)date('j', $ts));
    $mo = ['فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];
    return $jd . ' ' . $mo[$jm - 1] . ' ' . $jy;
}
function formatPersianDateTime($dt)
{
    if (empty($dt)) return '';
    $ts = strtotime($dt);
    if (!$ts) return $dt;
    list($jy, $jm, $jd) = gregorianToJalali((int)date('Y', $ts), (int)date('n', $ts), (int)date('j', $ts));
    $mo = ['فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];
    return $jd . ' ' . $mo[$jm - 1] . ' ' . $jy . ' - ' . date('H:i', $ts);
}
function formatDuration($h)
{
    $h = (float)$h;
    if ($h <= 0) return '—';
    if ($h < 1) {
        $m = round($h * 60);
        return $m < 1 ? 'کمتر از ۱ دقیقه' : $m . ' دقیقه';
    }
    if ($h < 24) {
        $hh = floor($h);
        $mm = round(($h - $hh) * 60);
        return $mm == 0 ? $hh . ' ساعت' : $hh . ' ساعت و ' . $mm . ' دقیقه';
    }
    $d = floor($h / 24);
    $rh = $h - ($d * 24);
    $hh = floor($rh);
    $mm = round(($rh - $hh) * 60);
    $r = $d . ' روز';
    if ($hh > 0) $r .= ' و ' . $hh . ' ساعت';
    if ($mm > 0 && $hh == 0) $r .= ' و ' . $mm . ' دقیقه';
    return $r;
}
// ⭐ فرمت تاخیر بر اساس روز (نه ساعت)
function formatDelayDays($days)
{
    $days = (int)$days;
    if ($days <= 0) return '—';
    if ($days === 1) return '۱ روز';
    if ($days < 30) return $days . ' روز';
    $m = round($days / 30, 1);
    return $m . ' ماه';
}
// ⭐ فرمت زودتر بودن بر اساس روز
function formatEarlyDays($days)
{
    $days = (int)$days;
    if ($days <= 0) return 'همان روز';
    if ($days === 1) return '۱ روز جلوتر';
    return $days . ' روز جلوتر';
}

// ================== Project & Access ==================
$stmt = $db->prepare("SELECT * FROM projects WHERE id = ? LIMIT 1");
$stmt->execute([$projectId]);
$project = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$project) {
    header('Location: ../projects/index.php');
    exit;
}

$isCreator = ((int)$project['user_id'] === $userId);
$hasAnalyticsPerm = false;
if (!$isCreator) {
    $permStmt = $db->prepare("SELECT permissions FROM project_members WHERE project_id = ? AND user_id = ? LIMIT 1");
    $permStmt->execute([$projectId, $userId]);
    $rawPerms = $permStmt->fetchColumn();
    if ($rawPerms) {
        $p = json_decode($rawPerms, true);
        if (is_array($p)) $hasAnalyticsPerm = in_array('view_analytics', $p, true);
    }
}
if (!$isCreator && !$hasAnalyticsPerm) {
    $_SESSION['project_flash'] = ['message' => 'شما دسترسی مشاهده تحلیل‌های این پروژه را ندارید.', 'type' => 'danger'];
    header('Location: ../projects/index.php');
    exit;
}
$displayUser = $_SESSION['user_mobile'] ?? $_SESSION['mobile'] ?? $_SESSION['username'] ?? 'کاربر';

$makeAvatarUrl = function ($file) {
    if (empty($file)) return null;
    $b = basename($file);
    if (!preg_match('/^[A-Za-z0-9_.\-]+$/', $b)) return null;
    if (file_exists(__DIR__ . '/../uploads/avatars/' . $b)) return '../uploads/avatars/' . rawurlencode($b);
    return null;
};
$makeProjectImageUrl = function ($file) {
    if (empty($file)) return null;
    $b = basename($file);
    if (!preg_match('/^[A-Za-z0-9_.\-]+$/', $b)) return null;
    if (file_exists(__DIR__ . '/../uploads/projects/' . $b)) return '../uploads/projects/' . rawurlencode($b);
    return null;
};

// ================== آمار کلی وظایف ==================
$stats = ['total' => 0, 'completed' => 0, 'pending' => 0, 'high' => 0, 'medium' => 0, 'low' => 0, 'with_due' => 0, 'overdue' => 0, 'on_time' => 0, 'late' => 0, 'unique_assignees' => 0, 'unique_creators' => 0];
try {
    $stmt = $db->prepare("
        SELECT COUNT(*) AS total,
            SUM(CASE WHEN is_completed=1 THEN 1 ELSE 0 END) AS completed,
            SUM(CASE WHEN is_completed=0 THEN 1 ELSE 0 END) AS pending,
            SUM(CASE WHEN priority='high' THEN 1 ELSE 0 END) AS high,
            SUM(CASE WHEN priority='medium' THEN 1 ELSE 0 END) AS medium,
            SUM(CASE WHEN priority='low' THEN 1 ELSE 0 END) AS low,
            SUM(CASE WHEN due_date IS NOT NULL THEN 1 ELSE 0 END) AS with_due,
            -- ⭐ عقب‌افتاده: مهلت گذشته و تکمیل نشده (فقط بر اساس تاریخ)
            SUM(CASE 
                WHEN is_completed=0 
                 AND due_date IS NOT NULL 
                 AND due_date < CURDATE()
                THEN 1 ELSE 0 END) AS overdue,
            -- ⭐ به‌موقع: تاریخ تکمیل <= تاریخ مهلت (فقط تاریخ)
            SUM(CASE 
                WHEN is_completed=1 
                 AND due_date IS NOT NULL 
                 AND completed_at IS NOT NULL 
                 AND DATE(completed_at) <= due_date
                THEN 1 ELSE 0 END) AS on_time,
            -- ⭐ با تاخیر: تاریخ تکمیل > تاریخ مهلت (فقط تاریخ)
            SUM(CASE 
                WHEN is_completed=1 
                 AND due_date IS NOT NULL 
                 AND completed_at IS NOT NULL 
                 AND DATE(completed_at) > due_date
                THEN 1 ELSE 0 END) AS late,
            COUNT(DISTINCT assignee_id) AS unique_assignees,
            COUNT(DISTINCT user_id) AS unique_creators
        FROM tasks WHERE project_id = ?
    ");
    $stmt->execute([$projectId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) foreach ($stats as $k => $v) $stats[$k] = (int)($row[$k] ?? 0);
} catch (PDOException $e) {
}
$completionRate = $stats['total'] > 0 ? round(($stats['completed'] / $stats['total']) * 100) : 0;

// ================== تحلیل زمان انجام ==================
$timeStats = ['avg' => 0, 'min' => 0, 'max' => 0, 'count' => 0];
try {
    $stmt = $db->prepare("SELECT AVG(TIMESTAMPDIFF(MINUTE,created_at,completed_at))/60 AS avg_hours, MIN(TIMESTAMPDIFF(MINUTE,created_at,completed_at))/60 AS min_hours, MAX(TIMESTAMPDIFF(MINUTE,created_at,completed_at))/60 AS max_hours, COUNT(*) AS done_count FROM tasks WHERE project_id = ? AND is_completed = 1 AND completed_at IS NOT NULL AND completed_at > created_at");
    $stmt->execute([$projectId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $timeStats['avg'] = round((float)($row['avg_hours'] ?? 0), 4);
        $timeStats['min'] = round((float)($row['min_hours'] ?? 0), 4);
        $timeStats['max'] = round((float)($row['max_hours'] ?? 0), 4);
        $timeStats['count'] = (int)($row['done_count'] ?? 0);
    }
} catch (PDOException $e) {
}

// ================== یادداشت‌ها و پیوست‌ها ==================
$notesCount = 0;
$notesAuthors = 0;
try {
    $s = $db->prepare("SELECT COUNT(*) AS cnt,COUNT(DISTINCT tn.user_id) AS authors FROM task_notes tn INNER JOIN tasks t ON t.id=tn.task_id WHERE t.project_id=?");
    $s->execute([$projectId]);
    $r = $s->fetch(PDO::FETCH_ASSOC);
    if ($r) {
        $notesCount = (int)$r['cnt'];
        $notesAuthors = (int)$r['authors'];
    }
} catch (PDOException $e) {
}
$taskAttachCount = 0;
try {
    $s = $db->prepare("SELECT COUNT(*) FROM task_attachments ta INNER JOIN tasks t ON t.id=ta.task_id WHERE t.project_id=?");
    $s->execute([$projectId]);
    $taskAttachCount = (int)$s->fetchColumn();
} catch (PDOException $e) {
}
$noteAttachCount = 0;
try {
    $s = $db->prepare("SELECT COUNT(*) FROM note_attachments na INNER JOIN task_notes tn ON tn.id=na.note_id INNER JOIN tasks t ON t.id=tn.task_id WHERE t.project_id=?");
    $s->execute([$projectId]);
    $noteAttachCount = (int)$s->fetchColumn();
} catch (PDOException $e) {
}
$totalAttachments = $taskAttachCount + $noteAttachCount;
$avgNotesPerTask = $stats['total'] > 0 ? round($notesCount / $stats['total'], 2) : 0;

// ================== روند ۷ روز ==================
$trendDays = [];
$trendCreated = [];
$trendCompleted = [];
$trendAvgHours = [];
for ($i = 6; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $trendDays[] = date('m/d', strtotime("-$i days"));
    try {
        $stmt = $db->prepare("SELECT COUNT(*) FROM tasks WHERE project_id=? AND DATE(created_at)=?");
        $stmt->execute([$projectId, $date]);
        $trendCreated[] = (int)$stmt->fetchColumn();
        $stmt = $db->prepare("SELECT COUNT(*) FROM tasks WHERE project_id=? AND DATE(completed_at)=?");
        $stmt->execute([$projectId, $date]);
        $trendCompleted[] = (int)$stmt->fetchColumn();
        $stmt = $db->prepare("SELECT AVG(TIMESTAMPDIFF(MINUTE,created_at,completed_at))/60 AS avg_hours FROM tasks WHERE project_id=? AND is_completed=1 AND completed_at IS NOT NULL AND completed_at>created_at AND DATE(completed_at)=?");
        $stmt->execute([$projectId, $date]);
        $avgH = $stmt->fetchColumn();
        $trendAvgHours[] = $avgH !== false && $avgH !== null ? round((float)$avgH, 2) : null;
    } catch (PDOException $e) {
        $trendCreated[] = 0;
        $trendCompleted[] = 0;
        $trendAvgHours[] = null;
    }
}

// ================== روند ۶ ماه ==================
$monthLabels = [];
$monthCreated = [];
$monthCompleted = [];
for ($i = 5; $i >= 0; $i--) {
    $start = date('Y-m-01', strtotime("-$i months"));
    $end = date('Y-m-t', strtotime("-$i months"));
    $monthLabels[] = date('m/Y', strtotime("-$i months"));
    try {
        $stmt = $db->prepare("SELECT COUNT(*) FROM tasks WHERE project_id=? AND DATE(created_at) BETWEEN ? AND ?");
        $stmt->execute([$projectId, $start, $end]);
        $monthCreated[] = (int)$stmt->fetchColumn();
        $stmt = $db->prepare("SELECT COUNT(*) FROM tasks WHERE project_id=? AND DATE(completed_at) BETWEEN ? AND ?");
        $stmt->execute([$projectId, $start, $end]);
        $monthCompleted[] = (int)$stmt->fetchColumn();
    } catch (PDOException $e) {
        $monthCreated[] = 0;
        $monthCompleted[] = 0;
    }
}

// ================== آمار اعضا ==================
$membersStats = [];
try {
    $memberStmt = $db->prepare("SELECT u.id,u.first_name,u.last_name,u.mobile,u.avatar FROM project_members pm INNER JOIN users u ON u.id=pm.user_id WHERE pm.project_id=? ORDER BY u.first_name ASC,u.last_name ASC");
    $memberStmt->execute([$projectId]);
    $members = $memberStmt->fetchAll(PDO::FETCH_ASSOC);

    $tasksStatsStmt = $db->prepare("
        SELECT assignee_id AS uid,
            COUNT(*) AS total,
            SUM(CASE WHEN is_completed=1 THEN 1 ELSE 0 END) AS completed,
            SUM(CASE WHEN is_completed=0 THEN 1 ELSE 0 END) AS pending,
            SUM(CASE WHEN is_completed=1 AND completed_at IS NOT NULL AND completed_at>created_at THEN TIMESTAMPDIFF(MINUTE,created_at,completed_at)/60 ELSE NULL END) AS total_hours,
            SUM(CASE WHEN is_completed=1 AND completed_at IS NOT NULL AND completed_at>created_at THEN 1 ELSE 0 END) AS done_with_time
        FROM tasks WHERE project_id=? GROUP BY assignee_id
    ");
    $tasksStatsStmt->execute([$projectId]);
    $taskStatsByUid = [];
    foreach ($tasksStatsStmt->fetchAll(PDO::FETCH_ASSOC) as $r) $taskStatsByUid[(int)$r['uid']] = $r;

    $notesByUser = [];
    $nStmt = $db->prepare("SELECT tn.user_id AS uid,COUNT(*) AS cnt FROM task_notes tn INNER JOIN tasks t ON t.id=tn.task_id WHERE t.project_id=? GROUP BY tn.user_id");
    $nStmt->execute([$projectId]);
    foreach ($nStmt->fetchAll(PDO::FETCH_ASSOC) as $r) $notesByUser[(int)$r['uid']] = (int)$r['cnt'];

    $filesByUser = [];
    $fStmt = $db->prepare("SELECT ta.user_id AS uid,COUNT(*) AS cnt FROM task_attachments ta INNER JOIN tasks t ON t.id=ta.task_id WHERE t.project_id=? GROUP BY ta.user_id");
    $fStmt->execute([$projectId]);
    foreach ($fStmt->fetchAll(PDO::FETCH_ASSOC) as $r) $filesByUser[(int)$r['uid']] = (int)$r['cnt'];

    foreach ($members as $m) {
        $uid = (int)$m['id'];
        $tRow = $taskStatsByUid[$uid] ?? null;
        $total = $tRow ? (int)$tRow['total'] : 0;
        $completed = $tRow ? (int)$tRow['completed'] : 0;
        $pending = $tRow ? (int)$tRow['pending'] : 0;
        $doneWithTime = $tRow ? (int)$tRow['done_with_time'] : 0;
        $totalHours = $tRow ? (float)$tRow['total_hours'] : 0;
        $avgHours = $doneWithTime > 0 ? round($totalHours / $doneWithTime, 4) : 0;
        $rate = $total > 0 ? round(($completed / $total) * 100) : 0;
        $fullName = trim(($m['first_name'] ?? '') . ' ' . ($m['last_name'] ?? ''));
        if ($fullName === '') $fullName = $m['mobile'];
        $membersStats[] = [
            'id' => $uid,
            'name' => $fullName,
            'mobile' => $m['mobile'],
            'avatar_url' => $makeAvatarUrl($m['avatar'] ?? null),
            'initial' => mb_substr(trim($m['first_name'] ?: $m['mobile']), 0, 1, 'UTF-8'),
            'is_creator' => ((int)$project['user_id'] === $uid),
            'total' => $total,
            'completed' => $completed,
            'pending' => $pending,
            'rate' => $rate,
            'avg_hours' => $avgHours,
            'notes' => $notesByUser[$uid] ?? 0,
            'files' => $filesByUser[$uid] ?? 0,
        ];
    }
    usort($membersStats, function ($a, $b) {
        if ($b['completed'] === $a['completed']) return $b['total'] <=> $a['total'];
        return $b['completed'] <=> $a['completed'];
    });
} catch (PDOException $e) {
}

// ================== روند سرعت کاربران ==================
$speedTrend = ['users' => []];
$speedUserColors = ['#2563eb', '#10b981', '#7c3aed', '#f59e0b', '#ec4899', '#14b8a6', '#ef4444', '#6366f1', '#0891b2', '#d946ef'];
try {
    $allSpeedUsers = $membersStats;
    $allSpeedIds = array_map(fn($u) => (int)$u['id'], $allSpeedUsers);
    if (!empty($allSpeedIds)) {
        $ph = implode(',', array_fill(0, count($allSpeedIds), '?'));
        $stmt = $db->prepare("SELECT assignee_id,DATE(completed_at) AS day_key,AVG(TIMESTAMPDIFF(MINUTE,created_at,completed_at))/60 AS avg_hours FROM tasks WHERE project_id=? AND assignee_id IN ($ph) AND is_completed=1 AND completed_at IS NOT NULL AND completed_at>created_at AND completed_at>=DATE_SUB(CURDATE(),INTERVAL 6 DAY) GROUP BY assignee_id,day_key");
        $stmt->execute(array_merge([$projectId], $allSpeedIds));
        $dayKeys = [];
        for ($i = 6; $i >= 0; $i--) $dayKeys[] = date('Y-m-d', strtotime("-$i days"));
        $speedMap = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) $speedMap[(int)$r['assignee_id']][$r['day_key']] = round((float)$r['avg_hours'], 2);
        $ci = 0;
        foreach ($allSpeedUsers as $u) {
            $data = [];
            $hasAny = false;
            foreach ($dayKeys as $dk) {
                if (isset($speedMap[(int)$u['id']][$dk])) {
                    $data[] = $speedMap[(int)$u['id']][$dk];
                    $hasAny = true;
                } else $data[] = null;
            }
            if ($hasAny) {
                $speedTrend['users'][] = ['name' => $u['name'], 'color' => $speedUserColors[$ci % count($speedUserColors)], 'data' => $data, 'is_creator' => $u['is_creator'], 'avatar_url' => $u['avatar_url'], 'initial' => $u['initial']];
                $ci++;
            }
        }
    }
} catch (PDOException $e) {
}

// ================== ⭐ تحلیل زمانبندی پیشرفته (اصلاح‌شده - فقط تاریخ) ==================
$scheduleStats = [
    'with_due' => 0,
    'with_due_time' => 0,
    'on_time' => 0,
    'late' => 0,
    'overdue' => 0,
    'upcoming_24h' => 0,
    'upcoming_48h' => 0,
    'avg_delay_days' => 0,
    'avg_early_days' => 0,
    'max_delay_days' => 0,
    'punctuality_rate' => 0,
];

try {
    $stmt = $db->prepare("
        SELECT 
            SUM(CASE WHEN due_date IS NOT NULL THEN 1 ELSE 0 END) AS with_due,
            SUM(CASE WHEN due_date IS NOT NULL AND due_time IS NOT NULL THEN 1 ELSE 0 END) AS with_due_time,
            -- ⭐ به‌موقع: DATE(completed_at) <= due_date
            SUM(CASE 
                WHEN is_completed=1 
                 AND due_date IS NOT NULL 
                 AND completed_at IS NOT NULL
                 AND DATE(completed_at) <= due_date
                THEN 1 ELSE 0 END) AS on_time,
            -- ⭐ با تاخیر: DATE(completed_at) > due_date
            SUM(CASE 
                WHEN is_completed=1 
                 AND due_date IS NOT NULL 
                 AND completed_at IS NOT NULL
                 AND DATE(completed_at) > due_date
                THEN 1 ELSE 0 END) AS late,
            -- ⭐ عقب‌افتاده: due_date < امروز و تکمیل نشده
            SUM(CASE 
                WHEN is_completed=0 
                 AND due_date IS NOT NULL 
                 AND due_date < CURDATE()
                THEN 1 ELSE 0 END) AS overdue,
            -- ⭐ مهلت امروز یا فردا
            SUM(CASE 
                WHEN is_completed=0 
                 AND due_date IS NOT NULL 
                 AND due_date IN (CURDATE(), DATE_ADD(CURDATE(), INTERVAL 1 DAY))
                THEN 1 ELSE 0 END) AS upcoming_24h,
            -- ⭐ مهلت تا ۲ روز آینده (امروز، فردا، پس‌فردا)
            SUM(CASE 
                WHEN is_completed=0 
                 AND due_date IS NOT NULL 
                 AND due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 2 DAY)
                THEN 1 ELSE 0 END) AS upcoming_48h,
            -- ⭐ میانگین تاخیر (بر حسب روز)
            AVG(CASE 
                WHEN is_completed=1 
                 AND due_date IS NOT NULL 
                 AND completed_at IS NOT NULL
                 AND DATE(completed_at) > due_date
                THEN DATEDIFF(completed_at, due_date)
                ELSE NULL END) AS avg_delay_days,
            -- ⭐ میانگین زودتر بودن (بر حسب روز)
            AVG(CASE 
                WHEN is_completed=1 
                 AND due_date IS NOT NULL 
                 AND completed_at IS NOT NULL
                 AND DATE(completed_at) <= due_date
                THEN DATEDIFF(due_date, completed_at)
                ELSE NULL END) AS avg_early_days,
            -- ⭐ بیشترین تاخیر (بر حسب روز)
            MAX(CASE 
                WHEN is_completed=1 
                 AND due_date IS NOT NULL 
                 AND completed_at IS NOT NULL
                 AND DATE(completed_at) > due_date
                THEN DATEDIFF(completed_at, due_date)
                ELSE NULL END) AS max_delay_days
        FROM tasks WHERE project_id = ?
    ");
    $stmt->execute([$projectId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $scheduleStats['with_due'] = (int)($row['with_due'] ?? 0);
        $scheduleStats['with_due_time'] = (int)($row['with_due_time'] ?? 0);
        $scheduleStats['on_time'] = (int)($row['on_time'] ?? 0);
        $scheduleStats['late'] = (int)($row['late'] ?? 0);
        $scheduleStats['overdue'] = (int)($row['overdue'] ?? 0);
        $scheduleStats['upcoming_24h'] = (int)($row['upcoming_24h'] ?? 0);
        $scheduleStats['upcoming_48h'] = (int)($row['upcoming_48h'] ?? 0);
        $scheduleStats['avg_delay_days'] = round((float)($row['avg_delay_days'] ?? 0), 1);
        $scheduleStats['avg_early_days'] = round((float)($row['avg_early_days'] ?? 0), 1);
        $scheduleStats['max_delay_days'] = round((float)($row['max_delay_days'] ?? 0), 1);
    }
    $judged = $scheduleStats['on_time'] + $scheduleStats['late'];
    $scheduleStats['punctuality_rate'] = $judged > 0 ? round(($scheduleStats['on_time'] / $judged) * 100) : 0;
} catch (PDOException $e) {
}

// ================== ⭐ آمار زمانبندی هر کاربر (اصلاح‌شده) ==================
$userScheduleStats = [];
try {
    $stmt = $db->prepare("
        SELECT 
            assignee_id AS uid,
            COUNT(CASE WHEN due_date IS NOT NULL THEN 1 END) AS with_due,
            SUM(CASE 
                WHEN is_completed=1 AND due_date IS NOT NULL AND completed_at IS NOT NULL
                 AND DATE(completed_at) <= due_date
                THEN 1 ELSE 0 END) AS on_time,
            SUM(CASE 
                WHEN is_completed=1 AND due_date IS NOT NULL AND completed_at IS NOT NULL
                 AND DATE(completed_at) > due_date
                THEN 1 ELSE 0 END) AS late,
            SUM(CASE 
                WHEN is_completed=0 AND due_date IS NOT NULL
                 AND due_date < CURDATE()
                THEN 1 ELSE 0 END) AS overdue
        FROM tasks WHERE project_id = ? AND due_date IS NOT NULL
        GROUP BY assignee_id
    ");
    $stmt->execute([$projectId]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $uid = (int)$r['uid'];
        $judged = (int)$r['on_time'] + (int)$r['late'];
        $userScheduleStats[$uid] = [
            'with_due' => (int)$r['with_due'],
            'on_time' => (int)$r['on_time'],
            'late' => (int)$r['late'],
            'overdue' => (int)$r['overdue'],
            'punctuality' => $judged > 0 ? round(($r['on_time'] / $judged) * 100) : 0,
        ];
    }
} catch (PDOException $e) {
}

// ================== ⭐ وظایف عقب‌افتاده/نزدیک به مهلت (اصلاح‌شده) ==================
$upcomingOverdueTasks = [];
try {
    $stmt = $db->prepare("
        SELECT t.id, t.title, t.due_date, t.due_time, t.priority, t.is_completed,
               u.first_name, u.last_name, u.mobile AS u_mobile, u.avatar AS u_avatar,
               DATEDIFF(t.due_date, CURDATE()) AS days_diff
        FROM tasks t
        LEFT JOIN users u ON u.id = t.assignee_id
        WHERE t.project_id = ? 
          AND t.is_completed = 0 
          AND t.due_date IS NOT NULL
          AND t.due_date <= DATE_ADD(CURDATE(), INTERVAL 2 DAY)
        ORDER BY t.due_date ASC, t.priority DESC
        LIMIT 15
    ");
    $stmt->execute([$projectId]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $fullName = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));
        if ($fullName === '') $fullName = $r['u_mobile'] ?? 'کاربر';
        $daysDiff = (int)$r['days_diff'];
        $upcomingOverdueTasks[] = [
            'id' => (int)$r['id'],
            'title' => (string)$r['title'],
            'due_date' => formatPersianDateOnly($r['due_date'] . ' 00:00:00'),
            'due_time' => $r['due_time'] ? substr($r['due_time'], 0, 5) : '',
            'priority' => (string)$r['priority'],
            'assignee_name' => $fullName,
            'assignee_initial' => mb_substr(trim($r['first_name'] ?: ($r['u_mobile'] ?? '?')), 0, 1, 'UTF-8'),
            'assignee_avatar' => $makeAvatarUrl($r['u_avatar'] ?? null),
            'days_diff' => $daysDiff,
            'is_overdue' => ($daysDiff < 0),
        ];
    }
} catch (PDOException $e) {
}

// ================== موضوعات ==================
$topSubjects = [];
try {
    $s = $db->prepare("SELECT s.title,COUNT(t.id) AS cnt FROM subjects s INNER JOIN tasks t ON t.subject_id=s.id WHERE t.project_id=? GROUP BY s.id ORDER BY cnt DESC");
    $s->execute([$projectId]);
    $topSubjects = $s->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
}
$subjectLabels = array_column($topSubjects, 'title');
$subjectCounts = array_map('intval', array_column($topSubjects, 'cnt'));

$dashboardData = [
    'total' => $stats['total'],
    'completed' => $stats['completed'],
    'pending' => $stats['pending'],
    'high' => $stats['high'],
    'medium' => $stats['medium'],
    'low' => $stats['low'],
    'trendDays' => $trendDays,
    'trendCreated' => $trendCreated,
    'trendCompleted' => $trendCompleted,
    'trendAvgHours' => $trendAvgHours,
    'monthLabels' => $monthLabels,
    'monthCreated' => $monthCreated,
    'monthCompleted' => $monthCompleted,
    'subjectLabels' => $subjectLabels,
    'subjectCounts' => $subjectCounts,
    'onTime' => $stats['on_time'],
    'late' => $stats['late'],
    'overdue' => $stats['overdue'],
    'speedTrend' => $speedTrend,
    'schedule' => $scheduleStats,
    'userSchedule' => $userScheduleStats,
];

$dashboardDataJson = json_encode($dashboardData, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
$projectImageUrl = $makeProjectImageUrl($project['profile_image'] ?? null);
$projectInitial = mb_substr(trim($project['title']), 0, 1, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <title>تحلیل‌های پروژه | <?= htmlspecialchars($project['title']) ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="icon" href="https://img.icons8.com/color/48/dashboard-layout.png" type="image/png">
    <link rel="stylesheet" href="style.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
</head>

<body>

    <div class="sidebar-overlay" id="sidebarOverlay" onclick="closeSidebar()"></div>
    <?php sidebar(); ?>

    <div class="main-wrapper">
        <div class="topbar">
            <div class="topbar-left">
                <button class="hamburger-btn" id="hamburgerBtn" onclick="toggleSidebar()" aria-label="باز کردن منو">
                    <div class="hamburger-lines"><span></span><span></span><span></span></div>
                </button>
                <a href="../projects/index.php" class="topbar-back" title="بازگشت به پروژه‌ها"><i class="fas fa-arrow-right"></i></a>
                <div class="topbar-project-thumb">
                    <?php if ($projectImageUrl): ?><img src="<?= htmlspecialchars($projectImageUrl) ?>" alt=""><?php else: ?><?= htmlspecialchars($projectInitial) ?><?php endif; ?>
                </div>
                <div class="topbar-title">
                    <h1>تحلیل‌های <?= htmlspecialchars($project['title']) ?></h1>
                    <span><span class="dot"></span> گزارش زنده از وضعیت پروژه</span>
                </div>
            </div>
            <div class="topbar-user">کاربر: <?= htmlspecialchars($displayUser) ?></div>
        </div>

        <div class="content-area">

            <div class="page-header">
                <h1>تحلیل‌ها</h1>
                <div class="page-header__actions">
                    <button type="button" class="btn-outline" onclick="window.scrollTo({top:0,behavior:'smooth'})"><i class="fas fa-table-columns"></i> چیدمان</button>
                    <button type="button" class="btn-outline" onclick="window.location.reload()"><i class="fas fa-sliders"></i> بروزرسانی</button>
                </div>
            </div>

            <!-- HERO -->
            <div class="hero-row">
                <div class="hero-main">
                    <div class="hero-main__toolbar">
                        <span class="hero-main__badge"><i class="fas fa-bolt"></i> داشبورد</span>
                        <div class="hero-main__icons">
                            <button type="button" title="ذخیره"><i class="fas fa-bookmark"></i></button>
                            <button type="button" title="لیست"><i class="fas fa-list-ul"></i></button>
                            <button type="button" title="فایل"><i class="fas fa-file-lines"></i></button>
                            <button type="button" title="پوشه"><i class="fas fa-folder"></i></button>
                            <button type="button" title="ایمیل"><i class="fas fa-envelope"></i></button>
                        </div>
                    </div>
                    <div class="hero-main__head">
                        <div>
                            <div class="hero-main__title">
                                <span class="ic"><i class="fas fa-chart-column"></i></span>
                                روند وظایف ۷ روز اخیر
                            </div>
                            <div class="hero-main__desc" style="padding-right:42px;">
                                <i class="fas fa-circle-info"></i>
                                مقایسه وظایف ایجاد شده در برابر تکمیل شده — برای دیدن جزئیات هر روز نشانگر را نگه دارید
                            </div>
                        </div>
                        <div class="hero-main__legend">
                            <span><span class="dot" style="background:#3b82f6;"></span> ایجاد شده</span>
                            <span><span class="dot" style="background:#10b981;"></span> تکمیل شده</span>
                        </div>
                    </div>
                    <div class="hero-main__chart"><canvas id="mainBarChart"></canvas></div>
                </div>
                <div class="hero-side">
                    <div class="hero-side__top">
                        <span class="hero-side__label">نرخ تکمیل وظایف</span>
                        <select class="hero-side__select" onchange="this.value='kpi'">
                            <option value="kpi">کل پروژه</option>
                        </select>
                    </div>
                    <div class="hero-side__value"><?= $completionRate ?><small>%</small></div>
                    <div class="hero-side__sub">
                        <span class="pill <?= $completionRate >= 50 ? '' : 'down' ?>"><?= $stats['completed'] ?> انجام شده</span>
                        <span><?= $stats['pending'] ?> باقی‌مانده</span>
                    </div>
                    <div class="hero-side__mini-chart"><canvas id="kpiMiniChart"></canvas></div>
                </div>
            </div>

            <!-- TOOLBAR -->
            <div class="toolbar-row">
                <div class="toolbar-row__title">
                    <i class="fas fa-xmark"></i> گزارش تحلیلی پروژه <i class="fas fa-pen edit-ic"></i>
                </div>
                <div class="toolbar-row__spacer"></div>
                <div class="toolbar-row__group"><i class="fas fa-calendar"></i> ۷ روز اخیر <i class="fas fa-chevron-down"></i></div>
                <button type="button" class="toolbar-row__icon-btn" title="نمودار"><i class="fas fa-chart-line"></i></button>
                <button type="button" class="toolbar-row__icon-btn" title="ستونی"><i class="fas fa-chart-bar"></i></button>
                <button type="button" class="toolbar-row__icon-btn" title="دانلود"><i class="fas fa-download"></i></button>
                <button type="button" class="toolbar-row__save" onclick="window.location.reload()"><i class="fas fa-rotate"></i> بروزرسانی داده</button>
            </div>

            <!-- SPLIT: TABLE + SUMMARY -->
            <div class="split-row">
                <div class="data-table-wrap">
                    <div class="data-table-head">
                        <h3><i class="fas fa-users"></i> عملکرد اعضای پروژه</h3>
                        <span style="font-size:.72rem;color:#94a3b8;"><i class="fas fa-sort"></i> مرتب بر اساس تسک انجام‌شده</span>
                    </div>
                    <div class="data-table-scroll">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th style="width:180px;">کاربر</th>
                                    <th>تکمیل‌شده</th>
                                    <th>نرخ تکمیل</th>
                                    <th>میانگین زمان</th>
                                    <th>یادداشت</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($membersStats)): ?>
                                    <tr>
                                        <td colspan="5" class="empty-row"><i class="fas fa-users-slash"></i>عضوی وجود ندارد</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($membersStats as $m):
                                        $rc = '#64748b';
                                        if ($m['total'] > 0) {
                                            if ($m['rate'] >= 70) $rc = '#059669';
                                            elseif ($m['rate'] >= 40) $rc = '#ea580c';
                                            else $rc = '#dc2626';
                                        }
                                    ?>
                                        <tr>
                                            <td>
                                                <div class="user-cell">
                                                    <div class="user-cell__avatar <?= $m['is_creator'] ? 'is-creator' : '' ?>">
                                                        <?php if ($m['avatar_url']): ?><img src="<?= htmlspecialchars($m['avatar_url']) ?>" alt=""><?php else: ?><?= htmlspecialchars($m['initial']) ?><?php endif; ?>
                                                    </div>
                                                    <div class="user-cell__info">
                                                        <div class="user-cell__name"><?php if ($m['is_creator']): ?><i class="fas fa-crown crown"></i><?php endif; ?><?= htmlspecialchars($m['name']) ?></div>
                                                        <div class="user-cell__mobile"><?= htmlspecialchars($m['mobile']) ?></div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td><span class="cell-pill green"><?= $m['completed'] ?></span><span style="color:#cbd5e1;font-size:.7rem;margin-right:4px;">/ <?= $m['total'] ?></span></td>
                                            <td>
                                                <div class="table-progress">
                                                    <div class="table-progress__track">
                                                        <div class="table-progress__fill" style="width:<?= $m['total'] > 0 ? $m['rate'] : 0 ?>%;background:<?= $rc ?>;"></div>
                                                    </div>
                                                    <div class="table-progress__value" style="color:<?= $rc ?>;"><?= $m['total'] > 0 ? $m['rate'] . '%' : '—' ?></div>
                                                </div>
                                            </td>
                                            <td><?php if ($m['avg_hours'] > 0): ?><span class="cell-mono small"><?= formatDuration($m['avg_hours']) ?></span><?php else: ?><span class="cell-mono small" style="color:#cbd5e1;">—</span><?php endif; ?></td>
                                            <td><?php if ($m['notes'] > 0): ?><span class="cell-pill violet"><i class="fas fa-comments" style="font-size:.7rem;"></i><?= $m['notes'] ?></span><?php else: ?><span class="cell-mono small" style="color:#cbd5e1;">۰</span><?php endif; ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="summary-card">
                    <div class="summary-card__head">
                        <div class="summary-card__title"><i class="fas fa-wand-magic-sparkles"></i> خلاصه تحلیلی</div>
                        <button type="button" class="toolbar-row__icon-btn" title="تنظیمات"><i class="fas fa-gear"></i></button>
                    </div>
                    <div class="summary-insight">
                        <?php if ($completionRate >= 70): ?>
                            نرخ تکمیل پروژه <strong><?= $completionRate ?>%</strong> است — عملکرد تیم <span class="highlight-green">عالی</span> ارزیابی می‌شود.
                        <?php elseif ($completionRate >= 40): ?>
                            نرخ تکمیل پروژه <strong><?= $completionRate ?>%</strong> است — روند <strong>متعادل</strong> ادامه دارد.
                        <?php else: ?>
                            نرخ تکمیل پروژه <strong><?= $completionRate ?>%</strong> است — نیاز به <span class="highlight-red">توجه بیشتر</span> دارد.
                        <?php endif; ?>
                        <?php if ($scheduleStats['overdue'] > 0): ?>
                            همچنین <span class="highlight-red"><?= $scheduleStats['overdue'] ?> وظیفه</span> عقب‌افتاده وجود دارد.
                        <?php else: ?>
                            هیچ وظیفه عقب‌افتاده‌ای وجود ندارد. ✅
                        <?php endif; ?>
                    </div>
                    <div class="summary-mini-grid">
                        <div class="mini-metric">
                            <div class="mini-metric__label">به موقع</div>
                            <div class="mini-metric__value green"><?= $scheduleStats['on_time'] ?></div>
                        </div>
                        <div class="mini-metric">
                            <div class="mini-metric__label">با تاخیر</div>
                            <div class="mini-metric__value orange"><?= $scheduleStats['late'] ?></div>
                        </div>
                        <div class="mini-metric">
                            <div class="mini-metric__label">عقب‌افتاده</div>
                            <div class="mini-metric__value red"><?= $scheduleStats['overdue'] ?></div>
                        </div>
                    </div>
                    <div class="summary-bars">
                        <div class="summary-bar-row"><span class="label">انجام شده</span>
                            <div class="bar-track">
                                <div class="bar-fill" style="width:<?= $completionRate ?>%;background:linear-gradient(90deg,#10b981,#34d399);"></div>
                            </div><span class="value"><?= $stats['completed'] ?></span>
                        </div>
                        <div class="summary-bar-row"><span class="label">در انتظار</span>
                            <div class="bar-track">
                                <div class="bar-fill" style="width:<?= $stats['total'] > 0 ? round(($stats['pending'] / $stats['total']) * 100) : 0 ?>%;background:linear-gradient(90deg,#f59e0b,#fbbf24);"></div>
                            </div><span class="value"><?= $stats['pending'] ?></span>
                        </div>
                        <div class="summary-bar-row"><span class="label">اولویت زیاد</span>
                            <div class="bar-track">
                                <div class="bar-fill" style="width:<?= $stats['total'] > 0 ? round(($stats['high'] / $stats['total']) * 100) : 0 ?>%;background:linear-gradient(90deg,#ef4444,#f87171);"></div>
                            </div><span class="value"><?= $stats['high'] ?></span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- SPEED TREND -->
            <div class="chart-card">
                <div class="chart-card__head">
                    <div class="chart-card__title-wrap">
                        <div class="chart-card__title">
                            <div class="chart-card__title-icon orange"><i class="fas fa-gauge-high"></i></div> روند سرعت انجام وظایف به تفکیک کاربر (۷ روز اخیر)
                        </div>
                        <div class="chart-card__desc"><i class="fas fa-circle-info"></i> سرعت هر کاربر به صورت امتیاز نمایش داده می‌شود؛ <strong style="color:#059669;">بالاتر = سریع‌تر = بهتر</strong> · <strong style="color:#dc2626;">پایین‌تر = کندتر</strong></div>
                    </div>
                    <div class="multi-select" id="speedUserMS">
                        <button type="button" class="multi-select__trigger" onclick="toggleMultiSelect(event,'speedUserMS')">
                            <i class="fas fa-users"></i>
                            <span class="multi-select__label" id="speedUserLabel">انتخاب کاربران</span>
                            <span class="multi-select__count" id="speedUserCount">۰/۵</span>
                            <i class="fas fa-chevron-down multi-select__chevron"></i>
                        </button>
                        <div class="multi-select__dropdown">
                            <div class="multi-select__header"><span><i class="fas fa-info-circle"></i> حداکثر ۵ کاربر را انتخاب کنید</span></div>
                            <div class="multi-select__list" id="speedUserList"></div>
                        </div>
                    </div>
                </div>
                <div class="chart-wrap" style="height:300px;">
                    <canvas id="speedTrendChart"></canvas>
                    <?php if (empty($speedTrend['users'])): ?>
                        <div style="text-align:center;color:#94a3b8;padding:60px 20px;font-size:.85rem;position:absolute;inset:0;display:flex;align-items:center;justify-content:center;flex-direction:column;gap:10px;">
                            <i class="fas fa-chart-line" style="font-size:2rem;opacity:.3;"></i><span>در ۷ روز اخیر تسکی تکمیل نشده است</span>
                        </div>
                    <?php endif; ?>
                </div>
                <?php if (!empty($speedTrend['users'])): ?><div class="speed-legend" id="speedLegend"></div><?php endif; ?>
            </div>

            <!-- AVG TIME -->
            <div class="chart-card">
                <div class="chart-card__head">
                    <div class="chart-card__title-wrap">
                        <div class="chart-card__title">
                            <div class="chart-card__title-icon teal"><i class="fas fa-stopwatch-20"></i></div> میانگین زمان انجام روزانه (۷ روز اخیر)
                        </div>
                        <div class="chart-card__desc"><i class="fas fa-circle-info"></i> میانگین زمان لازم برای تکمیل وظایف در هر روز — <strong style="color:#059669;">نزولی = بهبود سرعت تیم</strong></div>
                    </div>
                    <div class="chart-card__legend">
                        <div class="legend-item"><span class="legend-dot orange"></span> میانگین زمان (ساعت)</div>
                    </div>
                </div>
                <div class="chart-wrap" style="height:300px;"><canvas id="avgTrendChart"></canvas></div>
            </div>

            <!-- MONTHLY + SUBJECT -->
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:20px;">
                <div class="chart-card" style="margin-bottom:0;">
                    <div class="chart-card__head">
                        <div class="chart-card__title-wrap">
                            <div class="chart-card__title">
                                <div class="chart-card__title-icon violet"><i class="fas fa-chart-line"></i></div> روند ۶ ماه اخیر
                            </div>
                            <div class="chart-card__desc"><i class="fas fa-circle-info"></i> تعداد کل وظایف ایجاد و تکمیل شده در ۶ ماه گذشته</div>
                        </div>
                    </div>
                    <div class="chart-wrap"><canvas id="monthlyChart"></canvas></div>
                </div>
                <div class="chart-card" style="margin-bottom:0;">
                    <div class="chart-card__head">
                        <div class="chart-card__title-wrap">
                            <div class="chart-card__title">
                                <div class="chart-card__title-icon teal"><i class="fas fa-tags"></i></div> توزیع موضوعات
                            </div>
                            <div class="chart-card__desc"><i class="fas fa-circle-info"></i> سهم هر موضوع از کل وظایف پروژه</div>
                        </div>
                        <div class="multi-select" id="subjectMS">
                            <button type="button" class="multi-select__trigger" onclick="toggleMultiSelect(event,'subjectMS')">
                                <i class="fas fa-tags"></i>
                                <span class="multi-select__label" id="subjectLabel">انتخاب موضوعات</span>
                                <span class="multi-select__count" id="subjectCount">۰/۵</span>
                                <i class="fas fa-chevron-down multi-select__chevron"></i>
                            </button>
                            <div class="multi-select__dropdown">
                                <div class="multi-select__header"><span><i class="fas fa-info-circle"></i> حداکثر ۵ موضوع را انتخاب کنید</span></div>
                                <div class="multi-select__list" id="subjectList"></div>
                            </div>
                        </div>
                    </div>
                    <div class="chart-wrap">
                        <canvas id="subjectChart"></canvas>
                        <?php if (empty($subjectLabels)): ?>
                            <div style="text-align:center;color:#94a3b8;padding:60px 20px;font-size:.85rem;position:absolute;inset:0;display:flex;align-items:center;justify-content:center;flex-direction:column;gap:10px;">
                                <i class="fas fa-tags" style="font-size:2rem;opacity:.3;"></i><span>هنوز وظیفه‌ای به موضوع اختصاص نیافته است</span>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- SCHEDULING SECTION -->
            <div class="schedule-section">
                <div class="schedule-section__head">
                    <div class="schedule-section__title-wrap">
                        <h3><i class="fas fa-calendar-clock"></i> تحلیل زمانبندی</h3>
                        <div class="schedule-section__desc">
                            مقایسه فقط بر اساس <strong>تاریخ</strong> انجام می‌شود — اگر تاریخ تکمیل بعد از تاریخ سررسید باشد، «با تاخیر» محسوب می‌شود
                        </div>
                    </div>
                    <div style="display:flex;gap:6px;flex-wrap:wrap;flex-shrink:0;">
                        <span class="schedule-badge mode"><i class="fas fa-calendar-day" style="margin-left:4px;"></i> مقایسه تاریخی</span>
                        <span class="schedule-badge live"><i class="fas fa-circle" style="font-size:.4rem;margin-left:4px;"></i> تحلیل زنده</span>
                    </div>
                </div>

                <!-- 6 Stat Cards -->
                <div class="schedule-grid">
                    <div class="schedule-card">
                        <div class="schedule-card__icon violet"><i class="fas fa-calendar-check"></i></div>
                        <div class="schedule-card__value"><?= $scheduleStats['with_due'] ?></div>
                        <div class="schedule-card__label">وظایف دارای مهلت</div>
                        <div class="schedule-card__sub"><?= $scheduleStats['with_due_time'] ?> با ساعت مشخص</div>
                    </div>
                    <div class="schedule-card">
                        <div class="schedule-card__icon green"><i class="fas fa-check-circle"></i></div>
                        <div class="schedule-card__value" style="color:#059669;"><?= $scheduleStats['on_time'] ?></div>
                        <div class="schedule-card__label">به موقع انجام شده</div>
                        <div class="schedule-card__sub"><?= formatEarlyDays($scheduleStats['avg_early_days']) ?> میانگین</div>
                    </div>
                    <div class="schedule-card">
                        <div class="schedule-card__icon orange"><i class="fas fa-exclamation-triangle"></i></div>
                        <div class="schedule-card__value" style="color:#d97706;"><?= $scheduleStats['late'] ?></div>
                        <div class="schedule-card__label">با تاخیر انجام شده</div>
                        <div class="schedule-card__sub">میانگین <?= formatDelayDays($scheduleStats['avg_delay_days']) ?> تاخیر</div>
                    </div>
                    <div class="schedule-card">
                        <div class="schedule-card__icon red"><i class="fas fa-clock-rotate-left"></i></div>
                        <div class="schedule-card__value" style="color:#dc2626;"><?= $scheduleStats['overdue'] ?></div>
                        <div class="schedule-card__label">عقب‌افتاده</div>
                        <div class="schedule-card__sub">مهلت گذشته / تکمیل نشده</div>
                    </div>
                    <div class="schedule-card">
                        <div class="schedule-card__icon blue"><i class="fas fa-hourglass-half"></i></div>
                        <div class="schedule-card__value" style="color:#2563eb;"><?= $scheduleStats['upcoming_24h'] ?></div>
                        <div class="schedule-card__label">مهلت نزدیک (امروز/فردا)</div>
                        <div class="schedule-card__sub"><?= $scheduleStats['upcoming_48h'] ?> تا ۲ روز آینده</div>
                    </div>
                    <div class="schedule-card">
                        <div class="schedule-card__icon teal"><i class="fas fa-percentage"></i></div>
                        <div class="schedule-card__value" style="color:#0d9488;"><?= $scheduleStats['punctuality_rate'] ?>%</div>
                        <div class="schedule-card__label">نرخ وقت‌شناسی</div>
                        <div class="schedule-card__sub">از <?= $scheduleStats['on_time'] + $scheduleStats['late'] ?> وظیفه داوری‌شده</div>
                    </div>
                </div>

                <!-- Stacked Bar Chart per User -->
                <?php if (!empty($userScheduleStats)): ?>
                    <div class="schedule-chart-card">
                        <div class="schedule-chart-card__head">
                            <h4><i class="fas fa-users-viewfinder"></i> عملکرد زمانبندی به تفکیک کاربر</h4>
                            <div class="schedule-chart-card__legend">
                                <span><span class="ldot" style="background:#10b981;"></span> به موقع</span>
                                <span><span class="ldot" style="background:#f59e0b;"></span> با تاخیر</span>
                                <span><span class="ldot" style="background:#ef4444;"></span> عقب‌افتاده</span>
                            </div>
                        </div>
                        <div class="schedule-chart-wrap"><canvas id="userScheduleChart"></canvas></div>
                    </div>
                <?php endif; ?>

                <!-- Upcoming / Overdue Tasks Table -->
                <div class="schedule-tasks-table">
                    <div class="schedule-tasks-head">
                        <h4><i class="fas fa-bell"></i> وظایف نیازمند توجه (تا ۲ روز آینده یا عقب‌افتاده)</h4>
                        <span class="schedule-tasks-count"><?= count($upcomingOverdueTasks) ?> وظیفه</span>
                    </div>
                    <?php if (empty($upcomingOverdueTasks)): ?>
                        <div class="schedule-tasks-empty">
                            <i class="fas fa-check-double"></i>
                            هیچ وظیفه‌ای در ۲ روز آینده مهلت ندارد — آفرین! 🎉
                        </div>
                    <?php else: ?>
                        <div class="schedule-tasks-list">
                            <?php foreach ($upcomingOverdueTasks as $t):
                                $dd = $t['days_diff'];
                                if ($dd < 0) {
                                    $cls = 'overdue';
                                    $icon = 'fa-clock-rotate-left';
                                    $absD = abs($dd);
                                    $diffLabel = ($absD === 1) ? '۱ روز تاخیر' : ($absD . ' روز تاخیر');
                                } elseif ($dd === 0) {
                                    $cls = 'soon';
                                    $icon = 'fa-hourglass-end';
                                    $diffLabel = 'امروز';
                                } elseif ($dd === 1) {
                                    $cls = 'soon';
                                    $icon = 'fa-hourglass-half';
                                    $diffLabel = 'فردا';
                                } else {
                                    $cls = 'upcoming';
                                    $icon = 'fa-clock';
                                    $diffLabel = $dd . ' روز مانده';
                                }
                                $prioLabel = ['low' => 'کم', 'medium' => 'متوسط', 'high' => 'زیاد'][$t['priority']] ?? $t['priority'];
                            ?>
                                <div class="sched-task-item">
                                    <div class="sched-task-item__icon <?= $cls ?>"><i class="fas <?= $icon ?>"></i></div>
                                    <div class="sched-task-item__body">
                                        <div class="sched-task-item__title"><?= htmlspecialchars($t['title']) ?></div>
                                        <div class="sched-task-item__meta">
                                            <span><i class="fas fa-calendar"></i> <?= htmlspecialchars($t['due_date']) ?><?php if ($t['due_time']): ?> - <?= htmlspecialchars($t['due_time']) ?><?php endif; ?></span>
                                            <span><i class="fas fa-flag"></i> <?= $prioLabel ?></span>
                                        </div>
                                    </div>
                                    <div class="sched-task-item__assignee">
                                        <div class="sched-task-item__avatar">
                                            <?php if ($t['assignee_avatar']): ?><img src="<?= htmlspecialchars($t['assignee_avatar']) ?>" alt=""><?php else: ?><?= htmlspecialchars($t['assignee_initial']) ?><?php endif; ?>
                                        </div>
                                        <div class="sched-task-item__name"><?= htmlspecialchars($t['assignee_name']) ?></div>
                                    </div>
                                    <div class="sched-task-item__diff <?= $cls ?>"><?= $diffLabel ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        </div>
    </div>

    <!-- MODAL USER SPEED -->
    <div id="userSpeedModal" class="modal-overlay" onclick="if(event.target===this)closeUserModal()">
        <div class="modal-box" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h3>
                    <span class="modal-avatar" id="modalAvatar"><i class="fas fa-user"></i></span>
                    روند سرعت انجام — <span id="modalUserName">کاربر</span>
                </h3>
                <button class="modal-close" onclick="closeUserModal()">&times;</button>
            </div>
            <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:14px;flex-wrap:wrap;">
                <div style="font-size:.75rem;color:#64748b;line-height:1.8;"><i class="fas fa-circle-info" style="color:#cbd5e1;margin-left:4px;"></i> نمودار اختصاصی این کاربر در ۷ روز اخیر</div>
                <div style="font-size:.72rem;color:#94a3b8;"><i class="fas fa-chart-line"></i> امتیاز سرعت</div>
            </div>
            <div class="modal-chart-wrap"><canvas id="userModalChart"></canvas></div>
        </div>
    </div>

    <script>
        window.ANALYTICS_DATA = <?= $dashboardDataJson ?>;

        function formatDurationJS(hours) {
            hours = parseFloat(hours) || 0;
            if (hours <= 0) return '—';
            if (hours < 1) {
                const m = Math.round(hours * 60);
                return m < 1 ? 'کمتر از ۱ دقیقه' : m + ' دقیقه';
            }
            if (hours < 24) {
                const h = Math.floor(hours);
                const m = Math.round((hours - h) * 60);
                return m === 0 ? h + ' ساعت' : h + ' ساعت و ' + m + ' دقیقه';
            }
            const d = Math.floor(hours / 24);
            const rh = hours - d * 24;
            const h = Math.floor(rh);
            const m = Math.round((rh - h) * 60);
            let r = d + ' روز';
            if (h > 0) r += ' و ' + h + ' ساعت';
            if (m > 0 && h === 0) r += ' و ' + m + ' دقیقه';
            return r;
        }

        function hexToRgba(hex, alpha) {
            if (!hex) return 'rgba(59,130,246,' + alpha + ')';
            let h = hex.replace('#', '');
            if (h.length === 3) h = h[0] + h[0] + h[1] + h[1] + h[2] + h[2];
            const r = parseInt(h.substring(0, 2), 16),
                g = parseInt(h.substring(2, 4), 16),
                b = parseInt(h.substring(4, 6), 16);
            return 'rgba(' + r + ', ' + g + ', ' + b + ', ' + alpha + ')';
        }

        function hoursToSpeedScore(h) {
            if (h === null || h === undefined || h <= 0) return null;
            return Math.round(1000 / (h + 1));
        }

        function escapeHtmlAnalytics(s) {
            if (s === null || s === undefined) return '';
            const m = {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            };
            return String(s).replace(/[&<>"']/g, c => m[c]);
        }

        const AnalyticsState = {
            speedUsers: new Set(),
            subjects: new Set(),
            speedChart: null,
            subjectChart: null,
            mainBarChart: null,
            kpiMiniChart: null,
            modalChart: null,
            scheduleChart: null,
            activeModalUser: null,
            MAX_SPEED: 5,
            MAX_SUBJECTS: 5
        };
        let GLOBAL_DASHBOARD = null;
        const TOOLTIP_STYLE = {
            backgroundColor: '#1e293b',
            borderColor: 'rgba(255,255,255,0.1)',
            borderWidth: 1,
            padding: 12,
            cornerRadius: 10,
            titleColor: '#f8fafc',
            bodyColor: '#cbd5e1',
            titleFont: {
                size: 12,
                weight: 'bold'
            },
            bodyFont: {
                size: 12
            },
            boxPadding: 6,
            usePointStyle: true,
            displayColors: true,
        };
        const GRID_STYLE = {
            color: 'rgba(148,163,184,0.15)',
            drawBorder: false
        };

        function toggleMultiSelect(e, id) {
            if (e) e.stopPropagation();
            const el = document.getElementById(id);
            if (!el) return;
            const wasOpen = el.classList.contains('open');
            document.querySelectorAll('.multi-select').forEach(m => m.classList.remove('open'));
            if (!wasOpen) el.classList.add('open');
        }
        document.addEventListener('click', function(e) {
            if (!e.target.closest('.multi-select')) document.querySelectorAll('.multi-select.open').forEach(m => m.classList.remove('open'));
        });

        function renderSpeedUserList() {
            const list = document.getElementById('speedUserList');
            if (!list) return;
            if (!GLOBAL_DASHBOARD.speedTrend || !GLOBAL_DASHBOARD.speedTrend.users) {
                list.innerHTML = '';
                return;
            }
            const users = GLOBAL_DASHBOARD.speedTrend.users;
            let html = '';
            const selCount = AnalyticsState.speedUsers.size;
            users.forEach(u => {
                const isSel = AnalyticsState.speedUsers.has(u.name);
                const isDis = !isSel && selCount >= AnalyticsState.MAX_SPEED;
                const av = u.avatar_url ? '<img src="' + escapeHtmlAnalytics(u.avatar_url) + '" alt="">' : escapeHtmlAnalytics(u.initial || '?');
                const crown = u.is_creator ? '<i class="fas fa-crown crown"></i>' : '';
                html += `<div class="multi-select__item ${isSel?'selected':''} ${isDis?'disabled':''}" onclick="toggleSpeedUser('${escapeHtmlAnalytics(u.name).replace(/'/g,"\\'")}')"><span class="multi-select__check"><i class="fas fa-check"></i></span><span class="multi-select__avatar">${av}</span><span class="multi-select__name">${crown}${escapeHtmlAnalytics(u.name)}</span><span class="multi-select__color-dot" style="background:${u.color};"></span></div>`;
            });
            list.innerHTML = html;
            const cEl = document.getElementById('speedUserCount'),
                lEl = document.getElementById('speedUserLabel');
            if (cEl) cEl.textContent = selCount + '/' + AnalyticsState.MAX_SPEED;
            if (lEl) {
                if (selCount === 0) lEl.textContent = 'انتخاب کاربران';
                else if (selCount === 1) lEl.textContent = Array.from(AnalyticsState.speedUsers)[0];
                else lEl.textContent = selCount + ' کاربر انتخاب شده';
            }
        }

        function toggleSpeedUser(name) {
            const was = AnalyticsState.speedUsers.has(name);
            if (!was && AnalyticsState.speedUsers.size >= AnalyticsState.MAX_SPEED) return;
            if (was) AnalyticsState.speedUsers.delete(name);
            else AnalyticsState.speedUsers.add(name);
            renderSpeedUserList();
            renderSpeedChart();
            renderSpeedLegend();
        }

        function renderSubjectList() {
            const list = document.getElementById('subjectList');
            if (!list) return;
            const labels = GLOBAL_DASHBOARD.subjectLabels || [],
                counts = GLOBAL_DASHBOARD.subjectCounts || [];
            if (!labels.length) {
                list.innerHTML = '';
                return;
            }
            const colors = ['#2563eb', '#10b981', '#7c3aed', '#f59e0b', '#ec4899', '#14b8a6', '#ef4444', '#6366f1', '#0891b2', '#d946ef'];
            const selCount = AnalyticsState.subjects.size;
            let html = '';
            labels.forEach((lbl, i) => {
                const isSel = AnalyticsState.subjects.has(lbl);
                const isDis = !isSel && selCount >= AnalyticsState.MAX_SUBJECTS;
                const color = colors[i % colors.length];
                html += `<div class="multi-select__item ${isSel?'selected':''} ${isDis?'disabled':''}" onclick="toggleSubject('${escapeHtmlAnalytics(lbl).replace(/'/g,"\\'")}')"><span class="multi-select__check"><i class="fas fa-check"></i></span><span class="multi-select__color-dot" style="background:${color};"></span><span class="multi-select__name">${escapeHtmlAnalytics(lbl)}</span><span class="multi-select__count-badge">${counts[i]||0}</span></div>`;
            });
            list.innerHTML = html;
            const cEl = document.getElementById('subjectCount'),
                lEl = document.getElementById('subjectLabel');
            if (cEl) cEl.textContent = selCount + '/' + AnalyticsState.MAX_SUBJECTS;
            if (lEl) {
                if (selCount === 0) lEl.textContent = 'انتخاب موضوعات';
                else if (selCount === 1) lEl.textContent = Array.from(AnalyticsState.subjects)[0];
                else lEl.textContent = selCount + ' موضوع انتخاب شده';
            }
        }

        function toggleSubject(title) {
            const was = AnalyticsState.subjects.has(title);
            if (!was && AnalyticsState.subjects.size >= AnalyticsState.MAX_SUBJECTS) return;
            if (was) AnalyticsState.subjects.delete(title);
            else AnalyticsState.subjects.add(title);
            renderSubjectList();
            renderSubjectChart();
        }

        function renderSpeedLegend() {
            const legend = document.getElementById('speedLegend');
            if (!legend) return;
            const users = GLOBAL_DASHBOARD.speedTrend ? GLOBAL_DASHBOARD.speedTrend.users : [];
            const visible = users.filter(u => AnalyticsState.speedUsers.has(u.name));
            if (!visible.length) {
                legend.innerHTML = '<div style="text-align:center;color:#94a3b8;font-size:.78rem;padding:10px;">کاربری انتخاب نشده است</div>';
                return;
            }
            let html = '';
            visible.forEach(u => {
                const av = u.avatar_url ? '<img src="' + escapeHtmlAnalytics(u.avatar_url) + '" alt="">' : escapeHtmlAnalytics(u.initial || '?');
                const crown = u.is_creator ? '<i class="fas fa-crown" style="color:#fbbf24;font-size:.6rem;margin-left:3px;"></i>' : '';
                html += `<button type="button" class="speed-chip" onclick="openUserModal('${escapeHtmlAnalytics(u.name).replace(/'/g,"\\'")}')"><span class="speed-chip__avatar" style="background:${u.color};">${av}</span><span class="speed-chip__info"><span class="speed-chip__name">${crown}${escapeHtmlAnalytics(u.name)}</span><span class="speed-chip__hint">مشاهده پروفایل</span></span><i class="fas fa-chart-line speed-chip__arrow"></i></button>`;
            });
            legend.innerHTML = html;
        }

        function renderSpeedChart() {
            const canvas = document.getElementById('speedTrendChart');
            if (!canvas) return;
            const D = GLOBAL_DASHBOARD;
            if (!D.speedTrend || !D.speedTrend.users || !D.speedTrend.users.length) return;
            const visible = D.speedTrend.users.filter(u => AnalyticsState.speedUsers.has(u.name));
            if (AnalyticsState.speedChart) {
                AnalyticsState.speedChart.destroy();
                AnalyticsState.speedChart = null;
            }
            if (!visible.length) return;
            let maxScore = 0;
            visible.forEach(u => {
                (u.data || []).forEach(v => {
                    const s = hoursToSpeedScore(v);
                    if (s !== null && s > maxScore) maxScore = s;
                });
            });
            if (maxScore < 10) maxScore = 10;
            const ctx = canvas.getContext('2d');
            const datasets = visible.map(u => {
                const scoreData = (u.data || []).map(v => hoursToSpeedScore(v));
                const orig = (u.data || []).slice();
                const grad = ctx.createLinearGradient(0, 0, 0, 300);
                grad.addColorStop(0, hexToRgba(u.color, 0.45));
                grad.addColorStop(0.35, hexToRgba(u.color, 0.20));
                grad.addColorStop(0.7, hexToRgba(u.color, 0.08));
                grad.addColorStop(1, hexToRgba(u.color, 0.02));
                return {
                    label: u.name,
                    data: scoreData,
                    _origHours: orig,
                    _color: u.color,
                    borderColor: u.color,
                    backgroundColor: grad,
                    borderWidth: 2.5,
                    tension: 0.55,
                    spanGaps: true,
                    fill: true,
                    pointBackgroundColor: u.color,
                    pointBorderColor: '#fff',
                    pointBorderWidth: 2.5,
                    pointRadius: 5,
                    pointHoverRadius: 8,
                    pointHoverBorderWidth: 3
                };
            });
            AnalyticsState.speedChart = new Chart(canvas, {
                type: 'line',
                data: {
                    labels: D.trendDays,
                    datasets: datasets
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: {
                        mode: 'index',
                        intersect: false
                    },
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            ...TOOLTIP_STYLE,
                            callbacks: {
                                label: function(ctx) {
                                    const i = ctx.dataIndex;
                                    const oh = (ctx.dataset._origHours || [])[i];
                                    if (oh === null || oh === undefined) return ctx.dataset.label + ': بدون داده';
                                    return ctx.dataset.label + ': ' + formatDurationJS(oh);
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            suggestedMax: maxScore,
                            title: {
                                display: true,
                                text: 'امتیاز سرعت (بالاتر = سریع‌تر)',
                                color: '#94a3b8',
                                font: {
                                    size: 10,
                                    weight: 'bold'
                                }
                            },
                            ticks: {
                                color: '#64748b',
                                font: {
                                    size: 10
                                }
                            },
                            grid: {
                                color: 'rgba(148,163,184,0.14)',
                                drawBorder: false,
                                drawTicks: false
                            },
                            border: {
                                display: false
                            }
                        },
                        x: {
                            grid: {
                                display: false
                            },
                            border: {
                                display: false
                            },
                            ticks: {
                                color: '#64748b',
                                font: {
                                    size: 10,
                                    weight: 'bold'
                                },
                                padding: 6
                            }
                        }
                    }
                }
            });
        }

        function renderSubjectChart() {
            const canvas = document.getElementById('subjectChart');
            if (!canvas) return;
            const D = GLOBAL_DASHBOARD;
            const allLabels = D.subjectLabels || [],
                allCounts = D.subjectCounts || [];
            if (AnalyticsState.subjectChart) {
                AnalyticsState.subjectChart.destroy();
                AnalyticsState.subjectChart = null;
            }
            if (!allLabels.length) return;
            const colors = ['#2563eb', '#10b981', '#7c3aed', '#f59e0b', '#ec4899', '#14b8a6', '#ef4444', '#6366f1', '#0891b2', '#d946ef'];
            const labels = [],
                counts = [],
                bg = [];
            allLabels.forEach((l, i) => {
                if (AnalyticsState.subjects.has(l)) {
                    labels.push(l);
                    counts.push(allCounts[i] || 0);
                    bg.push(colors[i % colors.length]);
                }
            });
            if (!labels.length) return;
            AnalyticsState.subjectChart = new Chart(canvas, {
                type: 'doughnut',
                data: {
                    labels,
                    datasets: [{
                        data: counts,
                        backgroundColor: bg,
                        borderColor: '#fff',
                        borderWidth: 3,
                        hoverOffset: 10
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
                                color: '#475569',
                                font: {
                                    size: 10,
                                    weight: 'bold'
                                },
                                usePointStyle: true,
                                pointStyle: 'circle',
                                boxWidth: 7
                            }
                        },
                        tooltip: TOOLTIP_STYLE
                    }
                }
            });
        }

        function renderMainBarChart() {
            const canvas = document.getElementById('mainBarChart');
            if (!canvas) return;
            const D = GLOBAL_DASHBOARD;
            if (AnalyticsState.mainBarChart) {
                AnalyticsState.mainBarChart.destroy();
                AnalyticsState.mainBarChart = null;
            }
            const ctx = canvas.getContext('2d');
            const g1 = ctx.createLinearGradient(0, 0, 0, 300);
            g1.addColorStop(0, 'rgba(59,130,246,0.95)');
            g1.addColorStop(1, 'rgba(59,130,246,0.55)');
            const g2 = ctx.createLinearGradient(0, 0, 0, 300);
            g2.addColorStop(0, 'rgba(16,185,129,0.95)');
            g2.addColorStop(1, 'rgba(16,185,129,0.55)');
            AnalyticsState.mainBarChart = new Chart(canvas, {
                type: 'bar',
                data: {
                    labels: D.trendDays,
                    datasets: [{
                            label: 'ایجاد شده',
                            data: D.trendCreated,
                            backgroundColor: g1,
                            borderRadius: 8,
                            borderSkipped: false,
                            barPercentage: 0.7,
                            categoryPercentage: 0.65
                        },
                        {
                            label: 'تکمیل شده',
                            data: D.trendCompleted,
                            backgroundColor: g2,
                            borderRadius: 8,
                            borderSkipped: false,
                            barPercentage: 0.7,
                            categoryPercentage: 0.65
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: {
                        mode: 'index',
                        intersect: false
                    },
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: TOOLTIP_STYLE
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                stepSize: 1,
                                precision: 0,
                                color: '#64748b',
                                font: {
                                    size: 10
                                },
                                padding: 8
                            },
                            grid: {
                                color: 'rgba(148,163,184,0.12)'
                            },
                            border: {
                                display: false
                            }
                        },
                        x: {
                            grid: {
                                display: false
                            },
                            border: {
                                display: false
                            },
                            ticks: {
                                color: '#64748b',
                                font: {
                                    size: 10,
                                    weight: 'bold'
                                },
                                padding: 6
                            }
                        }
                    }
                }
            });
        }

        function renderKpiMiniChart() {
            const canvas = document.getElementById('kpiMiniChart');
            if (!canvas) return;
            const D = GLOBAL_DASHBOARD;
            if (AnalyticsState.kpiMiniChart) {
                AnalyticsState.kpiMiniChart.destroy();
                AnalyticsState.kpiMiniChart = null;
            }
            const ctx = canvas.getContext('2d');
            const g = ctx.createLinearGradient(0, 0, 0, 130);
            g.addColorStop(0, 'rgba(37,99,235,0.55)');
            g.addColorStop(1, 'rgba(37,99,235,0.02)');
            AnalyticsState.kpiMiniChart = new Chart(canvas, {
                type: 'line',
                data: {
                    labels: D.trendDays,
                    datasets: [{
                        data: D.trendCompleted,
                        borderColor: '#2563eb',
                        backgroundColor: g,
                        borderWidth: 2.5,
                        tension: 0.55,
                        fill: true,
                        pointRadius: 0,
                        pointHoverRadius: 6
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            ...TOOLTIP_STYLE,
                            callbacks: {
                                label: ctx => 'تکمیل شده: ' + ctx.parsed.y
                            }
                        }
                    },
                    scales: {
                        y: {
                            display: false,
                            beginAtZero: true
                        },
                        x: {
                            display: false
                        }
                    }
                }
            });
        }

        function renderScheduleChart() {
            const canvas = document.getElementById('userScheduleChart');
            if (!canvas) return;
            const D = GLOBAL_DASHBOARD;
            if (!D.userSchedule || !Object.keys(D.userSchedule).length) return;
            if (AnalyticsState.scheduleChart) {
                AnalyticsState.scheduleChart.destroy();
                AnalyticsState.scheduleChart = null;
            }

            const speedUsers = (D.speedTrend.users || []);
            const items = [];
            Object.keys(D.userSchedule).forEach((uid, idx) => {
                const s = D.userSchedule[uid];
                let name = 'کاربر ' + uid;
                let initial = '?';
                let avatar = null;
                if (speedUsers[idx]) {
                    name = speedUsers[idx].name;
                    initial = speedUsers[idx].initial;
                    avatar = speedUsers[idx].avatar_url;
                }
                items.push({
                    uid: parseInt(uid, 10),
                    ...s,
                    name,
                    initial,
                    avatar
                });
            });

            const labels = items.map(it => it.name);
            const onTimeData = items.map(it => it.on_time);
            const lateData = items.map(it => it.late);
            const overdueData = items.map(it => it.overdue);

            AnalyticsState.scheduleChart = new Chart(canvas, {
                type: 'bar',
                data: {
                    labels,
                    datasets: [{
                            label: 'به موقع',
                            data: onTimeData,
                            backgroundColor: '#10b981',
                            borderRadius: 6,
                            borderSkipped: false,
                            barPercentage: 0.72,
                            categoryPercentage: 0.7
                        },
                        {
                            label: 'با تاخیر',
                            data: lateData,
                            backgroundColor: '#f59e0b',
                            borderRadius: 6,
                            borderSkipped: false,
                            barPercentage: 0.72,
                            categoryPercentage: 0.7
                        },
                        {
                            label: 'عقب‌افتاده',
                            data: overdueData,
                            backgroundColor: '#ef4444',
                            borderRadius: 6,
                            borderSkipped: false,
                            barPercentage: 0.72,
                            categoryPercentage: 0.7
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: {
                        mode: 'index',
                        intersect: false
                    },
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            ...TOOLTIP_STYLE
                        }
                    },
                    scales: {
                        x: {
                            stacked: true,
                            grid: {
                                display: false
                            },
                            border: {
                                display: false
                            },
                            ticks: {
                                color: '#6b7280',
                                font: {
                                    size: 10,
                                    weight: 'bold'
                                }
                            }
                        },
                        y: {
                            stacked: true,
                            beginAtZero: true,
                            ticks: {
                                stepSize: 1,
                                precision: 0,
                                color: '#6b7280',
                                font: {
                                    size: 10
                                }
                            },
                            grid: {
                                color: 'rgba(148,163,184,0.14)',
                                drawBorder: false
                            },
                            border: {
                                display: false
                            }
                        }
                    }
                }
            });
        }

        function openUserModal(userName) {
            const D = GLOBAL_DASHBOARD;
            const user = D.speedTrend.users.find(u => u.name === userName);
            if (!user) return;
            AnalyticsState.activeModalUser = userName;
            const nEl = document.getElementById('modalUserName');
            if (nEl) nEl.textContent = userName;
            const avEl = document.getElementById('modalAvatar');
            if (avEl) {
                if (user.avatar_url) avEl.innerHTML = '<img src="' + escapeHtmlAnalytics(user.avatar_url) + '" alt="">';
                else avEl.textContent = user.initial || '?';
                avEl.style.background = user.color;
            }
            document.getElementById('userSpeedModal').classList.add('active');
            document.body.style.overflow = 'hidden';
            const canvas = document.getElementById('userModalChart');
            if (!canvas) return;
            if (AnalyticsState.modalChart) {
                AnalyticsState.modalChart.destroy();
                AnalyticsState.modalChart = null;
            }
            const scoreData = (user.data || []).map(v => hoursToSpeedScore(v));
            const orig = (user.data || []).slice();
            let maxScore = 0;
            scoreData.forEach(s => {
                if (s !== null && s > maxScore) maxScore = s;
            });
            if (maxScore < 10) maxScore = 10;
            const ctx = canvas.getContext('2d');
            const grad = ctx.createLinearGradient(0, 0, 0, 340);
            grad.addColorStop(0, hexToRgba(user.color, 0.50));
            grad.addColorStop(0.35, hexToRgba(user.color, 0.22));
            grad.addColorStop(0.7, hexToRgba(user.color, 0.08));
            grad.addColorStop(1, hexToRgba(user.color, 0.02));
            AnalyticsState.modalChart = new Chart(canvas, {
                type: 'line',
                data: {
                    labels: D.trendDays,
                    datasets: [{
                        label: userName,
                        data: scoreData,
                        _origHours: orig,
                        borderColor: user.color,
                        backgroundColor: grad,
                        borderWidth: 3,
                        tension: 0.55,
                        spanGaps: true,
                        fill: true,
                        pointBackgroundColor: user.color,
                        pointBorderColor: '#fff',
                        pointBorderWidth: 3,
                        pointRadius: 6,
                        pointHoverRadius: 10,
                        pointHoverBorderWidth: 3
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: {
                        mode: 'index',
                        intersect: false
                    },
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            ...TOOLTIP_STYLE,
                            callbacks: {
                                title: function(items) {
                                    if (items.length) return 'روز ' + items[0].label;
                                    return '';
                                },
                                label: function(ctx) {
                                    const i = ctx.dataIndex;
                                    const oh = (ctx.dataset._origHours || [])[i];
                                    if (oh === null || oh === undefined) return 'بدون داده';
                                    return ['زمان میانگین: ' + formatDurationJS(oh), 'امتیاز سرعت: ' + hoursToSpeedScore(oh)];
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            suggestedMax: maxScore,
                            title: {
                                display: true,
                                text: 'امتیاز سرعت',
                                color: '#94a3b8',
                                font: {
                                    size: 10,
                                    weight: 'bold'
                                }
                            },
                            ticks: {
                                color: '#64748b',
                                font: {
                                    size: 10
                                }
                            },
                            grid: {
                                color: 'rgba(148,163,184,0.14)',
                                drawBorder: false
                            },
                            border: {
                                display: false
                            }
                        },
                        x: {
                            grid: {
                                display: false
                            },
                            border: {
                                display: false
                            },
                            ticks: {
                                color: '#64748b',
                                font: {
                                    size: 11,
                                    weight: 'bold'
                                }
                            }
                        }
                    }
                }
            });
        }

        function closeUserModal() {
            document.getElementById('userSpeedModal').classList.remove('active');
            document.body.style.overflow = '';
            if (AnalyticsState.modalChart) {
                AnalyticsState.modalChart.destroy();
                AnalyticsState.modalChart = null;
            }
            AnalyticsState.activeModalUser = null;
        }
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && AnalyticsState.activeModalUser) closeUserModal();
        });

        document.addEventListener('DOMContentLoaded', function() {
            if (typeof Chart === 'undefined') return;
            const D = window.ANALYTICS_DATA;
            if (!D) return;
            GLOBAL_DASHBOARD = D;

            Chart.defaults.font.family = 'Tahoma, sans-serif';
            Chart.defaults.font.size = 11;
            Chart.defaults.color = '#64748b';

            if (D.speedTrend && D.speedTrend.users) {
                D.speedTrend.users.slice(0, AnalyticsState.MAX_SPEED).forEach(u => AnalyticsState.speedUsers.add(u.name));
            }
            if (D.subjectLabels) {
                D.subjectLabels.slice(0, AnalyticsState.MAX_SUBJECTS).forEach(s => AnalyticsState.subjects.add(s));
            }
            renderSpeedUserList();
            renderSubjectList();
            renderSpeedLegend();
            renderSpeedChart();
            renderSubjectChart();
            renderMainBarChart();
            renderKpiMiniChart();
            renderScheduleChart();

            const avgCtx = document.getElementById('avgTrendChart');
            if (avgCtx && D.trendAvgHours) {
                const c = avgCtx.getContext('2d');
                const g = c.createLinearGradient(0, 0, 0, 300);
                g.addColorStop(0, 'rgba(234,88,12,0.55)');
                g.addColorStop(0.35, 'rgba(234,88,12,0.28)');
                g.addColorStop(0.7, 'rgba(234,88,12,0.10)');
                g.addColorStop(1, 'rgba(234,88,12,0.02)');
                new Chart(avgCtx, {
                    type: 'line',
                    data: {
                        labels: D.trendDays,
                        datasets: [{
                            label: 'میانگین زمان (ساعت)',
                            data: D.trendAvgHours,
                            borderColor: '#ea580c',
                            backgroundColor: g,
                            borderWidth: 2.5,
                            tension: 0.55,
                            spanGaps: true,
                            fill: true,
                            pointBackgroundColor: '#ea580c',
                            pointBorderColor: '#fff',
                            pointBorderWidth: 2.5,
                            pointRadius: 5,
                            pointHoverRadius: 8,
                            pointHoverBorderWidth: 3
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        interaction: {
                            mode: 'index',
                            intersect: false
                        },
                        plugins: {
                            legend: {
                                display: false
                            },
                            tooltip: {
                                ...TOOLTIP_STYLE,
                                callbacks: {
                                    label: function(ctx) {
                                        if (ctx.parsed.y === null || ctx.parsed.y === undefined) return 'بدون داده';
                                        return 'میانگین زمان: ' + formatDurationJS(ctx.parsed.y);
                                    }
                                }
                            }
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                title: {
                                    display: true,
                                    text: 'ساعت',
                                    color: '#94a3b8',
                                    font: {
                                        size: 10,
                                        weight: 'bold'
                                    }
                                },
                                ticks: {
                                    color: '#64748b',
                                    font: {
                                        size: 10
                                    },
                                    callback: v => v + 'h'
                                },
                                grid: {
                                    color: 'rgba(148,163,184,0.14)',
                                    drawBorder: false
                                },
                                border: {
                                    display: false
                                }
                            },
                            x: {
                                grid: {
                                    display: false
                                },
                                border: {
                                    display: false
                                },
                                ticks: {
                                    color: '#64748b',
                                    font: {
                                        size: 10,
                                        weight: 'bold'
                                    }
                                }
                            }
                        }
                    }
                });
            }

            const mnCtx = document.getElementById('monthlyChart');
            if (mnCtx) {
                const c = mnCtx.getContext('2d');
                const g1 = c.createLinearGradient(0, 0, 0, 240);
                g1.addColorStop(0, 'rgba(124,58,237,0.55)');
                g1.addColorStop(0.4, 'rgba(124,58,237,0.22)');
                g1.addColorStop(1, 'rgba(124,58,237,0.02)');
                const g2 = c.createLinearGradient(0, 0, 0, 240);
                g2.addColorStop(0, 'rgba(16,185,129,0.50)');
                g2.addColorStop(0.4, 'rgba(16,185,129,0.20)');
                g2.addColorStop(1, 'rgba(16,185,129,0.02)');
                new Chart(mnCtx, {
                    type: 'line',
                    data: {
                        labels: D.monthLabels,
                        datasets: [{
                                label: 'ایجاد شده',
                                data: D.monthCreated,
                                borderColor: '#7c3aed',
                                backgroundColor: g1,
                                borderWidth: 2.5,
                                tension: 0.55,
                                fill: true,
                                pointBackgroundColor: '#7c3aed',
                                pointBorderColor: '#fff',
                                pointBorderWidth: 2,
                                pointRadius: 4,
                                pointHoverRadius: 7
                            },
                            {
                                label: 'تکمیل شده',
                                data: D.monthCompleted,
                                borderColor: '#10b981',
                                backgroundColor: g2,
                                borderWidth: 2.5,
                                tension: 0.55,
                                fill: true,
                                pointBackgroundColor: '#10b981',
                                pointBorderColor: '#fff',
                                pointBorderWidth: 2,
                                pointRadius: 4,
                                pointHoverRadius: 7
                            }
                        ]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        interaction: {
                            mode: 'index',
                            intersect: false
                        },
                        plugins: {
                            legend: {
                                position: 'bottom',
                                labels: {
                                    padding: 14,
                                    color: '#475569',
                                    font: {
                                        size: 11,
                                        weight: 'bold'
                                    },
                                    usePointStyle: true,
                                    pointStyle: 'circle',
                                    boxWidth: 8
                                }
                            },
                            tooltip: TOOLTIP_STYLE
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                ticks: {
                                    stepSize: 1,
                                    precision: 0,
                                    color: '#64748b',
                                    font: {
                                        size: 10
                                    }
                                },
                                grid: GRID_STYLE,
                                border: {
                                    display: false
                                }
                            },
                            x: {
                                grid: {
                                    display: false
                                },
                                border: {
                                    display: false
                                },
                                ticks: {
                                    color: '#64748b',
                                    font: {
                                        size: 10,
                                        weight: 'bold'
                                    }
                                }
                            }
                        }
                    }
                });
            }
        });

        function toggleSidebar() {
            const s = document.getElementById('appSidebar'),
                o = document.getElementById('sidebarOverlay'),
                b = document.getElementById('hamburgerBtn');
            if (!s || !o || !b) return;
            if (s.classList.contains('open')) closeSidebar();
            else {
                s.classList.add('open');
                o.classList.add('active');
                b.classList.add('active');
            }
        }

        function closeSidebar() {
            const s = document.getElementById('appSidebar'),
                o = document.getElementById('sidebarOverlay'),
                b = document.getElementById('hamburgerBtn');
            if (!s || !o || !b) return;
            s.classList.remove('open');
            o.classList.remove('active');
            b.classList.remove('active');
        }
        let rz;
        window.addEventListener('resize', function() {
            clearTimeout(rz);
            rz = setTimeout(() => {
                if (window.innerWidth > 900) closeSidebar();
            }, 150);
        });

        document.addEventListener('contextmenu', function(e) {
            const t = (e.target.tagName || '').toLowerCase();
            if (t === 'input' || t === 'textarea') return true;
            e.preventDefault();
            return false;
        });
        document.addEventListener('keydown', function(e) {
            if ((e.ctrlKey || e.metaKey) && (e.key === 'a' || e.key === 'A')) {
                const t = (e.target.tagName || '').toLowerCase();
                if (t !== 'input' && t !== 'textarea') {
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
        document.addEventListener('dragstart', function(e) {
            if (e.target.tagName === 'IMG' || e.target.tagName === 'A') e.preventDefault();
        });
    </script>
</body>

</html>
<?php ob_end_flush(); ?>