<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

// Apple sends POST (form_post), Google/GitHub send GET
$code = $_POST['code'] ?? $_GET['code'] ?? '';
$state = $_POST['state'] ?? $_GET['state'] ?? '';
$idToken = $_POST['id_token'] ?? '';
$error = $_POST['error'] ?? $_GET['error'] ?? '';

if ($error !== '') {
    header('Location: index.html?error=oauth_declined#login');
    exit;
}

if ($code === '') {
    header('Location: index.html?error=oauth_invalid#login');
    exit;
}

$sessionState = $_SESSION['oauth_state'] ?? '';
$provider = $_SESSION['oauth_provider'] ?? '';

// Clear session variables to prevent replay
unset($_SESSION['oauth_state'], $_SESSION['oauth_provider']);

if ($state === '' || $state !== $sessionState) {
    header('Location: index.html?error=oauth_state_mismatch#login');
    exit;
}

if (!in_array($provider, ['google', 'github', 'apple'], true)) {
    header('Location: index.html?error=invalid_provider#login');
    exit;
}

$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
$baseUrl = $protocol . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['PHP_SELF']), '/\\') . '/';
$redirectUri = $baseUrl . 'oauth_callback.php';

$email = '';

function doHttpPost($url, $data, $headers = []) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    if (!empty($headers)) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }
    $result = curl_exec($ch);
    curl_close($ch);
    return $result;
}

function doHttpGet($url, $headers = []) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    if (!empty($headers)) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }
    // GitHub requires a User-Agent
    curl_setopt($ch, CURLOPT_USERAGENT, '3DShikshan-OAuth');
    $result = curl_exec($ch);
    curl_close($ch);
    return $result;
}

if ($provider === 'google') {
    $tokenResponse = doHttpPost('https://oauth2.googleapis.com/token', [
        'client_id' => OAUTH_GOOGLE_CLIENT_ID,
        'client_secret' => OAUTH_GOOGLE_CLIENT_SECRET,
        'code' => $code,
        'grant_type' => 'authorization_code',
        'redirect_uri' => $redirectUri
    ]);
    
    $tokenData = json_decode((string)$tokenResponse, true);
    if (isset($tokenData['access_token'])) {
        $userResponse = doHttpGet('https://www.googleapis.com/oauth2/v2/userinfo', [
            'Authorization: Bearer ' . $tokenData['access_token']
        ]);
        $userData = json_decode((string)$userResponse, true);
        $email = $userData['email'] ?? '';
    }
} elseif ($provider === 'github') {
    $tokenResponse = doHttpPost('https://github.com/login/oauth/access_token', [
        'client_id' => OAUTH_GITHUB_CLIENT_ID,
        'client_secret' => OAUTH_GITHUB_CLIENT_SECRET,
        'code' => $code,
        'redirect_uri' => $redirectUri
    ], ['Accept: application/json']);
    
    $tokenData = json_decode((string)$tokenResponse, true);
    if (isset($tokenData['access_token'])) {
        $emailsResponse = doHttpGet('https://api.github.com/user/emails', [
            'Authorization: Bearer ' . $tokenData['access_token'],
            'Accept: application/vnd.github.v3+json'
        ]);
        $emailsData = json_decode((string)$emailsResponse, true);
        if (is_array($emailsData)) {
            foreach ($emailsData as $emailObj) {
                if (isset($emailObj['primary']) && $emailObj['primary'] === true) {
                    $email = $emailObj['email'] ?? '';
                    break;
                }
            }
        }
    }
} elseif ($provider === 'apple') {
    if ($idToken !== '') {
        $parts = explode('.', $idToken);
        if (count($parts) === 3) {
            $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
            if (isset($payload['email'])) {
                $email = $payload['email'];
            }
        }
    }
}

$email = trim(strtolower($email));

if ($email === '') {
    header('Location: index.html?error=oauth_no_email#login');
    exit;
}

// Now we lookup the user in the database
$connection = getDbConnection();
if ($connection === null) {
    header('Location: index.html?error=db#login');
    exit;
}

// Note: login_id is typically the email in this system
$sql = 'SELECT id, full_name, login_id, role FROM users WHERE login_id = ? LIMIT 1';
$stmt = $connection->prepare($sql);

if ($stmt === false) {
    $connection->close();
    header('Location: index.html?error=unavailable#login');
    exit;
}

$stmt->bind_param('s', $email);
$stmt->execute();
$result = $stmt->get_result();
$record = $result ? $result->fetch_assoc() : null;

$stmt->close();
$connection->close();

if (!$record) {
    // User does not exist, redirect to signup
    header('Location: signup.php?email=' . urlencode($email) . '&error=oauth_not_found');
    exit;
}

$role = (string)$record['role'];

$_SESSION['user'] = [
    'id' => (int)$record['id'],
    'name' => (string)$record['full_name'],
    'login_id' => (string)$record['login_id'],
    'role' => $role,
];

session_regenerate_id(true);

header('Location: ' . ($role === 'admin' ? 'admin/dashboard.php' : 'dashboard.php'));
exit;
