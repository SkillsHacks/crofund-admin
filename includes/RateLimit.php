<?php
declare(strict_types=1);

class RateLimit {
    private const MAX_ATTEMPTS = 5;        // Maximum login attempts
    private const LOCKOUT_TIME = 900;      // Lockout time in seconds (15 minutes)
    private const ATTEMPT_WINDOW = 300;    // Time window for attempts in seconds (5 minutes)
    private string $cacheFile;             // File to store rate limit data

    public function __construct() {
        $this->cacheFile = __DIR__ . '/../cache/ratelimit.json';
        $this->ensureCacheDirectory();
    }

    private function ensureCacheDirectory(): void {
        $cacheDir = dirname($this->cacheFile);
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }
    }

    public function isLimited(string $ip): bool {
        $data = $this->loadData();
        
        if (!isset($data[$ip])) {
            return false;
        }

        $attempts = $data[$ip];
        
        // Clean up old attempts
        $attempts = array_filter($attempts, function($timestamp) {
            return $timestamp > (time() - self::ATTEMPT_WINDOW);
        });

        // Check if IP is in lockout period
        if (count($attempts) >= self::MAX_ATTEMPTS) {
            $lastAttempt = max($attempts);
            if ($lastAttempt + self::LOCKOUT_TIME > time()) {
                return true;
            }
            // Reset attempts after lockout period
            $attempts = [];
        }

        $data[$ip] = $attempts;
        $this->saveData($data);
        
        return false;
    }

    public function logAttempt(string $ip): void {
        $data = $this->loadData();
        
        if (!isset($data[$ip])) {
            $data[$ip] = [];
        }

        $data[$ip][] = time();
        
        // Keep only recent attempts
        $data[$ip] = array_filter($data[$ip], function($timestamp) {
            return $timestamp > (time() - self::ATTEMPT_WINDOW);
        });

        $this->saveData($data);
    }

    private function loadData(): array {
        if (!file_exists($this->cacheFile)) {
            return [];
        }

        $content = file_get_contents($this->cacheFile);
        if ($content === false) {
            return [];
        }

        $data = json_decode($content, true);
        return is_array($data) ? $data : [];
    }

    private function saveData(array $data): void {
        file_put_contents(
            $this->cacheFile, 
            json_encode($data), 
            LOCK_EX
        );
    }

    public function resetAttempts(string $ip): void {
        $data = $this->loadData();
        unset($data[$ip]);
        $this->saveData($data);
    }
}