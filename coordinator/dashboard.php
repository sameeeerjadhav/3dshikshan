<?php
declare(strict_types=1);

session_start();

$user = $_SESSION['user'] ?? null;
if (!$user) {
    header('Location: ../index.php?login=1');
    exit;
}

if (($user['role'] ?? '') !== 'coordinator') {
    if (($user['role'] ?? '') === 'admin') {
        header('Location: ../admin/dashboard.php');
    } else {
        header('Location: ../dashboard.php');
    }
    exit;
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';

$coordinator = null;
$assignedColleges = [];
$assignedStudents = [];
$assignedStudentsView = [];
$scheduledSessions = [];
$sessionsByCollegeId = [];
$studentsByCollegeId = [];
$coordinatorTickets = [];
$ticketUnreadCount = 0;
$ticketOpenCount = 0;
$ticketResolvedCount = 0;

$conn = getDbConnection();
if ($conn !== null) {
    $profileSql = '
        SELECT id, first_name, second_name, last_name, email, mobile_no, address_line1, address_line2, state, district, pin
        FROM coordinators
        WHERE user_id = ?
        LIMIT 1
    ';
    $profileStmt = $conn->prepare($profileSql);
    if ($profileStmt !== false) {
        $userId = (int)$user['id'];
        $profileStmt->bind_param('i', $userId);
        $profileStmt->execute();
        $profileResult = $profileStmt->get_result();
        $coordinator = $profileResult ? $profileResult->fetch_assoc() : null;
        $profileStmt->close();
    }

    if ($coordinator) {
        $conn->query(
            "CREATE TABLE IF NOT EXISTS coordinator_sessions (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                coordinator_id INT UNSIGNED NOT NULL,
                college_id INT UNSIGNED NOT NULL,
                session_date DATE NOT NULL,
                session_details VARCHAR(2000) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_cs_college_date (college_id, session_date),
                INDEX idx_cs_coordinator_date (coordinator_id, session_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

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

        $collegeSql = '
            SELECT
                c.id,
                c.name,
                c.country,
                c.state,
                c.district,
                COUNT(sp.id) AS student_count
            FROM coordinator_colleges cc
            INNER JOIN colleges c ON c.id = cc.college_id
            LEFT JOIN student_profiles sp ON sp.college_id = c.id
            WHERE cc.coordinator_id = ?
            GROUP BY c.id, c.name, c.country, c.state, c.district
            ORDER BY c.name ASC
        ';
        $collegeStmt = $conn->prepare($collegeSql);
        if ($collegeStmt !== false) {
            $coordinatorId = (int)$coordinator['id'];
            $collegeStmt->bind_param('i', $coordinatorId);
            $collegeStmt->execute();
            $collegeResult = $collegeStmt->get_result();
            if ($collegeResult instanceof mysqli_result) {
                while ($row = $collegeResult->fetch_assoc()) {
                    $assignedColleges[] = $row;
                }
            }
            $collegeStmt->close();
        }

        $scheduleSql = '
            SELECT
                cs.id,
                cs.college_id,
                cs.session_date,
                cs.session_details,
                cs.session_type,
                cs.notes,
                cs.created_at,
                c.name AS college_name
            FROM coordinator_sessions cs
            INNER JOIN coordinator_colleges cc ON cc.college_id = cs.college_id AND cc.coordinator_id = cs.coordinator_id
            LEFT JOIN colleges c ON c.id = cs.college_id
            WHERE cs.coordinator_id = ?
            ORDER BY cs.session_date DESC, cs.id DESC
            LIMIT 50
        ';

        $scheduleStmt = $conn->prepare($scheduleSql);
        if ($scheduleStmt !== false) {
            $coordinatorId = (int)$coordinator['id'];
            $scheduleStmt->bind_param('i', $coordinatorId);
            $scheduleStmt->execute();
            $scheduleResult = $scheduleStmt->get_result();
            if ($scheduleResult instanceof mysqli_result) {
                while ($row = $scheduleResult->fetch_assoc()) {
                    $scheduledSessions[] = $row;
                }
            }
            $scheduleStmt->close();
        }

        $studentSql = '
            SELECT
                sp.id,
                sp.college_id,
                sp.first_name,
                sp.middle_name,
                sp.last_name,
                sp.email,
                sp.mobile_no,
                sp.state,
                sp.district,
                sp.created_at,
                sp.academic_year,
                sp.semester,
                c.name AS college_name,
                cr.course_name,
                cr.duration,
                cr.fees,
                COALESCE((
                    SELECT SUM(rp.amount_rupees)
                    FROM registration_payments rp
                    WHERE rp.student_profile_id = sp.id
                ), 0) AS total_paid,
                COALESCE((
                    SELECT COUNT(*)
                    FROM session_attendance sa
                    INNER JOIN coordinator_sessions cs_att
                        ON cs_att.id = sa.session_id
                       AND cs_att.coordinator_id = cc.coordinator_id
                    WHERE sa.student_profile_id = sp.id
                ), 0) AS attendance_marked,
                COALESCE((
                    SELECT COUNT(*)
                    FROM session_attendance sa
                    INNER JOIN coordinator_sessions cs_att
                        ON cs_att.id = sa.session_id
                       AND cs_att.coordinator_id = cc.coordinator_id
                    WHERE sa.student_profile_id = sp.id
                      AND sa.status = "present"
                ), 0) AS attendance_present
            FROM coordinator_colleges cc
            INNER JOIN student_profiles sp ON sp.college_id = cc.college_id
            LEFT JOIN colleges c ON c.id = sp.college_id
            LEFT JOIN courses cr ON cr.id = sp.course_id
            WHERE cc.coordinator_id = ?
            ORDER BY c.name ASC, sp.first_name ASC, sp.last_name ASC
        ';

        $studentStmt = $conn->prepare($studentSql);
        if ($studentStmt !== false) {
            $coordinatorId = (int)$coordinator['id'];
            $studentStmt->bind_param('i', $coordinatorId);
            $studentStmt->execute();
            $studentResult = $studentStmt->get_result();
            if ($studentResult instanceof mysqli_result) {
                while ($row = $studentResult->fetch_assoc()) {
                    $assignedStudents[] = $row;
                }
            }
            $studentStmt->close();
        }

        $ticketSql = '
            SELECT
                ct.id,
                ct.subject,
                ct.message,
                ct.status,
                ct.is_seen_by_coordinator,
                ct.created_at,
                ct.updated_at,
                sp.first_name,
                sp.middle_name,
                sp.last_name,
                sp.email,
                sp.academic_year,
                sp.semester,
                c.name AS college_name
            FROM coordinator_tickets ct
            INNER JOIN student_profiles sp ON sp.id = ct.student_profile_id
            LEFT JOIN colleges c ON c.id = ct.college_id
            WHERE ct.coordinator_id = ?
            ORDER BY ct.created_at DESC
            LIMIT 120
        ';

        $ticketStmt = $conn->prepare($ticketSql);
        if ($ticketStmt !== false) {
            $coordinatorId = (int)$coordinator['id'];
            $ticketStmt->bind_param('i', $coordinatorId);
            $ticketStmt->execute();
            $ticketResult = $ticketStmt->get_result();
            if ($ticketResult instanceof mysqli_result) {
                while ($row = $ticketResult->fetch_assoc()) {
                    $coordinatorTickets[] = $row;
                    if ((int)($row['is_seen_by_coordinator'] ?? 0) === 0) {
                        $ticketUnreadCount++;
                    }
                }
            }
            $ticketStmt->close();
        }

        foreach ($coordinatorTickets as $ticketMeta) {
            $ticketStatus = (string)($ticketMeta['status'] ?? 'open');
            if ($ticketStatus === 'resolved') {
                $ticketResolvedCount++;
            } else {
                $ticketOpenCount++;
            }
        }

        foreach ($assignedStudents as $student) {
            $feeText = (string)($student['fees'] ?? '0');
            $feeNumeric = preg_replace('/[^0-9.]/', '', $feeText) ?? '0';
            $totalFee = (float)$feeNumeric;
            $paidFee = (float)($student['total_paid'] ?? 0);
            $pendingFee = max(0.0, $totalFee - $paidFee);
            $attendanceMarked = (int)($student['attendance_marked'] ?? 0);
            $attendancePresent = (int)($student['attendance_present'] ?? 0);
            $attendancePercent = $attendanceMarked > 0
                ? (int)round(($attendancePresent / $attendanceMarked) * 100)
                : 0;

            $studentId = (int)$student['id'];
            $fullName = trim((string)$student['first_name'] . ' ' . (string)$student['middle_name'] . ' ' . (string)$student['last_name']);

            $assignedStudentsView[$studentId] = [
                'name' => $fullName,
                'email' => (string)$student['email'],
                'mobile' => (string)$student['mobile_no'],
                'college' => (string)($student['college_name'] ?? ''),
                'course' => (string)($student['course_name'] ?? ''),
                'duration' => (string)($student['duration'] ?? ''),
                'state' => (string)$student['state'],
                'district' => (string)$student['district'],
                'registered_on' => (string)$student['created_at'],
                'total_fee' => $totalFee,
                'paid_fee' => $paidFee,
                'pending_fee' => $pendingFee,
                'attendance_marked' => $attendanceMarked,
                'attendance_present' => $attendancePresent,
                'attendance_absent' => max(0, $attendanceMarked - $attendancePresent),
                'attendance_percent' => $attendancePercent,
            ];
        }
        // Build college-keyed session & student maps for Attendance JS
        $sessionsByCollegeId = [];
        foreach ($scheduledSessions as $sess) {
            $cid = (int)($sess['college_id'] ?? 0);
            if ($cid <= 0) { continue; }
            if (!isset($sessionsByCollegeId[$cid])) { $sessionsByCollegeId[$cid] = []; }
            $sessionsByCollegeId[$cid][] = [
                'id'             => (int)$sess['id'],
                'session_date'   => (string)$sess['session_date'],
                'session_details'=> mb_substr((string)$sess['session_details'], 0, 80),
            ];
        }

        $studentsByCollegeId = [];
        foreach ($assignedStudents as $s) {
            $cid = (int)($s['college_id'] ?? 0);
            if ($cid <= 0) { continue; }
            if (!isset($studentsByCollegeId[$cid])) { $studentsByCollegeId[$cid] = []; }
            $sName = trim((string)$s['first_name'] . ' ' . (string)$s['middle_name'] . ' ' . (string)$s['last_name']);
            $studentsByCollegeId[$cid][] = [
                'id'    => (int)$s['id'],
                'name'  => $sName !== '' ? $sName : 'Unknown',
                'email' => (string)$s['email'],
            ];
        }
    }

    $conn->close();
}

$displayName = htmlspecialchars((string)$user['name'], ENT_QUOTES, 'UTF-8');
$displayLoginId = htmlspecialchars((string)$user['login_id'], ENT_QUOTES, 'UTF-8');
$initial = strtoupper(substr((string)$user['name'], 0, 1));

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
    <title>Coordinator Dashboard - 3D Shikshan</title>
    <link rel="icon" type="image/png" href="../assets/logo.png" />
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;700&family=Space+Grotesk:wght@500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        *,*::before,*::after{margin:0;padding:0;box-sizing:border-box}
        :root{--bg:#f5f6f8;--surface:#fff;--surface-2:#eef0f4;--border:#e0e3ea;--text:#1a1d26;--text-muted:#6b7185;--accent:#0b8a5e;--accent-light:#0b8a5e14;--red:#d9435f;--sidebar-w:240px;--topbar-h:62px;--radius:14px;--transition:.22s cubic-bezier(.4,0,.2,1)}
        body{font-family:'DM Sans',sans-serif;background:var(--bg);color:var(--text);line-height:1.6;min-height:100vh;-webkit-font-smoothing:antialiased}
        .topbar{position:fixed;top:0;left:0;right:0;z-index:200;height:var(--topbar-h);background:var(--surface);border-bottom:1px solid var(--border);display:flex;align-items:center;padding:0 24px;gap:16px}
        .topbar-brand{display:flex;align-items:center;gap:10px;font-family:'Space Grotesk',sans-serif;font-size:1.08rem;font-weight:700;color:var(--accent);text-decoration:none}
        .topbar-brand span{color:var(--text)}
        .sidebar-toggle{background:none;border:none;cursor:pointer;color:var(--text-muted);font-size:1.1rem;width:36px;height:36px;border-radius:8px;display:flex;align-items:center;justify-content:center}
        .topbar-spacer{flex:1}
        .topbar-actions{display:flex;align-items:center;gap:10px}
        .notification-wrap{position:relative}
        .notification-btn{width:38px;height:38px;border-radius:12px;border:1px solid var(--border);background:var(--surface);color:var(--text-muted);cursor:pointer;display:inline-flex;align-items:center;justify-content:center;position:relative;transition:var(--transition)}
        .notification-btn:hover{border-color:var(--accent);background:var(--accent-light);color:var(--accent)}
        .notification-btn i{font-size:.95rem}
        .notification-badge{position:absolute;top:-5px;right:-5px;min-width:17px;height:17px;border-radius:999px;background:var(--red);color:#fff;border:2px solid var(--surface);font-size:.62rem;font-weight:700;display:inline-flex;align-items:center;justify-content:center;padding:0 4px}
        .notification-dropdown{position:absolute;top:calc(100% + 10px);right:0;background:var(--surface);border:1px solid var(--border);border-radius:14px;width:min(360px,85vw);max-height:420px;overflow:auto;box-shadow:0 18px 45px rgba(2,6,23,.16);opacity:0;pointer-events:none;transform:translateY(-8px);transition:opacity var(--transition),transform var(--transition);z-index:1400}
        .notification-wrap.open .notification-dropdown{opacity:1;pointer-events:auto;transform:translateY(0)}
        .notification-head{display:flex;align-items:center;justify-content:space-between;padding:12px 14px;border-bottom:1px solid var(--border)}
        .notification-head strong{font-size:.86rem}
        .notification-head span{font-size:.73rem;color:var(--text-muted);font-weight:700}
        .notification-list{display:flex;flex-direction:column}
        .notification-item{padding:12px 14px;border-bottom:1px solid var(--border);cursor:pointer;transition:var(--transition)}
        .notification-item:last-child{border-bottom:none}
        .notification-item:hover{background:#f8fafc}
        .notification-item-title{font-size:.8rem;font-weight:700;color:var(--text);margin-bottom:2px}
        .notification-item-msg{font-size:.75rem;color:var(--text-muted);line-height:1.4;margin-bottom:4px}
        .notification-item-time{font-size:.69rem;color:#94a3b8}
        .notification-empty{padding:16px 14px;text-align:center;color:var(--text-muted);font-size:.78rem}
        .profile-wrap{position:relative}
        .profile-btn{display:flex;align-items:center;gap:10px;background:none;border:1px solid var(--border);border-radius:40px;padding:5px 14px 5px 5px;cursor:pointer;color:var(--text)}
        .profile-avatar{width:34px;height:34px;border-radius:50%;background:var(--accent);display:flex;align-items:center;justify-content:center;color:#fff;font-size:.85rem;font-weight:700}
        .profile-name{font-size:.84rem;font-weight:600;max-width:110px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
        .profile-chevron{font-size:.65rem;color:var(--text-muted);transition:transform var(--transition)}
        .profile-wrap.open .profile-chevron{transform:rotate(180deg)}
        .profile-dropdown{position:absolute;top:calc(100% + 10px);right:0;background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);min-width:230px;box-shadow:0 8px 32px rgba(0,0,0,.12);opacity:0;pointer-events:none;transform:translateY(-8px);transition:opacity var(--transition),transform var(--transition);z-index:999}
        .profile-wrap.open .profile-dropdown{opacity:1;pointer-events:auto;transform:translateY(0)}
        .dropdown-header{padding:16px;border-bottom:1px solid var(--border)}
        .dropdown-header .d-name{font-weight:700;font-size:.9rem}
        .dropdown-header .d-id{font-size:.75rem;color:var(--text-muted);margin-top:1px}
        .dropdown-header .d-badge{display:inline-block;margin-top:6px;font-size:.62rem;font-weight:700;text-transform:uppercase;letter-spacing:1px;padding:3px 9px;border-radius:20px;background:var(--accent-light);color:var(--accent)}
        .dropdown-menu-list{padding:8px}
        .dropdown-item{display:flex;align-items:center;gap:10px;padding:10px 12px;border-radius:8px;text-decoration:none;font-size:.84rem;color:var(--text)}
        .dropdown-item i{width:16px;text-align:center;color:var(--text-muted)}
        .dropdown-item.danger{color:var(--red)}
        .dropdown-item.danger i{color:var(--red)}
        .dropdown-divider{height:1px;background:var(--border);margin:4px 8px}
        .sidebar{position:fixed;top:var(--topbar-h);left:0;bottom:0;width:var(--sidebar-w);background:var(--surface);border-right:1px solid var(--border);display:flex;flex-direction:column;z-index:100;transition:transform var(--transition)}
        .sidebar.collapsed{transform:translateX(calc(-1 * var(--sidebar-w)))}
        .sidebar-section{padding:8px;flex:1;overflow-y:auto}
        .sidebar-label{font-size:.6rem;font-weight:700;text-transform:uppercase;letter-spacing:1.4px;color:var(--text-muted);padding:10px 12px 4px}
        .nav-item{display:flex;align-items:center;gap:12px;padding:10px 12px;border-radius:10px;font-size:.87rem;font-weight:500;color:var(--text-muted);text-decoration:none;transition:var(--transition)}
        .nav-item i{width:18px;text-align:center}
        .nav-item:hover{background:var(--surface-2);color:var(--text)}
        .nav-item.active{background:var(--accent-light);color:var(--accent);font-weight:600}
        .sidebar-footer{padding:12px;border-top:1px solid var(--border)}
        .main-content{margin-top:var(--topbar-h);margin-left:var(--sidebar-w);min-height:calc(100vh - var(--topbar-h));padding:28px 28px 40px;transition:margin-left var(--transition)}
        .main-content.expanded{margin-left:0}
        .page-heading{font-family:'Space Grotesk',sans-serif;font-size:1.3rem;font-weight:700;margin-bottom:4px}
        .page-sub{color:var(--text-muted);font-size:.86rem;margin-bottom:20px}
        .overview-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px;margin-bottom:16px}
        .overview-stat{background:var(--surface);border:1px solid var(--border);border-radius:14px;padding:14px 15px}
        .overview-stat .k{font-size:.72rem;text-transform:uppercase;letter-spacing:.7px;color:var(--text-muted);margin-bottom:6px}
        .overview-stat .v{font-family:'Space Grotesk',sans-serif;font-size:1.2rem;font-weight:700;color:#1e293b;line-height:1.2}
        .info-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:20px 22px;margin-bottom:16px}
        .info-card h3{font-family:'Space Grotesk',sans-serif;font-size:1rem;font-weight:700;margin-bottom:14px}
        .info-row{display:flex;align-items:flex-start;gap:10px;padding:10px 0;border-bottom:1px solid var(--border);font-size:.86rem;min-width:0}
        .info-row:last-child{border-bottom:none;padding-bottom:0}
        .info-row i{color:var(--accent);width:16px;text-align:center;margin-top:3px;flex-shrink:0}
        .info-key{color:var(--text-muted);min-width:120px;flex-shrink:0}
        .info-val{font-weight:600;word-break:break-word;overflow-wrap:break-word;word-wrap:break-word;min-width:0;flex:1}
        .schedule-card{background:var(--surface);border:1px solid var(--border);border-radius:16px;padding:18px;margin-bottom:14px}
        .schedule-head{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:12px}
        .schedule-head h3{font-family:'Space Grotesk',sans-serif;font-size:1rem}
        .schedule-note{font-size:.78rem;color:var(--text-muted)}
        .schedule-form{display:grid;grid-template-columns:1.2fr .8fr;gap:10px}
        .schedule-group{display:flex;flex-direction:column;gap:6px}
        .schedule-group.full{grid-column:1 / -1}
        .schedule-group label{font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:var(--text-muted)}
        .schedule-group select,.schedule-group input,.schedule-group textarea{width:100%;border:1px solid var(--border);border-radius:10px;background:var(--surface-2);padding:10px 12px;font:inherit;font-size:.84rem;color:var(--text);outline:none}
        .schedule-group textarea{min-height:110px;resize:vertical}
        .schedule-group select:focus,.schedule-group input:focus,.schedule-group textarea:focus{border-color:var(--accent);box-shadow:0 0 0 3px #0b8a5e12;background:var(--surface)}
        .schedule-actions{display:flex;justify-content:flex-end;align-items:center;gap:10px}
        .btn-schedule{border:none;background:var(--accent);color:#fff;border-radius:10px;padding:10px 14px;font-size:.82rem;font-weight:700;cursor:pointer}
        .btn-schedule:disabled{opacity:.65;cursor:not-allowed}
        .schedule-message{display:none;margin-top:10px;border-radius:10px;padding:10px 12px;font-size:.8rem}
        .schedule-message.show{display:block}
        .schedule-message.success{background:#ecfdf3;border:1px solid #86efac;color:#166534}
        .schedule-message.error{background:#fff1f2;border:1px solid #fecdd3;color:#be123c}
        .table-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);overflow:hidden}
        .table-wrap{overflow-x:auto}
        .table{width:100%;border-collapse:collapse;min-width:620px}
        .table th,.table td{padding:12px 14px;text-align:left;border-bottom:1px solid var(--border);font-size:.82rem;white-space:nowrap}
        .table th{background:var(--surface-2);color:var(--text-muted);text-transform:uppercase;letter-spacing:.8px;font-size:.72rem}
        .table tbody tr:last-child td{border-bottom:none}
        .college-summary{display:flex;align-items:center;justify-content:space-between;gap:14px;background:linear-gradient(135deg,#ffffff 0%,#f7fbf9 100%);border:1px solid #dbe7e1;border-radius:18px;padding:18px 20px;margin-bottom:16px;box-shadow:0 14px 28px rgba(15,23,42,.04)}
        .college-summary-copy{display:flex;flex-direction:column;gap:4px}
        .college-summary-label{font-size:.72rem;font-weight:700;letter-spacing:.9px;text-transform:uppercase;color:var(--accent)}
        .college-summary-title{font-family:'Space Grotesk',sans-serif;font-size:1.05rem;font-weight:700;color:var(--text)}
        .college-summary-text{font-size:.84rem;color:var(--text-muted)}
        .college-summary-count{display:inline-flex;align-items:center;gap:8px;padding:10px 14px;border-radius:999px;background:#ffffff;border:1px solid #d9e2ec;font-size:.82rem;font-weight:700;color:#334155;white-space:nowrap}
        .college-summary-count i{color:var(--accent)}
        .college-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px}
        .college-card{background:var(--surface);border:1px solid #dbe2ea;border-radius:16px;padding:14px;box-shadow:0 8px 18px rgba(15,23,42,.04);display:flex;align-items:center;justify-content:space-between;gap:12px}
        .college-card-top{display:flex;align-items:center;justify-content:space-between;gap:12px;min-width:0;flex:1}
        .college-icon{width:44px;height:44px;border-radius:14px;background:linear-gradient(135deg,#0b8a5e 0%,#0f766e 100%);display:flex;align-items:center;justify-content:center;color:#fff;font-size:1rem;flex-shrink:0;box-shadow:0 10px 20px rgba(11,138,94,.2)}
        .college-card h3{font-family:'Space Grotesk',sans-serif;font-size:.95rem;line-height:1.35;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
        .college-view-btn{height:38px;padding:0 12px;border:1px solid #cbd5e1;border-radius:10px;background:#ffffff;font-size:.77rem;font-weight:700;color:#334155;cursor:pointer;display:inline-flex;align-items:center;gap:6px;justify-content:center;white-space:nowrap}
        .college-view-btn:hover{border-color:var(--accent);color:var(--accent);background:#f0fdf4}
        .college-modal-overlay{display:none;position:fixed;inset:0;background:rgba(2,6,23,.45);z-index:1200;padding:18px;align-items:center;justify-content:center}
        .college-modal-overlay.show{display:flex}
        .college-modal{width:min(680px,100%);max-height:90vh;overflow:auto;background:var(--surface);border:1px solid var(--border);border-radius:16px;padding:18px}
        .college-modal-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:10px}
        .college-modal-head h3{font-family:'Space Grotesk',sans-serif;font-size:1.05rem}
        .college-close{border:1px solid var(--border);background:var(--surface);border-radius:8px;width:34px;height:34px;cursor:pointer}
        .college-location-block{display:grid;gap:10px}
        .college-location-item{display:flex;align-items:flex-start;gap:10px;padding:10px 12px;border-radius:12px;background:#f8fafc;border:1px solid #e2e8f0}
        .college-location-item i{width:16px;text-align:center;color:var(--accent);margin-top:2px}
        .college-location-item strong{display:block;font-size:.68rem;font-weight:700;letter-spacing:.8px;text-transform:uppercase;color:#64748b;margin-bottom:3px}
        .college-location-item span{display:block;font-size:.84rem;color:var(--text)}
        .student-table th,.student-table td{white-space:normal;vertical-align:top}
        .student-cards{display:none;gap:8px}
        .student-card{background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:10px}
        .student-card h4{font-family:'Space Grotesk',sans-serif;font-size:.9rem;margin-bottom:6px}
        .student-meta{display:grid;grid-template-columns:1fr 1fr;gap:6px 8px}
        .student-meta div{font-size:.74rem;color:var(--text-muted)}
        .student-meta strong{display:block;font-size:.64rem;text-transform:uppercase;letter-spacing:.5px;color:#475569}
        .student-filter-panel{background:linear-gradient(180deg,#ffffff 0%,#fbfcfd 100%);border:1px solid #d8dde6;border-radius:18px;padding:16px 18px;margin-bottom:16px;box-shadow:0 12px 30px rgba(15,23,42,.05)}
        .student-filter-top{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-bottom:14px}
        .student-filter-title{display:flex;align-items:center;gap:8px;font-size:.86rem;font-weight:700;color:var(--text);flex-shrink:0}
        .student-filter-title i{color:var(--accent)}
        .student-filter-count{font-size:.77rem;font-weight:700;color:#475569;background:#f8fafc;border:1px solid #e2e8f0;padding:6px 11px;border-radius:999px;flex-shrink:0}
        .student-filter-bar{display:grid;grid-template-columns:minmax(250px,1.2fr) minmax(180px,1fr) minmax(120px,.6fr) minmax(120px,.6fr);gap:10px;align-items:center;min-width:0;background:#f8fafc;border:1px solid #e2e8f0;border-radius:14px;padding:8px}
        .student-field{display:flex;flex-direction:column;gap:0;min-width:0}
        .student-field-label{display:none}
        .student-input-wrap,.student-select-wrap{position:relative}
        .student-input-icon{position:absolute;left:12px;top:50%;transform:translateY(-50%);font-size:.8rem;color:var(--text-muted);pointer-events:none}
        .student-select-icon{position:absolute;left:12px;top:50%;transform:translateY(-50%);font-size:.8rem;color:var(--text-muted);pointer-events:none}
        .student-filter-input,.student-filter-select{height:40px;border:1px solid #dbe2ea;border-radius:12px;background:#ffffff;font-size:.82rem;color:var(--text);outline:none;transition:border-color var(--transition),box-shadow var(--transition),background var(--transition)}
        .student-filter-input{width:100%;padding:0 14px 0 38px}
        .student-filter-select{width:100%;padding:0 36px 0 38px;appearance:none;-webkit-appearance:none;-moz-appearance:none}
        .student-select-caret{position:absolute;right:12px;top:50%;transform:translateY(-50%);font-size:.72rem;color:#64748b;pointer-events:none}
        .student-filter-input::placeholder{color:#94a3b8}
        .student-filter-input:focus,.student-filter-select:focus{border-color:var(--accent);box-shadow:0 0 0 4px rgba(11,138,94,.12);background:#fff}
        .student-filter-actions{display:flex;justify-content:flex-end;margin-top:10px;padding-top:10px;border-top:1px solid #edf2f7}
        .student-filter-clear{height:38px;padding:0 16px;border:1px solid #cbd5e1;border-radius:12px;background:#ffffff;font-size:.78rem;font-weight:700;color:#475569;cursor:pointer;display:inline-flex;align-items:center;gap:7px;justify-content:center;min-width:140px;box-shadow:0 6px 16px rgba(148,163,184,.12)}
        .student-filter-clear:hover{border-color:var(--accent);color:var(--accent);background:#f0fdf4;box-shadow:0 10px 22px rgba(11,138,94,.12)}
        .fee-chip{display:inline-block;padding:4px 8px;border-radius:14px;font-size:.68rem;font-weight:700;letter-spacing:.4px}
        .fee-chip.total{background:#dbeafe;color:#1d4ed8}
        .fee-chip.paid{background:#dcfce7;color:#166534}
        .fee-chip.pending{background:#ffedd5;color:#9a3412}
        .btn-view-student{display:inline-flex;align-items:center;gap:6px;padding:7px 10px;border:1px solid var(--border);border-radius:8px;background:var(--surface);font-size:.76rem;font-weight:700;cursor:pointer;color:var(--text)}
        .btn-view-student:hover{border-color:var(--accent);background:var(--accent-light);color:var(--accent)}

        .student-modal-overlay{display:none;position:fixed;inset:0;background:rgba(2,6,23,.45);z-index:1200;padding:18px;align-items:center;justify-content:center}
        .student-modal-overlay.show{display:flex}
        .student-modal{width:min(760px,100%);max-height:90vh;overflow:auto;background:var(--surface);border:1px solid var(--border);border-radius:16px;padding:18px}
        .student-modal-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:10px}
        .student-modal-head h3{font-family:'Space Grotesk',sans-serif;font-size:1.05rem}
        .student-close{border:1px solid var(--border);background:var(--surface);border-radius:8px;width:34px;height:34px;cursor:pointer}
        .student-detail-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}
        .student-detail-item{background:var(--surface-2);border:1px solid var(--border);border-radius:10px;padding:10px;min-width:0;word-break:break-word}
        .student-detail-item strong{display:block;font-size:.68rem;text-transform:uppercase;letter-spacing:.5px;color:#475569;margin-bottom:3px}
        .student-detail-item span{font-size:.84rem;color:var(--text);word-break:break-word;overflow-wrap:break-word;word-wrap:break-word}
        .student-detail-item.full{grid-column:1 / -1}
        .att-progress-card{background:linear-gradient(180deg,#ffffff 0%,#f8fafc 100%);border:1px solid #dbe2ea;border-radius:12px;padding:12px}
        .att-progress-head{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:8px}
        .att-progress-title{font-size:.76rem;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:#475569}
        .att-progress-percent{font-size:.9rem;font-weight:800;color:var(--accent)}
        .att-progress-track{width:100%;height:9px;border-radius:999px;background:#e5e7eb;overflow:hidden}
        .att-progress-fill{height:100%;border-radius:999px;background:var(--accent);transition:width var(--transition)}
        .att-progress-meta{display:flex;gap:8px;flex-wrap:wrap;margin-top:9px}
        .att-progress-chip{display:inline-flex;align-items:center;gap:5px;padding:4px 9px;border-radius:999px;font-size:.72rem;font-weight:700}
        .att-progress-chip.present{background:var(--accent-light);color:var(--accent)}
        .att-progress-chip.absent{background:#fff1f2;color:var(--red)}
        .att-progress-chip.total{background:#f1f5f9;color:#334155}
        .student-filter-empty{display:none;background:var(--surface);border:1px dashed var(--border);border-radius:var(--radius);padding:16px;color:var(--text-muted);text-align:center;font-size:.84rem;margin-top:10px}
        .student-filter-empty.show{display:block}
        .tickets-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);overflow:hidden}
        .tickets-head{padding:9px 12px;border-bottom:1px solid var(--border);font-size:.76rem;font-weight:700;text-transform:uppercase;letter-spacing:.7px;color:var(--text-muted)}
        .ticket-filter-bar{display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin-bottom:12px;background:linear-gradient(180deg,#fff 0%,#fbfcfd 100%);border:1px solid #d8dde6;border-radius:14px;padding:10px}
        .ticket-field{position:relative;min-width:0;flex:1;min-width:140px}
        .ticket-field.search-field{flex:2;min-width:250px}
        .ticket-icon{position:absolute;left:12px;top:50%;transform:translateY(-50%);color:var(--text-muted);font-size:.8rem;pointer-events:none}
        .ticket-search,.ticket-status-filter{width:100%;height:40px;border:1px solid #dbe2ea;border-radius:10px;background:#fff;outline:none;font-size:.82rem;color:var(--text);font-family:'DM Sans',sans-serif;transition:border-color var(--transition),box-shadow var(--transition)}
        .ticket-search{padding:0 12px 0 36px}
        .ticket-status-filter{padding:0 34px 0 36px;appearance:none;-webkit-appearance:none;-moz-appearance:none}
        .ticket-search:focus,.ticket-status-filter:focus{border-color:var(--accent);box-shadow:0 0 0 4px rgba(11,138,94,.12)}
        .ticket-select-caret{position:absolute;right:12px;top:50%;transform:translateY(-50%);font-size:.68rem;color:#64748b;pointer-events:none}
        .ticket-filter-count{margin-left:auto;font-size:.76rem;font-weight:700;color:#475569;background:#f8fafc;border:1px solid #e2e8f0;padding:7px 11px;border-radius:999px;white-space:nowrap}
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
        .ticket-row{padding:8px 10px;border-bottom:1px solid var(--border)}
        .ticket-row.is-unread{background:#f9fffb}
        .ticket-row:last-child{border-bottom:none}
        .ticket-row-top{display:flex;align-items:flex-start;justify-content:space-between;gap:8px;margin-bottom:4px}
        .ticket-subject{font-size:.82rem;font-weight:700;color:var(--text);line-height:1.35}
        .ticket-meta{font-size:.7rem;color:#64748b;margin-bottom:4px;line-height:1.35}
        .ticket-message{font-size:.76rem;color:var(--text-muted);white-space:pre-line;line-height:1.4;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
        .ticket-status{display:inline-flex;align-items:center;justify-content:center;padding:4px 8px;border-radius:999px;font-size:.66rem;font-weight:700;text-transform:uppercase;letter-spacing:.5px;border:1px solid transparent;white-space:nowrap}
        .ticket-status{padding:3px 7px;font-size:.62rem}
        .ticket-status.open{background:#ffedd5;border-color:#fdba74;color:#9a3412}
        .ticket-status.in-progress{background:#dbeafe;border-color:#93c5fd;color:#1d4ed8}
        .ticket-status.resolved{background:#dcfce7;border-color:#86efac;color:#166534}
        .ticket-actions{display:flex;gap:8px;margin-top:12px;padding-top:12px;border-top:1px solid var(--border)}
        .ticket-resolve-btn{display:inline-flex;align-items:center;gap:6px;padding:8px 14px;border:1px solid #86efac;border-radius:8px;background:#ecfdf3;color:#166534;font-size:.78rem;font-weight:700;cursor:pointer;transition:var(--transition)}
        .ticket-resolve-btn:hover{background:#dcfce7;border-color:#6ee7b7}
        .empty-box{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:26px;color:var(--text-muted);text-align:center;font-size:.86rem}
        .att-setup-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:22px;margin-bottom:20px;display:grid;grid-template-columns:1fr 1fr;gap:18px}
        .att-step label{display:block;font-size:.76rem;font-weight:700;letter-spacing:.06em;color:var(--text-muted);text-transform:uppercase;margin-bottom:7px}
        .att-step select{width:100%;height:42px;border:1.5px solid var(--border);border-radius:10px;padding:0 14px;font-size:.88rem;background:var(--surface);color:var(--text);cursor:pointer;appearance:none;-webkit-appearance:none}
        .att-step select:focus{outline:none;border-color:var(--accent)}
        .att-step select:disabled{opacity:.5;cursor:not-allowed}
        .att-toolbar{display:flex;align-items:center;justify-content:space-between;background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:12px 16px;margin-bottom:12px;gap:10px}
        .att-select-all-wrap{display:flex;align-items:center;gap:8px;font-size:.86rem;font-weight:600;cursor:pointer;user-select:none}
        .att-select-all-wrap input[type=checkbox]{width:17px;height:17px;accent-color:var(--accent);cursor:pointer;flex-shrink:0}
        .att-count{font-size:.8rem;color:var(--text-muted);white-space:nowrap}
        .att-toolbar-right{display:flex;align-items:center;gap:8px;flex-wrap:wrap;justify-content:flex-end}
        .att-status{display:none;padding:5px 10px;border-radius:999px;font-size:.72rem;font-weight:700;letter-spacing:.4px;border:1px solid transparent}
        .att-status.show{display:inline-flex;align-items:center}
        .att-status.completed{color:#166534;background:#ecfdf3;border-color:#86efac}
        .att-status.editing{color:#92400e;background:#fffbeb;border-color:#fcd34d}
        .att-edit-btn{display:none;height:34px;padding:0 12px;border:1px solid #cbd5e1;border-radius:10px;background:#fff;font-size:.76rem;font-weight:700;color:#334155;cursor:pointer;align-items:center;gap:6px}
        .att-edit-btn.show{display:inline-flex}
        .att-edit-btn:hover{border-color:var(--accent);color:var(--accent);background:#f0fdf4}
        .att-list{display:flex;flex-direction:column;gap:8px;margin-bottom:16px}
        .att-student-row{display:flex;align-items:center;gap:12px;background:var(--surface);border:1.5px solid var(--border);border-radius:10px;padding:12px 14px;transition:border-color var(--transition),background var(--transition)}
        .att-student-row.checked{border-color:var(--accent);background:var(--accent-light)}
        .att-student-chk{width:18px;height:18px;accent-color:var(--accent);cursor:pointer;flex-shrink:0}
        .att-student-info{flex:1;min-width:0}
        .att-student-name{font-size:.88rem;font-weight:700;color:var(--text)}
        .att-student-sub{font-size:.74rem;color:var(--text-muted);margin-top:2px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
        .att-badge{font-size:.7rem;font-weight:700;padding:2px 9px;border-radius:999px;border:1.5px solid currentColor;flex-shrink:0}
        .att-badge.present{color:var(--accent);background:var(--accent-light)}
        .att-badge.absent{color:var(--red);background:#d9435f12}
        .sidebar-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.3);z-index:99}
        .sidebar-overlay.visible{display:block}
        @media (max-width:700px){.sidebar{transform:translateX(calc(-1 * var(--sidebar-w)))}.sidebar.open{transform:translateX(0)}.main-content{margin-left:0;padding:16px 12px 30px}.profile-name{display:none}.topbar-brand span{display:none}.notification-btn{width:34px;height:34px;border-radius:10px}.notification-dropdown{left:8px;right:8px;width:auto;max-width:none}.page-heading{font-size:1.18rem}.topbar{padding:0 12px}}
        @media (max-width:920px){.student-table-wrap{display:none}.student-cards{display:grid}.student-meta{grid-template-columns:1fr}.student-filter-bar{grid-template-columns:1fr 1fr}}
        @media(max-width:768px){
            .ticket-filter-bar{flex-direction:column;align-items:stretch}
            .ticket-filter-count, .ticket-filter-reset{margin-left:0; width:100%; justify-content:center;}
            .ticket-row-top{flex-direction:column;align-items:flex-start}
        }
        @media (max-width:640px){.college-summary{padding:16px;flex-direction:column;align-items:flex-start}.college-summary-count{width:100%;justify-content:center}.college-grid{grid-template-columns:1fr}.college-card{padding:12px}.college-view-btn{height:36px;padding:0 10px}.student-detail-grid{grid-template-columns:1fr}.student-filter-panel{padding:12px}.student-filter-top{flex-wrap:wrap;white-space:normal}.student-filter-bar{grid-template-columns:1fr;gap:8px;padding:7px}.student-filter-input,.student-filter-select{height:38px;font-size:.78rem}.student-filter-input{padding:0 10px 0 32px}.student-filter-select{padding:0 26px 0 32px}.student-input-icon,.student-select-icon{left:10px;font-size:.74rem}.student-select-caret{right:10px;font-size:.66rem}.student-filter-actions{justify-content:stretch}.student-filter-clear{width:100%;height:36px}.ticket-filter-bar{flex-direction:column;align-items:stretch}.ticket-filter-count{margin-left:0}.ticket-row{padding:8px 9px}.ticket-row-top{flex-direction:column;gap:5px;margin-bottom:5px}.ticket-status{align-self:flex-start}.schedule-form{grid-template-columns:1fr}.att-setup-card{grid-template-columns:1fr}}
        @media (max-width:480px){
            .overview-stats{grid-template-columns:repeat(2,1fr);gap:8px}
            .student-filter-bar{grid-template-columns:1fr}
            .ticket-field{min-width:0;width:100%}
            .student-modal-body{padding:12px 10px}
            .att-list{gap:6px}
            .page-heading{font-size:1.1rem}
            .page-sub{font-size:.8rem}
        }
    </style>
</head>
<body>
    <header class="topbar">
    <button class="sidebar-toggle" id="sidebarToggle" aria-label="Toggle navigation"><i class="fa-solid fa-bars"></i></button>
    <a href="#" class="topbar-brand"><img src="../assets/logo.png" alt="Logo" style="height: 28px; width: auto; object-fit: contain; margin-right: 10px;">3D <span>Shikshan</span></a>
    <div class="topbar-spacer"></div>
    <div class="topbar-actions">
        <div class="notification-wrap" id="notificationWrap">
            <button type="button" class="notification-btn" id="coordinatorNotificationBtn" aria-label="Ticket notifications">
                <i class="fa-regular fa-bell"></i>
                <span class="notification-badge" id="coordinatorNotificationBadge"><?php echo (int)$ticketUnreadCount; ?></span>
            </button>
            <div class="notification-dropdown" id="notificationDropdown">
                <div class="notification-head">
                    <strong>Ticket Alerts</strong>
                    <span><?php echo (int)$ticketUnreadCount; ?> unread</span>
                </div>
                <div class="notification-list">
                    <?php if (!empty($coordinatorTickets)): ?>
                        <?php foreach (array_slice($coordinatorTickets, 0, 6) as $ticketAlert): ?>
                            <?php
                                $alertName = trim(
                                    (string)($ticketAlert['first_name'] ?? '') . ' ' .
                                    (string)($ticketAlert['middle_name'] ?? '') . ' ' .
                                    (string)($ticketAlert['last_name'] ?? '')
                                );
                            ?>
                            <div class="notification-item" data-open-tickets="1">
                                <div class="notification-item-title"><?php echo esc((string)$ticketAlert['subject']); ?></div>
                                <div class="notification-item-msg"><?php echo esc($alertName !== '' ? $alertName : 'Student'); ?> • <?php echo esc((string)($ticketAlert['college_name'] ?? '-')); ?></div>
                                <div class="notification-item-time"><?php echo esc((string)($ticketAlert['created_at'] ?? '-')); ?></div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="notification-empty">No ticket notifications yet.</div>
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
                <div class="d-badge">Coordinator</div>
            </div>
            <div class="dropdown-menu-list">
                <a href="#" class="dropdown-item" onclick="showSection('overview'); closeProfile();"><i class="fa-solid fa-user"></i> My Profile</a>
                <div class="dropdown-divider"></div>
                <a href="../logout.php" class="dropdown-item danger"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
            </div>
        </div>
    </div>
</header>

<div class="sidebar-overlay" id="sidebarOverlay"></div>

<aside class="sidebar" id="sidebar">
    <div class="sidebar-section">
        <div class="sidebar-label">Coordinator Panel</div>
        <a class="nav-item active" href="#" onclick="showSection('overview')"><i class="fa-solid fa-house"></i> Overview</a>
        <a class="nav-item" href="#" onclick="showSection('colleges')"><i class="fa-solid fa-building-columns"></i> Assigned Colleges</a>
        <a class="nav-item" href="#" onclick="showSection('schedule')"><i class="fa-solid fa-calendar-days"></i> Schedule</a>
        <a class="nav-item" href="#" onclick="showSection('attendance')"><i class="fa-solid fa-clipboard-check"></i> Attendance</a>
        <a class="nav-item" href="#" onclick="showSection('students')"><i class="fa-solid fa-users"></i> Students</a>
        <a class="nav-item" href="#" onclick="showSection('tickets')"><i class="fa-solid fa-life-ring"></i> Tickets</a>
    </div>
    <div class="sidebar-footer">
        <a href="../logout.php" class="nav-item" style="color:#d9435f;"><i class="fa-solid fa-right-from-bracket" style="color:#d9435f;"></i> Logout</a>
    </div>
</aside>

<main class="main-content" id="mainContent">
    <div id="section-overview">
        <div class="page-heading">Coordinator Dashboard</div>
        <p class="page-sub">Welcome back, <?php echo $displayName; ?>.</p>

        <div class="overview-stats">
            <div class="overview-stat">
                <div class="k">Assigned Colleges</div>
                <div class="v"><?php echo count($assignedColleges); ?></div>
            </div>
            <div class="overview-stat">
                <div class="k">Assigned Students</div>
                <div class="v"><?php echo count($assignedStudents); ?></div>
            </div>
            <div class="overview-stat">
                <div class="k">Scheduled Sessions</div>
                <div class="v"><?php echo count($scheduledSessions); ?></div>
            </div>
            <div class="overview-stat">
                <div class="k">Open Tickets</div>
                <div class="v"><?php echo (int)$ticketOpenCount; ?></div>
            </div>
        </div>

        <?php if ($coordinator): ?>
            <div class="info-card">
                <h3>Coordinator Profile</h3>
                <div class="info-row"><i class="fa-solid fa-user"></i><span class="info-key">Full Name</span><span class="info-val"><?php echo esc(trim((string)$coordinator['first_name'] . ' ' . (string)$coordinator['second_name'] . ' ' . (string)$coordinator['last_name'])); ?></span></div>
                <div class="info-row"><i class="fa-solid fa-envelope"></i><span class="info-key">Email</span><span class="info-val"><?php echo esc((string)$coordinator['email']); ?></span></div>
                <div class="info-row"><i class="fa-solid fa-phone"></i><span class="info-key">Mobile</span><span class="info-val"><?php echo esc((string)$coordinator['mobile_no']); ?></span></div>
                <div class="info-row"><i class="fa-solid fa-location-dot"></i><span class="info-key">Address</span><span class="info-val"><?php echo esc(trim((string)$coordinator['address_line1'] . ', ' . (string)$coordinator['address_line2'])); ?></span></div>
                <div class="info-row"><i class="fa-solid fa-map"></i><span class="info-key">Location</span><span class="info-val"><?php echo esc((string)$coordinator['district'] . ', ' . (string)$coordinator['state'] . ' - ' . (string)$coordinator['pin']); ?></span></div>
            </div>
        <?php else: ?>
            <div class="empty-box">Coordinator profile not found.</div>
        <?php endif; ?>
    </div>

    <div id="section-colleges" style="display:none;">
        <div class="page-heading">Assigned Colleges</div>
        <p class="page-sub">Colleges assigned to your coordinator account.</p>

        <?php if (!empty($assignedColleges)): ?>
            <div class="college-summary">
                <div class="college-summary-copy">
                    <div class="college-summary-label">Coordinator Access</div>
                    <div class="college-summary-title">Assigned College Directory</div>
                    <div class="college-summary-text">Review every college currently mapped to your coordinator account.</div>
                </div>
                <div class="college-summary-count"><i class="fa-solid fa-building-columns"></i> <?php echo count($assignedColleges); ?> Colleges Assigned</div>
            </div>

            <div class="college-grid">
                <?php foreach ($assignedColleges as $college): ?>
                    <?php
                        $collegeData = esc(json_encode([
                            'name' => (string)$college['name'],
                            'country' => (string)$college['country'],
                            'state' => (string)$college['state'],
                            'district' => (string)$college['district'],
                            'city' => (string)$college['city'],
                            'student_count' => (int)($college['student_count'] ?? 0),
                        ], JSON_UNESCAPED_UNICODE));
                    ?>
                    <article class="college-card">
                        <div class="college-card-top">
                            <div style="display:flex;align-items:flex-start;gap:12px;min-width:0;">
                                <div class="college-icon"><i class="fa-solid fa-building-columns"></i></div>
                                <div style="min-width:0;">
                                    <h3><?php echo esc((string)$college['name']); ?></h3>
                                </div>
                            </div>
                        </div>
                        <button type="button" class="college-view-btn" data-college="<?php echo $collegeData; ?>">
                            <i class="fa-solid fa-eye"></i> View
                        </button>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="empty-box">No colleges assigned yet.</div>
        <?php endif; ?>
    </div>

    <div id="section-schedule" style="display:none;">
        <div class="page-heading">Schedule Activities</div>
        <p class="page-sub">Create activity notifications for students from your assigned colleges.</p>

        <div class="schedule-card">
            <div class="schedule-head">
                <h3>New Activity Schedule</h3>
                <div class="schedule-note">Only assigned colleges are available</div>
            </div>

            <form id="scheduleSessionForm" class="schedule-form">
                <div class="schedule-group">
                    <label for="schedule_college_id">Select College</label>
                    <select id="schedule_college_id" required>
                        <option value="">Choose assigned college</option>
                        <?php foreach ($assignedColleges as $college): ?>
                            <option value="<?php echo (int)$college['id']; ?>"><?php echo esc((string)$college['name']); ?> (<?php echo (int)($college['student_count'] ?? 0); ?> Students)</option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="schedule-group">
                    <label for="schedule_session_date">Activity Date</label>
                    <input type="date" id="schedule_session_date" required>
                </div>

                <div class="schedule-group">
                    <label for="schedule_session_type">Activity Type</label>
                    <select id="schedule_session_type" required>
                        <option value="Class" selected>Class</option>
                        <option value="Industrial Visit">Industrial Visit</option>
                    </select>
                </div>

                <div class="schedule-group full">
                    <label for="schedule_session_details">Activity Details</label>
                    <textarea id="schedule_session_details" placeholder="Enter full activity details for students" required></textarea>
                </div>
                
                <div class="schedule-group full">
                    <label for="schedule_notes">Notes / Instructions</label>
                    <textarea id="schedule_notes" placeholder="Enter additional notes or instructions for students (optional)"></textarea>
                </div>

                <div class="schedule-group full schedule-actions">
                    <button type="submit" id="scheduleSubmitBtn" class="btn-schedule">Schedule Activity</button>
                </div>
            </form>
            <div class="schedule-message" id="scheduleMessage"></div>
        </div>

        <?php if (!empty($scheduledSessions)): ?>
            <div class="table-card">
                <div class="table-wrap">
                    <table class="table" id="scheduledSessionsTable">
                        <thead>
                            <tr>
                                <th>Activity Date</th>
                                <th>College</th>
                                <th>Type</th>
                                <th>Details</th>
                                <th>Created At</th>
                            </tr>
                        </thead>
                        <tbody id="scheduledSessionsTbody">
                            <?php foreach ($scheduledSessions as $session): ?>
                                <tr>
                                    <td><?php echo esc((string)$session['session_date']); ?></td>
                                    <td><?php echo esc((string)($session['college_name'] ?? '')); ?></td>
                                    <td><?php echo esc((string)($session['session_type'] ?? 'Class')); ?></td>
                                    <td><?php echo esc((string)$session['session_details']); ?></td>
                                    <td><?php echo esc((string)$session['created_at']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php else: ?>
            <div class="empty-box" id="noScheduledSessionsBox">No activities scheduled yet.</div>
            <div class="table-card" id="scheduledSessionsTableWrap" style="display:none;">
                <div class="table-wrap">
                    <table class="table" id="scheduledSessionsTable">
                        <thead>
                            <tr>
                                <th>Activity Date</th>
                                <th>College</th>
                                <th>Type</th>
                                <th>Details</th>
                                <th>Created At</th>
                            </tr>
                        </thead>
                        <tbody id="scheduledSessionsTbody"></tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <div id="section-attendance" style="display:none;">
        <div class="page-heading">Attendance</div>
        <p class="page-sub">Mark student attendance for your scheduled activities.</p>

        <?php if (empty($assignedColleges)): ?>
            <div class="empty-box">No colleges assigned yet. Attendance will be available once colleges are assigned.</div>
        <?php else: ?>
        <div class="att-setup-card">
            <div class="att-step">
                <label for="attCollegeSelect">Select College</label>
                <select id="attCollegeSelect">
                    <option value="">— Choose Assigned College —</option>
                    <?php foreach ($assignedColleges as $college): ?>
                        <option value="<?php echo (int)$college['id']; ?>"><?php echo esc((string)$college['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="att-step">
                <label for="attSessionSelect">Select Activity</label>
                <select id="attSessionSelect" disabled>
                    <option value="">— Choose Activity Date —</option>
                </select>
            </div>
        </div>

        <div id="attStudentSection" style="display:none;">
            <div class="att-toolbar">
                <label class="att-select-all-wrap" for="attSelectAll">
                    <input type="checkbox" id="attSelectAll">
                    <span>Select All (Mark Present)</span>
                </label>
                <div class="att-toolbar-right">
                    <span class="att-count" id="attSelectedCount">0 selected</span>
                    <span class="att-status" id="attStatusBadge">Attendance Completed</span>
                    <button type="button" class="att-edit-btn" id="attEditBtn"><i class="fa-solid fa-pen-to-square"></i> Edit Attendance</button>
                </div>
            </div>
            <div class="att-list" id="attStudentList"></div>
            <button type="button" class="btn-schedule" id="attSubmitBtn" style="min-width:180px;">Submit Attendance</button>
            <div class="schedule-message" id="attMessage" style="margin-top:12px;"></div>
        </div>
        <?php endif; ?>
    </div>

    <div id="section-students" style="display:none;">
        <div class="page-heading">Assigned Colleges Students</div>
        <p class="page-sub">View student list and open full profile with fees details.</p>

        <?php
            $collegeFilterOptions = [];
            if (!empty($assignedStudents)) {
                foreach ($assignedStudents as $studentOption) {
                    $collegeName = trim((string)($studentOption['college_name'] ?? ''));
                    if ($collegeName !== '') {
                        $collegeFilterOptions[$collegeName] = true;
                    }
                }
            }
            $collegeFilterNames = array_keys($collegeFilterOptions);
            sort($collegeFilterNames, SORT_NATURAL | SORT_FLAG_CASE);
        ?>

            <div class="student-filter-panel">
                <div class="student-filter-top">
                    <div class="student-filter-title"><i class="fa-solid fa-sliders"></i> Student Filters</div>
                    <div class="student-filter-count" id="studentVisibleCount">Showing <?php echo count($assignedStudents); ?> students</div>
                </div>

                <div class="student-filter-bar">
                    <div class="student-field">
                        <label for="studentSearchInput" class="student-field-label">Search Students</label>
                        <div class="student-input-wrap">
                            <i class="fa-solid fa-magnifying-glass student-input-icon"></i>
                            <input
                                type="text"
                                id="studentSearchInput"
                                class="student-filter-input"
                                placeholder="Name, email, mobile, or course"
                            >
                        </div>
                    </div>

                    <div class="student-field">
                        <label for="studentCollegeFilter" class="student-field-label">Filter by College</label>
                        <div class="student-select-wrap">
                            <i class="fa-solid fa-building-columns student-select-icon"></i>
                            <select id="studentCollegeFilter" class="student-filter-select">
                                <option value="">All Assigned Colleges</option>
                                <?php foreach ($collegeFilterNames as $collegeName): ?>
                                    <option value="<?php echo esc(mb_strtolower($collegeName)); ?>"><?php echo esc($collegeName); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <i class="fa-solid fa-chevron-down student-select-caret"></i>
                        </div>
                    </div>
                    
                    <div class="student-field">
                        <label for="studentYearFilter" class="student-field-label">Filter by Year</label>
                        <div class="student-select-wrap">
                            <i class="fa-solid fa-calendar-alt student-select-icon"></i>
                            <select id="studentYearFilter" class="student-filter-select">
                                <option value="">All Years</option>
                                <option value="2024">2024</option>
                                <option value="2025">2025</option>
                                <option value="2026">2026</option>
                                <option value="2027">2027</option>
                                <option value="2028">2028</option>
                            </select>
                            <i class="fa-solid fa-chevron-down student-select-caret"></i>
                        </div>
                    </div>

                    <div class="student-field">
                        <label for="studentSemesterFilter" class="student-field-label">Filter by Semester</label>
                        <div class="student-select-wrap">
                            <i class="fa-solid fa-book student-select-icon"></i>
                            <select id="studentSemesterFilter" class="student-filter-select">
                                <option value="">All Semesters</option>
                                <option value="Odd">Odd</option>
                                <option value="Even">Even</option>
                            </select>
                            <i class="fa-solid fa-chevron-down student-select-caret"></i>
                        </div>
                    </div>
                </div>

                <div class="student-filter-actions">
                    <button type="button" class="student-filter-clear" id="studentFilterClearBtn">
                        <i class="fa-solid fa-rotate-left"></i> Clear Filters
                    </button>
                </div>
            </div>

            <?php if (!empty($assignedStudents)): ?>

            <div class="table-card student-table-wrap">
                <div class="table-wrap">
                    <table class="table student-table">
                        <thead>
                            <tr>
                                <th>Student</th>
                                <th>College</th>
                                <th>Course</th>
                                <th>Fees Summary</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($assignedStudents as $student): ?>
                                <?php
                                    $studentId = (int)$student['id'];
                                    $studentName = trim((string)$student['first_name'] . ' ' . (string)$student['middle_name'] . ' ' . (string)$student['last_name']);
                                    $viewData = $assignedStudentsView[$studentId] ?? [];
                                    $totalFee = (float)($viewData['total_fee'] ?? 0);
                                    $paidFee = (float)($viewData['paid_fee'] ?? 0);
                                    $pendingFee = (float)($viewData['pending_fee'] ?? 0);
                                    $collegeName = (string)($student['college_name'] ?? '');
                                    $searchBlob = strtolower(trim(
                                        $studentName . ' ' .
                                        (string)($student['email'] ?? '') . ' ' .
                                        (string)($student['mobile_no'] ?? '') . ' ' .
                                        (string)($student['course_name'] ?? '') . ' ' .
                                        $collegeName
                                    ));
                                ?>
                                <?php
                                    $studentYear = (string)($student['academic_year'] ?? 'Unknown');
                                    $studentSemester = (string)($student['semester'] ?? 'Unknown');
                                ?>
                                <tr class="student-row" data-college="<?php echo esc(strtolower($collegeName)); ?>" data-search="<?php echo esc($searchBlob); ?>" data-year="<?php echo esc(strtolower($studentYear)); ?>" data-semester="<?php echo esc(strtolower($studentSemester)); ?>">
                                    <td><?php echo esc($studentName); ?></td>
                                    <td><?php echo esc((string)($student['college_name'] ?? '')); ?></td>
                                    <td><?php echo esc((string)($student['course_name'] ?? '')); ?></td>
                                    <td>
                                        <div style="display:flex;gap:6px;flex-wrap:wrap;">
                                            <span class="fee-chip total">Total ₹<?php echo number_format($totalFee, 2); ?></span>
                                            <span class="fee-chip paid">Paid ₹<?php echo number_format($paidFee, 2); ?></span>
                                            <span class="fee-chip pending">Pending ₹<?php echo number_format($pendingFee, 2); ?></span>
                                        </div>
                                    </td>
                                    <td>
                                        <button
                                            type="button"
                                            class="btn-view-student"
                                            data-student="<?php echo esc(json_encode($viewData, JSON_UNESCAPED_UNICODE)); ?>"
                                        >
                                            <i class="fa-solid fa-eye"></i> View
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="student-cards">
                <?php foreach ($assignedStudents as $student): ?>
                    <?php
                        $studentId = (int)$student['id'];
                        $studentName = trim((string)$student['first_name'] . ' ' . (string)$student['middle_name'] . ' ' . (string)$student['last_name']);
                        $viewData = $assignedStudentsView[$studentId] ?? [];
                        $totalFee = (float)($viewData['total_fee'] ?? 0);
                        $paidFee = (float)($viewData['paid_fee'] ?? 0);
                        $pendingFee = (float)($viewData['pending_fee'] ?? 0);
                        $collegeName = (string)($student['college_name'] ?? '');
                        $searchBlob = strtolower(trim(
                            $studentName . ' ' .
                            (string)($student['email'] ?? '') . ' ' .
                            (string)($student['mobile_no'] ?? '') . ' ' .
                            (string)($student['course_name'] ?? '') . ' ' .
                            $collegeName
                        ));
                    ?>
                    <?php
                        $studentYear = (string)($student['academic_year'] ?? 'Unknown');
                        $studentSemester = (string)($student['semester'] ?? 'Unknown');
                    ?>
                    <div class="student-card student-card-item" data-college="<?php echo esc(strtolower($collegeName)); ?>" data-search="<?php echo esc($searchBlob); ?>" data-year="<?php echo esc(strtolower($studentYear)); ?>" data-semester="<?php echo esc(strtolower($studentSemester)); ?>">
                        <h4><?php echo esc($studentName); ?></h4>
                        <div class="student-meta">
                            <div><strong>College</strong><?php echo esc((string)($student['college_name'] ?? '')); ?></div>
                            <div><strong>Course</strong><?php echo esc((string)($student['course_name'] ?? '')); ?></div>
                            <div><strong>Year</strong><?php echo esc($studentYear); ?></div>
                            <div><strong>Semester</strong><?php echo esc($studentSemester); ?></div>
                            <div style="grid-column:1 / -1;display:flex;gap:6px;flex-wrap:wrap;margin-top:2px;">
                                <span class="fee-chip total">Total ₹<?php echo number_format($totalFee, 2); ?></span>
                                <span class="fee-chip paid">Paid ₹<?php echo number_format($paidFee, 2); ?></span>
                                <span class="fee-chip pending">Pending ₹<?php echo number_format($pendingFee, 2); ?></span>
                            </div>
                        </div>
                        <div style="margin-top:10px;">
                            <button
                                type="button"
                                class="btn-view-student"
                                data-student="<?php echo esc(json_encode($viewData, JSON_UNESCAPED_UNICODE)); ?>"
                            >
                                <i class="fa-solid fa-eye"></i> View
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="student-filter-empty" id="studentFilterEmpty">No students match your search/filter.</div>
        <?php else: ?>
            <div class="empty-box">No students found for your assigned colleges yet.</div>
        <?php endif; ?>
    </div>

    <div id="section-tickets" style="display:none;">
        <div class="page-heading">Tickets</div>
        <p class="page-sub">Student issues raised for your assigned colleges.</p>

        <div class="ticket-filter-bar">
            <div class="ticket-field search-field">
                <i class="fa-solid fa-magnifying-glass ticket-icon"></i>
                <input type="search" id="ticketSearchInput" class="ticket-search" placeholder="Search by subject, student, college, email">
            </div>
            <div class="ticket-field">
                <i class="fa-solid fa-filter ticket-icon"></i>
                <select id="ticketStatusFilter" class="ticket-status-filter">
                    <option value="">All Statuses</option>
                    <option value="open">Open</option>
                    <option value="in_progress">In Progress</option>
                    <option value="resolved">Resolved</option>
                </select>
                <i class="fa-solid fa-chevron-down ticket-select-caret"></i>
            </div>
            <div class="ticket-field">
                <i class="fa-solid fa-calendar-alt ticket-icon"></i>
                <select id="ticketYearFilter" class="ticket-status-filter">
                    <option value="">All Years</option>
                    <option value="2025">2025</option>
                    <option value="2026">2026</option>
                    <option value="2027">2027</option>
                </select>
                <i class="fa-solid fa-chevron-down ticket-select-caret"></i>
            </div>
            <div class="ticket-field">
                <i class="fa-solid fa-book ticket-icon"></i>
                <select id="ticketSemesterFilter" class="ticket-status-filter">
                    <option value="">All Semesters</option>
                    <option value="Odd">Odd</option>
                    <option value="Even">Even</option>
                </select>
                <i class="fa-solid fa-chevron-down ticket-select-caret"></i>
            </div>
            <div class="ticket-filter-count" id="ticketVisibleCount">Showing <?php echo count($coordinatorTickets); ?> tickets</div>
            <button type="button" class="ticket-filter-reset" id="ticketResetBtn">
                <i class="fa-solid fa-rotate-left"></i> Reset
            </button>
        </div>

        <?php if (!empty($coordinatorTickets)): ?>
            <div class="tickets-card">
                <div class="tickets-head">Recent Tickets</div>
                <?php foreach ($coordinatorTickets as $ticket): ?>
                    <?php
                        $ticketStatus = (string)($ticket['status'] ?? 'open');
                        $ticketStatusClass = $ticketStatus === 'in_progress' ? 'in-progress' : ($ticketStatus === 'resolved' ? 'resolved' : 'open');
                        $ticketStatusLabel = $ticketStatus === 'in_progress' ? 'In Progress' : ($ticketStatus === 'resolved' ? 'Resolved' : 'Open');
                        $studentName = trim(
                            (string)($ticket['first_name'] ?? '') . ' ' .
                            (string)($ticket['middle_name'] ?? '') . ' ' .
                            (string)($ticket['last_name'] ?? '')
                        );
                        $ticketSearchBlob = strtolower(trim(
                            (string)($ticket['subject'] ?? '') . ' ' .
                            (string)($ticket['message'] ?? '') . ' ' .
                            $studentName . ' ' .
                            (string)($ticket['email'] ?? '') . ' ' .
                            (string)($ticket['college_name'] ?? '')
                        ));
                        $isUnreadTicket = (int)($ticket['is_seen_by_coordinator'] ?? 0) === 0;
                        $ticketYear = (string)($ticket['academic_year'] ?? 'Unknown');
                        $ticketSemester = (string)($ticket['semester'] ?? 'Unknown');
                    ?>
                    <div class="ticket-row <?php echo $isUnreadTicket ? 'is-unread' : ''; ?>" data-ticket-item="1" data-ticket-id="<?php echo (int)($ticket['id'] ?? 0); ?>" data-status="<?php echo esc($ticketStatus); ?>" data-search="<?php echo esc($ticketSearchBlob); ?>" data-year="<?php echo esc(strtolower($ticketYear)); ?>" data-semester="<?php echo esc(strtolower($ticketSemester)); ?>">
                        <div class="ticket-row-top">
                            <div class="ticket-subject"><?php echo esc((string)$ticket['subject']); ?></div>
                            <span class="ticket-status <?php echo esc($ticketStatusClass); ?>"><?php echo esc($ticketStatusLabel); ?></span>
                        </div>
                        <div class="ticket-meta">
                            <?php echo esc($studentName !== '' ? $studentName : 'Student'); ?>
                            (<?php echo esc((string)($ticket['email'] ?? '-')); ?>)
                            • <?php echo esc((string)($ticket['college_name'] ?? '-')); ?>
                            • <?php echo esc((string)($ticket['created_at'] ?? '-')); ?>
                        </div>
                        <div class="ticket-message"><?php echo nl2br(esc((string)$ticket['message'])); ?></div>
                        <?php if ($ticketStatus !== 'resolved'): ?>
                            <div class="ticket-actions">
                                <button type="button" class="ticket-resolve-btn" data-ticket-id="<?php echo (int)($ticket['id'] ?? 0); ?>">
                                    <i class="fa-solid fa-check-circle"></i> Resolve Ticket
                                </button>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="empty-box" id="ticketFilterEmpty" style="display:none;margin-top:12px;">No tickets match your search/filter.</div>
        <?php else: ?>
            <div class="empty-box">No tickets raised by students yet.</div>
        <?php endif; ?>
    </div>
</main>

<div class="student-modal-overlay" id="studentModalOverlay">
    <div class="student-modal" role="dialog" aria-modal="true" aria-labelledby="studentModalTitle">
        <div class="student-modal-head">
            <h3 id="studentModalTitle">Student Details</h3>
            <button type="button" class="student-close" id="studentModalClose"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="student-detail-grid" id="studentDetailGrid"></div>
    </div>
</div>

<div class="college-modal-overlay" id="collegeModalOverlay">
    <div class="college-modal" role="dialog" aria-modal="true" aria-labelledby="collegeModalTitle">
        <div class="college-modal-head">
            <h3 id="collegeModalTitle">College Details</h3>
            <button type="button" class="college-close" id="collegeModalClose"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="college-location-block" id="collegeDetailGrid"></div>
    </div>
</div>

<div class="college-modal-overlay" id="ticketResolveModalOverlay">
    <div class="college-modal" role="dialog" aria-modal="true" aria-labelledby="ticketResolveModalTitle">
        <div class="college-modal-head">
            <h3 id="ticketResolveModalTitle">Resolve Ticket</h3>
            <button type="button" class="college-close" id="ticketResolveModalClose"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div style="padding:0 18px 18px;">
            <form id="ticketResolveForm">
                <div style="margin-bottom:14px;">
                    <label style="display:block;font-size:.76rem;font-weight:700;letter-spacing:.06em;color:var(--text-muted);text-transform:uppercase;margin-bottom:7px;">Resolution Message</label>
                    <textarea id="ticketResolutionMessage" placeholder="Provide details about how the ticket was resolved..." style="width:100%;height:140px;border:1.5px solid var(--border);border-radius:10px;padding:12px 14px;font-size:.88rem;background:var(--surface);color:var(--text);font-family:'DM Sans',sans-serif;resize:vertical;outline:none;" required></textarea>
                </div>
                <div style="display:flex;gap:10px;justify-content:flex-end;">
                    <button type="button" id="ticketResolveCancel" style="padding:10px 16px;border:1px solid var(--border);border-radius:10px;background:var(--surface);font-size:.82rem;font-weight:700;cursor:pointer;color:var(--text);">Cancel</button>
                    <button type="submit" id="ticketResolveSubmit" style="padding:10px 16px;border:none;border-radius:10px;background:var(--accent);color:#fff;font-size:.82rem;font-weight:700;cursor:pointer;">Resolve & Notify</button>
                </div>
                <div id="ticketResolveMessage" style="display:none;margin-top:12px;border-radius:10px;padding:10px 12px;font-size:.8rem;"></div>
            </form>
        </div>
    </div>
</div>

<script>
const sidebar = document.getElementById('sidebar');
const sidebarOverlay = document.getElementById('sidebarOverlay');
const mainContent = document.getElementById('mainContent');
const profileWrap = document.getElementById('profileWrap');
const notificationWrap = document.getElementById('notificationWrap');
const notificationDropdown = document.getElementById('notificationDropdown');
const coordinatorNotificationBtn = document.getElementById('coordinatorNotificationBtn');
const coordinatorNotificationBadge = document.getElementById('coordinatorNotificationBadge');
const isMobile = () => window.innerWidth <= 700;
let hasMarkedTicketsSeen = false;

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

document.addEventListener('click', () => {
    profileWrap.classList.remove('open');
    if (notificationWrap) {
        notificationWrap.classList.remove('open');
    }
});

function closeProfile() { profileWrap.classList.remove('open'); }

const sections = document.querySelectorAll('[id^="section-"]');
const navItems = document.querySelectorAll('.nav-item');
const studentModalOverlay = document.getElementById('studentModalOverlay');
const studentModalClose = document.getElementById('studentModalClose');
const studentDetailGrid = document.getElementById('studentDetailGrid');
const collegeModalOverlay = document.getElementById('collegeModalOverlay');
const collegeModalClose = document.getElementById('collegeModalClose');
const collegeDetailGrid = document.getElementById('collegeDetailGrid');
const studentSearchInput = document.getElementById('studentSearchInput');
const studentCollegeFilter = document.getElementById('studentCollegeFilter');
const studentRows = document.querySelectorAll('.student-row');
const studentCards = document.querySelectorAll('.student-card-item');
const studentFilterEmpty = document.getElementById('studentFilterEmpty');
const studentVisibleCount = document.getElementById('studentVisibleCount');
const studentFilterClearBtn = document.getElementById('studentFilterClearBtn');
const scheduleSessionForm = document.getElementById('scheduleSessionForm');
const scheduleSubmitBtn = document.getElementById('scheduleSubmitBtn');
const scheduleMessage = document.getElementById('scheduleMessage');
const scheduledSessionsTbody = document.getElementById('scheduledSessionsTbody');
const noScheduledSessionsBox = document.getElementById('noScheduledSessionsBox');
const scheduledSessionsTableWrap = document.getElementById('scheduledSessionsTableWrap');
const ticketSearchInput = document.getElementById('ticketSearchInput');
const ticketStatusFilter = document.getElementById('ticketStatusFilter');
const ticketYearFilter = document.getElementById('ticketYearFilter');
const ticketSemesterFilter = document.getElementById('ticketSemesterFilter');
const ticketVisibleCount = document.getElementById('ticketVisibleCount');
const ticketFilterEmpty = document.getElementById('ticketFilterEmpty');
const ticketRows = document.querySelectorAll('[data-ticket-item="1"]');

async function markTicketsSeen() {
    if (hasMarkedTicketsSeen) {
        return;
    }
    hasMarkedTicketsSeen = true;

    try {
        const response = await fetch('tickets_mark_seen.php', { method: 'POST' });
        const data = await response.json();
        if (data && data.ok && coordinatorNotificationBadge) {
            coordinatorNotificationBadge.textContent = String(data.unread ?? 0);
        }
        document.querySelectorAll('.ticket-row.is-unread').forEach((node) => {
            node.classList.remove('is-unread');
        });
    } catch (error) {
        hasMarkedTicketsSeen = false;
    }
}

function formatCurrency(value) {
    const amount = Number(value || 0);
    return '₹' + amount.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function renderStudentDetails(student) {
    if (!studentDetailGrid) {
        return;
    }

    const attendanceMarked = Number(student.attendance_marked || 0);
    const attendancePresent = Number(student.attendance_present || 0);
    const attendanceAbsent = Number(student.attendance_absent || 0);
    const rawPercent = Number(student.attendance_percent || 0);
    const attendancePercent = Math.max(0, Math.min(100, Number.isFinite(rawPercent) ? rawPercent : 0));

    const fields = [
        ['Name', student.name || '-'],
        ['Email', student.email || '-'],
        ['Mobile', student.mobile || '-'],
        ['College', student.college || '-'],
        ['Course', student.course || '-'],
        ['Duration', student.duration || '-'],
        ['State', student.state || '-'],
        ['District', student.district || '-'],
        ['City', student.city || '-'],
        ['Registered On', student.registered_on || '-'],
        ['Total Fees', formatCurrency(student.total_fee)],
        ['Paid Fees', formatCurrency(student.paid_fee)],
        ['Pending Fees', formatCurrency(student.pending_fee)]
    ];

    studentDetailGrid.innerHTML = '';

    const attCard = document.createElement('div');
    attCard.className = 'student-detail-item full att-progress-card';

    const attHead = document.createElement('div');
    attHead.className = 'att-progress-head';
    const attTitle = document.createElement('div');
    attTitle.className = 'att-progress-title';
    attTitle.textContent = 'Attendance Progress';
    const attPercent = document.createElement('div');
    attPercent.className = 'att-progress-percent';
    attPercent.textContent = attendancePercent + '%';
    attHead.appendChild(attTitle);
    attHead.appendChild(attPercent);

    const attTrack = document.createElement('div');
    attTrack.className = 'att-progress-track';
    const attFill = document.createElement('div');
    attFill.className = 'att-progress-fill';
    attFill.style.width = attendancePercent + '%';
    attTrack.appendChild(attFill);

    const attMeta = document.createElement('div');
    attMeta.className = 'att-progress-meta';

    const chipPresent = document.createElement('span');
    chipPresent.className = 'att-progress-chip present';
    chipPresent.textContent = 'Present: ' + attendancePresent;

    const chipAbsent = document.createElement('span');
    chipAbsent.className = 'att-progress-chip absent';
    chipAbsent.textContent = 'Absent: ' + attendanceAbsent;

    const chipTotal = document.createElement('span');
    chipTotal.className = 'att-progress-chip total';
    chipTotal.textContent = 'Marked: ' + attendanceMarked;

    attMeta.appendChild(chipPresent);
    attMeta.appendChild(chipAbsent);
    attMeta.appendChild(chipTotal);

    attCard.appendChild(attHead);
    attCard.appendChild(attTrack);
    attCard.appendChild(attMeta);
    studentDetailGrid.appendChild(attCard);

    fields.forEach(([label, value]) => {
        const item = document.createElement('div');
        item.className = 'student-detail-item';

        const strong = document.createElement('strong');
        strong.textContent = label;
        const span = document.createElement('span');
        span.textContent = value;

        item.appendChild(strong);
        item.appendChild(span);
        studentDetailGrid.appendChild(item);
    });
}

function openStudentModal(studentData) {
    renderStudentDetails(studentData);
    studentModalOverlay.classList.add('show');
}

function closeStudentModal() {
    studentModalOverlay.classList.remove('show');
}

function renderCollegeDetails(college) {
    const fields = [
        ['College Name', college.name || '-', 'fa-building-columns'],
        ['Students', String(college.student_count ?? 0), 'fa-users'],
        ['Country', college.country || '-', 'fa-earth-asia'],
        ['State', college.state || '-', 'fa-map-location-dot'],
        ['District', college.district || '-', 'fa-map-pin'],
        ['City', college.city || '-', 'fa-city']
    ];

    collegeDetailGrid.innerHTML = fields.map(([label, value, icon]) => (
        '<div class="college-location-item"><i class="fa-solid ' + icon + '"></i><div><strong>' + label + '</strong><span>' + value + '</span></div></div>'
    )).join('');
}

function openCollegeModal(collegeData) {
    renderCollegeDetails(collegeData);
    collegeModalOverlay.classList.add('show');
}

function closeCollegeModal() {
    collegeModalOverlay.classList.remove('show');
}

function showScheduleMessage(text, type) {
    if (!scheduleMessage) {
        return;
    }
    scheduleMessage.textContent = text;
    scheduleMessage.className = 'schedule-message show ' + type;
}

function applyTicketFilters() {
    if (!ticketRows.length) {
        if (ticketVisibleCount) {
            ticketVisibleCount.textContent = 'Showing 0 tickets';
        }
        return;
    }

    const query = (ticketSearchInput && ticketSearchInput.value ? ticketSearchInput.value : '').trim().toLowerCase();
    const selectedStatus = (ticketStatusFilter && ticketStatusFilter.value ? ticketStatusFilter.value : '').trim().toLowerCase();
    const selectedYear = (ticketYearFilter && ticketYearFilter.value ? ticketYearFilter.value : '').trim().toLowerCase();
    const selectedSemester = (ticketSemesterFilter && ticketSemesterFilter.value ? ticketSemesterFilter.value : '').trim().toLowerCase();

    let visibleCount = 0;
    ticketRows.forEach((row) => {
        const rowStatus = String(row.getAttribute('data-status') || '').toLowerCase();
        const rowSearch = String(row.getAttribute('data-search') || '').toLowerCase();
        const rowYear = String(row.getAttribute('data-year') || '').toLowerCase();
        const rowSemester = String(row.getAttribute('data-semester') || '').toLowerCase();
        
        const matchesStatus = selectedStatus === '' || rowStatus === selectedStatus;
        const matchesYear = selectedYear === '' || rowYear === selectedYear;
        const matchesSemester = selectedSemester === '' || rowSemester === selectedSemester;
        const matchesQuery = query === '' || rowSearch.includes(query);
        const visible = matchesStatus && matchesYear && matchesSemester && matchesQuery;
        row.style.display = visible ? '' : 'none';
        if (visible) {
            visibleCount += 1;
        }
    });

    if (ticketVisibleCount) {
        ticketVisibleCount.textContent = 'Showing ' + visibleCount + ' ticket' + (visibleCount === 1 ? '' : 's');
    }
    if (ticketFilterEmpty) {
        ticketFilterEmpty.style.display = (visibleCount === 0) ? '' : 'none';
    }
}

function applyStudentFilters() {
    if (!studentSearchInput || !studentCollegeFilter) {
        return;
    }

    const query = (studentSearchInput.value || '').toLowerCase().trim();
    const selectedCollege = (studentCollegeFilter.value || '').toLowerCase().trim();
    const selectedYear = (document.getElementById('studentYearFilter')?.value || '').toLowerCase().trim();
    const selectedSemester = (document.getElementById('studentSemesterFilter')?.value || '').toLowerCase().trim();

    let visibleCount = 0;

    studentRows.forEach(row => {
        const rowCollege = (row.dataset.college || '').toLowerCase();
        const rowSearch = (row.dataset.search || '').toLowerCase();
        const rowYear = (row.dataset.year || '').toLowerCase();
        const rowSemester = (row.dataset.semester || '').toLowerCase();
        
        const matchesCollege = selectedCollege === '' || rowCollege === selectedCollege;
        const matchesYear = selectedYear === '' || rowYear === selectedYear;
        const matchesSemester = selectedSemester === '' || rowSemester === selectedSemester;
        const matchesQuery = query === '' || rowSearch.includes(query);
        
        const visible = matchesCollege && matchesYear && matchesSemester && matchesQuery;
        row.style.display = visible ? '' : 'none';
        if (visible) {
            visibleCount += 1;
        }
    });

    studentCards.forEach(card => {
        const cardCollege = (card.dataset.college || '').toLowerCase();
        const cardSearch = (card.dataset.search || '').toLowerCase();
        const cardYear = (card.dataset.year || '').toLowerCase();
        const cardSemester = (card.dataset.semester || '').toLowerCase();

        const matchesCollege = selectedCollege === '' || cardCollege === selectedCollege;
        const matchesYear = selectedYear === '' || cardYear === selectedYear;
        const matchesSemester = selectedSemester === '' || cardSemester === selectedSemester;
        const matchesQuery = query === '' || cardSearch.includes(query);
        
        const visible = matchesCollege && matchesYear && matchesSemester && matchesQuery;
        card.style.display = visible ? '' : 'none';
    });

    if (studentVisibleCount) {
        studentVisibleCount.textContent = 'Showing ' + visibleCount + ' student' + (visibleCount === 1 ? '' : 's');
    }

    if (studentFilterEmpty) {
        studentFilterEmpty.classList.toggle('show', visibleCount === 0 && (query !== '' || selectedCollege !== '' || selectedYear !== '' || selectedSemester !== ''));
    }
}

function showSection(name, updateHash = true) {
    if (updateHash) {
        window.location.hash = name;
    }
    // Persist to localStorage so refresh stays on same page
    try { localStorage.setItem('coord_active_section', name); } catch(e) {}
    sections.forEach(section => section.style.display = 'none');
    const target = document.getElementById('section-' + name);
    if (target) target.style.display = 'block';
    if (notificationWrap) {
        notificationWrap.classList.remove('open');
    }

    if (name === 'tickets') {
        markTicketsSeen();
    }

    navItems.forEach(item => {
        item.classList.toggle('active', item.getAttribute('onclick') && item.getAttribute('onclick').includes("'" + name + "'"));
    });

    if (isMobile()) {
        sidebar.classList.remove('open');
        sidebarOverlay.classList.remove('visible');
    }
}

if (coordinatorNotificationBtn) {
    coordinatorNotificationBtn.addEventListener('click', (event) => {
        event.stopPropagation();
        if (notificationWrap) {
            notificationWrap.classList.toggle('open');
            profileWrap.classList.remove('open');
        } else {
            showSection('tickets');
        }
    });
}

if (notificationDropdown) {
    notificationDropdown.addEventListener('click', (event) => {
        const clickable = event.target.closest('[data-open-tickets="1"]');
        if (clickable) {
            showSection('tickets');
            if (notificationWrap) {
                notificationWrap.classList.remove('open');
            }
        }
    });
}

document.querySelectorAll('.btn-view-student').forEach(button => {
    button.addEventListener('click', () => {
        const rawData = button.getAttribute('data-student') || '{}';
        let parsed = {};
        try {
            parsed = JSON.parse(rawData);
        } catch (error) {
            parsed = {};
        }
        openStudentModal(parsed);
    });
});

document.querySelectorAll('.college-view-btn').forEach(button => {
    button.addEventListener('click', () => {
        const rawData = button.getAttribute('data-college') || '{}';
        let parsed = {};
        try {
            parsed = JSON.parse(rawData);
        } catch (error) {
            parsed = {};
        }
        openCollegeModal(parsed);
    });
});

studentModalClose.addEventListener('click', closeStudentModal);
studentModalOverlay.addEventListener('click', (event) => {
    if (event.target === studentModalOverlay) {
        closeStudentModal();
    }
});

collegeModalClose.addEventListener('click', closeCollegeModal);
collegeModalOverlay.addEventListener('click', (event) => {
    if (event.target === collegeModalOverlay) {
        closeCollegeModal();
    }
});

document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && studentModalOverlay.classList.contains('show')) {
        closeStudentModal();
    }
    if (event.key === 'Escape' && collegeModalOverlay.classList.contains('show')) {
        closeCollegeModal();
    }
});

if (studentSearchInput) {
    studentSearchInput.addEventListener('input', applyStudentFilters);
}

if (studentCollegeFilter) {
    studentCollegeFilter.addEventListener('change', applyStudentFilters);
}

const yearFilter = document.getElementById('studentYearFilter');
if (yearFilter) yearFilter.addEventListener('change', applyStudentFilters);

const semesterFilter = document.getElementById('studentSemesterFilter');
if (semesterFilter) semesterFilter.addEventListener('change', applyStudentFilters);

if (studentFilterClearBtn) {
    studentFilterClearBtn.addEventListener('click', () => {
        if (studentSearchInput) studentSearchInput.value = '';
        if (studentCollegeFilter) studentCollegeFilter.value = '';
        if (yearFilter) yearFilter.value = '';
        if (semesterFilter) semesterFilter.value = '';
        applyStudentFilters();
        if (studentSearchInput) studentSearchInput.focus();
    });
}

applyStudentFilters();

if (ticketSearchInput) {
    ticketSearchInput.addEventListener('input', applyTicketFilters);
}

if (ticketStatusFilter) {
    ticketStatusFilter.addEventListener('change', applyTicketFilters);
}
if (ticketYearFilter) {
    ticketYearFilter.addEventListener('change', applyTicketFilters);
}
if (ticketSemesterFilter) {
    ticketSemesterFilter.addEventListener('change', applyTicketFilters);
}

const ticketResetBtn = document.getElementById('ticketResetBtn');
if (ticketResetBtn) {
    ticketResetBtn.addEventListener('click', () => {
        if (ticketSearchInput) ticketSearchInput.value = '';
        if (ticketStatusFilter) ticketStatusFilter.value = '';
        if (ticketYearFilter) ticketYearFilter.value = '';
        if (ticketSemesterFilter) ticketSemesterFilter.value = '';
        applyTicketFilters();
    });
}

applyTicketFilters();

if (scheduleSessionForm && scheduleSubmitBtn) {
    const dateInput = document.getElementById('schedule_session_date');
    if (dateInput) {
        const today = new Date();
        const yyyy = today.getFullYear();
        const mm = String(today.getMonth() + 1).padStart(2, '0');
        const dd = String(today.getDate()).padStart(2, '0');
        dateInput.min = `${yyyy}-${mm}-${dd}`;
    }

    scheduleSessionForm.addEventListener('submit', async (event) => {
        event.preventDefault();

        const collegeSelect = document.getElementById('schedule_college_id');
        const sessionDateInput = document.getElementById('schedule_session_date');
        const sessionTypeSelect = document.getElementById('schedule_session_type');
        const detailsInput = document.getElementById('schedule_session_details');
        const notesInput = document.getElementById('schedule_notes');

        const collegeId = Number((collegeSelect && collegeSelect.value) || 0);
        const sessionDate = (sessionDateInput && sessionDateInput.value) ? sessionDateInput.value : '';
        const sessionType = (sessionTypeSelect && sessionTypeSelect.value) ? sessionTypeSelect.value : 'Class';
        const sessionDetails = (detailsInput && detailsInput.value) ? detailsInput.value.trim() : '';
        const notes = (notesInput && notesInput.value) ? notesInput.value.trim() : '';

        if (!collegeId) {
            showScheduleMessage('Please select an assigned college.', 'error');
            return;
        }
        if (!sessionDate) {
            showScheduleMessage('Please select an activity date.', 'error');
            return;
        }
        if (sessionDetails.length < 5) {
            showScheduleMessage('Please enter detailed activity information (minimum 5 characters).', 'error');
            return;
        }

        scheduleSubmitBtn.disabled = true;
        showScheduleMessage('Scheduling activity...', 'success');

        try {
            const response = await fetch('schedule_session.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    college_id: collegeId,
                    session_date: sessionDate,
                    session_type: sessionType,
                    session_details: sessionDetails,
                    notes: notes
                })
            });

            const result = await response.json();
            if (!result.ok) {
                throw new Error(result.error || 'Unable to schedule this activity.');
            }

            const scheduled = result.scheduled || {};
            const notifiedStudents = Number(result.notified_students || 0);
            showScheduleMessage(`Activity scheduled successfully. Notification sent to ${notifiedStudents} student(s).`, 'success');

            if (scheduledSessionsTbody) {
                const row = document.createElement('tr');
                const tdDate = document.createElement('td');
                const tdCollege = document.createElement('td');
                const tdType = document.createElement('td');
                const tdDetails = document.createElement('td');
                const tdCreated = document.createElement('td');

                tdDate.textContent = scheduled.session_date || '-';
                tdCollege.textContent = scheduled.college_name || '-';
                tdType.textContent = sessionType || 'Class';
                tdDetails.textContent = scheduled.session_details || '-';
                tdCreated.textContent = scheduled.created_at || '-';

                row.appendChild(tdDate);
                row.appendChild(tdCollege);
                row.appendChild(tdType);
                row.appendChild(tdDetails);
                row.appendChild(tdCreated);
                scheduledSessionsTbody.prepend(row);
            }

            if (noScheduledSessionsBox) {
                noScheduledSessionsBox.style.display = 'none';
            }
            if (scheduledSessionsTableWrap) {
                scheduledSessionsTableWrap.style.display = '';
            }

            if (detailsInput) {
                detailsInput.value = '';
            }
            if (notesInput) {
                notesInput.value = '';
            }
            if (sessionTypeSelect) {
                sessionTypeSelect.value = 'Class';
            }
            if (sessionDateInput) {
                sessionDateInput.value = '';
            }
        } catch (error) {
            showScheduleMessage(error.message || 'Unable to schedule activity right now.', 'error');
        } finally {
            scheduleSubmitBtn.disabled = false;
        }
    });
}

// ── Attendance ──────────────────────────────────────────────
const SESSIONS_BY_COLLEGE = <?php echo json_encode($sessionsByCollegeId, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
const STUDENTS_BY_COLLEGE = <?php echo json_encode($studentsByCollegeId, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

const attCollegeSelect  = document.getElementById('attCollegeSelect');
const attSessionSelect  = document.getElementById('attSessionSelect');
const attStudentSection = document.getElementById('attStudentSection');
const attStudentList    = document.getElementById('attStudentList');
const attSelectAll      = document.getElementById('attSelectAll');
const attSelectedCount  = document.getElementById('attSelectedCount');
const attSubmitBtn      = document.getElementById('attSubmitBtn');
const attMessage        = document.getElementById('attMessage');
const attStatusBadge    = document.getElementById('attStatusBadge');
const attEditBtn        = document.getElementById('attEditBtn');

let attendanceLocked = false;

function setAttendanceEditable(isEditable) {
    attendanceLocked = !isEditable;
    if (attSelectAll) {
        attSelectAll.disabled = !isEditable;
    }
    if (attStudentList) {
        attStudentList.querySelectorAll('.att-student-chk').forEach(chk => {
            chk.disabled = !isEditable;
        });
    }
    if (attSubmitBtn) {
        attSubmitBtn.style.display = isEditable ? '' : 'none';
    }
}

function applyAttendanceRecords(records) {
    if (!attStudentList || !Array.isArray(records)) {
        return;
    }
    const statusByStudent = {};
    records.forEach(rec => {
        const spid = Number(rec.student_profile_id || 0);
        if (spid > 0) {
            statusByStudent[spid] = rec.status === 'present' ? 'present' : 'absent';
        }
    });

    attStudentList.querySelectorAll('.att-student-row').forEach(row => {
        const spid = Number(row.dataset.id || 0);
        const chk = row.querySelector('.att-student-chk');
        const badge = row.querySelector('.att-badge');
        if (!chk) {
            return;
        }
        const isPresent = statusByStudent[spid] === 'present';
        chk.checked = isPresent;
        row.classList.toggle('checked', isPresent);
        setAttBadge(badge, chk);
    });
    updateAttCount();
}

async function loadExistingAttendance(sessionId) {
    if (!sessionId) {
        return;
    }
    try {
        const res = await fetch('get_attendance.php?session_id=' + encodeURIComponent(String(sessionId)));
        const result = await res.json();
        if (!result.ok) {
            throw new Error(result.error || 'Unable to load attendance.');
        }

        if (result.exists) {
            applyAttendanceRecords(result.records || []);
            setAttendanceEditable(false);
            if (attEditBtn) {
                attEditBtn.classList.add('show');
            }
            if (attStatusBadge) {
                attStatusBadge.className = 'att-status completed show';
                attStatusBadge.textContent = 'Attendance Completed';
            }
            if (attMessage) {
                const present = Number(result.present || 0);
                const total = Number(result.total || 0);
                attMessage.textContent = 'Attendance already submitted (' + present + ' present / ' + total + '). Click Edit Attendance to update.';
                attMessage.className = 'schedule-message show success';
            }
        } else {
            setAttendanceEditable(true);
            if (attEditBtn) {
                attEditBtn.classList.remove('show');
            }
            if (attStatusBadge) {
                attStatusBadge.className = 'att-status';
                attStatusBadge.textContent = 'Attendance Completed';
            }
            if (attMessage) {
                attMessage.className = 'schedule-message';
                attMessage.textContent = '';
            }
        }
    } catch (error) {
        setAttendanceEditable(true);
        if (attEditBtn) {
            attEditBtn.classList.remove('show');
        }
        if (attStatusBadge) {
            attStatusBadge.className = 'att-status';
            attStatusBadge.textContent = 'Attendance Completed';
        }
        if (attMessage) {
            attMessage.textContent = error.message || 'Unable to load attendance.';
            attMessage.className = 'schedule-message show error';
        }
    }
}

function updateAttCount() {
    if (!attStudentList) return;
    const checked = attStudentList.querySelectorAll('.att-student-chk:checked').length;
    const total   = attStudentList.querySelectorAll('.att-student-chk').length;
    if (attSelectedCount) attSelectedCount.textContent = checked + ' of ' + total + ' marked present';
    if (attSelectAll) {
        attSelectAll.checked       = total > 0 && checked === total;
        attSelectAll.indeterminate = checked > 0 && checked < total;
    }
}

function setAttBadge(badge, chk) {
    if (!badge) return;
    badge.className  = chk.checked ? 'att-badge present' : 'att-badge absent';
    badge.textContent = chk.checked ? 'Present' : 'Absent';
}

function renderAttStudents(students) {
    if (!attStudentList) return;
    attStudentList.innerHTML = '';
    if (!students || students.length === 0) {
        const empty = document.createElement('div');
        empty.className = 'empty-box';
        empty.textContent = 'No students found for this college.';
        attStudentList.appendChild(empty);
        return;
    }
    students.forEach(s => {
        const row = document.createElement('div');
        row.className = 'att-student-row';
        row.dataset.id = String(s.id);

        const chk = document.createElement('input');
        chk.type = 'checkbox';
        chk.className = 'att-student-chk';
        chk.value = String(s.id);

        const info = document.createElement('div');
        info.className = 'att-student-info';
        const nameEl = document.createElement('div');
        nameEl.className = 'att-student-name';
        nameEl.textContent = s.name || 'Unknown';
        const subEl = document.createElement('div');
        subEl.className = 'att-student-sub';
        subEl.textContent = s.email || '';
        info.appendChild(nameEl);
        info.appendChild(subEl);

        const badge = document.createElement('span');
        badge.className = 'att-badge absent';
        badge.textContent = 'Absent';

        chk.addEventListener('change', () => {
            row.classList.toggle('checked', chk.checked);
            setAttBadge(badge, chk);
            updateAttCount();
        });

        row.appendChild(chk);
        row.appendChild(info);
        row.appendChild(badge);
        attStudentList.appendChild(row);
    });
    updateAttCount();
}

if (attCollegeSelect) {
    attCollegeSelect.addEventListener('change', () => {
        const cid = Number(attCollegeSelect.value);
        if (attSessionSelect) {
            attSessionSelect.innerHTML = '<option value="">— Choose Session Date —</option>';
            attSessionSelect.disabled = true;
        }
        if (attStudentSection) attStudentSection.style.display = 'none';
        if (!cid) return;
        const sessions = SESSIONS_BY_COLLEGE[cid] || [];
        if (sessions.length === 0) {
            const opt = document.createElement('option');
            opt.value = '';
            opt.textContent = 'No sessions scheduled for this college';
            if (attSessionSelect) attSessionSelect.appendChild(opt);
            return;
        }
        sessions.forEach(sess => {
            const opt = document.createElement('option');
            opt.value = String(sess.id);
            opt.textContent = sess.session_date + (sess.session_details ? ' — ' + sess.session_details : '');
            if (attSessionSelect) attSessionSelect.appendChild(opt);
        });
        if (attSessionSelect) { attSessionSelect.disabled = false; attSessionSelect.value = ''; }
    });
}

if (attSessionSelect) {
    attSessionSelect.addEventListener('change', async () => {
        const sessionId = Number(attSessionSelect.value);
        if (!sessionId) { if (attStudentSection) attStudentSection.style.display = 'none'; return; }
        const cid = Number(attCollegeSelect ? attCollegeSelect.value : 0);
        renderAttStudents(STUDENTS_BY_COLLEGE[cid] || []);
        if (attStudentSection) attStudentSection.style.display = '';
        if (attSelectAll) { attSelectAll.checked = false; attSelectAll.indeterminate = false; }
        if (attEditBtn) { attEditBtn.classList.remove('show'); }
        if (attStatusBadge) { attStatusBadge.className = 'att-status'; attStatusBadge.textContent = 'Attendance Completed'; }
        if (attMessage) { attMessage.className = 'schedule-message'; attMessage.textContent = ''; }
        await loadExistingAttendance(sessionId);
    });
}

if (attSelectAll) {
    attSelectAll.addEventListener('change', () => {
        if (!attStudentList) return;
        attStudentList.querySelectorAll('.att-student-row').forEach(row => {
            const chk   = row.querySelector('.att-student-chk');
            const badge = row.querySelector('.att-badge');
            if (chk) {
                chk.checked = attSelectAll.checked;
                row.classList.toggle('checked', chk.checked);
                setAttBadge(badge, chk);
            }
        });
        updateAttCount();
    });
}

if (attSubmitBtn) {
    attSubmitBtn.addEventListener('click', async () => {
        if (!attSessionSelect || !attStudentList) return;
        const sessionId = Number(attSessionSelect.value);
        if (!sessionId) {
            if (attMessage) { attMessage.textContent = 'Please select a session first.'; attMessage.className = 'schedule-message show error'; }
            return;
        }
        const rows = attStudentList.querySelectorAll('.att-student-row');
        if (rows.length === 0) {
            if (attMessage) { attMessage.textContent = 'No students to submit.'; attMessage.className = 'schedule-message show error'; }
            return;
        }
        const records = [];
        rows.forEach(row => {
            const chk  = row.querySelector('.att-student-chk');
            const spid = Number(row.dataset.id || 0);
            if (spid > 0) records.push({ student_profile_id: spid, status: (chk && chk.checked) ? 'present' : 'absent' });
        });

        attSubmitBtn.disabled = true;
        if (attMessage) { attMessage.textContent = 'Saving attendance\u2026'; attMessage.className = 'schedule-message show success'; }

        try {
            const res    = await fetch('save_attendance.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ session_id: sessionId, records }) });
            const result = await res.json();
            if (!result.ok) throw new Error(result.error || 'Failed to save.');
            setAttendanceEditable(false);
            if (attEditBtn) { attEditBtn.classList.add('show'); }
            if (attStatusBadge) {
                attStatusBadge.className = 'att-status completed show';
                attStatusBadge.textContent = 'Attendance Completed';
            }
            if (attMessage) { attMessage.textContent = 'Attendance saved for ' + (result.saved || 0) + ' student(s).'; attMessage.className = 'schedule-message show success'; }
        } catch (err) {
            if (attMessage) { attMessage.textContent = err.message || 'Error saving attendance.'; attMessage.className = 'schedule-message show error'; }
        } finally {
            attSubmitBtn.disabled = false;
        }
    });
}

if (attEditBtn) {
    attEditBtn.addEventListener('click', () => {
        setAttendanceEditable(true);
        if (attStatusBadge) {
            attStatusBadge.className = 'att-status editing show';
            attStatusBadge.textContent = 'Editing Attendance';
        }
        if (attMessage) {
            attMessage.textContent = 'Edit mode enabled. Update attendance and submit again.';
            attMessage.className = 'schedule-message show success';
        }
        attEditBtn.classList.remove('show');
    });
}

// ── Ticket Resolution ───────────────────────────────────────
const ticketResolveModalOverlay = document.getElementById('ticketResolveModalOverlay');
const ticketResolveForm = document.getElementById('ticketResolveForm');
const ticketResolutionMessage = document.getElementById('ticketResolutionMessage');
const ticketResolveMessage = document.getElementById('ticketResolveMessage');
const ticketResolveSubmit = document.getElementById('ticketResolveSubmit');
const ticketResolveCancel = document.getElementById('ticketResolveCancel');
const ticketResolveModalClose = document.getElementById('ticketResolveModalClose');
let currentResolveTicketId = null;

function openTicketResolveModal(ticketId) {
    currentResolveTicketId = ticketId;
    if (ticketResolutionMessage) {
        ticketResolutionMessage.value = '';
    }
    if (ticketResolveMessage) {
        ticketResolveMessage.style.display = 'none';
        ticketResolveMessage.textContent = '';
        ticketResolveMessage.className = '';
    }
    if (ticketResolveModalOverlay) {
        ticketResolveModalOverlay.classList.add('show');
    }
}

function closeTicketResolveModal() {
    currentResolveTicketId = null;
    if (ticketResolveModalOverlay) {
        ticketResolveModalOverlay.classList.remove('show');
    }
    if (ticketResolveForm) {
        ticketResolveForm.reset();
    }
}

if (ticketResolveModalClose) {
    ticketResolveModalClose.addEventListener('click', closeTicketResolveModal);
}

if (ticketResolveCancel) {
    ticketResolveCancel.addEventListener('click', closeTicketResolveModal);
}

if (ticketResolveModalOverlay) {
    ticketResolveModalOverlay.addEventListener('click', (event) => {
        if (event.target === ticketResolveModalOverlay) {
            closeTicketResolveModal();
        }
    });
}

if (ticketResolveForm) {
    ticketResolveForm.addEventListener('submit', async (event) => {
        event.preventDefault();

        if (!currentResolveTicketId) {
            if (ticketResolveMessage) {
                ticketResolveMessage.textContent = 'Error: Ticket ID not found.';
                ticketResolveMessage.className = 'schedule-message show error';
                ticketResolveMessage.style.display = 'block';
            }
            return;
        }

        const message = (ticketResolutionMessage && ticketResolutionMessage.value ? ticketResolutionMessage.value.trim() : '');
        if (!message || message.length < 5) {
            if (ticketResolveMessage) {
                ticketResolveMessage.textContent = 'Please provide a resolution message (minimum 5 characters).';
                ticketResolveMessage.className = 'schedule-message show error';
                ticketResolveMessage.style.display = 'block';
            }
            return;
        }

        if (ticketResolveSubmit) {
            ticketResolveSubmit.disabled = true;
        }

        if (ticketResolveMessage) {
            ticketResolveMessage.textContent = 'Resolving ticket...';
            ticketResolveMessage.className = 'schedule-message show success';
            ticketResolveMessage.style.display = 'block';
        }

        try {
            const response = await fetch('ticket_resolve.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    ticket_id: currentResolveTicketId,
                    resolution_message: message
                })
            });

            const result = await response.json();
            if (!result.ok) {
                throw new Error(result.error || 'Unable to resolve ticket.');
            }

            if (ticketResolveMessage) {
                ticketResolveMessage.textContent = 'Ticket resolved successfully! Student has been notified.';
                ticketResolveMessage.className = 'schedule-message show success';
                ticketResolveMessage.style.display = 'block';
            }

            // Update the ticket row UI
            const ticketRow = document.querySelector(`[data-ticket-id="${currentResolveTicketId}"]`);
            if (ticketRow) {
                // Update status badge
                const statusBadge = ticketRow.querySelector('.ticket-status');
                if (statusBadge) {
                    statusBadge.className = 'ticket-status resolved';
                    statusBadge.textContent = 'Resolved';
                }

                // Update data status
                ticketRow.setAttribute('data-status', 'resolved');

                // Remove actions if present
                const actions = ticketRow.querySelector('.ticket-actions');
                if (actions) {
                    actions.style.display = 'none';
                }
            }

            setTimeout(() => {
                closeTicketResolveModal();
                applyTicketFilters();
            }, 1500);
        } catch (error) {
            if (ticketResolveMessage) {
                ticketResolveMessage.textContent = error.message || 'Unable to resolve ticket right now.';
                ticketResolveMessage.className = 'schedule-message show error';
                ticketResolveMessage.style.display = 'block';
            }
        } finally {
            if (ticketResolveSubmit) {
                ticketResolveSubmit.disabled = false;
            }
        }
    });
}

// Add event listeners to all resolve buttons
document.querySelectorAll('.ticket-resolve-btn').forEach(button => {
    button.addEventListener('click', () => {
        const ticketId = Number(button.getAttribute('data-ticket-id') || 0);
        if (ticketId > 0) {
            openTicketResolveModal(ticketId);
        }
    });
});

document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && ticketResolveModalOverlay && ticketResolveModalOverlay.classList.contains('show')) {
        closeTicketResolveModal();
    }
});

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
            const saved = localStorage.getItem('coord_active_section');
            if (saved && document.getElementById('section-' + saved)) {
                showSection(saved, false);
            }
        } catch(e) {}
    }
});
</script>
</body>
</html>
