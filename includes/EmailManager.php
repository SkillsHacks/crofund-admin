<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/EmailTemplateRenderer.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

final class EmailManager
{
    private PHPMailer $mailer;
    private PDO $db;
    private EmailTemplateRenderer $renderer;

    public function __construct(PDO $db)
    {
        $this->db = $db;
        $this->renderer = new EmailTemplateRenderer(__DIR__ . '/../email_templates/');

        $this->mailer = new PHPMailer(true);
        $this->mailer->isSMTP();
        $this->mailer->Host = SMTP_HOST;
        $this->mailer->SMTPAuth = true;
        $this->mailer->Username = SMTP_USER;
        $this->mailer->Password = SMTP_PASS;
        $this->mailer->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $this->mailer->Port = SMTP_PORT;
        $this->mailer->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
        $this->mailer->CharSet = 'UTF-8';
        $this->mailer->Timeout = 20;
    }

    public function sendQueuedEmail(array $queueRecord): array
    {
        $templateData = json_decode((string) ($queueRecord['template_data'] ?? '{}'), true);
        if (!is_array($templateData)) {
            $templateData = [];
        }

        if (isset($templateData['plain_message']) && !isset($templateData['custom_message'])) {
            $templateData['custom_message'] = nl2br(htmlspecialchars((string) $templateData['plain_message'], ENT_QUOTES, 'UTF-8'));
        }

        $rendered = $this->renderer->render((string) $queueRecord['template_name'], $templateData);

        return $this->deliverEmail(
            (string) $queueRecord['recipient_email'],
            (string) ($queueRecord['recipient_name'] ?? ''),
            (string) $queueRecord['subject'],
            $rendered['html'],
            $rendered['text']
        );
    }

    public function sendAdminTwoFactorCode(string $recipientEmail, string $recipientName, string $code): bool
    {
        try {
            $expiresInMinutes = defined('TWO_FACTOR_CODE_TTL') ? (int) ceil(TWO_FACTOR_CODE_TTL / 60) : 10;
            $rendered = $this->renderer->render('admin_two_factor_code', [
                'recipient_name' => htmlspecialchars($recipientName, ENT_QUOTES, 'UTF-8'),
                'company_name' => htmlspecialchars(COMPANY_NAME, ENT_QUOTES, 'UTF-8'),
                'site_url' => htmlspecialchars(SITE_URL, ENT_QUOTES, 'UTF-8'),
                'verification_code' => htmlspecialchars($code, ENT_QUOTES, 'UTF-8'),
                'expires_in_minutes' => (string) $expiresInMinutes,
                'current_year' => date('Y'),
            ]);

            $result = $this->deliverEmail(
                $recipientEmail,
                $recipientName,
                'Your ' . COMPANY_NAME . ' admin verification code',
                $rendered['html'],
                $rendered['text']
            );

            return $result['success'];
        } catch (Throwable $e) {
            error_log('Failed to send admin 2FA email: ' . $e->getMessage());
            return false;
        }
    }

    private function deliverEmail(string $recipientEmail, string $recipientName, string $subject, string $htmlBody, string $altBody = ''): array
    {
        try {
            $this->mailer->clearAddresses();
            $this->mailer->clearAttachments();
            $this->mailer->isHTML(true);
            $this->mailer->addAddress($recipientEmail, $recipientName);
            $this->mailer->Subject = $subject;
            $this->mailer->Body = $htmlBody;
            $this->mailer->AltBody = $altBody !== '' ? $altBody : trim(strip_tags($htmlBody));
            $this->mailer->send();

            $providerResponse = $this->mailer->ErrorInfo !== '' ? $this->mailer->ErrorInfo : 'accepted';
            $this->logEmailSent($recipientEmail, $subject, true, null);

            return [
                'success' => true,
                'provider' => 'smtp',
                'provider_response' => $providerResponse,
            ];
        } catch (Exception $e) {
            $message = $e->getMessage();
            $this->logEmailSent($recipientEmail, $subject, false, $message);
            error_log('Email send failure: ' . $message);

            return [
                'success' => false,
                'provider' => 'smtp',
                'provider_response' => $message,
            ];
        }
    }

    private function logEmailSent(string $email, string $subject, bool $success, ?string $error = null): void
    {
        try {
            $stmt = $this->db->prepare('INSERT INTO email_log (recipient_email, subject, success, error_message, sent_at) VALUES (?, ?, ?, ?, NOW())');
            $stmt->execute([$email, $subject, $success ? 1 : 0, $error]);
        } catch (Throwable $e) {
            error_log('Unable to write email log entry: ' . $e->getMessage());
        }
    }
}
