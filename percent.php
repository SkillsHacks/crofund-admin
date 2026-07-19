<?php
declare(strict_types=1);

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
    die('DB connection failed');
}

$stmt = $db->query("SELECT amount, created_at FROM withdrawals WHERE status = 'completed' ORDER BY created_at ASC");
$withdrawals = $stmt->fetchAll();

$total_withdrawn = 0;
$total_commission = 0;
$nuulu = 0;
$kt = 0;
$withdrawals_by_month = [];

$getMonthKey = static function ($value): ?string {
    if (!is_string($value) || trim($value) === '') {
        return null;
    }

    $timestamp = strtotime($value);
    if ($timestamp === false) {
        return null;
    }

    return date('Y-m', $timestamp);
};

foreach ($withdrawals as $row) {
    $amount = (float) $row['amount'];
    $commission = $amount * 0.15;
    $nuulu += $commission * 0.6;
    $kt += $commission * 0.4;
    $total_commission += $commission;
    $total_withdrawn += $amount;

    $month = $getMonthKey($row['created_at'] ?? null);
    if ($month === null) {
        continue;
    }

    if (!isset($withdrawals_by_month[$month])) {
        $withdrawals_by_month[$month] = 0;
    }
    $withdrawals_by_month[$month] += $amount;
}

$stmt = $db->query('SELECT amount, created_at FROM donations ORDER BY created_at ASC');
$donations = $stmt->fetchAll();
$total_donated = 0;
$donations_by_month = [];
foreach ($donations as $row) {
    $amount = (float) $row['amount'];
    $total_donated += $amount;

    $month = $getMonthKey($row['created_at'] ?? null);
    if ($month === null) {
        continue;
    }

    if (!isset($donations_by_month[$month])) {
        $donations_by_month[$month] = 0;
    }
    $donations_by_month[$month] += $amount;
}

$months = array_unique(array_merge(array_keys($withdrawals_by_month), array_keys($donations_by_month)));
sort($months);

$withdrawals_chart = [];
$donations_chart = [];
foreach ($months as $m) {
    $withdrawals_chart[] = $withdrawals_by_month[$m] ?? 0;
    $donations_chart[] = $donations_by_month[$m] ?? 0;
}

admin_shell_start(
    'percent',
    'Percentages & Stats',
    'Revenue share and financial trend monitoring for Crofund admins.',
    [],
    ['<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>']
);
?>
<div class="grid grid-cols-1 gap-6 md:grid-cols-2 xl:grid-cols-5">
    <?php admin_stat_card('Total Withdrawn', '$' . number_format($total_withdrawn, 2), 'fa-solid fa-money-bill-transfer', 'emerald'); ?>
    <?php admin_stat_card('Total Donations', '$' . number_format($total_donated, 2), 'fa-solid fa-hand-holding-dollar', 'blue'); ?>
    <?php admin_stat_card('Commission (15%)', '$' . number_format($total_commission, 2), 'fa-solid fa-percent', 'amber'); ?>
    <?php admin_stat_card('Nile (60% of 15%)', '$' . number_format($nuulu, 2), 'fa-solid fa-chart-line', 'purple'); ?>
    <?php admin_stat_card('Killer (40% of 15%)', '$' . number_format($kt, 2), 'fa-solid fa-coins', 'rose'); ?>
</div>

<div class="mt-8 grid grid-cols-1 gap-6 xl:grid-cols-2">
    <section class="admin-card rounded-3xl p-6">
        <h3 class="text-lg font-semibold text-white">Withdrawals by month</h3>
        <p class="mt-1 text-sm text-slate-400">Completed withdrawal values over time.</p>
        <div class="mt-6 h-80"><canvas id="withdrawalsChart"></canvas></div>
    </section>
    <section class="admin-card rounded-3xl p-6">
        <h3 class="text-lg font-semibold text-white">Donations by month</h3>
        <p class="mt-1 text-sm text-slate-400">Track total donation flow across reporting periods.</p>
        <div class="mt-6 h-80"><canvas id="donationsChart"></canvas></div>
    </section>
</div>

<section class="admin-card mt-8 rounded-3xl p-6">
    <h3 class="text-lg font-semibold text-white">Revenue share breakdown</h3>
    <div class="mt-6 space-y-5">
        <div>
            <div class="mb-2 flex items-center justify-between text-sm">
                <span class="text-slate-300">Nile share</span>
                <span class="font-semibold text-white"><?= $total_commission > 0 ? round($nuulu / $total_commission * 100, 1) : 0 ?>%</span>
            </div>
            <div class="h-3 rounded-full bg-slate-800"><div class="h-3 rounded-full bg-gradient-to-r from-violet-500 to-fuchsia-500" style="width: <?= $total_commission > 0 ? ($nuulu / $total_commission * 100) : 0 ?>%"></div></div>
        </div>
        <div>
            <div class="mb-2 flex items-center justify-between text-sm">
                <span class="text-slate-300">Killer share</span>
                <span class="font-semibold text-white"><?= $total_commission > 0 ? round($kt / $total_commission * 100, 1) : 0 ?>%</span>
            </div>
            <div class="h-3 rounded-full bg-slate-800"><div class="h-3 rounded-full bg-gradient-to-r from-rose-500 to-pink-500" style="width: <?= $total_commission > 0 ? ($kt / $total_commission * 100) : 0 ?>%"></div></div>
        </div>
    </div>
</section>
<?php
admin_shell_end([
    '<script>
    document.addEventListener("DOMContentLoaded", function () {
        const months = ' . json_encode($months) . ';
        const withdrawalsData = ' . json_encode($withdrawals_chart) . ';
        const donationsData = ' . json_encode($donations_chart) . ';
        const commonOptions = {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                x: { ticks: { color: "#94a3b8" }, grid: { display: false } },
                y: { ticks: { color: "#94a3b8" }, grid: { color: "rgba(148,163,184,0.12)" } }
            }
        };
        new Chart(document.getElementById("withdrawalsChart"), {
            type: "bar",
            data: { labels: months, datasets: [{ data: withdrawalsData, backgroundColor: "rgba(16, 185, 129, 0.75)", borderRadius: 12, barPercentage: 0.62 }] },
            options: commonOptions
        });
        new Chart(document.getElementById("donationsChart"), {
            type: "bar",
            data: { labels: months, datasets: [{ data: donationsData, backgroundColor: "rgba(56, 189, 248, 0.75)", borderRadius: 12, barPercentage: 0.62 }] },
            options: commonOptions
        });
    });
    </script>'
]);
