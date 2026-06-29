<?php
declare(strict_types=1);

session_start();
header('Content-Type: application/json');

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

$firstName = trim((string)($data['first_name'] ?? ''));
$middleName = trim((string)($data['middle_name'] ?? ''));
$lastName = trim((string)($data['last_name'] ?? ''));
$mobileNo = trim((string)($data['mobile_no'] ?? ''));
$email = trim((string)($data['email'] ?? ''));
$state = trim((string)($data['state'] ?? ''));
$district = trim((string)($data['district'] ?? ''));
$collegeId = (int)($data['college_id'] ?? 0);
$courseId = (int)($data['course_id'] ?? 0);
$academicYear = trim((string)($data['academic_year'] ?? ''));
$semester = trim((string)($data['semester'] ?? ''));
$razorpayOrderId = trim((string)($data['razorpay_order_id'] ?? ''));
$razorpayPaymentId = trim((string)($data['razorpay_payment_id'] ?? ''));
$razorpaySignature = trim((string)($data['razorpay_signature'] ?? ''));

if (
    $firstName === '' || $lastName === '' || $mobileNo === '' || $email === '' ||
    $state === '' || $district === '' || $academicYear === '' || $semester === '' ||
    $collegeId <= 0 || $courseId <= 0 || $razorpayOrderId === '' ||
    $razorpayPaymentId === '' || $razorpaySignature === ''
) {
    echo json_encode(['ok' => false, 'error' => 'Missing required registration fields.']);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['ok' => false, 'error' => 'Invalid email address.']);
    exit;
}

if (!preg_match('/^\d{10,15}$/', $mobileNo)) {
    echo json_encode(['ok' => false, 'error' => 'Invalid mobile number.']);
    exit;
}

$isTestBypass = ($razorpayOrderId === 'order_test_bypass' && strpos(RAZORPAY_KEY_ID, 'rzp_test_') === 0);

if (!$isTestBypass) {
    $expectedSignature = hash_hmac('sha256', $razorpayOrderId . '|' . $razorpayPaymentId, RAZORPAY_KEY_SECRET);
    if (!hash_equals($expectedSignature, $razorpaySignature)) {
        echo json_encode(['ok' => false, 'error' => 'Payment signature verification failed.']);
        exit;
    }

$paymentUrl = 'https://api.razorpay.com/v1/payments/' . rawurlencode($razorpayPaymentId);
$ch = curl_init($paymentUrl);
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
    echo json_encode(['ok' => false, 'error' => 'Invalid Razorpay payment response.']);
    exit;
}

$paymentOrderId = (string)($paymentData['order_id'] ?? '');
$paymentStatus = strtolower((string)($paymentData['status'] ?? ''));
$paymentCurrency = (string)($paymentData['currency'] ?? '');
$paymentAmountPaise = (int)($paymentData['amount'] ?? 0);

if ($paymentOrderId !== $razorpayOrderId) {
    echo json_encode(['ok' => false, 'error' => 'Payment order mismatch.']);
    exit;
}

if ($paymentStatus !== 'captured') {
    echo json_encode(['ok' => false, 'error' => 'Payment is not captured.']);
    exit;
}

    if ($paymentCurrency !== RAZORPAY_CURRENCY) {
        echo json_encode(['ok' => false, 'error' => 'Payment currency mismatch.']);
        exit;
    }

    $paidRupees = round($paymentAmountPaise / 100, 2);
} else {
    // If test bypass, simulate successful payment data
    $paidRupees = isset($data['amount_to_pay']) ? (float)$data['amount_to_pay'] : 1000.0;
    $paymentStatus = 'captured';
    $paymentCurrency = RAZORPAY_CURRENCY;
}

$conn = getDbConnection();
if ($conn === null) {
    echo json_encode(['ok' => false, 'error' => 'Database connection failed.']);
    exit;
}

// Validate selected college
$collegeStmt = $conn->prepare('SELECT id, state, district FROM colleges WHERE id = ? LIMIT 1');
if ($collegeStmt === false) {
    $conn->close();
    echo json_encode(['ok' => false, 'error' => 'Unable to validate college.']);
    exit;
}
$collegeStmt->bind_param('i', $collegeId);
$collegeStmt->execute();
$collegeResult = $collegeStmt->get_result();
$college = $collegeResult ? $collegeResult->fetch_assoc() : null;
$collegeStmt->close();

if (!$college) {
    $conn->close();
    echo json_encode(['ok' => false, 'error' => 'Selected college not found.']);
    exit;
}

if (
    strcasecmp((string)$college['state'], $state) !== 0 ||
    strcasecmp((string)$college['district'], $district) !== 0
) {
    $conn->close();
    echo json_encode(['ok' => false, 'error' => 'Selected college does not match selected location.']);
    exit;
}

// Validate selected course and fees
$courseStmt = $conn->prepare('SELECT id, fees FROM courses WHERE id = ? LIMIT 1');
if ($courseStmt === false) {
    $conn->close();
    echo json_encode(['ok' => false, 'error' => 'Unable to validate course.']);
    exit;
}
$courseStmt->bind_param('i', $courseId);
$courseStmt->execute();
$courseResult = $courseStmt->get_result();
$course = $courseResult ? $courseResult->fetch_assoc() : null;
$courseStmt->close();

if (!$course) {
    $conn->close();
    echo json_encode(['ok' => false, 'error' => 'Selected course not found.']);
    exit;
}

$feesText = (string)$course['fees'];
$numericText = preg_replace('/[^0-9.]/', '', $feesText) ?? '';
$parsedFee = (float)$numericText;
$maxPayable = $parsedFee > 0 ? $parsedFee : 0.0;
if ($maxPayable < 1000.0) {
    $conn->close();
    echo json_encode(['ok' => false, 'error' => 'Course fees is below minimum payable ₹1000. Contact admin.']);
    exit;
}

if ($paidRupees < 1000.0 || $paidRupees > $maxPayable) {
    $conn->close();
    echo json_encode(['ok' => false, 'error' => 'Paid amount must be between ₹1000 and course fees.']);
    exit;
}

$existingStmt = $conn->prepare('SELECT id FROM users WHERE login_id = ? LIMIT 1');
if ($existingStmt === false) {
    $conn->close();
    echo json_encode(['ok' => false, 'error' => 'Unable to validate user.']);
    exit;
}
$existingStmt->bind_param('s', $email);
$existingStmt->execute();
$existingResult = $existingStmt->get_result();
$existingUser = $existingResult ? $existingResult->fetch_assoc() : null;
$existingStmt->close();

if ($existingUser) {
    $conn->close();
    echo json_encode(['ok' => false, 'error' => 'Email already registered. Please login.']);
    exit;
}

$fullName = trim($firstName . ' ' . $middleName . ' ' . $lastName);
$generatedPassword = 'STD' . strtoupper(bin2hex(random_bytes(4)));
$passwordHash = password_hash($generatedPassword, PASSWORD_DEFAULT);

$conn->begin_transaction();

try {
    $role = 'student';
    $userStmt = $conn->prepare('INSERT INTO users (full_name, login_id, password_hash, role) VALUES (?, ?, ?, ?)');
    if ($userStmt === false) {
        throw new RuntimeException('Unable to create user account.');
    }
    $userStmt->bind_param('ssss', $fullName, $email, $passwordHash, $role);
    if (!$userStmt->execute()) {
        $userStmt->close();
        throw new RuntimeException('Unable to save user account.');
    }
    $userId = (int)$conn->insert_id;
    $userStmt->close();

    $profileStmt = $conn->prepare(
        'INSERT INTO student_profiles (user_id, first_name, middle_name, last_name, mobile_no, email, state, district, college_id, course_id, academic_year, semester) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    if ($profileStmt === false) {
        throw new RuntimeException('Unable to create student profile.');
    }
    $profileStmt->bind_param(
        'isssssssiiss',
        $userId,
        $firstName,
        $middleName,
        $lastName,
        $mobileNo,
        $email,
        $state,
        $district,
        $collegeId,
        $courseId,
        $academicYear,
        $semester
    );
    if (!$profileStmt->execute()) {
        $profileStmt->close();
        throw new RuntimeException('Unable to save student profile.');
    }
    $studentProfileId = (int)$conn->insert_id;
    $profileStmt->close();

    $paymentStmt = $conn->prepare(
        'INSERT INTO registration_payments (student_profile_id, razorpay_order_id, razorpay_payment_id, amount_rupees, currency, status) VALUES (?, ?, ?, ?, ?, ?)'
    );
    if ($paymentStmt === false) {
        throw new RuntimeException('Unable to save payment record.');
    }
    $currency = RAZORPAY_CURRENCY;
    $paymentStmt->bind_param(
        'issdss',
        $studentProfileId,
        $razorpayOrderId,
        $razorpayPaymentId,
        $paidRupees,
        $currency,
        $paymentStatus
    );
    if (!$paymentStmt->execute()) {
        $paymentStmt->close();
        throw new RuntimeException('Unable to save payment information.');
    }
    $paymentStmt->close();

    $conn->commit();

    echo json_encode([
        'ok' => true,
        'login_id' => $email,
        'generated_password' => $generatedPassword,
    ]);
} catch (Throwable $exception) {
    $conn->rollback();
    echo json_encode(['ok' => false, 'error' => $exception->getMessage()]);
} finally {
    $conn->close();
}
