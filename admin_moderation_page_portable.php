<?php
declare(strict_types=1);

require_once 'includes/config.php';
session_start();
require_once 'includes/auth.php';
$auth = new Auth();
$auth->requireAuth();
require_once 'includes/admin_layout.php';
require_once 'admin_trust_risk_portable.php';

$error = null;
$success = null;
$query = trim($_GET['q'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');
$pendingIdentityOnly = isset($_GET['pending_identity']) && $_GET['pending_identity'] === '1';
$selectedUserId = isset($_GET['user_id']) ? max(0, (int) $_GET['user_id']) : 0;
$selectedProfile = null;
$selectedHistory = [];

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(secure_random_bytes(32));
}

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

    $trustRisk = new AdminTrustRiskService($db);
    $trustRisk->assertAdminSession();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
            throw new RuntimeException('Invalid request token.');
        }

        $targetUserId = (int) ($_POST['user_id'] ?? 0);
        $reason = trim((string) ($_POST['reason'] ?? ''));
        $internalNotes = trim((string) ($_POST['internal_notes'] ?? ''));
        $reviewExpiresAtInput = trim((string) ($_POST['review_expires_at'] ?? ''));
        $reviewExpiresAt = null;

        if ($reviewExpiresAtInput !== '') {
            $timestamp = strtotime($reviewExpiresAtInput);
            if ($timestamp === false) {
                throw new RuntimeException('Invalid review expiry date.');
            }
            $reviewExpiresAt = date('Y-m-d H:i:s', $timestamp);
        }

        if ($targetUserId <= 0) {
            throw new RuntimeException('Please select a valid user.');
        }

        if ($reason === '') {
            throw new RuntimeException('A moderation reason is required.');
        }

        $moderatorUserId = $trustRisk->resolveModeratorUserId();

        if (isset($_POST['set_status'])) {
            $trustRisk->setUserStatus($moderatorUserId, $targetUserId, (string) ($_POST['risk_status'] ?? ''), $reason, $reviewExpiresAt, $internalNotes);
            $success = 'Account status updated successfully.';
            $selectedUserId = $targetUserId;
        } elseif (isset($_POST['add_flag'])) {
            $trustRisk->addRiskFlag($moderatorUserId, $targetUserId, (string) ($_POST['flag_type'] ?? 'manual_review'), (string) ($_POST['severity'] ?? 'medium'), $reason, $internalNotes, $reviewExpiresAt);
            $success = 'Risk flag recorded successfully.';
            $selectedUserId = $targetUserId;
        } elseif (isset($_POST['approve_identity_verification'])) {
            $attemptId = (int) ($_POST['attempt_id'] ?? 0);
            $trustRisk->approveIdentityVerification($moderatorUserId, $targetUserId, $attemptId, $reason, $internalNotes);
            $success = 'Identity verification approved and withdrawal access can now be granted based on ID verification.';
            $selectedUserId = $targetUserId;
        } elseif (isset($_POST['reject_identity_verification'])) {
            $attemptId = (int) ($_POST['attempt_id'] ?? 0);
            $trustRisk->rejectIdentityVerification($moderatorUserId, $targetUserId, $attemptId, $reason, $internalNotes);
            $success = 'Identity verification rejected and the decision has been recorded.';
            $selectedUserId = $targetUserId;
        }
    }

    $users = $pendingIdentityOnly
        ? $trustRisk->getUsersWithPendingIdentityReview($query)
        : $trustRisk->searchUsersForModeration($query, $statusFilter !== '' ? $statusFilter : null);

    if ($selectedUserId > 0) {
        $selectedProfile = $trustRisk->getUserRiskProfile($selectedUserId);
        $selectedHistory = $trustRisk->getModerationHistory($selectedUserId);
    }
} catch (Throwable $e) {
    error_log('Error in admin_moderation_page_portable.php: ' . $e->getMessage());
    $error = $e->getMessage();
    $users = $users ?? [];
}

admin_shell_start(
    'moderation',
    'Trust & Risk Moderation',
    'Search users, update account risk status, add flags, and review moderation history.'
);

if ($error) {
    admin_alert($error, 'error');
}
if ($success) {
    admin_alert($success, 'success');
}

function resolvePublicImageUrl(?string $path): ?string

{
    if ($path === null) {
        return null;
    }

    $normalizedPath = trim(str_replace('\\', '/', (string) $path));
    if ($normalizedPath === '') {
        return null;
    }

    if (preg_match('/^https?:\/\//i', $normalizedPath) === 1) {
        return $normalizedPath;
    }

    if (preg_match('/^\/\//', $normalizedPath) === 1) {
        return 'https:' . $normalizedPath;
    }

    return 'https://crofund.org/new/' . ltrim($normalizedPath, '/');
}

?>

<div class="grid grid-cols-1 gap-6 xl:grid-cols-[minmax(0,1.7fr),420px]">
    <div class="space-y-6">
        <section class="admin-card rounded-3xl p-6 sm:p-8">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.22em] text-crofund-300">Moderation workspace</p>
                    <h3 class="mt-2 text-2xl font-bold text-white">Review account safety</h3>
                    <p class="mt-2 max-w-2xl text-sm text-slate-400">Search users, change risk status, record flags, and keep an audit trail for follow-up reviews.</p>
                </div>
                <div class="rounded-2xl border border-crofund-500/20 bg-crofund-500/10 px-4 py-3 text-sm text-crofund-50">
                    Limited to authenticated admin sessions.
                </div>
            </div>
        </section>

        <section class="admin-card rounded-3xl p-6">
            <div class="mb-4">
                <h3 class="text-lg font-semibold text-white">Search accounts</h3>
                <p class="mt-1 text-sm text-slate-400">Filter by name, email, current risk status, or load users whose latest ID submission is under review.</p>
            </div>
            <form method="GET" class="grid grid-cols-1 gap-4 md:grid-cols-[minmax(0,1fr),220px,auto]">
                <input
                    type="text"
                    name="q"
                    value="<?php echo htmlspecialchars($query); ?>"
                    placeholder="Search users by name or email"
                    class="admin-input"
                >
                <select name="status" class="admin-input admin-select">
                    <option value="">All statuses</option>
                    <?php foreach (['ACTIVE', 'UNDER_REVIEW', 'LIMITED', 'SUSPENDED', 'BANNED'] as $status): ?>
                        <option value="<?php echo $status; ?>" <?php echo $statusFilter === $status ? 'selected' : ''; ?>><?php echo $status; ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="flex flex-wrap gap-3">
                    <button type="submit" class="inline-flex items-center justify-center rounded-2xl bg-gradient-to-r from-crofund-500 to-emerald-600 px-5 py-3 text-sm font-semibold text-white">Search</button>
                    <button type="submit" name="pending_identity" value="1" class="inline-flex items-center justify-center rounded-2xl border border-crofund-500/30 bg-crofund-500/10 px-5 py-3 text-sm font-semibold text-crofund-50 hover:bg-crofund-500/15">Fetch Under Review IDs</button>
                    <?php if ($pendingIdentityOnly || $query !== '' || $statusFilter !== ''): ?>
                        <a href="admin_moderation_page_portable.php" class="inline-flex items-center justify-center rounded-2xl border border-white/10 bg-white/5 px-5 py-3 text-sm font-semibold text-slate-200 hover:bg-white/10">Reset</a>
                    <?php endif; ?>
                </div>
            </form>
        </section>

        <section class="admin-card table-shell rounded-3xl p-0 overflow-hidden">
            <div class="px-6 py-5 border-b border-white/10">
                <h3 class="text-lg font-semibold text-white"><?php echo $pendingIdentityOnly ? 'Users with IDs Under Review' : 'Users'; ?></h3>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm text-slate-200">
                    <thead>
                        <tr>
                            <th class="px-5 py-4 text-left">User</th>
                            <th class="px-5 py-4 text-left">Status</th>
                            <th class="px-5 py-4 text-left"><?php echo $pendingIdentityOnly ? 'ID Review' : 'Created'; ?></th>
                            <th class="px-5 py-4 text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $listedUser): ?>
                            <tr>
                                <td class="px-5 py-4">
                                    <div class="font-semibold text-white"><?php echo htmlspecialchars((string) $listedUser['name']); ?></div>
                                    <div class="text-slate-400"><?php echo htmlspecialchars((string) $listedUser['email']); ?></div>
                                </td>
                                <td class="px-5 py-4">
                                    <span class="rounded-full bg-white/5 px-3 py-1 text-xs font-semibold text-slate-200"><?php echo htmlspecialchars((string) ($listedUser['risk_status'] ?? 'ACTIVE')); ?></span>
                                </td>
                                <td class="px-5 py-4 text-slate-400">
                                    <?php if ($pendingIdentityOnly): ?>
                                        <div class="text-slate-200"><?php echo htmlspecialchars($trustRisk->getIdentityAttemptStatusLabel((string) ($listedUser['identity_status'] ?? 'pending_review'))); ?></div>
                                        <div class="text-xs text-slate-500">Submitted <?php echo htmlspecialchars((string) ($listedUser['identity_submitted_at'] ?? '')); ?></div>
                                    <?php else: ?>
                                        <?php echo htmlspecialchars((string) ($listedUser['created_at'] ?? '')); ?>
                                    <?php endif; ?>
                                </td>
                                <td class="px-5 py-4 text-right">
                                    <a href="?q=<?php echo urlencode($query); ?>&status=<?php echo urlencode($statusFilter); ?>&pending_identity=<?php echo $pendingIdentityOnly ? '1' : '0'; ?>&user_id=<?php echo (int) $listedUser['id']; ?>" class="inline-flex items-center justify-center rounded-xl border border-white/10 bg-white/5 px-4 py-2 text-xs font-semibold text-slate-100 hover:bg-white/10">Review</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if (empty($users)): ?>
                <div class="px-6 py-12 text-center text-slate-400"><?php echo $pendingIdentityOnly ? 'No users currently have IDs under review.' : 'No users matched your current search.'; ?></div>
            <?php endif; ?>
        </section>
    </div>

    <aside class="space-y-6">
        <section class="admin-card rounded-3xl p-6">
            <h3 class="text-lg font-semibold text-white">Moderation controls</h3>
            <?php if ($selectedProfile): ?>
                <div class="mt-4 rounded-2xl border border-white/10 bg-white/5 px-4 py-4">
                    <p class="text-sm font-semibold text-white"><?php echo htmlspecialchars((string) $selectedProfile['user']['name']); ?></p>
                    <p class="mt-1 text-sm text-slate-400"><?php echo htmlspecialchars((string) $selectedProfile['user']['email']); ?></p>
                    <p class="mt-3 text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Current status</p>
                    <p class="mt-2 text-base font-semibold text-white"><?php echo htmlspecialchars((string) $selectedProfile['status']); ?></p>
                    <p class="mt-2 text-sm text-slate-400"><?php echo htmlspecialchars((string) $selectedProfile['message']['full']); ?></p>
                    <div class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-3">
                        <div class="rounded-2xl border border-white/10 bg-white/5 px-3 py-3">
                            <p class="text-xs uppercase tracking-[0.18em] text-slate-500">ID verified</p>
                            <p class="mt-2 text-sm font-semibold text-white"><?php echo !empty($selectedProfile['user']['id_verified']) ? 'Yes' : 'No'; ?></p>
                        </div>
                        <div class="rounded-2xl border border-white/10 bg-white/5 px-3 py-3">
                            <p class="text-xs uppercase tracking-[0.18em] text-slate-500">Verification attempted</p>
                            <p class="mt-2 text-sm font-semibold text-white"><?php echo !empty($selectedProfile['user']['verification_attempted']) ? 'Yes' : 'No'; ?></p>
                        </div>
                        <div class="rounded-2xl border border-white/10 bg-white/5 px-3 py-3">
                            <p class="text-xs uppercase tracking-[0.18em] text-slate-500">Attempt count</p>
                            <p class="mt-2 text-sm font-semibold text-white"><?php echo (int) ($selectedProfile['user']['verification_attempts'] ?? 0); ?></p>
                        </div>
                    </div>
                    <?php if (!empty($selectedProfile['review_expires_at'])): ?>
                        <p class="mt-2 text-xs text-amber-300">Review expires: <?php echo htmlspecialchars((string) $selectedProfile['review_expires_at']); ?></p>
                    <?php endif; ?>
                </div>

                <?php $latestIdentityAttempt = $selectedProfile['latest_identity_attempt'] ?? null; ?>
                <?php $identityAttempts = $selectedProfile['identity_attempts'] ?? []; ?>
                <?php $latestIdentityAttemptIsReviewable = $latestIdentityAttempt ? $trustRisk->canReviewIdentityAttempt($latestIdentityAttempt) : false; ?>

                <?php if ($latestIdentityAttempt): ?>
                    <div class="mt-5 rounded-2xl border border-crofund-500/20 bg-crofund-500/10 p-4">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <p class="text-sm font-semibold text-white">Latest identity verification attempt</p>
                                <p class="mt-1 text-xs uppercase tracking-[0.18em] text-crofund-100/80">Attempt #<?php echo (int) ($latestIdentityAttempt['id'] ?? 0); ?></p>
                            </div>
                            <span class="rounded-full border border-white/10 bg-white/5 px-3 py-1 text-xs font-semibold text-slate-100"><?php echo htmlspecialchars($trustRisk->getIdentityAttemptStatusLabel((string) ($latestIdentityAttempt['status'] ?? 'unknown'))); ?></span>
                        </div>
                        <div class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <div class="rounded-2xl border border-white/10 bg-slate-950/30 px-4 py-3 text-sm text-slate-300">
                                <p><strong class="text-white">Decision:</strong> <?php echo htmlspecialchars($trustRisk->getIdentityDecisionStatusLabel((string) ($latestIdentityAttempt['decision_status'] ?? 'manual_review'))); ?></p>
                                <p class="mt-1"><strong class="text-white">Score:</strong> <?php echo (int) ($latestIdentityAttempt['decision_score'] ?? 0); ?></p>
                                <p class="mt-1"><strong class="text-white">OCR:</strong> <?php echo htmlspecialchars((string) ($latestIdentityAttempt['ocr_status'] ?? 'unknown')); ?></p>
                                <p class="mt-1"><strong class="text-white">Submitted:</strong> <?php echo htmlspecialchars((string) ($latestIdentityAttempt['created_at'] ?? '')); ?></p>
                            </div>
                            <div class="rounded-2xl border border-white/10 bg-slate-950/30 px-4 py-3 text-sm text-slate-300">
                                <p><strong class="text-white">Image:</strong> <?php echo (int) ($latestIdentityAttempt['image_width'] ?? 0); ?> × <?php echo (int) ($latestIdentityAttempt['image_height'] ?? 0); ?></p>
                                <p class="mt-1"><strong class="text-white">Brightness:</strong> <?php echo htmlspecialchars((string) ($latestIdentityAttempt['brightness_score'] ?? 'n/a')); ?></p>
                                <p class="mt-1"><strong class="text-white">Blur score:</strong> <?php echo htmlspecialchars((string) ($latestIdentityAttempt['blur_score'] ?? 'n/a')); ?></p>
                                <p class="mt-1"><strong class="text-white">File:</strong> <?php echo htmlspecialchars((string) ($latestIdentityAttempt['original_filename'] ?? '')); ?></p>
                            </div>
                        </div>
                        <?php $latestIdentityImageUrl = resolvePublicImageUrl($latestIdentityAttempt['original_image_path'] ?? ($latestIdentityAttempt['processed_image_path'] ?? null)); ?>
                        <?php if ($latestIdentityImageUrl): ?>
                            <div class="mt-4">
                                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Uploaded verification image</p>
                                <img src="<?php echo htmlspecialchars($latestIdentityImageUrl); ?>" alt="Uploaded identity verification image" class="mt-3 max-h-80 w-full rounded-2xl border border-white/10 bg-slate-950/40 object-contain">
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($latestIdentityAttempt['failure_reason'])): ?>
                            <div class="mt-3 rounded-2xl border border-amber-500/20 bg-amber-500/10 px-4 py-3 text-sm text-amber-100">
                                <?php echo htmlspecialchars((string) $latestIdentityAttempt['failure_reason']); ?>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($latestIdentityAttempt['ocr_message'])): ?>
                            <p class="mt-3 text-sm text-slate-400"><?php echo htmlspecialchars((string) $latestIdentityAttempt['ocr_message']); ?></p>
                        <?php endif; ?>
                    </div>

                    <?php if ($latestIdentityAttemptIsReviewable): ?>
                        <form method="POST" class="mt-5 space-y-3 rounded-2xl border border-emerald-500/20 bg-emerald-500/5 p-4">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                            <input type="hidden" name="user_id" value="<?php echo (int) $selectedProfile['user']['id']; ?>">
                            <input type="hidden" name="attempt_id" value="<?php echo (int) ($latestIdentityAttempt['id'] ?? 0); ?>">
                            <div>
                                <label class="mb-1.5 block text-sm font-semibold text-slate-200">Approval reason</label>
                                <textarea name="reason" rows="3" required class="admin-input" placeholder="Record why this identity submission is being approved."></textarea>
                            </div>
                            <div>
                                <label class="mb-1.5 block text-sm font-semibold text-slate-200">Internal notes</label>
                                <textarea name="internal_notes" rows="3" class="admin-input" placeholder="Optional internal notes for the audit trail."></textarea>
                            </div>
                            <button type="submit" name="approve_identity_verification" class="inline-flex w-full items-center justify-center rounded-xl bg-gradient-to-r from-crofund-500 to-emerald-600 px-4 py-2.5 text-xs font-semibold text-white">Approve identity verification</button>
                        </form>

                        <form method="POST" class="mt-4 space-y-3 rounded-2xl border border-red-500/20 bg-red-500/5 p-4">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                            <input type="hidden" name="user_id" value="<?php echo (int) $selectedProfile['user']['id']; ?>">
                            <input type="hidden" name="attempt_id" value="<?php echo (int) ($latestIdentityAttempt['id'] ?? 0); ?>">
                            <div>
                                <label class="mb-1.5 block text-sm font-semibold text-slate-200">Rejection reason</label>
                                <textarea name="reason" rows="3" required class="admin-input" placeholder="Record why this identity submission is being rejected."></textarea>
                            </div>
                            <div>
                                <label class="mb-1.5 block text-sm font-semibold text-slate-200">Internal notes</label>
                                <textarea name="internal_notes" rows="3" class="admin-input" placeholder="Optional internal notes for the audit trail."></textarea>
                            </div>
                            <button type="submit" name="reject_identity_verification" class="inline-flex w-full items-center justify-center rounded-xl border border-red-500/30 bg-red-500/10 px-4 py-2.5 text-xs font-semibold text-red-50 hover:bg-red-500/15">Reject identity verification</button>
                        </form>
                    <?php else: ?>
                        <div class="mt-5 rounded-2xl border border-white/10 bg-white/5 px-4 py-4 text-sm text-slate-400">
                            This identity submission has already been reviewed. No further approval or rejection action is needed here.
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="mt-5 rounded-2xl border border-white/10 bg-white/5 px-4 py-4 text-sm text-slate-400">
                        This user does not have any recorded identity verification attempts yet.
                    </div>
                <?php endif; ?>

                <form method="POST" class="mt-5 space-y-4">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                    <input type="hidden" name="user_id" value="<?php echo (int) $selectedProfile['user']['id']; ?>">
                    <div>
                        <label class="mb-1.5 block text-sm font-semibold text-slate-200">Set risk status</label>
                        <select name="risk_status" class="admin-input admin-select">
                            <?php foreach (['ACTIVE', 'UNDER_REVIEW', 'LIMITED', 'SUSPENDED', 'BANNED'] as $status): ?>
                                <option value="<?php echo $status; ?>" <?php echo $selectedProfile['status'] === $status ? 'selected' : ''; ?>><?php echo $status; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1.5 block text-sm font-semibold text-slate-200">Reason</label>
                        <textarea name="reason" rows="4" required class="admin-input" placeholder="Record the reason for this moderation action."></textarea>
                    </div>
                    <div>
                        <label class="mb-1.5 block text-sm font-semibold text-slate-200">Internal notes</label>
                        <textarea name="internal_notes" rows="3" class="admin-input" placeholder="Additional notes for admins only."></textarea>
                    </div>
                    <div>
                        <label class="mb-1.5 block text-sm font-semibold text-slate-200">Review expiry</label>
                        <input type="datetime-local" name="review_expires_at" class="admin-input">
                    </div>
                    <button type="submit" name="set_status" class="inline-flex w-full items-center justify-center rounded-xl bg-gradient-to-r from-crofund-500 to-emerald-600 px-4 py-2.5 text-xs font-semibold text-white">Save status change</button>
                </form>

                <form method="POST" class="mt-6 space-y-4 border-t border-white/10 pt-6">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                    <input type="hidden" name="user_id" value="<?php echo (int) $selectedProfile['user']['id']; ?>">
                    <div>
                        <label class="mb-1.5 block text-sm font-semibold text-slate-200">Add risk flag</label>
                        <input type="text" name="flag_type" value="manual_review" class="admin-input">
                    </div>
                    <div>
                        <label class="mb-1.5 block text-sm font-semibold text-slate-200">Severity</label>
                        <select name="severity" class="admin-input admin-select">
                            <option value="low">Low</option>
                            <option value="medium" selected>Medium</option>
                            <option value="high">High</option>
                            <option value="critical">Critical</option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1.5 block text-sm font-semibold text-slate-200">Reason</label>
                        <textarea name="reason" rows="3" required class="admin-input"></textarea>
                    </div>
                    <div>
                        <label class="mb-1.5 block text-sm font-semibold text-slate-200">Internal notes</label>
                        <textarea name="internal_notes" rows="3" class="admin-input"></textarea>
                    </div>
                    <div>
                        <label class="mb-1.5 block text-sm font-semibold text-slate-200">Review expiry</label>
                        <input type="datetime-local" name="review_expires_at" class="admin-input">
                    </div>
                    <button type="submit" name="add_flag" class="inline-flex w-full items-center justify-center rounded-xl border border-white/10 bg-white/5 px-4 py-2.5 text-xs font-semibold text-slate-100 hover:bg-white/10">Record flag</button>
                </form>
            <?php else: ?>
                <p class="mt-4 text-sm text-slate-400">Select a user from the table to review their account status and moderation history.</p>
            <?php endif; ?>
        </section>

        <?php if ($selectedProfile): ?>
            <section class="admin-card rounded-3xl p-6">
                <h3 class="text-lg font-semibold text-white">Identity verification history</h3>
                <div class="mt-4 space-y-4">
                    <?php foreach ($identityAttempts as $attempt): ?>
                        <div class="rounded-2xl border border-white/10 bg-white/5 p-4">
                            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                <div>
                                    <p class="text-sm font-semibold text-white">Attempt #<?php echo (int) ($attempt['id'] ?? 0); ?> · <?php echo htmlspecialchars((string) ($attempt['document_type'] ?? 'national_id')); ?></p>
                                    <p class="mt-1 text-xs text-slate-400">Submitted <?php echo htmlspecialchars((string) ($attempt['created_at'] ?? '')); ?> · Updated <?php echo htmlspecialchars((string) ($attempt['updated_at'] ?? '')); ?></p>
                                </div>
                                <div class="flex flex-wrap gap-2">
                                    <span class="rounded-full border border-white/10 bg-white/5 px-3 py-1 text-xs font-semibold text-slate-100">Status: <?php echo htmlspecialchars($trustRisk->getIdentityAttemptStatusLabel((string) ($attempt['status'] ?? 'unknown'))); ?></span>
                                    <span class="rounded-full border border-crofund-500/20 bg-crofund-500/10 px-3 py-1 text-xs font-semibold text-crofund-50">Decision: <?php echo htmlspecialchars($trustRisk->getIdentityDecisionStatusLabel((string) ($attempt['decision_status'] ?? 'manual_review'))); ?></span>
                                </div>
                            </div>

                            <div class="mt-4 grid grid-cols-1 gap-3 lg:grid-cols-2">
                                <div class="rounded-2xl border border-white/10 bg-slate-950/30 px-4 py-3 text-sm text-slate-300">
                                    <p><strong class="text-white">Decision score:</strong> <?php echo (int) ($attempt['decision_score'] ?? 0); ?></p>
                                    <p class="mt-1"><strong class="text-white">OCR status:</strong> <?php echo htmlspecialchars((string) ($attempt['ocr_status'] ?? 'unknown')); ?></p>
                                    <p class="mt-1"><strong class="text-white">Image size:</strong> <?php echo (int) ($attempt['image_width'] ?? 0); ?> × <?php echo (int) ($attempt['image_height'] ?? 0); ?></p>
                                    <p class="mt-1"><strong class="text-white">Brightness:</strong> <?php echo htmlspecialchars((string) ($attempt['brightness_score'] ?? 'n/a')); ?></p>
                                    <p class="mt-1"><strong class="text-white">Blur score:</strong> <?php echo htmlspecialchars((string) ($attempt['blur_score'] ?? 'n/a')); ?></p>
                                </div>
                                <div class="rounded-2xl border border-white/10 bg-slate-950/30 px-4 py-3 text-sm text-slate-300 break-all">
                                    <p><strong class="text-white">Original file:</strong> <?php echo htmlspecialchars((string) ($attempt['original_filename'] ?? '')); ?></p>
                                    <p class="mt-1"><strong class="text-white">Stored original:</strong> <?php echo htmlspecialchars((string) ($attempt['original_image_path'] ?? '')); ?></p>
                                    <p class="mt-1"><strong class="text-white">Processed copy:</strong> <?php echo htmlspecialchars((string) ($attempt['processed_image_path'] ?? '')); ?></p>
                                </div>
                            </div>

                            <?php $attemptImageUrl = resolvePublicImageUrl($attempt['original_image_path'] ?? ($attempt['processed_image_path'] ?? null)); ?>
                            <?php if ($attemptImageUrl): ?>
                                <div class="mt-4">
                                    <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Uploaded verification image</p>
                                    <img src="<?php echo htmlspecialchars($attemptImageUrl); ?>" alt="Identity verification document image" class="mt-3 max-h-64 w-full rounded-2xl border border-white/10 bg-slate-950/40 object-contain">
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($attempt['ocr_message'])): ?>
                                <p class="mt-3 text-sm text-slate-400"><?php echo htmlspecialchars((string) $attempt['ocr_message']); ?></p>
                            <?php endif; ?>

                            <?php if (!empty($attempt['failure_reason'])): ?>
                                <div class="mt-3 rounded-2xl border border-amber-500/20 bg-amber-500/10 px-4 py-3 text-sm text-amber-100">
                                    <?php echo htmlspecialchars((string) $attempt['failure_reason']); ?>
                                </div>
                            <?php endif; ?>

                            <details class="mt-3 rounded-2xl border border-white/10 bg-slate-950/20 px-4 py-3">
                                <summary class="cursor-pointer text-sm font-semibold text-slate-200">View extracted data and decision checks</summary>
                                <div class="mt-3 grid grid-cols-1 gap-3 lg:grid-cols-2">
                                    <div>
                                        <p class="text-xs uppercase tracking-[0.18em] text-slate-500">Extracted fields</p>
                                        <pre class="mt-2 overflow-x-auto rounded-2xl bg-slate-950/60 p-3 text-xs text-slate-300"><?php echo htmlspecialchars((string) ($attempt['extracted_fields'] ?? '')); ?></pre>
                                    </div>
                                    <div>
                                        <p class="text-xs uppercase tracking-[0.18em] text-slate-500">Decision checks</p>
                                        <pre class="mt-2 overflow-x-auto rounded-2xl bg-slate-950/60 p-3 text-xs text-slate-300"><?php echo htmlspecialchars((string) ($attempt['decision_checks'] ?? '')); ?></pre>
                                    </div>
                                </div>
                            </details>

                            <?php if ($trustRisk->canReviewIdentityAttempt($attempt)): ?>
                                <div class="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-2">
                                    <form method="POST" class="space-y-3 rounded-2xl border border-emerald-500/20 bg-emerald-500/5 p-3">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                        <input type="hidden" name="user_id" value="<?php echo (int) $selectedProfile['user']['id']; ?>">
                                        <input type="hidden" name="attempt_id" value="<?php echo (int) ($attempt['id'] ?? 0); ?>">
                                        <label class="block text-sm font-semibold text-slate-200">Approve this attempt</label>
                                        <textarea name="reason" rows="3" required class="admin-input" placeholder="Record why this identity submission is being approved."></textarea>
                                        <textarea name="internal_notes" rows="2" class="admin-input" placeholder="Optional internal notes."></textarea>
                                        <button type="submit" name="approve_identity_verification" class="inline-flex w-full items-center justify-center rounded-xl bg-gradient-to-r from-crofund-500 to-emerald-600 px-4 py-2.5 text-xs font-semibold text-white">Approve this attempt</button>
                                    </form>

                                    <form method="POST" class="space-y-3 rounded-2xl border border-red-500/20 bg-red-500/5 p-3">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                        <input type="hidden" name="user_id" value="<?php echo (int) $selectedProfile['user']['id']; ?>">
                                        <input type="hidden" name="attempt_id" value="<?php echo (int) ($attempt['id'] ?? 0); ?>">
                                        <label class="block text-sm font-semibold text-slate-200">Reject this attempt</label>
                                        <textarea name="reason" rows="3" required class="admin-input" placeholder="Record why this identity submission is being rejected."></textarea>
                                        <textarea name="internal_notes" rows="2" class="admin-input" placeholder="Optional internal notes."></textarea>
                                        <button type="submit" name="reject_identity_verification" class="inline-flex w-full items-center justify-center rounded-xl border border-red-500/30 bg-red-500/10 px-4 py-2.5 text-xs font-semibold text-red-50 hover:bg-red-500/15">Reject this attempt</button>
                                    </form>
                                </div>
                            <?php else: ?>
                                <div class="mt-4 rounded-2xl border border-white/10 bg-white/5 px-4 py-4 text-sm text-slate-400">
                                    This attempt is already finalized and cannot be reviewed again from this page.
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                    <?php if (empty($identityAttempts)): ?>
                        <p class="text-sm text-slate-400">No identity verification attempts are recorded for this user yet.</p>
                    <?php endif; ?>
                </div>
            </section>

            <section class="admin-card rounded-3xl p-6">
                <h3 class="text-lg font-semibold text-white">Open flags</h3>
                <div class="mt-4 space-y-3">
                    <?php foreach ($selectedProfile['flags'] as $flag): ?>
                        <div class="rounded-2xl border border-white/10 bg-white/5 px-4 py-4">
                            <div class="flex items-center justify-between gap-3">
                                <p class="text-sm font-semibold text-white"><?php echo htmlspecialchars((string) $flag['flag_type']); ?></p>
                                <span class="rounded-full bg-red-500/10 px-3 py-1 text-xs font-semibold text-red-100"><?php echo htmlspecialchars((string) $flag['severity']); ?></span>
                            </div>
                            <p class="mt-2 text-sm text-slate-300"><?php echo htmlspecialchars((string) ($flag['reason'] ?? '')); ?></p>
                            <?php if (!empty($flag['internal_notes'])): ?>
                                <p class="mt-2 text-xs text-slate-400"><?php echo htmlspecialchars((string) $flag['internal_notes']); ?></p>
                            <?php endif; ?>
                            <p class="mt-2 text-xs text-slate-500">Status: <?php echo htmlspecialchars((string) ($flag['status'] ?? 'open')); ?> · <?php echo htmlspecialchars((string) ($flag['created_at'] ?? '')); ?></p>
                        </div>
                    <?php endforeach; ?>
                    <?php if (empty($selectedProfile['flags'])): ?>
                        <p class="text-sm text-slate-400">No risk flags recorded for this user yet.</p>
                    <?php endif; ?>
                </div>
            </section>

            <section class="admin-card rounded-3xl p-6">
                <h3 class="text-lg font-semibold text-white">Moderation history</h3>
                <div class="mt-4 space-y-4">
                    <?php foreach ($selectedHistory as $history): ?>
                        <div class="rounded-2xl border border-white/10 bg-white/5 px-4 py-4">
                            <p class="text-sm font-semibold text-white"><?php echo htmlspecialchars((string) $history['action_type']); ?></p>
                            <p class="mt-1 text-sm text-slate-400">From <?php echo htmlspecialchars((string) ($history['previous_status'] ?? '—')); ?> to <?php echo htmlspecialchars((string) ($history['new_status'] ?? '—')); ?></p>
                            <p class="mt-2 text-sm text-slate-300"><?php echo htmlspecialchars((string) $history['reason']); ?></p>
                            <p class="mt-2 text-xs text-slate-500"><?php echo htmlspecialchars((string) ($history['admin_name'] ?? $history['admin_email'] ?? ('User #' . ($history['admin_id'] ?? '')))); ?> · <?php echo htmlspecialchars((string) ($history['created_at'] ?? '')); ?></p>
                        </div>
                    <?php endforeach; ?>
                    <?php if (empty($selectedHistory)): ?>
                        <p class="text-sm text-slate-400">No moderation history recorded for this user yet.</p>
                    <?php endif; ?>
                </div>
            </section>
        <?php endif; ?>
    </aside>
</div>
<?php
admin_shell_end();
