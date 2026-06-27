<?php
declare(strict_types=1);

session_start();
header('Content-Type: application/json');

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

$courseId = (int)($data['course_id'] ?? 0);
$email = trim((string)($data['email'] ?? ''));
$amountRupeesInput = (float)($data['amount_rupees'] ?? 0);

if ($courseId <= 0 || $email === '') {
    echo json_encode(['ok' => false, 'error' => 'Course and email are required.']);
    exit;
}

if ($amountRupeesInput <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Enter valid amount to pay.']);
    exit;
}

$conn = getDbConnection();
if ($conn === null) {
    echo json_encode(['ok' => false, 'error' => 'Database connection failed.']);
    exit;
}

$stmt = $conn->prepare('SELECT fees FROM courses WHERE id = ? LIMIT 1');
if ($stmt === false) {
    $conn->close();
    echo json_encode(['ok' => false, 'error' => 'Unable to read course fee.']);
    exit;
}

$stmt->bind_param('i', $courseId);
$stmt->execute();
$result = $stmt->get_result();
$course = $result ? $result->fetch_assoc() : null;
$stmt->close();
$conn->close();

if (!$course) {
    echo json_encode(['ok' => false, 'error' => 'Selected course not found.']);
    exit;
}

$feesText = (string)$course['fees'];
$numericText = preg_replace('/[^0-9.]/', '', $feesText) ?? '';
$parsedFee = (float)$numericText;
$maxPayableRupees = $parsedFee > 0 ? $parsedFee : 0.0;

if ($maxPayableRupees < 1000.0) {
    echo json_encode(['ok' => false, 'error' => 'Course fees is below minimum payable ₹1000. Contact admin.']);
    exit;
}

$payableRupees = round($amountRupeesInput, 2);
if ($payableRupees < 1000.0 || $payableRupees > $maxPayableRupees) {
    echo json_encode([
        'ok' => false,
        'error' => 'Amount must be between ₹1000 and ₹' . number_format($maxPayableRupees, 2) . '.',
    ]);
    exit;
}

$amountPaise = (int)round($payableRupees * 100);

$receipt = 'reg_' . time() . '_' . substr(md5($email), 0, 8);
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
    $message = $curlError !== '' ? $curlError : 'Razorpay order creation failed.';
    echo json_encode(['ok' => false, 'error' => $message]);
    exit;
}

$orderData = json_decode($response, true);
if (!is_array($orderData) || !isset($orderData['id'])) {
    echo json_encode(['ok' => false, 'error' => 'Invalid Razorpay response.']);
    exit;
}

echo json_encode([
    'ok' => true,
    'key' => RAZORPAY_KEY_ID,
    'order_id' => (string)$orderData['id'],
    'amount' => $amountPaise,
    'amount_rupees' => $payableRupees,
    'currency' => RAZORPAY_CURRENCY,
    'company' => RAZORPAY_COMPANY,
]);
