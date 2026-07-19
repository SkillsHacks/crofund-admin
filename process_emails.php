<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo 'CLI only';
    exit(1);
}

require_once 'includes/config.php';
require_once 'includes/EmailManager.php';
require_once 'includes/EmailQueue.php';

try {
    $db = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET,
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );

    $queue = new EmailQueue($db);
    $queue->ensureSchema();

    $rateStmt = $db->query("SELECT COUNT(*) FROM email_queue_attempts WHERE success = 1 AND attempted_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)");
    $sentLastHour = (int) $rateStmt->fetchColumn();
    $quotaRemaining = max(0, EMAIL_MAX_PER_HOUR - $sentLastHour);

    if ($quotaRemaining <= 0) {
        echo json_encode([
            'success' => true,
            'processed' => 0,
            'sent' => 0,
            'failed' => 0,
            'remaining_quota' => 0,
            'rate_limited' => true,
            'message' => 'Provider limit reached for the current rolling hour.'
        ], JSON_PRETTY_PRINT), PHP_EOL;
        exit(0);
    }

    global $argv;
    $requestedBatch = EMAIL_WORKER_DEFAULT_BATCH_SIZE;
    if (!empty($argv[1]) && ctype_digit((string) $argv[1])) {
        $requestedBatch = (int) $argv[1];
    }

    $batchSize = max(1, min($requestedBatch, $quotaRemaining, EMAIL_WORKER_DEFAULT_BATCH_SIZE));
    $workerId = gethostname() . ':' . getmypid() . ':' . substr(bin2hex(secure_random_bytes(6)), 0, 12);
    $jobs = $queue->reserveBatch($batchSize, $workerId);

    if (!$jobs) {
        echo json_encode([
            'success' => true,
            'processed' => 0,
            'sent' => 0,
            'failed' => 0,
            'remaining_quota' => $quotaRemaining,
            'queue' => $queue->getStats(),
            'message' => 'No pending emails available for processing.'
        ], JSON_PRETTY_PRINT), PHP_EOL;
        exit(0);
    }

    $emailManager = new EmailManager($db);
    $sent = 0;
    $failed = 0;

    foreach ($jobs as $job) {
        $result = $emailManager->sendQueuedEmail($job);
        if ($result['success']) {
            $queue->markSent((int) $job['id'], (string) ($result['provider_response'] ?? 'accepted'), (string) ($result['provider'] ?? 'smtp'));
            $sent++;
        } else {
            $queue->reschedule((int) $job['id'], (int) $job['attempts'] + 1, (string) ($result['provider_response'] ?? 'Failed to send email'), $workerId);
            $failed++;
        }
    }

    echo json_encode([
        'success' => true,
        'processed' => count($jobs),
        'sent' => $sent,
        'failed' => $failed,
        'remaining_quota' => max(0, EMAIL_MAX_PER_HOUR - ((int) $queue->getStats()['sent_last_hour'])),
        'queue' => $queue->getStats(),
        'worker_id' => $workerId,
        'message' => 'Worker run completed.'
    ], JSON_PRETTY_PRINT), PHP_EOL;
} catch (Throwable $e) {
    error_log('Error in process_emails.php: ' . $e->getMessage());
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
