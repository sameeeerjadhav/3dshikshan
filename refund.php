<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/legal.php';

$company = legal_company_name();
$email = legal_contact_email();

legal_render_head('Refund & Cancellation Policy');
?>
<p>This Refund &amp; Cancellation Policy applies to registration and course fee payments made through the <?php echo legal_esc($company); ?> portal via Razorpay.</p>

<h2>1. Payment confirmation</h2>
<p>After a successful Razorpay payment, you will receive a payment ID and may download a receipt from the student dashboard. Please save these details for your records.</p>

<h2>2. Registration payments</h2>
<ul>
    <li>Registration payments (minimum ₹1000 or as shown at signup) secure your application on the Platform.</li>
    <li>If your registration cannot be completed due to a verified technical error on our side (duplicate charge, system failure after payment), contact us within <strong>7 calendar days</strong> with your Razorpay payment ID.</li>
</ul>

<h2>3. Course fee payments</h2>
<ul>
    <li>Partial fee payments reduce your outstanding balance as shown on your dashboard.</li>
    <li>Fees paid toward an enrolled course are generally <strong>non-refundable</strong> except where required by law or explicitly approved by <?php echo legal_esc($company); ?> management.</li>
</ul>

<h2>4. Eligible refunds</h2>
<p>Refunds may be considered only in cases such as:</p>
<ul>
    <li>Duplicate payment for the same order or amount.</li>
    <li>Payment captured but account not credited due to a confirmed system error.</li>
    <li>Course cancellation by <?php echo legal_esc($company); ?> before commencement, as announced officially.</li>
</ul>
<p>Refund requests are not accepted for change of mind after successful payment unless otherwise stated in writing by us.</p>

<h2>5. Refund process &amp; timeline</h2>
<ul>
    <li>Email <a href="mailto:<?php echo legal_esc($email); ?>"><?php echo legal_esc($email); ?></a> with your full name, login email, Razorpay payment ID, order ID, amount, and reason.</li>
    <li>Approved refunds are processed back to the original payment method via Razorpay/banking networks where possible.</li>
    <li>Refunds typically reflect within <strong>5–10 business days</strong>, depending on your bank or UPI provider.</li>
</ul>

<h2>6. Cancellations</h2>
<p>Cancelling a payment window in Razorpay checkout before completion does not charge your account. Closing the payment modal without paying does not create a refund obligation.</p>

<h2>7. Chargebacks &amp; disputes</h2>
<p>If you dispute a charge with your bank without contacting us first, we may provide Razorpay and the bank with transaction logs and registration records to resolve the dispute.</p>

<h2>8. Contact</h2>
<p>For refund or cancellation queries: <a href="mailto:<?php echo legal_esc($email); ?>"><?php echo legal_esc($email); ?></a></p>

<p>See also <a href="terms.php">Terms &amp; Conditions</a> and <a href="privacy.php">Privacy Policy</a>.</p>
<?php
legal_render_foot();
