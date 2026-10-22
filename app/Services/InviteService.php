<?php
namespace OpenLMS\Services;

use OpenLMS\Core\Config;
use OpenLMS\Core\Database;
use PDO;

final class InviteService
{
    public const MIN_PUBLIC_ID = 100000;
    public const MAX_PUBLIC_ID = 99999999;
    public const MAX_BULK_USES = 500;
    public const MAX_EXPIRY_HOURS = 720;

    public static function create(string $role, int $maxUses, int $expiresHours, int $creatorId): array
    {
        $pdo = Database::connection();
        $role = trim($role);
        $maxUses = max(1, min(self::MAX_BULK_USES, $maxUses));
        $expiresHours = max(1, min(self::MAX_EXPIRY_HOURS, $expiresHours));

        if ($creatorId < 1 || $role === '' || $role === 'super_admin') {
            throw new \InvalidArgumentException('Invalid registration-link request.');
        }
        $roleCheck = $pdo->prepare('SELECT label FROM roles WHERE name=? LIMIT 1');
        $roleCheck->execute([$role]);
        $roleLabel = $roleCheck->fetchColumn();
        if ($roleLabel === false) throw new \InvalidArgumentException('Choose a valid account role.');

        self::archiveExpired($pdo);
        $publicId = self::generatePublicId($pdo);
        $secret = bin2hex(random_bytes(24));
        $now = gmdate('Y-m-d H:i:s');
        $expiresAt = gmdate('Y-m-d H:i:s', time() + ($expiresHours * 3600));
        $pdo->prepare('INSERT INTO registration_invites (public_id,token_hash,role_name,max_uses,used_count,expires_at,created_by,created_at) VALUES (?,?,?,?,0,?,?,?)')
            ->execute([$publicId, hash('sha256', $secret), $role, $maxUses, $expiresAt, $creatorId, $now]);

        return [
            'public_id' => $publicId,
            'secret' => $secret,
            'role' => $role,
            'role_label' => (string)$roleLabel,
            'max_uses' => $maxUses,
            'expires_at' => $expiresAt,
            'url' => absolute_app_url('?' . http_build_query(['r'=>'join','id'=>(string)$publicId,'key'=>$secret])),
        ];
    }

    public static function activeList(): array
    {
        $pdo = Database::connection();
        self::archiveExpired($pdo);
        return $pdo->query("SELECT i.public_id,i.role_name,r.label role_label,i.max_uses,i.used_count,i.expires_at,i.created_at,u.name creator_name FROM registration_invites i JOIN roles r ON r.name=i.role_name JOIN users u ON u.id=i.created_by ORDER BY i.id DESC")->fetchAll();
    }

    public static function revoke(int $publicId, int $actorId): bool
    {
        if (!self::validPublicId($publicId) || $actorId < 1) return false;
        $pdo = Database::connection();
        self::beginWrite($pdo);
        try {
            $invite = self::lockedInvite($pdo, $publicId);
            if (!$invite) {
                self::commit($pdo);
                return false;
            }
            self::archiveAndDelete($pdo, $invite, 'revoked');
            self::commit($pdo);
            return true;
        } catch (\Throwable $e) {
            self::rollback($pdo);
            throw $e;
        }
    }

    /** @return array{state:string,invite:?array} */
    public static function inspect(int $publicId, string $secret): array
    {
        if (!self::validPublicId($publicId) || !preg_match('/^[a-f0-9]{48}$/', $secret)) {
            return ['state'=>'not_found','invite'=>null];
        }
        $pdo = Database::connection();
        self::archiveExpired($pdo);
        $tokenHash = hash('sha256', $secret);
        $stmt = $pdo->prepare('SELECT i.*,r.label role_label FROM registration_invites i JOIN roles r ON r.name=i.role_name WHERE i.public_id=? LIMIT 1');
        $stmt->execute([$publicId]);
        $invite = $stmt->fetch();
        if ($invite) {
            if (!hash_equals((string)$invite['token_hash'], $tokenHash)) return ['state'=>'not_found','invite'=>null];
            return ['state'=>'active','invite'=>$invite];
        }

        $stmt = $pdo->prepare('SELECT h.*,r.label role_label FROM registration_invite_history h LEFT JOIN roles r ON r.name=h.role_name WHERE h.public_id=? LIMIT 1');
        $stmt->execute([$publicId]);
        $history = $stmt->fetch();
        if (!$history || !hash_equals((string)$history['token_hash'], $tokenHash)) return ['state'=>'not_found','invite'=>null];
        return ['state'=>(string)$history['final_state'],'invite'=>$history];
    }

    /** @return array{0:bool,1:string,2:?int} */
    public static function register(int $publicId, string $secret, string $name, string $email, string $password): array
    {
        if (!self::validPublicId($publicId) || !preg_match('/^[a-f0-9]{48}$/', $secret)) return [false, 'This registration link is invalid.', null];
        $name = trim($name);
        $email = mb_strtolower(trim($email));
        if (mb_strlen($name) < 2 || mb_strlen($name) > 120) return [false, 'Enter a name between 2 and 120 characters.', null];
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190) return [false, 'Enter a valid email address.', null];
        if (strlen($password) < 12) return [false, 'Password must contain at least 12 characters.', null];

        $pdo = Database::connection();
        $tokenHash = hash('sha256', $secret);
        self::beginWrite($pdo);
        try {
            $invite = self::lockedInvite($pdo, $publicId);
            if (!$invite || !hash_equals((string)$invite['token_hash'], $tokenHash)) {
                self::rollback($pdo);
                return [false, 'This registration link is invalid or no longer available.', null];
            }
            if (strtotime((string)$invite['expires_at'] . ' UTC') <= time()) {
                self::archiveAndDelete($pdo, $invite, 'expired');
                self::commit($pdo);
                return [false, 'This registration link has expired.', null];
            }
            if ((int)$invite['used_count'] >= (int)$invite['max_uses']) {
                self::archiveAndDelete($pdo, $invite, 'used');
                self::commit($pdo);
                return [false, 'This registration link has already reached its account limit.', null];
            }

            $roleStmt = $pdo->prepare("SELECT id FROM roles WHERE name=? AND name<>'super_admin' LIMIT 1");
            $roleStmt->execute([(string)$invite['role_name']]);
            $roleId = (int)$roleStmt->fetchColumn();
            if ($roleId < 1) throw new \RuntimeException('Invite role is unavailable.');

            $exists = $pdo->prepare('SELECT COUNT(*) FROM users WHERE email=?');
            $exists->execute([$email]);
            if ((int)$exists->fetchColumn() > 0) {
                self::rollback($pdo);
                return [false, 'An account with this email address already exists.', null];
            }

            $now = gmdate('Y-m-d H:i:s');
            $stmt = $pdo->prepare('INSERT INTO users (name,email,password_hash,status,created_at,updated_at) VALUES (?,?,?,?,?,?)');
            $stmt->execute([$name,$email,password_hash($password,PASSWORD_DEFAULT),'active',$now,$now]);
            $userId = (int)$pdo->lastInsertId();
            $pdo->prepare('INSERT INTO user_roles (user_id,role_id) VALUES (?,?)')->execute([$userId,$roleId]);

            $newUsed = (int)$invite['used_count'] + 1;
            $invite['used_count'] = $newUsed;
            $invite['last_used_at'] = $now;
            if ($newUsed >= (int)$invite['max_uses']) {
                self::archiveAndDelete($pdo, $invite, 'used');
            } else {
                $pdo->prepare('UPDATE registration_invites SET used_count=?,last_used_at=? WHERE public_id=?')->execute([$newUsed,$now,$publicId]);
            }
            self::commit($pdo);
            return [true, 'Account created.', $userId];
        } catch (\PDOException $e) {
            self::rollback($pdo);
            $message = strtolower($e->getMessage());
            if (str_contains($message, 'unique') || str_contains($message, 'duplicate')) return [false, 'An account with this email address already exists.', null];
            throw $e;
        } catch (\Throwable $e) {
            self::rollback($pdo);
            throw $e;
        }
    }

    public static function archiveExpired(?PDO $pdo = null): void
    {
        $pdo ??= Database::connection();
        $rows = $pdo->prepare('SELECT * FROM registration_invites WHERE expires_at<=?');
        $rows->execute([gmdate('Y-m-d H:i:s')]);
        foreach ($rows->fetchAll() as $invite) {
            try {
                $pdo->beginTransaction();
                $locked = self::lockedInvite($pdo, (int)$invite['public_id']);
                if ($locked && strtotime((string)$locked['expires_at'] . ' UTC') <= time()) self::archiveAndDelete($pdo, $locked, 'expired');
                if ($pdo->inTransaction()) $pdo->commit();
            } catch (\Throwable) {
                if ($pdo->inTransaction()) $pdo->rollBack();
            }
        }
    }

    public static function validPublicId(int $publicId): bool
    {
        return $publicId >= self::MIN_PUBLIC_ID && $publicId <= self::MAX_PUBLIC_ID;
    }

    private static function generatePublicId(PDO $pdo): int
    {
        for ($attempt = 0; $attempt < 50; $attempt++) {
            $id = random_int(self::MIN_PUBLIC_ID, self::MAX_PUBLIC_ID);
            $stmt = $pdo->prepare('SELECT (SELECT COUNT(*) FROM registration_invites WHERE public_id=?) + (SELECT COUNT(*) FROM registration_invite_history WHERE public_id=?)');
            $stmt->execute([$id,$id]);
            if ((int)$stmt->fetchColumn() === 0) return $id;
        }
        throw new \RuntimeException('Unable to allocate a registration link ID.');
    }

    private static function archiveAndDelete(PDO $pdo, array $invite, string $state): void
    {
        if (!in_array($state, ['used','expired','revoked'], true)) throw new \InvalidArgumentException('Invalid invite state.');
        $existing = $pdo->prepare('SELECT COUNT(*) FROM registration_invite_history WHERE public_id=?');
        $existing->execute([(int)$invite['public_id']]);
        if ((int)$existing->fetchColumn() === 0) {
            $pdo->prepare('INSERT INTO registration_invite_history (public_id,token_hash,role_name,max_uses,used_count,final_state,expires_at,created_by,created_at,archived_at) VALUES (?,?,?,?,?,?,?,?,?,?)')
                ->execute([(int)$invite['public_id'],(string)$invite['token_hash'],(string)$invite['role_name'],(int)$invite['max_uses'],(int)$invite['used_count'],$state,(string)$invite['expires_at'],isset($invite['created_by'])?(int)$invite['created_by']:null,(string)$invite['created_at'],gmdate('Y-m-d H:i:s')]);
        }
        $pdo->prepare('DELETE FROM registration_invites WHERE public_id=?')->execute([(int)$invite['public_id']]);
    }

    private static function lockedInvite(PDO $pdo, int $publicId): ?array
    {
        $driver = (string)Config::get('database.driver', 'mysql');
        $sql = 'SELECT * FROM registration_invites WHERE public_id=? LIMIT 1' . ($driver === 'mysql' ? ' FOR UPDATE' : '');
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$publicId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    private static function beginWrite(PDO $pdo): void
    {
        $driver = (string)Config::get('database.driver', 'mysql');
        if ($driver === 'sqlite') $pdo->exec('BEGIN IMMEDIATE');
        else $pdo->beginTransaction();
    }

    private static function commit(PDO $pdo): void
    {
        if ((string)Config::get('database.driver', 'mysql') === 'sqlite') {
            try { $pdo->exec('COMMIT'); } catch (\Throwable) {}
            return;
        }
        if ($pdo->inTransaction()) $pdo->commit();
    }

    private static function rollback(PDO $pdo): void
    {
        if ((string)Config::get('database.driver', 'mysql') === 'sqlite') {
            try { $pdo->exec('ROLLBACK'); } catch (\Throwable) {}
            return;
        }
        if ($pdo->inTransaction()) $pdo->rollBack();
    }
}
