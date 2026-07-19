<?php
declare(strict_types=1);

require_once 'includes/config.php';

ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_secure', (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? '1' : '0');
ini_set('session.use_only_cookies', '1');
ini_set('session.cookie_samesite', 'Strict');
session_start();

$nonce = base64_encode(secure_random_bytes(16));
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-{$nonce}' https://cdn.tailwindcss.com; style-src 'self' 'unsafe-inline'; font-src 'self'; img-src 'self' data:; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self';");
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

require_once 'includes/RateLimit.php';
require_once __DIR__ . '/includes/auth.php';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(secure_random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedToken = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], $postedToken)) {
        http_response_code(403);
        die('Request could not be processed.');
    }
}

$rateLimit = new RateLimit();
$clientIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$auth = new Auth();
$error = null;
$success = null;
$pendingUser = $auth->getPendingTwoFactorUser();
$isTwoFactorStep = $pendingUser !== null;

if ($auth->isLoggedIn()) {
    header('Location: dashboard.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'login';

    if ($action === 'login') {
        if ($rateLimit->isLimited($clientIp)) {
            http_response_code(429);
            $error = 'Too many attempts. Please try again later.';
        } else {
            $username = trim((string) ($_POST['username'] ?? ''));
            $password = (string) ($_POST['password'] ?? '');

            try {
                if ($auth->startLogin($username, $password)) {
                    session_regenerate_id(true);
                    $pendingUser = $auth->getPendingTwoFactorUser();
                    $isTwoFactorStep = true;
                    $success = 'A verification code has been requested. Please check your email shortly.';
                    $rateLimit->resetAttempts($clientIp);
                } else {
                    $error = 'Sign-in could not be completed.';
                    $rateLimit->logAttempt($clientIp);
                }
            } catch (Exception $e) {
                error_log($e->getMessage());
                $error = 'Sign-in could not be completed.';
            }
        }
    } elseif ($action === 'verify') {
        $code = preg_replace('/\D+/', '', (string) ($_POST['verification_code'] ?? ''));

        if (!$auth->hasPendingTwoFactor()) {
            $isTwoFactorStep = false;
            $error = 'Your session has expired. Please sign in again.';
        } elseif (!preg_match('/^\d{6}$/', $code)) {
            $pendingUser = $auth->getPendingTwoFactorUser();
            $isTwoFactorStep = $pendingUser !== null;
            $error = 'Enter the verification code to continue.';
        } else {
            try {
                if ($auth->verifyTwoFactorCode($code)) {
                    session_regenerate_id(true);
                    $rateLimit->resetAttempts($clientIp);
                    header('Location: dashboard.php');
                    exit();
                }

                $pendingUser = $auth->getPendingTwoFactorUser();
                $isTwoFactorStep = $pendingUser !== null;
                $error = $pendingUser
                    ? 'Verification could not be completed.'
                    : 'Your session has expired. Please sign in again.';
            } catch (Exception $e) {
                error_log($e->getMessage());
                $pendingUser = $auth->getPendingTwoFactorUser();
                $isTwoFactorStep = $pendingUser !== null;
                $error = 'Verification could not be completed.';
            }
        }
    } elseif ($action === 'resend_code') {
        try {
            if ($auth->resendTwoFactorCode()) {
                $pendingUser = $auth->getPendingTwoFactorUser();
                $isTwoFactorStep = $pendingUser !== null;
                $success = 'A new verification code has been requested. Please check your email shortly.';
            } else {
                $pendingUser = $auth->getPendingTwoFactorUser();
                $isTwoFactorStep = $pendingUser !== null;
                $error = $isTwoFactorStep
                    ? 'Please wait before requesting another code.'
                    : 'Your session has expired. Please sign in again.';
            }
        } catch (Exception $e) {
            error_log($e->getMessage());
            $pendingUser = $auth->getPendingTwoFactorUser();
            $isTwoFactorStep = $pendingUser !== null;
            $error = 'Verification could not be completed.';
        }
    } elseif ($action === 'back_to_login') {
        $auth->clearPendingTwoFactor();
        $isTwoFactorStep = false;
        $pendingUser = null;
    }
}

$usernameValue = '';
if (!empty($_POST['username']) && !$isTwoFactorStep) {
    $usernameValue = (string) $_POST['username'];
} elseif (!empty($_SESSION['pending_admin_login']['remember_username'])) {
    $usernameValue = (string) $_SESSION['pending_admin_login']['remember_username'];
}
?>
<!DOCTYPE html>
<html lang="en" class="h-full bg-slate-950">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#020617">
    <title><?php echo $isTwoFactorStep ? 'Verify Sign In' : 'Administrator Sign In'; ?> | <?php echo COMPANY_NAME; ?></title>
    <script nonce="<?php echo $nonce; ?>" src="https://cdn.tailwindcss.com"></script>
    <script nonce="<?php echo $nonce; ?>">
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        crofund: {
                            400: '#34d399',
                            500: '#10b981',
                            600: '#059669'
                        }
                    },
                    boxShadow: {
                        panel: '0 24px 64px rgba(15, 23, 42, 0.45)'
                    }
                }
            }
        };
    </script>
    <style nonce="<?php echo $nonce; ?>">
        .page-grid {
            background-image:
                linear-gradient(rgba(148, 163, 184, 0.06) 1px, transparent 1px),
                linear-gradient(90deg, rgba(148, 163, 184, 0.06) 1px, transparent 1px);
            background-size: 40px 40px;
        }
        .login-panel {
            background: linear-gradient(180deg, rgba(15, 23, 42, 0.94), rgba(15, 23, 42, 0.88));
            backdrop-filter: blur(18px);
        }
        .field-input {
            width: 100%;
            border-radius: 1rem;
            border: 1px solid rgba(148, 163, 184, 0.16);
            background: rgba(2, 6, 23, 0.48);
            color: #f8fafc;
            padding: 0.95rem 1rem;
            outline: none;
            transition: all 0.2s ease;
        }
        .field-input::placeholder {
            color: #64748b;
        }
        .field-input:focus {
            border-color: rgba(52, 211, 153, 0.7);
            box-shadow: 0 0 0 4px rgba(16, 185, 129, 0.14);
        }
    </style>
</head>
<body class="h-full bg-slate-950 text-slate-100 antialiased">
    <div class="page-grid relative flex min-h-screen items-center justify-center overflow-hidden px-4 py-10 sm:px-6 lg:px-8">
        <main class="w-full max-w-md">
            <div class="login-panel rounded-3xl border border-white/10 p-8 shadow-panel sm:p-10">
                <div class="text-center">
                    <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl border border-white/10 bg-white/5 text-lg font-semibold text-white">
                        CF
                    </div>
                    <p class="mt-4 text-xs font-semibold uppercase tracking-[0.28em] text-slate-400">CROFUND</p>
                    <h1 class="mt-3 text-3xl font-semibold tracking-tight text-white">
                        <?php echo $isTwoFactorStep ? 'Verify sign in' : 'Administrator sign in'; ?>
                    </h1>
                    <p class="mt-3 text-sm leading-6 text-slate-300">
                        <?php if ($isTwoFactorStep): ?>
                            Enter the verification code to continue.
                        <?php else: ?>
                            Sign in to your administrator account.
                        <?php endif; ?>
                    </p>
                </div>

                <?php if ($error): ?>
                    <div class="mt-6 rounded-2xl border border-red-500/25 bg-red-500/10 px-4 py-3 text-sm text-red-100" role="alert">
                        <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>

                <?php if ($success): ?>
                    <div class="mt-6 rounded-2xl border border-crofund-500/25 bg-crofund-500/10 px-4 py-3 text-sm text-crofund-50" role="status">
                        <?php echo htmlspecialchars($success); ?>
                    </div>
                <?php endif; ?>

                <?php if (!$isTwoFactorStep): ?>
                    <form class="mt-8 space-y-5" method="POST" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" autocomplete="off" id="loginForm">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                        <input type="hidden" name="action" value="login">

                        <div>
                            <label for="username" class="mb-2 block text-sm font-medium text-slate-200">Username</label>
                            <input
                                type="text"
                                name="username"
                                id="username"
                                required
                                value="<?php echo htmlspecialchars($usernameValue); ?>"
                                class="field-input"
                                placeholder="Enter your username"
                                pattern="[a-zA-Z0-9@._-]{3,50}"
                                maxlength="50">
                        </div>

                        <div>
                            <div class="mb-2 flex items-center justify-between gap-4">
                                <label for="password" class="block text-sm font-medium text-slate-200">Password</label>
                                <a href="#" class="text-xs font-medium text-slate-400 hover:text-white">Forgot Password</a>
                            </div>
                            <div class="relative">
                                <input
                                    type="password"
                                    name="password"
                                    id="password"
                                    required
                                    class="field-input pr-12"
                                    placeholder="Enter your password"
                                    minlength="<?php echo PASSWORD_MIN_LENGTH; ?>"
                                    maxlength="<?php echo PASSWORD_MAX_LENGTH; ?>">
                                <button type="button" id="togglePassword" class="absolute inset-y-0 right-0 px-4 text-sm text-slate-400 hover:text-white" aria-label="Toggle password visibility">
                                    Show
                                </button>
                            </div>
                        </div>

                        <button type="submit" class="inline-flex w-full items-center justify-center rounded-2xl bg-gradient-to-r from-crofund-500 to-crofund-600 px-4 py-3.5 text-sm font-semibold text-white transition hover:from-crofund-400 hover:to-crofund-500 focus:outline-none focus:ring-2 focus:ring-crofund-400/40">
                            Sign in
                        </button>
                    </form>
                <?php else: ?>
                    <form class="mt-8 space-y-5" method="POST" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" autocomplete="off" id="verifyForm">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                        <input type="hidden" name="action" value="verify">

                        <div>
                            <label for="verification_code" class="mb-2 block text-sm font-medium text-slate-200">Verification code</label>
                            <input
                                type="text"
                                name="verification_code"
                                id="verification_code"
                                required
                                inputmode="numeric"
                                pattern="\d{6}"
                                maxlength="6"
                                class="field-input text-center text-lg tracking-[0.35em]"
                                placeholder="000000">
                        </div>

                        <button type="submit" class="inline-flex w-full items-center justify-center rounded-2xl bg-gradient-to-r from-crofund-500 to-crofund-600 px-4 py-3.5 text-sm font-semibold text-white transition hover:from-crofund-400 hover:to-crofund-500 focus:outline-none focus:ring-2 focus:ring-crofund-400/40">
                            Continue
                        </button>
                    </form>

                    <div class="mt-5 grid gap-3 sm:grid-cols-2">
                        <form method="POST" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                            <input type="hidden" name="action" value="resend_code">
                            <button type="submit" class="w-full rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-sm font-medium text-slate-200 transition hover:bg-white/10">
                                Resend code
                            </button>
                        </form>
                        <form method="POST" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                            <input type="hidden" name="action" value="back_to_login">
                            <button type="submit" class="w-full rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-sm font-medium text-slate-200 transition hover:bg-white/10">
                                Back
                            </button>
                        </form>
                    </div>
                <?php endif; ?>

                <div class="mt-8 border-t border-white/10 pt-5 text-center text-xs text-slate-500">
                    &copy; <?php echo date('Y'); ?> <?php echo COMPANY_NAME; ?>
                </div>
            </div>
        </main>
    </div>

    <script nonce="<?php echo $nonce; ?>">
        document.addEventListener('DOMContentLoaded', function () {
            const activeForm = document.querySelector('form#loginForm, form#verifyForm');
            if (activeForm) {
                activeForm.addEventListener('submit', function () {
                    const submitButton = this.querySelector('button[type="submit"]');
                    if (!submitButton) {
                        return;
                    }

                    submitButton.disabled = true;
                    submitButton.classList.add('opacity-80', 'cursor-not-allowed');
                    submitButton.textContent = 'Processing...';
                });
            }

            const togglePassword = document.getElementById('togglePassword');
            const passwordInput = document.getElementById('password');
            if (togglePassword && passwordInput) {
                togglePassword.addEventListener('click', function () {
                    const nextType = passwordInput.type === 'password' ? 'text' : 'password';
                    passwordInput.type = nextType;
                    this.textContent = nextType === 'password' ? 'Show' : 'Hide';
                });
            }
        });
    </script>
</body>
</html>
