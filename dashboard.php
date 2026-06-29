<?php
declare(strict_types=1);

session_start();

$user = $_SESSION['user'] ?? null;
if (!$user) {
    header('Location: index.php');
    exit;
}

if (($user['role'] ?? '') === 'admin') {
    header('Location: admin/dashboard.php');
    exit;
}

if (($user['role'] ?? '') === 'coordinator') {
    header('Location: coordinator/dashboard.php');
    exit;
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

$studentProfile = null;
$paymentRows = [];
$courseTotalFees = 0.0;
$totalPaidFees = 0.0;
$pendingFees = 0.0;
$attendanceMarked = 0;
$attendancePresent = 0;
$attendanceAbsent = 0;
$attendancePercent = 0;
$studentNotifications = [];
$unreadNotificationCount = 0;
$scheduleSessions = [];
$nextScheduledSessionDate = '';
$studentTickets = [];
$ticketCoordinatorName = '';

$conn = getDbConnection();
if ($conn !== null) {
    $conn->query(
        "CREATE TABLE IF NOT EXISTS student_notifications (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            student_profile_id INT UNSIGNED NOT NULL,
            college_id INT UNSIGNED NOT NULL,
            coordinator_session_id INT UNSIGNED NULL,
            title VARCHAR(255) NOT NULL,
            message VARCHAR(1200) NOT NULL,
            is_read TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_sn_student_created (student_profile_id, created_at),
            INDEX idx_sn_user_unread (user_id, is_read)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $conn->query(
        "CREATE TABLE IF NOT EXISTS session_attendance (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            session_id INT UNSIGNED NOT NULL,
            student_profile_id INT UNSIGNED NOT NULL,
            status ENUM('present','absent') NOT NULL DEFAULT 'absent',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uk_sa_session_student (session_id, student_profile_id),
            INDEX idx_sa_session (session_id),
            INDEX idx_sa_student (student_profile_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $conn->query(
        "CREATE TABLE IF NOT EXISTS coordinator_tickets (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            coordinator_id INT UNSIGNED NOT NULL,
            student_profile_id INT UNSIGNED NOT NULL,
            college_id INT UNSIGNED NOT NULL,
            subject VARCHAR(180) NOT NULL,
            message VARCHAR(2000) NOT NULL,
            status ENUM('open','in_progress','resolved') NOT NULL DEFAULT 'open',
            is_seen_by_coordinator TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_ct_coordinator_seen (coordinator_id, is_seen_by_coordinator),
            INDEX idx_ct_student_created (student_profile_id, created_at),
            INDEX idx_ct_status_created (status, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $profileSql = '
        SELECT
            sp.id,
            sp.college_id,
            sp.first_name,
            sp.middle_name,
            sp.last_name,
            sp.mobile_no,
            sp.email,
            sp.state,
            sp.district,
            sp.city,
            c.name AS college_name,
            cr.course_name,
            cr.description,
            cr.duration,
            cr.fees,
            cr.required_details
        FROM student_profiles sp
        LEFT JOIN colleges c ON c.id = sp.college_id
        LEFT JOIN courses cr ON cr.id = sp.course_id
        WHERE sp.user_id = ?
        LIMIT 1
    ';

    $profileStmt = $conn->prepare($profileSql);
    if ($profileStmt !== false) {
        $uid = (int)$user['id'];
        $profileStmt->bind_param('i', $uid);
        $profileStmt->execute();
        $profileResult = $profileStmt->get_result();
        $studentProfile = $profileResult ? $profileResult->fetch_assoc() : null;
        $profileStmt->close();
    }

    if ($studentProfile) {
        $paymentSql = '
            SELECT id, razorpay_order_id, razorpay_payment_id, amount_rupees, currency, status, created_at
            FROM registration_payments
            WHERE student_profile_id = ?
            ORDER BY created_at DESC
        ';
        $paymentStmt = $conn->prepare($paymentSql);
        if ($paymentStmt !== false) {
            $studentProfileId = (int)$studentProfile['id'];
            $paymentStmt->bind_param('i', $studentProfileId);
            $paymentStmt->execute();
            $paymentResult = $paymentStmt->get_result();
            if ($paymentResult instanceof mysqli_result) {
                while ($row = $paymentResult->fetch_assoc()) {
                    $paymentRows[] = $row;
                }
            }
            $paymentStmt->close();
        }

        $feeText = (string)($studentProfile['fees'] ?? '0');
        $feeNumericText = preg_replace('/[^0-9.]/', '', $feeText) ?? '0';
        $courseTotalFees = (float)$feeNumericText;

        foreach ($paymentRows as $payment) {
            $totalPaidFees += (float)$payment['amount_rupees'];
        }

        $pendingFees = max(0.0, $courseTotalFees - $totalPaidFees);

        $attendanceSql = '
            SELECT
                COUNT(*) AS attendance_marked,
                COALESCE(SUM(CASE WHEN status = "present" THEN 1 ELSE 0 END), 0) AS attendance_present
            FROM session_attendance
            WHERE student_profile_id = ?
        ';
        $attendanceStmt = $conn->prepare($attendanceSql);
        if ($attendanceStmt !== false) {
            $studentProfileId = (int)$studentProfile['id'];
            $attendanceStmt->bind_param('i', $studentProfileId);
            $attendanceStmt->execute();
            $attendanceResult = $attendanceStmt->get_result();
            $attendanceRow = $attendanceResult ? $attendanceResult->fetch_assoc() : null;
            $attendanceStmt->close();

            if (is_array($attendanceRow)) {
                $attendanceMarked = (int)($attendanceRow['attendance_marked'] ?? 0);
                $attendancePresent = (int)($attendanceRow['attendance_present'] ?? 0);
                $attendanceAbsent = max(0, $attendanceMarked - $attendancePresent);
                $attendancePercent = $attendanceMarked > 0
                    ? (int)round(($attendancePresent / $attendanceMarked) * 100)
                    : 0;
            }
        }

        $notificationSql = '
            SELECT id, title, message, is_read, created_at
            FROM student_notifications
            WHERE student_profile_id = ?
            ORDER BY created_at DESC
            LIMIT 30
        ';
        $notificationStmt = $conn->prepare($notificationSql);
        if ($notificationStmt !== false) {
            $studentProfileId = (int)$studentProfile['id'];
            $notificationStmt->bind_param('i', $studentProfileId);
            $notificationStmt->execute();
            $notificationResult = $notificationStmt->get_result();
            if ($notificationResult instanceof mysqli_result) {
                while ($row = $notificationResult->fetch_assoc()) {
                    $studentNotifications[] = $row;
                    if ((int)($row['is_read'] ?? 0) === 0) {
                        $unreadNotificationCount++;
                    }
                }
            }
            $notificationStmt->close();
        }

        $scheduleStmt = $conn->prepare(
            'SELECT cs.session_date, cs.session_details, cs.session_type, cs.notes
             FROM coordinator_sessions cs
             WHERE cs.college_id = ?
             ORDER BY cs.session_date ASC'
        );
        if ($scheduleStmt !== false) {
            $studentCollegeId = (int)$studentProfile['college_id'];
            $scheduleStmt->bind_param('i', $studentCollegeId);
            $scheduleStmt->execute();
            $scheduleResult = $scheduleStmt->get_result();
            if ($scheduleResult instanceof mysqli_result) {
                while ($row = $scheduleResult->fetch_assoc()) {
                    $scheduleSessions[] = [
                        'date'    => (string)$row['session_date'],
                        'details' => (string)$row['session_details'],
                        'type'    => (string)($row['session_type'] ?? 'Class'),
                        'notes'   => (string)($row['notes'] ?? ''),
                    ];
                }
            }
            $scheduleStmt->close();

            $todayDate = date('Y-m-d');
            foreach ($scheduleSessions as $session) {
                $sessionDate = (string)($session['date'] ?? '');
                if ($sessionDate !== '' && $sessionDate >= $todayDate) {
                    $nextScheduledSessionDate = $sessionDate;
                    break;
                }
            }
        }

        $coordinatorStmt = $conn->prepare(
            'SELECT c.first_name, c.second_name, c.last_name
             FROM coordinator_colleges cc
             INNER JOIN coordinators c ON c.id = cc.coordinator_id
             WHERE cc.college_id = ?
             ORDER BY cc.coordinator_id ASC
             LIMIT 1'
        );
        if ($coordinatorStmt !== false) {
            $studentCollegeId = (int)$studentProfile['college_id'];
            $coordinatorStmt->bind_param('i', $studentCollegeId);
            $coordinatorStmt->execute();
            $coordinatorResult = $coordinatorStmt->get_result();
            $coordinatorRow = $coordinatorResult ? $coordinatorResult->fetch_assoc() : null;
            if (is_array($coordinatorRow)) {
                $ticketCoordinatorName = trim(
                    (string)($coordinatorRow['first_name'] ?? '') . ' ' .
                    (string)($coordinatorRow['second_name'] ?? '') . ' ' .
                    (string)($coordinatorRow['last_name'] ?? '')
                );
            }
            $coordinatorStmt->close();
        }

        $ticketStmt = $conn->prepare(
            'SELECT id, subject, message, status, created_at, updated_at
             FROM coordinator_tickets
             WHERE student_profile_id = ?
             ORDER BY created_at DESC
             LIMIT 40'
        );
        if ($ticketStmt !== false) {
            $studentProfileId = (int)$studentProfile['id'];
            $ticketStmt->bind_param('i', $studentProfileId);
            $ticketStmt->execute();
            $ticketResult = $ticketStmt->get_result();
            if ($ticketResult instanceof mysqli_result) {
                while ($row = $ticketResult->fetch_assoc()) {
                    $studentTickets[] = $row;
                }
            }
            $ticketStmt->close();
        }
    }

    $conn->close();
}

$displayName = htmlspecialchars((string)$user['name'], ENT_QUOTES, 'UTF-8');
$displayLoginId = htmlspecialchars((string)$user['login_id'], ENT_QUOTES, 'UTF-8');
$displayRole = htmlspecialchars(ucfirst((string)$user['role']), ENT_QUOTES, 'UTF-8');
$initial = strtoupper(substr((string)$user['name'], 0, 1));
$razorpayEnabled = RAZORPAY_KEY_ID !== '' && RAZORPAY_KEY_SECRET !== '';

function esc(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>Student Dashboard - 3D Shikshan</title>
    <link rel="icon" type="image/png" href="assets/logo.png" />
    <link rel="manifest" href="manifest.webmanifest" />
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;700&family=Space+Grotesk:wght@500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="assets/css/legal.css">
    <style>
        *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }

        :root {
            --bg: #f5f6f8;
            --surface: #ffffff;
            --surface-2: #eef0f4;
            --border: #e0e3ea;
            --text: #1a1d26;
            --text-muted: #6b7185;
            --accent: #0b8a5e;
            --accent-light: #0b8a5e14;
            --red: #d9435f;
            --sidebar-w: 240px;
            --topbar-h: 62px;
            --radius: 14px;
            --transition: .22s cubic-bezier(.4,0,.2,1);
        }

        html { -webkit-tap-highlight-color: transparent; }

        body {
            font-family: 'DM Sans', sans-serif;
            background: var(--bg);
            color: var(--text);
            line-height: 1.6;
            min-height: 100vh;
            -webkit-font-smoothing: antialiased;
        }

        .topbar {
            position: fixed; top: 0; left: 0; right: 0; z-index: 200;
            height: var(--topbar-h);
            background: var(--surface);
            border-bottom: 1px solid var(--border);
            display: flex; align-items: center;
            padding: 0 24px; gap: 16px;
        }
        .topbar-brand {
            display: flex; align-items: center; gap: 10px;
            font-family: 'Space Grotesk', sans-serif;
            font-size: 1.08rem; font-weight: 700;
            color: var(--accent); text-decoration: none;
            flex-shrink: 0;
        }
        .topbar-brand i { font-size: 1.15rem; }
        .topbar-brand span { color: var(--text); }

        .sidebar-toggle {
            background: none; border: none; cursor: pointer;
            color: var(--text-muted); font-size: 1.1rem;
            width: 36px; height: 36px; border-radius: 8px;
            display: flex; align-items: center; justify-content: center;
            transition: var(--transition);
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
            position: absolute; top: calc(100% + 10px); right: 0;
            width: min(360px, 88vw);
            background: var(--surface); border: 1px solid var(--border);
            border-radius: var(--radius);
            box-shadow: 0 8px 32px rgba(0,0,0,.12);
            opacity: 0; pointer-events: none; transform: translateY(-8px);
            transition: opacity var(--transition), transform var(--transition);
            z-index: 999;
        }
        .notification-wrap.open .notification-dropdown { opacity: 1; pointer-events: auto; transform: translateY(0); }
        .notification-head {
            display:flex; align-items:center; justify-content:space-between;
            padding: 12px 14px; border-bottom:1px solid var(--border);
        }
        .notification-head strong { font-size: .88rem; }
        .notification-head span { font-size: .72rem; color: var(--text-muted); font-weight: 700; }
        .notification-list { max-height: 320px; overflow: auto; }
        .notification-item { padding: 12px 14px; border-bottom: 1px solid var(--border); }
        .notification-item:last-child { border-bottom: none; }
        .notification-item-title { font-size: .8rem; font-weight: 700; color: var(--text); margin-bottom: 3px; }
        .notification-item-msg { font-size: .76rem; color: var(--text-muted); line-height: 1.45; margin-bottom: 5px; }
        .notification-item-time { font-size: .7rem; color: #94a3b8; }
        .notification-empty { padding: 18px 14px; font-size: .8rem; color: var(--text-muted); text-align: center; }

        .profile-wrap { position: relative; }
        .profile-btn {
            display: flex; align-items: center; gap: 10px;
            background: none; border: 1px solid var(--border);
            border-radius: 40px; padding: 5px 14px 5px 5px;
            cursor: pointer; color: var(--text); transition: var(--transition);
        }
        .profile-btn:hover { background: var(--surface-2); border-color: var(--accent); }
        .profile-avatar {
            width: 34px; height: 34px; border-radius: 50%;
            background: var(--accent); color: #fff;
            display: flex; align-items: center; justify-content: center;
            font-size: .85rem; font-weight: 700;
        }
        .profile-name {
            font-size: .84rem; font-weight: 600;
            max-width: 110px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
        }
        .profile-chevron { font-size: .65rem; color: var(--text-muted); transition: transform var(--transition); }
        .profile-wrap.open .profile-chevron { transform: rotate(180deg); }

        .profile-dropdown {
            position: absolute; top: calc(100% + 10px); right: 0;
            background: var(--surface); border: 1px solid var(--border);
            border-radius: var(--radius); min-width: 230px;
            box-shadow: 0 8px 32px rgba(0,0,0,.12);
            opacity: 0; pointer-events: none; transform: translateY(-8px);
            transition: opacity var(--transition), transform var(--transition);
            z-index: 999;
        }
        .profile-wrap.open .profile-dropdown { opacity: 1; pointer-events: auto; transform: translateY(0); }

        .dropdown-header { padding: 16px; border-bottom: 1px solid var(--border); }
        .dropdown-header .d-name { font-weight: 700; font-size: .9rem; }
        .dropdown-header .d-id { font-size: .75rem; color: var(--text-muted); margin-top: 1px; }
        .dropdown-header .d-badge {
            display: inline-block; margin-top: 6px;
            font-size: .62rem; font-weight: 700; text-transform: uppercase;
            letter-spacing: 1px; padding: 3px 9px; border-radius: 20px;
            background: var(--accent-light); color: var(--accent);
        }
        .dropdown-menu-list { padding: 8px; }
        .dropdown-item {
            display: flex; align-items: center; gap: 10px;
            padding: 10px 12px; border-radius: 8px; text-decoration: none;
            font-size: .84rem; color: var(--text); transition: var(--transition);
        }
        .dropdown-item:hover { background: var(--surface-2); }
        .dropdown-item i { width: 16px; text-align: center; color: var(--text-muted); }
        .dropdown-item.danger { color: var(--red); }
        .dropdown-item.danger i { color: var(--red); }
        .dropdown-divider { height: 1px; background: var(--border); margin: 4px 8px; }

        .sidebar {
            position: fixed; top: var(--topbar-h); left: 0; bottom: 0;
            width: var(--sidebar-w); background: var(--surface);
            border-right: 1px solid var(--border);
            display: flex; flex-direction: column;
            transition: transform var(--transition);
            z-index: 100;
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
            transition: var(--transition);
        }
        .nav-item i { width: 18px; text-align: center; }
        .nav-item:hover { background: var(--surface-2); color: var(--text); }
        .nav-item.active { background: var(--accent-light); color: var(--accent); font-weight: 600; }

        .sidebar-footer { padding: 12px; border-top: 1px solid var(--border); }

        .main-content {
            margin-top: var(--topbar-h);
            margin-left: var(--sidebar-w);
            min-height: calc(100vh - var(--topbar-h));
            padding: 28px 28px 40px;
            transition: margin-left var(--transition);
        }
        .main-content.expanded { margin-left: 0; }

        .page-heading {
            font-family: 'Space Grotesk', sans-serif;
            font-size: 1.3rem; font-weight: 700; margin-bottom: 4px;
        }
        .page-sub { color: var(--text-muted); font-size: .86rem; margin-bottom: 20px; }

        .info-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 20px 22px;
            margin-bottom: 16px;
        }
        .info-card h3 {
            font-family: 'Space Grotesk', sans-serif;
            font-size: 1rem; font-weight: 700; margin-bottom: 14px;
        }
        .info-row {
            display: flex; align-items: flex-start; gap: 10px;
            padding: 10px 0; border-bottom: 1px solid var(--border);
            font-size: .86rem;
        }
        .info-row:last-child { border-bottom: none; padding-bottom: 0; }
        .info-row i { color: var(--accent); width: 16px; text-align: center; margin-top: 3px; }
        .info-key { color: var(--text-muted); min-width: 115px; }
        .info-val { font-weight: 600; }

        .attendance-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 18px 20px;
            margin-bottom: 16px;
        }
        .attendance-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            margin-bottom: 8px;
        }
        .attendance-head h3 {
            font-family: 'Space Grotesk', sans-serif;
            font-size: 1rem;
            font-weight: 700;
        }
        .attendance-percent {
            font-size: 1.05rem;
            font-weight: 800;
            color: var(--accent);
        }
        .attendance-track {
            width: 100%;
            height: 10px;
            border-radius: 999px;
            background: #e5e7eb;
            overflow: hidden;
        }
        .attendance-fill {
            height: 100%;
            border-radius: 999px;
            background: var(--accent);
        }
        .attendance-meta {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-top: 10px;
        }
        .attendance-chip {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 10px;
            border-radius: 999px;
            font-size: .74rem;
            font-weight: 700;
        }
        .attendance-chip.present { background: var(--accent-light); color: var(--accent); }
        .attendance-chip.absent { background: #fff1f2; color: var(--red); }
        .attendance-chip.total { background: #f1f5f9; color: #334155; }

        .quick-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 12px;
            margin-bottom: 16px;
        }
        .quick-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 14px 16px;
        }
        .quick-card .lbl {
            font-size: .74rem;
            text-transform: uppercase;
            letter-spacing: .7px;
            color: var(--text-muted);
            margin-bottom: 6px;
        }
        .quick-card .val {
            font-family: 'Space Grotesk', sans-serif;
            font-size: 1.1rem;
            line-height: 1.2;
            font-weight: 700;
            color: #1e293b;
        }

        .table-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            overflow: hidden;
        }
        .table-wrap { overflow-x: auto; }
        .table {
            width: 100%; border-collapse: collapse; min-width: 760px;
        }
        .table th, .table td {
            padding: 12px 14px;
            text-align: left;
            border-bottom: 1px solid var(--border);
            font-size: .82rem;
            white-space: nowrap;
        }
        .table th {
            background: var(--surface-2);
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: .8px;
            font-size: .72rem;
        }
        .table tbody tr:last-child td { border-bottom: none; }
        .status-pill {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 20px;
            font-size: .68rem;
            font-weight: 700;
            letter-spacing: .5px;
            text-transform: uppercase;
            background: var(--accent-light);
            color: var(--accent);
        }

        .fee-summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 12px;
            margin-bottom: 14px;
        }
        .fee-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 16px;
        }
        .fee-card .lbl {
            font-size: .74rem;
            text-transform: uppercase;
            letter-spacing: .7px;
            color: var(--text-muted);
            margin-bottom: 6px;
        }
        .fee-card .val {
            font-family: 'Space Grotesk', sans-serif;
            font-size: 1.35rem;
            line-height: 1.1;
            font-weight: 700;
        }
        .fee-card.total .val { color: #1e293b; }
        .fee-card.paid .val { color: var(--accent); }
        .fee-card.pending .val { color: #b45309; }

        .btn-receipt {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            border: 1px solid var(--border);
            background: var(--surface);
            color: var(--text);
            text-decoration: none;
            border-radius: 8px;
            padding: 7px 10px;
            font-size: .76rem;
            font-weight: 700;
            transition: var(--transition);
        }
        .btn-receipt:hover {
            border-color: var(--accent);
            color: var(--accent);
            background: var(--accent-light);
        }

        .pay-fee-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 16px;
            margin-bottom: 14px;
        }
        .pay-fee-title {
            font-family: 'Space Grotesk', sans-serif;
            font-size: 1rem;
            margin-bottom: 6px;
        }
        .pay-fee-sub {
            font-size: .82rem;
            color: var(--text-muted);
            margin-bottom: 12px;
        }
        .pay-fee-form {
            display: flex;
            align-items: end;
            gap: 10px;
            flex-wrap: wrap;
        }
        .pay-fee-group {
            min-width: 220px;
            flex: 1;
        }
        .pay-fee-group label {
            display: block;
            font-size: .74rem;
            font-weight: 700;
            margin-bottom: 5px;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: .6px;
        }
        .pay-fee-group input {
            width: 100%;
            padding: 10px 12px;
            border-radius: 10px;
            border: 1px solid var(--border);
            background: var(--surface-2);
            outline: none;
            font-size: .88rem;
            font-family: 'DM Sans', sans-serif;
        }
        .pay-fee-group input:focus {
            border-color: var(--accent);
            box-shadow: 0 0 0 3px #0b8a5e12;
        }
        .btn-pay-fee {
            border: none;
            background: var(--accent);
            color: #fff;
            border-radius: 10px;
            padding: 10px 16px;
            font-size: .84rem;
            font-weight: 700;
            cursor: pointer;
            transition: var(--transition);
        }
        .btn-pay-fee:disabled {
            opacity: .65;
            cursor: not-allowed;
        }
        .pay-fee-msg {
            margin-top: 10px;
            font-size: .8rem;
            border-radius: 8px;
            padding: 9px 10px;
            display: none;
        }
        .pay-fee-msg.show { display: block; }
        .pay-fee-msg.error { background: #fff1f2; color: #be123c; border: 1px solid #fecdd3; }
        .pay-fee-msg.success { background: #ecfdf3; color: #166534; border: 1px solid #86efac; }

        .ticket-form-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 16px;
            margin-bottom: 14px;
        }
        .ticket-form-card h3 {
            font-family: 'Space Grotesk', sans-serif;
            font-size: 1rem;
            margin-bottom: 4px;
        }
        .ticket-form-sub {
            font-size: .8rem;
            color: var(--text-muted);
            margin-bottom: 12px;
        }
        .ticket-form-grid {
            display: grid;
            gap: 10px;
        }
        .ticket-field label {
            display: block;
            font-size: .74rem;
            font-weight: 700;
            margin-bottom: 5px;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: .6px;
        }
        .ticket-field input,
        .ticket-field textarea {
            width: 100%;
            border: 1px solid var(--border);
            border-radius: 10px;
            background: var(--surface-2);
            padding: 10px 12px;
            font-family: 'DM Sans', sans-serif;
            font-size: .86rem;
            color: var(--text);
            outline: none;
        }
        .ticket-field textarea {
            min-height: 120px;
            resize: vertical;
        }
        .ticket-field input:focus,
        .ticket-field textarea:focus {
            border-color: var(--accent);
            box-shadow: 0 0 0 3px #0b8a5e12;
            background: var(--surface);
        }
        .ticket-actions {
            display: flex;
            justify-content: flex-end;
        }
        .ticket-submit-btn {
            border: none;
            background: var(--accent);
            color: #fff;
            border-radius: 10px;
            padding: 10px 16px;
            font-size: .84rem;
            font-weight: 700;
            cursor: pointer;
        }
        .ticket-submit-btn:disabled {
            opacity: .65;
            cursor: not-allowed;
        }
        .ticket-msg {
            display: none;
            margin-top: 10px;
            padding: 10px 12px;
            border-radius: 10px;
            font-size: .8rem;
        }
        .ticket-msg.show { display: block; }
        .ticket-msg.success { background: #ecfdf3; border: 1px solid #86efac; color: #166534; }
        .ticket-msg.error { background: #fff1f2; border: 1px solid #fecdd3; color: #be123c; }
        .ticket-history {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            overflow: hidden;
        }
        .ticket-history-head {
            padding: 12px 14px;
            border-bottom: 1px solid var(--border);
            font-size: .82rem;
            font-weight: 700;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: .7px;
        }
        .ticket-list {
            display: flex;
            flex-direction: column;
        }
        .ticket-item {
            padding: 12px 14px;
            border-bottom: 1px solid var(--border);
        }
        .ticket-item:last-child {
            border-bottom: none;
        }
        .ticket-item-top {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 10px;
            margin-bottom: 4px;
        }
        .ticket-item-title {
            font-size: .88rem;
            font-weight: 700;
        }
        .ticket-item-msg {
            font-size: .8rem;
            color: var(--text-muted);
            white-space: pre-line;
            margin-bottom: 6px;
        }
        .ticket-item-time {
            font-size: .72rem;
            color: #94a3b8;
        }
        .ticket-status {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 4px 8px;
            border-radius: 999px;
            font-size: .66rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .5px;
            border: 1px solid transparent;
            white-space: nowrap;
        }
        .ticket-status.open { background: #ffedd5; border-color: #fdba74; color: #9a3412; }
        .ticket-status.in-progress { background: #dbeafe; border-color: #93c5fd; color: #1d4ed8; }
        .ticket-status.resolved { background: #dcfce7; border-color: #86efac; color: #166534; }

        .empty-box {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 26px;
            color: var(--text-muted);
            text-align: center;
            font-size: .86rem;
        }

        .sidebar-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,.3);
            z-index: 99;
        }
        .sidebar-overlay.visible { display: block; }

        @media (max-width: 700px) {
            .sidebar { transform: translateX(calc(-1 * var(--sidebar-w))); }
            .sidebar.open { transform: translateX(0); }
            .main-content { margin-left: 0; }
            .profile-name { display: none; }
            .topbar-brand span { display: none; }
            .notification-btn { width: 34px; height: 34px; border-radius: 10px; }
            /* Keep notification dropdown fully inside viewport on mobile */
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
            .cal-grid { gap: 4px; }
            .cal-day { min-height: 36px; font-size: .78rem; }
        }

        /* ── Schedule / Calendar ── */
        .cal-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 20px 22px;
            margin-bottom: 16px;
        }
        .cal-nav {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 16px;
        }
        .cal-nav-btn {
            background: var(--surface-2);
            border: 1px solid var(--border);
            border-radius: 8px;
            width: 34px; height: 34px;
            display: flex; align-items: center; justify-content: center;
            cursor: pointer;
            color: var(--text-muted);
            font-size: .85rem;
            transition: var(--transition);
        }
        .cal-nav-btn:hover { background: var(--accent-light); color: var(--accent); border-color: var(--accent); }
        .cal-month-label {
            font-family: 'Space Grotesk', sans-serif;
            font-size: 1.05rem;
            font-weight: 700;
        }
        .cal-weekdays {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 6px;
            margin-bottom: 6px;
        }
        .cal-wd {
            text-align: center;
            font-size: .7rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .8px;
            color: var(--text-muted);
            padding: 4px 0;
        }
        .cal-grid {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 6px;
        }
        .cal-day {
            min-height: 44px;
            border-radius: 10px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            font-size: .84rem;
            font-weight: 500;
            color: var(--text-muted);
            cursor: default;
            position: relative;
            transition: var(--transition);
        }
        .cal-day.other-month { color: #d1d5db; }
        .cal-day.today {
            background: var(--surface-2);
            color: var(--text);
            font-weight: 700;
        }
        .cal-day.has-session-class {
            background: #dbeafe;
            color: #1d4ed8;
            font-weight: 700;
            cursor: pointer;
        }
        .cal-day.has-session-class:hover { background: #bfdbfe; }
        .cal-day.has-session-class::after {
            content: '';
            width: 5px; height: 5px;
            border-radius: 50%;
            background: #2563eb;
            position: absolute;
            bottom: 6px;
        }
        .cal-day.today.has-session-class {
            background: #1d4ed8;
            color: #fff;
        }
        .cal-day.today.has-session-class::after { background: #bfdbfe; }
        
        .cal-day.has-session-iv {
            background: #ffe4e6;
            color: #be123c;
            font-weight: 700;
            cursor: pointer;
        }
        .cal-day.has-session-iv:hover { background: #fecdd3; }
        .cal-day.has-session-iv::after {
            content: '';
            width: 5px; height: 5px;
            border-radius: 50%;
            background: #e11d48;
            position: absolute;
            bottom: 6px;
        }
        .cal-day.today.has-session-iv {
            background: #be123c;
            color: #fff;
        }
        .cal-day.today.has-session-iv::after { background: #fecdd3; }
        .cal-session-panel {
            margin-top: 14px;
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            border-radius: 10px;
            padding: 14px 16px;
            display: none;
        }
        .cal-session-panel.show { display: block; }
        .cal-session-date {
            font-family: 'Space Grotesk', sans-serif;
            font-size: .88rem;
            font-weight: 700;
            color: #1d4ed8;
            margin-bottom: 6px;
        }
        .cal-session-details {
            font-size: .83rem;
            color: #1e3a8a;
            line-height: 1.5;
            white-space: pre-wrap;
        }
        .cal-legend {
            display: flex;
            align-items: center;
            gap: 16px;
            margin-top: 12px;
            font-size: .75rem;
            color: var(--text-muted);
        }
        .cal-legend-item {
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .cal-legend-dot {
            width: 12px; height: 12px;
            border-radius: 4px;
        }
        .cal-legend-dot.session-class { background: #dbeafe; border: 1px solid #93c5fd; }
        .cal-legend-dot.session-iv { background: #ffe4e6; border: 1px solid #fda4af; }
        .cal-legend-dot.today-dot { background: var(--surface-2); border: 1px solid var(--border); }
        .cal-no-sessions {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 26px;
            color: var(--text-muted);
            text-align: center;
            font-size: .86rem;
        }

        /* ── RESPONSIVE ADDITIONS ── */
        @media (max-width: 700px) {
            .sidebar { transform: translateX(calc(-1 * var(--sidebar-w))); }
            .sidebar.open { transform: translateX(0); }
            .main-content { margin-left: 0; padding: 16px 12px 30px; }
            .topbar { padding: 0 12px; }
            .profile-name { display: none; }
            .topbar-brand span { display: none; }
            .notification-btn { width: 34px; height: 34px; border-radius: 10px; }
            .notification-dropdown { position: fixed; top: var(--topbar-h); left: 8px; right: 8px; width: auto; max-width: none; }
            .notification-wrap.open .notification-dropdown { transform: translateY(0); }
            .cal-grid { gap: 4px; }
            .cal-day { min-height: 36px; font-size: .78rem; }
            .page-heading { font-size: 1.18rem; }
        }
        @media (max-width: 640px) {
            .overview-stats { grid-template-columns: repeat(2, 1fr); gap: 8px; }
            .fees-table th, .fees-table td { padding: 8px 6px; font-size: .78rem; }
            .ticket-card { padding: 12px; }
            .ticket-list-item-meta { flex-wrap: wrap; }
            .section-heading { font-size: 1rem; }
        }
        @media (max-width: 480px) {
            .overview-stats { grid-template-columns: 1fr 1fr; gap: 6px; }
            .stat-value { font-size: 1.5rem; }
            .cal-card { padding: 14px 10px; }
            .cal-day { min-height: 30px; font-size: .7rem; }
            .page-heading { font-size: 1.05rem; }
            .page-sub { font-size: .8rem; }
        }
    </style>
</head>
<body>
    <header class="topbar">
        <button class="sidebar-toggle" id="sidebarToggle" aria-label="Toggle navigation">
            <i class="fa-solid fa-bars"></i>
        </button>

        <a href="#" class="topbar-brand">
            <img src="assets/logo.png" alt="Logo" style="height: 28px; width: auto; object-fit: contain;">
            3D <span>Shikshan</span>
        </a>

        <div class="topbar-spacer"></div>

        <div class="topbar-actions">
            <div class="notification-wrap" id="notificationWrap">
                <button type="button" class="notification-btn" id="notificationBtn" aria-label="Notifications">
                    <i class="fa-regular fa-bell"></i>
                    <span class="notification-badge"><?php echo (int)$unreadNotificationCount; ?></span>
                </button>

                <div class="notification-dropdown" id="notificationDropdown">
                    <div class="notification-head">
                        <strong>Notifications</strong>
                        <span><?php echo count($studentNotifications); ?> total</span>
                    </div>
                    <div class="notification-list">
                        <?php if (!empty($studentNotifications)): ?>
                            <?php foreach ($studentNotifications as $notification): ?>
                                <div class="notification-item">
                                    <div class="notification-item-title"><?php echo esc((string)$notification['title']); ?></div>
                                    <div class="notification-item-msg"><?php echo esc((string)$notification['message']); ?></div>
                                    <div class="notification-item-time"><?php echo esc((string)$notification['created_at']); ?></div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="notification-empty">No notifications yet.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="profile-wrap" id="profileWrap">
            <button class="profile-btn" id="profileBtn" aria-label="Open profile menu">
                <div class="profile-avatar"><?php echo esc($initial); ?></div>
                <span class="profile-name"><?php echo $displayName; ?></span>
                <i class="fa-solid fa-chevron-down profile-chevron"></i>
            </button>

            <div class="profile-dropdown" role="menu">
                <div class="dropdown-header">
                    <div class="d-name"><?php echo $displayName; ?></div>
                    <div class="d-id"><?php echo $displayLoginId; ?></div>
                    <div class="d-badge">Student</div>
                </div>
                <div class="dropdown-menu-list">
                    <a href="#" class="dropdown-item" onclick="showSection('profile'); closeProfile();">
                        <i class="fa-solid fa-user"></i> My Profile
                    </a>
                    <a href="#" class="dropdown-item" onclick="showSection('attendance'); closeProfile();">
                        <i class="fa-solid fa-clipboard-check"></i> Attendance
                    </a>
                    <a href="#" class="dropdown-item" onclick="showSection('schedule'); closeProfile();">
                        <i class="fa-solid fa-calendar-days"></i> Schedule
                    </a>
                    <a href="#" class="dropdown-item" onclick="showSection('fees'); closeProfile();">
                        <i class="fa-solid fa-indian-rupee-sign"></i> Fees
                    </a>
                    <a href="#" class="dropdown-item" onclick="showSection('tickets'); closeProfile();">
                        <i class="fa-solid fa-life-ring"></i> Raise Ticket
                    </a>
                    <a href="#" class="dropdown-item" onclick="showSection('upload'); closeProfile();">
                        <i class="fa-solid fa-upload"></i> Upload
                    </a>
                    <a href="#" class="dropdown-item" onclick="showSection('download'); closeProfile();">
                        <i class="fa-solid fa-download"></i> Download
                    </a>
                    <div class="dropdown-divider"></div>
                    <a href="logout.php" class="dropdown-item danger">
                        <i class="fa-solid fa-right-from-bracket"></i> Logout
                    </a>
                </div>
            </div>
        </div>
    </header>

    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <aside class="sidebar" id="sidebar">
        <div class="sidebar-section">
            <div class="sidebar-label">Student Panel</div>
            <a class="nav-item active" href="#" onclick="showSection('overview')">
                <i class="fa-solid fa-house"></i> Overview
            </a>
            <a class="nav-item" href="#" onclick="showSection('attendance')">
                <i class="fa-solid fa-clipboard-check"></i> Attendance
            </a>
            <a class="nav-item" href="#" onclick="showSection('schedule')">
                <i class="fa-solid fa-calendar-days"></i> Schedule
            </a>
            <a class="nav-item" href="#" onclick="showSection('fees')">
                <i class="fa-solid fa-indian-rupee-sign"></i> Fees
            </a>
            <a class="nav-item" href="#" onclick="showSection('tickets')">
                <i class="fa-solid fa-life-ring"></i> Raise Ticket
            </a>
            <a class="nav-item" href="#" onclick="showSection('upload')">
                <i class="fa-solid fa-upload"></i> Upload
            </a>
            <a class="nav-item" href="#" onclick="showSection('download')">
                <i class="fa-solid fa-download"></i> Download
            </a>
        </div>
        <div class="sidebar-footer">
            <a href="logout.php" class="nav-item" style="color:#d9435f;">
                <i class="fa-solid fa-right-from-bracket" style="color:#d9435f;"></i> Logout
            </a>
        </div>
    </aside>

    <main class="main-content" id="mainContent">
        <div id="section-overview">
            <div class="page-heading">Student Dashboard</div>
            <p class="page-sub">Welcome back, <?php echo $displayName; ?>. Track your attendance and learning progress.</p>

            <?php if ($studentProfile): ?>
                <div class="attendance-card" onclick="showSection('attendance')" style="cursor: pointer;">
                    <div class="attendance-head">
                        <h3>Attendance Progress</h3>
                        <span class="attendance-percent"><?php echo (int)$attendancePercent; ?>%</span>
                    </div>
                    <div class="attendance-track">
                        <div class="attendance-fill" style="width: <?php echo (int)$attendancePercent; ?>%;"></div>
                    </div>
                    <div class="attendance-meta">
                        <span class="attendance-chip present">Present: <?php echo (int)$attendancePresent; ?></span>
                        <span class="attendance-chip absent">Absent: <?php echo (int)$attendanceAbsent; ?></span>
                        <span class="attendance-chip total">Marked: <?php echo (int)$attendanceMarked; ?></span>
                    </div>
                </div>

                <div class="quick-grid">
                    <div class="quick-card" onclick="showSection('schedule')" style="cursor: pointer;">
                        <div class="lbl">Scheduled Sessions</div>
                        <div class="val"><?php echo (int)count($scheduleSessions); ?></div>
                    </div>
                    <div class="quick-card" onclick="showSection('schedule')" style="cursor: pointer;">
                        <div class="lbl">Next Session Date</div>
                        <div class="val"><?php echo $nextScheduledSessionDate !== '' ? esc($nextScheduledSessionDate) : 'Not scheduled'; ?></div>
                    </div>
                    <div class="quick-card" onclick="showSection('fees')" style="cursor: pointer;">
                        <div class="lbl">Pending Fees</div>
                        <div class="val">₹<?php echo esc(number_format($pendingFees, 2)); ?></div>
                    </div>
                </div>
            <?php else: ?>
                <div class="empty-box">Student profile details not found.</div>
            <?php endif; ?>
        </div>

        <div id="section-upload" style="display:none;">
            <div class="page-heading">Upload</div>
            <p class="page-sub">Upload your files and documents here.</p>
            <div class="empty-box">Upload functionality coming soon.</div>
        </div>

        <div id="section-download" style="display:none;">
            <div class="page-heading">Download</div>
            <p class="page-sub">Download resources and files here.</p>
            <div class="empty-box">Download functionality coming soon.</div>
        </div>

        <div id="section-profile" style="display:none;">
            <div class="page-heading">My Profile</div>
            <p class="page-sub">Your personal and academic information.</p>

            <?php if ($studentProfile): ?>
                <div class="info-card">
                    <h3>Profile Details</h3>
                    <div class="info-row">
                        <i class="fa-solid fa-user"></i>
                        <span class="info-key">Full Name</span>
                        <span class="info-val"><?php echo esc(trim((string)$studentProfile['first_name'] . ' ' . (string)$studentProfile['middle_name'] . ' ' . (string)$studentProfile['last_name'])); ?></span>
                    </div>
                    <div class="info-row">
                        <i class="fa-solid fa-envelope"></i>
                        <span class="info-key">Email</span>
                        <span class="info-val"><?php echo esc((string)$studentProfile['email']); ?></span>
                    </div>
                    <div class="info-row">
                        <i class="fa-solid fa-phone"></i>
                        <span class="info-key">Mobile</span>
                        <span class="info-val"><?php echo esc((string)$studentProfile['mobile_no']); ?></span>
                    </div>
                    <div class="info-row">
                        <i class="fa-solid fa-shield-halved"></i>
                        <span class="info-key">Role</span>
                        <span class="info-val"><?php echo $displayRole; ?></span>
                    </div>
                </div>

                <div class="info-card">
                    <h3>Academic Details</h3>
                    <div class="info-row">
                        <i class="fa-solid fa-building-columns"></i>
                        <span class="info-key">College</span>
                        <span class="info-val"><?php echo esc((string)($studentProfile['college_name'] ?? '')); ?></span>
                    </div>
                    <div class="info-row">
                        <i class="fa-solid fa-location-dot"></i>
                        <span class="info-key">Location</span>
                        <span class="info-val"><?php echo esc((string)$studentProfile['city'] . ', ' . (string)$studentProfile['district'] . ', ' . (string)$studentProfile['state']); ?></span>
                    </div>
                    <div class="info-row">
                        <i class="fa-solid fa-book"></i>
                        <span class="info-key">Course</span>
                        <span class="info-val"><?php echo esc((string)($studentProfile['course_name'] ?? '')); ?></span>
                    </div>
                    <div class="info-row">
                        <i class="fa-solid fa-clock"></i>
                        <span class="info-key">Duration</span>
                        <span class="info-val"><?php echo esc((string)($studentProfile['duration'] ?? '')); ?></span>
                    </div>
                    <div class="info-row">
                        <i class="fa-solid fa-indian-rupee-sign"></i>
                        <span class="info-key">Course Fees</span>
                        <span class="info-val">₹<?php echo esc((string)($studentProfile['fees'] ?? '0')); ?></span>
                    </div>
                    <div class="info-row">
                        <i class="fa-solid fa-circle-info"></i>
                        <span class="info-key">Required Details</span>
                        <span class="info-val"><?php echo esc((string)($studentProfile['required_details'] ?? '')); ?></span>
                    </div>
                    <div class="info-row">
                        <i class="fa-solid fa-file-lines"></i>
                        <span class="info-key">Description</span>
                        <span class="info-val"><?php echo esc((string)($studentProfile['description'] ?? '')); ?></span>
                    </div>
                </div>
            <?php else: ?>
                <div class="empty-box">Student profile details not found.</div>
            <?php endif; ?>
        </div>

        <div id="section-attendance" style="display:none;">
            <div class="page-heading">Attendance</div>
            <p class="page-sub">Overall attendance summary.</p>

            <?php if ($studentProfile): ?>
                <div class="attendance-card">
                    <div class="attendance-head">
                        <h3>Attendance Progress</h3>
                        <span class="attendance-percent"><?php echo (int)$attendancePercent; ?>%</span>
                    </div>
                    <div class="attendance-track">
                        <div class="attendance-fill" style="width: <?php echo (int)$attendancePercent; ?>%;"></div>
                    </div>
                </div>

                <div class="quick-grid">
                    <div class="quick-card">
                        <div class="lbl">Present</div>
                        <div class="val"><?php echo (int)$attendancePresent; ?></div>
                    </div>
                    <div class="quick-card">
                        <div class="lbl">Absent</div>
                        <div class="val"><?php echo (int)$attendanceAbsent; ?></div>
                    </div>
                    <div class="quick-card">
                        <div class="lbl">All Marked</div>
                        <div class="val"><?php echo (int)$attendanceMarked; ?></div>
                    </div>
                    <div class="quick-card">
                        <div class="lbl">Overall %</div>
                        <div class="val"><?php echo (int)$attendancePercent; ?>%</div>
                    </div>
                </div>
            <?php else: ?>
                <div class="empty-box">Student profile details not found.</div>
            <?php endif; ?>
        </div>

        <div id="section-schedule" style="display:none;">
            <div class="page-heading">My Schedule</div>
            <p class="page-sub">Activities scheduled by your coordinator are highlighted in blue.</p>

            <?php if ($studentProfile): ?>
                <div class="cal-card">
                    <div class="cal-nav">
                        <button class="cal-nav-btn" id="calPrev" aria-label="Previous month"><i class="fa-solid fa-chevron-left"></i></button>
                        <span class="cal-month-label" id="calMonthLabel"></span>
                        <button class="cal-nav-btn" id="calNext" aria-label="Next month"><i class="fa-solid fa-chevron-right"></i></button>
                    </div>
                    <div class="cal-weekdays">
                        <div class="cal-wd">Sun</div>
                        <div class="cal-wd">Mon</div>
                        <div class="cal-wd">Tue</div>
                        <div class="cal-wd">Wed</div>
                        <div class="cal-wd">Thu</div>
                        <div class="cal-wd">Fri</div>
                        <div class="cal-wd">Sat</div>
                    </div>
                    <div class="cal-grid" id="calGrid"></div>
                    <div class="cal-session-panel" id="calSessionPanel">
                        <div class="cal-session-date" id="calSessionDate"></div>
                        <div class="cal-session-type" id="calSessionType" style="font-weight: 600; margin-bottom: 4px; color: #475569; font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.5px;"></div>
                        <div class="cal-session-details" id="calSessionDetails"></div>
                        <div class="cal-session-notes" id="calSessionNotes" style="font-style: italic; margin-top: 8px; color: #64748b; font-size: 0.8rem;"></div>
                    </div>
                    <div class="cal-legend">
                        <div class="cal-legend-item"><div class="cal-legend-dot session-class"></div> Class</div>
                        <div class="cal-legend-item"><div class="cal-legend-dot session-iv"></div> Industrial Visit</div>
                        <div class="cal-legend-item"><div class="cal-legend-dot today-dot"></div> Today</div>
                    </div>
                </div>
            <?php else: ?>
                <div class="cal-no-sessions">Student profile not found. Cannot load schedule.</div>
            <?php endif; ?>
        </div>

        <div id="section-fees" style="display:none;">
            <div class="page-heading">Fees</div>
            <p class="page-sub">Your paid fee records are listed below.</p>

            <div class="fee-summary-grid">
                <div class="fee-card total">
                    <div class="lbl">Total Course Fees</div>
                    <div class="val">₹<?php echo esc(number_format($courseTotalFees, 2)); ?></div>
                </div>
                <div class="fee-card paid">
                    <div class="lbl">Total Paid</div>
                    <div class="val">₹<?php echo esc(number_format($totalPaidFees, 2)); ?></div>
                </div>
                <div class="fee-card pending">
                    <div class="lbl">Pending Fees</div>
                    <div class="val">₹<?php echo esc(number_format($pendingFees, 2)); ?></div>
                </div>
            </div>

            <div class="pay-fee-card">
                <div class="pay-fee-title">Pay Remaining Fees</div>
                <div class="pay-fee-sub">Remaining fees: ₹<?php echo esc(number_format($pendingFees, 2)); ?></div>
                <form id="payFeeForm" class="pay-fee-form" data-remaining="<?php echo esc(number_format($pendingFees, 2, '.', '')); ?>">
                    <div class="pay-fee-group">
                        <label for="fee_amount_to_pay">Enter Amount to Pay (₹)</label>
                        <input type="number" id="fee_amount_to_pay" min="1" step="0.01" placeholder="Enter amount" <?php echo $pendingFees > 0 ? '' : 'disabled'; ?> required>
                    </div>
                    <?php if ($razorpayEnabled && $pendingFees > 0): ?>
                        <?php
                        $paymentConsentCheckboxId = 'feePaymentTermsAccept';
                        require __DIR__ . '/includes/payment_consent.php';
                        ?>
                    <?php endif; ?>
                    <button type="submit" id="payFeeBtn" class="btn-pay-fee" <?php echo ($pendingFees > 0 && $razorpayEnabled) ? '' : 'disabled'; ?>>Pay Fees</button>
                </form>
                <div class="payment-legal-footer">
                    <a href="terms.php">Terms</a> ·
                    <a href="privacy.php">Privacy</a> ·
                    <a href="refund.php">Refunds</a>
                </div>
                <div id="payFeeMsg" class="pay-fee-msg"></div>
                <?php if (!$razorpayEnabled): ?>
                    <div class="pay-fee-msg show error">Razorpay is not configured. Please contact admin.</div>
                <?php endif; ?>
            </div>

            <?php if (!empty($paymentRows)): ?>
                <div class="table-card">
                    <div class="table-wrap">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Paid On</th>
                                    <th>Amount</th>
                                    <th>Currency</th>
                                    <th>Status</th>
                                    <th>Razorpay Payment ID</th>
                                    <th>Razorpay Order ID</th>
                                    <th>Receipt</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($paymentRows as $payment): ?>
                                    <tr>
                                        <td><?php echo esc((string)$payment['created_at']); ?></td>
                                        <td>₹<?php echo esc(number_format((float)$payment['amount_rupees'], 2)); ?></td>
                                        <td><?php echo esc((string)$payment['currency']); ?></td>
                                        <td><span class="status-pill"><?php echo esc((string)$payment['status']); ?></span></td>
                                        <td><?php echo esc((string)$payment['razorpay_payment_id']); ?></td>
                                        <td><?php echo esc((string)$payment['razorpay_order_id']); ?></td>
                                        <td>
                                            <a class="btn-receipt" href="download_receipt.php?payment_id=<?php echo (int)$payment['id']; ?>">
                                                <i class="fa-solid fa-file-pdf"></i> Download
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php else: ?>
                <div class="empty-box">No paid fees found yet.</div>
            <?php endif; ?>
        </div>

        <div id="section-tickets" style="display:none;">
            <div class="page-heading">Raise Ticket</div>
            <p class="page-sub">Share your issues with your coordinator and track status updates.</p>

            <div class="ticket-form-card">
                <h3>Need Help?</h3>
                <div class="ticket-form-sub">
                    <?php if ($ticketCoordinatorName !== ''): ?>
                        Your ticket will be sent to <?php echo esc($ticketCoordinatorName); ?>.
                    <?php else: ?>
                        Your ticket will be sent to your assigned coordinator.
                    <?php endif; ?>
                </div>

                <form id="studentTicketForm" class="ticket-form-grid">
                    <div class="ticket-field">
                        <label for="ticketSubject">Issue Subject</label>
                        <input type="text" id="ticketSubject" maxlength="180" placeholder="e.g. Unable to attend scheduled session" required>
                    </div>
                    <div class="ticket-field">
                        <label for="ticketMessage">Issue Details</label>
                        <textarea id="ticketMessage" maxlength="2000" placeholder="Describe your issue in detail..." required></textarea>
                    </div>
                    <div class="ticket-actions">
                        <button type="submit" class="ticket-submit-btn" id="ticketSubmitBtn">Raise Ticket</button>
                    </div>
                </form>
                <div class="ticket-msg" id="ticketMsg"></div>
            </div>

            <div class="ticket-history">
                <div class="ticket-history-head">Your Tickets</div>
                <div class="ticket-list" id="studentTicketList">
                    <?php if (!empty($studentTickets)): ?>
                        <?php foreach ($studentTickets as $ticket): ?>
                            <?php
                                $status = (string)($ticket['status'] ?? 'open');
                                $statusClass = $status === 'in_progress' ? 'in-progress' : ($status === 'resolved' ? 'resolved' : 'open');
                                $statusLabel = $status === 'in_progress' ? 'In Progress' : ($status === 'resolved' ? 'Resolved' : 'Open');
                            ?>
                            <div class="ticket-item">
                                <div class="ticket-item-top">
                                    <div class="ticket-item-title"><?php echo esc((string)$ticket['subject']); ?></div>
                                    <span class="ticket-status <?php echo esc($statusClass); ?>"><?php echo esc($statusLabel); ?></span>
                                </div>
                                <div class="ticket-item-msg"><?php echo nl2br(esc((string)$ticket['message'])); ?></div>
                                <div class="ticket-item-time"><?php echo esc((string)$ticket['created_at']); ?></div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-box" id="studentTicketEmptyBox" style="border:none;border-radius:0;padding:18px;">No tickets raised yet.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>

    <script src="https://checkout.razorpay.com/v1/checkout.js"></script>
    <script>
        const sidebar = document.getElementById('sidebar');
        const sidebarOverlay = document.getElementById('sidebarOverlay');
        const mainContent = document.getElementById('mainContent');
        const profileWrap = document.getElementById('profileWrap');
        const notificationWrap = document.getElementById('notificationWrap');
        const notificationBtn = document.getElementById('notificationBtn');
        const studentTicketForm = document.getElementById('studentTicketForm');
        const ticketSubject = document.getElementById('ticketSubject');
        const ticketMessage = document.getElementById('ticketMessage');
        const ticketSubmitBtn = document.getElementById('ticketSubmitBtn');
        const ticketMsg = document.getElementById('ticketMsg');
        const studentTicketList = document.getElementById('studentTicketList');
        const isMobile = () => window.innerWidth <= 700;
        const razorpayEnabled = <?php echo $razorpayEnabled ? 'true' : 'false'; ?>;

        function ticketStatusClass(status) {
            if (status === 'in_progress') {
                return 'in-progress';
            }
            if (status === 'resolved') {
                return 'resolved';
            }
            return 'open';
        }

        function ticketStatusLabel(status) {
            if (status === 'in_progress') {
                return 'In Progress';
            }
            if (status === 'resolved') {
                return 'Resolved';
            }
            return 'Open';
        }

        function showTicketMsg(message, type) {
            if (!ticketMsg) {
                return;
            }
            ticketMsg.textContent = message;
            ticketMsg.className = 'ticket-msg show ' + type;
        }

        function escapeHtml(value) {
            return String(value)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        function prependTicket(ticket) {
            if (!studentTicketList || !ticket) {
                return;
            }

            const emptyBox = document.getElementById('studentTicketEmptyBox');
            if (emptyBox) {
                emptyBox.remove();
            }

            const status = String(ticket.status || 'open');
            const wrapper = document.createElement('div');
            wrapper.className = 'ticket-item';
            wrapper.innerHTML =
                '<div class="ticket-item-top">' +
                    '<div class="ticket-item-title">' + escapeHtml(ticket.subject || '-') + '</div>' +
                    '<span class="ticket-status ' + ticketStatusClass(status) + '">' + ticketStatusLabel(status) + '</span>' +
                '</div>' +
                '<div class="ticket-item-msg">' + escapeHtml(ticket.message || '-').replace(/\n/g, '<br>') + '</div>' +
                '<div class="ticket-item-time">' + escapeHtml(ticket.created_at || '-') + '</div>';
            studentTicketList.prepend(wrapper);
        }

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

        document.getElementById('profileBtn').addEventListener('click', (event) => {
            event.stopPropagation();
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

        function closeProfile() {
            profileWrap.classList.remove('open');
        }

        const sections = document.querySelectorAll('[id^="section-"]');
        const navItems = document.querySelectorAll('.nav-item');

        function showSection(name, updateHash = true) {
            if (updateHash) {
                window.location.hash = name;
            }
            sections.forEach(section => section.style.display = 'none');
            const target = document.getElementById('section-' + name);
            if (target) {
                target.style.display = 'block';
            }

            navItems.forEach(item => {
                item.classList.toggle('active', item.getAttribute('onclick') && item.getAttribute('onclick').includes("'" + name + "'"));
            });

            if (isMobile()) {
                sidebar.classList.remove('open');
                sidebarOverlay.classList.remove('visible');
            }
        }

        const payFeeForm = document.getElementById('payFeeForm');
        const payFeeBtn = document.getElementById('payFeeBtn');
        const feeAmountInput = document.getElementById('fee_amount_to_pay');
        const payFeeMsg = document.getElementById('payFeeMsg');

        function showPayMsg(message, type) {
            payFeeMsg.textContent = message;
            payFeeMsg.className = 'pay-fee-msg show ' + type;
        }

        if (payFeeForm && payFeeBtn && feeAmountInput) {
            const remaining = Number(payFeeForm.dataset.remaining || '0');
            if (remaining > 0) {
                feeAmountInput.max = String(remaining);
                feeAmountInput.value = remaining.toFixed(2);
            }

            const feePaymentTermsEl = document.getElementById('feePaymentTermsAccept');

            payFeeForm.addEventListener('submit', async function (event) {
                event.preventDefault();

                if (!razorpayEnabled) {
                    showPayMsg('Razorpay is not configured by admin.', 'error');
                    return;
                }

                if (feePaymentTermsEl && !feePaymentTermsEl.checked) {
                    showPayMsg('Please accept the Terms & Conditions, Privacy Policy, Refund Policy, and Razorpay terms to continue.', 'error');
                    return;
                }

                const amount = Number(feeAmountInput.value);
                if (!Number.isFinite(amount) || amount <= 0) {
                    showPayMsg('Enter valid amount.', 'error');
                    return;
                }
                if (amount > remaining) {
                    showPayMsg('Amount cannot be greater than remaining fees.', 'error');
                    return;
                }

                payFeeBtn.disabled = true;
                showPayMsg('Creating payment order...', 'success');

                try {
                    const orderRes = await fetch('student_create_fee_order.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ amount_rupees: amount, terms_accepted: true })
                    });
                    const orderData = await orderRes.json();
                    if (!orderData.ok) {
                        throw new Error(orderData.error || 'Unable to create order.');
                    }

                    const rz = new Razorpay({
                        key: orderData.key,
                        amount: orderData.amount,
                        currency: orderData.currency,
                        name: orderData.company || '3D_Shikshan',
                        description: 'Remaining Fees Payment',
                        order_id: orderData.order_id,
                        handler: async function (response) {
                            try {
                                const completeRes = await fetch('student_complete_fee_payment.php', {
                                    method: 'POST',
                                    headers: { 'Content-Type': 'application/json' },
                                    body: JSON.stringify({
                                        razorpay_order_id: response.razorpay_order_id,
                                        razorpay_payment_id: response.razorpay_payment_id,
                                        razorpay_signature: response.razorpay_signature
                                    })
                                });
                                const completeData = await completeRes.json();
                                if (!completeData.ok) {
                                    throw new Error(completeData.error || 'Payment verification failed.');
                                }

                                showPayMsg('Payment successful. Receipt download started.', 'success');
                                if (completeData.receipt_url) {
                                    window.open(completeData.receipt_url, '_blank');
                                }
                                setTimeout(() => window.location.reload(), 1200);
                            } catch (error) {
                                showPayMsg(error.message || 'Unable to complete payment.', 'error');
                                payFeeBtn.disabled = false;
                            }
                        },
                        modal: {
                            ondismiss: function () {
                                payFeeBtn.disabled = false;
                                showPayMsg('Payment cancelled.', 'error');
                            }
                        },
                        theme: { color: '#0b8a5e' },
                        notes: {
                            policy: 'Terms, Privacy & Refund accepted on fee payment'
                        }
                    });

                    rz.open();
                } catch (error) {
                    showPayMsg(error.message || 'Unable to initiate payment.', 'error');
                    payFeeBtn.disabled = false;
                }
            });
        }

        // ── Calendar ──
        const SESSION_DATES = <?php echo json_encode($scheduleSessions, JSON_HEX_TAG | JSON_HEX_AMP); ?>;

        // Build a Set of YYYY-MM-DD → details string for fast lookup
        const SESSION_MAP = {};
        SESSION_DATES.forEach(function(s) { 
            SESSION_MAP[s.date] = { details: s.details, type: s.type, notes: s.notes }; 
        });

        const MONTH_NAMES = ['January','February','March','April','May','June',
                             'July','August','September','October','November','December'];

        const calGrid        = document.getElementById('calGrid');
        const calMonthLabel  = document.getElementById('calMonthLabel');
        const calSessionPanel= document.getElementById('calSessionPanel');
        const calSessionDate = document.getElementById('calSessionDate');
        const calSessionDet  = document.getElementById('calSessionDetails');
        const calSessionType = document.getElementById('calSessionType');
        const calSessionNotes= document.getElementById('calSessionNotes');

        const today = new Date();
        let calYear  = today.getFullYear();
        let calMonth = today.getMonth(); // 0-indexed

        function pad2(n) { return String(n).padStart(2, '0'); }

        function renderCalendar() {
            calGrid.innerHTML = '';
            calSessionPanel.classList.remove('show');

            calMonthLabel.textContent = MONTH_NAMES[calMonth] + ' ' + calYear;

            const firstDay    = new Date(calYear, calMonth, 1).getDay(); // 0=Sun
            const daysInMonth = new Date(calYear, calMonth + 1, 0).getDate();
            const prevDays    = new Date(calYear, calMonth, 0).getDate();

            const todayStr = today.getFullYear() + '-' + pad2(today.getMonth() + 1) + '-' + pad2(today.getDate());

            // Leading blanks from previous month
            for (let i = 0; i < firstDay; i++) {
                const cell = document.createElement('div');
                cell.className = 'cal-day other-month';
                cell.textContent = prevDays - firstDay + 1 + i;
                calGrid.appendChild(cell);
            }

            // Current month days
            for (let d = 1; d <= daysInMonth; d++) {
                const dateStr = calYear + '-' + pad2(calMonth + 1) + '-' + pad2(d);
                const cell = document.createElement('div');
                cell.className = 'cal-day';
                cell.textContent = d;

                if (dateStr === todayStr) cell.classList.add('today');
                if (SESSION_MAP[dateStr] !== undefined) {
                    const sessionData = SESSION_MAP[dateStr];
                    if (sessionData.type === 'Industrial Visit') {
                        cell.classList.add('has-session-iv');
                    } else {
                        cell.classList.add('has-session-class');
                    }
                    cell.addEventListener('click', function() {
                        const friendly = MONTH_NAMES[calMonth] + ' ' + d + ', ' + calYear;
                        calSessionDate.textContent = 'Activity on ' + friendly;
                        calSessionType.textContent = sessionData.type || 'Class';
                        calSessionDet.textContent  = sessionData.details || 'No details provided.';
                        if (sessionData.notes) {
                            calSessionNotes.textContent = 'Notes: ' + sessionData.notes;
                            calSessionNotes.style.display = 'block';
                        } else {
                            calSessionNotes.style.display = 'none';
                        }
                        calSessionPanel.classList.add('show');
                    });
                }

                calGrid.appendChild(cell);
            }

            // Trailing blanks to fill last row
            const totalCells = firstDay + daysInMonth;
            const trailing = totalCells % 7 === 0 ? 0 : 7 - (totalCells % 7);
            for (let i = 1; i <= trailing; i++) {
                const cell = document.createElement('div');
                cell.className = 'cal-day other-month';
                cell.textContent = i;
                calGrid.appendChild(cell);
            }
        }

        if (calGrid) {
            renderCalendar();

            document.getElementById('calPrev').addEventListener('click', function() {
                calMonth--;
                if (calMonth < 0) { calMonth = 11; calYear--; }
                renderCalendar();
            });

            document.getElementById('calNext').addEventListener('click', function() {
                calMonth++;
                if (calMonth > 11) { calMonth = 0; calYear++; }
                renderCalendar();
            });
        }

        if (studentTicketForm && ticketSubmitBtn && ticketSubject && ticketMessage) {
            studentTicketForm.addEventListener('submit', async function(event) {
                event.preventDefault();

                const subject = ticketSubject.value.trim();
                const message = ticketMessage.value.trim();

                if (subject.length < 4) {
                    showTicketMsg('Please enter a valid subject (minimum 4 characters).', 'error');
                    return;
                }
                if (message.length < 10) {
                    showTicketMsg('Please describe your issue in detail (minimum 10 characters).', 'error');
                    return;
                }

                ticketSubmitBtn.disabled = true;
                showTicketMsg('Raising ticket...', 'success');

                try {
                    const response = await fetch('student_raise_ticket.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ subject: subject, message: message })
                    });
                    const data = await response.json();
                    if (!data.ok) {
                        throw new Error(data.error || 'Unable to raise ticket right now.');
                    }

                    prependTicket(data.ticket || {
                        subject: subject,
                        message: message,
                        status: 'open',
                        created_at: new Date().toISOString().slice(0, 19).replace('T', ' ')
                    });
                    studentTicketForm.reset();
                    showTicketMsg(data.message || 'Ticket raised successfully.', 'success');
                } catch (error) {
                    showTicketMsg(error.message || 'Unable to raise ticket right now.', 'error');
                } finally {
                    ticketSubmitBtn.disabled = false;
                }
            });
        }
    </script>
    <script>
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', () => {
                navigator.serviceWorker.register('sw.js').then(reg => {
                    console.log('SW registered!', reg);
                }).catch(err => console.log('SW registration failed', err));
            });
        }
    </script>
</body>
</html>
