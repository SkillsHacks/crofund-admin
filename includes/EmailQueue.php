<?php
declare(strict_types=1);

final class EmailQueue
{
    public const PRIORITY_CRITICAL = 100;
    public const PRIORITY_HIGH = 75;
    public const PRIORITY_NORMAL = 50;
    public const PRIORITY_LOW = 25;

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function ensureSchema(): void
    {
        $this->db->exec("CREATE TABLE IF NOT EXISTS email_queue (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            message_type VARCHAR(100) NOT NULL,
            dedupe_key VARCHAR(191) DEFAULT NULL,
            recipient_email VARCHAR(255) NOT NULL,
            recipient_name VARCHAR(255) DEFAULT NULL,
            subject VARCHAR(500) NOT NULL,
            template_name VARCHAR(150) NOT NULL,
            template_data LONGTEXT NOT NULL,
            priority INT NOT NULL DEFAULT 50,
            status ENUM('pending','processing','sent','failed') NOT NULL DEFAULT 'pending',
            provider VARCHAR(100) DEFAULT NULL,
            locked_by VARCHAR(100) DEFAULT NULL,
            locked_at DATETIME DEFAULT NULL,
            available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            attempts INT NOT NULL DEFAULT 0,
            max_attempts INT NOT NULL DEFAULT 5,
            last_attempt_at DATETIME DEFAULT NULL,
            sent_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            error_message TEXT DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_email_queue_dedupe (dedupe_key),
            KEY idx_email_queue_pick (status, available_at, priority, id),
            KEY idx_email_queue_recipient (recipient_email),
            KEY idx_email_queue_locked (locked_at),
            KEY idx_email_queue_message_type (message_type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->db->exec("CREATE TABLE IF NOT EXISTS email_queue_attempts (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            email_queue_id BIGINT UNSIGNED NOT NULL,
            attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            success TINYINT(1) NOT NULL DEFAULT 0,
            provider_response TEXT DEFAULT NULL,
            error_message TEXT DEFAULT NULL,
            worker_id VARCHAR(100) DEFAULT NULL,
            PRIMARY KEY (id),
            KEY idx_attempts_queue (email_queue_id, attempted_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public function queueEmail(array $payload): bool
    {
        $messageType = (string) $payload['message_type'];
        $recipientEmail = strtolower(trim((string) $payload['recipient_email']));
        $recipientName = trim((string) ($payload['recipient_name'] ?? ''));
        $subject = trim((string) $payload['subject']);
        $templateName = trim((string) $payload['template_name']);
        $templateData = $payload['template_data'] ?? [];
        $priority = (int) ($payload['priority'] ?? self::PRIORITY_NORMAL);
        $maxAttempts = (int) ($payload['max_attempts'] ?? EMAIL_MAX_RETRIES);
        $availableAt = (string) ($payload['available_at'] ?? date('Y-m-d H:i:s'));
        $dedupeKey = $payload['dedupe_key'] ?? $this->buildDedupeKey($messageType, $recipientEmail, $templateData, (int) (EMAIL_DEDUPLICATION_WINDOW_HOURS ?? 24));

        $stmt = $this->db->prepare("
            INSERT INTO email_queue (
                message_type, dedupe_key, recipient_email, recipient_name, subject,
                template_name, template_data, priority, status, available_at, max_attempts, created_at, updated_at
            ) VALUES (
                :message_type, :dedupe_key, :recipient_email, :recipient_name, :subject,
                :template_name, :template_data, :priority, 'pending', :available_at, :max_attempts, NOW(), NOW()
            )
            ON DUPLICATE KEY UPDATE updated_at = updated_at
        ");

        $stmt->execute([
            ':message_type' => $messageType,
            ':dedupe_key' => $dedupeKey,
            ':recipient_email' => $recipientEmail,
            ':recipient_name' => $recipientName,
            ':subject' => $subject,
            ':template_name' => $templateName,
            ':template_data' => json_encode($templateData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':priority' => $priority,
            ':available_at' => $availableAt,
            ':max_attempts' => $maxAttempts,
        ]);

        return $stmt->rowCount() > 0;
    }

    public function queueBulk(iterable $messages): array
    {
        $queued = 0;
        $duplicates = 0;
        foreach ($messages as $message) {
            if ($this->queueEmail($message)) {
                $queued++;
            } else {
                $duplicates++;
            }
        }

        return ['queued' => $queued, 'duplicates' => $duplicates];
    }

    public function releaseStaleLocks(): void
    {
        $minutes = (int) (EMAIL_STALE_PROCESSING_MINUTES ?? 30);
        $stmt = $this->db->prepare("
            UPDATE email_queue
            SET status = 'pending', locked_by = NULL, locked_at = NULL
            WHERE status = 'processing'
              AND locked_at IS NOT NULL
              AND locked_at < DATE_SUB(NOW(), INTERVAL {$minutes} MINUTE)
        ");
        $stmt->execute();
    }

    public function reserveBatch(int $limit, string $workerId): array
    {
        $limit = max(1, min($limit, (int) (EMAIL_WORKER_DEFAULT_BATCH_SIZE ?? 20)));
        $this->releaseStaleLocks();

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare("
                SELECT *
                FROM email_queue
                WHERE status = 'pending'
                  AND available_at <= NOW()
                ORDER BY priority DESC, available_at ASC, id ASC
                LIMIT {$limit}
                FOR UPDATE SKIP LOCKED
            ");
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (!$rows) {
                $this->db->commit();
                return [];
            }

            $ids = array_column($rows, 'id');
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $update = $this->db->prepare("UPDATE email_queue SET status = 'processing', locked_by = ?, locked_at = NOW(), attempts = attempts + 1, last_attempt_at = NOW() WHERE id IN ({$placeholders})");
            $update->execute(array_merge([$workerId], $ids));
            $this->db->commit();

            return $rows;
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function markSent(int $id, string $providerResponse, ?string $provider = null): void
    {
        $stmt = $this->db->prepare("UPDATE email_queue SET status = 'sent', sent_at = NOW(), locked_by = NULL, locked_at = NULL, provider = ?, error_message = NULL WHERE id = ?");
        $stmt->execute([$provider, $id]);
        $this->logAttempt($id, true, $providerResponse, null, null);
    }

    public function reschedule(int $id, int $attempts, string $errorMessage, ?string $workerId = null): void
    {
        $maxAttempts = (int) (EMAIL_MAX_RETRIES ?? 5);
        if ($attempts >= $maxAttempts) {
            $stmt = $this->db->prepare("UPDATE email_queue SET status = 'failed', error_message = ?, locked_by = NULL, locked_at = NULL WHERE id = ?");
            $stmt->execute([$errorMessage, $id]);
            $this->logAttempt($id, false, null, $errorMessage, $workerId);
            return;
        }

        $delaySeconds = $this->calculateBackoffSeconds($attempts);
        $stmt = $this->db->prepare("UPDATE email_queue SET status = 'pending', available_at = DATE_ADD(NOW(), INTERVAL ? SECOND), error_message = ?, locked_by = NULL, locked_at = NULL WHERE id = ?");
        $stmt->execute([$delaySeconds, $errorMessage, $id]);
        $this->logAttempt($id, false, null, $errorMessage, $workerId);
    }

    public function getStats(): array
    {
        $stats = [
            'pending' => 0,
            'processing' => 0,
            'sent' => 0,
            'failed' => 0,
            'provider_limit_per_hour' => (int) EMAIL_MAX_PER_HOUR,
        ];

        $stmt = $this->db->query("SELECT status, COUNT(*) AS count FROM email_queue GROUP BY status");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $stats[$row['status']] = (int) $row['count'];
        }

        $rateStmt = $this->db->query("SELECT COUNT(*) AS count FROM email_queue_attempts WHERE success = 1 AND attempted_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)");
        $stats['sent_last_hour'] = (int) $rateStmt->fetchColumn();

        return $stats;
    }

    public function getRecentAttempts(int $limit = 20): array
    {
        $limit = max(1, min($limit, 100));
        $stmt = $this->db->query("SELECT a.*, q.recipient_email, q.subject, q.message_type FROM email_queue_attempts a JOIN email_queue q ON q.id = a.email_queue_id ORDER BY a.attempted_at DESC LIMIT {$limit}");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function buildDedupeKey(string $messageType, string $recipientEmail, array $templateData = [], int $bucketHours = 24): string
    {
        $bucket = floor(time() / max(1, $bucketHours * 3600));
        return hash('sha256', $messageType . '|' . strtolower($recipientEmail) . '|' . json_encode($templateData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '|' . $bucket);
    }

    public function queueAdminTwoFactorCode(string $recipientEmail, string $recipientName, string $code): bool
    {
        $expiresInMinutes = defined('TWO_FACTOR_CODE_TTL') ? (int) ceil(TWO_FACTOR_CODE_TTL / 60) : 10;
        return $this->queueEmail([
            'message_type' => 'admin_two_factor_code',
            'recipient_email' => $recipientEmail,
            'recipient_name' => $recipientName,
            'subject' => 'Your ' . COMPANY_NAME . ' admin verification code',
            'template_name' => 'admin_two_factor_code',
            'template_data' => [
                'recipient_name' => htmlspecialchars($recipientName, ENT_QUOTES, 'UTF-8'),
                'company_name' => htmlspecialchars(COMPANY_NAME, ENT_QUOTES, 'UTF-8'),
                'site_url' => htmlspecialchars(SITE_URL, ENT_QUOTES, 'UTF-8'),
                'verification_code' => htmlspecialchars($code, ENT_QUOTES, 'UTF-8'),
                'expires_in_minutes' => (string) $expiresInMinutes,
                'current_year' => date('Y'),
            ],
            'priority' => self::PRIORITY_CRITICAL,
            'max_attempts' => EMAIL_MAX_RETRIES,
            'dedupe_key' => $this->buildDedupeKey('admin_two_factor_code', $recipientEmail, ['code' => $code], 1),
        ]);
    }

    public function queueCustomTemplateEmail(string $messageType, string $recipientEmail, string $recipientName, string $subject, array $templateData, int $priority = self::PRIORITY_NORMAL, ?string $dedupeKey = null): bool
    {
        return $this->queueEmail([
            'message_type' => $messageType,
            'recipient_email' => $recipientEmail,
            'recipient_name' => $recipientName,
            'subject' => $subject,
            'template_name' => 'pending_donation',
            'template_data' => $templateData,
            'priority' => $priority,
            'max_attempts' => EMAIL_MAX_RETRIES,
            'dedupe_key' => $dedupeKey,
        ]);
    }

    public function queuePendingDonationNotification(string $recipientEmail, string $recipientName): bool
    {
        $recipientEmail = strtolower(trim($recipientEmail));
        if ($recipientEmail === '') {
            return false;
        }

        return $this->queueCustomTemplateEmail(
            'pending_donation_notification',
            $recipientEmail,
            $recipientName,
            'Important: Pending Donation Notification - ' . COMPANY_NAME,
            [
                'name' => htmlspecialchars($recipientName, ENT_QUOTES, 'UTF-8'),
                'company_name' => COMPANY_NAME,
                'site_url' => SITE_URL,
                'current_year' => date('Y'),
                'custom_message' => 'We wanted to remind you about your pending donation activity.',
                'email' => htmlspecialchars($recipientEmail, ENT_QUOTES, 'UTF-8'),
            ],
            self::PRIORITY_HIGH,
            $this->buildDedupeKey('pending_donation_notification', $recipientEmail, ['recipient_name' => $recipientName], 24)
        );
    }

    public function queueWithdrawalStatusEmail(array $withdrawal, string $status = 'completed'): bool
    {
        $recipientEmail = strtolower(trim((string) ($withdrawal['email'] ?? '')));
        if ($recipientEmail === '') {
            return false;
        }

        $recipientName = (string) ($withdrawal['name'] ?? 'Valued User');
        $method = ucfirst((string) ($withdrawal['method'] ?? 'withdrawal'));
        $createdAt = !empty($withdrawal['created_at']) ? date('M d, Y H:i', strtotime((string) $withdrawal['created_at'])) : date('M d, Y H:i');
        $amount = number_format((float) ($withdrawal['amount'] ?? 0), 2);
        $subject = $status === 'completed' ? 'Withdrawal Initiated' : 'Withdrawal Update';
        $statusLabel = $status === 'completed' ? 'sent successfully' : 'rejected';
        $statusMessage = $status === 'completed'
            ? 'Your withdrawal has been sent. Funds are typically credited within 4 to 6 business days.'
            : 'Your withdrawal request has been rejected. Please contact support if you need more information.';

        $detailsHtml = '';
        $detailsText = '';
        $details = json_decode((string) ($withdrawal['details'] ?? '{}'), true);
        if (is_array($details)) {
            foreach ($details as $key => $value) {
                $label = htmlspecialchars(ucfirst(str_replace('_', ' ', (string) $key)), ENT_QUOTES, 'UTF-8');
                $safeValue = htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
                $detailsHtml .= '<tr><th style="border:1px solid #e5e7eb;padding:10px;text-align:left;background:#f8fafc;">' . $label . '</th><td style="border:1px solid #e5e7eb;padding:10px;">' . $safeValue . '</td></tr>';
                $detailsText .= ucfirst(str_replace('_', ' ', (string) $key)) . ': ' . (string) $value . "\n";
            }
        }

        $htmlMessage = '<div style="font-family:Arial,sans-serif;background:#f8fafc;color:#0f172a;padding:24px;">'
            . '<div style="max-width:640px;margin:0 auto;background:#ffffff;border-radius:18px;overflow:hidden;box-shadow:0 18px 40px rgba(15,23,42,0.12);">'
            . '<div style="background:#059669;color:#ffffff;padding:28px 32px;"><h1 style="margin:0;font-size:24px;">' . htmlspecialchars(COMPANY_NAME, ENT_QUOTES, 'UTF-8') . '</h1></div>'
            . '<div style="padding:32px;">'
            . '<p>Dear ' . htmlspecialchars($recipientName, ENT_QUOTES, 'UTF-8') . ',</p>'
            . '<p>Your withdrawal request has been <strong>' . htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8') . '</strong>.</p>'
            . '<p>' . htmlspecialchars($statusMessage, ENT_QUOTES, 'UTF-8') . '</p>'
            . '<table style="width:100%;border-collapse:collapse;margin-top:20px;">'
            . '<tr><th style="border:1px solid #e5e7eb;padding:10px;text-align:left;background:#f8fafc;">Amount</th><td style="border:1px solid #e5e7eb;padding:10px;">$' . $amount . '</td></tr>'
            . '<tr><th style="border:1px solid #e5e7eb;padding:10px;text-align:left;background:#f8fafc;">Method</th><td style="border:1px solid #e5e7eb;padding:10px;">' . htmlspecialchars($method, ENT_QUOTES, 'UTF-8') . '</td></tr>'
            . '<tr><th style="border:1px solid #e5e7eb;padding:10px;text-align:left;background:#f8fafc;">Requested At</th><td style="border:1px solid #e5e7eb;padding:10px;">' . htmlspecialchars($createdAt, ENT_QUOTES, 'UTF-8') . '</td></tr>'
            . $detailsHtml
            . '</table>'
            . '</div></div></div>';

        $textMessage = "Dear {$recipientName},\n\nYour withdrawal request has been {$statusLabel}.\nAmount: {$amount}\nMethod: {$method}\nRequested At: {$createdAt}\n" . $detailsText;

        return $this->queueCustomTemplateEmail(
            'withdrawal_status',
            $recipientEmail,
            $recipientName,
            $subject,
            [
                'name' => htmlspecialchars($recipientName, ENT_QUOTES, 'UTF-8'),
                'email' => htmlspecialchars($recipientEmail, ENT_QUOTES, 'UTF-8'),
                'site_url' => SITE_URL,
                'company_name' => COMPANY_NAME,
                'current_year' => date('Y'),
                'custom_message' => $htmlMessage,
                'plain_message' => $textMessage,
            ],
            self::PRIORITY_HIGH,
            $this->buildDedupeKey('withdrawal_status', $recipientEmail, ['status' => $status, 'created_at' => $createdAt, 'amount' => $amount], 24)
        );
    }

    private function calculateBackoffSeconds(int $attemptNumber): int
    {
        $base = (int) (EMAIL_BASE_RETRY_DELAY_SECONDS ?? 300);
        $multiplier = max(0, $attemptNumber - 1);
        return $base * (2 ** $multiplier);
    }

    private function logAttempt(int $queueId, bool $success, ?string $providerResponse, ?string $errorMessage, ?string $workerId): void
    {
        $stmt = $this->db->prepare("INSERT INTO email_queue_attempts (email_queue_id, attempted_at, success, provider_response, error_message, worker_id) VALUES (?, NOW(), ?, ?, ?, ?)");
        $stmt->execute([$queueId, $success ? 1 : 0, $providerResponse, $errorMessage, $workerId]);
    }
}
