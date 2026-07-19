<?php
declare(strict_types=1);

require_once __DIR__ . '/EmailManager.php';
require_once __DIR__ . '/EmailQueue.php';

class Auth {
    private PDO $db;
    
    public function __construct() {
        require_once __DIR__ . '/config.php';
        
        try {
            $this->db = new PDO(
                "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET,
                DB_USER,
                DB_PASS,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]
            );
        } catch (PDOException $e) {
            error_log("Database connection failed: " . $e->getMessage());
            throw new Exception("Authentication system unavailable");
        }
    }

    public function startLogin(string $username, string $password): bool {
        $user = $this->findActiveAdminByUsername($username);

        if (!$user || !password_verify($password, $user['password_hash'])) {
            return false;
        }

        $code = $this->generateTwoFactorCode();
        $codeHash = password_hash($code, PASSWORD_DEFAULT);

        $_SESSION['pending_admin_login'] = [
            'admin_id' => (int) $user['id'],
            'username' => $user['username'],
            'email' => $user['email'],
            'full_name' => $user['full_name'],
            'code_hash' => $codeHash,
            'expires_at' => time() + TWO_FACTOR_CODE_TTL,
            'attempts' => 0,
            'remember_username' => $user['username'],
        ];
        $_SESSION['two_factor_last_sent_at'] = time();

        $queue = new EmailQueue($this->db);
        $queue->ensureSchema();
        if (!$queue->queueAdminTwoFactorCode($user['email'], $user['full_name'], $code)) {
            unset($_SESSION['pending_admin_login'], $_SESSION['two_factor_last_sent_at']);
            throw new Exception('Unable to queue verification code.');
        }

        return true;
    }

    public function verifyTwoFactorCode(string $code): bool {
        if (!$this->hasPendingTwoFactor()) {
            return false;
        }

        $pending = $_SESSION['pending_admin_login'];

        if (($pending['attempts'] ?? 0) >= TWO_FACTOR_MAX_ATTEMPTS) {
            unset($_SESSION['pending_admin_login'], $_SESSION['two_factor_last_sent_at']);
            return false;
        }

        if (!password_verify($code, $pending['code_hash'])) {
            $_SESSION['pending_admin_login']['attempts'] = ($pending['attempts'] ?? 0) + 1;
            return false;
        }

        $_SESSION = array();
        $_SESSION['admin_id'] = (int) $pending['admin_id'];
        $_SESSION['admin_username'] = $pending['username'];
        $_SESSION['admin_name'] = $pending['full_name'];
        $_SESSION['admin_logged_in'] = true;
        $_SESSION['last_activity'] = time();

        $this->updateLastLogin((int) $pending['admin_id']);

        return true;
    }

    public function resendTwoFactorCode(): bool {
        if (!$this->hasPendingTwoFactor()) {
            return false;
        }

        $lastSentAt = $_SESSION['two_factor_last_sent_at'] ?? 0;
        if ((time() - (int) $lastSentAt) < TWO_FACTOR_RESEND_COOLDOWN) {
            return false;
        }

        $pending = $_SESSION['pending_admin_login'];
        $code = $this->generateTwoFactorCode();
        $_SESSION['pending_admin_login']['code_hash'] = password_hash($code, PASSWORD_DEFAULT);
        $_SESSION['pending_admin_login']['expires_at'] = time() + TWO_FACTOR_CODE_TTL;
        $_SESSION['pending_admin_login']['attempts'] = 0;
        $_SESSION['two_factor_last_sent_at'] = time();

        $queue = new EmailQueue($this->db);
        $queue->ensureSchema();
        return $queue->queueAdminTwoFactorCode($pending['email'], $pending['full_name'], $code);
    }

    public function hasPendingTwoFactor(): bool {
        if (empty($_SESSION['pending_admin_login']) || !is_array($_SESSION['pending_admin_login'])) {
            return false;
        }

        $pending = $_SESSION['pending_admin_login'];
        if (($pending['expires_at'] ?? 0) < time()) {
            unset($_SESSION['pending_admin_login'], $_SESSION['two_factor_last_sent_at']);
            return false;
        }

        return true;
    }

    public function getPendingTwoFactorUser(): ?array {
        if (!$this->hasPendingTwoFactor()) {
            return null;
        }

        $pending = $_SESSION['pending_admin_login'];
        return [
            'username' => $pending['username'],
            'full_name' => $pending['full_name'],
            'masked_email' => $this->maskEmail($pending['email']),
            'expires_in' => max(0, (int) $pending['expires_at'] - time()),
        ];
    }

    public function clearPendingTwoFactor(): void {
        unset($_SESSION['pending_admin_login'], $_SESSION['two_factor_last_sent_at']);
    }

    public function login(string $username, string $password): bool {
        return $this->startLogin($username, $password);
    }

    public function isLoggedIn(): bool {
        return isset($_SESSION['admin_logged_in']) && 
               $_SESSION['admin_logged_in'] === true && 
               isset($_SESSION['last_activity']) && 
               (time() - $_SESSION['last_activity'] < SESSION_LIFETIME);
    }

    public function requireAuth(): void {
        if (!$this->isLoggedIn()) {
            $_SESSION = array();
            session_destroy();
            header('Location: index.php');
            exit();
        }
        
        $_SESSION['last_activity'] = time();
    }

    private function findActiveAdminByUsername(string $username): ?array {
        $stmt = $this->db->prepare(
            "SELECT id, username, password_hash, email, full_name FROM admin_users WHERE username = ? AND active = 1 LIMIT 1"
        );
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        return $user ?: null;
    }

    private function generateTwoFactorCode(): string {
        if (function_exists('random_int')) {
            return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        }

        $bytes = secure_random_bytes(4);
        $number = unpack('N', $bytes)[1] % 1000000;
        return str_pad((string) $number, 6, '0', STR_PAD_LEFT);
    }

    private function updateLastLogin(int $adminId): void {
        $stmt = $this->db->prepare("UPDATE admin_users SET last_login = NOW() WHERE id = ?");
        $stmt->execute([$adminId]);
    }

    private function maskEmail(string $email): string {
        $parts = explode('@', $email, 2);
        if (count($parts) !== 2) {
            return $email;
        }

        $name = $parts[0];
        $domain = $parts[1];
        $visible = substr($name, 0, min(2, strlen($name)));
        return $visible . str_repeat('*', max(1, strlen($name) - strlen($visible))) . '@' . $domain;
    }
}