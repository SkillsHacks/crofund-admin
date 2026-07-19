<?php
declare(strict_types=1);

require_once 'includes/config.php';
require_once 'includes/auth.php';
require_once 'includes/EmailQueue.php';
session_start();

$auth = new Auth();
$auth->requireAuth();
require_once 'includes/admin_layout.php';

$error = null;
$success = null;

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

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
            throw new Exception('Request could not be processed.');
        }

        $subject = trim((string) ($_POST['subject'] ?? ''));
        $message = trim((string) ($_POST['message'] ?? ''));
        $messageType = 'admin_broadcast';

        if ($subject === '' || $message === '') {
            throw new Exception('Subject and message are required.');
        }

        $stmt = $db->query("SELECT name, email FROM users WHERE email IS NOT NULL AND email != ''");
        $allUsers = $stmt->fetchAll();

        $messages = [];
        foreach ($allUsers as $user) {
            $recipientEmail = strtolower(trim((string) $user['email']));
            $templateData = [
                'name' => htmlspecialchars((string) $user['name'], ENT_QUOTES, 'UTF-8'),
                'email' => htmlspecialchars($recipientEmail, ENT_QUOTES, 'UTF-8'),
                'site_url' => SITE_URL,
                'company_name' => COMPANY_NAME,
                'current_year' => date('Y'),
                'custom_message' => nl2br(htmlspecialchars($message, ENT_QUOTES, 'UTF-8')),
            ];

            $messages[] = [
                'message_type' => $messageType,
                'recipient_email' => $recipientEmail,
                'recipient_name' => (string) $user['name'],
                'subject' => $subject,
                'template_name' => 'pending_donation',
                'template_data' => $templateData,
                'priority' => EmailQueue::PRIORITY_LOW,
                'max_attempts' => EMAIL_MAX_RETRIES,
                'dedupe_key' => $queue->buildDedupeKey($messageType, $recipientEmail, ['subject' => $subject, 'message' => $message], EMAIL_DEDUPLICATION_WINDOW_HOURS),
            ];
        }

        $result = $queue->queueBulk($messages);
        $success = sprintf(
            'Queued %d email(s). %d duplicate item(s) were skipped.',
            $result['queued'],
            $result['duplicates']
        );
    }

    $stmt = $db->query("SELECT COUNT(*) FROM users WHERE email IS NOT NULL AND email != ''");
    $totalUsers = (int) $stmt->fetchColumn();
    $queueStats = $queue->getStats();
    $recentAttempts = $queue->getRecentAttempts(12);
    $estimatedHours = (int) ceil(max(1, $queueStats['pending']) / max(1, EMAIL_MAX_PER_HOUR));
} catch (Throwable $e) {
    error_log('Error in emails.php: ' . $e->getMessage());
    $error = 'Unable to load email queue information.';
    $totalUsers = 0;
    $queueStats = ['pending' => 0, 'processing' => 0, 'sent' => 0, 'failed' => 0, 'sent_last_hour' => 0, 'provider_limit_per_hour' => EMAIL_MAX_PER_HOUR];
    $recentAttempts = [];
    $estimatedHours = 0;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(secure_random_bytes(32));
}

admin_shell_start('emails', 'Email Queue Management', 'Queue-first outbound email management for controlled delivery.');
if ($error) {
    admin_alert($error, 'error');
}
if ($success) {
    admin_alert($success, 'success');
}
?>
<div class="grid grid-cols-1 gap-6 xl:grid-cols-[minmax(0,1fr)_360px]">
    <section class="admin-card rounded-3xl p-6">
        <h3 class="text-xl font-semibold text-white">Queue a broadcast</h3>
        <p class="mt-1 text-sm text-slate-400">Web requests only enqueue email jobs. Delivery is handled separately by the queue worker.</p>

        <div class="mt-6 grid grid-cols-1 gap-4 md:grid-cols-3">
            <div class="rounded-2xl border border-white/10 bg-white/5 p-4">
                <p class="text-xs uppercase tracking-[0.18em] text-slate-500">Audience</p>
                <p class="mt-2 text-2xl font-semibold text-white"><?= $totalUsers ?></p>
                <p class="mt-1 text-xs text-slate-400">Reachable users</p>
            </div>
            <div class="rounded-2xl border border-white/10 bg-white/5 p-4">
                <p class="text-xs uppercase tracking-[0.18em] text-slate-500">Provider limit</p>
                <p class="mt-2 text-2xl font-semibold text-white"><?= EMAIL_MAX_PER_HOUR ?></p>
                <p class="mt-1 text-xs text-slate-400">Emails per hour</p>
            </div>
            <div class="rounded-2xl border border-white/10 bg-white/5 p-4">
                <p class="text-xs uppercase tracking-[0.18em] text-slate-500">Projected queue time</p>
                <p class="mt-2 text-2xl font-semibold text-white"><?= $estimatedHours ?></p>
                <p class="mt-1 text-xs text-slate-400">Hour(s) for current pending volume</p>
            </div>
        </div>

        <div class="mt-6 rounded-2xl border border-cyan-500/20 bg-cyan-500/10 p-4 text-sm text-cyan-100">
            Recommended cron worker command: <code class="rounded bg-slate-950/40 px-2 py-1 text-cyan-50">php process_emails.php 20</code>
        </div>

        <form method="POST" class="mt-6 space-y-5">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
            <div>
                <label for="subject" class="mb-2 block text-sm font-medium text-slate-200">Subject</label>
                <input type="text" name="subject" id="subject" required class="admin-input" placeholder="Enter email subject">
            </div>
            <div>
                <label for="message" class="mb-2 block text-sm font-medium text-slate-200">Message</label>
                <textarea name="message" id="message" rows="10" required class="admin-input" placeholder="Enter your message"></textarea>
                <p class="mt-2 text-xs text-slate-400">Template variables: {name}, {email}, {site_url}, {company_name}</p>
            </div>
            <button type="submit" class="inline-flex w-full items-center justify-center gap-3 rounded-2xl bg-gradient-to-r from-crofund-500 to-emerald-600 px-4 py-3 text-sm font-semibold text-white">
                <span>Queue Broadcast</span>
            </button>
        </form>
    </section>

    <section class="admin-card rounded-3xl p-6">
        <h3 class="text-lg font-semibold text-white">Queue status</h3>
        <div class="mt-6 grid grid-cols-2 gap-4">
            <?php admin_stat_card('Pending', (string) $queueStats['pending'], 'fa-regular fa-clock', 'amber'); ?>
            <?php admin_stat_card('Processing', (string) $queueStats['processing'], 'fa-solid fa-gear', 'blue'); ?>
            <?php admin_stat_card('Sent', (string) $queueStats['sent'], 'fa-solid fa-circle-check', 'emerald'); ?>
            <?php admin_stat_card('Failed', (string) $queueStats['failed'], 'fa-solid fa-circle-xmark', 'rose'); ?>
        </div>
        <div class="mt-4 rounded-2xl border border-white/10 bg-white/5 p-4 text-sm text-slate-300">
            <div class="flex items-center justify-between gap-4">
                <span>Sent in last hour</span>
                <span class="font-semibold text-white"><?= (int) $queueStats['sent_last_hour'] ?> / <?= EMAIL_MAX_PER_HOUR ?></span>
            </div>
        </div>
    </section>
</div>

<section class="admin-card mt-6 rounded-3xl p-6">
    <h3 class="text-lg font-semibold text-white">Recent delivery attempts</h3>
    <div class="mt-6 overflow-x-auto">
        <table class="min-w-full text-sm text-slate-200">
            <thead>
                <tr>
                    <th class="px-4 py-3 text-left">Time</th>
                    <th class="px-4 py-3 text-left">Recipient</th>
                    <th class="px-4 py-3 text-left">Type</th>
                    <th class="px-4 py-3 text-left">Subject</th>
                    <th class="px-4 py-3 text-left">Result</th>
                    <th class="px-4 py-3 text-left">Worker</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recentAttempts as $attempt): ?>
                    <tr>
                        <td class="px-4 py-4 text-slate-400"><?= htmlspecialchars((string) $attempt['attempted_at']) ?></td>
                        <td class="px-4 py-4"><?= htmlspecialchars((string) $attempt['recipient_email']) ?></td>
                        <td class="px-4 py-4"><?= htmlspecialchars((string) $attempt['message_type']) ?></td>
                        <td class="px-4 py-4"><?= htmlspecialchars((string) $attempt['subject']) ?></td>
                        <td class="px-4 py-4">
                            <?php if ((int) $attempt['success'] === 1): ?>
                                <span class="rounded-full bg-crofund-500/15 px-3 py-1 text-xs font-semibold text-crofund-100">Sent</span>
                            <?php else: ?>
                                <span class="rounded-full bg-red-500/15 px-3 py-1 text-xs font-semibold text-red-200">Failed</span>
                                <?php if (!empty($attempt['error_message'])): ?>
                                    <div class="mt-1 text-xs text-slate-400"><?= htmlspecialchars((string) $attempt['error_message']) ?></div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-4 text-slate-400"><?= htmlspecialchars((string) ($attempt['worker_id'] ?? '')) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php if (!$recentAttempts): ?>
            <div class="py-12 text-center text-slate-400">No delivery attempts recorded yet.</div>
        <?php endif; ?>
    </div>
</section>
<?php admin_shell_end();
