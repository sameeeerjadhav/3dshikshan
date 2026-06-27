<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/legal.php';

$company = legal_company_name();
$email = legal_contact_email();

legal_render_head('Terms & Conditions');
?>
<p>These Terms &amp; Conditions (&ldquo;Terms&rdquo;) govern your use of the <?php echo legal_esc($company); ?> student portal and related services (&ldquo;Platform&rdquo;). By registering, logging in, or making a payment, you agree to these Terms.</p>

<h2>1. Services</h2>
<p>The Platform provides course registration, fee payment, attendance, scheduling, notifications, and support ticketing for enrolled students, coordinators, and administrators.</p>

<h2>2. Account &amp; eligibility</h2>
<ul>
    <li>You must provide accurate registration details and keep your login credentials confidential.</li>
    <li>You are responsible for all activity under your account.</li>
    <li>Minimum registration payment and fee rules are displayed at checkout and may vary by course.</li>
</ul>

<h2>3. Payments via Razorpay</h2>
<ul>
    <li>Online payments are accepted in INR through Razorpay, our authorised payment gateway partner.</li>
    <li>By proceeding to pay, you also agree to <a href="https://razorpay.com/terms/" target="_blank" rel="noopener noreferrer">Razorpay&rsquo;s Terms of Service</a> and <a href="https://razorpay.com/privacy/" target="_blank" rel="noopener noreferrer">Privacy Policy</a>.</li>
    <li>Successful payment does not guarantee admission until your registration is verified and approved by <?php echo legal_esc($company); ?>.</li>
    <li>Payment confirmations, Razorpay order IDs, and payment IDs are recorded for reconciliation and receipts.</li>
</ul>

<h2>4. Fees &amp; pricing</h2>
<p>Course fees, minimum payable amounts, and remaining balances are shown on the Platform before you confirm payment. You agree to pay only the amount you enter or confirm at checkout, subject to course limits.</p>

<h2>5. Acceptable use</h2>
<p>You must not misuse the Platform, attempt unauthorised access, submit false information, or interfere with other users or system integrity.</p>

<h2>6. Intellectual property</h2>
<p>Portal content, branding, and materials are owned by <?php echo legal_esc($company); ?> or its licensors. You may not copy or redistribute them without permission.</p>

<h2>7. Limitation of liability</h2>
<p>To the extent permitted by law, <?php echo legal_esc($company); ?> is not liable for indirect losses, payment network downtime, or issues caused by third-party services including Razorpay or your bank/UPI provider. Our liability for any claim is limited to the amount you paid for the specific transaction in dispute.</p>

<h2>8. Changes</h2>
<p>We may update these Terms. Continued use after changes constitutes acceptance. Material payment-related changes will be reflected on payment screens where practicable.</p>

<h2>9. Governing law &amp; contact</h2>
<p>These Terms are governed by the laws of India. For questions, contact <a href="mailto:<?php echo legal_esc($email); ?>"><?php echo legal_esc($email); ?></a>.</p>

<p>See also our <a href="privacy.php">Privacy Policy</a> and <a href="refund.php">Refund &amp; Cancellation Policy</a>.</p>
<?php
legal_render_foot();
