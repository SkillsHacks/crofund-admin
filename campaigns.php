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
$campaigns = [];
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$perPage = 10;
$offset = ($page - 1) * $perPage;
$search = trim($_GET['search'] ?? '');
$statusFilter = $_GET['status'] ?? '';
$categoryFilter = $_GET['category'] ?? '';
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';

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

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
        if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
            throw new Exception('CSRF token validation failed');
        }

        $campaignId = filter_input(INPUT_POST, 'campaign_id', FILTER_VALIDATE_INT);
        if (!$campaignId) {
            throw new Exception('Invalid campaign ID');
        }

        $db->beginTransaction();
        try {
            $db->prepare('DELETE FROM donations WHERE campaign_id = ?')->execute([$campaignId]);
            $db->prepare('DELETE FROM campaigns WHERE id = ?')->execute([$campaignId]);
            $db->commit();
            $success = 'Campaign and associated donations deleted successfully.';
        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }
    }

    $where = [];
    $params = [];
    if ($search !== '') {
        $where[] = '(c.title LIKE :search OR u.name LIKE :search OR u.email LIKE :search)';
        $params[':search'] = "%$search%";
    }
    if ($statusFilter !== '') {
        $where[] = 'c.status = :status';
        $params[':status'] = $statusFilter;
    }
    if ($categoryFilter !== '') {
        $where[] = 'c.category = :category';
        $params[':category'] = $categoryFilter;
    }
    if ($dateFrom !== '') {
        $where[] = 'DATE(c.created_at) >= :date_from';
        $params[':date_from'] = $dateFrom;
    }
    if ($dateTo !== '') {
        $where[] = 'DATE(c.created_at) <= :date_to';
        $params[':date_to'] = $dateTo;
    }
    $whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $countStmt = $db->prepare("
        SELECT COUNT(DISTINCT c.id)
        FROM campaigns c
        LEFT JOIN users u ON c.user_id = u.id
        $whereSQL
    ");
    $countStmt->execute($params);
    $totalCampaigns = (int) $countStmt->fetchColumn();
    $totalPages = (int) ceil($totalCampaigns / $perPage);

    $sql = "
        SELECT 
            c.*,
            u.name as creator_name,
            u.email as creator_email,
            COUNT(DISTINCT d.id) as donation_count,
            COALESCE(SUM(d.amount), 0) as total_raised
        FROM campaigns c
        LEFT JOIN users u ON c.user_id = u.id
        LEFT JOIN donations d ON c.id = d.campaign_id
        $whereSQL
        GROUP BY c.id
        ORDER BY c.created_at DESC
        LIMIT :limit OFFSET :offset
    ";
    $stmt = $db->prepare($sql);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $campaigns = $stmt->fetchAll();

    $categories = $db->query('SELECT DISTINCT category FROM campaigns ORDER BY category')->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    error_log('Error in campaigns.php: ' . $e->getMessage());
    $error = 'An error occurred while processing your request.';
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(secure_random_bytes(32));
}

admin_shell_start('campaigns', 'Manage Campaigns', 'Search, filter, and review Crofund fundraising campaigns.');
if ($error) {
    admin_alert($error, 'error');
}
if ($success) {
    admin_alert($success, 'success');
}
?>
<section class="admin-card rounded-3xl p-6">
    <form method="get" class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-6">
        <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search title, creator, or email" class="admin-input xl:col-span-2">
        <select name="status" class="admin-input admin-select">
            <option value="">All Statuses</option>
            <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active</option>
            <option value="inactive" <?= $statusFilter === 'inactive' ? 'selected' : '' ?>>Inactive</option>
            <option value="pending" <?= $statusFilter === 'pending' ? 'selected' : '' ?>>Pending</option>
        </select>
        <select name="category" class="admin-input admin-select">
            <option value="">All Categories</option>
            <?php foreach ($categories as $cat): ?>
                <option value="<?= htmlspecialchars($cat) ?>" <?= $categoryFilter === $cat ? 'selected' : '' ?>><?= htmlspecialchars($cat) ?></option>
            <?php endforeach; ?>
        </select>
        <input type="date" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>" class="admin-input">
        <input type="date" name="date_to" value="<?= htmlspecialchars($dateTo) ?>" class="admin-input">
        <button class="rounded-2xl bg-gradient-to-r from-crofund-500 to-emerald-600 px-4 py-3 text-sm font-semibold text-white xl:col-span-6 xl:max-w-[180px]">Filter</button>
    </form>
</section>

<section class="admin-card table-shell mt-6 rounded-3xl p-0 overflow-hidden">
    <div class="overflow-x-auto">
        <table class="min-w-[1000px] w-full text-sm text-slate-200">
            <thead>
                <tr>
                    <th class="px-4 py-4 text-left">Campaign</th>
                    <th class="px-4 py-4 text-left">Creator</th>
                    <th class="px-4 py-4 text-left">Progress</th>
                    <th class="px-4 py-4 text-left">Status</th>
                    <th class="px-4 py-4 text-left">Created</th>
                    <th class="px-4 py-4 text-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($campaigns as $campaign): ?>
                    <tr>
                        <td class="px-4 py-4">
                            <div class="font-medium text-white"><?= htmlspecialchars($campaign['title']) ?></div>
                            <div class="mt-1 text-xs text-slate-400"><?= htmlspecialchars($campaign['category']) ?></div>
                        </td>
                        <td class="px-4 py-4">
                            <div><?= htmlspecialchars($campaign['creator_name']) ?></div>
                            <div class="mt-1 text-xs text-slate-400"><?= htmlspecialchars($campaign['creator_email']) ?></div>
                        </td>
                        <td class="px-4 py-4">
                            <div class="font-medium text-white">$<?= number_format((float) $campaign['total_raised'], 2) ?> / $<?= number_format((float) $campaign['goal_amount'], 2) ?></div>
                            <div class="mt-1 text-xs text-slate-400"><?= (int) $campaign['donation_count'] ?> donations</div>
                        </td>
                        <td class="px-4 py-4">
                            <span class="rounded-full px-3 py-1 text-xs font-semibold <?= $campaign['status'] === 'active' ? 'bg-crofund-500/15 text-crofund-100' : ($campaign['status'] === 'pending' ? 'bg-amber-500/15 text-amber-200' : 'bg-red-500/15 text-red-200') ?>">
                                <?= htmlspecialchars($campaign['status']) ?>
                            </span>
                        </td>
                        <td class="px-4 py-4 text-slate-400"><?= htmlspecialchars($campaign['created_at']) ?></td>
                        <td class="px-4 py-4 text-right">
                            <div class="flex flex-wrap justify-end gap-2">
                                <?php if ((int) (defined('ENABLE_CAMPAIGN_DONATIONS_VIEW') ? ENABLE_CAMPAIGN_DONATIONS_VIEW : 1) !== 0): ?>
                                    <a href="campaign_donations.php?campaign_id=<?= (int) $campaign['id'] ?>" class="rounded-xl border border-crofund-500/30 bg-crofund-500/10 px-3 py-2 text-xs font-semibold text-crofund-100 hover:bg-crofund-500/15">View Donations</a>
                                <?php endif; ?>
                                <form method="POST" class="inline-block" onsubmit="return confirm('Are you sure you want to delete this campaign? All associated donations will also be deleted.');">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="campaign_id" value="<?= (int) $campaign['id'] ?>">
                                    <button type="submit" class="rounded-xl border border-red-500/30 bg-red-500/10 px-3 py-2 text-xs font-semibold text-red-100 hover:bg-red-500/15">Delete</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php if (empty($campaigns)): ?>
        <div class="px-6 py-12 text-center text-slate-400">No campaigns matched your current filters.</div>
    <?php endif; ?>
</section>

<?php if ($totalPages > 1): ?>
    <section class="admin-card mt-6 rounded-3xl p-5">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <p class="text-sm text-slate-400">
                Showing <span class="font-semibold text-white"><?= (($page - 1) * $perPage) + 1 ?></span>
                - <span class="font-semibold text-white"><?= min($page * $perPage, $totalCampaigns) ?></span>
                of <span class="font-semibold text-white"><?= $totalCampaigns ?></span>
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
<?php admin_shell_end();
