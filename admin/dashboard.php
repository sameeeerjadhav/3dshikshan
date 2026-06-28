<?php
declare(strict_types=1);

session_start();

$user = $_SESSION['user'] ?? null;
if (!$user) {
    header('Location: ../index.php');
    exit;
}

if (($user['role'] ?? '') !== 'admin') {
    header('Location: ../dashboard.php');
    exit;
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';

function parseFeeAmount(string $feeText): float
{
    $numeric = preg_replace('/[^0-9.]/', '', $feeText) ?? '0';
    return (float)$numeric;
}

$colleges = [];
$courses = [];
$coordinators = [];
$users = [];
$students = [];
$adminNotifications = [];
$adminTickets = [];
$totalNotificationGroups = 0;
$unreadAlerts = 0;
$conn = getDbConnection();
if ($conn !== null) {
    $result = $conn->query('SELECT id, name, country, state, district, address, latitude, longitude FROM colleges ORDER BY id DESC');
    if ($result instanceof mysqli_result) {
        while ($row = $result->fetch_assoc()) {
            $colleges[] = $row;
        }
        $result->free();
    }

    $courseResult = $conn->query('SELECT id, course_name, description, duration, fees, required_details FROM courses ORDER BY id DESC');
    if ($courseResult instanceof mysqli_result) {
        while ($row = $courseResult->fetch_assoc()) {
            $courses[] = $row;
        }
        $courseResult->free();
    }

    $coordinatorResult = $conn->query(
        "SELECT
            co.id,
            co.first_name,
            co.second_name,
            co.last_name,
            co.email,
            co.mobile_no,
            co.address_line1,
            co.address_line2,
            co.state,
            co.district,
            co.pin,
            GROUP_CONCAT(c.name ORDER BY c.name SEPARATOR ', ') AS assigned_colleges,
            GROUP_CONCAT(c.id SEPARATOR ',') AS assigned_college_ids
        FROM coordinators co
        LEFT JOIN coordinator_colleges cc ON cc.coordinator_id = co.id
        LEFT JOIN colleges c ON c.id = cc.college_id
        GROUP BY co.id
        ORDER BY co.id DESC"
    );
    if ($coordinatorResult instanceof mysqli_result) {
        while ($row = $coordinatorResult->fetch_assoc()) {
            $coordinators[] = $row;
        }
        $coordinatorResult->free();
    }

    $userResult = $conn->query('SELECT id, full_name, login_id, role, created_at FROM users ORDER BY id DESC');
    if ($userResult instanceof mysqli_result) {
        while ($row = $userResult->fetch_assoc()) {
            $users[] = $row;
        }
        $userResult->free();
    }

    $studentResult = $conn->query(
        "SELECT
            sp.id AS profile_id,
            sp.user_id,
            sp.first_name,
            sp.middle_name,
            sp.last_name,
            u.full_name,
            u.login_id,
            sp.email,
            sp.mobile_no,
            sp.state,
            sp.district,
            sp.college_id,
            sp.course_id,
            sp.academic_year,
            sp.semester,
            c.name AS college_name,
            cr.course_name,
            cr.duration,
            cr.fees,
            sp.created_at,
            COALESCE(SUM(rp.amount_rupees), 0) AS total_paid
        FROM student_profiles sp
        INNER JOIN users u ON u.id = sp.user_id
        INNER JOIN colleges c ON c.id = sp.college_id
        INNER JOIN courses cr ON cr.id = sp.course_id
        LEFT JOIN registration_payments rp ON rp.student_profile_id = sp.id
        GROUP BY sp.id
        ORDER BY sp.id DESC"
    );
    if ($studentResult instanceof mysqli_result) {
        while ($row = $studentResult->fetch_assoc()) {
            $totalFee = parseFeeAmount((string)($row['fees'] ?? '0'));
            $paidFee = (float)($row['total_paid'] ?? 0);
            $remainingFee = max(0.0, $totalFee - $paidFee);

            $row['total_fee'] = $totalFee;
            $row['paid_fee'] = $paidFee;
            $row['remaining_fee'] = $remainingFee;
            $students[] = $row;
        }
        $studentResult->free();
    }

    $alertResult = $conn->query(
        'SELECT COUNT(*) AS cnt FROM (SELECT 1 FROM student_notifications WHERE is_read = 0 GROUP BY title, message) grouped_unread'
    );
    if ($alertResult instanceof mysqli_result) {
        $alertRow = $alertResult->fetch_assoc();
        $unreadAlerts = (int)($alertRow['cnt'] ?? 0);
        $alertResult->free();
    }

    $notificationCountResult = $conn->query(
        'SELECT COUNT(*) AS cnt FROM (SELECT 1 FROM student_notifications GROUP BY title, message) grouped_all'
    );
    if ($notificationCountResult instanceof mysqli_result) {
        $notificationCountRow = $notificationCountResult->fetch_assoc();
        $totalNotificationGroups = (int)($notificationCountRow['cnt'] ?? 0);
        $notificationCountResult->free();
    }

    $notificationResult = $conn->query(
        "SELECT
            MIN(id) AS id,
            title,
            message,
            MAX(created_at) AS created_at,
            MAX(CASE WHEN is_read = 0 THEN 1 ELSE 0 END) AS is_read,
            COUNT(*) AS duplicate_count,
            SUM(CASE WHEN is_read = 0 THEN 1 ELSE 0 END) AS unread_count
        FROM student_notifications
        GROUP BY title, message
        ORDER BY MAX(created_at) DESC
        LIMIT 20"
    );
    if ($notificationResult instanceof mysqli_result) {
        while ($row = $notificationResult->fetch_assoc()) {
            $adminNotifications[] = $row;
        }
        $notificationResult->free();
    }

    // Fetch all tickets for admin view
    $ticketResult = $conn->query(
        "SELECT
            ct.id,
            ct.coordinator_id,
            ct.student_profile_id,
            ct.college_id,
            ct.subject,
            ct.message,
            ct.status,
            ct.created_at,
            ct.updated_at,
            sp.first_name,
            sp.middle_name,
            sp.last_name,
            sp.email,
            c.name AS college_name,
            co.first_name AS coordinator_first_name,
            co.last_name AS coordinator_last_name
        FROM coordinator_tickets ct
        LEFT JOIN student_profiles sp ON sp.id = ct.student_profile_id
        LEFT JOIN colleges c ON c.id = ct.college_id
        LEFT JOIN coordinators co ON co.id = ct.coordinator_id
        ORDER BY ct.created_at DESC
        LIMIT 200"
    );
    if ($ticketResult instanceof mysqli_result) {
        while ($row = $ticketResult->fetch_assoc()) {
            $adminTickets[] = $row;
        }
        $ticketResult->free();
    }

    $conn->close();
}

$totalUsers = count($users);
$totalCourses = count($courses);
$totalStudents = count($students);
$totalFeeAssigned = 0.0;
$totalFeeCollected = 0.0;
$totalFeePending = 0.0;
$fullyPaidStudents = 0;

foreach ($students as $studentFee) {
    $totalFeeAssigned += (float)($studentFee['total_fee'] ?? 0);
    $totalFeeCollected += (float)($studentFee['paid_fee'] ?? 0);
    $pending = (float)($studentFee['remaining_fee'] ?? 0);
    $totalFeePending += $pending;
    if ($pending <= 0.009) {
        $fullyPaidStudents++;
    }
}

$feeCollectionPercent = $totalFeeAssigned > 0
    ? round(($totalFeeCollected / $totalFeeAssigned) * 100, 1)
    : 0.0;

$roleCounts = [
    'admin' => 0,
    'coordinator' => 0,
    'student' => 0,
];
foreach ($users as $usr) {
    $roleKey = strtolower((string)($usr['role'] ?? ''));
    if (isset($roleCounts[$roleKey])) {
        $roleCounts[$roleKey]++;
    }
}

$ticketStatusCounts = [
    'open' => 0,
    'in_progress' => 0,
    'resolved' => 0,
];
$coordinatorTicketLoad = [];
foreach ($adminTickets as $ticketMeta) {
    $status = (string)($ticketMeta['status'] ?? 'open');
    if (!isset($ticketStatusCounts[$status])) {
        $status = 'open';
    }
    $ticketStatusCounts[$status]++;

    $coordName = trim(
        (string)($ticketMeta['coordinator_first_name'] ?? '') . ' ' .
        (string)($ticketMeta['coordinator_last_name'] ?? '')
    );
    if ($coordName === '') {
        $coordName = 'Unassigned';
    }

    if (!isset($coordinatorTicketLoad[$coordName])) {
        $coordinatorTicketLoad[$coordName] = [
            'name' => $coordName,
            'total' => 0,
            'open' => 0,
            'in_progress' => 0,
            'resolved' => 0,
        ];
    }

    $coordinatorTicketLoad[$coordName]['total']++;
    $coordinatorTicketLoad[$coordName][$status]++;
}

uasort($coordinatorTicketLoad, static function (array $a, array $b): int {
    return ($b['total'] <=> $a['total']);
});

$collegePerformance = [];
foreach ($students as $studentRow) {
    $collegeName = trim((string)($studentRow['college_name'] ?? ''));
    if ($collegeName === '') {
        $collegeName = 'Unknown';
    }

    if (!isset($collegePerformance[$collegeName])) {
        $collegePerformance[$collegeName] = [
            'college' => $collegeName,
            'students' => 0,
            'assigned' => 0.0,
            'collected' => 0.0,
            'pending' => 0.0,
            'collection_percent' => 0.0,
        ];
    }

    $collegePerformance[$collegeName]['students']++;
    $collegePerformance[$collegeName]['assigned'] += (float)($studentRow['total_fee'] ?? 0);
    $collegePerformance[$collegeName]['collected'] += (float)($studentRow['paid_fee'] ?? 0);
    $collegePerformance[$collegeName]['pending'] += (float)($studentRow['remaining_fee'] ?? 0);
}

foreach ($collegePerformance as $key => $collegeRow) {
    $assigned = (float)$collegeRow['assigned'];
    $collected = (float)$collegeRow['collected'];
    $collegePerformance[$key]['collection_percent'] = $assigned > 0
        ? round(($collected / $assigned) * 100, 1)
        : 0.0;
}

uasort($collegePerformance, static function (array $a, array $b): int {
    return ($b['pending'] <=> $a['pending']);
});

$monthlyAdmissions = [];
foreach ($students as $studentRow) {
    $createdAt = (string)($studentRow['created_at'] ?? '');
    $timestamp = strtotime($createdAt);
    $monthKey = $timestamp ? date('Y-m', $timestamp) : 'Unknown';

    if (!isset($monthlyAdmissions[$monthKey])) {
        $monthlyAdmissions[$monthKey] = [
            'month' => $monthKey,
            'students' => 0,
            'assigned' => 0.0,
            'collected' => 0.0,
        ];
    }

    $monthlyAdmissions[$monthKey]['students']++;
    $monthlyAdmissions[$monthKey]['assigned'] += (float)($studentRow['total_fee'] ?? 0);
    $monthlyAdmissions[$monthKey]['collected'] += (float)($studentRow['paid_fee'] ?? 0);
}

ksort($monthlyAdmissions);
if (count($monthlyAdmissions) > 8) {
    $monthlyAdmissions = array_slice($monthlyAdmissions, -8, 8, true);
}

$totalTickets = count($adminTickets);
$resolvedTicketPercent = $totalTickets > 0
    ? round(($ticketStatusCounts['resolved'] / $totalTickets) * 100, 1)
    : 0.0;
$activeTicketCount = $ticketStatusCounts['open'] + $ticketStatusCounts['in_progress'];

$adminName    = htmlspecialchars((string)$user['name'],     ENT_QUOTES, 'UTF-8');
$adminLoginId = htmlspecialchars((string)$user['login_id'], ENT_QUOTES, 'UTF-8');
$initials     = strtoupper(substr((string)$user['name'], 0, 1));
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>Admin Dashboard — 3D Shikshan</title>
    <link rel="icon" type="image/png" href="../assets/logo.png" />
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;700&family=Space+Grotesk:wght@500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }

        :root {
            --bg: #f5f6f8;
            --surface: #ffffff;
            --surface-2: #eef0f4;
            --surface-3: #e7ebf2;
            --border: #e0e3ea;
            --text: #1a1d26;
            --text-muted: #6b7185;
            --accent: #0b8a5e;
            --accent-light: #0b8a5e14;
            --red: #d9435f;
            --shadow-soft: 0 8px 26px rgba(15, 23, 42, .06);
            --shadow-card: 0 14px 34px rgba(15, 23, 42, .08);
            --sidebar-w: 240px;
            --topbar-h: 62px;
            --radius: 14px;
            --transition: .22s cubic-bezier(.4,0,.2,1);
        }

        html { -webkit-tap-highlight-color: transparent; }

        body {
            font-family: 'DM Sans', sans-serif;
            background:
                radial-gradient(circle at 12% -10%, #cceadf 0%, transparent 38%),
                radial-gradient(circle at 95% 5%, #dceafe 0%, transparent 30%),
                var(--bg);
            color: var(--text);
            line-height: 1.6;
            -webkit-font-smoothing: antialiased;
            min-height: 100vh;
        }

        /* ── TOPBAR ─────────────────────────────────── */
        .topbar {
            position: fixed; top: 0; left: 0; right: 0; z-index: 200;
            height: var(--topbar-h);
            background: rgba(255,255,255,.82);
            border-bottom: 1px solid #d9deea;
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            display: flex; align-items: center;
            padding: 0 24px;
            gap: 16px;
        }

        .topbar-brand {
            display: flex; align-items: center; gap: 10px;
            font-family: 'Space Grotesk', sans-serif;
            font-size: 1.1rem; font-weight: 700;
            color: var(--accent); text-decoration: none;
            flex-shrink: 0;
        }
        .topbar-brand i {
            font-size: 1rem;
            width: 28px;
            height: 28px;
            border-radius: 8px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: var(--accent-light);
        }
        .topbar-brand span { color: var(--text); }

        .sidebar-toggle {
            background: none; border: none; cursor: pointer;
            color: var(--text-muted); font-size: 1.1rem;
            width: 36px; height: 36px; border-radius: 8px;
            display: flex; align-items: center; justify-content: center;
            transition: var(--transition);
            flex-shrink: 0;
        }
        .sidebar-toggle:hover { background: var(--surface-2); color: var(--text); }

        .topbar-spacer { flex: 1; }
        .topbar-actions { display: flex; align-items: center; gap: 10px; }
        .notification-btn {
            width: 38px; height: 38px; border-radius: 12px;
            border: 1px solid var(--border); background: var(--surface);
            color: var(--text-muted); cursor: pointer;
            display: inline-flex; align-items: center; justify-content: center;
            position: relative; transition: var(--transition);
        }
        .notification-btn:hover { border-color: var(--accent); background: var(--accent-light); color: var(--accent); }
        .notification-btn i { font-size: .95rem; }
        .notification-badge {
            position: absolute; top: -5px; right: -5px;
            min-width: 17px; height: 17px; border-radius: 999px;
            background: var(--red); color: #fff;
            border: 2px solid var(--surface);
            font-size: .62rem; font-weight: 700;
            display: inline-flex; align-items: center; justify-content: center;
            padding: 0 4px;
        }
        .notification-wrap { position: relative; }
        .notification-dropdown {
            position: absolute;
            top: calc(100% + 10px);
            right: 0;
            width: min(390px, 92vw);
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            box-shadow: 0 14px 40px rgba(15, 23, 42, .16);
            opacity: 0;
            pointer-events: none;
            transform: translateY(-8px);
            transition: opacity var(--transition), transform var(--transition);
            z-index: 999;
        }
        .notification-wrap.open .notification-dropdown {
            opacity: 1;
            pointer-events: auto;
            transform: translateY(0);
        }
        .notification-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            padding: 12px 14px;
            border-bottom: 1px solid var(--border);
        }
        .notification-head-left {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }
        .notification-head strong {
            font-size: .88rem;
            line-height: 1;
        }
        .notification-head span {
            font-size: .72rem;
            color: var(--text-muted);
            font-weight: 700;
        }
        .notification-mark-all {
            border: 1px solid var(--border);
            background: var(--surface-2);
            color: var(--text);
            border-radius: 8px;
            padding: 6px 9px;
            font-size: .69rem;
            font-weight: 700;
            cursor: pointer;
            transition: var(--transition);
        }
        .notification-mark-all:hover {
            border-color: var(--accent);
            color: var(--accent);
            background: #fff;
        }
        .notification-list {
            max-height: 330px;
            overflow: auto;
        }
        .notification-item {
            padding: 12px 14px;
            border-bottom: 1px solid var(--border);
            background: #fff;
        }
        .notification-item.is-unread {
            background: #edf9f3;
        }
        .notification-item:last-child {
            border-bottom: none;
        }
        .notification-item-title {
            font-size: .8rem;
            font-weight: 700;
            color: var(--text);
            margin-bottom: 3px;
        }
        .notification-item-msg {
            font-size: .76rem;
            color: var(--text-muted);
            line-height: 1.45;
            margin-bottom: 5px;
        }
        .notification-item-time {
            font-size: .7rem;
            color: #94a3b8;
        }
        .notification-item-meta {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
        }
        .notification-pill {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 2px 8px;
            border-radius: 999px;
            font-size: .64rem;
            font-weight: 700;
            color: #0f172a;
            background: #e2e8f0;
            white-space: nowrap;
        }
        .notification-pill.unread {
            color: #065f46;
            background: #d1fae5;
        }
        .notification-empty {
            padding: 18px 14px;
            font-size: .8rem;
            color: var(--text-muted);
            text-align: center;
        }

        /* Profile section */
        .profile-wrap { position: relative; }

        .profile-btn {
            display: flex; align-items: center; gap: 10px;
            background: rgba(255,255,255,.72); border: 1px solid var(--border);
            border-radius: 40px; padding: 5px 14px 5px 5px;
            cursor: pointer; transition: var(--transition);
            color: var(--text);
        }
        .profile-btn:hover { background: #fff; border-color: var(--accent); }

        .profile-avatar {
            width: 34px; height: 34px; border-radius: 50%;
            background: var(--accent);
            display: flex; align-items: center; justify-content: center;
            color: #fff; font-size: .85rem; font-weight: 700;
            flex-shrink: 0;
        }
        .profile-name {
            font-size: .85rem; font-weight: 600;
            max-width: 110px; overflow: hidden;
            text-overflow: ellipsis; white-space: nowrap;
        }
        .profile-chevron {
            font-size: .65rem; color: var(--text-muted);
            transition: transform var(--transition);
        }
        .profile-wrap.open .profile-chevron { transform: rotate(180deg); }

        /* Dropdown */
        .profile-dropdown {
            position: absolute; top: calc(100% + 10px); right: 0;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            box-shadow: 0 8px 32px rgba(0,0,0,.12);
            min-width: 220px; z-index: 999;
            opacity: 0; pointer-events: none;
            transform: translateY(-8px);
            transition: opacity var(--transition), transform var(--transition);
        }
        .profile-wrap.open .profile-dropdown {
            opacity: 1; pointer-events: auto; transform: translateY(0);
        }

        .dropdown-header {
            padding: 16px;
            border-bottom: 1px solid var(--border);
        }
        .dropdown-header .d-name {
            font-weight: 700; font-size: .9rem;
        }
        .dropdown-header .d-id {
            font-size: .75rem; color: var(--text-muted); margin-top: 1px;
        }
        .dropdown-header .d-badge {
            display: inline-block; margin-top: 6px;
            font-size: .62rem; font-weight: 700; text-transform: uppercase;
            letter-spacing: 1px; padding: 3px 9px; border-radius: 20px;
            background: var(--accent-light); color: var(--accent);
        }

        .dropdown-menu-list { padding: 8px; }
        .dropdown-item {
            display: flex; align-items: center; gap: 10px;
            padding: 10px 12px; border-radius: 8px;
            font-size: .84rem; color: var(--text);
            text-decoration: none; cursor: pointer;
            border: none; background: none; width: 100%;
            text-align: left; transition: var(--transition);
        }
        .dropdown-item:hover { background: var(--surface-2); }
        .dropdown-item i { width: 16px; text-align: center; color: var(--text-muted); }
        .dropdown-item.danger { color: var(--red); }
        .dropdown-item.danger i { color: var(--red); }
        .dropdown-divider { height: 1px; background: var(--border); margin: 4px 8px; }

        /* ── SIDEBAR ────────────────────────────────── */
        .sidebar {
            position: fixed; top: var(--topbar-h); left: 0; bottom: 0;
            width: var(--sidebar-w);
            background: linear-gradient(180deg, #ffffff 0%, #f9fafc 100%);
            border-right: 1px solid var(--border);
            display: flex; flex-direction: column;
            z-index: 100;
            transform: translateX(0);
            transition: transform var(--transition), width var(--transition);
            overflow: hidden;
        }
        .sidebar.collapsed { transform: translateX(calc(-1 * var(--sidebar-w))); }

        .sidebar-section { padding: 8px; flex: 1; overflow-y: auto; }
        .sidebar-label {
            font-size: .6rem; font-weight: 700; text-transform: uppercase;
            letter-spacing: 1.4px; color: var(--text-muted);
            padding: 10px 12px 4px;
        }

        .nav-item {
            display: flex; align-items: center; gap: 12px;
            padding: 10px 12px; border-radius: 10px;
            font-size: .87rem; font-weight: 500;
            color: var(--text-muted); text-decoration: none;
            cursor: pointer; border: none; background: none; width: 100%;
            text-align: left; transition: var(--transition);
            position: relative;
        }
        .nav-item i { width: 18px; text-align: center; font-size: .95rem; flex-shrink: 0; }
        .nav-item:hover {
            background: var(--surface-2);
            color: var(--text);
            transform: translateX(2px);
        }
        .nav-item.active {
            background: var(--accent-light);
            color: var(--accent); font-weight: 600;
        }
        .nav-item.active::before {
            content: '';
            position: absolute;
            left: -8px;
            top: 9px;
            bottom: 9px;
            width: 3px;
            border-radius: 4px;
            background: var(--accent);
        }
        .nav-item.active i { color: var(--accent); }
        .nav-badge {
            margin-left: auto; background: var(--accent);
            color: #fff; font-size: .6rem; font-weight: 700;
            padding: 2px 7px; border-radius: 20px;
        }

        .sidebar-footer {
            padding: 12px;
            border-top: 1px solid var(--border);
        }

        /* ── MAIN CONTENT ───────────────────────────── */
        .main-content {
            margin-top: var(--topbar-h);
            margin-left: var(--sidebar-w);
            padding: 28px 28px 40px;
            transition: margin-left var(--transition);
            min-height: calc(100vh - var(--topbar-h));
        }
        .main-content.expanded { margin-left: 0; }

        .dashboard-hero {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 18px;
            flex-wrap: wrap;
        }
        .hero-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        .hero-chip {
            border: 1px solid #ccd5e3;
            background: rgba(255,255,255,.7);
            color: var(--text);
            border-radius: 999px;
            padding: 8px 12px;
            font-size: .76rem;
            font-weight: 700;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 7px;
            transition: var(--transition);
        }
        .hero-chip:hover {
            border-color: var(--accent);
            color: var(--accent);
            background: #fff;
            transform: translateY(-1px);
        }

        /* ── CARDS ──────────────────────────────────── */
        .page-heading {
            font-family: 'Space Grotesk', sans-serif;
            font-size: 1.42rem;
            font-weight: 700;
            margin-bottom: 4px;
            letter-spacing: -.2px;
        }
        .page-sub { color: var(--text-muted); font-size: .86rem; margin-bottom: 24px; }

        .stat-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
            gap: 14px; margin-bottom: 24px;
        }
        .stat-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 18px 18px 14px;
            box-shadow: var(--shadow-soft);
            transition: transform var(--transition), box-shadow var(--transition), border-color var(--transition);
        }
        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-card);
            border-color: #d2d9e8;
        }
        .stat-icon {
            width: 38px; height: 38px; border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            font-size: .95rem; margin-bottom: 12px;
        }
        .si-green { background: #0b8a5e14; color: var(--accent); }
        .si-blue  { background: #2e7bbf14; color: #2e7bbf; }
        .si-red   { background: #d9435f12; color: var(--red); }
        .si-yellow{ background: #c08b1d14; color: #c08b1d; }

        .stat-val {
            font-family: 'Space Grotesk', sans-serif;
            font-size: 1.6rem; font-weight: 700; line-height: 1;
        }
        .stat-lbl { font-size: .75rem; color: var(--text-muted); margin-top: 4px; }

        .info-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 20px 22px;
            box-shadow: var(--shadow-soft);
        }
        .info-card h3 {
            font-family: 'Space Grotesk', sans-serif;
            font-size: 1rem; font-weight: 700; margin-bottom: 14px;
        }
        .info-row {
            display: flex; align-items: center; gap: 10px;
            padding: 10px 0; border-bottom: 1px solid var(--border);
            font-size: .86rem;
        }
        .info-row:last-child { border-bottom: none; padding-bottom: 0; }
        .info-row i { color: var(--accent); width: 16px; text-align: center; }
        .info-key { color: var(--text-muted); min-width: 90px; }
        .info-val { font-weight: 600; }

        /* ── FORM ───────────────────────────────────── */
        .form-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 24px 24px 28px;
            max-width: 640px;
            box-shadow: var(--shadow-soft);
        }
        .form-card h3 {
            font-family: 'Space Grotesk', sans-serif;
            font-size: 1rem; font-weight: 700; margin-bottom: 18px;
            display: flex; align-items: center; gap: 10px;
        }
        .form-card h3 i { color: var(--accent); }
        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px;
        }
        .form-grid .full { grid-column: 1 / -1; }
        .f-group { display: flex; flex-direction: column; gap: 5px; }
        .f-group label {
            font-size: .75rem; font-weight: 600;
            color: var(--text-muted); text-transform: uppercase;
            letter-spacing: .6px;
        }
        .f-group input, .f-group select, .f-group textarea {
            padding: 10px 13px;
            background: var(--bg);
            border: 1px solid var(--border);
            border-radius: 9px;
            color: var(--text);
            font-family: 'DM Sans', sans-serif;
            font-size: .88rem;
            outline: none;
            transition: var(--transition);
            width: 100%;
        }
        .f-group input:focus, .f-group select:focus, .f-group textarea:focus {
            border-color: var(--accent);
            box-shadow: 0 0 0 3px #0b8a5e12;
        }
        .f-group input::placeholder { color: #b0b5c1; }
        .f-group textarea {
            min-height: 92px;
            resize: vertical;
        }
        .f-group textarea::placeholder { color: #b0b5c1; }
        .btn-submit {
            margin-top: 20px;
            padding: 11px 28px;
            background: var(--accent); color: #fff;
            border: none; border-radius: 9px;
            font-family: 'DM Sans', sans-serif;
            font-size: .9rem; font-weight: 700;
            cursor: pointer; transition: var(--transition);
            display: inline-flex; align-items: center; gap: 8px;
            box-shadow: 0 4px 14px rgba(11,138,94,.22);
        }
        .btn-submit:hover { background: #097a52; }
        .btn-submit:active { transform: scale(.97); }
        .btn-submit:disabled { opacity: .6; cursor: not-allowed; }

        .section-toolbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 14px;
            flex-wrap: wrap;
        }
        .btn-add-college {
            padding: 10px 16px;
            background: var(--accent);
            color: #fff;
            border: none;
            border-radius: 9px;
            font-family: 'DM Sans', sans-serif;
            font-size: .84rem;
            font-weight: 700;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 6px 16px rgba(11,138,94,.18);
            transition: var(--transition);
        }
        .btn-add-college:hover {
            background: #097a52;
            transform: translateY(-1px);
        }

        .college-list-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            overflow: hidden;
            margin-bottom: 14px;
            box-shadow: var(--shadow-soft);
        }
        .college-list-wrap {
            overflow-x: auto;
        }
        .college-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 660px;
        }
        .college-table th,
        .college-table td {
            padding: 12px 14px;
            font-size: .82rem;
            text-align: left;
            border-bottom: 1px solid var(--border);
            white-space: nowrap;
        }
        .college-table th {
            font-size: .72rem;
            letter-spacing: .8px;
            text-transform: uppercase;
            color: var(--text-muted);
            background: #f2f5fa;
            position: sticky;
            top: 0;
            z-index: 1;
        }
        .college-table tbody tr:nth-child(even) td {
            background: #fbfcfe;
        }
        .college-table tbody tr:hover td {
            background: #f4f8ff;
        }
        .college-table tbody tr:last-child td {
            border-bottom: none;
        }
        .college-empty {
            padding: 20px 16px;
            color: var(--text-muted);
            font-size: .84rem;
        }

        .role-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 3px 9px;
            border-radius: 999px;
            font-size: .67rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .7px;
            border: 1px solid transparent;
        }
        .role-badge.role-admin {
            color: #7c2d12;
            background: #ffedd5;
            border-color: #fdba74;
        }
        .role-badge.role-coordinator {
            color: #1d4ed8;
            background: #dbeafe;
            border-color: #93c5fd;
        }
        .role-badge.role-student {
            color: #166534;
            background: #dcfce7;
            border-color: #86efac;
        }
        .subtle-cell {
            color: var(--text-muted);
            font-size: .78rem;
        }

        .fee-chip {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 14px;
            font-size: .68rem;
            font-weight: 700;
            letter-spacing: .35px;
            white-space: nowrap;
        }
        .fee-chip.total { background: #dbeafe; color: #1d4ed8; }
        .fee-chip.paid { background: #dcfce7; color: #166534; }
        .fee-chip.pending { background: #ffedd5; color: #9a3412; }

        .row-actions {
            display: flex;
            align-items: center;
            gap: 6px;
            flex-wrap: wrap;
        }
        .action-btn {
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 7px 10px;
            font-size: .74rem;
            font-weight: 700;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: var(--transition);
            background: var(--surface-2);
            color: var(--text);
        }
        .action-btn.view:hover {
            border-color: #60a5fa;
            color: #1d4ed8;
            background: #eff6ff;
        }
        .action-btn.delete:hover {
            border-color: #fda4af;
            color: #be123c;
            background: #fff1f2;
        }

        .student-filter-bar {
            display: grid;
            grid-template-columns: minmax(220px, 1.25fr) minmax(140px, .85fr) minmax(110px, .6fr) minmax(110px, .6fr) auto;
            gap: 10px;
            align-items: center;
            margin-bottom: 12px;
            background: linear-gradient(180deg, #ffffff 0%, #fbfcfe 100%);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 10px;
            box-shadow: var(--shadow-soft);
        }
        .student-filter-field {
            position: relative;
            min-width: 0;
        }
        .student-filter-icon {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-muted);
            font-size: .8rem;
            pointer-events: none;
        }
        .student-filter-input,
        .student-filter-select {
            width: 100%;
            height: 40px;
            border: 1px solid #d7dee9;
            border-radius: 10px;
            background: #fff;
            color: var(--text);
            font-size: .82rem;
            font-family: 'DM Sans', sans-serif;
            outline: none;
            transition: border-color var(--transition), box-shadow var(--transition);
        }
        .student-filter-input {
            padding: 0 12px 0 36px;
        }
        .student-filter-select {
            padding: 0 32px 0 36px;
            appearance: none;
            -webkit-appearance: none;
            -moz-appearance: none;
        }
        .student-filter-input:focus,
        .student-filter-select:focus {
            border-color: var(--accent);
            box-shadow: 0 0 0 4px rgba(11, 138, 94, .12);
        }
        .student-filter-caret {
            position: absolute;
            right: 11px;
            top: 50%;
            transform: translateY(-50%);
            color: #64748b;
            font-size: .68rem;
            pointer-events: none;
        }
        .student-filter-count {
            justify-self: end;
            font-size: .76rem;
            font-weight: 700;
            color: #475569;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 999px;
            padding: 7px 11px;
            white-space: nowrap;
        }

        .student-cards {
            display: none;
            gap: 8px;
        }
        .student-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 10px;
            box-shadow: var(--shadow-soft);
        }
        .student-card h4 {
            font-family: 'Space Grotesk', sans-serif;
            font-size: .9rem;
            margin-bottom: 6px;
        }
        .student-meta {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 6px;
            font-size: .74rem;
            color: var(--text-muted);
        }
        .student-meta strong {
            display: block;
            font-size: .62rem;
            text-transform: uppercase;
            letter-spacing: .5px;
            margin-bottom: 1px;
            color: var(--text);
        }

        .student-modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(2, 6, 23, .45);
            z-index: 1300;
            padding: 16px;
            align-items: center;
            justify-content: center;
        }
        .student-modal-overlay.show { display: flex; }
        .student-modal {
            width: min(860px, 100%);
            max-height: 92vh;
            overflow: auto;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 16px;
            box-shadow: 0 18px 50px rgba(15, 23, 42, .18);
        }
        .student-modal-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            margin-bottom: 12px;
        }
        .student-modal-head-actions {
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .student-modal-head h3 {
            font-family: 'Space Grotesk', sans-serif;
            font-size: 1.02rem;
        }
        .student-delete-btn {
            border: 1px solid #fca5a5;
            background: #fff1f2;
            color: #be123c;
            border-radius: 10px;
            padding: 7px 10px;
            font-size: .74rem;
            font-weight: 700;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .student-close {
            border: 1px solid var(--border);
            background: var(--surface-2);
            width: 34px;
            height: 34px;
            border-radius: 10px;
            cursor: pointer;
            color: var(--text);
        }
        .student-detail-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px;
        }
        .student-detail-item {
            border: 1px solid var(--border);
            background: #f9fafc;
            border-radius: 10px;
            padding: 10px 11px;
        }
        .student-detail-item strong {
            display: block;
            font-size: .68rem;
            text-transform: uppercase;
            letter-spacing: .45px;
            color: var(--text-muted);
            margin-bottom: 3px;
        }
        .student-detail-item span {
            font-size: .84rem;
            color: var(--text);
            line-height: 1.4;
            word-break: break-word;
        }
        .student-detail-item.full { grid-column: 1 / -1; }
        .payment-history {
            margin-top: 12px;
            border: 1px solid var(--border);
            border-radius: 12px;
            overflow: hidden;
            background: #fff;
        }
        .payment-history-head {
            padding: 10px 12px;
            font-weight: 700;
            font-size: .82rem;
            border-bottom: 1px solid var(--border);
            background: #f3f6fb;
        }
        .payment-history-list {
            max-height: 280px;
            overflow: auto;
        }
        .payment-row {
            padding: 10px 12px;
            border-bottom: 1px solid var(--border);
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 8px;
        }
        .payment-row:last-child { border-bottom: none; }
        .payment-main {
            font-size: .8rem;
            color: var(--text);
        }
        .payment-sub {
            font-size: .72rem;
            color: var(--text-muted);
            margin-top: 2px;
        }
        .payment-amount {
            font-size: .8rem;
            font-weight: 700;
            color: #166534;
            white-space: nowrap;
        }

        .hidden { display: none !important; }

        /* Toast */
        .toast {
            position: fixed; bottom: 28px; right: 28px; z-index: 9999;
            padding: 13px 18px; border-radius: 10px;
            font-size: .86rem; font-weight: 600;
            display: flex; align-items: center; gap: 9px;
            box-shadow: 0 6px 24px rgba(0,0,0,.14);
            opacity: 0; pointer-events: none;
            transform: translateY(10px);
            transition: opacity .3s, transform .3s;
        }
        .toast.show { opacity: 1; pointer-events: auto; transform: translateY(0); }
        .toast.success { background: #0b8a5e; color: #fff; }
        .toast.error   { background: var(--red); color: #fff; }

        @media (max-width: 500px) { .form-grid { grid-template-columns: 1fr; } }

        /* ── OVERLAY (mobile sidebar) ───────────────── */
        .sidebar-overlay {
            display: none; position: fixed; inset: 0;
            background: rgba(0,0,0,.3); z-index: 99;
        }
        .sidebar-overlay.visible { display: block; }

        /* ── RESPONSIVE ─────────────────────────────── */
        @media (max-width: 700px) {
            .sidebar { transform: translateX(calc(-1 * var(--sidebar-w))); }
            .sidebar.open { transform: translateX(0); }
            .main-content { margin-left: 0; padding: 18px 14px 30px; }
            .profile-name { display: none; }
            .topbar-brand span { display: none; }
            .topbar { padding: 0 14px; }
            .notification-btn { width: 34px; height: 34px; border-radius: 10px; }
            .notification-dropdown {
                position: fixed;
                top: var(--topbar-h);
                left: 8px;
                right: 8px;
                width: auto;
                max-width: none;
            }
            .notification-wrap.open .notification-dropdown {
                transform: translateY(0);
            }
            .page-heading { font-size: 1.22rem; }
            .hero-actions { width: 100%; }
            .hero-chip { flex: 1; justify-content: center; }
            .students-table-wrap { display: none; }
            .student-cards { display: grid; }
            .student-meta { grid-template-columns: 1fr; }
            .student-filter-bar {
                grid-template-columns: 1fr 1fr;
                gap: 8px;
            }
            .student-filter-count {
                justify-self: start;
            }
            .row-actions { width: 100%; }
            .action-btn { flex: 1; justify-content: center; }
            .student-modal-overlay { padding: 0; align-items: stretch; }
            .student-modal {
                width: 100%;
                max-height: 100vh;
                border-radius: 0;
                border: none;
                padding: 14px;
            }
            .student-modal-head { align-items: flex-start; }
            .student-modal-head-actions { width: 100%; justify-content: flex-end; }
            .student-detail-grid { grid-template-columns: 1fr; }
        }

        /* ── TICKETS ────────────────────────────────── */
        .tickets-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 10px;
        }
        .tickets-head {
            padding: 6px 2px 10px;
            border-bottom: 1px dashed #dbe2ea;
            font-size: .74rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .9px;
            color: var(--text-muted);
            margin: 0 0 8px;
        }
        .ticket-filter-bar {
            display: grid;
            grid-template-columns: minmax(260px,1.2fr) minmax(170px,.6fr) auto auto;
            gap: 10px;
            align-items: center;
            margin-bottom: 12px;
            background: linear-gradient(180deg,#fff 0%,#fbfcfd 100%);
            border: 1px solid #d8dde6;
            border-radius: 14px;
            padding: 10px;
        }
        .ticket-field {
            position: relative;
            min-width: 0;
        }
        .ticket-icon {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-muted);
            font-size: .8rem;
            pointer-events: none;
        }
        .ticket-search, .ticket-status-filter {
            width: 100%;
            height: 40px;
            border: 1px solid #dbe2ea;
            border-radius: 10px;
            background: #fff;
            outline: none;
            font-size: .82rem;
            color: var(--text);
            font-family: 'DM Sans', sans-serif;
            transition: border-color var(--transition), box-shadow var(--transition);
        }
        .ticket-search {
            padding: 0 12px 0 36px;
        }
        .ticket-status-filter {
            padding: 0 34px 0 36px;
            appearance: none;
            -webkit-appearance: none;
            -moz-appearance: none;
        }
        .ticket-search:focus, .ticket-status-filter:focus {
            border-color: var(--accent);
            box-shadow: 0 0 0 4px rgba(11,138,94,.12);
        }
        .ticket-select-caret {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            font-size: .68rem;
            color: #64748b;
            pointer-events: none;
        }
        .ticket-filter-count {
            justify-self: end;
            font-size: .76rem;
            font-weight: 700;
            color: #475569;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            padding: 7px 11px;
            border-radius: 999px;
            white-space: nowrap;
        }
        .ticket-filter-reset {
            height: 40px;
            border: 1px solid #cbd5e1;
            border-radius: 10px;
            background: #ffffff;
            color: #334155;
            font-size: .78rem;
            font-weight: 700;
            padding: 0 12px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            transition: var(--transition);
            white-space: nowrap;
        }
        .ticket-filter-reset:hover {
            border-color: var(--accent);
            color: var(--accent);
            background: #f0fdf4;
        }
        .ticket-row {
            padding: 10px 11px;
            border: 1px solid #e2e8f0;
            border-left: 4px solid #cbd5e1;
            border-radius: 12px;
            background: #ffffff;
            box-shadow: 0 8px 16px rgba(15,23,42,.04);
            margin-bottom: 8px;
        }
        .ticket-row:last-child {
            margin-bottom: 0;
        }
        .ticket-row.ticket-open {
            border-left-color: #f59e0b;
        }
        .ticket-row.ticket-in-progress {
            border-left-color: #3b82f6;
        }
        .ticket-row.ticket-resolved {
            border-left-color: #16a34a;
            background: #fbfffc;
        }
        .ticket-row-top {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 8px;
            margin-bottom: 4px;
        }
        .ticket-context {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: .66rem;
            font-weight: 700;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: .6px;
            margin-bottom: 3px;
        }
        .ticket-context-dot {
            width: 4px;
            height: 4px;
            border-radius: 999px;
            background: #94a3b8;
        }
        .ticket-subject {
            font-size: .82rem;
            font-weight: 700;
            color: var(--text);
            line-height: 1.35;
        }
        .ticket-meta {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 6px;
            font-size: .7rem;
            color: #64748b;
            margin-bottom: 4px;
            line-height: 1.35;
        }
        .ticket-meta-item {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            background: #f8fafc;
            padding: 4px 7px;
            min-width: 0;
        }
        .ticket-meta-item strong {
            font-weight: 700;
            color: var(--text);
        }
        .ticket-meta-item span {
            min-width: 0;
            word-break: break-word;
            overflow-wrap: anywhere;
        }
        .ticket-message {
            font-size: .76rem;
            color: var(--text-muted);
            white-space: pre-line;
            line-height: 1.4;
            display: -webkit-box;
            -webkit-line-clamp: 3;
            -webkit-box-orient: vertical;
            overflow: hidden;
            padding-top: 4px;
            border-top: 1px dashed #e2e8f0;
        }
        .ticket-status {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 3px 7px;
            border-radius: 999px;
            font-size: .62rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .5px;
            border: 1px solid transparent;
            white-space: nowrap;
        }
        .ticket-status.open {
            background: #ffedd5;
            border-color: #fdba74;
            color: #9a3412;
        }
        .ticket-status.in-progress {
            background: #dbeafe;
            border-color: #93c5fd;
            color: #1d4ed8;
        }
        .ticket-status.resolved {
            background: #dcfce7;
            border-color: #86efac;
            color: #166534;
        }
        .empty-box {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 26px;
            color: var(--text-muted);
            text-align: center;
            font-size: .86rem;
        }

        /* ── FEES ───────────────────────────────────── */
        .fees-summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
            gap: 12px;
            margin-bottom: 14px;
        }
        .fees-summary-card {
            border: 1px solid var(--border);
            border-radius: 12px;
            background: #fff;
            padding: 11px 12px;
        }
        .fees-summary-label {
            font-size: .67rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .7px;
            color: var(--text-muted);
            margin-bottom: 5px;
        }
        .fees-summary-value {
            font-family: 'Space Grotesk', sans-serif;
            font-size: 1.05rem;
            font-weight: 700;
            color: #1e293b;
            line-height: 1.2;
        }
        .fees-summary-hint {
            margin-top: 3px;
            font-size: .72rem;
            color: #64748b;
        }
        .fee-filter-bar {
            display: grid;
            grid-template-columns: minmax(260px, 1.3fr) minmax(170px, .7fr) auto auto;
            gap: 10px;
            align-items: center;
            margin-bottom: 12px;
            background: linear-gradient(180deg,#fff 0%,#fbfcfd 100%);
            border: 1px solid #d8dde6;
            border-radius: 14px;
            padding: 10px;
        }
        .fee-filter-count {
            justify-self: end;
            font-size: .76rem;
            font-weight: 700;
            color: #475569;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            padding: 7px 11px;
            border-radius: 999px;
            white-space: nowrap;
        }
        .fee-status {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 999px;
            font-size: .64rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .5px;
            padding: 3px 8px;
            border: 1px solid transparent;
            white-space: nowrap;
        }
        .fee-status.fully-paid {
            color: #166534;
            background: #dcfce7;
            border-color: #86efac;
        }
        .fee-status.partial {
            color: #1d4ed8;
            background: #dbeafe;
            border-color: #93c5fd;
        }
        .fee-status.unpaid {
            color: #9a3412;
            background: #ffedd5;
            border-color: #fdba74;
        }
        .fee-amount {
            font-weight: 700;
            color: #1f2937;
        }
        .fee-amount.pending {
            color: #b45309;
        }

        /* ── REPORTS ───────────────────────────────── */
        .report-kpi-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 12px;
            margin-bottom: 14px;
        }
        .report-kpi-card {
            border: 1px solid var(--border);
            border-radius: 12px;
            background: #fff;
            padding: 12px;
        }
        .report-kpi-label {
            font-size: .68rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .65px;
            color: var(--text-muted);
            margin-bottom: 5px;
        }
        .report-kpi-value {
            font-family: 'Space Grotesk', sans-serif;
            font-size: 1.05rem;
            font-weight: 700;
            color: #1e293b;
            line-height: 1.2;
        }
        .report-kpi-sub {
            margin-top: 3px;
            font-size: .72rem;
            color: #64748b;
        }
        .report-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px;
        }
        .report-card {
            border: 1px solid var(--border);
            border-radius: 12px;
            background: #fff;
            overflow: hidden;
        }
        .report-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            border-bottom: 1px solid var(--border);
            padding: 10px 12px;
        }
        .report-head h3 {
            font-size: .86rem;
            font-family: 'Space Grotesk', sans-serif;
            font-weight: 700;
            color: #1f2937;
        }
        .report-head span {
            font-size: .72rem;
            color: #64748b;
            font-weight: 600;
        }
        .report-table-wrap {
            overflow: auto;
        }
        .report-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 520px;
        }
        .report-table th,
        .report-table td {
            padding: 9px 10px;
            border-bottom: 1px solid #ecf0f4;
            text-align: left;
            white-space: nowrap;
            font-size: .76rem;
            color: #334155;
        }
        .report-table th {
            font-size: .67rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .6px;
            color: #64748b;
            background: #f8fafc;
        }
        .report-table tbody tr:last-child td {
            border-bottom: none;
        }
        .report-pill {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 3px 8px;
            border-radius: 999px;
            font-size: .64rem;
            font-weight: 700;
            letter-spacing: .4px;
            text-transform: uppercase;
            border: 1px solid transparent;
            white-space: nowrap;
        }
        .report-pill.good {
            background: #dcfce7;
            border-color: #86efac;
            color: #166534;
        }
        .report-pill.warn {
            background: #ffedd5;
            border-color: #fdba74;
            color: #9a3412;
        }
        .report-pill.info {
            background: #dbeafe;
            border-color: #93c5fd;
            color: #1d4ed8;
        }
        @media (max-width: 980px) {
            .ticket-filter-bar {
                grid-template-columns: minmax(0,1fr) minmax(0,1fr);
            }
            .ticket-filter-count {
                justify-self: start;
            }
            .ticket-filter-reset {
                justify-self: end;
            }
            .fee-filter-bar {
                grid-template-columns: minmax(0,1fr) minmax(0,1fr);
            }
            .fee-filter-count {
                justify-self: start;
            }
            .report-grid {
                grid-template-columns: 1fr;
            }
        }
        @media (max-width: 700px) {
            .ticket-filter-bar {
                grid-template-columns: 1fr;
                gap: 8px;
                padding: 8px;
            }
            .ticket-filter-count,
            .ticket-filter-reset {
                width: 100%;
                justify-self: stretch;
                justify-content: center;
            }
            .ticket-row {
                padding: 8px 9px;
            }
            .ticket-row-top {
                flex-direction: column;
                gap: 5px;
                margin-bottom: 5px;
            }
            .ticket-status {
                align-self: flex-start;
            }
            .ticket-meta {
                grid-template-columns: 1fr;
                gap: 5px;
            }
            .ticket-meta-item {
                width: 100%;
                border-radius: 10px;
                padding: 4px 7px;
            }
            .fee-filter-bar {
                grid-template-columns: 1fr;
                gap: 8px;
                padding: 8px;
            }
            .fee-filter-count,
            .ticket-filter-reset {
                width: 100%;
                justify-self: stretch;
                justify-content: center;
            }
        }
        /* Colleges Popover */
        .colleges-badge {
            background: var(--surface-2);
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 5px 10px;
            font-size: 0.78rem;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            color: var(--accent);
            cursor: pointer;
            font-weight: 600;
            transition: background 0.18s, border-color 0.18s;
            user-select: none;
            position: relative;
        }
        .colleges-badge:hover { background: var(--accent-light); border-color: var(--accent); }
        .colleges-badge-none {
            background: #fff1f2;
            border: 1px solid #ffe4e6;
            border-radius: 8px;
            padding: 5px 10px;
            font-size: 0.78rem;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            color: #be123c;
        }
        /* Global popover */
        #collegesPopover {
            position: fixed;
            z-index: 99999;
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 14px;
            box-shadow: 0 8px 32px rgba(0,0,0,.15);
            min-width: 240px;
            max-width: 340px;
            padding: 0;
            display: none;
            flex-direction: column;
        }
        #collegesPopover.visible { display: flex; }
        .clg-popover-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px 16px 10px;
            border-bottom: 1px solid var(--border);
        }
        .clg-popover-title {
            font-size: 0.82rem;
            font-weight: 700;
            color: var(--text);
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .clg-popover-title i { color: var(--accent); }
        .clg-popover-close {
            background: none;
            border: none;
            cursor: pointer;
            color: var(--text-muted);
            font-size: 0.9rem;
            padding: 2px 6px;
            border-radius: 6px;
            line-height: 1;
        }
        .clg-popover-close:hover { background: var(--surface-2); color: var(--text); }
        .clg-popover-list {
            padding: 10px 12px;
            display: flex;
            flex-direction: column;
            gap: 5px;
            max-height: 260px;
            overflow-y: auto;
        }
        .clg-popover-item {
            padding: 7px 10px;
            border-radius: 8px;
            background: var(--surface-2);
            font-size: 0.8rem;
            color: var(--text);
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .clg-popover-item i { color: var(--accent); font-size: 0.72rem; flex-shrink: 0; }
        /* Popover backdrop (for mobile) */
        #collegesPopoverBackdrop {
            display: none;
            position: fixed;
            inset: 0;
            z-index: 99998;
        }
        #collegesPopoverBackdrop.visible { display: block; }
    </style>
</head>
<body>

<!-- ── TOPBAR ─────────────────────────────────────── -->
<header class="topbar">
    <button class="sidebar-toggle" id="sidebarToggle" aria-label="Toggle navigation">
        <i class="fa-solid fa-bars"></i>
    </button>

    <a href="#" class="topbar-brand">
        <img src="../assets/logo.png" alt="Logo" style="height: 28px; width: auto; object-fit: contain;">
        3D <span>Shikshan</span>
    </a>

    <div class="topbar-spacer"></div>

    <div class="topbar-actions">
        <div class="notification-wrap" id="notificationWrap">
            <button type="button" class="notification-btn" id="notificationBtn" aria-label="Notifications">
                <i class="fa-regular fa-bell"></i>
                <span class="notification-badge" id="alertsBadge"><?php echo $unreadAlerts; ?></span>
            </button>

            <div class="notification-dropdown" id="notificationDropdown">
                <div class="notification-head">
                    <div class="notification-head-left">
                        <strong>Notifications</strong>
                        <span id="notificationTotalText"><?php echo $totalNotificationGroups; ?> total</span>
                    </div>
                    <button type="button" class="notification-mark-all" id="notificationMarkAllBtn">Mark all read</button>
                </div>
                <div class="notification-list" id="notificationList">
                    <?php if (!empty($adminNotifications)): ?>
                        <?php foreach ($adminNotifications as $notification): ?>
                            <div class="notification-item <?php echo ((int)($notification['is_read'] ?? 0) === 0) ? 'is-unread' : ''; ?>">
                                <div class="notification-item-title"><?php echo htmlspecialchars((string)$notification['title'], ENT_QUOTES, 'UTF-8'); ?></div>
                                <div class="notification-item-msg"><?php echo htmlspecialchars((string)$notification['message'], ENT_QUOTES, 'UTF-8'); ?></div>
                                <div class="notification-item-meta">
                                    <div class="notification-item-time"><?php echo htmlspecialchars((string)$notification['created_at'], ENT_QUOTES, 'UTF-8'); ?></div>
                                    <?php if ((int)($notification['duplicate_count'] ?? 1) > 1): ?>
                                        <span class="notification-pill">Sent to <?php echo (int)$notification['duplicate_count']; ?></span>
                                    <?php endif; ?>
                                    <?php if ((int)($notification['unread_count'] ?? 0) > 0): ?>
                                        <span class="notification-pill unread"><?php echo (int)$notification['unread_count']; ?> unread</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="notification-empty">No notifications yet.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Profile section -->
    <div class="profile-wrap" id="profileWrap">
        <button class="profile-btn" id="profileBtn" aria-label="Account menu">
            <div class="profile-avatar"><?php echo $initials; ?></div>
            <span class="profile-name"><?php echo $adminName; ?></span>
            <i class="fa-solid fa-chevron-down profile-chevron"></i>
        </button>

        <div class="profile-dropdown" id="profileDropdown" role="menu">
            <div class="dropdown-header">
                <div class="d-name"><?php echo $adminName; ?></div>
                <div class="d-id"><?php echo $adminLoginId; ?></div>
                <div class="d-badge">Administrator</div>
            </div>
            <div class="dropdown-menu-list">
                <a href="#" class="dropdown-item" onclick="showSection('profile'); closeProfile();">
                    <i class="fa-solid fa-user"></i> My Profile
                </a>
                <a href="#" class="dropdown-item">
                    <i class="fa-solid fa-gear"></i> Settings
                </a>
                <div class="dropdown-divider"></div>
                <a href="../logout.php" class="dropdown-item danger">
                    <i class="fa-solid fa-right-from-bracket"></i> Sign Out
                </a>
            </div>
        </div>
    </div>
</header>

<!-- ── SIDEBAR OVERLAY ────────────────────────────── -->
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<!-- ── SIDEBAR ────────────────────────────────────── -->
<aside class="sidebar" id="sidebar">
    <div class="sidebar-section">
        <div class="sidebar-label">Main</div>
        <a class="nav-item active" href="#" onclick="showSection('overview')">
            <i class="fa-solid fa-house"></i> Overview
        </a>
        <a class="nav-item" href="#" onclick="showSection('users')">
            <i class="fa-solid fa-user-graduate"></i> Students
            <span class="nav-badge" id="studentsNavBadge"><?php echo $totalStudents; ?></span>
        </a>
        <a class="nav-item" href="#" onclick="showSection('fees')">
            <i class="fa-solid fa-indian-rupee-sign"></i> Fees
        </a>

        <div class="sidebar-label" style="margin-top:8px;">Management</div>
        <a class="nav-item" href="#" onclick="showSection('add-college')">
            <i class="fa-solid fa-building-columns"></i> Add College
        </a>
        <a class="nav-item" href="#" onclick="showSection('coordinators')">
            <i class="fa-solid fa-user-tie"></i> Coordinators
        </a>
        <a class="nav-item" href="#" onclick="showSection('tickets')">
            <i class="fa-solid fa-life-ring"></i> Tickets
        </a>
        <a class="nav-item" href="#" onclick="showSection('reports')">
            <i class="fa-solid fa-chart-bar"></i> Reports
        </a>
        <a class="nav-item" href="#" onclick="showSection('settings')">
            <i class="fa-solid fa-gear"></i> Settings
        </a>
    </div>

    <div class="sidebar-footer">
        <a href="../logout.php" class="nav-item danger" style="color:#d9435f;">
            <i class="fa-solid fa-right-from-bracket" style="color:#d9435f;"></i> Sign Out
        </a>
    </div>
</aside>

<!-- ── MAIN CONTENT ───────────────────────────────── -->
<main class="main-content" id="mainContent">

    <!-- OVERVIEW SECTION -->
    <div id="section-overview">
        <div class="dashboard-hero">
            <div>
                <div class="page-heading">Overview</div>
                <p class="page-sub">Welcome back, <?php echo $adminName; ?>. Here is a clear snapshot of your portal.</p>
            </div>
            <div class="hero-actions">
                <button type="button" class="hero-chip" onclick="showSection('add-college')">
                    <i class="fa-solid fa-building-columns"></i> Add College
                </button>
                <button type="button" class="hero-chip" onclick="showSection('courses')">
                    <i class="fa-solid fa-cube"></i> Add Course
                </button>
                <button type="button" class="hero-chip" onclick="showSection('coordinators')">
                    <i class="fa-solid fa-user-tie"></i> Add Coordinator
                </button>
                <button type="button" class="hero-chip" onclick="showSection('fees')">
                    <i class="fa-solid fa-indian-rupee-sign"></i> View Fees
                </button>
            </div>
        </div>

        <div class="stat-grid">
            <div class="stat-card">
                <div class="stat-icon si-green"><i class="fa-solid fa-users"></i></div>
                <div class="stat-val" id="statTotalUsers"><?php echo $totalUsers; ?></div>
                <div class="stat-lbl">Total Users</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon si-blue"><i class="fa-solid fa-cube"></i></div>
                <div class="stat-val" id="statCourses"><?php echo $totalCourses; ?></div>
                <div class="stat-lbl">Courses</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon si-yellow"><i class="fa-solid fa-graduation-cap"></i></div>
                <div class="stat-val" id="statStudents"><?php echo $totalStudents; ?></div>
                <div class="stat-lbl">Students</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon si-red"><i class="fa-solid fa-triangle-exclamation"></i></div>
                <div class="stat-val" id="statAlerts"><?php echo $unreadAlerts; ?></div>
                <div class="stat-lbl">Alerts</div>
            </div>
        </div>

        <div class="info-card">
            <h3>Logged-in Account</h3>
            <div class="info-row">
                <i class="fa-solid fa-user"></i>
                <span class="info-key">Name</span>
                <span class="info-val"><?php echo $adminName; ?></span>
            </div>
            <div class="info-row">
                <i class="fa-solid fa-at"></i>
                <span class="info-key">Login ID</span>
                <span class="info-val"><?php echo $adminLoginId; ?></span>
            </div>
            <div class="info-row">
                <i class="fa-solid fa-shield-halved"></i>
                <span class="info-key">Role</span>
                <span class="info-val">Administrator</span>
            </div>
        </div>
    </div>

    <!-- PROFILE SECTION -->
    <div id="section-profile" style="display:none;">
        <div class="page-heading">My Profile</div>
        <p class="page-sub">Your administrator account details.</p>
        <div class="info-card">
            <div style="display:flex;align-items:center;gap:16px;margin-bottom:20px;">
                <div class="profile-avatar" style="width:56px;height:56px;font-size:1.3rem;">
                    <?php echo $initials; ?>
                </div>
                <div>
                    <div style="font-weight:700;font-size:1.05rem;"><?php echo $adminName; ?></div>
                    <div style="font-size:.8rem;color:var(--text-muted);"><?php echo $adminLoginId; ?></div>
                </div>
            </div>
            <div class="info-row">
                <i class="fa-solid fa-user"></i>
                <span class="info-key">Full Name</span>
                <span class="info-val"><?php echo $adminName; ?></span>
            </div>
            <div class="info-row">
                <i class="fa-solid fa-at"></i>
                <span class="info-key">Login ID</span>
                <span class="info-val"><?php echo $adminLoginId; ?></span>
            </div>
            <div class="info-row">
                <i class="fa-solid fa-shield-halved"></i>
                <span class="info-key">Role</span>
                <span class="info-val">Administrator</span>
            </div>
        </div>
    </div>

    <!-- ADD COLLEGE SECTION -->
    <div id="section-add-college" style="display:none;">
        <div class="page-heading">Colleges</div>
        <p class="page-sub">Show all colleges and add new college records.</p>

        <div class="section-toolbar">
            <div></div>
            <button type="button" class="btn-add-college" id="toggleCollegeFormBtn">
                <i class="fa-solid fa-plus"></i>
                Add College
            </button>
        </div>

        <div class="college-list-card">
            <div class="college-list-wrap">
                <table class="college-table" id="collegeTable">
                    <thead>
                        <tr>
                            <th>College Name</th>
                            <th>Country</th>
                            <th>State</th>
                            <th>District</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="collegeTableBody">
                        <?php if (empty($colleges)): ?>
                            <tr>
                                <td colspan="5" class="college-empty">No colleges added yet.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($colleges as $college): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars((string)$college['name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars((string)$college['country'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars((string)$college['state'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars((string)$college['district'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td>
                                        <button type="button" class="action-btn edit-college" data-college='<?php echo htmlspecialchars(json_encode([
                                            "id" => $college["id"],
                                            "name" => $college["name"],
                                            "country" => $college["country"],
                                            "state" => $college["state"],
                                            "district" => $college["district"],
                                            "address" => $college["address"] ?? "",
                                            "latitude" => $college["latitude"] ?? "",
                                            "longitude" => $college["longitude"] ?? ""
                                        ]), ENT_QUOTES, "UTF-8"); ?>'>
                                            <i class="fa-solid fa-pen"></i> Edit
                                        </button>
                                        <button type="button" class="action-btn delete delete-college" data-college-id="<?php echo (int)$college['id']; ?>" data-college-name="<?php echo htmlspecialchars((string)$college['name'], ENT_QUOTES, 'UTF-8'); ?>">
                                            <i class="fa-solid fa-trash"></i> Delete
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="form-card hidden" id="collegeFormWrap">
            <h3><i class="fa-solid fa-building-columns"></i> College Details</h3>
            <form id="clgForm" autocomplete="off" novalidate>
                <input type="hidden" id="clg_id" name="clg_id" value="">
                <div class="form-grid">
                    <div class="f-group full">
                        <label for="clg_name">College Name</label>
                        <input type="text" id="clg_name" name="clg_name"
                               placeholder="e.g. Shri Shivaji College of Engineering" required>
                    </div>
                    <div class="f-group full">
                        <label for="clg_address">Full Address</label>
                        <textarea id="clg_address" name="clg_address"
                               placeholder="e.g. 123 Main St, Near Park" required></textarea>
                    </div>
                    <div class="f-group">
                        <label for="clg_country">Country</label>
                        <input type="text" id="clg_country" name="clg_country"
                               placeholder="e.g. India" required>
                    </div>
                    <div class="f-group">
                        <label for="clg_state">State</label>
                        <input type="text" id="clg_state" name="clg_state"
                               placeholder="e.g. Maharashtra" required>
                    </div>
                    <div class="f-group">
                        <label for="clg_district">District</label>
                        <input type="text" id="clg_district" name="clg_district"
                               placeholder="e.g. Amravati" required>
                    </div>
                    <div class="f-group">
                        <label for="clg_latitude">Latitude</label>
                        <input type="text" id="clg_latitude" name="clg_latitude"
                               placeholder="e.g. 19.0760">
                    </div>
                    <div class="f-group">
                        <label for="clg_longitude">Longitude</label>
                        <input type="text" id="clg_longitude" name="clg_longitude"
                               placeholder="e.g. 72.8777">
                    </div>
                </div>
                <button type="submit" class="btn-submit" id="clgSubmitBtn">
                    <i class="fa-solid fa-plus"></i> Save College
                </button>
            </form>
        </div>
    </div>

    <!-- PLACEHOLDER SECTIONS -->
    <div id="section-users" style="display:none;">
        <div class="page-heading">Students</div>
        <p class="page-sub">View complete student profile, fee status, payment history, and delete records when needed.</p>

        <?php
            $studentCollegeOptions = [];
            foreach ($students as $studentOption) {
                $optionCollege = trim((string)($studentOption['college_name'] ?? ''));
                if ($optionCollege !== '') {
                    $studentCollegeOptions[strtolower($optionCollege)] = $optionCollege;
                }
            }
            natcasesort($studentCollegeOptions);
        ?>

        <div class="student-filter-bar">
            <div class="student-filter-field">
                <i class="fa-solid fa-magnifying-glass student-filter-icon"></i>
                <input
                    type="search"
                    id="studentSearchInput"
                    class="student-filter-input"
                    placeholder="Search by name, login ID, course, or college"
                    autocomplete="off"
                >
            </div>
            <div class="student-filter-field">
                <i class="fa-solid fa-building-columns student-filter-icon"></i>
                <select id="studentCollegeFilter" class="student-filter-select">
                    <option value="">All colleges</option>
                    <?php foreach ($studentCollegeOptions as $collegeOption): ?>
                        <option value="<?php echo htmlspecialchars(strtolower((string)$collegeOption), ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars((string)$collegeOption, ENT_QUOTES, 'UTF-8'); ?></option>
                    <?php endforeach; ?>
                </select>
                <i class="fa-solid fa-chevron-down student-filter-caret"></i>
            </div>
            
            <div class="student-filter-field">
                <i class="fa-solid fa-calendar-alt student-filter-icon"></i>
                <select id="studentYearFilter" class="student-filter-select">
                    <option value="">All Years</option>
                    <option value="2024">2024</option>
                    <option value="2025">2025</option>
                    <option value="2026">2026</option>
                    <option value="2027">2027</option>
                    <option value="2028">2028</option>
                </select>
                <i class="fa-solid fa-chevron-down student-filter-caret"></i>
            </div>

            <div class="student-filter-field">
                <i class="fa-solid fa-book student-filter-icon"></i>
                <select id="studentSemesterFilter" class="student-filter-select">
                    <option value="">All Semesters</option>
                    <option value="Odd">Odd</option>
                    <option value="Even">Even</option>
                </select>
                <i class="fa-solid fa-chevron-down student-filter-caret"></i>
            </div>
            <div class="student-filter-count" id="studentFilterCount"><?php echo count($students); ?> students</div>
        </div>

        <div class="college-list-card students-table-wrap">
            <div class="college-list-wrap">
                <table class="college-table" id="usersTable">
                    <thead>
                        <tr>
                            <th>Student Name</th>
                            <th>Login ID</th>
                            <th>Course</th>
                            <th>College</th>
                            <th>Fees</th>
                            <th>Registered On</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="usersTableBody">
                        <?php if (empty($students)): ?>
                            <tr>
                                <td colspan="7" class="college-empty">No students found.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($students as $u): ?>
                                <?php
                                    $studentName = (string)$u['full_name'];
                                    $studentLoginId = (string)$u['login_id'];
                                    $studentCourse = (string)$u['course_name'];
                                    $studentCollege = (string)$u['college_name'];
                                    $studentSearchBlob = strtolower(trim($studentName . ' ' . $studentLoginId . ' ' . $studentCourse . ' ' . $studentCollege));
                                ?>
                                <?php
                                    $studentYear = (string)($u['academic_year'] ?? 'Unknown');
                                    $studentSemester = (string)($u['semester'] ?? 'Unknown');
                                ?>
                                <tr
                                    data-student-profile-id="<?php echo (int)$u['profile_id']; ?>"
                                    data-college="<?php echo htmlspecialchars(strtolower($studentCollege), ENT_QUOTES, 'UTF-8'); ?>"
                                    data-search="<?php echo htmlspecialchars($studentSearchBlob, ENT_QUOTES, 'UTF-8'); ?>"
                                    data-year="<?php echo htmlspecialchars(strtolower($studentYear), ENT_QUOTES, 'UTF-8'); ?>"
                                    data-semester="<?php echo htmlspecialchars(strtolower($studentSemester), ENT_QUOTES, 'UTF-8'); ?>"
                                >
                                    <td><?php echo htmlspecialchars((string)$u['full_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars((string)$u['login_id'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars((string)$u['course_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars((string)$u['college_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td>
                                        <div style="display:flex;gap:6px;flex-wrap:wrap;">
                                            <span class="fee-chip total">Total ₹<?php echo number_format((float)($u['total_fee'] ?? 0), 2); ?></span>
                                            <span class="fee-chip paid">Paid ₹<?php echo number_format((float)($u['paid_fee'] ?? 0), 2); ?></span>
                                            <span class="fee-chip pending">Remain ₹<?php echo number_format((float)($u['remaining_fee'] ?? 0), 2); ?></span>
                                        </div>
                                    </td>
                                    <td class="subtle-cell"><?php echo htmlspecialchars((string)$u['created_at'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td>
                                        <div class="row-actions">
                                            <button type="button" class="action-btn view" data-student-profile-id="<?php echo (int)$u['profile_id']; ?>">
                                                <i class="fa-solid fa-eye"></i> View
                                            </button>
                                            <button type="button" class="action-btn edit-student" data-student='<?php echo htmlspecialchars(json_encode([
                                                "profile_id" => $u["profile_id"],
                                                "user_id" => $u["user_id"],
                                                "first_name" => $u["first_name"],
                                                "middle_name" => $u["middle_name"],
                                                "last_name" => $u["last_name"],
                                                "login_id" => $u["login_id"],
                                                "email" => $u["email"],
                                                "mobile_no" => $u["mobile_no"],
                                                "state" => $u["state"],
                                                "district" => $u["district"],
                                                "college_id" => $u["college_id"],
                                                "course_id" => $u["course_id"],
                                                "academic_year" => $u["academic_year"],
                                                "semester" => $u["semester"]
                                            ]), ENT_QUOTES, "UTF-8"); ?>'>
                                                <i class="fa-solid fa-pen"></i> Edit
                                            </button>
                                            <button type="button" class="action-btn delete delete-student" data-student-profile-id="<?php echo (int)$u['profile_id']; ?>" data-student-name="<?php echo htmlspecialchars((string)$u['full_name'], ENT_QUOTES, 'UTF-8'); ?>">
                                                <i class="fa-solid fa-trash"></i> Delete
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <tr id="usersFilterEmptyRow" style="display:none;">
                                <td colspan="7" class="college-empty">No students match your search/filter.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="student-cards" id="studentsCardList">
            <?php foreach ($students as $u): ?>
                <?php
                    $studentName = (string)$u['full_name'];
                    $studentLoginId = (string)$u['login_id'];
                    $studentCourse = (string)$u['course_name'];
                    $studentCollege = (string)$u['college_name'];
                    $studentSearchBlob = strtolower(trim($studentName . ' ' . $studentLoginId . ' ' . $studentCourse . ' ' . $studentCollege));
                ?>
                <?php
                    $studentYear = (string)($u['academic_year'] ?? 'Unknown');
                    $studentSemester = (string)($u['semester'] ?? 'Unknown');
                ?>
                <div
                    class="student-card"
                    data-student-profile-id="<?php echo (int)$u['profile_id']; ?>"
                    data-college="<?php echo htmlspecialchars(strtolower($studentCollege), ENT_QUOTES, 'UTF-8'); ?>"
                    data-search="<?php echo htmlspecialchars($studentSearchBlob, ENT_QUOTES, 'UTF-8'); ?>"
                    data-year="<?php echo htmlspecialchars(strtolower($studentYear), ENT_QUOTES, 'UTF-8'); ?>"
                    data-semester="<?php echo htmlspecialchars(strtolower($studentSemester), ENT_QUOTES, 'UTF-8'); ?>"
                >
                    <h4><?php echo htmlspecialchars((string)$u['full_name'], ENT_QUOTES, 'UTF-8'); ?></h4>
                    <div class="student-meta">
                        <div><strong>Login</strong><?php echo htmlspecialchars((string)$u['login_id'], ENT_QUOTES, 'UTF-8'); ?></div>
                        <div><strong>Course</strong><?php echo htmlspecialchars((string)$u['course_name'], ENT_QUOTES, 'UTF-8'); ?></div>
                        <div><strong>College</strong><?php echo htmlspecialchars((string)$u['college_name'], ENT_QUOTES, 'UTF-8'); ?></div>
                        <div><strong>Year</strong><?php echo htmlspecialchars($studentYear, ENT_QUOTES, 'UTF-8'); ?></div>
                        <div><strong>Semester</strong><?php echo htmlspecialchars($studentSemester, ENT_QUOTES, 'UTF-8'); ?></div>
                        <div><strong>Registered</strong><?php echo htmlspecialchars((string)$u['created_at'], ENT_QUOTES, 'UTF-8'); ?></div>
                    </div>
                    <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:10px;">
                        <span class="fee-chip total">Total ₹<?php echo number_format((float)($u['total_fee'] ?? 0), 2); ?></span>
                        <span class="fee-chip paid">Paid ₹<?php echo number_format((float)($u['paid_fee'] ?? 0), 2); ?></span>
                        <span class="fee-chip pending">Remain ₹<?php echo number_format((float)($u['remaining_fee'] ?? 0), 2); ?></span>
                    </div>
                    <div class="row-actions" style="margin-top:10px;">
                        <button type="button" class="action-btn view" data-student-profile-id="<?php echo (int)$u['profile_id']; ?>">
                            <i class="fa-solid fa-eye"></i> View
                        </button>
                        <button type="button" class="action-btn edit-student" data-student='<?php echo htmlspecialchars(json_encode([
                            "profile_id" => $u["profile_id"],
                            "user_id" => $u["user_id"],
                            "first_name" => $u["first_name"],
                            "middle_name" => $u["middle_name"],
                            "last_name" => $u["last_name"],
                            "login_id" => $u["login_id"],
                            "email" => $u["email"],
                            "mobile_no" => $u["mobile_no"],
                            "state" => $u["state"],
                            "district" => $u["district"],
                            "college_id" => $u["college_id"],
                            "course_id" => $u["course_id"],
                            "academic_year" => $u["academic_year"],
                            "semester" => $u["semester"]
                        ]), ENT_QUOTES, "UTF-8"); ?>'>
                            <i class="fa-solid fa-pen"></i> Edit
                        </button>
                        <button type="button" class="action-btn delete delete-student" data-student-profile-id="<?php echo (int)$u['profile_id']; ?>" data-student-name="<?php echo htmlspecialchars((string)$u['full_name'], ENT_QUOTES, 'UTF-8'); ?>">
                            <i class="fa-solid fa-trash"></i> Delete
                        </button>
                    </div>
                </div>
            <?php endforeach; ?>
            <?php if (!empty($students)): ?>
                <div class="college-empty" id="studentsFilterEmptyCard" style="background:var(--surface);border:1px solid var(--border);border-radius:12px;display:none;">No students match your search/filter.</div>
            <?php endif; ?>
            <?php if (empty($students)): ?>
                <div class="college-empty" style="background:var(--surface);border:1px solid var(--border);border-radius:12px;">No students found.</div>
            <?php endif; ?>
        </div>
        </div>

        <div class="form-card hidden" id="studentFormWrap">
            <div class="section-toolbar" style="margin-bottom: 20px;">
                <h3><i class="fa-solid fa-user-pen"></i> Edit Student Profile</h3>
                <button type="button" class="btn-cancel" id="cancelStudentEditBtn">
                    <i class="fa-solid fa-arrow-left"></i> Back to List
                </button>
            </div>
            <form id="studentEditForm" autocomplete="off" novalidate>
                <input type="hidden" id="edit_student_profile_id" name="profile_id" value="">
                <input type="hidden" id="edit_student_user_id" name="user_id" value="">
                <div class="form-grid">
                    <div class="f-group">
                        <label for="edit_student_first_name">First Name</label>
                        <input type="text" id="edit_student_first_name" name="first_name" required>
                    </div>
                    <div class="f-group">
                        <label for="edit_student_middle_name">Middle Name</label>
                        <input type="text" id="edit_student_middle_name" name="middle_name">
                    </div>
                    <div class="f-group">
                        <label for="edit_student_last_name">Last Name</label>
                        <input type="text" id="edit_student_last_name" name="last_name" required>
                    </div>
                    <div class="f-group">
                        <label for="edit_student_login_id">Login ID (Username)</label>
                        <input type="text" id="edit_student_login_id" name="login_id" required>
                    </div>
                    <div class="f-group">
                        <label for="edit_student_email">Email</label>
                        <input type="email" id="edit_student_email" name="email" required>
                    </div>
                    <div class="f-group">
                        <label for="edit_student_mobile_no">Mobile No</label>
                        <input type="text" id="edit_student_mobile_no" name="mobile_no" required>
                    </div>
                    <div class="f-group">
                        <label for="edit_student_state">State</label>
                        <input type="text" id="edit_student_state" name="state" required>
                    </div>
                    <div class="f-group">
                        <label for="edit_student_district">District</label>
                        <input type="text" id="edit_student_district" name="district" required>
                    </div>
                    <div class="f-group">
                        <label for="edit_student_college_id">College</label>
                        <select id="edit_student_college_id" name="college_id" required>
                            <option value="">Select College</option>
                            <?php foreach ($colleges as $c): ?>
                                <option value="<?php echo (int)$c['id']; ?>"><?php echo htmlspecialchars((string)$c['name'], ENT_QUOTES, 'UTF-8'); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="f-group">
                        <label for="edit_student_course_id">Course</label>
                        <select id="edit_student_course_id" name="course_id" required>
                            <option value="">Select Course</option>
                            <?php foreach ($courses as $c): ?>
                                <option value="<?php echo (int)$c['id']; ?>"><?php echo htmlspecialchars((string)$c['course_name'], ENT_QUOTES, 'UTF-8'); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="f-group">
                        <label for="edit_student_academic_year">Academic Year</label>
                        <select id="edit_student_academic_year" name="academic_year" required>
                            <option value="2024">2024</option>
                            <option value="2025">2025</option>
                            <option value="2026">2026</option>
                            <option value="2027">2027</option>
                            <option value="2028">2028</option>
                        </select>
                    </div>
                    <div class="f-group">
                        <label for="edit_student_semester">Semester</label>
                        <select id="edit_student_semester" name="semester" required>
                            <option value="Odd">Odd</option>
                            <option value="Even">Even</option>
                        </select>
                    </div>
                    <div class="f-group full">
                        <label for="edit_student_password">Reset Password (leave blank to keep current)</label>
                        <input type="text" id="edit_student_password" name="password" placeholder="New password">
                    </div>
                </div>
                <button type="submit" class="btn-submit" id="studentSubmitBtn">
                    <i class="fa-solid fa-save"></i> Save Changes
                </button>
            </form>
        </div>
    </div>
    
    <div id="section-fees" style="display:none;">
        <div class="page-heading">Fees Collection</div>
        <p class="page-sub">Track fee assignment, collections, pending balances, and student-wise payment details.</p>

        <div class="fee-filter-bar" style="margin-bottom: 24px;">
            <div class="ticket-field">
                <i class="fa-solid fa-magnifying-glass ticket-icon"></i>
                <input type="search" id="adminFeeSearchInput" class="ticket-search" placeholder="Search by student, email, college, course">
            </div>
            <div class="ticket-field">
                <i class="fa-solid fa-filter ticket-icon"></i>
                <select id="adminFeeStatusFilter" class="ticket-status-filter">
                    <option value="">All Payment Status</option>
                    <option value="fully_paid">Fully Paid</option>
                    <option value="partial">Partial</option>
                    <option value="unpaid">Unpaid</option>
                </select>
                <i class="fa-solid fa-chevron-down ticket-select-caret"></i>
            </div>
            <div class="ticket-field">
                <i class="fa-solid fa-building-columns ticket-icon"></i>
                <select class="ticket-status-filter">
                    <option value="">College</option>
                </select>
                <i class="fa-solid fa-chevron-down ticket-select-caret"></i>
            </div>
            <div class="ticket-field">
                <i class="fa-regular fa-calendar ticket-icon"></i>
                <select class="ticket-status-filter">
                    <option value="">Year</option>
                </select>
                <i class="fa-solid fa-chevron-down ticket-select-caret"></i>
            </div>
            <div class="ticket-field">
                <i class="fa-solid fa-layer-group ticket-icon"></i>
                <select class="ticket-status-filter">
                    <option value="">Semester</option>
                </select>
                <i class="fa-solid fa-chevron-down ticket-select-caret"></i>
            </div>
            <div class="fee-filter-count" id="adminFeeVisibleCount">Showing <?php echo count($students); ?> students</div>
            <button type="button" class="ticket-filter-reset" id="adminFeeResetBtn"><i class="fa-solid fa-rotate-left"></i> Reset</button>
        </div>

        <div class="fees-summary-grid">
            <div class="fees-summary-card">
                <div class="fees-summary-label">Total Fee Assigned</div>
                <div class="fees-summary-value" id="adminTotalFeeAssigned">INR <?php echo number_format($totalFeeAssigned, 2); ?></div>
                <div class="fees-summary-hint" id="adminTotalStudentsHint">Across <?php echo $totalStudents; ?> students</div>
            </div>
            <div class="fees-summary-card">
                <div class="fees-summary-label">Total Collected</div>
                <div class="fees-summary-value" id="adminTotalFeeCollected">INR <?php echo number_format($totalFeeCollected, 2); ?></div>
                <div class="fees-summary-hint" id="adminCollectionRate">Collection rate <?php echo number_format($feeCollectionPercent, 1); ?>%</div>
            </div>
            <div class="fees-summary-card">
                <div class="fees-summary-label">Total Pending</div>
                <div class="fees-summary-value" id="adminTotalFeePending">INR <?php echo number_format($totalFeePending, 2); ?></div>
                <div class="fees-summary-hint" id="adminStudentsPending"><?php echo max(0, $totalStudents - $fullyPaidStudents); ?> students pending</div>
            </div>
            <div class="fees-summary-card">
                <div class="fees-summary-label">Fully Paid Students</div>
                <div class="fees-summary-value" id="adminFullyPaidStudents"><?php echo $fullyPaidStudents; ?></div>
                <div class="fees-summary-hint" id="adminFullyPaidHint">Out of <?php echo $totalStudents; ?> students</div>
            </div>
        </div>


        <div class="college-list-card">
            <div class="fee-table-wrap">
            <div class="college-list-wrap">
                <table class="college-table" id="feesTable">
                    <thead>
                        <tr>
                            <th>Student</th>
                            <th>Email</th>
                            <th>College</th>
                            <th>Course</th>
                            <th>Total Fee</th>
                            <th>Collected</th>
                            <th>Pending</th>
                            <th>Status</th>
                            <th>Registered On</th>
                        </tr>
                    </thead>
                    <tbody id="feesTableBody">
                        <?php if (empty($students)): ?>
                            <tr>
                                <td colspan="9" class="college-empty">No student fee records found.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($students as $studentFee): ?>
                                <?php
                                    $studentTotal = (float)($studentFee['total_fee'] ?? 0);
                                    $studentPaid = (float)($studentFee['paid_fee'] ?? 0);
                                    $studentPending = (float)($studentFee['remaining_fee'] ?? 0);
                                    if ($studentPending <= 0.009) {
                                        $feeStatus = 'fully_paid';
                                        $feeStatusLabel = 'Fully Paid';
                                        $feeStatusClass = 'fully-paid';
                                    } elseif ($studentPaid > 0) {
                                        $feeStatus = 'partial';
                                        $feeStatusLabel = 'Partial';
                                        $feeStatusClass = 'partial';
                                    } else {
                                        $feeStatus = 'unpaid';
                                        $feeStatusLabel = 'Unpaid';
                                        $feeStatusClass = 'unpaid';
                                    }
                                    $feeSearchBlob = strtolower(trim(
                                        (string)($studentFee['full_name'] ?? '') . ' ' .
                                        (string)($studentFee['email'] ?? '') . ' ' .
                                        (string)($studentFee['college_name'] ?? '') . ' ' .
                                        (string)($studentFee['course_name'] ?? '') . ' ' .
                                        (string)($studentFee['login_id'] ?? '')
                                    ));
                                ?>
                                <tr data-fee-item="1" data-status="<?php echo htmlspecialchars($feeStatus, ENT_QUOTES, 'UTF-8'); ?>" data-search="<?php echo htmlspecialchars($feeSearchBlob, ENT_QUOTES, 'UTF-8'); ?>" data-total="<?php echo $studentTotal; ?>" data-paid="<?php echo $studentPaid; ?>" data-pending="<?php echo $studentPending; ?>">
                                    <td><?php echo htmlspecialchars((string)($studentFee['full_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars((string)($studentFee['email'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars((string)($studentFee['college_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars((string)($studentFee['course_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td class="fee-amount">INR <?php echo number_format($studentTotal, 2); ?></td>
                                    <td class="fee-amount">INR <?php echo number_format($studentPaid, 2); ?></td>
                                    <td class="fee-amount pending">INR <?php echo number_format($studentPending, 2); ?></td>
                                    <td><span class="fee-status <?php echo htmlspecialchars($feeStatusClass, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($feeStatusLabel, ENT_QUOTES, 'UTF-8'); ?></span></td>
                                    <td><?php echo htmlspecialchars((string)($studentFee['created_at'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            </div><!-- /.fee-table-wrap -->
            <!-- Mobile fee card list -->
            <div class="fee-card-list" id="feeCardList">
                <?php if (empty($students)): ?>
                    <div style="padding:18px;text-align:center;color:var(--text-muted);">No student fee records found.</div>
                <?php else: ?>
                    <?php foreach ($students as $studentFee): ?>
                        <?php
                            $sfTotal = (float)($studentFee['total_fee'] ?? 0);
                            $sfPaid = (float)($studentFee['paid_fee'] ?? 0);
                            $sfPending = (float)($studentFee['remaining_fee'] ?? 0);
                            if ($sfPending <= 0.009) { $sfClass = 'fully-paid'; $sfLabel = 'Fully Paid'; }
                            elseif ($sfPaid > 0) { $sfClass = 'partial'; $sfLabel = 'Partial'; }
                            else { $sfClass = 'unpaid'; $sfLabel = 'Unpaid'; }
                            $sfBlob = strtolower(trim((string)($studentFee['full_name']??'').' '.(string)($studentFee['email']??'').' '.(string)($studentFee['college_name']??'').' '.(string)($studentFee['course_name']??'').' '.(string)($studentFee['login_id']??'')));
                        ?>
                        <div class="fee-card" data-fee-item="1" data-status="<?php echo htmlspecialchars($sfClass,ENT_QUOTES,'UTF-8'); ?>" data-search="<?php echo htmlspecialchars($sfBlob,ENT_QUOTES,'UTF-8'); ?>" data-total="<?php echo $sfTotal; ?>" data-paid="<?php echo $sfPaid; ?>" data-pending="<?php echo $sfPending; ?>">
                            <div class="fee-card-name"><?php echo htmlspecialchars((string)($studentFee['full_name']??''),ENT_QUOTES,'UTF-8'); ?></div>
                            <div class="fee-card-email"><?php echo htmlspecialchars((string)($studentFee['email']??''),ENT_QUOTES,'UTF-8'); ?></div>
                            <div class="fee-card-sub"><?php echo htmlspecialchars((string)($studentFee['college_name']??''),ENT_QUOTES,'UTF-8'); ?> &middot; <?php echo htmlspecialchars((string)($studentFee['course_name']??''),ENT_QUOTES,'UTF-8'); ?></div>
                            <div class="fee-card-amounts">
                                <div class="fee-card-amount"><strong>Total</strong>&#8377;<?php echo number_format($sfTotal,2); ?></div>
                                <div class="fee-card-amount"><strong>Paid</strong>&#8377;<?php echo number_format($sfPaid,2); ?></div>
                                <div class="fee-card-amount"><strong>Due</strong>&#8377;<?php echo number_format($sfPending,2); ?></div>
                            </div>
                            <div class="fee-card-footer">
                                <span class="fee-status <?php echo htmlspecialchars($sfClass,ENT_QUOTES,'UTF-8'); ?>"><?php echo htmlspecialchars($sfLabel,ENT_QUOTES,'UTF-8'); ?></span>
                                <span style="font-size:0.72rem;color:var(--text-muted);"><?php echo htmlspecialchars((string)($studentFee['created_at']??''),ENT_QUOTES,'UTF-8'); ?></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="empty-box" id="adminFeeFilterEmpty" style="display:none;margin-top:12px;">No fee records match your search/filter.</div>
    </div>

    <div id="section-coordinators" style="display:none;">
        <div class="page-heading">Coordinators</div>
        <p class="page-sub">Show coordinators and create new coordinator accounts.</p>

        <div class="section-toolbar">
            <div></div>
            <button type="button" class="btn-add-college" id="toggleCoordinatorFormBtn">
                <i class="fa-solid fa-plus"></i>
                Add Coordinator
            </button>
        </div>

        <div class="college-list-card">
            <div class="coord-table-wrap">
            <div class="college-list-wrap">
                <table class="college-table" id="coordinatorTable">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Mobile</th>
                            <th>State</th>
                            <th>District</th>
                            <th>PIN</th>
                            <th>Assigned Colleges</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="coordinatorTableBody">
                        <?php if (empty($coordinators)): ?>
                            <tr>
                                <td colspan="9" class="college-empty">No coordinators added yet.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($coordinators as $coordinator): ?>
                                <?php
                                    $coordinatorName = trim(
                                        (string)$coordinator['first_name'] . ' ' .
                                        (string)$coordinator['second_name'] . ' ' .
                                        (string)$coordinator['last_name']
                                    );
                                ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($coordinatorName, ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars((string)$coordinator['email'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars((string)$coordinator['mobile_no'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars((string)$coordinator['state'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars((string)$coordinator['district'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars((string)$coordinator['pin'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td>
                                        <?php
                                        $collegesArr = array_filter(array_map('trim', explode(',', (string)($coordinator['assigned_colleges'] ?? ''))));
                                        $count = count($collegesArr);
                                        if ($count > 0) {
                                            $dataColleges = htmlspecialchars(json_encode(array_values($collegesArr)), ENT_QUOTES, 'UTF-8');
                                            echo '<button type="button" class="colleges-badge" data-colleges="' . $dataColleges . '"><i class="fa-solid fa-building-columns"></i> ' . $count . ' ' . ($count === 1 ? 'College' : 'Colleges') . ' <i class="fa-solid fa-chevron-down" style="font-size:0.6rem;"></i></button>';
                                        } else {
                                            echo '<span class="colleges-badge-none"><i class="fa-solid fa-building-columns"></i> None</span>';
                                        }
                                        ?>
                                    </td>
                                    <td>
                                        <button type="button" class="action-btn edit-coord" data-coordinator='<?php echo htmlspecialchars(json_encode([
                                            "id" => $coordinator["id"],
                                            "first_name" => $coordinator["first_name"],
                                            "second_name" => $coordinator["second_name"],
                                            "last_name" => $coordinator["last_name"],
                                            "email" => $coordinator["email"],
                                            "mobile_no" => $coordinator["mobile_no"],
                                            "address_line1" => $coordinator["address_line1"] ?? "",
                                            "address_line2" => $coordinator["address_line2"] ?? "",
                                            "state" => $coordinator["state"],
                                            "district" => $coordinator["district"],
                                            "pin" => $coordinator["pin"],
                                            "assigned_colleges" => $coordinator["assigned_colleges"] ?? "",
                                            "college_ids" => array_filter(explode(",", (string)($coordinator["assigned_college_ids"] ?? "")))
                                        ]), ENT_QUOTES, "UTF-8"); ?>'>
                                            <i class="fa-solid fa-pen"></i> Edit
                                        </button>
                                        <button type="button" class="action-btn delete" data-coordinator-id="<?php echo (int)$coordinator['id']; ?>" data-coordinator-name="<?php echo htmlspecialchars($coordinatorName, ENT_QUOTES, 'UTF-8'); ?>">
                                            <i class="fa-solid fa-trash"></i> Delete
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            </div><!-- /.coord-table-wrap -->
            <!-- Mobile card view for coordinators -->
            <div class="coord-card-list" id="coordCardList">
                <?php if (empty($coordinators)): ?>
                    <div style="padding:18px;text-align:center;color:var(--text-muted);">No coordinators added yet.</div>
                <?php else: ?>
                    <?php foreach ($coordinators as $coordinator): ?>
                        <?php
                            $cName = trim((string)$coordinator['first_name'] . ' ' . (string)$coordinator['second_name'] . ' ' . (string)$coordinator['last_name']);
                            $cCollegesArr = array_filter(array_map('trim', explode(',', (string)($coordinator['assigned_colleges'] ?? ''))));
                            $cCount = count($cCollegesArr);
                        ?>
                        <div class="coord-card">
                            <div class="coord-card-name"><?php echo htmlspecialchars($cName, ENT_QUOTES, 'UTF-8'); ?></div>
                            <div class="coord-card-email"><?php echo htmlspecialchars((string)$coordinator['email'], ENT_QUOTES, 'UTF-8'); ?></div>
                            <div class="coord-card-meta">
                                <div class="coord-card-meta-item"><strong>Mobile</strong><?php echo htmlspecialchars((string)$coordinator['mobile_no'], ENT_QUOTES, 'UTF-8'); ?></div>
                                <div class="coord-card-meta-item"><strong>PIN</strong><?php echo htmlspecialchars((string)$coordinator['pin'], ENT_QUOTES, 'UTF-8'); ?></div>
                                <div class="coord-card-meta-item"><strong>State</strong><?php echo htmlspecialchars((string)$coordinator['state'], ENT_QUOTES, 'UTF-8'); ?></div>
                                <div class="coord-card-meta-item"><strong>District</strong><?php echo htmlspecialchars((string)$coordinator['district'], ENT_QUOTES, 'UTF-8'); ?></div>
                                <div class="coord-card-meta-item" style="grid-column:1/-1;">
                                    <strong>Colleges</strong>
                                    <?php if ($cCount > 0): ?>
                                        <button type="button" class="colleges-badge" data-colleges="<?php echo htmlspecialchars(json_encode(array_values($cCollegesArr)), ENT_QUOTES, 'UTF-8'); ?>" style="font-size:0.75rem;padding:3px 8px;margin-top:3px;">
                                            <i class="fa-solid fa-building-columns"></i> <?php echo $cCount; ?> <?php echo $cCount === 1 ? 'College' : 'Colleges'; ?> <i class="fa-solid fa-chevron-down" style="font-size:0.55rem;"></i>
                                        </button>
                                    <?php else: ?><span style="color:var(--text-muted);">None</span><?php endif; ?>
                                </div>
                            </div>
                            <div class="coord-card-footer">
                                <button type="button" class="action-btn edit-coord" data-coordinator='<?php echo htmlspecialchars(json_encode(["id"=>$coordinator["id"],"first_name"=>$coordinator["first_name"],"second_name"=>$coordinator["second_name"],"last_name"=>$coordinator["last_name"],"email"=>$coordinator["email"],"mobile_no"=>$coordinator["mobile_no"],"address_line1"=>$coordinator["address_line1"]??"","address_line2"=>$coordinator["address_line2"]??"","state"=>$coordinator["state"],"district"=>$coordinator["district"],"pin"=>$coordinator["pin"],"assigned_colleges"=>$coordinator["assigned_colleges"]??""]), ENT_QUOTES, "UTF-8"); ?>'>
                                    <i class="fa-solid fa-pen"></i> Edit
                                </button>
                                <button type="button" class="action-btn delete" data-coordinator-id="<?php echo (int)$coordinator['id']; ?>" data-coordinator-name="<?php echo htmlspecialchars($cName, ENT_QUOTES, 'UTF-8'); ?>">
                                    <i class="fa-solid fa-trash"></i> Delete
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="form-card hidden" id="coordinatorFormWrap">
            <h3><i class="fa-solid fa-user-tie"></i> Coordinator Details</h3>
            <form id="coordinatorForm" autocomplete="off" novalidate>
                <input type="hidden" id="coord_id" name="coordinator_id" value="">
                <div class="form-grid">
                    <div class="f-group">
                        <label for="coord_first_name">First Name</label>
                        <input type="text" id="coord_first_name" name="first_name" required>
                    </div>
                    <div class="f-group">
                        <label for="coord_second_name">Second Name</label>
                        <input type="text" id="coord_second_name" name="second_name">
                    </div>
                    <div class="f-group">
                        <label for="coord_last_name">Last Name</label>
                        <input type="text" id="coord_last_name" name="last_name" required>
                    </div>
                    <div class="f-group">
                        <label for="coord_email">Email</label>
                        <input type="email" id="coord_email" name="email" required>
                    </div>
                    <div class="f-group">
                        <label for="coord_mobile">Mobile No</label>
                        <input type="text" id="coord_mobile" name="mobile_no" required>
                    </div>
                    <div class="f-group">
                        <label for="coord_pin">PIN</label>
                        <input type="text" id="coord_pin" name="pin" required>
                    </div>
                    <div class="f-group full">
                        <label for="coord_address_1">Address Line 1</label>
                        <input type="text" id="coord_address_1" name="address_line1" required>
                    </div>
                    <div class="f-group full">
                        <label for="coord_address_2">Address Line 2</label>
                        <input type="text" id="coord_address_2" name="address_line2">
                    </div>
                    <div class="f-group">
                        <label for="coord_state">State</label>
                        <input type="text" id="coord_state" name="state" required>
                    </div>
                    <div class="f-group">
                        <label for="coord_district">District</label>
                        <input type="text" id="coord_district" name="district" required>
                    </div>

                    <div class="f-group full">
                        <label for="coord_colleges">Assign Colleges (multiple)</label>
                        <select id="coord_colleges" name="assigned_colleges[]" multiple size="6" required>
                            <?php foreach ($colleges as $college): ?>
                                <option value="<?php echo (int)$college['id']; ?>"><?php echo htmlspecialchars((string)$college['name'], ENT_QUOTES, 'UTF-8'); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <button type="submit" class="btn-submit" id="coordinatorSubmitBtn">
                    <i class="fa-solid fa-plus"></i> Create Coordinator
                </button>
            </form>
        </div>
    </div>

    <div id="section-tickets" style="display:none;">
        <div class="page-heading">Support Tickets</div>
        <p class="page-sub">All tickets raised by students across all colleges and coordinators.</p>

        <div class="ticket-filter-bar">
            <div class="ticket-field">
                <i class="fa-solid fa-magnifying-glass ticket-icon"></i>
                <input type="search" id="adminTicketSearchInput" class="ticket-search" placeholder="Search by subject, student, college, coordinator">
            </div>
            <div class="ticket-field">
                <i class="fa-solid fa-filter ticket-icon"></i>
                <select id="adminTicketStatusFilter" class="ticket-status-filter">
                    <option value="">All Statuses</option>
                    <option value="open">Open</option>
                    <option value="in_progress">In Progress</option>
                    <option value="resolved">Resolved</option>
                </select>
                <i class="fa-solid fa-chevron-down ticket-select-caret"></i>
            </div>
            <div class="ticket-filter-count" id="adminTicketVisibleCount">Showing <?php echo count($adminTickets); ?> tickets</div>
            <button type="button" class="ticket-filter-reset" id="adminTicketResetBtn"><i class="fa-solid fa-rotate-left"></i> Reset</button>
        </div>

        <?php if (!empty($adminTickets)): ?>
            <div class="tickets-card">
                <div class="tickets-head">All Tickets</div>
                <?php foreach ($adminTickets as $ticket): ?>
                    <?php
                        $ticketStatus = (string)($ticket['status'] ?? 'open');
                        $ticketStatusClass = $ticketStatus === 'in_progress' ? 'in-progress' : ($ticketStatus === 'resolved' ? 'resolved' : 'open');
                        $ticketStatusLabel = $ticketStatus === 'in_progress' ? 'In Progress' : ($ticketStatus === 'resolved' ? 'Resolved' : 'Open');
                        $studentName = trim(
                            (string)($ticket['first_name'] ?? '') . ' ' .
                            (string)($ticket['middle_name'] ?? '') . ' ' .
                            (string)($ticket['last_name'] ?? '')
                        );
                        $coordinatorName = trim(
                            (string)($ticket['coordinator_first_name'] ?? '') . ' ' .
                            (string)($ticket['coordinator_last_name'] ?? '')
                        );
                        $ticketSearchBlob = strtolower(trim(
                            (string)($ticket['subject'] ?? '') . ' ' .
                            (string)($ticket['message'] ?? '') . ' ' .
                            $studentName . ' ' .
                            (string)($ticket['email'] ?? '') . ' ' .
                            (string)($ticket['college_name'] ?? '') . ' ' .
                            $coordinatorName
                        ));
                    ?>
                    <div class="ticket-row ticket-<?php echo htmlspecialchars($ticketStatusClass, ENT_QUOTES, 'UTF-8'); ?>" data-ticket-item="1" data-status="<?php echo htmlspecialchars($ticketStatus, ENT_QUOTES, 'UTF-8'); ?>" data-search="<?php echo htmlspecialchars($ticketSearchBlob, ENT_QUOTES, 'UTF-8'); ?>">
                        <div class="ticket-context">
                            <span>#<?php echo (int)($ticket['id'] ?? 0); ?></span>
                            <span class="ticket-context-dot"></span>
                            <span><?php echo htmlspecialchars((string)($ticket['created_at'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></span>
                        </div>
                        <div class="ticket-row-top">
                            <div class="ticket-subject"><?php echo htmlspecialchars((string)$ticket['subject'], ENT_QUOTES, 'UTF-8'); ?></div>
                            <span class="ticket-status <?php echo htmlspecialchars($ticketStatusClass, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($ticketStatusLabel, ENT_QUOTES, 'UTF-8'); ?></span>
                        </div>
                        <div class="ticket-meta">
                            <span class="ticket-meta-item"><strong>Student:</strong><span><?php echo htmlspecialchars($studentName !== '' ? $studentName : 'Unknown', ENT_QUOTES, 'UTF-8'); ?></span></span>
                            <span class="ticket-meta-item"><strong>Email:</strong><span><?php echo htmlspecialchars((string)($ticket['email'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></span></span>
                            <span class="ticket-meta-item"><strong>College:</strong><span><?php echo htmlspecialchars((string)($ticket['college_name'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></span></span>
                            <span class="ticket-meta-item"><strong>Coordinator:</strong><span><?php echo htmlspecialchars($coordinatorName !== '' ? $coordinatorName : 'Unassigned', ENT_QUOTES, 'UTF-8'); ?></span></span>
                            <span class="ticket-meta-item"><strong>Status:</strong><span><?php echo htmlspecialchars($ticketStatusLabel, ENT_QUOTES, 'UTF-8'); ?></span></span>
                        </div>
                        <div class="ticket-message"><?php echo nl2br(htmlspecialchars((string)$ticket['message'], ENT_QUOTES, 'UTF-8')); ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="empty-box" id="adminTicketFilterEmpty" style="display:none;margin-top:12px;">No tickets match your search/filter.</div>
        <?php else: ?>
            <div class="empty-box">No tickets raised by students yet.</div>
        <?php endif; ?>
    </div>

    <div id="section-reports" style="display:none;">
        <div class="page-heading">Reports</div>
        <p class="page-sub">Operational analytics across users, fees, tickets, and coordinator workload.</p>

        <div class="report-kpi-grid">
            <div class="report-kpi-card">
                <div class="report-kpi-label">Total Users</div>
                <div class="report-kpi-value"><?php echo $totalUsers; ?></div>
                <div class="report-kpi-sub"><?php echo $roleCounts['student']; ?> students, <?php echo $roleCounts['coordinator']; ?> coordinators</div>
            </div>
            <div class="report-kpi-card">
                <div class="report-kpi-label">Fee Collection</div>
                <div class="report-kpi-value"><?php echo number_format($feeCollectionPercent, 1); ?>%</div>
                <div class="report-kpi-sub">INR <?php echo number_format($totalFeeCollected, 2); ?> of INR <?php echo number_format($totalFeeAssigned, 2); ?></div>
            </div>
            <div class="report-kpi-card">
                <div class="report-kpi-label">Active Tickets</div>
                <div class="report-kpi-value"><?php echo $activeTicketCount; ?></div>
                <div class="report-kpi-sub"><?php echo number_format($resolvedTicketPercent, 1); ?>% resolved out of <?php echo $totalTickets; ?></div>
            </div>
            <div class="report-kpi-card">
                <div class="report-kpi-label">Pending Fee</div>
                <div class="report-kpi-value">INR <?php echo number_format($totalFeePending, 2); ?></div>
                <div class="report-kpi-sub"><?php echo max(0, $totalStudents - $fullyPaidStudents); ?> students pending payment</div>
            </div>
        </div>

        <div class="report-grid">
            <div class="report-card">
                <div class="report-head">
                    <h3>College Fee Performance</h3>
                    <span>By pending amount</span>
                </div>
                <div class="report-table-wrap">
                    <table class="report-table">
                        <thead>
                            <tr>
                                <th>College</th>
                                <th>Students</th>
                                <th>Assigned</th>
                                <th>Collected</th>
                                <th>Pending</th>
                                <th>Rate</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($collegePerformance)): ?>
                                <tr><td colspan="6">No college fee data available.</td></tr>
                            <?php else: ?>
                                <?php foreach ($collegePerformance as $collegeRow): ?>
                                    <?php $isHealthy = (float)$collegeRow['collection_percent'] >= 70; ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars((string)$collegeRow['college'], ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td><?php echo (int)$collegeRow['students']; ?></td>
                                        <td>INR <?php echo number_format((float)$collegeRow['assigned'], 2); ?></td>
                                        <td>INR <?php echo number_format((float)$collegeRow['collected'], 2); ?></td>
                                        <td>INR <?php echo number_format((float)$collegeRow['pending'], 2); ?></td>
                                        <td><span class="report-pill <?php echo $isHealthy ? 'good' : 'warn'; ?>"><?php echo number_format((float)$collegeRow['collection_percent'], 1); ?>%</span></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="report-card">
                <div class="report-head">
                    <h3>Coordinator Ticket Workload</h3>
                    <span>Open, in progress, resolved</span>
                </div>
                <div class="report-table-wrap">
                    <table class="report-table">
                        <thead>
                            <tr>
                                <th>Coordinator</th>
                                <th>Total</th>
                                <th>Open</th>
                                <th>In Progress</th>
                                <th>Resolved</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($coordinatorTicketLoad)): ?>
                                <tr><td colspan="5">No ticket data available.</td></tr>
                            <?php else: ?>
                                <?php foreach ($coordinatorTicketLoad as $coordLoad): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars((string)$coordLoad['name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td><?php echo (int)$coordLoad['total']; ?></td>
                                        <td><?php echo (int)$coordLoad['open']; ?></td>
                                        <td><?php echo (int)$coordLoad['in_progress']; ?></td>
                                        <td><span class="report-pill info"><?php echo (int)$coordLoad['resolved']; ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="report-card">
                <div class="report-head">
                    <h3>Monthly Admissions and Fees</h3>
                    <span>Latest months</span>
                </div>
                <div class="report-table-wrap">
                    <table class="report-table">
                        <thead>
                            <tr>
                                <th>Month</th>
                                <th>Students</th>
                                <th>Assigned</th>
                                <th>Collected</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($monthlyAdmissions)): ?>
                                <tr><td colspan="4">No monthly trend data available.</td></tr>
                            <?php else: ?>
                                <?php foreach ($monthlyAdmissions as $monthRow): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars((string)$monthRow['month'], ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td><?php echo (int)$monthRow['students']; ?></td>
                                        <td>INR <?php echo number_format((float)$monthRow['assigned'], 2); ?></td>
                                        <td>INR <?php echo number_format((float)$monthRow['collected'], 2); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="report-card">
                <div class="report-head">
                    <h3>User Role Breakdown</h3>
                    <span>Current access distribution</span>
                </div>
                <div class="report-table-wrap">
                    <table class="report-table">
                        <thead>
                            <tr>
                                <th>Role</th>
                                <th>Count</th>
                                <th>Share</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($roleCounts as $roleName => $roleCount): ?>
                                <?php $share = $totalUsers > 0 ? round(((int)$roleCount / $totalUsers) * 100, 1) : 0.0; ?>
                                <tr>
                                    <td><?php echo htmlspecialchars(ucfirst((string)$roleName), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo (int)$roleCount; ?></td>
                                    <td><span class="report-pill <?php echo $share >= 50 ? 'info' : 'good'; ?>"><?php echo number_format($share, 1); ?>%</span></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div id="section-settings" style="display:none;">
        <div class="page-heading">Settings</div>
        <p class="page-sub">Update administrator profile details and password securely.</p>

        <div class="form-card" style="max-width:780px;">
            <h3><i class="fa-solid fa-gear"></i> Account Settings</h3>
            <form id="adminSettingsForm" autocomplete="off" novalidate>
                <div class="form-grid">
                    <div class="f-group full">
                        <label for="settings_full_name">Admin Name</label>
                        <input type="text" id="settings_full_name" name="full_name" value="<?php echo htmlspecialchars((string)$user['name'], ENT_QUOTES, 'UTF-8'); ?>" required>
                    </div>
                    <div class="f-group full">
                        <label for="settings_login_id">Login ID</label>
                        <input type="text" id="settings_login_id" name="login_id" value="<?php echo htmlspecialchars((string)$user['login_id'], ENT_QUOTES, 'UTF-8'); ?>" required>
                    </div>

                    <div class="f-group full">
                        <label for="settings_mobile_no">Mobile Number (For OTP Verification)</label>
                        <input type="text" id="settings_mobile_no" name="mobile_no" value="<?php echo htmlspecialchars($user['mobile_no'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" required>
                    </div>

                    <div class="f-group full">
                        <label for="settings_current_password">Current Password (Required for login ID/password changes)</label>
                        <input type="password" id="settings_current_password" name="current_password" placeholder="Enter current password">
                    </div>

                    <div class="f-group">
                        <label for="settings_new_password">New Password</label>
                        <input type="password" id="settings_new_password" name="new_password" placeholder="Leave blank if no change">
                    </div>
                    <div class="f-group">
                        <label for="settings_confirm_password">Confirm New Password</label>
                        <input type="password" id="settings_confirm_password" name="confirm_password" placeholder="Re-enter new password">
                    </div>
                </div>
                <button type="submit" class="btn-submit" id="adminSettingsSubmitBtn">
                    <i class="fa-solid fa-floppy-disk"></i> Save Settings
                </button>
            </form>
        </div>
    </div>

    <!-- OTP Modal for Admin Settings -->
    <div class="modal-overlay" id="adminOtpOverlay" style="display:none; align-items:center; justify-content:center;">
        <div class="form-card" style="width:100%; max-width:400px; text-align:center;">
            <h3>OTP Verification</h3>
            <p class="page-sub" style="margin-bottom:15px;" id="adminOtpMessage">An OTP has been generated for security. (Check popup/console)</p>
            <div class="f-group" style="text-align:left;">
                <label for="settings_otp">Enter 6-digit OTP</label>
                <input type="text" id="settings_otp" placeholder="e.g. 123456" maxlength="6" style="text-align:center; letter-spacing:4px; font-size:1.2rem; font-weight:bold;">
            </div>
            <div style="display:flex; gap:10px; justify-content:center; margin-top:20px;">
                <button type="button" class="btn-submit" style="background:var(--red);" onclick="document.getElementById('adminOtpOverlay').style.display='none';">Cancel</button>
                <button type="button" class="btn-submit" id="adminOtpVerifyBtn">Verify & Save</button>
            </div>
        </div>
    </div>

</main>

<div class="student-modal-overlay" id="studentModalOverlay">
    <div class="student-modal" role="dialog" aria-modal="true" aria-labelledby="studentModalTitle">
        <div class="student-modal-head">
            <h3 id="studentModalTitle">Student Details</h3>
            <div class="student-modal-head-actions">
                <button type="button" class="student-delete-btn" id="studentModalDeleteBtn"><i class="fa-solid fa-trash"></i> Delete Student</button>
                <button type="button" class="student-close" id="studentModalClose"><i class="fa-solid fa-xmark"></i></button>
            </div>
        </div>
        <div class="student-detail-grid" id="studentDetailGrid"></div>
        <div class="payment-history" id="paymentHistoryWrap">
            <div class="payment-history-head">Payment History</div>
            <div class="payment-history-list" id="paymentHistoryList"></div>
        </div>
    </div>
</div>

<div id="globalToast" class="toast" aria-live="polite"></div>

<script>
    const sidebar        = document.getElementById('sidebar');
    const sidebarOverlay = document.getElementById('sidebarOverlay');
    const mainContent    = document.getElementById('mainContent');
    const profileWrap    = document.getElementById('profileWrap');
    const notificationWrap = document.getElementById('notificationWrap');
    const notificationBtn = document.getElementById('notificationBtn');
    const notificationList = document.getElementById('notificationList');
    const notificationTotalText = document.getElementById('notificationTotalText');
    const notificationMarkAllBtn = document.getElementById('notificationMarkAllBtn');
    const alertsBadge = document.getElementById('alertsBadge');
    const isMobile       = () => window.innerWidth <= 700;

    /* ── Sidebar toggle ── */
    document.getElementById('sidebarToggle').addEventListener('click', () => {
        if (isMobile()) {
            sidebar.classList.toggle('open');
            sidebarOverlay.classList.toggle('visible');
        } else {
            sidebar.classList.toggle('collapsed');
            mainContent.classList.toggle('expanded');
        }
    });

    sidebarOverlay.addEventListener('click', () => {
        sidebar.classList.remove('open');
        sidebarOverlay.classList.remove('visible');
    });

    /* ── Profile dropdown ── */
    document.getElementById('profileBtn').addEventListener('click', (e) => {
        e.stopPropagation();
        profileWrap.classList.toggle('open');
        if (notificationWrap) {
            notificationWrap.classList.remove('open');
        }
    });

    if (notificationBtn && notificationWrap) {
        notificationBtn.addEventListener('click', (event) => {
            event.stopPropagation();
            notificationWrap.classList.toggle('open');
            profileWrap.classList.remove('open');
        });
    }

    document.addEventListener('click', () => {
        profileWrap.classList.remove('open');
        if (notificationWrap) {
            notificationWrap.classList.remove('open');
        }
    });

    function closeProfile() { profileWrap.classList.remove('open'); }

    const toggleCollegeFormBtn = document.getElementById('toggleCollegeFormBtn');
    const collegeFormWrap = document.getElementById('collegeFormWrap');
    const collegeTableBody = document.getElementById('collegeTableBody');

    const toggleCoordinatorFormBtn = document.getElementById('toggleCoordinatorFormBtn');
    const coordinatorFormWrap = document.getElementById('coordinatorFormWrap');
    const coordinatorTableBody = document.getElementById('coordinatorTableBody');
    const usersTableBody = document.getElementById('usersTableBody');
    const studentsCardList = document.getElementById('studentsCardList');
    const studentSearchInput = document.getElementById('studentSearchInput');
    const studentCollegeFilter = document.getElementById('studentCollegeFilter');
    const studentFilterCount = document.getElementById('studentFilterCount');
    const studentsNavBadge = document.getElementById('studentsNavBadge');
    const statTotalUsers = document.getElementById('statTotalUsers');
    const statStudents = document.getElementById('statStudents');
    const statCourses = document.getElementById('statCourses');
    const statAlerts = document.getElementById('statAlerts');
    const studentModalOverlay = document.getElementById('studentModalOverlay');
    const studentModalClose = document.getElementById('studentModalClose');
    const studentModalDeleteBtn = document.getElementById('studentModalDeleteBtn');
    const studentDetailGrid = document.getElementById('studentDetailGrid');
    const paymentHistoryList = document.getElementById('paymentHistoryList');
    let activeStudentProfileId = '';
    let activeStudentName = '';

    function updateCollegeToggleLabel() {
        const isHidden = collegeFormWrap.classList.contains('hidden');
        toggleCollegeFormBtn.innerHTML = isHidden
            ? '<i class="fa-solid fa-plus"></i> Add College'
            : '<i class="fa-solid fa-xmark"></i> Close Form';
    }

    function escapeHtml(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function prependCollegeRow(college) {
        const hasEmptyRow = collegeTableBody.querySelector('.college-empty');
        if (hasEmptyRow) {
            collegeTableBody.innerHTML = '';
        }

        const row = document.createElement('tr');
        row.innerHTML = `
            <td>${escapeHtml(college.name)}</td>
            <td>${escapeHtml(college.country)}</td>
            <td>${escapeHtml(college.state)}</td>
            <td>${escapeHtml(college.district)}</td>
        `;

        collegeTableBody.prepend(row);
    }

    function updateCoordinatorToggleLabel() {
        const isHidden = coordinatorFormWrap.classList.contains('hidden');
        toggleCoordinatorFormBtn.innerHTML = isHidden
            ? '<i class="fa-solid fa-plus"></i> Add Coordinator'
            : '<i class="fa-solid fa-xmark"></i> Close Form';
    }

    function prependCoordinatorRow(coordinator) {
        const hasEmptyRow = coordinatorTableBody.querySelector('.college-empty');
        if (hasEmptyRow) {
            coordinatorTableBody.innerHTML = '';
        }

        const row = document.createElement('tr');
        if (coordinator.id) {
            row.dataset.coordinatorId = String(coordinator.id);
        }
        row.innerHTML = `
            <td>${escapeHtml(coordinator.name)}</td>
            <td>${escapeHtml(coordinator.email)}</td>
            <td>${escapeHtml(coordinator.mobile_no)}</td>
            <td>${escapeHtml(coordinator.state)}</td>
            <td>${escapeHtml(coordinator.district)}</td>
            <td>${escapeHtml(coordinator.pin)}</td>
            <td>
                ${(function() {
                    const arr = (coordinator.assigned_colleges || '').split(',').map(c => c.trim()).filter(c => c);
                    if (arr.length > 0) {
                        const dataColleges = escapeHtml(JSON.stringify(arr));
                        return `<button type="button" class="colleges-badge" data-colleges="${dataColleges}"><i class="fa-solid fa-building-columns"></i> ${arr.length} ${arr.length === 1 ? 'College' : 'Colleges'} <i class="fa-solid fa-chevron-down" style="font-size:0.6rem;"></i></button>`;
                    }
                    return `<span class="colleges-badge-none"><i class="fa-solid fa-building-columns"></i> None</span>`;
                })()}
            </td>
            <td>
                <button type="button" class="action-btn delete" data-coordinator-id="${escapeHtml(coordinator.id || '')}" data-coordinator-name="${escapeHtml(coordinator.name)}">
                    <i class="fa-solid fa-trash"></i> Delete
                </button>
            </td>
        `;

        coordinatorTableBody.prepend(row);
    }

    function formatCurrency(value) {
        const amount = Number(value || 0);
        return '₹' + amount.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function renderStudentDetails(student) {
        if (!studentDetailGrid) {
            return;
        }

        const details = [
            ['Student Name', student.name || '-'],
            ['Login ID', student.login_id || '-'],
            ['Email', student.email || '-'],
            ['Mobile', student.mobile_no || '-'],
            ['College', student.college_name || '-'],
            ['Course', student.course_name || '-'],
            ['Duration', student.duration || '-'],
            ['State', student.state || '-'],
            ['District', student.district || '-'],
            ['Registered On', student.created_at || '-']
        ];

        const feeCard = document.createElement('div');
        feeCard.className = 'student-detail-item full';
        feeCard.innerHTML = `
            <strong>Fees Status</strong>
            <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:6px;">
                <span class="fee-chip total">Total ${formatCurrency(student.total_fee)}</span>
                <span class="fee-chip paid">Paid ${formatCurrency(student.paid_fee)}</span>
                <span class="fee-chip pending">Remain ${formatCurrency(student.remaining_fee)}</span>
            </div>
        `;

        studentDetailGrid.innerHTML = '';
        studentDetailGrid.appendChild(feeCard);

        details.forEach(([key, value]) => {
            const item = document.createElement('div');
            item.className = 'student-detail-item';
            item.innerHTML = `<strong>${escapeHtml(key)}</strong><span>${escapeHtml(String(value))}</span>`;
            studentDetailGrid.appendChild(item);
        });
    }

    function renderPaymentHistory(payments) {
        if (!paymentHistoryList) {
            return;
        }

        if (!Array.isArray(payments) || payments.length === 0) {
            paymentHistoryList.innerHTML = '<div class="payment-row"><div class="payment-main">No payments found.</div></div>';
            return;
        }

        paymentHistoryList.innerHTML = payments.map(payment => {
            return `
                <div class="payment-row">
                    <div>
                        <div class="payment-main">${escapeHtml(payment.razorpay_payment_id || '-')} (${escapeHtml((payment.status || '').toUpperCase() || '-')})</div>
                        <div class="payment-sub">Order: ${escapeHtml(payment.razorpay_order_id || '-')}</div>
                        <div class="payment-sub">${escapeHtml(payment.created_at || '-')}</div>
                    </div>
                    <div class="payment-amount">${formatCurrency(payment.amount_rupees)}</div>
                </div>
            `;
        }).join('');
    }

    async function openStudentModal(profileId) {
        if (!profileId) {
            return;
        }

        try {
            const response = await fetch('student_details.php?profile_id=' + encodeURIComponent(String(profileId)));
            const data = await response.json();

            if (!data.ok) {
                showToast(data.error || 'Unable to load student details.', 'error');
                return;
            }

            renderStudentDetails(data.student || {});
            renderPaymentHistory(data.payments || []);
            activeStudentProfileId = String((data.student && data.student.profile_id) || profileId || '');
            activeStudentName = String((data.student && data.student.name) || '');
            if (studentModalOverlay) {
                studentModalOverlay.classList.add('show');
            }
        } catch {
            showToast('Network error while loading student details.', 'error');
        }
    }

    function closeStudentModal() {
        if (studentModalOverlay) {
            studentModalOverlay.classList.remove('show');
        }
        activeStudentProfileId = '';
        activeStudentName = '';
    }

    async function deleteStudent(profileId, studentName) {
        if (!profileId) {
            return;
        }

        const confirmed = window.confirm('Delete student "' + (studentName || '') + '"? This will remove profile and payment history.');
        if (!confirmed) {
            return;
        }

        try {
            const body = new URLSearchParams();
            body.append('profile_id', String(profileId));
            const response = await fetch('student_delete.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
                body: body.toString()
            });
            const data = await response.json();

            if (!data.ok) {
                showToast(data.error || 'Unable to delete student.', 'error');
                return;
            }

            document.querySelectorAll('[data-student-profile-id="' + String(profileId) + '"]').forEach(node => {
                const row = node.closest('tr') || node;
                row.remove();
            });

            if (usersTableBody && usersTableBody.querySelectorAll('tr[data-student-profile-id]').length === 0) {
                usersTableBody.innerHTML = '<tr><td colspan="7" class="college-empty">No students found.</td></tr>';
            }
            if (studentsCardList && studentsCardList.querySelectorAll('.student-card[data-student-profile-id]').length === 0) {
                studentsCardList.innerHTML = '<div class="college-empty" style="background:var(--surface);border:1px solid var(--border);border-radius:12px;">No students found.</div>';
            }

            applyStudentFilters();

            incrementCounter(statTotalUsers, -1);
            incrementCounter(statStudents, -1);
            incrementCounter(studentsNavBadge, -1);
            showToast('Student deleted successfully.', 'success');
        } catch {
            showToast('Network error while deleting student.', 'error');
        }
    }

    async function deleteCoordinator(coordinatorId, coordinatorName) {
        if (!coordinatorId) {
            return;
        }

        const confirmed = window.confirm('Delete coordinator "' + (coordinatorName || '') + '"?');
        if (!confirmed) {
            return;
        }

        try {
            const body = new URLSearchParams();
            body.append('coordinator_id', String(coordinatorId));
            const response = await fetch('coordinator_delete.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
                body: body.toString()
            });
            const data = await response.json();

            if (!data.ok) {
                showToast(data.error || 'Unable to delete coordinator.', 'error');
                return;
            }

            document.querySelectorAll('[data-coordinator-id="' + String(coordinatorId) + '"]').forEach(node => {
                const row = node.closest('tr') || node;
                row.remove();
            });

            if (coordinatorTableBody && coordinatorTableBody.querySelectorAll('tr').length === 0) {
                coordinatorTableBody.innerHTML = '<tr><td colspan="9" class="college-empty">No coordinators added yet.</td></tr>';
            }

            incrementCounter(statTotalUsers, -1);
            showToast('Coordinator deleted successfully.', 'success');
        } catch {
            showToast('Network error while deleting coordinator.', 'error');
        }
    }

    async function deleteCollege(collegeId, collegeName) {
        if (!collegeId) {
            return;
        }

        const confirmed = window.confirm('Delete college "' + (collegeName || '') + '"?');
        if (!confirmed) {
            return;
        }

        try {
            const body = new URLSearchParams();
            body.append('id', String(collegeId));
            const response = await fetch('college_delete.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
                body: body.toString()
            });
            const data = await response.json();

            if (!data.ok) {
                showToast(data.error || 'Unable to delete college.', 'error');
                return;
            }

            document.querySelectorAll('.delete-college[data-college-id="' + String(collegeId) + '"]').forEach(node => {
                const row = node.closest('tr') || node;
                row.remove();
            });

            if (document.getElementById('collegeTableBody') && document.getElementById('collegeTableBody').querySelectorAll('tr').length === 0) {
                document.getElementById('collegeTableBody').innerHTML = '<tr><td colspan="5" class="college-empty">No colleges added yet.</td></tr>';
            }

            showToast('College deleted successfully.', 'success');
        } catch {
            showToast('Network error while deleting college.', 'error');
        }
    }
    function incrementCounter(element, amount = 1) {
        if (!element) {
            return;
        }
        const currentValue = parseInt(element.textContent || '0', 10) || 0;
        element.textContent = String(Math.max(0, currentValue + amount));
    }

    function applyStudentFilters() {
        const query = (studentSearchInput && studentSearchInput.value ? studentSearchInput.value : '').trim().toLowerCase();
        const selectedCollege = (studentCollegeFilter && studentCollegeFilter.value ? studentCollegeFilter.value : '').trim().toLowerCase();
        const yearFilterInput = document.getElementById('studentYearFilter');
        const semesterFilterInput = document.getElementById('studentSemesterFilter');
        const selectedYear = (yearFilterInput && yearFilterInput.value ? yearFilterInput.value : '').trim().toLowerCase();
        const selectedSemester = (semesterFilterInput && semesterFilterInput.value ? semesterFilterInput.value : '').trim().toLowerCase();

        const tableRows = usersTableBody
            ? Array.from(usersTableBody.querySelectorAll('tr[data-student-profile-id]'))
            : [];
        const cardRows = studentsCardList
            ? Array.from(studentsCardList.querySelectorAll('.student-card[data-student-profile-id]'))
            : [];

        const matchesFilter = (node) => {
            const nodeCollege = String(node.getAttribute('data-college') || '').toLowerCase();
            const nodeSearch = String(node.getAttribute('data-search') || '').toLowerCase();
            const nodeYear = String(node.getAttribute('data-year') || '').toLowerCase();
            const nodeSemester = String(node.getAttribute('data-semester') || '').toLowerCase();
            
            const matchesCollege = selectedCollege === '' || nodeCollege === selectedCollege;
            const matchesYear = selectedYear === '' || nodeYear === selectedYear;
            const matchesSemester = selectedSemester === '' || nodeSemester === selectedSemester;
            const matchesQuery = query === '' || nodeSearch.includes(query);
            return matchesCollege && matchesYear && matchesSemester && matchesQuery;
        };

        let visibleCount = 0;

        tableRows.forEach((row) => {
            const visible = matchesFilter(row);
            row.style.display = visible ? '' : 'none';
            if (visible) {
                visibleCount += 1;
            }
        });

        cardRows.forEach((card) => {
            const visible = matchesFilter(card);
            card.style.display = visible ? '' : 'none';
        });

        const usersFilterEmptyRow = document.getElementById('usersFilterEmptyRow');
        if (usersFilterEmptyRow) {
            usersFilterEmptyRow.style.display = tableRows.length > 0 && visibleCount === 0 ? '' : 'none';
        }

        const studentsFilterEmptyCard = document.getElementById('studentsFilterEmptyCard');
        if (studentsFilterEmptyCard) {
            studentsFilterEmptyCard.style.display = cardRows.length > 0 && visibleCount === 0 ? '' : 'none';
        }

        if (studentFilterCount) {
            if (tableRows.length === 0) {
                studentFilterCount.textContent = '0 students';
            } else {
                studentFilterCount.textContent = visibleCount + ' of ' + tableRows.length + ' students';
            }
        }
    }

    async function markAllNotificationsRead() {
        if (!notificationMarkAllBtn) {
            return;
        }

        notificationMarkAllBtn.disabled = true;
        notificationMarkAllBtn.textContent = 'Updating...';

        try {
            const response = await fetch('notifications_mark_read.php', { method: 'POST' });
            const data = await response.json();

            if (!data.ok) {
                showToast(data.error || 'Failed to update notifications.', 'error');
                return;
            }

            if (notificationList) {
                notificationList.querySelectorAll('.notification-item').forEach(item => {
                    item.classList.remove('is-unread');
                });
            }

            if (alertsBadge) {
                alertsBadge.textContent = String(data.unread ?? 0);
            }
            if (statAlerts) {
                statAlerts.textContent = String(data.unread ?? 0);
            }
            showToast('All notifications marked as read.', 'success');
        } catch {
            showToast('Network error. Please try again.', 'error');
        } finally {
            notificationMarkAllBtn.disabled = false;
            notificationMarkAllBtn.textContent = 'Mark all read';
        }
    }

    if (notificationMarkAllBtn) {
        notificationMarkAllBtn.addEventListener('click', (event) => {
            event.stopPropagation();
            markAllNotificationsRead();
        });
    }

    document.addEventListener('click', (event) => {
        const viewBtn = event.target.closest('.action-btn.view');
        if (viewBtn) {
            const profileId = viewBtn.getAttribute('data-student-profile-id') || '';
            openStudentModal(profileId);
            return;
        }

        const studentDeleteBtn = event.target.closest('.action-btn.delete[data-student-profile-id]');
        if (studentDeleteBtn) {
            const profileId = studentDeleteBtn.getAttribute('data-student-profile-id') || '';
            const studentName = studentDeleteBtn.getAttribute('data-student-name') || '';
            deleteStudent(profileId, studentName);
            return;
        }

        const coordinatorEditBtn = event.target.closest('.action-btn.edit-coord');
        if (coordinatorEditBtn) {
            const data = JSON.parse(coordinatorEditBtn.getAttribute('data-coordinator'));
            document.getElementById('coord_id').value = data.id;
            document.getElementById('coord_first_name').value = data.first_name || '';
            document.getElementById('coord_second_name').value = data.second_name || '';
            document.getElementById('coord_last_name').value = data.last_name || '';
            document.getElementById('coord_email').value = data.email || '';
            document.getElementById('coord_mobile').value = data.mobile_no || '';
            document.getElementById('coord_pin').value = data.pin || '';
            document.getElementById('coord_address_1').value = data.address_line1 || '';
            document.getElementById('coord_address_2').value = data.address_line2 || '';
            document.getElementById('coord_state').value = data.state || '';
            document.getElementById('coord_district').value = data.district || '';
            
            const collegeSelect = document.getElementById('coord_colleges');
            if (collegeSelect) {
                Array.from(collegeSelect.options).forEach(opt => {
                    opt.selected = data.college_ids.includes(opt.value);
                });
            }
            
            document.getElementById('coordinatorSubmitBtn').innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Update Coordinator';
            coordinatorFormWrap.classList.remove('hidden');
            updateCoordinatorToggleLabel();
            coordinatorFormWrap.scrollIntoView({ behavior: 'smooth' });
            return;
        }

        const coordinatorDeleteBtn = event.target.closest('.action-btn.delete[data-coordinator-id]');
        if (coordinatorDeleteBtn) {
            const coordinatorId = coordinatorDeleteBtn.getAttribute('data-coordinator-id') || '';
            const coordinatorName = coordinatorDeleteBtn.getAttribute('data-coordinator-name') || '';
            deleteCoordinator(coordinatorId, coordinatorName);
            return;
        }

        const collegeEditBtn = event.target.closest('.action-btn.edit-college');
        if (collegeEditBtn) {
            const data = JSON.parse(collegeEditBtn.getAttribute('data-college'));
            document.getElementById('clg_id').value = data.id;
            document.getElementById('clg_name').value = data.name || '';
            document.getElementById('clg_address').value = data.address || '';
            document.getElementById('clg_country').value = data.country || '';
            document.getElementById('clg_state').value = data.state || '';
            document.getElementById('clg_district').value = data.district || '';
            document.getElementById('clg_latitude').value = data.latitude || '';
            document.getElementById('clg_longitude').value = data.longitude || '';
            
            document.getElementById('clgSubmitBtn').innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Update College';
            collegeFormWrap.classList.remove('hidden');
            updateCollegeToggleLabel();
            collegeFormWrap.scrollIntoView({ behavior: 'smooth' });
            return;
        }

        const collegeDeleteBtn = event.target.closest('.action-btn.delete.delete-college[data-college-id]');
        if (collegeDeleteBtn) {
            const collegeId = collegeDeleteBtn.getAttribute('data-college-id') || '';
            const collegeName = collegeDeleteBtn.getAttribute('data-college-name') || '';
            deleteCollege(collegeId, collegeName);
            return;
        }
    });

    if (studentModalClose) {
        studentModalClose.addEventListener('click', closeStudentModal);
    }
    if (studentModalDeleteBtn) {
        studentModalDeleteBtn.addEventListener('click', async () => {
            if (!activeStudentProfileId) {
                return;
            }
            await deleteStudent(activeStudentProfileId, activeStudentName);
            closeStudentModal();
        });
    }
    if (studentModalOverlay) {
        studentModalOverlay.addEventListener('click', (event) => {
            if (event.target === studentModalOverlay) {
                closeStudentModal();
            }
        });
    }
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && studentModalOverlay && studentModalOverlay.classList.contains('show')) {
            closeStudentModal();
        }
    });

    toggleCollegeFormBtn.addEventListener('click', () => {
        if (!collegeFormWrap.classList.contains('hidden')) {
            document.getElementById('clgForm').reset();
            document.getElementById('clg_id').value = '';
            document.getElementById('clgSubmitBtn').innerHTML = '<i class="fa-solid fa-plus"></i> Save College';
        }
        collegeFormWrap.classList.toggle('hidden');
        updateCollegeToggleLabel();
    });

    toggleCoordinatorFormBtn.addEventListener('click', () => {
        if (!coordinatorFormWrap.classList.contains('hidden')) {
            document.getElementById('coordinatorForm').reset();
            document.getElementById('coord_id').value = '';
            document.getElementById('coordinatorSubmitBtn').innerHTML = '<i class="fa-solid fa-plus"></i> Create Coordinator';
            const collegeSelect = document.getElementById('coord_colleges');
            if (collegeSelect) {
                Array.from(collegeSelect.options).forEach(opt => opt.selected = false);
            }
        }
        coordinatorFormWrap.classList.toggle('hidden');
        updateCoordinatorToggleLabel();
    });

    if (studentSearchInput) {
        studentSearchInput.addEventListener('input', applyStudentFilters);
    }
    if (studentCollegeFilter) {
        studentCollegeFilter.addEventListener('change', applyStudentFilters);
    }
    applyStudentFilters();

    updateCollegeToggleLabel();
    updateCoordinatorToggleLabel();

    /* ── Toast ── */
    function showToast(msg, type) {
        let t = document.getElementById('globalToast');
        t.className = 'toast ' + type;
        t.innerHTML = (type === 'success'
            ? '<i class="fa-solid fa-circle-check"></i>'
            : '<i class="fa-solid fa-circle-xmark"></i>') + msg;
        t.classList.add('show');
        setTimeout(() => t.classList.remove('show'), 3500);
    }

    /* ── Add College form ── */
    document.getElementById('clgForm').addEventListener('submit', async function(e) {
        e.preventDefault();
        const btn = document.getElementById('clgSubmitBtn');
        const fields = ['clg_name','clg_country','clg_state','clg_district','clg_address'];
        let valid = true;
        fields.forEach(id => {
            const el = document.getElementById(id);
            if (!el.value.trim()) { el.style.borderColor = 'var(--red)'; valid = false; }
            else el.style.borderColor = '';
        });
        if (!valid) { showToast('Please fill all fields.', 'error'); return; }

        btn.disabled = true;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving…';

        const body = new FormData(this);
        try {
            const res  = await fetch('college_save.php', { method: 'POST', body });
            const data = await res.json();
            if (data.ok) {
                if (document.getElementById('clg_id').value !== '') {
                    showToast('College updated successfully. Refreshing...', 'success');
                    setTimeout(() => window.location.reload(), 1000);
                } else {
                    showToast('College added successfully!', 'success');
                    if (data.college) {
                        prependCollegeRow(data.college);
                    }
                    this.reset();
                    document.getElementById('clg_id').value = '';
                    collegeFormWrap.classList.add('hidden');
                    updateCollegeToggleLabel();
                }
            } else {
                showToast(data.error || 'Failed to save.', 'error');
            }
        } catch {
            showToast('Network error. Please try again.', 'error');
        }

        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-plus"></i> Add College';
    });

    /* ── Add Coordinator form ── */
    document.getElementById('coordinatorForm').addEventListener('submit', async function(e) {
        e.preventDefault();
        const btn = document.getElementById('coordinatorSubmitBtn');
        const fields = [
            'coord_first_name','coord_last_name','coord_email','coord_mobile','coord_pin',
            'coord_address_1','coord_state','coord_district'
        ];

        let valid = true;
        fields.forEach(id => {
            const el = document.getElementById(id);
            if (!el.value.trim()) { el.style.borderColor = 'var(--red)'; valid = false; }
            else el.style.borderColor = '';
        });

        const collegeSelect = document.getElementById('coord_colleges');
        const selectedColleges = Array.from(collegeSelect.selectedOptions);
        if (selectedColleges.length === 0) {
            collegeSelect.style.borderColor = 'var(--red)';
            valid = false;
        } else {
            collegeSelect.style.borderColor = '';
        }

        if (!valid) {
            showToast('Please fill all mandatory coordinator details and assign colleges.', 'error');
            return;
        }

        btn.disabled = true;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Creating…';

        const body = new FormData(this);
        try {
            const res = await fetch('coordinator_save.php', { method: 'POST', body });
            const data = await res.json();

            if (data.ok) {
                if (document.getElementById('coord_id').value !== '') {
                    showToast('Coordinator updated successfully. Refreshing...', 'success');
                    setTimeout(() => window.location.reload(), 1000);
                } else {
                    if (data.coordinator) {
                        prependCoordinatorRow(data.coordinator);
                    }
                    incrementCounter(statTotalUsers, 1);
                    this.reset();
                    document.getElementById('coord_id').value = '';
                    coordinatorFormWrap.classList.add('hidden');
                    updateCoordinatorToggleLabel();
                    showToast(
                        'Coordinator created. Login ID: ' + escapeHtml(data.login_id || '') + '<br>Password: ' + escapeHtml(data.generated_password || ''),
                        'success'
                    );
                }
            } else {
                showToast(data.error || 'Failed to create coordinator.', 'error');
            }
        } catch {
            showToast('Network error. Please try again.', 'error');
        }

        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-plus"></i> Create Coordinator';
    });

    /* ── Edit Student Logic ── */
    const studentFormWrap = document.getElementById('studentFormWrap');
    const cancelStudentEditBtn = document.getElementById('cancelStudentEditBtn');
    const studentEditForm = document.getElementById('studentEditForm');

    if (cancelStudentEditBtn && studentFormWrap) {
        cancelStudentEditBtn.addEventListener('click', () => {
            studentFormWrap.classList.add('hidden');
            document.querySelector('.student-filter-bar').style.display = '';
            document.querySelector('.students-table-wrap').style.display = '';
            document.getElementById('studentsCardList').style.display = '';
        });
    }

    document.querySelectorAll('.edit-student').forEach(btn => {
        btn.addEventListener('click', () => {
            const data = JSON.parse(btn.getAttribute('data-student') || '{}');
            document.getElementById('edit_student_profile_id').value = data.profile_id || '';
            document.getElementById('edit_student_user_id').value = data.user_id || '';
            document.getElementById('edit_student_first_name').value = data.first_name || '';
            document.getElementById('edit_student_middle_name').value = data.middle_name || '';
            document.getElementById('edit_student_last_name').value = data.last_name || '';
            document.getElementById('edit_student_login_id').value = data.login_id || '';
            document.getElementById('edit_student_email').value = data.email || '';
            document.getElementById('edit_student_mobile_no').value = data.mobile_no || '';
            document.getElementById('edit_student_state').value = data.state || '';
            document.getElementById('edit_student_district').value = data.district || '';
            document.getElementById('edit_student_college_id').value = data.college_id || '';
            document.getElementById('edit_student_course_id').value = data.course_id || '';
            document.getElementById('edit_student_academic_year').value = data.academic_year || '';
            document.getElementById('edit_student_semester').value = data.semester || '';
            document.getElementById('edit_student_password').value = ''; // Always clear password

            // Hide list and show form
            document.querySelector('.student-filter-bar').style.display = 'none';
            document.querySelector('.students-table-wrap').style.display = 'none';
            document.getElementById('studentsCardList').style.display = 'none';
            studentFormWrap.classList.remove('hidden');
            window.scrollTo({ top: 0, behavior: 'smooth' });
        });
    });

    if (studentEditForm) {
        studentEditForm.addEventListener('submit', async function(e) {
            e.preventDefault();
            const btn = document.getElementById('studentSubmitBtn');
            const originalHTML = btn.innerHTML;
            
            // Basic validation
            const requiredFields = [
                'edit_student_first_name', 'edit_student_last_name', 'edit_student_login_id',
                'edit_student_email', 'edit_student_mobile_no', 'edit_student_state',
                'edit_student_district', 'edit_student_college_id', 'edit_student_course_id'
            ];
            let valid = true;
            requiredFields.forEach(id => {
                const el = document.getElementById(id);
                if (!el.value.trim()) { el.style.borderColor = 'var(--red)'; valid = false; }
                else { el.style.borderColor = ''; }
            });

            if (!valid) {
                showToast('Please fill out all required fields.', 'error');
                return;
            }

            btn.disabled = true;
            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving...';

            const body = new FormData(this);
            try {
                const res = await fetch('student_update.php', { method: 'POST', body });
                const data = await res.json();
                if (data.ok) {
                    showToast('Student updated successfully!', 'success');
                    setTimeout(() => window.location.reload(), 1000);
                } else {
                    showToast(data.error || 'Failed to update student.', 'error');
                }
            } catch (err) {
                showToast('Network error. Please try again.', 'error');
            }

            btn.disabled = false;
            btn.innerHTML = originalHTML;
        });
    }

    /* ── Section switching ── */
    const sections = document.querySelectorAll('[id^="section-"]');
    const navItems = document.querySelectorAll('.nav-item');

    function showSection(name, updateHash = true) {
        if (updateHash) {
            window.location.hash = name;
        }
        // Persist to localStorage so refresh stays on same page
        try { localStorage.setItem('admin_active_section', name); } catch(e) {}
        sections.forEach(s => s.style.display = 'none');
        const target = document.getElementById('section-' + name);
        if (target) target.style.display = 'block';

        navItems.forEach(n => {
            n.classList.toggle('active', n.getAttribute('onclick') && n.getAttribute('onclick').includes("'" + name + "'"));
        });

        if (isMobile()) {
            sidebar.classList.remove('open');
            sidebarOverlay.classList.remove('visible');
        }

        if (notificationWrap) {
            notificationWrap.classList.remove('open');
        }
    }

    // ── TICKET FILTERING ────────────────────────────
    const adminTicketSearchInput = document.getElementById('adminTicketSearchInput');
    const adminTicketStatusFilter = document.getElementById('adminTicketStatusFilter');
    const adminTicketVisibleCount = document.getElementById('adminTicketVisibleCount');
    const adminTicketFilterEmpty = document.getElementById('adminTicketFilterEmpty');
    const adminTicketResetBtn = document.getElementById('adminTicketResetBtn');
    const ticketRows = document.querySelectorAll('[data-ticket-item="1"]');

    function applyAdminTicketFilters() {
        if (!ticketRows.length) {
            if (adminTicketVisibleCount) {
                adminTicketVisibleCount.textContent = 'Showing 0 tickets';
            }
            return;
        }

        const query = (adminTicketSearchInput && adminTicketSearchInput.value ? adminTicketSearchInput.value : '').trim().toLowerCase();
        const selectedStatus = (adminTicketStatusFilter && adminTicketStatusFilter.value ? adminTicketStatusFilter.value : '').trim().toLowerCase();

        let visibleCount = 0;
        ticketRows.forEach((row) => {
            const rowStatus = String(row.getAttribute('data-status') || '').toLowerCase();
            const rowSearch = String(row.getAttribute('data-search') || '').toLowerCase();
            const matchesStatus = selectedStatus === '' || rowStatus === selectedStatus;
            const matchesQuery = query === '' || rowSearch.includes(query);
            const visible = matchesStatus && matchesQuery;
            row.style.display = visible ? '' : 'none';
            if (visible) {
                visibleCount += 1;
            }
        });

        if (adminTicketVisibleCount) {
            adminTicketVisibleCount.textContent = 'Showing ' + visibleCount + ' ticket' + (visibleCount === 1 ? '' : 's');
        }
        if (adminTicketFilterEmpty) {
            adminTicketFilterEmpty.style.display = (visibleCount === 0) ? '' : 'none';
        }
    }

    if (adminTicketSearchInput) {
        adminTicketSearchInput.addEventListener('input', applyAdminTicketFilters);
    }

    if (adminTicketStatusFilter) {
        adminTicketStatusFilter.addEventListener('change', applyAdminTicketFilters);
    }

    if (adminTicketResetBtn) {
        adminTicketResetBtn.addEventListener('click', () => {
            if (adminTicketSearchInput) {
                adminTicketSearchInput.value = '';
            }
            if (adminTicketStatusFilter) {
                adminTicketStatusFilter.value = '';
            }
            applyAdminTicketFilters();
            if (adminTicketSearchInput) {
                adminTicketSearchInput.focus();
            }
        });
    }

    if (studentSearchInput) {
        studentSearchInput.addEventListener('input', applyStudentFilters);
    }
    if (studentCollegeFilter) {
        studentCollegeFilter.addEventListener('change', applyStudentFilters);
    }
    const studentYearFilter = document.getElementById('studentYearFilter');
    if (studentYearFilter) {
        studentYearFilter.addEventListener('change', applyStudentFilters);
    }
    const studentSemesterFilter = document.getElementById('studentSemesterFilter');
    if (studentSemesterFilter) {
        studentSemesterFilter.addEventListener('change', applyStudentFilters);
    }
    
    // Add reset filter logic if it exists (some other parts might use clear)
    
    // ── FEES FILTERING ──────────────────────────────
    const adminFeeSearchInput = document.getElementById('adminFeeSearchInput');
    const adminFeeStatusFilter = document.getElementById('adminFeeStatusFilter');
    const adminFeeVisibleCount = document.getElementById('adminFeeVisibleCount');
    const adminFeeFilterEmpty = document.getElementById('adminFeeFilterEmpty');
    const adminFeeResetBtn = document.getElementById('adminFeeResetBtn');
    const feeRows = document.querySelectorAll('[data-fee-item="1"]');

    function applyAdminFeeFilters() {
        if (!feeRows.length) {
            if (adminFeeVisibleCount) {
                adminFeeVisibleCount.textContent = 'Showing 0 students';
            }
            return;
        }

        const query = (adminFeeSearchInput && adminFeeSearchInput.value ? adminFeeSearchInput.value : '').trim().toLowerCase();
        const selectedStatus = (adminFeeStatusFilter && adminFeeStatusFilter.value ? adminFeeStatusFilter.value : '').trim().toLowerCase();

        let visibleCount = 0;
        let tAssigned = 0;
        let tCollected = 0;
        let tPending = 0;
        let fPaid = 0;

        feeRows.forEach((row) => {
            const rowStatus = String(row.getAttribute('data-status') || '').toLowerCase();
            const rowSearch = String(row.getAttribute('data-search') || '').toLowerCase();
            const matchesStatus = selectedStatus === '' || rowStatus === selectedStatus;
            const matchesQuery = query === '' || rowSearch.includes(query);
            const visible = matchesStatus && matchesQuery;
            row.style.display = visible ? '' : 'none';
            
            if (visible) {
                visibleCount += 1;
                tAssigned += parseFloat(row.getAttribute('data-total') || 0);
                tCollected += parseFloat(row.getAttribute('data-paid') || 0);
                let p = parseFloat(row.getAttribute('data-pending') || 0);
                tPending += p;
                if (p <= 0.009) fPaid++;
            }
        });

        if (adminFeeVisibleCount) {
            adminFeeVisibleCount.textContent = 'Showing ' + visibleCount + ' student' + (visibleCount === 1 ? '' : 's');
        }
        if (adminFeeFilterEmpty) {
            adminFeeFilterEmpty.style.display = (visibleCount === 0) ? '' : 'none';
        }

        const fmtCurrency = (val) => 'INR ' + val.toLocaleString('en-IN', {minimumFractionDigits: 2, maximumFractionDigits: 2});
        
        const elAssigned = document.getElementById('adminTotalFeeAssigned');
        const elCollected = document.getElementById('adminTotalFeeCollected');
        const elPending = document.getElementById('adminTotalFeePending');
        const elFullyPaid = document.getElementById('adminFullyPaidStudents');
        const elTotalStudentsHint = document.getElementById('adminTotalStudentsHint');
        const elCollectionRate = document.getElementById('adminCollectionRate');
        const elStudentsPending = document.getElementById('adminStudentsPending');
        const elFullyPaidHint = document.getElementById('adminFullyPaidHint');

        if (elAssigned) elAssigned.textContent = fmtCurrency(tAssigned);
        if (elCollected) elCollected.textContent = fmtCurrency(tCollected);
        if (elPending) elPending.textContent = fmtCurrency(tPending);
        if (elFullyPaid) elFullyPaid.textContent = fPaid;
        if (elTotalStudentsHint) elTotalStudentsHint.textContent = 'Across ' + visibleCount + ' students';
        if (elFullyPaidHint) elFullyPaidHint.textContent = 'Out of ' + visibleCount + ' students';
        
        let cRate = tAssigned > 0 ? ((tCollected / tAssigned) * 100).toFixed(1) : '0.0';
        if (elCollectionRate) elCollectionRate.textContent = 'Collection rate ' + cRate + '%';
        if (elStudentsPending) elStudentsPending.textContent = Math.max(0, visibleCount - fPaid) + ' students pending';
    }

    if (adminFeeSearchInput) {
        adminFeeSearchInput.addEventListener('input', applyAdminFeeFilters);
    }

    if (adminFeeStatusFilter) {
        adminFeeStatusFilter.addEventListener('change', applyAdminFeeFilters);
    }

    if (adminFeeResetBtn) {
        adminFeeResetBtn.addEventListener('click', () => {
            if (adminFeeSearchInput) {
                adminFeeSearchInput.value = '';
            }
            if (adminFeeStatusFilter) {
                adminFeeStatusFilter.value = '';
            }
            applyAdminFeeFilters();
            if (adminFeeSearchInput) {
                adminFeeSearchInput.focus();
            }
        });
    }

    // ── SETTINGS SAVE ───────────────────────────────
    const adminSettingsForm = document.getElementById('adminSettingsForm');
    const adminSettingsSubmitBtn = document.getElementById('adminSettingsSubmitBtn');

    if (adminSettingsForm && adminSettingsSubmitBtn) {
        adminSettingsForm.addEventListener('submit', async (event) => {
            event.preventDefault();

            const fullNameInput = document.getElementById('settings_full_name');
            const loginIdInput = document.getElementById('settings_login_id');
            const currentPasswordInput = document.getElementById('settings_current_password');
            const newPasswordInput = document.getElementById('settings_new_password');
            const confirmPasswordInput = document.getElementById('settings_confirm_password');

            const mobileNoInput = document.getElementById('settings_mobile_no');

            const fullName = fullNameInput ? fullNameInput.value.trim() : '';
            const loginId = loginIdInput ? loginIdInput.value.trim() : '';
            const mobileNo = mobileNoInput ? mobileNoInput.value.trim() : '';
            const currentPassword = currentPasswordInput ? currentPasswordInput.value : '';
            const newPassword = newPasswordInput ? newPasswordInput.value : '';
            const confirmPassword = confirmPasswordInput ? confirmPasswordInput.value : '';

            if (fullName.length < 2) {
                showToast('Please enter a valid name (minimum 2 characters).', 'error');
                return;
            }

            if (loginId.length < 4) {
                showToast('Please enter a valid login ID (minimum 4 characters).', 'error');
                return;
            }

            if (newPassword !== '' && newPassword.length < 8) {
                showToast('New password must be at least 8 characters.', 'error');
                return;
            }

            if (newPassword !== confirmPassword) {
                showToast('New password and confirmation do not match.', 'error');
                return;
            }

            adminSettingsSubmitBtn.disabled = true;
            adminSettingsSubmitBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Processing...';

            const payload = {
                full_name: fullName,
                login_id: loginId,
                mobile_no: mobileNo,
                current_password: currentPassword,
                new_password: newPassword,
                confirm_password: confirmPassword,
            };

            // If changing password, request OTP first
            if (newPassword !== '') {
                try {
                    const response = await fetch('settings_save.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ action: 'request_otp', ...payload })
                    });
                    const result = await response.json();
                    if (!result.ok) {
                        throw new Error(result.error || 'Failed to request OTP.');
                    }
                    
                    // Show OTP Modal
                    document.getElementById('adminOtpOverlay').style.display = 'flex';
                    // Show mock OTP for testing
                    if (result.mock_otp) {
                        alert(`MOCK SMS (Sent to ${mobileNo}): Your 3DShikshan Admin OTP is ${result.mock_otp}`);
                    }
                } catch (error) {
                    showToast(error.message || 'Unable to request OTP right now.', 'error');
                } finally {
                    adminSettingsSubmitBtn.disabled = false;
                    adminSettingsSubmitBtn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Save Settings';
                }
                return; // Stop here, wait for OTP modal verification
            }

            // Normal save without OTP (if password is not changed)
            try {
                const response = await fetch('settings_save.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'save', ...payload })
                });

                const result = await response.json();
                if (!result.ok) {
                    throw new Error(result.error || 'Unable to save settings.');
                }

                showToast('Settings updated successfully. Refreshing dashboard...', 'success');
                setTimeout(() => {
                    window.location.reload();
                }, 900);
            } catch (error) {
                showToast(error.message || 'Unable to save settings right now.', 'error');
            } finally {
                adminSettingsSubmitBtn.disabled = false;
                adminSettingsSubmitBtn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Save Settings';
            }
        });
    }

    const adminOtpVerifyBtn = document.getElementById('adminOtpVerifyBtn');
    if (adminOtpVerifyBtn) {
        adminOtpVerifyBtn.addEventListener('click', async () => {
            const otp = document.getElementById('settings_otp').value.trim();
            if (otp.length !== 6) {
                showToast('Please enter a valid 6-digit OTP.', 'error');
                return;
            }

            const fullName = document.getElementById('settings_full_name').value.trim();
            const loginId = document.getElementById('settings_login_id').value.trim();
            const mobileNo = document.getElementById('settings_mobile_no').value.trim();
            const currentPassword = document.getElementById('settings_current_password').value;
            const newPassword = document.getElementById('settings_new_password').value;

            adminOtpVerifyBtn.disabled = true;
            adminOtpVerifyBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Verifying...';

            try {
                const response = await fetch('settings_save.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'verify_otp',
                        otp: otp,
                        full_name: fullName,
                        login_id: loginId,
                        mobile_no: mobileNo,
                        current_password: currentPassword,
                        new_password: newPassword
                    })
                });

                const result = await response.json();
                if (!result.ok) {
                    throw new Error(result.error || 'Invalid OTP or failed to save settings.');
                }

                document.getElementById('adminOtpOverlay').style.display = 'none';
                showToast('Settings & Password updated successfully. Refreshing...', 'success');
                setTimeout(() => {
                    window.location.reload();
                }, 900);
            } catch (error) {
                showToast(error.message || 'Unable to verify OTP right now.', 'error');
            } finally {
                adminOtpVerifyBtn.disabled = false;
                adminOtpVerifyBtn.innerHTML = 'Verify & Save';
            }
        });
    }

    applyAdminFeeFilters();

    applyAdminTicketFilters();

    window.addEventListener('hashchange', () => {
        const hash = window.location.hash.replace('#', '');
        if (hash && document.getElementById('section-' + hash)) {
            showSection(hash, false);
        }
    });

    document.addEventListener('DOMContentLoaded', () => {
        // Restore section: prefer URL hash, fall back to localStorage
        const hash = window.location.hash.replace('#', '');
        if (hash && document.getElementById('section-' + hash)) {
            showSection(hash, false);
        } else {
            try {
                const saved = localStorage.getItem('admin_active_section');
                if (saved && document.getElementById('section-' + saved)) {
                    showSection(saved, false);
                }
            } catch(e) {}
        }
    });
</script>
<!-- Colleges Popover -->
<div id="collegesPopoverBackdrop"></div>
<div id="collegesPopover" role="dialog" aria-modal="true" aria-label="Assigned Colleges">
    <div class="clg-popover-header">
        <div class="clg-popover-title"><i class="fa-solid fa-building-columns"></i> Assigned Colleges</div>
        <button type="button" class="clg-popover-close" id="clgPopoverClose" aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="clg-popover-list" id="clgPopoverList"></div>
</div>
<script>
(function() {
    const popover = document.getElementById('collegesPopover');
    const backdrop = document.getElementById('collegesPopoverBackdrop');
    const list = document.getElementById('clgPopoverList');
    const closeBtn = document.getElementById('clgPopoverClose');

    function closePopover() {
        popover.classList.remove('visible');
        backdrop.classList.remove('visible');
    }

    function openPopover(badge) {
        let colleges;
        try { colleges = JSON.parse(badge.getAttribute('data-colleges') || '[]'); } catch(e) { colleges = []; }
        list.innerHTML = colleges.map(c => `<div class="clg-popover-item"><i class="fa-solid fa-circle-dot"></i>${c}</div>`).join('');

        // Position
        const rect = badge.getBoundingClientRect();
        const pw = Math.min(340, window.innerWidth - 24);
        popover.style.width = pw + 'px';
        popover.style.minWidth = '';

        let left = rect.left;
        let top = rect.bottom + 6;

        // Keep within viewport horizontally
        if (left + pw > window.innerWidth - 12) left = window.innerWidth - pw - 12;
        if (left < 12) left = 12;

        // Flip up if not enough space below
        const popH = 300; // approximate max height
        if (top + popH > window.innerHeight - 12) {
            top = rect.top - 6 - Math.min(popH, list.children.length * 46 + 60);
            if (top < 12) top = 12;
        }

        popover.style.left = left + 'px';
        popover.style.top = top + 'px';

        popover.classList.add('visible');
        backdrop.classList.add('visible');
    }

    // Delegate click on badges
    document.addEventListener('click', function(e) {
        const badge = e.target.closest('.colleges-badge');
        if (badge) {
            e.stopPropagation();
            if (popover.classList.contains('visible')) {
                closePopover();
            } else {
                openPopover(badge);
            }
            return;
        }
        if (e.target === backdrop || e.target.closest('#clgPopoverClose')) {
            closePopover();
        }
    });

    // Close on backdrop tap (mobile)
    backdrop.addEventListener('click', closePopover);
    closeBtn.addEventListener('click', closePopover);

    // Close on resize only (not scroll - scroll shouldn't close the popover)
    window.addEventListener('resize', closePopover);
})();
</script>
</body>
</html>
