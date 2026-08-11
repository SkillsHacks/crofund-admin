<?php
declare(strict_types=1);

require_once 'includes/config.php';
session_start();
require_once 'includes/auth.php';
$auth = new Auth();
$auth->requireAuth();
require_once 'includes/admin_layout.php';

$error = null;
$heldDonations = [];
$search = trim($_GET['search'] ?? '');

try {
    $db = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET,
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );

    $sql = "SELECT d.id, d.amount, d.status, d.donor_name, d.donor_email, d.transaction_id,
                   d.hold_reason, d.hold_at, c.id AS campaign_id, c.title AS campaign_title,
                   u.id AS user_id, u.name AS beneficiary_name, u.email AS beneficiary_email
            FROM donations d
            INNER JOIN campaigns c ON c.id = d.campaign_id
            INNER JOIN users u ON u.id = c.user_id
            WHERE COALESCE(d.is_on_hold, 0) = 1";
    $params = [];

    if ($search !== '') {
        $sql .= " AND (c.title LIKE :search OR u.name LIKE :search OR u.email LIKE :search OR d.donor_name LIKE :search OR d.donor_email LIKE :search OR d.transaction_id LIKE :search)";
        $params[':search'] = '%' . $search . '%';
    }

    $sql .= " ORDER BY d.hold_at DESC, d.id DESC";

    $stmt = $db->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    $heldDonations = $stmt->fetchAll() ?: [];
} catch (Throwable $e) {
    error_log('Error in held_donations.php: ' . $e->getMessage());
    $error = $e->getMessage() !== '' ? $e->getMessage() : 'An error occurred while loading held donations.';
}

admin_shell_start('heldDonations', 'Held Donations', 'Review donations that are currently on hold and require admin attention.');
if ($error) {
    admin_alert($error, 'error');
}
?>
<section class="admin-card rounded-3xl p-6">
    <form method="get" class="grid grid-cols-1 gap-4 md:grid-cols-[minmax(0,1fr),auto]">
        <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search by campaign, beneficiary, donor, or transaction ID" class="admin-input">
        <button type="submit" class="rounded-2xl bg-gradient-to-r from-crofund-500 to-emerald-600 px-5 py-3 text-sm font-semibold text-white">Search</button>
    </form>
</section>

<section class="admin-card table-shell mt-6 rounded-3xl p-0 overflow-hidden">
    <div class="px-6 py-5 border-b border-white/10">
        <h3 class="text-lg font-semibold text-white">Held donation records</h3>
    </div>
    <div class="overflow-x-auto">
        <table class="min-w-[1200px] w-full text-sm text-slate-200">
            <thead>
                <tr>
                    <th class="px-4 py-4 text-left">Campaign</th>
                    <th class="px-4 py-4 text-left">Beneficiary</th>
                    <th class="px-4 py-4 text-left">Donor</th>
                    <th class="px-4 py-4 text-left">Amount</th>
                    <th class="px-4 py-4 text-left">Reason</th>
                    <th class="px-4 py-4 text-left">Held at</th>
                    <th class="px-4 py-4 text-right">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($heldDonations as $donation): ?>
                    <tr>
                        <td class="px-4 py-4">
                            <div class="font-medium text-white"><?= htmlspecialchars((string) $donation['campaign_title']) ?></div>
                            <div class="mt-1 text-xs text-slate-500">Campaign #<?= (int) $donation['campaign_id'] ?></div>
                        </td>
                        <td class="px-4 py-4">
                            <div class="font-medium text-white"><?= htmlspecialchars((string) $donation['beneficiary_name']) ?></div>
                            <div class="mt-1 text-xs text-slate-400"><?= htmlspecialchars((string) $donation['beneficiary_email']) ?></div>
                        </td>
                        <td class="px-4 py-4">
                            <div class="font-medium text-white"><?= htmlspecialchars((string) (($donation['donor_name'] ?? '') !== '' ? $donation['donor_name'] : 'Anonymous')) ?></div>
                            <div class="mt-1 text-xs text-slate-400"><?= htmlspecialchars((string) (($donation['donor_email'] ?? '') !== '' ? $donation['donor_email'] : 'No email provided')) ?></div>
                        </td>
                        <td class="px-4 py-4 font-semibold text-amber-100">$<?= number_format((float) $donation['amount'], 2) ?></td>
                        <td class="px-4 py-4 text-slate-300"><?= htmlspecialchars((string) ($donation['hold_reason'] ?? '—')) ?></td>
                        <td class="px-4 py-4 text-slate-400"><?= htmlspecialchars((string) ($donation['hold_at'] ?? '—')) ?></td>
                        <td class="px-4 py-4 text-right">
                            <a href="campaign_donations.php?campaign_id=<?= (int) $donation['campaign_id'] ?>&status=on_hold" class="rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-xs font-semibold text-slate-100 hover:bg-white/10">Manage</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php if (empty($heldDonations)): ?>
        <div class="px-6 py-12 text-center text-slate-400">No donations are currently on hold.</div>
    <?php endif; ?>
</section>
<?php admin_shell_end();
