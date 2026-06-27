<?php
declare(strict_types=1);

session_start();
header('Content-Type: application/json');

$user = $_SESSION['user'] ?? null;
if (!$user || ($user['role'] ?? '') !== 'admin') {
    echo json_encode(['ok' => false, 'error' => 'Unauthorized.']);
    exit;
}

$profileId = (int)($_GET['profile_id'] ?? 0);
if ($profileId <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Invalid student profile id.']);
    exit;
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';

function parseFeeAmount(string $feeText): float
{
    $numeric = preg_replace('/[^0-9.]/', '', $feeText) ?? '0';
    return (float)$numeric;
}

$conn = getDbConnection();
if ($conn === null) {
    echo json_encode(['ok' => false, 'error' => 'Database connection failed.']);
    exit;
}

$studentSql = "SELECT
    sp.id AS profile_id,
    u.full_name,
    u.login_id,
    sp.email,
    sp.mobile_no,
    sp.state,
    sp.district,
    sp.city,
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
WHERE sp.id = ?
GROUP BY sp.id
LIMIT 1";

$studentStmt = $conn->prepare($studentSql);
if ($studentStmt === false) {
    $conn->close();
    echo json_encode(['ok' => false, 'error' => 'Unable to read student details.']);
    exit;
}

$studentStmt->bind_param('i', $profileId);
$studentStmt->execute();
$studentResult = $studentStmt->get_result();
$student = $studentResult ? $studentResult->fetch_assoc() : null;
$studentStmt->close();

if (!$student) {
    $conn->close();
    echo json_encode(['ok' => false, 'error' => 'Student not found.']);
    exit;
}

$totalFee = parseFeeAmount((string)($student['fees'] ?? '0'));
$paidFee = (float)($student['total_paid'] ?? 0);
$remainingFee = max(0.0, $totalFee - $paidFee);

$paymentSql = 'SELECT id, razorpay_order_id, razorpay_payment_id, amount_rupees, currency, status, created_at FROM registration_payments WHERE student_profile_id = ? ORDER BY created_at DESC';
$paymentStmt = $conn->prepare($paymentSql);
$payments = [];
if ($paymentStmt !== false) {
    $paymentStmt->bind_param('i', $profileId);
    $paymentStmt->execute();
    $paymentResult = $paymentStmt->get_result();
    if ($paymentResult instanceof mysqli_result) {
        while ($row = $paymentResult->fetch_assoc()) {
            $payments[] = $row;
        }
        $paymentResult->free();
    }
    $paymentStmt->close();
}

$conn->close();

echo json_encode([
    'ok' => true,
    'student' => [
        'profile_id' => (int)$student['profile_id'],
        'name' => (string)$student['full_name'],
        'login_id' => (string)$student['login_id'],
        'email' => (string)$student['email'],
        'mobile_no' => (string)$student['mobile_no'],
        'state' => (string)$student['state'],
        'district' => (string)$student['district'],
        'city' => (string)$student['city'],
        'college_name' => (string)$student['college_name'],
        'course_name' => (string)$student['course_name'],
        'duration' => (string)$student['duration'],
        'created_at' => (string)$student['created_at'],
        'total_fee' => $totalFee,
        'paid_fee' => $paidFee,
        'remaining_fee' => $remainingFee,
    ],
    'payments' => $payments,
]);
