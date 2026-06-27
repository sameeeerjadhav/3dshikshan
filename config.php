<?php
declare(strict_types=1);

function load_env_file(string $path): void
{
	if (!is_file($path) || !is_readable($path)) {
		return;
	}

	$lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
	if ($lines === false) {
		return;
	}

	foreach ($lines as $line) {
		$line = trim($line);
		if ($line === '' || str_starts_with($line, '#') || str_starts_with($line, ';')) {
			continue;
		}

		$position = strpos($line, '=');
		if ($position === false) {
			continue;
		}

		$name = trim(substr($line, 0, $position));
		$value = trim(substr($line, $position + 1));

		if ($name === '') {
			continue;
		}

		if ((str_starts_with($value, '"') && str_ends_with($value, '"')) || (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
			$value = substr($value, 1, -1);
		}

		$value = str_replace(['\\n', '\\r'], ["\n", "\r"], $value);

		putenv($name . '=' . $value);
		$_ENV[$name] = $value;
		$_SERVER[$name] = $value;
	}
}

function env_value(string $key, string $default = ''): string
{
	$value = getenv($key);
	if ($value === false || $value === '') {
		$value = $_ENV[$key] ?? $_SERVER[$key] ?? $default;
	}

	return (string)$value;
}

load_env_file(__DIR__ . '/.env');

if (!defined('DB_HOST')) {
	define('DB_HOST', env_value('DB_HOST', 'localhost'));
}

if (!defined('DB_PORT')) {
	define('DB_PORT', (int)env_value('DB_PORT', '3306'));
}

if (!defined('DB_NAME')) {
	define('DB_NAME', env_value('DB_NAME', '3dshikshan'));
}

if (!defined('DB_USER')) {
	define('DB_USER', env_value('DB_USER', '3dshikshan'));
}

if (!defined('DB_PASS')) {
	define('DB_PASS', env_value('DB_PASS', ''));
}

if (!defined('ADMIN_LOGIN_ID')) {
	define('ADMIN_LOGIN_ID', env_value('ADMIN_LOGIN_ID', 'admin@3dshikshan.com'));
}

if (!defined('ADMIN_PASSWORD')) {
	define('ADMIN_PASSWORD', env_value('ADMIN_PASSWORD', 'Admin@123'));
}

if (!defined('RAZORPAY_KEY_ID')) {
	define('RAZORPAY_KEY_ID', env_value('RAZORPAY_KEY_ID', ''));
}

if (!defined('RAZORPAY_KEY_SECRET')) {
	define('RAZORPAY_KEY_SECRET', env_value('RAZORPAY_KEY_SECRET', ''));
<?php
declare(strict_types=1);

function load_env_file(string $path): void
{
	if (!is_file($path) || !is_readable($path)) {
		return;
	}

	$lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
	if ($lines === false) {
		return;
	}

	foreach ($lines as $line) {
		$line = trim($line);
		if ($line === '' || str_starts_with($line, '#') || str_starts_with($line, ';')) {
			continue;
		}

		$position = strpos($line, '=');
		if ($position === false) {
			continue;
		}

		$name = trim(substr($line, 0, $position));
		$value = trim(substr($line, $position + 1));

		if ($name === '') {
			continue;
		}

		if ((str_starts_with($value, '"') && str_ends_with($value, '"')) || (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
			$value = substr($value, 1, -1);
		}

		$value = str_replace(['\\n', '\\r'], ["\n", "\r"], $value);

		putenv($name . '=' . $value);
		$_ENV[$name] = $value;
		$_SERVER[$name] = $value;
	}
}

function env_value(string $key, string $default = ''): string
{
	$value = getenv($key);
	if ($value === false || $value === '') {
		$value = $_ENV[$key] ?? $_SERVER[$key] ?? $default;
	}

	return (string)$value;
}

load_env_file(__DIR__ . '/.env');

if (!defined('DB_HOST')) {
	define('DB_HOST', env_value('DB_HOST', 'localhost'));
}

if (!defined('DB_PORT')) {
	define('DB_PORT', (int)env_value('DB_PORT', '3306'));
}

if (!defined('DB_NAME')) {
	define('DB_NAME', env_value('DB_NAME', '3dshikshan'));
}

if (!defined('DB_USER')) {
	define('DB_USER', env_value('DB_USER', '3dshikshan'));
}

if (!defined('DB_PASS')) {
	define('DB_PASS', env_value('DB_PASS', ''));
}

if (!defined('ADMIN_LOGIN_ID')) {
	define('ADMIN_LOGIN_ID', env_value('ADMIN_LOGIN_ID', 'admin@3dshikshan.com'));
}

if (!defined('ADMIN_PASSWORD')) {
	define('ADMIN_PASSWORD', env_value('ADMIN_PASSWORD', 'Admin@123'));
}

if (!defined('RAZORPAY_KEY_ID')) {
	define('RAZORPAY_KEY_ID', env_value('RAZORPAY_KEY_ID', ''));
}

if (!defined('RAZORPAY_KEY_SECRET')) {
	define('RAZORPAY_KEY_SECRET', env_value('RAZORPAY_KEY_SECRET', ''));
}

if (!defined('RAZORPAY_CURRENCY')) {
	define('RAZORPAY_CURRENCY', env_value('RAZORPAY_CURRENCY', 'INR'));
}

if (!defined('RAZORPAY_COMPANY')) {
	define('RAZORPAY_COMPANY', env_value('RAZORPAY_COMPANY', '3D_Shikshan'));
}

if (!defined('COMPANY_EMAIL')) {
	define('COMPANY_EMAIL', env_value('COMPANY_EMAIL', 'admin@3dshikshan.com'));
}

// SMTP Settings
if (!defined('SMTP_HOST')) define('SMTP_HOST', env_value('SMTP_HOST', ''));
if (!defined('SMTP_PORT')) define('SMTP_PORT', (int)env_value('SMTP_PORT', '465'));
if (!defined('SMTP_USER')) define('SMTP_USER', env_value('SMTP_USER', ''));
if (!defined('SMTP_PASS')) define('SMTP_PASS', env_value('SMTP_PASS', ''));
if (!defined('SMTP_FROM_NAME')) define('SMTP_FROM_NAME', env_value('SMTP_FROM_NAME', '3D Shikshan'));

// Security settings
define('MAX_LOGIN_ATTEMPTS', 5);
