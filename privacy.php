<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/legal.php';

$company = legal_company_name();
$email = legal_contact_email();

legal_render_head('Privacy Policy');
?>
<p><?php echo legal_esc($company); ?> (&ldquo;we&rdquo;, &ldquo;us&rdquo;) respects your privacy. This policy explains what data we collect, how we use it, and how payment data is handled when you use our portal.</p>

<h2>1. Information we collect</h2>
<ul>
    <li><strong>Account data:</strong> name, email, mobile number, login ID, role, and password (stored as a secure hash).</li>
    <li><strong>Student profile:</strong> address (state, district, city), college, course, and related academic records.</li>
    <li><strong>Payment records:</strong> amount paid, currency, payment status, Razorpay order ID, Razorpay payment ID, and timestamps (we do not store full card or UPI credentials).</li>
    <li><strong>Usage data:</strong> attendance, schedules, notifications, and support tickets you create.</li>
</ul>

<h2>2. How we use information</h2>
<ul>
    <li>To provide portal access, process registrations, and record fee payments.</li>
    <li>To generate receipts and show payment history to you and authorised staff.</li>
    <li>To communicate about courses, sessions, and support requests.</li>
    <li>To comply with legal, tax, and audit requirements.</li>
</ul>

<h2>3. Payment processing (Razorpay)</h2>
<p>When you pay online, you are redirected to Razorpay&rsquo;s secure checkout. Razorpay collects and processes payment instrument data according to its own policies:</p>
<ul>
    <li><a href="https://razorpay.com/privacy/" target="_blank" rel="noopener noreferrer">Razorpay Privacy Policy</a></li>
    <li><a href="https://razorpay.com/terms/" target="_blank" rel="noopener noreferrer">Razorpay Terms of Service</a></li>
</ul>
<p>We receive payment status, transaction identifiers, and amounts from Razorpay to update your account. We recommend you review Razorpay&rsquo;s policies before completing payment.</p>

<h2>4. Sharing of data</h2>
<p>We do not sell your personal data. We may share data with:</p>
<ul>
    <li>Razorpay and banking partners, solely to process payments.</li>
    <li>Hosting and infrastructure providers that operate our servers under confidentiality obligations.</li>
    <li>Authorities when required by applicable law.</li>
</ul>

<h2>5. Data retention &amp; security</h2>
<p>We retain account and payment records as needed for operations, disputes, and legal compliance. We use reasonable technical and organisational measures to protect data; no method of transmission over the internet is 100% secure.</p>

<h2>6. Your rights</h2>
<p>You may request correction of inaccurate profile data or account-related inquiries by emailing <a href="mailto:<?php echo legal_esc($email); ?>"><?php echo legal_esc($email); ?></a>. Certain requests may require identity verification.</p>

<h2>7. Cookies &amp; sessions</h2>
<p>We use session cookies to keep you logged in. Third-party scripts (e.g. Razorpay checkout) may set their own cookies when you initiate payment.</p>

<h2>8. Children</h2>
<p>The Platform is intended for students and authorised users. If you believe a minor has provided data without appropriate consent, contact us to review removal where applicable.</p>

<h2>9. Updates</h2>
<p>We may update this Privacy Policy. The &ldquo;Last updated&rdquo; date at the top will change when we do.</p>

<p>Related: <a href="terms.php">Terms &amp; Conditions</a> · <a href="refund.php">Refund &amp; Cancellation Policy</a></p>
<?php
legal_render_foot();
