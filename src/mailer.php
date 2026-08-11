<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/config.php'; // ensures .env is loaded even if this file is required first

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

/**
 * Low-level sender. Every other helper in this file (and every page
 * that used to call mail() directly) should route through this.
 *
 * @param string      $to       Recipient email address.
 * @param string      $subject  Email subject line.
 * @param string      $htmlBody Full HTML body (wrap with email_template() for consistent branding).
 * @param string|null $toName   Recipient display name (optional, nicer inbox display).
 * @param string|null $textBody Plain-text fallback. Auto-derived from $htmlBody if omitted.
 * @return bool True on accepted-for-delivery, false on failure (check error_log for details).
 */
function send_mail(string $to, string $subject, string $htmlBody, ?string $toName = null, ?string $textBody = null): bool {
    // Fail closed but never fatal a request just because email is down —
    // callers should treat a false return as "log it and move on".
    if (empty($to) || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        error_log("mailer.php: refused to send — invalid recipient address: " . var_export($to, true));
        return false;
    }

    $mail = new PHPMailer(true);

    try {
        // --- SMTP transport config (Brevo / SendGrid both work via SMTP relay) ---
        $mail->isSMTP();
        $mail->Host       = getenv('MAIL_HOST') ?: 'smtp-relay.brevo.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = getenv('MAIL_USERNAME') ?: '';
        $mail->Password   = getenv('MAIL_PASSWORD') ?: '';
        $mail->SMTPSecure = getenv('MAIL_ENCRYPTION') ?: PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = (int)(getenv('MAIL_PORT') ?: 587);
        $mail->CharSet    = 'UTF-8';

        // --- Sender / recipient ---
        $fromAddress = getenv('MAIL_FROM_ADDRESS') ?: 'no-reply@agroloan.com';
        $fromName    = getenv('MAIL_FROM_NAME') ?: 'AgroLoan';
        $mail->setFrom($fromAddress, $fromName);
        $mail->addAddress($to, $toName ?? '');

        // Brevo/SendGrid both require the From address to match a domain/sender
        // you've verified in their dashboard — mismatched From addresses get
        // silently rejected or land in spam, so this isn't optional.
        $mail->addReplyTo(getenv('MAIL_REPLY_TO') ?: $fromAddress);

        // --- Content ---
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $htmlBody;
        $mail->AltBody = $textBody ?? trim(strip_tags(preg_replace('/<br\s*\/?>/i', "\n", $htmlBody)));

        $mail->send();
        return true;

    } catch (PHPMailerException $e) {
        // Never let a mail failure break the page that triggered it
        // (an order, payment, or approval must still succeed even if
        // the notification email bounces).
        error_log("mailer.php send failure to {$to}: " . $mail->ErrorInfo);
        return false;
    }
}

/**
 * Wraps a content block in a consistent branded HTML shell so every
 * notification looks like it came from the same product.
 */
function email_template(string $title, string $bodyHtml): string {
    $safeTitle = htmlspecialchars($title);
    return <<<HTML
    <div style="font-family: Arial, sans-serif; max-width: 560px; margin: 0 auto; color: #1f2937;">
        <div style="background:#15803d; padding:20px 24px; border-radius:8px 8px 0 0;">
            <h1 style="color:#fff; margin:0; font-size:18px;">AgroLoan</h1>
        </div>
        <div style="border:1px solid #e5e7eb; border-top:none; padding:24px; border-radius:0 0 8px 8px;">
            <h2 style="margin-top:0; font-size:16px;">{$safeTitle}</h2>
            {$bodyHtml}
            <p style="margin-top:32px; font-size:12px; color:#6b7280;">
                This is an automated message from AgroLoan. Please do not reply directly to this email.
            </p>
        </div>
    </div>
    HTML;
}

// Buyer places an order
function send_order_confirmation_email(string $to, string $buyerName, int $orderId, float $total): bool {
    $body = "<p>Hi " . htmlspecialchars($buyerName) . ",</p>" .
            "<p>Your order <strong>#{$orderId}</strong> has been placed successfully. " .
            "Total: <strong>GHS " . number_format($total, 2) . "</strong>.</p>" .
            "<p>We'll notify you again once the seller confirms and dispatches your order.</p>";
    return send_mail($to, "Order #{$orderId} Confirmed", email_template('Order Confirmed', $body), $buyerName);
}

// MoMo collection succeeds
function send_payment_confirmation_email(string $to, string $name, int $orderId, float $amount): bool {
    $body = "<p>Hi " . htmlspecialchars($name) . ",</p>" .
            "<p>We've received your payment of <strong>GHS " . number_format($amount, 2) . "</strong> " .
            "for order <strong>#{$orderId}</strong>. Funds are held securely in escrow until delivery is confirmed.</p>";
    return send_mail($to, "Payment Received — Order #{$orderId}", email_template('Payment Confirmed', $body), $name);
}

// Admin releases escrow to a seller 
function send_escrow_release_email(string $to, string $name, int $orderId, float $amount): bool {
    $body = "<p>Hi " . htmlspecialchars($name) . ",</p>" .
            "<p>Escrow funds of <strong>GHS " . number_format($amount, 2) . "</strong> for order " .
            "<strong>#{$orderId}</strong> have been released to you.</p>";
    return send_mail($to, "Escrow Released — Order #{$orderId}", email_template('Escrow Released', $body), $name);
}

// Forgot-password flow 
function send_password_reset_email(string $to, string $resetLink): bool {
    $body = "<p>We received a request to reset your AgroLoan password.</p>" .
            "<p><a href=\"{$resetLink}\" style=\"background:#15803d;color:#fff;padding:10px 18px;" .
            "border-radius:6px;text-decoration:none;display:inline-block;\">Reset Password</a></p>" .
            "<p>This link expires in 1 hour. If you didn't request this, you can safely ignore this email.</p>";
    return send_mail($to, "Reset Your AgroLoan Password", email_template('Password Reset Requested', $body));
}

// Agent approves/rejects a loan stage
function send_loan_stage_email(string $to, string $farmerName, int $stageNumber, string $decision): bool {
    $verb = $decision === 'approved' ? 'approved and disbursed' : 'rejected';
    $body = "<p>Hi " . htmlspecialchars($farmerName) . ",</p>" .
            "<p>Stage <strong>{$stageNumber}</strong> of your loan has been <strong>{$verb}</strong> by your agent.</p>";
    return send_mail($to, "Loan Stage {$stageNumber} " . ucfirst($decision), email_template('Loan Stage Update', $body), $farmerName);
}

//Farmer vetting decision
function send_vetting_decision_email(string $to, string $farmerName, string $decision, string $reason = ''): bool {
    $body = "<p>Hi " . htmlspecialchars($farmerName) . ",</p>" .
            "<p>Your farmer verification has been <strong>" . htmlspecialchars($decision) . "</strong>.</p>" .
            ($reason ? "<p>Note: " . htmlspecialchars($reason) . "</p>" : '');
    return send_mail($to, "Farmer Verification " . ucfirst($decision), email_template('Verification Update', $body), $farmerName);
}

// Account status change from admin
function send_account_status_email(string $to, string $name, string $newStatus, string $justification = ''): bool {
    $body = "<p>Hi " . htmlspecialchars($name) . ",</p>" .
            "<p>Your AgroLoan account status has been updated to <strong>" . strtoupper($newStatus) . "</strong>.</p>" .
            ($justification ? "<p>Reason: " . htmlspecialchars($justification) . "</p>" : '');
    return send_mail($to, "Account Status Update — " . ucfirst($newStatus), email_template('Account Update', $body), $name);
}

// Generic dispute notification
function send_dispute_notification_email(string $to, string $name, string $subject, string $message): bool {
    $body = "<p>Hi " . htmlspecialchars($name) . ",</p><p>" . nl2br(htmlspecialchars($message)) . "</p>";
    return send_mail($to, $subject, email_template('Dispute Update', $body), $name);
}

// New loan application notification to the assigned agent
function send_loan_application_email(string $to, string $agentName, string $farmerName, int $applicationId): bool {
    $body = "<p>Hi " . htmlspecialchars($agentName) . ",</p>" .
            "<p>A new loan application (<strong>#{$applicationId}</strong>) from " .
            "<strong>" . htmlspecialchars($farmerName) . "</strong> is awaiting your review.</p>";
    return send_mail($to, "New Loan Application #{$applicationId}", email_template('New Application', $body), $agentName);
}
