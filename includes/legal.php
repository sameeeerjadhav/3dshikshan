<?php
declare(strict_types=1);

function legal_company_name(): string
{
	return defined('RAZORPAY_COMPANY') && RAZORPAY_COMPANY !== ''
		? str_replace('_', ' ', RAZORPAY_COMPANY)
		: '3D Shikshan';
}

function legal_contact_email(): string
{
	return defined('COMPANY_EMAIL') ? COMPANY_EMAIL : 'admin@3dshikshan.com';
}

function legal_esc(string $value): string
{
	return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function payment_terms_accepted(array $data): bool
{
	$value = $data['terms_accepted'] ?? false;

	return $value === true || $value === 1 || $value === '1' || $value === 'true';
}

function legal_render_head(string $title): void
{
	$pageTitle = legal_esc($title . ' — ' . legal_company_name());
	echo '<!doctype html><html lang="en"><head>';
	echo '<meta charset="UTF-8">';
	echo '<meta name="viewport" content="width=device-width, initial-scale=1.0">';
	echo '<title>' . $pageTitle . '</title>';
	echo '<link rel="icon" type="image/png" href="assets/logo.png" />';
	echo '<link rel="preconnect" href="https://fonts.googleapis.com">';
	echo '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>';
	echo '<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;700&family=Space+Grotesk:wght@500;700&display=swap" rel="stylesheet">';
	echo '<link rel="stylesheet" href="assets/css/legal.css">';
	echo '</head><body class="legal-page">';
	echo '<header class="legal-header"><a href="index.php?login=1" class="legal-brand">' . legal_esc(legal_company_name()) . '</a></header>';
	echo '<main class="legal-main"><article class="legal-article">';
	echo '<h1>' . legal_esc($title) . '</h1>';
	echo '<p class="legal-updated">Last updated: May 2026</p>';
}

function legal_render_foot(): void
{
	echo '</article></main>';
	echo '<footer class="legal-footer">';
	echo '<nav class="legal-nav">';
	echo '<a href="terms.php">Terms</a>';
	echo '<a href="privacy.php">Privacy</a>';
	echo '<a href="refund.php">Refunds</a>';
	echo '<a href="index.php?login=1">Login</a>';
	echo '</nav>';
	echo '<p>&copy; ' . date('Y') . ' ' . legal_esc(legal_company_name()) . '</p>';
	echo '</footer></body></html>';
}
