<?php
declare(strict_types=1);

require_once 'includes/config.php';
session_start();
require_once 'includes/auth.php';
$auth = new Auth();
$auth->requireAuth();
require_once 'includes/admin_layout.php';

$error = null;
$success = null;
$campaign = null;
$donations = [];

$page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
$perPage = 15;
$offset = ($page - 1) * $perPage;

$campaignId = filter_input(INPUT_GET, 'campaign_id', FILTER_VALIDATE_INT);
$search = trim($_GET['search'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');
$gatewayFilter = trim($_GET['gateway'] ?? '');
$sort = trim($_GET['sort'] ?? 'latest');
$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo = trim($_GET['date_to'] ?? '');

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(secure_random_bytes(32));
}

try {
    if (!$campaignId) {
        throw new Exception('Invalid campaign selected.');
    }

    $db = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET,
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_donation') {
        if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
            throw new Exception('CSRF token validation failed.');
        }

        $postedCampaignId = filter_input(INPUT_POST, 'campaign_id', FILTER_VALIDATE_INT);
        $donationId = filter_input(INPUT_POST, 'donation_id', FILTER_VALIDATE_INT);

        if (!$postedCampaignId || $postedCampaignId !== $campaignId || !$donationId) {
            throw new Exception('Invalid donation delete request.');
        }

        $deleteStmt = $db->prepare('DELETE FROM donations WHERE id = ? AND campaign_id = ?');
        $deleteStmt->execute([$donationId, $campaignId]);

        if ($deleteStmt->rowCount() < 1) {
            throw new Exception('Donation not found or already deleted.');
        }

        $query = $_GET;
        $query['campaign_id'] = $campaignId;
        $query['msg'] = 'Donation deleted successfully.';
        unset($query['page']);
        header('Location: campaign_donations.php?' . http_build_query($query));
        exit;
    }

    $campaignStmt = $db->prepare(
        "SELECT c.*, u.name AS creator_name, u.email AS creator_email
         FROM campaigns c
         LEFT JOIN users u ON c.user_id = u.id
         WHERE c.id = ?"
    );
    $campaignStmt->execute([$campaignId]);
    $campaign = $campaignStmt->fetch();

    if (!$campaign) {
        throw new Exception('Campaign not found.');
    }

    if (!empty($_GET['msg'])) {
        $success = (string) $_GET['msg'];
    }

    $where = ['d.campaign_id = :campaign_id'];
    $params = [':campaign_id' => $campaignId];

    if ($search !== '') {
        $where[] = '(d.donor_name LIKE :search OR d.donor_email LIKE :search OR d.transaction_id LIKE :search)';
        $params[':search'] = '%' . $search . '%';
    }

    if ($statusFilter !== '') {
        $where[] = 'd.status = :status';
        $params[':status'] = $statusFilter;
    }

    if ($gatewayFilter !== '') {
        $where[] = 'd.payment_gateway = :gateway';
        $params[':gateway'] = $gatewayFilter;
    }

    if ($dateFrom !== '') {
        $where[] = 'DATE(d.created_at) >= :date_from';
        $params[':date_from'] = $dateFrom;
    }

    if ($dateTo !== '') {
        $where[] = 'DATE(d.created_at) <= :date_to';
        $params[':date_to'] = $dateTo;
    }

    $whereSql = 'WHERE ' . implode(' AND ', $where);

    $orderBy = match ($sort) {
        'oldest' => 'd.created_at ASC, d.id ASC',
        'highest' => 'd.amount DESC, d.created_at DESC',
        'lowest' => 'd.amount ASC, d.created_at DESC',
        default => 'd.created_at DESC, d.id DESC',
    };

    $countStmt = $db->prepare("SELECT COUNT(*) FROM donations d $whereSql");
    $countStmt->execute($params);
    $totalDonations = (int) $countStmt->fetchColumn();
    $totalPages = max(1, (int) ceil($totalDonations / $perPage));
    if ($page > $totalPages) {
        $page = $totalPages;
        $offset = ($page - 1) * $perPage;
    }

    $totalsStmt = $db->prepare(
        "SELECT COUNT(*) AS donation_count, COALESCE(SUM(d.amount), 0) AS total_amount
         FROM donations d
         $whereSql"
    );
    $totalsStmt->execute($params);
    $totals = $totalsStmt->fetch() ?: ['donation_count' => 0, 'total_amount' => 0];

    $donationSql =
        "SELECT d.id, d.amount, d.payment_gateway, d.status, d.country, d.postal_code,
                d.transaction_id, d.donor_name, d.donor_email, d.payment_id, d.created_at
         FROM donations d
         $whereSql
         ORDER BY $orderBy
         LIMIT :limit OFFSET :offset";

    $donationStmt = $db->prepare($donationSql);
    foreach ($params as $key => $value) {
        $donationStmt->bindValue($key, $value);
    }
    $donationStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $donationStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $donationStmt->execute();
    $donations = $donationStmt->fetchAll();

    $gatewayStmt = $db->prepare('SELECT DISTINCT payment_gateway FROM donations WHERE campaign_id = ? AND payment_gateway IS NOT NULL AND payment_gateway != "" ORDER BY payment_gateway');
    $gatewayStmt->execute([$campaignId]);
    $gateways = $gatewayStmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    error_log('Error in campaign_donations.php: ' . $e->getMessage());
    $error = $e->getMessage() !== '' ? $e->getMessage() : 'An error occurred while loading campaign donations.';
    $gateways = [];
    $totals = ['donation_count' => 0, 'total_amount' => 0];
    $totalPages = 1;
    $totalDonations = 0;
}

$pageTitle = $campaign ? 'Campaign Donations' : 'Campaign Donations';
$pageSubtitle = $campaign
    ? 'Review donations for "' . $campaign['title'] . '" and manage individual records.'
    : 'Review donations for a selected campaign.';

admin_shell_start(
    'campaigns',
    $pageTitle,
    $pageSubtitle,
    [
        ['label' => 'Back to Campaigns', 'href' => 'campaigns.php', 'icon' => 'fa-solid fa-arrow-left', 'primary' => false],
    ]
);

if ($error) {
    admin_alert($error, 'error');
}
if ($success) {
    admin_alert($success, 'success');
}
?>

<?php if ($campaign): ?>
    <div class="grid grid-cols-1 gap-6 xl:grid-cols-[minmax(0,1fr)_320px]">
        <section class="admin-card rounded-3xl p-6">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.22em] text-crofund-200/80">Campaign</p>
                    <h3 class="mt-2 text-2xl font-semibold text-white"><?= htmlspecialchars((string) $campaign['title']) ?></h3>
                    <p class="mt-2 text-sm text-slate-400"><?= htmlspecialchars((string) $campaign['description']) ?></p>
                </div>
                <div class="rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-sm text-slate-300">
                    <p><span class="text-slate-500">Status:</span> <?= htmlspecialchars((string) $campaign['status']) ?></p>
                    <p class="mt-1"><span class="text-slate-500">Creator:</span> <?= htmlspecialchars((string) ($campaign['creator_name'] ?? 'Unknown')) ?></p>
                    <p class="mt-1"><span class="text-slate-500">Goal:</span> $<?= number_format((float) $campaign['goal_amount'], 2) ?></p>
                </div>
            </div>
        </section>

        <section class="admin-card rounded-3xl p-6">
            <h3 class="text-lg font-semibold text-white">Filtered donation summary</h3>
            <div class="mt-6 space-y-4">
                <div class="rounded-2xl border border-white/10 bg-white/5 p-4">
                    <p class="text-xs uppercase tracking-[0.18em] text-slate-500">Donations</p>
                    <p class="mt-2 text-2xl font-semibold text-white"><?= (int) ($totals['donation_count'] ?? 0) ?></p>
                </div>
                <div class="rounded-2xl border border-white/10 bg-white/5 p-4">
                    <p class="text-xs uppercase tracking-[0.18em] text-slate-500">Amount</p>
                    <p class="mt-2 text-2xl font-semibold text-crofund-300">$<?= number_format((float) ($totals['total_amount'] ?? 0), 2) ?></p>
                </div>
            </div>
        </section>
    </div>

    <section class="admin-card mt-6 rounded-3xl p-6">
        <form method="get" class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-7">
            <input type="hidden" name="campaign_id" value="<?= (int) $campaign['id'] ?>">
            <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search donor name, email, or transaction ID" class="admin-input xl:col-span-2">
            <select name="status" class="admin-input admin-select">
                <option value="">All statuses</option>
                <?php foreach (['completed', 'pending', 'failed', 'refunded'] as $statusOption): ?>
                    <option value="<?= $statusOption ?>" <?= $statusFilter === $statusOption ? 'selected' : '' ?>><?= ucfirst($statusOption) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="gateway" class="admin-input admin-select">
                <option value="">All gateways</option>
                <?php foreach ($gateways as $gateway): ?>
                    <option value="<?= htmlspecialchars((string) $gateway) ?>" <?= $gatewayFilter === $gateway ? 'selected' : '' ?>><?= htmlspecialchars((string) ucfirst((string) $gateway)) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="sort" class="admin-input admin-select">
                <option value="latest" <?= $sort === 'latest' ? 'selected' : '' ?>>Latest donations</option>
                <option value="oldest" <?= $sort === 'oldest' ? 'selected' : '' ?>>Oldest donations</option>
                <option value="highest" <?= $sort === 'highest' ? 'selected' : '' ?>>Highest amount</option>
                <option value="lowest" <?= $sort === 'lowest' ? 'selected' : '' ?>>Lowest amount</option>
            </select>
            <input type="date" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>" class="admin-input">
            <input type="date" name="date_to" value="<?= htmlspecialchars($dateTo) ?>" class="admin-input">
            <div class="flex flex-wrap gap-3 xl:col-span-7">
                <button class="rounded-2xl bg-gradient-to-r from-crofund-500 to-emerald-600 px-4 py-3 text-sm font-semibold text-white">Apply Filters</button>
                <a href="campaign_donations.php?campaign_id=<?= (int) $campaign['id'] ?>" class="rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-sm font-semibold text-slate-200 hover:bg-white/10">Reset</a>
            </div>
        </form>
    </section>

    <section class="admin-card table-shell mt-6 rounded-3xl p-0 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="min-w-[1250px] w-full text-sm text-slate-200">
                <thead>
                    <tr>
                        <th class="px-4 py-4 text-left">Donor</th>
                        <th class="px-4 py-4 text-left">Amount</th>
                        <th class="px-4 py-4 text-left">Gateway</th>
                        <th class="px-4 py-4 text-left">Status</th>
                        <th class="px-4 py-4 text-left">Country</th>
                        <th class="px-4 py-4 text-left">Transaction</th>
                        <th class="px-4 py-4 text-left">Created</th>
                        <th class="px-4 py-4 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($donations as $donation): ?>
                        <tr>
                            <td class="px-4 py-4">
                                <div class="font-medium text-white"><?= htmlspecialchars((string) ($donation['donor_name'] ?: 'Anonymous')) ?></div>
                                <div class="mt-1 text-xs text-slate-400"><?= htmlspecialchars((string) ($donation['donor_email'] ?: 'No email provided')) ?></div>
                            </td>
                            <td class="px-4 py-4 font-semibold text-crofund-300">$<?= number_format((float) $donation['amount'], 2) ?></td>
                            <td class="px-4 py-4 capitalize"><?= htmlspecialchars((string) ($donation['payment_gateway'] ?: 'Unknown')) ?></td>
                            <td class="px-4 py-4">
                                <?php
                                $status = (string) $donation['status'];
                                $statusClass = match ($status) {
                                    'completed' => 'bg-crofund-500/15 text-crofund-100',
                                    'pending' => 'bg-amber-500/15 text-amber-200',
                                    'failed' => 'bg-red-500/15 text-red-200',
                                    'refunded' => 'bg-sky-500/15 text-sky-200',
                                    default => 'bg-white/10 text-slate-200',
                                };
                                ?>
                                <span class="rounded-full px-3 py-1 text-xs font-semibold <?= $statusClass ?>"><?= htmlspecialchars(ucfirst($status)) ?></span>
                            </td>
                            <td class="px-4 py-4 text-slate-300"><?= htmlspecialchars((string) ($donation['country'] ?: '—')) ?></td>
                            <td class="px-4 py-4 text-slate-300">
                                <div><?= htmlspecialchars((string) ($donation['transaction_id'] ?: '—')) ?></div>
                                <?php if (!empty($donation['payment_id'])): ?>
                                    <div class="mt-1 text-xs text-slate-500">Payment ID: <?= htmlspecialchars((string) $donation['payment_id']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-4 text-slate-400"><?= htmlspecialchars((string) ($donation['created_at'] ?: '—')) ?></td>
                            <td class="px-4 py-4 text-right">
                                <form method="POST" class="inline-block" onsubmit="return confirm('Delete this donation from the selected campaign?');">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                    <input type="hidden" name="action" value="delete_donation">
                                    <input type="hidden" name="campaign_id" value="<?= (int) $campaign['id'] ?>">
                                    <input type="hidden" name="donation_id" value="<?= (int) $donation['id'] ?>">
                                    <button type="submit" class="rounded-xl border border-red-500/30 bg-red-500/10 px-3 py-2 text-xs font-semibold text-red-100 hover:bg-red-500/15">Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if (empty($donations)): ?>
            <div class="px-6 py-12 text-center text-slate-400">No donations matched your current filters for this campaign.</div>
        <?php endif; ?>
    </section>

    <?php if (($totalPages ?? 1) > 1): ?>
        <section class="admin-card mt-6 rounded-3xl p-5">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <p class="text-sm text-slate-400">
                    Showing <span class="font-semibold text-white"><?= $totalDonations > 0 ? $offset + 1 : 0 ?></span>
                    - <span class="font-semibold text-white"><?= min($offset + $perPage, $totalDonations) ?></span>
                    of <span class="font-semibold text-white"><?= $totalDonations ?></span>
                </p>
                <div class="flex flex-wrap gap-2">
                    <?php if ($page > 1): ?>
                        <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>" class="rounded-2xl border border-white/10 bg-white/5 px-4 py-2 text-sm text-slate-200 hover:bg-white/10">Previous</a>
                    <?php endif; ?>
                    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                        <a href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>" class="rounded-2xl px-4 py-2 text-sm <?= $i === $page ? 'bg-crofund-500 text-white' : 'border border-white/10 bg-white/5 text-slate-200 hover:bg-white/10' ?>"><?= $i ?></a>
                    <?php endfor; ?>
                    <?php if ($page < $totalPages): ?>
                        <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>" class="rounded-2xl border border-white/10 bg-white/5 px-4 py-2 text-sm text-slate-200 hover:bg-white/10">Next</a>
                    <?php endif; ?>
                </div>
            </div>
        </section>
    <?php endif; ?>
<?php endif; ?>

<?php admin_shell_end();
