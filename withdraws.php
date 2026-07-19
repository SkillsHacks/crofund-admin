<?php
declare(strict_types=1);
require_once 'includes/config.php';
require_once 'includes/auth.php';
require_once 'includes/EmailQueue.php';
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

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(secure_random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['withdrawal_id'], $_POST['status'])) {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        header('Location: withdraws.php?err=CSRF validation failed');
        exit;
    }

    $id = (int) $_POST['withdrawal_id'];
    $status = $_POST['status'];
    if (!in_array($status, ['completed', 'rejected'], true)) {
        header('Location: withdraws.php?err=Invalid status');
        exit;
    }

    $stmt = $db->prepare('UPDATE withdrawals SET status = ?, updated_at = NOW() WHERE id = ?');
    $stmt->execute([$status, $id]);

    if (in_array($status, ['completed', 'rejected'], true)) {
        $stmt = $db->prepare("
            SELECT w.amount, w.method, w.details, w.created_at, u.email, u.name
            FROM withdrawals w
            JOIN users u ON w.user_id = u.id
            WHERE w.id = ?
        ");
        $stmt->execute([$id]);
        $info = $stmt->fetch();

        if ($info) {
            $queue = new EmailQueue($db);
            $queue->ensureSchema();
            $queue->queueWithdrawalStatusEmail($info, $status);
        }
    }

    header('Location: withdraws.php?msg=Status updated!');
    exit;
}

$where = [];
$params = [];
if (!empty($_GET['user'])) {
    $where[] = '(u.name LIKE ? OR u.email LIKE ?)';
    $params[] = '%' . $_GET['user'] . '%';
    $params[] = '%' . $_GET['user'] . '%';
}
if (!empty($_GET['status'])) {
    $where[] = 'w.status = ?';
    $params[] = $_GET['status'];
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$stmt = $db->prepare("
    SELECT w.*, u.name 
    FROM withdrawals w
    LEFT JOIN users u ON w.user_id = u.id
    $whereSql
    ORDER BY CASE WHEN w.status = 'pending' THEN 1 ELSE 2 END, w.created_at ASC
");
$stmt->execute($params);
$withdrawals = $stmt->fetchAll();

admin_shell_start('withdraws', 'Manage Withdrawals', 'Review, approve, and reject payout requests.');

if (!empty($_GET['msg'])) {
    admin_alert((string) $_GET['msg'], 'success');
} elseif (!empty($_GET['err'])) {
    admin_alert((string) $_GET['err'], 'error');
}
?>
<section class="admin-card rounded-3xl p-6">
    <form method="get" class="grid grid-cols-1 gap-4 lg:grid-cols-[minmax(0,1fr)_220px_140px]">
        <input type="text" name="user" value="<?= htmlspecialchars($_GET['user'] ?? '') ?>" placeholder="Search user by name or email" class="admin-input">
        <select name="status" class="admin-input admin-select">
            <option value="">All statuses</option>
            <option value="pending" <?= ($_GET['status'] ?? '') === 'pending' ? 'selected' : '' ?>>Pending</option>
            <option value="completed" <?= ($_GET['status'] ?? '') === 'completed' ? 'selected' : '' ?>>Completed</option>
            <option value="rejected" <?= ($_GET['status'] ?? '') === 'rejected' ? 'selected' : '' ?>>Rejected</option>
        </select>
        <button class="rounded-2xl bg-gradient-to-r from-crofund-500 to-emerald-600 px-4 py-3 text-sm font-semibold text-white">Filter</button>
    </form>
</section>

<section class="admin-card table-shell mt-6 rounded-3xl p-0 overflow-hidden">
    <div class="overflow-x-auto">
        <table class="min-w-full text-sm text-slate-200">
            <thead>
                <tr>
                    <th class="px-4 py-4 text-left">ID</th>
                    <th class="px-4 py-4 text-left">User</th>
                    <th class="px-4 py-4 text-left">Amount</th>
                    <th class="px-4 py-4 text-left">Method</th>
                    <th class="px-4 py-4 text-left">Details</th>
                    <th class="px-4 py-4 text-left">Status</th>
                    <th class="px-4 py-4 text-left">Requested</th>
                    <th class="px-4 py-4 text-left">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($withdrawals as $w): ?>
                    <tr>
                        <td class="px-4 py-4"><?= (int) $w['id'] ?></td>
                        <td class="px-4 py-4 font-medium text-white"><?= htmlspecialchars($w['name']) ?></td>
                        <td class="px-4 py-4 font-semibold text-crofund-300">$<?= number_format((float) $w['amount'], 2) ?></td>
                        <td class="px-4 py-4 capitalize"><?= htmlspecialchars($w['method']) ?></td>
                        <td class="px-4 py-4 text-slate-300">
                            <?php foreach (json_decode($w['details'], true) ?? [] as $k => $v): ?>
                                <div><span class="font-semibold text-slate-200"><?= ucfirst(str_replace('_', ' ', $k)) ?>:</span> <?= htmlspecialchars((string) $v) ?></div>
                            <?php endforeach; ?>
                        </td>
                        <td class="px-4 py-4">
                            <?php if ($w['status'] === 'pending'): ?>
                                <span class="rounded-full bg-amber-500/15 px-3 py-1 text-xs font-semibold text-amber-200">Pending</span>
                            <?php elseif ($w['status'] === 'completed'): ?>
                                <span class="rounded-full bg-crofund-500/15 px-3 py-1 text-xs font-semibold text-crofund-100">Completed</span>
                            <?php else: ?>
                                <span class="rounded-full bg-red-500/15 px-3 py-1 text-xs font-semibold text-red-200">Rejected</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-4 text-slate-400"><?= date('M d, Y H:i', strtotime($w['created_at'])) ?></td>
                        <td class="px-4 py-4">
                            <?php if ($w['status'] === 'pending'): ?>
                                <div class="flex flex-wrap gap-2">
                                    <form method="post" class="inline">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                        <input type="hidden" name="withdrawal_id" value="<?= (int) $w['id'] ?>">
                                        <input type="hidden" name="status" value="completed">
                                        <button class="rounded-xl bg-crofund-500 px-3 py-2 text-xs font-semibold text-white hover:bg-crofund-400">Complete</button>
                                    </form>
                                    <form method="post" class="inline">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                        <input type="hidden" name="withdrawal_id" value="<?= (int) $w['id'] ?>">
                                        <input type="hidden" name="status" value="rejected">
                                        <button class="rounded-xl bg-red-500/90 px-3 py-2 text-xs font-semibold text-white hover:bg-red-500">Reject</button>
                                    </form>
                                </div>
                            <?php else: ?>
                                <span class="text-xs italic text-slate-500">No action</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php if (empty($withdrawals)): ?>
            <div class="py-12 text-center text-slate-400">No withdrawal requests matched your filter.</div>
        <?php endif; ?>
    </div>
</section>
<?php admin_shell_end();
