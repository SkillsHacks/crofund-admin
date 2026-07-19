<?php
declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';
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
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET,
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );

    $queue = new EmailQueue($db);
    $queue->ensureSchema();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
            throw new Exception('CSRF token validation failed');
        }

        $subject = trim($_POST['subject'] ?? '');
        $message = trim($_POST['message'] ?? '');

        if (empty($subject) || empty($message)) {
            throw new Exception('Subject and message are required');
        }

        $stmt = $db->query("SELECT DISTINCT donor_name, donor_email FROM donations WHERE donor_email IS NOT NULL AND donor_email != ''");
        $allDonors = $stmt->fetchAll();

        $messages = [];
        foreach ($allDonors as $donor) {
            $recipientEmail = strtolower(trim((string) $donor['donor_email']));
            $recipientName = (string) $donor['donor_name'];
            $templateData = [
                'name' => htmlspecialchars($recipientName, ENT_QUOTES, 'UTF-8'),
                'email' => htmlspecialchars($recipientEmail, ENT_QUOTES, 'UTF-8'),
                'site_url' => SITE_URL,
                'company_name' => COMPANY_NAME,
                'current_year' => date('Y'),
                'custom_message' => nl2br(htmlspecialchars($message, ENT_QUOTES, 'UTF-8')),
            ];

            $messages[] = [
                'message_type' => 'donor_broadcast',
                'recipient_email' => $recipientEmail,
                'recipient_name' => $recipientName,
                'subject' => $subject,
                'template_name' => 'pending_donation',
                'template_data' => $templateData,
                'priority' => EmailQueue::PRIORITY_LOW,
                'max_attempts' => EMAIL_MAX_RETRIES,
                'dedupe_key' => $queue->buildDedupeKey('donor_broadcast', $recipientEmail, ['subject' => $subject, 'message' => $message], EMAIL_DEDUPLICATION_WINDOW_HOURS),
            ];
        }

        $result = $queue->queueBulk($messages);
        $success = sprintf(
            'Queued %d donor email(s). %d duplicate item(s) were skipped.',
            $result['queued'],
            $result['duplicates']
        );
    }

    $stmt = $db->query("SELECT COUNT(DISTINCT donor_email) as total FROM donations WHERE donor_email IS NOT NULL AND donor_email != ''");
    $totalDonors = (int) $stmt->fetch()['total'];
} catch (Exception $e) {
    error_log('Error in donorsMail.php: ' . $e->getMessage());
    $error = 'An error occurred while processing your request: ' . $e->getMessage();
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(secure_random_bytes(32));
}

admin_shell_start('donorsMail', 'Send Email to Donors', 'Reach supporters directly from the Crofund admin console.');
if ($error) {
    admin_alert($error, 'error');
}
if ($success) {
    admin_alert($success, 'success');
}
?>
<div class="grid grid-cols-1 gap-6 xl:grid-cols-[minmax(0,1fr)_320px]">
    <section class="admin-card rounded-3xl p-6">
        <h3 class="text-xl font-semibold text-white">Donor email composer</h3>
        <p class="mt-1 text-sm text-slate-400">Personalize outreach with the template variables already supported by your mailer.</p>
        <form method="POST" class="mt-6 space-y-5">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
            <div>
                <label for="subject" class="mb-2 block text-sm font-medium text-slate-200">Subject</label>
                <input type="text" name="subject" id="subject" required class="admin-input" placeholder="Enter email subject">
            </div>
            <div>
                <label for="message" class="mb-2 block text-sm font-medium text-slate-200">Message</label>
                <textarea name="message" id="message" rows="10" required class="admin-input" placeholder="Write your donor message"></textarea>
                <p class="mt-2 text-xs text-slate-400">Available variables: {name}, {email}, {site_url}, {company_name}</p>
            </div>
            <button type="submit" class="inline-flex w-full items-center justify-center gap-3 rounded-2xl bg-gradient-to-r from-crofund-500 to-emerald-600 px-4 py-3 text-sm font-semibold text-white">
                <i class="fa-solid fa-paper-plane"></i>
                <span>Send Email to All Donors</span>
            </button>
        </form>
    </section>

    <section class="admin-card rounded-3xl p-6">
        <h3 class="text-lg font-semibold text-white">Audience snapshot</h3>
        <div class="mt-6 space-y-4">
            <?php admin_stat_card('Reachable Donors', (string) $totalDonors, 'fa-solid fa-users', 'emerald', 'Distinct donor emails on file'); ?>
        </div>
        <div class="mt-6 rounded-2xl border border-white/10 bg-white/5 p-4 text-sm text-slate-300">
            <p class="font-medium text-white">Best practice</p>
            <p class="mt-2 leading-6 text-slate-400">Keep donor announcements concise and action-oriented. Use placeholders to personalize trust-building updates.</p>
        </div>
    </section>
</div>
<?php admin_shell_end();
