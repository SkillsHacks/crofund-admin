<?php
require_once 'includes/config.php';
require_once 'includes/auth.php';
session_start();

$auth = new Auth();
$auth->requireAuth();

require_once 'includes/admin_layout.php';

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
} catch (Exception $e) {
    die("DB connection failed");
}

$total_users = $db->query("SELECT COUNT(*) FROM users")->fetchColumn();
$total_campaigns = $db->query("SELECT COUNT(*) FROM campaigns")->fetchColumn();
$total_donations = $db->query("SELECT COALESCE(SUM(amount),0) FROM donations")->fetchColumn();
$total_withdrawn = $db->query("SELECT COALESCE(SUM(amount),0) FROM withdrawals WHERE status = 'completed'")->fetchColumn();

$sort = $_GET['sort'] ?? '';
$orderBy = 'u.created_at DESC';
if ($sort === 'donations_desc') {
    $orderBy = 'total_donations DESC';
} elseif ($sort === 'donations_asc') {
    $orderBy = 'total_donations ASC';
}

$stmt = $db->query("
    SELECT 
        u.id, 
        u.name, 
        u.email, 
        u.created_at, 
        (SELECT COUNT(*) FROM campaigns c WHERE c.user_id = u.id) AS campaigns_count,
        (SELECT COALESCE(SUM(amount),0) FROM donations d WHERE d.campaign_id IN (SELECT id FROM campaigns WHERE user_id = u.id)) AS total_donations,
        (SELECT COALESCE(SUM(amount),0) FROM withdrawals w WHERE w.user_id = u.id AND w.status = 'completed') AS total_withdrawn
    FROM users u
    ORDER BY $orderBy
");
$users = $stmt->fetchAll();

$users_per_month = $db->query("
    SELECT DATE_FORMAT(created_at, '%Y-%m') as month, COUNT(*) as count
    FROM users
    GROUP BY month
    ORDER BY month
")->fetchAll(PDO::FETCH_KEY_PAIR);

$donations_per_month = $db->query("
    SELECT DATE_FORMAT(created_at, '%Y-%m') as month, COALESCE(SUM(amount),0) as total
    FROM donations
    GROUP BY month
    ORDER BY month
")->fetchAll(PDO::FETCH_KEY_PAIR);

$withdrawals_per_month = $db->query("
    SELECT DATE_FORMAT(created_at, '%Y-%m') as month, COALESCE(SUM(amount),0) as total
    FROM withdrawals
    WHERE status = 'completed'
    GROUP BY month
    ORDER BY month
")->fetchAll(PDO::FETCH_KEY_PAIR);

$all_months = array_unique(array_merge(
    array_keys($users_per_month),
    array_keys($donations_per_month),
    array_keys($withdrawals_per_month)
));
sort($all_months);

$users_chart = [];
$donations_chart = [];
$withdrawals_chart = [];
foreach ($all_months as $m) {
    $users_chart[] = isset($users_per_month[$m]) ? (int)$users_per_month[$m] : 0;
    $donations_chart[] = isset($donations_per_month[$m]) ? (float)$donations_per_month[$m] : 0;
    $withdrawals_chart[] = isset($withdrawals_per_month[$m]) ? (float)$withdrawals_per_month[$m] : 0;
}

admin_shell_start(
    'dashboard',
    'Dashboard',
    'Operational overview for Crofund admins.',
    [
        ['label' => 'Manage Campaigns', 'href' => 'campaigns.php', 'icon' => 'fa-solid fa-bullhorn', 'primary' => true]
    ],
    ['<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>']
);
?>
<div class="grid grid-cols-1 gap-6 md:grid-cols-2 xl:grid-cols-4">
    <?php admin_stat_card('Total Users', (string) (int) $total_users, 'fa-solid fa-users', 'emerald', 'Registered on Crofund'); ?>
    <?php admin_stat_card('Total Campaigns', (string) (int) $total_campaigns, 'fa-solid fa-bullhorn', 'blue', 'Active and archived records'); ?>
    <?php admin_stat_card('Total Donations', '$' . number_format((float) $total_donations, 2), 'fa-solid fa-hand-holding-dollar', 'amber', 'All-time donation volume'); ?>
    <?php admin_stat_card('Total Withdrawn', '$' . number_format((float) $total_withdrawn, 2), 'fa-solid fa-money-bill-transfer', 'purple', 'Completed withdrawals only'); ?>
</div>

<div class="mt-8 grid grid-cols-1 gap-6 xl:grid-cols-3">
    <section class="admin-card rounded-3xl p-6 xl:col-span-1">
        <h3 class="text-lg font-semibold text-white">Users per month</h3>
        <p class="mt-1 text-sm text-slate-400">Growth of registered accounts over time.</p>
        <div class="mt-6 h-72"><canvas id="usersChart"></canvas></div>
    </section>
    <section class="admin-card rounded-3xl p-6 xl:col-span-1">
        <h3 class="text-lg font-semibold text-white">Donations per month</h3>
        <p class="mt-1 text-sm text-slate-400">Track incoming support volume.</p>
        <div class="mt-6 h-72"><canvas id="donationsChart"></canvas></div>
    </section>
    <section class="admin-card rounded-3xl p-6 xl:col-span-1">
        <h3 class="text-lg font-semibold text-white">Withdrawals per month</h3>
        <p class="mt-1 text-sm text-slate-400">Completed withdrawal trends.</p>
        <div class="mt-6 h-72"><canvas id="withdrawalsChart"></canvas></div>
    </section>
</div>

<section class="admin-card table-shell mt-8 rounded-3xl p-6">
    <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
        <div>
            <h3 class="text-xl font-semibold text-white">User activity</h3>
            <p class="mt-1 text-sm text-slate-400">Sort users by donation performance and review platform participation.</p>
        </div>
        <form method="get" class="flex flex-wrap items-center gap-3">
            <select name="sort" class="admin-input admin-select min-w-[220px]">
                <option value="">Sort by</option>
                <option value="donations_desc" <?= $sort === 'donations_desc' ? 'selected' : '' ?>>Most Donations</option>
                <option value="donations_asc" <?= $sort === 'donations_asc' ? 'selected' : '' ?>>Least Donations</option>
            </select>
            <button class="inline-flex items-center gap-2 rounded-2xl bg-gradient-to-r from-crofund-500 to-emerald-600 px-4 py-3 text-sm font-semibold text-white">
                <i class="fa-solid fa-sliders"></i>
                <span>Apply</span>
            </button>
        </form>
    </div>

    <div class="mt-6 overflow-x-auto">
        <table class="min-w-full text-sm text-slate-200">
            <thead>
                <tr>
                    <th class="px-4 py-3 text-left">ID</th>
                    <th class="px-4 py-3 text-left">Name</th>
                    <th class="px-4 py-3 text-left">Email</th>
                    <th class="px-4 py-3 text-left">Campaigns</th>
                    <th class="px-4 py-3 text-left">Donations</th>
                    <th class="px-4 py-3 text-left">Withdrawn</th>
                    <th class="px-4 py-3 text-left">Joined</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $user): ?>
                    <tr>
                        <td class="px-4 py-4"><?= (int) $user['id'] ?></td>
                        <td class="px-4 py-4 font-medium text-white"><?= htmlspecialchars($user['name']) ?></td>
                        <td class="px-4 py-4 text-slate-300"><?= htmlspecialchars($user['email']) ?></td>
                        <td class="px-4 py-4"><?= (int) $user['campaigns_count'] ?></td>
                        <td class="px-4 py-4 font-semibold text-crofund-300">$<?= number_format((float) $user['total_donations'], 2) ?></td>
                        <td class="px-4 py-4 font-semibold text-sky-300">$<?= number_format((float) $user['total_withdrawn'], 2) ?></td>
                        <td class="px-4 py-4 text-slate-400"><?= date('M d, Y', strtotime($user['created_at'])) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php if (empty($users)): ?>
            <div class="py-12 text-center text-slate-400">No users found.</div>
        <?php endif; ?>
    </div>
</section>
<?php
admin_shell_end([
    '<script>
    document.addEventListener("DOMContentLoaded", function () {
        const months = ' . json_encode($all_months) . ';
        const usersData = ' . json_encode($users_chart) . ';
        const donationsData = ' . json_encode($donations_chart) . ';
        const withdrawalsData = ' . json_encode($withdrawals_chart) . ';

        const commonOptions = {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                x: { ticks: { color: "#94a3b8" }, grid: { display: false } },
                y: { ticks: { color: "#94a3b8" }, grid: { color: "rgba(148,163,184,0.12)" } }
            }
        };

        new Chart(document.getElementById("usersChart"), {
            type: "line",
            data: {
                labels: months,
                datasets: [{
                    data: usersData,
                    borderColor: "rgba(52, 211, 153, 1)",
                    backgroundColor: "rgba(52, 211, 153, 0.12)",
                    tension: 0.35,
                    fill: true,
                    pointRadius: 3
                }]
            },
            options: commonOptions
        });

        new Chart(document.getElementById("donationsChart"), {
            type: "bar",
            data: {
                labels: months,
                datasets: [{
                    data: donationsData,
                    backgroundColor: "rgba(56, 189, 248, 0.75)",
                    borderRadius: 12,
                    barPercentage: 0.62
                }]
            },
            options: commonOptions
        });

        new Chart(document.getElementById("withdrawalsChart"), {
            type: "bar",
            data: {
                labels: months,
                datasets: [{
                    data: withdrawalsData,
                    backgroundColor: "rgba(167, 139, 250, 0.75)",
                    borderRadius: 12,
                    barPercentage: 0.62
                }]
            },
            options: commonOptions
        });
    });
    </script>'
]);
