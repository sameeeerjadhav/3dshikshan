<?php
declare(strict_types=1);

session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';

$user = $_SESSION['user'] ?? null;
if (!$user || ($user['role'] ?? '') !== 'admin') {
    echo json_encode(['ok' => false, 'error' => 'Unauthorized access.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Invalid request method.']);
    exit;
}

$profileId = isset($_POST['profile_id']) ? (int)$_POST['profile_id'] : 0;
$userId = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
$firstName = trim($_POST['first_name'] ?? '');
$middleName = trim($_POST['middle_name'] ?? '');
$lastName = trim($_POST['last_name'] ?? '');
$loginId = trim($_POST['login_id'] ?? '');
$email = trim($_POST['email'] ?? '');
$mobileNo = trim($_POST['mobile_no'] ?? '');
$state = trim($_POST['state'] ?? '');
$district = trim($_POST['district'] ?? '');
$collegeId = isset($_POST['college_id']) ? (int)$_POST['college_id'] : 0;
$courseId = isset($_POST['course_id']) ? (int)$_POST['course_id'] : 0;
$academicYear = trim($_POST['academic_year'] ?? '');
$semester = trim($_POST['semester'] ?? '');
$password = trim($_POST['password'] ?? '');

if (empty($profileId) || empty($userId) || empty($firstName) || empty($lastName) || empty($loginId) || empty($email) || empty($mobileNo) || empty($collegeId) || empty($courseId)) {
    echo json_encode(['ok' => false, 'error' => 'Please fill all required fields.']);
    exit;
}

$conn = getDbConnection();
if ($conn === null) {
    echo json_encode(['ok' => false, 'error' => 'Database connection failed.']);
    exit;
}

// 1. Check if login_id is unique
$stmt = $conn->prepare('SELECT id FROM users WHERE login_id = ? AND id != ?');
$stmt->bind_param('si', $loginId, $userId);
$stmt->execute();
$stmt->store_result();
if ($stmt->num_rows > 0) {
    echo json_encode(['ok' => false, 'error' => 'Login ID is already in use by another user.']);
    $stmt->close();
    $conn->close();
    exit;
}
$stmt->close();

// 2. Check if email is unique in student_profiles
$stmt = $conn->prepare('SELECT id FROM student_profiles WHERE email = ? AND id != ?');
$stmt->bind_param('si', $email, $profileId);
$stmt->execute();
$stmt->store_result();
if ($stmt->num_rows > 0) {
    echo json_encode(['ok' => false, 'error' => 'Email is already in use by another student.']);
    $stmt->close();
    $conn->close();
    exit;
}
$stmt->close();

$fullName = trim($firstName . ' ' . $middleName . ' ' . $lastName);
$fullName = preg_replace('/\s+/', ' ', $fullName); // replace multiple spaces

$conn->begin_transaction();
try {
    // 3. Update users table
    if (!empty($password)) {
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        $stmtUser = $conn->prepare('UPDATE users SET full_name = ?, login_id = ?, password_hash = ? WHERE id = ? AND role = "student"');
        $stmtUser->bind_param('sssi', $fullName, $loginId, $passwordHash, $userId);
    } else {
        $stmtUser = $conn->prepare('UPDATE users SET full_name = ?, login_id = ? WHERE id = ? AND role = "student"');
        $stmtUser->bind_param('ssi', $fullName, $loginId, $userId);
    }
    
    if (!$stmtUser->execute()) {
        throw new Exception('Failed to update user record.');
    }
    $stmtUser->close();

    // 4. Update student_profiles table
    $stmtProfile = $conn->prepare('UPDATE student_profiles SET 
        first_name = ?, middle_name = ?, last_name = ?, mobile_no = ?, email = ?, 
        state = ?, district = ?, college_id = ?, course_id = ?, academic_year = ?, semester = ? 
        WHERE id = ? AND user_id = ?');
        
    $stmtProfile->bind_param('sssssssiiisii', 
        $firstName, $middleName, $lastName, $mobileNo, $email, 
        $state, $district, $collegeId, $courseId, $academicYear, $semester, 
        $profileId, $userId
    );
    
    if (!$stmtProfile->execute()) {
        throw new Exception('Failed to update student profile.');
    }
    $stmtProfile->close();

    $conn->commit();
    echo json_encode(['ok' => true]);

} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}

$conn->close();
