<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/PHPMailer/Exception.php';
require_once __DIR__ . '/../includes/PHPMailer/PHPMailer.php';
require_once __DIR__ . '/../includes/PHPMailer/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$payload = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid request payload.']);
    exit;
}

$email = trim((string)($payload['email'] ?? ''));

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    // If it's not a valid email format, we can check if it's a login_id. But since we need an email to send the link, we must enforce email format.
    // However, some admins might have login_id as email. Let's just assume they input their email.
    if ($email === '') {
        echo json_encode(['ok' => false, 'error' => 'Please provide a valid email address.']);
        exit;
    }
}

$conn = getDbConnection();
if (!$conn) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Database connection failed.']);
    exit;
}

// Check if email exists in users table (admin/coordinators)
$stmt = $conn->prepare("SELECT login_id as email, full_name as name FROM users WHERE login_id = ? LIMIT 1");
$stmt->bind_param('s', $email);
$stmt->execute();
$res = $stmt->get_result();
$user = $res->fetch_assoc();
$stmt->close();

// Check if email exists in student_profiles
if (!$user) {
    $stmt = $conn->prepare("SELECT email, CONCAT(first_name, ' ', last_name) as name FROM student_profiles WHERE email = ? LIMIT 1");
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $res = $stmt->get_result();
    $user = $res->fetch_assoc();
    $stmt->close();
}

// To prevent email enumeration, we could just say "If the email exists, a link was sent."
// But for a better UX (since this is internal system), we will tell them if it's not found.
if (!$user) {
    echo json_encode(['ok' => false, 'error' => 'No account found with that email address.']);
    $conn->close();
    exit;
}

$token = bin2hex(random_bytes(32));
$expires_at = date('Y-m-d H:i:s', time() + 3600); // 1 hour expiration

// Delete any existing tokens for this email
$stmt = $conn->prepare("DELETE FROM password_resets WHERE email = ?");
$stmt->bind_param('s', $email);
$stmt->execute();
$stmt->close();

// Insert new token
$stmt = $conn->prepare("INSERT INTO password_resets (email, token, expires_at) VALUES (?, ?, ?)");
$stmt->bind_param('sss', $email, $token, $expires_at);
if (!$stmt->execute()) {
    echo json_encode(['ok' => false, 'error' => 'Failed to generate reset token.']);
    $stmt->close();
    $conn->close();
    exit;
}
$stmt->close();
$conn->close();

$resetLink = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]/reset_password.php?email=" . urlencode($email) . "&token=" . urlencode($token);

// Send Email
$mail = new PHPMailer(true);
try {
    if (SMTP_HOST !== '') {
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USER;
        $mail->Password   = SMTP_PASS;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        $mail->Port       = SMTP_PORT;
    } else {
        // Fallback to mail() if no SMTP host is configured
        $mail->isMail();
    }

    $mail->setFrom(COMPANY_EMAIL, SMTP_FROM_NAME);
    $mail->addAddress($email, $user['name']);

    $mail->isHTML(true);
    $mail->Subject = 'Password Reset Request - 3D Shikshan';
    $mail->Body    = "
        <h2>Password Reset Request</h2>
        <p>Hi " . htmlspecialchars($user['name']) . ",</p>
        <p>We received a request to reset your password for your 3D Shikshan account.</p>
        <p>Click the link below to set a new password. This link will expire in 1 hour.</p>
        <p><a href='" . htmlspecialchars($resetLink) . "' style='padding: 10px 15px; background: #007664; color: #fff; text-decoration: none; border-radius: 5px; display: inline-block; margin: 15px 0;'>Reset Password</a></p>
        <p>If you did not request this, you can safely ignore this email.</p>
        <br>
        <p>Regards,<br>3D Shikshan Team</p>
    ";
    
    $mail->AltBody = "Hi " . $user['name'] . ",\n\nClick the link below to reset your password:\n" . $resetLink . "\n\nRegards,\n3D Shikshan Team";

    $mail->send();
    echo json_encode(['ok' => true, 'message' => 'Reset link has been sent to your email.']);
} catch (Exception $e) {
    echo json_encode(['ok' => false, 'error' => 'Failed to send email. Mailer Error: ' . $mail->ErrorInfo]);
}
