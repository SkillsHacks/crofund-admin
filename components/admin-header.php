
<header class="bg-white shadow">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between h-16">
            <div class="flex">
                <div class="flex-shrink-0 flex items-center">
                    <a href="<?php echo BASE_URL; ?>/admin/dashboard.php" class="text-2xl font-bold text-emerald-600">
                        CroFunding Admin
                    </a>
                </div>
                <nav class="ml-6 flex space-x-4">
                    <a href="<?php echo BASE_URL; ?>/admin/dashboard.php" 
                       class="text-gray-600 hover:text-gray-900 px-3 py-2 rounded-md text-sm font-medium">
                        Dashboard
                    </a>
                    <a href="<?php echo BASE_URL; ?>/admin/users.php" 
                       class="text-gray-600 hover:text-gray-900 px-3 py-2 rounded-md text-sm font-medium">
                        Users
                    </a>
                    <a href="<?php echo BASE_URL; ?>/admin/campaigns.php" 
                       class="text-gray-600 hover:text-gray-900 px-3 py-2 rounded-md text-sm font-medium">
                        Campaigns
                    </a>
                    <a href="<?php echo BASE_URL; ?>/admin/donations.php" 
                       class="text-gray-600 hover:text-gray-900 px-3 py-2 rounded-md text-sm font-medium">
                        Donations
                    </a>
                </nav>
            </div>
            <div class="flex items-center">
                <form action="<?php echo BASE_URL; ?>/admin/logout.php" method="POST">
                    <button type="submit" 
                            class="text-gray-600 hover:text-gray-900 px-3 py-2 rounded-md text-sm font-medium">
                        Logout
                    </button>
                </form>
            </div>
        </div>
    </div>
</header>