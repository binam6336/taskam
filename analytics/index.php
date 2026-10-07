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
function formatDelayDays($days)
{
    $days = (int)$days;
    if ($days <= 0) return '—';
    if ($days === 1) return '۱ روز';
    if ($days < 30) return $days . ' روز';
    $m = round($days / 30, 1);
    return $m . ' ماه';
}
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
// ⭐ منطق اصلاح‌شده:
//   - on_time  = تکمیل‌شده و (بدون due_date یا DATE(completed_at) <= due_date)
//   - late     = تکمیل‌شده و due_date دارد و DATE(completed_at) > due_date
//   - overdue  = تکمیل نشده و due_date < امروز
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
            SUM(CASE WHEN is_completed=0 AND due_date IS NOT NULL AND due_date < CURDATE() THEN 1 ELSE 0 END) AS overdue,
            SUM(CASE 
                WHEN is_completed=1 
                 AND (due_date IS NULL OR (completed_at IS NOT NULL AND DATE(completed_at) <= due_date))
                THEN 1 ELSE 0 END) AS on_time,
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

// ================== ⭐ تحلیل زمانبندی (اصلاح‌شده نهایی) ==================
//   - on_time  = تکمیل‌شده و (بدون due_date یا DATE(completed_at) <= due_date)
//   - late     = تکمیل‌شده و due_date دارد و DATE(completed_at) > due_date
//   - pending  = تکمیل نشده (کل)
$scheduleStats = [
    'with_due' => 0,
    'on_time' => 0,
    'late' => 0,
    'pending_total' => 0,
    'avg_delay_days' => 0,
    'avg_early_days' => 0,
    'max_delay_days' => 0,
    'punctuality_rate' => 0,
];

try {
    $stmt = $db->prepare("
        SELECT 
            SUM(CASE WHEN due_date IS NOT NULL THEN 1 ELSE 0 END) AS with_due,
            SUM(CASE 
                WHEN is_completed=1 
                 AND (due_date IS NULL OR (completed_at IS NOT NULL AND DATE(completed_at) <= due_date))
                THEN 1 ELSE 0 END) AS on_time,
            SUM(CASE 
                WHEN is_completed=1 
                 AND due_date IS NOT NULL 
                 AND completed_at IS NOT NULL
                 AND DATE(completed_at) > due_date
                THEN 1 ELSE 0 END) AS late,
            SUM(CASE WHEN is_completed=0 THEN 1 ELSE 0 END) AS pending_total,
            AVG(CASE 
                WHEN is_completed=1 AND due_date IS NOT NULL AND completed_at IS NOT NULL
                 AND DATE(completed_at) > due_date
                THEN DATEDIFF(completed_at, due_date)
                ELSE NULL END) AS avg_delay_days,
            AVG(CASE 
                WHEN is_completed=1 AND due_date IS NOT NULL AND completed_at IS NOT NULL
                 AND DATE(completed_at) <= due_date
                THEN DATEDIFF(due_date, completed_at)
                ELSE NULL END) AS avg_early_days,
            MAX(CASE 
                WHEN is_completed=1 AND due_date IS NOT NULL AND completed_at IS NOT NULL
                 AND DATE(completed_at) > due_date
                THEN DATEDIFF(completed_at, due_date)
                ELSE NULL END) AS max_delay_days
        FROM tasks WHERE project_id = ?
    ");
    $stmt->execute([$projectId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $scheduleStats['with_due'] = (int)($row['with_due'] ?? 0);
        $scheduleStats['on_time'] = (int)($row['on_time'] ?? 0);
        $scheduleStats['late'] = (int)($row['late'] ?? 0);
        $scheduleStats['pending_total'] = (int)($row['pending_total'] ?? 0);
        $scheduleStats['avg_delay_days'] = round((float)($row['avg_delay_days'] ?? 0), 1);
        $scheduleStats['avg_early_days'] = round((float)($row['avg_early_days'] ?? 0), 1);
        $scheduleStats['max_delay_days'] = round((float)($row['max_delay_days'] ?? 0), 1);
    }
    $judged = $scheduleStats['on_time'] + $scheduleStats['late'];
    $scheduleStats['punctuality_rate'] = $judged > 0 ? round(($scheduleStats['on_time'] / $judged) * 100) : 0;
} catch (PDOException $e) {
}

// ================== آمار زمانبندی هر کاربر ==================
$userScheduleStats = [];
try {
    $stmt = $db->prepare("
        SELECT 
            assignee_id AS uid,
            COUNT(CASE WHEN due_date IS NOT NULL THEN 1 END) AS with_due,
            SUM(CASE 
                WHEN is_completed=1 
                 AND (due_date IS NULL OR (completed_at IS NOT NULL AND DATE(completed_at) <= due_date))
                THEN 1 ELSE 0 END) AS on_time,
            SUM(CASE 
                WHEN is_completed=1 AND due_date IS NOT NULL AND completed_at IS NOT NULL
                 AND DATE(completed_at) > due_date
                THEN 1 ELSE 0 END) AS late,
            SUM(CASE WHEN is_completed=0 THEN 1 ELSE 0 END) AS pending_total
        FROM tasks WHERE project_id = ?
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
            'pending_total' => (int)$r['pending_total'],
            'punctuality' => $judged > 0 ? round(($r['on_time'] / $judged) * 100) : 0,
        ];
    }
} catch (PDOException $e) {
}

// ================== وظایف نیازمند توجه (انجام نشده با مهلت نزدیک/گذشته) ==================
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
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <link rel="stylesheet" href="style.css">
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
                        <?php if ($scheduleStats['late'] > 0): ?>
                            همچنین <span class="highlight-red"><?= $scheduleStats['late'] ?> وظیفه</span> با تاخیر تکمیل شده است.
                        <?php else: ?>
                            هیچ وظیفه‌ای با تاخیر تکمیل نشده است. ✅
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
                            <div class="mini-metric__label">انجام نشده</div>
                            <div class="mini-metric__value red"><?= $scheduleStats['pending_total'] ?></div>
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
                            مقایسه فقط بر اساس <strong>تاریخ</strong> — تسک‌های بدون مهلت <strong>به موقع</strong> محسوب می‌شوند
                        </div>
                    </div>
                    <div style="display:flex;gap:6px;flex-wrap:wrap;flex-shrink:0;">
                        <span class="schedule-badge mode"><i class="fas fa-calendar-day" style="margin-left:4px;"></i> مقایسه تاریخی</span>
                        <span class="schedule-badge live"><i class="fas fa-circle" style="font-size:.4rem;margin-left:4px;"></i> تحلیل زنده</span>
                    </div>
                </div>

                <!-- 6 Stat Cards -->
                <div class="schedule-grid">
                    <!-- 1. دارای مهلت -->
                    <div class="schedule-card">
                        <div class="schedule-card__icon violet"><i class="fas fa-calendar-check"></i></div>
                        <div class="schedule-card__value"><?= $scheduleStats['with_due'] ?></div>
                        <div class="schedule-card__label">وظایف دارای مهلت</div>
                        <div class="schedule-card__sub">دارای تاریخ سررسید</div>
                    </div>

                    <!-- 2. به موقع انجام شده -->
                    <div class="schedule-card">
                        <div class="schedule-card__icon green"><i class="fas fa-check-circle"></i></div>
                        <div class="schedule-card__value" style="color:#059669;"><?= $scheduleStats['on_time'] ?></div>
                        <div class="schedule-card__label">به موقع انجام شده</div>
                        <div class="schedule-card__sub">شامل بدون‌مهلت + رعایت مهلت</div>
                    </div>

                    <!-- 3. انجام با تاخیر -->
                    <div class="schedule-card">
                        <div class="schedule-card__icon orange"><i class="fas fa-exclamation-triangle"></i></div>
                        <div class="schedule-card__value" style="color:#d97706;"><?= $scheduleStats['late'] ?></div>
                        <div class="schedule-card__label">انجام با تاخیر</div>
                        <div class="schedule-card__sub">
                            <?php if ($scheduleStats['avg_delay_days'] > 0): ?>
                                میانگین <?= formatDelayDays($scheduleStats['avg_delay_days']) ?> تاخیر
                            <?php else: ?>
                                تاریخ انجام > تاریخ مهلت
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- 4. انجام نشده (کل) -->
                    <div class="schedule-card">
                        <div class="schedule-card__icon red"><i class="fas fa-hourglass-half"></i></div>
                        <div class="schedule-card__value" style="color:#dc2626;"><?= $scheduleStats['pending_total'] ?></div>
                        <div class="schedule-card__label">انجام نشده</div>
                        <div class="schedule-card__sub">بدون توجه به مهلت</div>
                    </div>

                    <!-- 5. نرخ وقت‌شناسی -->
                    <div class="schedule-card">
                        <div class="schedule-card__icon teal"><i class="fas fa-percentage"></i></div>
                        <div class="schedule-card__value" style="color:#0d9488;"><?= $scheduleStats['punctuality_rate'] ?>%</div>
                        <div class="schedule-card__label">نرخ وقت‌شناسی</div>
                        <div class="schedule-card__sub">از <?= $scheduleStats['on_time'] + $scheduleStats['late'] ?> وظیفه داوری‌شده</div>
                    </div>

                    <!-- 6. بیشترین تاخیر -->
                    <div class="schedule-card">
                        <div class="schedule-card__icon orange"><i class="fas fa-clock-rotate-left"></i></div>
                        <div class="schedule-card__value" style="color:#ea580c;">
                            <?php if ($scheduleStats['max_delay_days'] > 0): ?>
                                <?= formatDelayDays($scheduleStats['max_delay_days']) ?>
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </div>
                        <div class="schedule-card__label">بیشترین تاخیر</div>
                        <div class="schedule-card__sub">تک‌تسک با بیشترین تاخیر</div>
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
                                <span><span class="ldot" style="background:#94a3b8;"></span> انجام نشده</span>
                            </div>
                        </div>
                        <div class="schedule-chart-wrap"><canvas id="userScheduleChart"></canvas></div>
                    </div>
                <?php endif; ?>

                <!-- Upcoming / Overdue Tasks Table -->
                <div class="schedule-tasks-table">
                    <div class="schedule-tasks-head">
                        <h4><i class="fas fa-bell"></i> وظایف نیازمند توجه (انجام نشده تا ۲ روز آینده یا عقب‌افتاده)</h4>
                        <span class="schedule-tasks-count"><?= count($upcomingOverdueTasks) ?> وظیفه</span>
                    </div>
                    <?php if (empty($upcomingOverdueTasks)): ?>
                        <div class="schedule-tasks-empty">
                            <i class="fas fa-check-double"></i>
                            هیچ وظیفه انجام‌نشده‌ای در ۲ روز آینده مهلت ندارد — آفرین! 🎉
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
    </script>

    <script src="app.js"></script>
</body>

</html>
<?php ob_end_flush(); ?>