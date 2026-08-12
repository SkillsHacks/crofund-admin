<?php
declare(strict_types=1);

require_once 'includes/config.php';
session_start();
require_once 'includes/auth.php';
$auth = new Auth();
$auth->requireAuth();
require_once 'includes/admin_layout.php';

class AdminTrustRiskService
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function assertAdminSession(): void
    {
        if (empty($_SESSION['admin_id'])) {
            throw new RuntimeException('You do not have permission to access trust and risk tools.');
        }
    }

    public function resolveModeratorUserId(): int
    {
        $adminId = (int) ($_SESSION['admin_id'] ?? 0);
        $adminUsername = trim((string) ($_SESSION['admin_username'] ?? ''));
        $adminName = trim((string) ($_SESSION['admin_name'] ?? ''));

        if ($adminId <= 0) {
            throw new RuntimeException('Missing authenticated admin session.');
        }

        if ($adminUsername !== '') {
            $stmt = $this->db->prepare('SELECT id FROM users WHERE email = ? OR name = ? LIMIT 1');
            $stmt->execute([$adminUsername, $adminUsername]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($user && !empty($user['id'])) {
                return (int) $user['id'];
            }
        }

        if ($adminName !== '') {
            $stmt = $this->db->prepare('SELECT id FROM users WHERE name = ? LIMIT 1');
            $stmt->execute([$adminName]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($user && !empty($user['id'])) {
                return (int) $user['id'];
            }
        }

        $stmt = $this->db->prepare('SELECT email, full_name, username FROM admin_users WHERE id = ? LIMIT 1');
        $stmt->execute([$adminId]);
        $adminUser = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($adminUser) {
            $candidateValues = array_values(array_filter([
                trim((string) ($adminUser['email'] ?? '')),
                trim((string) ($adminUser['full_name'] ?? '')),
                trim((string) ($adminUser['username'] ?? '')),
            ], static fn (string $value): bool => $value !== ''));

            foreach ($candidateValues as $candidate) {
                $userStmt = $this->db->prepare('SELECT id FROM users WHERE email = ? OR name = ? LIMIT 1');
                $userStmt->execute([$candidate, $candidate]);
                $user = $userStmt->fetch(PDO::FETCH_ASSOC);
                if ($user && !empty($user['id'])) {
                    return (int) $user['id'];
                }
            }
        }

        error_log('Moderation fallback: admin session ' . $adminId . ' is not linked to a users row. Falling back to admin_users.id for audit records.');
        return $adminId;
    }

    public function searchUsersForModeration(string $query = '', ?string $status = null): array
    {
        $sql = 'SELECT id, name, email, created_at,
                       COALESCE(risk_status, "ACTIVE") AS risk_status,
                       risk_status_reason,
                       risk_review_expires_at
                FROM users
                WHERE 1=1';
        $params = [];

        if ($query !== '') {
            $sql .= ' AND (name LIKE ? OR email LIKE ?)';
            $params[] = '%' . $query . '%';
            $params[] = '%' . $query . '%';
        }

        if ($status !== null && $status !== '') {
            $sql .= ' AND COALESCE(risk_status, "ACTIVE") = ?';
            $params[] = strtoupper($status);
        }

        $sql .= ' ORDER BY created_at DESC LIMIT 100';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function getUsersWithPendingIdentityReview(string $query = ''): array
    {
        $sql = 'SELECT u.id,
                       u.name,
                       u.email,
                       u.created_at,
                       COALESCE(u.risk_status, "ACTIVE") AS risk_status,
                       u.risk_status_reason,
                       u.risk_review_expires_at,
                       latest_attempt.status AS identity_status,
                       latest_attempt.decision_status,
                       latest_attempt.created_at AS identity_submitted_at
                FROM users u
                INNER JOIN (
                    SELECT iva.user_id,
                           iva.status,
                           iva.decision_status,
                           iva.created_at,
                           iva.id
                    FROM identity_verification_attempts iva
                    INNER JOIN (
                        SELECT user_id, MAX(id) AS latest_id
                        FROM identity_verification_attempts
                        GROUP BY user_id
                    ) latest ON latest.latest_id = iva.id
                ) latest_attempt ON latest_attempt.user_id = u.id
                WHERE latest_attempt.status IN ("pending_review", "uploaded")';

        $params = [];

        if ($query !== '') {
            $sql .= ' AND (u.name LIKE ? OR u.email LIKE ?)';
            $params[] = '%' . $query . '%';
            $params[] = '%' . $query . '%';
        }

        $sql .= ' ORDER BY latest_attempt.created_at DESC, u.created_at DESC LIMIT 100';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function getModerationHistory(int $userId): array
    {
        $stmt = $this->db->prepare(
            'SELECT ma.*, u.name AS admin_name, u.email AS admin_email
             FROM moderation_actions ma
             LEFT JOIN users u ON u.id = ma.admin_id
             WHERE ma.user_id = ?
             ORDER BY ma.created_at DESC'
        );
        $stmt->execute([$userId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function getOpenFlags(int $userId): array
    {
        $stmt = $this->db->prepare(
            'SELECT flag_type, severity, reason, internal_notes, status, review_expires_at, created_at
             FROM user_risk_flags
             WHERE user_id = ?
             ORDER BY created_at DESC'
        );
        $stmt->execute([$userId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function getUserRiskProfile(int $userId): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, name, email, created_at,
                    COALESCE(risk_status, "ACTIVE") AS risk_status,
                    risk_status_reason,
                    risk_review_expires_at,
                    COALESCE(id_verified, 0) AS id_verified,
                    verification_attempted,
                    verification_attempts
             FROM users
             WHERE id = ?
             LIMIT 1'
        );
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            throw new RuntimeException('User not found.');
        }

        return [
            'user' => $user,
            'status' => (string) $user['risk_status'],
            'message' => [
                'full' => (string) ($user['risk_status_reason'] ?: 'No risk reason recorded for this user yet.'),
            ],
            'review_expires_at' => $user['risk_review_expires_at'] ?? null,
            'flags' => $this->getOpenFlags((int) $userId),
            'latest_identity_attempt' => $this->getLatestIdentityVerificationAttempt((int) $userId),
            'identity_attempts' => $this->getIdentityVerificationAttempts((int) $userId),
        ];
    }

    public function setUserStatus(int $adminId, int $userId, string $newStatus, string $reason, ?string $reviewExpiresAt = null, ?string $internalNotes = null): void
    {
        $allowedStatuses = ['ACTIVE', 'UNDER_REVIEW', 'LIMITED', 'SUSPENDED', 'BANNED'];
        $newStatus = strtoupper(trim($newStatus));

        if (!in_array($newStatus, $allowedStatuses, true)) {
            throw new InvalidArgumentException('Invalid risk status.');
        }

        $stmt = $this->db->prepare('SELECT COALESCE(risk_status, "ACTIVE") AS risk_status FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $current = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$current) {
            throw new RuntimeException('User not found.');
        }

        $previousStatus = (string) $current['risk_status'];

        $update = $this->db->prepare(
            'UPDATE users
             SET risk_status = ?,
                 risk_status_reason = ?,
                 risk_review_expires_at = ?,
                 risk_last_action_at = NOW(),
                 risk_last_action_by = ?
             WHERE id = ?'
        );
        $update->execute([$newStatus, $reason, $reviewExpiresAt, $adminId, $userId]);

        $this->logModerationAction($adminId, $userId, 'status_change', $previousStatus, $newStatus, $reason, $internalNotes, $reviewExpiresAt);
    }

    public function addRiskFlag(int $adminId, int $userId, string $flagType, string $severity, string $reason, ?string $internalNotes = null, ?string $reviewExpiresAt = null): void
    {
        $severity = strtolower(trim($severity));
        $allowedSeverities = ['low', 'medium', 'high', 'critical'];
        if (!in_array($severity, $allowedSeverities, true)) {
            throw new InvalidArgumentException('Invalid flag severity.');
        }

        $flagType = trim($flagType) !== '' ? trim($flagType) : 'manual_review';

        $stmt = $this->db->prepare(
            'INSERT INTO user_risk_flags
                (user_id, flag_type, severity, source, status, score_impact, reason, internal_notes, created_by, review_expires_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())'
        );
        $stmt->execute([
            $userId,
            $flagType,
            $severity,
            'manual',
            'open',
            $this->defaultScoreForSeverity($severity),
            $reason,
            $internalNotes,
            $adminId,
            $reviewExpiresAt,
        ]);

        $this->logModerationAction($adminId, $userId, 'flag_added', null, null, $reason, $internalNotes, $reviewExpiresAt, [
            'flag_type' => $flagType,
            'severity' => $severity,
        ]);
    }

    public function getLatestIdentityVerificationAttempt(int $userId): ?array
    {
        $attempts = $this->getIdentityVerificationAttempts($userId, 1);
        return $attempts[0] ?? null;
    }

    public function getIdentityAttemptStatusLabel(?string $status): string
    {
        $normalizedStatus = strtolower(trim((string) $status));

        return match ($normalizedStatus) {
            'failed' => 'Upload Failed',
            'uploaded' => 'Uploaded',
            'pending_review' => 'Under Review',
            'rejected' => 'Rejected',
            'verified' => 'Verified',
            default => ucwords(str_replace('_', ' ', $normalizedStatus !== '' ? $normalizedStatus : 'unknown')),
        };
    }

    public function getIdentityDecisionStatusLabel(?string $status): string
    {
        $normalizedStatus = strtolower(trim((string) $status));

        return match ($normalizedStatus) {
            'manual_review' => 'Manual Review',
            'partial_match' => 'Partial Match',
            'matched' => 'Matched',
            'no_match' => 'No Match',
            default => ucwords(str_replace('_', ' ', $normalizedStatus !== '' ? $normalizedStatus : 'unknown')),
        };
    }

    public function canReviewIdentityAttempt(array $attempt): bool
    {
        $status = strtolower(trim((string) ($attempt['status'] ?? '')));
        return in_array($status, ['pending_review', 'uploaded'], true);
    }

    public function getIdentityVerificationAttempts(int $userId, ?int $limit = null): array
    {
        $sql = 'SELECT *
                FROM identity_verification_attempts
                WHERE user_id = ?
                ORDER BY created_at DESC, id DESC';

        if ($limit !== null && $limit > 0) {
            $sql .= ' LIMIT ' . (int) $limit;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$userId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function approveIdentityVerification(int $adminId, int $userId, int $attemptId, string $reason, ?string $internalNotes = null): void
    {
        $attempt = $this->requireIdentityAttemptForUser($userId, $attemptId);
        $previousStatus = (string) ($attempt['status'] ?? 'unknown');

        $this->db->beginTransaction();

        try {
            $updateAttempt = $this->db->prepare(
                'UPDATE identity_verification_attempts
                 SET status = ?,
                     updated_at = NOW()
                 WHERE id = ? AND user_id = ?'
            );
            $updateAttempt->execute(['verified', $attemptId, $userId]);

            $updateUser = $this->db->prepare(
                'UPDATE users
                 SET id_verified = 1,
                     verification_attempted = 1
                 WHERE id = ?'
            );
            $updateUser->execute([$userId]);

            $this->logModerationAction(
                $adminId,
                $userId,
                'identity_verification_approved',
                $previousStatus,
                'verified',
                $reason,
                $internalNotes,
                null,
                [
                    'attempt_id' => $attemptId,
                    'document_type' => $attempt['document_type'] ?? 'national_id',
                    'decision_status' => $attempt['decision_status'] ?? null,
                    'decision_score' => $attempt['decision_score'] ?? null,
                ]
            );

            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function rejectIdentityVerification(int $adminId, int $userId, int $attemptId, string $reason, ?string $internalNotes = null): void
    {
        $attempt = $this->requireIdentityAttemptForUser($userId, $attemptId);
        $previousStatus = (string) ($attempt['status'] ?? 'unknown');

        $this->db->beginTransaction();

        try {
            $updateAttempt = $this->db->prepare(
                'UPDATE identity_verification_attempts
                 SET status = ?,
                     failure_reason = ?,
                     updated_at = NOW()
                 WHERE id = ? AND user_id = ?'
            );
            $updateAttempt->execute(['rejected', $reason, $attemptId, $userId]);

            $updateUser = $this->db->prepare(
                'UPDATE users
                 SET id_verified = 0,
                     verification_attempted = 1,
                     verification_attempts = verification_attempts + 1
                 WHERE id = ?'
            );
            $updateUser->execute([$userId]);

            $this->logModerationAction(
                $adminId,
                $userId,
                'identity_verification_rejected',
                $previousStatus,
                'rejected',
                $reason,
                $internalNotes,
                null,
                [
                    'attempt_id' => $attemptId,
                    'document_type' => $attempt['document_type'] ?? 'national_id',
                    'decision_status' => $attempt['decision_status'] ?? null,
                    'decision_score' => $attempt['decision_score'] ?? null,
                ]
            );

            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    private function requireIdentityAttemptForUser(int $userId, int $attemptId): array
    {
        if ($attemptId <= 0) {
            throw new InvalidArgumentException('Invalid identity verification attempt.');
        }

        $stmt = $this->db->prepare(
            'SELECT *
             FROM identity_verification_attempts
             WHERE id = ? AND user_id = ?
             LIMIT 1'
        );
        $stmt->execute([$attemptId, $userId]);
        $attempt = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$attempt) {
            throw new RuntimeException('Identity verification attempt not found for this user.');
        }

        return $attempt;
    }

    private function logModerationAction(int $adminId, int $userId, string $actionType, ?string $previousStatus, ?string $newStatus, string $reason, ?string $internalNotes = null, ?string $reviewExpiresAt = null, ?array $metadata = null): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO moderation_actions
                (user_id, admin_id, action_type, previous_status, new_status, reason, internal_notes, review_expires_at, metadata, ip_address, user_agent, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
        );
        $stmt->execute([
            $userId,
            $adminId,
            $actionType,
            $previousStatus,
            $newStatus,
            $reason,
            $internalNotes,
            $reviewExpiresAt,
            $metadata ? json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            $_SERVER['REMOTE_ADDR'] ?? null,
            $_SERVER['HTTP_USER_AGENT'] ?? null,
        ]);
    }

    private function defaultScoreForSeverity(string $severity): int
    {
        return match ($severity) {
            'low' => 10,
            'medium' => 30,
            'high' => 60,
            'critical' => 90,
            default => 20,
        };
    }
}
