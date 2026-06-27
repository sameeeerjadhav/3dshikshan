<?php
declare(strict_types=1);

session_start();
header('Content-Type: application/json');

$user = $_SESSION['user'] ?? null;
if (!$user || ($user['role'] ?? '') !== 'student') {
    echo json_encode(['ok' => false, 'error' => 'Unauthorized.']);
    exit;
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/legal.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Invalid request method.']);
    exit;
}

if (RAZORPAY_KEY_ID === '' || RAZORPAY_KEY_SECRET === '') {
    echo json_encode(['ok' => false, 'error' => 'Payment gateway is not configured.']);
    exit;
}

$raw = file_get_contents('php://input');
$data = json_decode($raw ?: '', true);
if (!is_array($data)) {
    echo json_encode(['ok' => false, 'error' => 'Invalid payload.']);
    exit;
}

if (!payment_terms_accepted($data)) {
    echo json_encode(['ok' => false, 'error' => 'You must accept the Terms & Conditions and policies before payment.']);
    exit;
}

$requestedAmount = (float)($data['amount_rupees'] ?? 0);
if ($requestedAmount <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Enter valid amount.']);
    exit;
}

$conn = getDbConnection();
if ($conn === null) {
    echo json_encode(['ok' => false, 'error' => 'Database connection failed.']);
    exit;
}

$profileSql = '
    SELECT sp.id, cr.fees
    FROM student_profiles sp
    LEFT JOIN courses cr ON cr.id = sp.course_id
    WHERE sp.user_id = ?
    LIMIT 1
';
$profileStmt = $conn->prepare($profileSql);
if ($profileStmt === false) {
    $conn->close();
    echo json_encode(['ok' => false, 'error' => 'Unable to read student profile.']);
    exit;
}

$userId = (int)$user['id'];
$profileStmt->bind_param('i', $userId);
$profileStmt->execute();
$profileResult = $profileStmt->get_result();
$profile = $profileResult ? $profileResult->fetch_assoc() : null;
$profileStmt->close();

if (!$profile) {
    $conn->close();
    echo json_encode(['ok' => false, 'error' => 'Student profile not found.']);
    exit;
}

$courseFeeText = (string)($profile['fees'] ?? '0');
$courseFeeNumeric = preg_replace('/[^0-9.]/', '', $courseFeeText) ?? '0';
$courseTotal = (float)$courseFeeNumeric;

$paidStmt = $conn->prepare('SELECT COALESCE(SUM(amount_rupees), 0) AS total_paid FROM registration_payments WHERE student_profile_id = ?');
if ($paidStmt === false) {
    $conn->close();
    echo json_encode(['ok' => false, 'error' => 'Unable to read payment history.']);
    exit;
}
$studentProfileId = (int)$profile['id'];
$paidStmt->bind_param('i', $studentProfileId);
$paidStmt->execute();
$paidResult = $paidStmt->get_result();
$paidRow = $paidResult ? $paidResult->fetch_assoc() : ['total_paid' => 0];
$paidStmt->close();

$totalPaid = (float)($paidRow['total_paid'] ?? 0);
$remaining = max(0.0, $courseTotal - $totalPaid);
if ($remaining <= 0) {
    $conn->close();
    echo json_encode(['ok' => false, 'error' => 'No remaining fees pending.']);
    exit;
}

$payable = round($requestedAmount, 2);
if ($payable > $remaining) {
    $conn->close();
    echo json_encode(['ok' => false, 'error' => 'Amount cannot exceed remaining fees ₹' . number_format($remaining, 2) . '.']);
    exit;
}

$amountPaise = (int)round($payable * 100);
$receipt = 'fee_' . time() . '_' . $studentProfileId;
$orderPayload = [
    'amount' => $amountPaise,
    'currency' => RAZORPAY_CURRENCY,
    'receipt' => $receipt,
    'payment_capture' => 1,
];

$ch = curl_init('https://api.razorpay.com/v1/orders');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
curl_setopt($ch, CURLOPT_USERPWD, RAZORPAY_KEY_ID . ':' . RAZORPAY_KEY_SECRET);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($orderPayload));

$response = curl_exec($ch);
$httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($response === false || $httpCode >= 400) {
    $conn->close();
    $message = $curlError !== '' ? $curlError : 'Razorpay order creation failed.';
    echo json_encode(['ok' => false, 'error' => $message]);
    exit;
}

$orderData = json_decode($response, true);
if (!is_array($orderData) || !isset($orderData['id'])) {
    $conn->close();
    echo json_encode(['ok' => false, 'error' => 'Invalid Razorpay response.']);
    exit;
}

if (!isset($_SESSION['pending_fee_orders']) || !is_array($_SESSION['pending_fee_orders'])) {
    $_SESSION['pending_fee_orders'] = [];
}
$_SESSION['pending_fee_orders'][(string)$orderData['id']] = [
    'student_profile_id' => $studentProfileId,
    'amount_paise' => $amountPaise,
    'currency' => RAZORPAY_CURRENCY,
    'created_at' => time(),
];

$conn->close();

echo json_encode([
    'ok' => true,
    'key' => RAZORPAY_KEY_ID,
    'order_id' => (string)$orderData['id'],
    'amount' => $amountPaise,
    'currency' => RAZORPAY_CURRENCY,
    'company' => RAZORPAY_COMPANY,
]);
