<?php
declare(strict_types=1);

require_once 'includes/config.php';
require_once 'includes/auth.php';
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

    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(secure_random_bytes(32));
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
            throw new Exception('Request could not be processed.');
        }

        $email = trim((string) ($_POST['paypal_business_email'] ?? ''));
        if ($email === '') {
            throw new Exception('PayPal business email is required.');
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception('Please enter a valid PayPal business email address.');
        }

        $existing = $db->prepare('SELECT id FROM settings WHERE setting_key = :setting_key LIMIT 1');
        $existing->execute([':setting_key' => 'paypal_business_email']);

        if ($existing->fetch()) {
            $stmt = $db->prepare('UPDATE settings SET setting_value = :setting_value, updated_at = NOW() WHERE setting_key = :setting_key');
            $stmt->execute([
                ':setting_key' => 'paypal_business_email',
                ':setting_value' => $email,
            ]);
        } else {
            $stmt = $db->prepare('INSERT INTO settings (setting_key, setting_value, created_at, updated_at) VALUES (:setting_key, :setting_value, NOW(), NOW())');
            $stmt->execute([
                ':setting_key' => 'paypal_business_email',
                ':setting_value' => $email,
            ]);
        }

        $success = 'PayPal business email saved successfully.';
    }

    $stmt = $db->prepare('SELECT setting_value FROM settings WHERE setting_key = :setting_key LIMIT 1');
    $stmt->execute([':setting_key' => 'paypal_business_email']);
    $setting = $stmt->fetch();
    $paypalBusinessEmail = $setting['setting_value'] ?? '';
} catch (Throwable $e) {
    error_log('Error in settings.php: ' . $e->getMessage());
    $error = 'Unable to load or save settings right now.';
    $paypalBusinessEmail = '';
}

admin_shell_start('settings', 'Settings', 'Manage system-level admin settings.');

if ($error) {
    admin_alert($error, 'error');
}
if ($success) {
    admin_alert($success, 'success');
}
?>
<div class="grid grid-cols-1 gap-6 xl:grid-cols-[minmax(0,1fr)_360px]">
    <section class="admin-card rounded-3xl p-6">
        <h3 class="text-xl font-semibold text-white">PayPal configuration</h3>
        <p class="mt-1 text-sm text-slate-400">Update the PayPal business email used by the donation workflow.</p>

        <form method="POST" class="mt-6 space-y-5">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">

            <div>
                <label for="paypal_business_email" class="mb-2 block text-sm font-medium text-slate-200">PayPal business email</label>
                <input
                    type="email"
                    id="paypal_business_email"
                    name="paypal_business_email"
                    value="<?= htmlspecialchars($paypalBusinessEmail, ENT_QUOTES, 'UTF-8') ?>"
                    class="admin-input"
                    placeholder="name@paypal.com"
                    required
                >
            </div>

            <button type="submit" class="inline-flex w-full items-center justify-center gap-3 rounded-2xl bg-gradient-to-r from-crofund-500 to-emerald-600 px-4 py-3 text-sm font-semibold text-white">
                <i class="fa-solid fa-floppy-disk"></i>
                <span>Save settings</span>
            </button>
        </form>
    </section>

    <aside class="admin-card rounded-3xl p-6">
        <h3 class="text-lg font-semibold text-white">Important note</h3>
        <ul class="mt-4 space-y-3 text-sm text-slate-300">
            <li class="flex gap-3"><i class="fa-solid fa-circle-info mt-1 text-cyan-300"></i><span>This value is read from the <strong class="text-white">settings</strong> table by the donation flow.</span></li>
            <li class="flex gap-3"><i class="fa-solid fa-shield-halved mt-1 text-emerald-300"></i><span>Use the same PayPal business account email that receives payouts.</span></li>
            <li class="flex gap-3"><i class="fa-solid fa-clock mt-1 text-amber-300"></i><span>Changes take effect on the next use of the payment form.</span></li>
        </ul>
    </aside>
</div>
<?php admin_shell_end();
