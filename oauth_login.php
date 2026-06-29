<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/config.php';

$provider = $_GET['provider'] ?? '';

if (!in_array($provider, ['google', 'github', 'apple'], true)) {
    header('Location: index.html?error=invalid_provider#login');
    exit;
}

$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
$baseUrl = $protocol . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['PHP_SELF']), '/\\') . '/';
$redirectUri = $baseUrl . 'oauth_callback.php';

// Generate a random state for CSRF protection
$state = bin2hex(random_bytes(16));
$_SESSION['oauth_state'] = $state;
$_SESSION['oauth_provider'] = $provider;

$authUrl = '';

if ($provider === 'google') {
    if (OAUTH_GOOGLE_CLIENT_ID === '') {
        header('Location: index.html?error=oauth_not_configured#login');
        exit;
    }
    $params = [
        'client_id' => OAUTH_GOOGLE_CLIENT_ID,
        'redirect_uri' => $redirectUri,
        'response_type' => 'code',
        'scope' => 'email profile',
        'state' => $state,
        'access_type' => 'online'
    ];
    $authUrl = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);
} elseif ($provider === 'github') {
    if (OAUTH_GITHUB_CLIENT_ID === '') {
        header('Location: index.html?error=oauth_not_configured#login');
        exit;
    }
    $params = [
        'client_id' => OAUTH_GITHUB_CLIENT_ID,
        'redirect_uri' => $redirectUri,
        'scope' => 'user:email',
        'state' => $state
    ];
    $authUrl = 'https://github.com/login/oauth/authorize?' . http_build_query($params);
} elseif ($provider === 'apple') {
    if (OAUTH_APPLE_CLIENT_ID === '') {
        header('Location: index.html?error=oauth_not_configured#login');
        exit;
    }
    $params = [
        'client_id' => OAUTH_APPLE_CLIENT_ID,
        'redirect_uri' => $redirectUri,
        'response_type' => 'code id_token',
        'scope' => 'name email',
        'response_mode' => 'form_post',
        'state' => $state
    ];
    $authUrl = 'https://appleid.apple.com/auth/authorize?' . http_build_query($params);
}

header('Location: ' . $authUrl);
exit;
