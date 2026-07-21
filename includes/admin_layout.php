<?php
declare(strict_types=1);

if (!function_exists('admin_nav_items')) {
    function admin_nav_items(): array {
        return [
            'dashboard' => ['label' => 'Dashboard', 'href' => 'dashboard.php', 'icon' => 'fa-solid fa-chart-pie'],
            'withdraws' => ['label' => 'Withdrawals', 'href' => 'withdraws.php', 'icon' => 'fa-solid fa-money-bill-transfer'],
            'percent' => ['label' => 'Percentages', 'href' => 'percent.php', 'icon' => 'fa-solid fa-percent'],
            'emails' => ['label' => 'Emails', 'href' => 'emails.php', 'icon' => 'fa-solid fa-envelope-circle-check'],
            'campaigns' => ['label' => 'Campaigns', 'href' => 'campaigns.php', 'icon' => 'fa-solid fa-bullhorn'],
            'donorsMail' => ['label' => 'Donors Mail', 'href' => 'donorsMail.php', 'icon' => 'fa-solid fa-paper-plane'],
        ];
    }
}

if (!function_exists('admin_shell_start')) {
    function admin_shell_start(string $pageKey, string $pageTitle, string $pageSubtitle = '', array $actions = [], array $extraHeadTags = []): void {
        $companyName = defined('COMPANY_NAME') ? COMPANY_NAME : 'Crofund';
        $pageTitleHtml = htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8');
        $pageSubtitleHtml = htmlspecialchars($pageSubtitle, ENT_QUOTES, 'UTF-8');
        $fullTitle = $pageTitleHtml . ' | ' . htmlspecialchars($companyName, ENT_QUOTES, 'UTF-8');
        $navItems = admin_nav_items();
        $adminName = isset($_SESSION['admin_name']) ? (string) $_SESSION['admin_name'] : 'Crofund Admin';
        $adminNameHtml = htmlspecialchars($adminName, ENT_QUOTES, 'UTF-8');
        ?>
<!DOCTYPE html>
<html lang="en" class="h-full bg-slate-950">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#020617">
    <title><?php echo $fullTitle; ?></title>
    <link rel="preconnect" href="https://cdn.jsdelivr.net">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.5.2/css/all.min.css">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        crofund: {
                            50: '#ecfdf5',
                            100: '#d1fae5',
                            200: '#a7f3d0',
                            300: '#6ee7b7',
                            400: '#34d399',
                            500: '#10b981',
                            600: '#059669',
                            700: '#047857',
                            800: '#065f46',
                            900: '#064e3b'
                        }
                    },
                    boxShadow: {
                        panel: '0 18px 50px rgba(2, 6, 23, 0.35)',
                        insetsoft: 'inset 0 1px 0 rgba(255,255,255,0.04)'
                    }
                }
            }
        };
    </script>
    <?php foreach ($extraHeadTags as $tag): ?>
        <?php echo $tag, PHP_EOL; ?>
    <?php endforeach; ?>
    <style>
        .admin-grid {
            background-image:
                linear-gradient(rgba(148, 163, 184, 0.06) 1px, transparent 1px),
                linear-gradient(90deg, rgba(148, 163, 184, 0.06) 1px, transparent 1px);
            background-size: 40px 40px;
        }
        .admin-card {
            background: linear-gradient(180deg, rgba(15,23,42,0.92), rgba(15,23,42,0.82));
            border: 1px solid rgba(148,163,184,0.12);
            box-shadow: 0 20px 50px rgba(2,6,23,0.35);
            backdrop-filter: blur(14px);
        }
        .admin-surface {
            background: rgba(15, 23, 42, 0.82);
            border: 1px solid rgba(148,163,184,0.14);
        }
        .admin-input {
            width: 100%;
            border-radius: 1rem;
            border: 1px solid rgba(148,163,184,0.18);
            background: rgba(2, 6, 23, 0.45);
            color: #f8fafc;
            padding: 0.8rem 1rem;
            outline: none;
            transition: all 0.2s ease;
        }
        .admin-input::placeholder {
            color: #64748b;
        }
        .admin-input:focus {
            border-color: rgba(52, 211, 153, 0.7);
            box-shadow: 0 0 0 4px rgba(16, 185, 129, 0.14);
        }
        .admin-select {
            appearance: none;
        }
        .stat-card {
            background: linear-gradient(180deg, rgba(15,23,42,0.9), rgba(15,23,42,0.8));
            border: 1px solid rgba(148,163,184,0.14);
        }
        .orb-a, .orb-b {
            position: absolute;
            border-radius: 9999px;
            filter: blur(60px);
            opacity: 0.22;
            pointer-events: none;
        }
        .orb-a { width: 20rem; height: 20rem; background: #10b981; top: -4rem; left: -4rem; }
        .orb-b { width: 24rem; height: 24rem; background: #06b6d4; bottom: -8rem; right: -6rem; }
        .table-shell table thead th {
            color: #94a3b8;
            font-size: 0.72rem;
            text-transform: uppercase;
            letter-spacing: 0.08em;
        }
        .table-shell table tbody tr {
            border-top: 1px solid rgba(148,163,184,0.1);
        }
        .table-shell table tbody tr:hover {
            background: rgba(15, 118, 110, 0.08);
        }
    </style>
</head>
<body class="min-h-full bg-slate-950 text-slate-100 antialiased">
    <div class="relative min-h-screen overflow-hidden bg-slate-950">
        <div class="absolute inset-0 admin-grid"></div>
        <div class="orb-a"></div>
        <div class="orb-b"></div>

        <div id="mobile-sidebar-overlay" class="fixed inset-0 z-40 hidden bg-slate-950/70 backdrop-blur-sm lg:hidden"></div>

        <aside id="admin-sidebar" class="fixed inset-y-0 left-0 z-50 flex w-80 max-w-[86vw] -translate-x-full flex-col border-r border-white/10 bg-slate-950/95 px-5 py-6 shadow-2xl backdrop-blur transition-transform duration-300 lg:translate-x-0">
            <div class="flex items-center justify-between gap-3">
                <div class="flex items-center gap-3">
                    <div class="flex h-12 w-12 items-center justify-center rounded-2xl bg-gradient-to-br from-crofund-500 to-emerald-700 text-white shadow-lg shadow-crofund-900/40">
                        <i class="fa-solid fa-seedling"></i>
                    </div>
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.24em] text-crofund-200/80">Crofund</p>
                        <h1 class="text-lg font-bold text-white">Admin Console</h1>
                    </div>
                </div>
                <button id="close-sidebar" type="button" class="rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-slate-300 lg:hidden">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>

            <div class="mt-6 rounded-2xl border border-crofund-400/20 bg-crofund-500/10 p-4 text-sm text-crofund-50">
                <p class="text-xs font-semibold uppercase tracking-[0.22em] text-crofund-200/80">Signed in</p>
                <p class="mt-2 font-medium text-white"><?php echo $adminNameHtml; ?></p>
                <p class="mt-1 text-xs text-crofund-100/80">Protected operations workspace</p>
            </div>

            <nav class="mt-8 flex-1 space-y-2">
                <?php foreach ($navItems as $key => $item): ?>
                    <?php $isActive = $key === $pageKey; ?>
                    <a href="<?php echo htmlspecialchars($item['href'], ENT_QUOTES, 'UTF-8'); ?>"
                       class="group flex items-center gap-3 rounded-2xl px-4 py-3 text-sm font-medium transition <?php echo $isActive ? 'bg-gradient-to-r from-crofund-500/20 to-emerald-500/10 text-white ring-1 ring-crofund-300/30' : 'text-slate-300 hover:bg-white/5 hover:text-white'; ?>">
                        <span class="flex h-10 w-10 items-center justify-center rounded-xl <?php echo $isActive ? 'bg-crofund-500 text-white shadow-lg shadow-crofund-900/30' : 'bg-white/5 text-slate-400 group-hover:text-crofund-300'; ?>">
                            <i class="<?php echo htmlspecialchars($item['icon'], ENT_QUOTES, 'UTF-8'); ?>"></i>
                        </span>
                        <span><?php echo htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8'); ?></span>
                    </a>
                <?php endforeach; ?>
            </nav>

            <div class="mt-6">
                <a href="logout.php" class="inline-flex w-full items-center justify-center gap-3 rounded-2xl border border-red-500/20 bg-red-500/10 px-4 py-3 text-sm font-semibold text-red-100 transition hover:bg-red-500/15">
                    <i class="fa-solid fa-right-from-bracket"></i>
                    <span>Log out</span>
                </a>
            </div>
        </aside>

        <div class="relative z-10 lg:pl-80">
            <header class="sticky top-0 z-30 border-b border-white/10 bg-slate-950/80 backdrop-blur">
                <div class="mx-auto flex max-w-[1600px] items-center justify-between gap-4 px-4 py-4 sm:px-6 lg:px-8">
                    <div class="flex items-center gap-3">
                        <button id="open-sidebar" type="button" class="inline-flex h-11 w-11 items-center justify-center rounded-2xl border border-white/10 bg-white/5 text-slate-200 lg:hidden">
                            <i class="fa-solid fa-bars"></i>
                        </button>
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-[0.22em] text-crofund-200/80">Crofund Admin</p>
                            <h2 class="text-2xl font-bold tracking-tight text-white"><?php echo $pageTitleHtml; ?></h2>
                            <?php if ($pageSubtitle !== ''): ?>
                                <p class="mt-1 text-sm text-slate-400"><?php echo $pageSubtitleHtml; ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php if (!empty($actions)): ?>
                        <div class="flex flex-wrap items-center gap-3">
                            <?php foreach ($actions as $action): ?>
                                <a href="<?php echo htmlspecialchars($action['href'] ?? '#', ENT_QUOTES, 'UTF-8'); ?>"
                                   class="inline-flex items-center gap-2 rounded-2xl px-4 py-3 text-sm font-semibold transition <?php echo !empty($action['primary']) ? 'bg-gradient-to-r from-crofund-500 to-emerald-600 text-white shadow-lg shadow-crofund-900/30 hover:from-crofund-400 hover:to-emerald-500' : 'border border-white/10 bg-white/5 text-slate-200 hover:bg-white/10'; ?>">
                                    <?php if (!empty($action['icon'])): ?><i class="<?php echo htmlspecialchars($action['icon'], ENT_QUOTES, 'UTF-8'); ?>"></i><?php endif; ?>
                                    <span><?php echo htmlspecialchars((string) ($action['label'] ?? 'Action'), ENT_QUOTES, 'UTF-8'); ?></span>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </header>

            <main class="mx-auto max-w-[1600px] px-4 py-8 sm:px-6 lg:px-8">
        <?php
    }
}

if (!function_exists('admin_shell_end')) {
    function admin_shell_end(array $extraScripts = []): void {
        ?>
            </main>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const sidebar = document.getElementById('admin-sidebar');
            const overlay = document.getElementById('mobile-sidebar-overlay');
            const openBtn = document.getElementById('open-sidebar');
            const closeBtn = document.getElementById('close-sidebar');

            function openSidebar() {
                if (!sidebar || !overlay) return;
                sidebar.classList.remove('-translate-x-full');
                overlay.classList.remove('hidden');
            }

            function closeSidebar() {
                if (!sidebar || !overlay) return;
                sidebar.classList.add('-translate-x-full');
                overlay.classList.add('hidden');
            }

            openBtn && openBtn.addEventListener('click', openSidebar);
            closeBtn && closeBtn.addEventListener('click', closeSidebar);
            overlay && overlay.addEventListener('click', closeSidebar);
        });
    </script>
    <?php foreach ($extraScripts as $script): ?>
        <?php echo $script, PHP_EOL; ?>
    <?php endforeach; ?>
</body>
</html>
        <?php
    }
}

if (!function_exists('admin_alert')) {
    function admin_alert(string $message, string $type = 'info'): void {
        $styles = [
            'success' => 'border-crofund-500/30 bg-crofund-500/10 text-crofund-50',
            'error' => 'border-red-500/30 bg-red-500/10 text-red-100',
            'warning' => 'border-amber-500/30 bg-amber-500/10 text-amber-100',
            'info' => 'border-cyan-500/30 bg-cyan-500/10 text-cyan-100',
        ];
        $icons = [
            'success' => 'fa-solid fa-circle-check',
            'error' => 'fa-solid fa-circle-exclamation',
            'warning' => 'fa-solid fa-triangle-exclamation',
            'info' => 'fa-solid fa-circle-info',
        ];
        $class = $styles[$type] ?? $styles['info'];
        $icon = $icons[$type] ?? $icons['info'];
        ?>
        <div class="mb-6 rounded-3xl border p-4 text-sm <?php echo $class; ?>">
            <div class="flex items-start gap-3">
                <i class="<?php echo $icon; ?> mt-0.5"></i>
                <p><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></p>
            </div>
        </div>
        <?php
    }
}

if (!function_exists('admin_stat_card')) {
    function admin_stat_card(string $label, string $value, string $icon, string $accent = 'emerald', string $subtext = ''): void {
        $accentMap = [
            'emerald' => 'from-crofund-500/20 to-emerald-500/5 text-crofund-300',
            'blue' => 'from-sky-500/20 to-cyan-500/5 text-sky-300',
            'purple' => 'from-violet-500/20 to-fuchsia-500/5 text-violet-300',
            'amber' => 'from-amber-500/20 to-yellow-500/5 text-amber-300',
            'rose' => 'from-rose-500/20 to-pink-500/5 text-rose-300',
        ];
        $accentClass = $accentMap[$accent] ?? $accentMap['emerald'];
        ?>
        <div class="stat-card rounded-3xl p-5 shadow-panel">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <p class="text-sm text-slate-400"><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></p>
                    <p class="mt-3 text-3xl font-bold tracking-tight text-white"><?php echo htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); ?></p>
                    <?php if ($subtext !== ''): ?>
                        <p class="mt-2 text-xs text-slate-500"><?php echo htmlspecialchars($subtext, ENT_QUOTES, 'UTF-8'); ?></p>
                    <?php endif; ?>
                </div>
                <div class="flex h-14 w-14 items-center justify-center rounded-2xl bg-gradient-to-br <?php echo $accentClass; ?>">
                    <i class="<?php echo htmlspecialchars($icon, ENT_QUOTES, 'UTF-8'); ?> text-lg"></i>
                </div>
            </div>
        </div>
        <?php
    }
}
