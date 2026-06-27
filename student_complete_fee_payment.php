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

$orderId = trim((string)($data['razorpay_order_id'] ?? ''));
$paymentId = trim((string)($data['razorpay_payment_id'] ?? ''));
$signature = trim((string)($data['razorpay_signature'] ?? ''));

if ($orderId === '' || $paymentId === '' || $signature === '') {
    echo json_encode(['ok' => false, 'error' => 'Missing payment information.']);
    exit;
}

$pendingOrders = $_SESSION['pending_fee_orders'] ?? [];
$orderMeta = $pendingOrders[$orderId] ?? null;
if (!is_array($orderMeta)) {
    echo json_encode(['ok' => false, 'error' => 'Payment order not recognized. Please retry payment.']);
    exit;
}

$expectedSignature = hash_hmac('sha256', $orderId . '|' . $paymentId, RAZORPAY_KEY_SECRET);
if (!hash_equals($expectedSignature, $signature)) {
    echo json_encode(['ok' => false, 'error' => 'Payment signature verification failed.']);
    exit;
}

$ch = curl_init('https://api.razorpay.com/v1/payments/' . rawurlencode($paymentId));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
curl_setopt($ch, CURLOPT_USERPWD, RAZORPAY_KEY_ID . ':' . RAZORPAY_KEY_SECRET);
$paymentResponse = curl_exec($ch);
$paymentHttpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$paymentCurlError = curl_error($ch);
curl_close($ch);

if ($paymentResponse === false || $paymentHttpCode >= 400) {
    $message = $paymentCurlError !== '' ? $paymentCurlError : 'Unable to verify payment with Razorpay.';
    echo json_encode(['ok' => false, 'error' => $message]);
    exit;
}

$paymentData = json_decode($paymentResponse, true);
if (!is_array($paymentData)) {
    echo json_encode(['ok' => false, 'error' => 'Invalid payment verification response.']);
    exit;
}

$verifiedOrderId = (string)($paymentData['order_id'] ?? '');
$verifiedStatus = strtolower((string)($paymentData['status'] ?? ''));
$verifiedCurrency = (string)($paymentData['currency'] ?? '');
$verifiedAmountPaise = (int)($paymentData['amount'] ?? 0);

if ($verifiedOrderId !== $orderId) {
    echo json_encode(['ok' => false, 'error' => 'Order mismatch.']);
    exit;
}
if ($verifiedStatus !== 'captured') {
    echo json_encode(['ok' => false, 'error' => 'Payment not captured.']);
    exit;
}
if ($verifiedCurrency !== (string)$orderMeta['currency']) {
    echo json_encode(['ok' => false, 'error' => 'Currency mismatch.']);
    exit;
}
if ($verifiedAmountPaise !== (int)$orderMeta['amount_paise']) {
    echo json_encode(['ok' => false, 'error' => 'Paid amount mismatch.']);
    exit;
}

$conn = getDbConnection();
if ($conn === null) {
    echo json_encode(['ok' => false, 'error' => 'Database connection failed.']);
    exit;
}

$profileStmt = $conn->prepare('SELECT id, course_id FROM student_profiles WHERE user_id = ? LIMIT 1');
if ($profileStmt === false) {
    $conn->close();
    echo json_encode(['ok' => false, 'error' => 'Unable to validate student profile.']);
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

$studentProfileId = (int)$profile['id'];
if ($studentProfileId !== (int)$orderMeta['student_profile_id']) {
    $conn->close();
    echo json_encode(['ok' => false, 'error' => 'Order profile mismatch.']);
    exit;
}

$courseStmt = $conn->prepare('SELECT fees FROM courses WHERE id = ? LIMIT 1');
if ($courseStmt === false) {
    $conn->close();
    echo json_encode(['ok' => false, 'error' => 'Unable to validate course fee.']);
    exit;
}
$courseId = (int)$profile['course_id'];
$courseStmt->bind_param('i', $courseId);
$courseStmt->execute();
$courseResult = $courseStmt->get_result();
$course = $courseResult ? $courseResult->fetch_assoc() : null;
$courseStmt->close();

if (!$course) {
    $conn->close();
    echo json_encode(['ok' => false, 'error' => 'Course not found.']);
    exit;
}

$courseFeeText = (string)$course['fees'];
$courseFeeNumeric = preg_replace('/[^0-9.]/', '', $courseFeeText) ?? '0';
$courseTotal = (float)$courseFeeNumeric;

$paidStmt = $conn->prepare('SELECT COALESCE(SUM(amount_rupees), 0) AS total_paid FROM registration_payments WHERE student_profile_id = ?');
if ($paidStmt === false) {
    $conn->close();
    echo json_encode(['ok' => false, 'error' => 'Unable to calculate paid fees.']);
    exit;
}
$paidStmt->bind_param('i', $studentProfileId);
$paidStmt->execute();
$paidResult = $paidStmt->get_result();
$paidRow = $paidResult ? $paidResult->fetch_assoc() : ['total_paid' => 0];
$paidStmt->close();

$alreadyPaid = (float)($paidRow['total_paid'] ?? 0);
$remaining = max(0.0, $courseTotal - $alreadyPaid);
$paidNow = round($verifiedAmountPaise / 100, 2);
if ($paidNow > $remaining + 0.01) {
    $conn->close();
    echo json_encode(['ok' => false, 'error' => 'Paid amount exceeds pending fees.']);
    exit;
}

$dupStmt = $conn->prepare('SELECT id FROM registration_payments WHERE razorpay_payment_id = ? LIMIT 1');
if ($dupStmt === false) {
    $conn->close();
    echo json_encode(['ok' => false, 'error' => 'Unable to validate payment record.']);
    exit;
}
$dupStmt->bind_param('s', $paymentId);
$dupStmt->execute();
$dupResult = $dupStmt->get_result();
$duplicate = $dupResult ? $dupResult->fetch_assoc() : null;
$dupStmt->close();

if ($duplicate) {
    unset($_SESSION['pending_fee_orders'][$orderId]);
    $conn->close();
    echo json_encode(['ok' => false, 'error' => 'Payment already processed.']);
    exit;
}

$paymentStatus = 'captured';
$currency = RAZORPAY_CURRENCY;
$saveStmt = $conn->prepare(
    'INSERT INTO registration_payments (student_profile_id, razorpay_order_id, razorpay_payment_id, amount_rupees, currency, status) VALUES (?, ?, ?, ?, ?, ?)'
);
if ($saveStmt === false) {
    $conn->close();
    echo json_encode(['ok' => false, 'error' => 'Unable to save payment record.']);
    exit;
}
$saveStmt->bind_param(
    'issdss',
    $studentProfileId,
    $orderId,
    $paymentId,
    $paidNow,
    $currency,
    $paymentStatus
);
if (!$saveStmt->execute()) {
    $saveStmt->close();
    $conn->close();
    echo json_encode(['ok' => false, 'error' => 'Failed to save payment.']);
    exit;
}
$newPaymentId = (int)$conn->insert_id;
$saveStmt->close();
$conn->close();

unset($_SESSION['pending_fee_orders'][$orderId]);

echo json_encode([
    'ok' => true,
    'payment_id' => $newPaymentId,
    'receipt_url' => 'download_receipt.php?payment_id=' . $newPaymentId,
]);
