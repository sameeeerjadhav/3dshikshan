<?php
declare(strict_types=1);

$paymentConsentCheckboxId = $paymentConsentCheckboxId ?? 'paymentTermsAccept';
?>
<div class="payment-legal-consent">
    <label class="payment-legal-label" for="<?php echo htmlspecialchars($paymentConsentCheckboxId, ENT_QUOTES, 'UTF-8'); ?>">
        <input
            type="checkbox"
            id="<?php echo htmlspecialchars($paymentConsentCheckboxId, ENT_QUOTES, 'UTF-8'); ?>"
            name="payment_terms_accept"
            value="1"
            required
        >
        <span>
            I agree to the
            <a href="terms.php" target="_blank" rel="noopener">Terms &amp; Conditions</a>,
            <a href="privacy.php" target="_blank" rel="noopener">Privacy Policy</a>,
            <a href="refund.php" target="_blank" rel="noopener">Refund &amp; Cancellation Policy</a>
            of <?php echo htmlspecialchars(defined('RAZORPAY_COMPANY') ? str_replace('_', ' ', RAZORPAY_COMPANY) : '3D Shikshan', ENT_QUOTES, 'UTF-8'); ?>,
            and Razorpay&rsquo;s
            <a href="https://razorpay.com/terms/" target="_blank" rel="noopener noreferrer">Terms of Service</a>
            &amp;
            <a href="https://razorpay.com/privacy/" target="_blank" rel="noopener noreferrer">Privacy Policy</a>.
        </span>
    </label>
    <p class="payment-legal-note">
        Payments are processed securely by Razorpay Software Private Limited. We do not store your full card, UPI PIN, or net-banking credentials on our servers.
    </p>
</div>
