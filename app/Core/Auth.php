<?php
namespace OpenLMS\Core;

use OpenLMS\Services\MailService;
use PDO;

final class Auth
{
    private const AUTH_COOKIE = 'open_lms_auth';
    private const NORMAL_LIFETIME = 43200; // 12 hours
    private const REMEMBER_LIFETIME = 86400; // 1 day
    private static array $permissionCache = [];

    public static function user(): ?array
    {
        if (empty($_SESSION['user_id'])) self::restorePersistentSession();
        if (empty($_SESSION['user_id'])) return null;

        if (!empty($_SESSION['auth_expires_at']) && (int)$_SESSION['auth_expires_at'] <= time()) {
            self::invalidateCurrentSession('Your sign-in session expired. Please sign in again.');
            return null;
        }

        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT id,name,email,status,created_at,last_login_at,first_login_at,last_login_ip,last_login_user_agent FROM users WHERE id=? LIMIT 1');
        $stmt->execute([(int)$_SESSION['user_id']]);
        $user = $stmt->fetch();
        if (!$user) {
            self::invalidateCurrentSession(null);
            return null;
        }
        if ($user['status'] !== 'active') {
            $notice = $user['status'] === 'suspended'
                ? 'This account is suspended. Contact the LMS administration for assistance.'
                : 'This account is not active. Contact the LMS administration for assistance.';
            self::invalidateCurrentSession($notice);
            return null;
        }
        $user['roles'] = self::roles((int)$user['id']);
        self::touchCurrentSession();
        return $user;
    }

    public static function roles(int $userId): array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT r.name FROM roles r INNER JOIN user_roles ur ON ur.role_id=r.id WHERE ur.user_id=? ORDER BY r.id');
        $stmt->execute([$userId]);
        return array_column($stmt->fetchAll(), 'name');
    }

    public static function hasRole(string $role): bool
    {
        $user = self::user();
        return $user && in_array($role, $user['roles'], true);
    }

    public static function hasAnyRole(array $roles): bool
    {
        $user = self::user();
        return $user && (bool)array_intersect($roles, $user['roles']);
    }

    public static function permissions(?int $userId = null): array
    {
        $userId = $userId ?? (int)($_SESSION['user_id'] ?? 0);
        if ($userId < 1) return [];
        if (isset(self::$permissionCache[$userId])) return self::$permissionCache[$userId];
        $roles = self::roles($userId);
        if (in_array('super_admin', $roles, true)) {
            $all = Database::connection()->query('SELECT name FROM permissions')->fetchAll(PDO::FETCH_COLUMN);
            return self::$permissionCache[$userId] = array_values($all);
        }
        $stmt = Database::connection()->prepare('SELECT DISTINCT p.name FROM permissions p INNER JOIN role_permissions rp ON rp.permission_id=p.id INNER JOIN user_roles ur ON ur.role_id=rp.role_id WHERE ur.user_id=?');
        $stmt->execute([$userId]);
        return self::$permissionCache[$userId] = array_column($stmt->fetchAll(), 'name');
    }

    public static function can(string $permission): bool { return in_array($permission, self::permissions(), true); }

    public static function requirePermission(string $permission): void
    {
        self::requireAuth();
        if (!self::can($permission)) { render_status_page(403,'Access denied','You do not have permission to access this page.','Back to dashboard',app_url('?r=dashboard')); exit; }
    }

    public static function isPrivileged(): bool
    {
        $user = self::user();
        return $user && (bool)array_intersect(['super_admin','administrator'], $user['roles']);
    }

    /** @return array{0:bool,1:?string} */
    public static function attempt(string $email, string $password, string $ip, bool $remember = false): array
    {
        $pdo = Database::connection();
        $email = mb_strtolower(trim($email));
        $window = max(1, (int)Config::get('security.login_window_minutes', 15));
        $max = max(3, (int)Config::get('security.login_max_attempts', 5));
        $cutoff = gmdate('Y-m-d H:i:s', time() - ($window * 60));
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM login_attempts WHERE email=? AND ip_address=? AND successful=0 AND attempted_at>=?');
        $stmt->execute([$email,$ip,$cutoff]);
        if ((int)$stmt->fetchColumn() >= $max) return [false,'Too many failed login attempts. Try again later.'];

        $stmt = $pdo->prepare('SELECT * FROM users WHERE email=? LIMIT 1');
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        $passwordOk = $user && password_verify($password, (string)$user['password_hash']);
        $pdo->prepare('INSERT INTO login_attempts (email,ip_address,successful,attempted_at) VALUES (?,?,?,?)')
            ->execute([$email,$ip,$passwordOk?1:0,gmdate('Y-m-d H:i:s')]);
        if (!$passwordOk) return [false,'Invalid email or password.'];

        if ($user['status'] === 'suspended') {
            self::revokeAllSessions((int)$user['id']);
            return [false,'This account is suspended. Contact the LMS administration for assistance.'];
        }
        if ($user['status'] !== 'active') return [false,'This account is not active. Contact the LMS administration for assistance.'];

        if (password_needs_rehash((string)$user['password_hash'], PASSWORD_DEFAULT)) {
            $pdo->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([password_hash($password,PASSWORD_DEFAULT),(int)$user['id']]);
        }

        $mfa=$pdo->prepare('SELECT COUNT(*) FROM mfa_credentials WHERE user_id=? AND enabled_at IS NOT NULL');$mfa->execute([(int)$user['id']]);
        if ((int)$mfa->fetchColumn() > 0) {
            session_regenerate_id(true);
            $_SESSION['mfa_pending_user_id']=(int)$user['id'];
            $_SESSION['mfa_pending_started_at']=time();
            $_SESSION['mfa_pending_attempts']=0;
            $_SESSION['mfa_pending_locked_until']=0;
            $_SESSION['mfa_pending_remember']=$remember?1:0;
            unset($_SESSION['user_id']);Csrf::rotate();return [true,'mfa'];
        }
        self::finishLogin((int)$user['id'],$remember);
        return [true,null];
    }

    public static function finishLogin(int $userId, ?bool $remember = null): void
    {
        $remember ??= !empty($_SESSION['mfa_pending_remember']);
        $lifetime = $remember ? self::REMEMBER_LIFETIME : self::NORMAL_LIFETIME;
        $nowTs=time();$now=gmdate('Y-m-d H:i:s',$nowTs);$expires=gmdate('Y-m-d H:i:s',$nowTs+$lifetime);
        $ip=client_ip();$ua=mb_substr((string)($_SERVER['HTTP_USER_AGENT']??'Unknown'),0,500);$device=self::deviceLabel($ua);
        $token=bin2hex(random_bytes(32));$tokenHash=hash('sha256',$token);$pdo=Database::connection();

        session_regenerate_id(true);
        unset($_SESSION['mfa_pending_user_id'],$_SESSION['mfa_pending_started_at'],$_SESSION['mfa_pending_attempts'],$_SESSION['mfa_pending_locked_until'],$_SESSION['mfa_pending_remember']);
        $_SESSION['user_id']=$userId;$_SESSION['authenticated_at']=$nowTs;$_SESSION['auth_expires_at']=$nowTs+$lifetime;self::$permissionCache=[];Csrf::rotate();

        $pdo->prepare('INSERT INTO auth_sessions (user_id,token_hash,remember_me,ip_address,user_agent,device_label,created_at,last_seen_at,expires_at) VALUES (?,?,?,?,?,?,?,?,?)')
            ->execute([$userId,$tokenHash,$remember?1:0,$ip,$ua,$device,$now,$now,$expires]);
        $_SESSION['auth_session_id']=(int)$pdo->lastInsertId();
        self::setAuthCookie($token,$nowTs+$lifetime);
        $pdo->prepare('INSERT INTO user_login_history (user_id,ip_address,user_agent,device_label,remember_me,login_at,session_expires_at) VALUES (?,?,?,?,?,?,?)')
            ->execute([$userId,$ip,$ua,$device,$remember?1:0,$now,$expires]);
        $pdo->prepare('UPDATE users SET first_login_at=?,first_login_ip=?,first_login_user_agent=? WHERE id=? AND first_login_at IS NULL')
            ->execute([$now,$ip,$ua,$userId]);
        $pdo->prepare('UPDATE users SET last_login_at=?,last_login_ip=?,last_login_user_agent=?,updated_at=? WHERE id=?')
            ->execute([$now,$ip,$ua,$now,$userId]);
        self::audit('auth.login','User signed in',$userId);
        try{$st=$pdo->prepare('SELECT id,name,email FROM users WHERE id=?');$st->execute([$userId]);$user=$st->fetch();if($user)MailService::sendLoginAlert($user,['login_at'=>$now,'ip_address'=>$ip,'device_label'=>$device]);}catch(\Throwable $e){error_log('[OpenLMS login mail] '.$e->getMessage());}
    }

    public static function verifyCurrentPassword(string $password): bool
    {
        $userId=(int)($_SESSION['user_id']??0);if($userId<1)return false;$st=Database::connection()->prepare("SELECT password_hash FROM users WHERE id=? AND status='active'");$st->execute([$userId]);$hash=$st->fetchColumn();return is_string($hash)&&password_verify($password,$hash);
    }

    public static function mfaEnabled(?int $userId=null):bool
    {
        $userId??=(int)($_SESSION['user_id']??0);if($userId<1)return false;try{$st=Database::connection()->prepare('SELECT COUNT(*) FROM mfa_credentials WHERE user_id=? AND enabled_at IS NOT NULL');$st->execute([$userId]);return (int)$st->fetchColumn()>0;}catch(\Throwable){return false;}
    }

    public static function logout(): void
    {
        $uid=(int)($_SESSION['user_id']??0);if($uid){try{self::audit('auth.logout','User signed out',$uid);}catch(\Throwable){}}
        self::revokeCurrentSession();self::$permissionCache=[];$_SESSION=[];self::clearAuthCookie();if(ini_get('session.use_cookies')){$p=session_get_cookie_params();setcookie(session_name(),'',time()-42000,$p['path'],$p['domain'],$p['secure'],$p['httponly']);}session_destroy();
    }

    public static function revokeAllSessions(int $userId): void
    {
        if($userId<1)return;try{Database::connection()->prepare('UPDATE auth_sessions SET revoked_at=? WHERE user_id=? AND revoked_at IS NULL')->execute([gmdate('Y-m-d H:i:s'),$userId]);}catch(\Throwable){}
        if((int)($_SESSION['user_id']??0)===$userId)self::invalidateCurrentSession('This account is suspended. Contact the LMS administration for assistance.');
    }

    public static function requireAuth():void{if(!self::user()){header('Location: '.app_url('?r=login'));exit;}}
    public static function requireAdmin():void{self::requireAuth();if(!self::isPrivileged()){render_status_page(403,'Access denied','You do not have permission to access this page.','Back to dashboard',app_url('?r=dashboard'));exit;}}

    public static function audit(string $action,string $description,?int $actorUserId=null,?string $entityType=null,?int $entityId=null):void
    {
        $stmt=Database::connection()->prepare('INSERT INTO audit_logs (actor_user_id,action,description,entity_type,entity_id,ip_address,created_at) VALUES (?,?,?,?,?,?,?)');$stmt->execute([$actorUserId??($_SESSION['user_id']??null),$action,$description,$entityType,$entityId,client_ip(),gmdate('Y-m-d H:i:s')]);
    }

    private static function restorePersistentSession():void
    {
        $token=(string)($_COOKIE[self::AUTH_COOKIE]??'');if(!preg_match('/^[a-f0-9]{64}$/',$token))return;$hash=hash('sha256',$token);try{$st=Database::connection()->prepare('SELECT s.*,u.status FROM auth_sessions s JOIN users u ON u.id=s.user_id WHERE s.token_hash=? AND s.revoked_at IS NULL AND s.expires_at>? LIMIT 1');$st->execute([$hash,gmdate('Y-m-d H:i:s')]);$row=$st->fetch();if(!$row){self::clearAuthCookie();return;}if($row['status']!=='active'){Database::connection()->prepare('UPDATE auth_sessions SET revoked_at=? WHERE id=?')->execute([gmdate('Y-m-d H:i:s'),(int)$row['id']]);self::clearAuthCookie();return;}session_regenerate_id(true);$_SESSION['user_id']=(int)$row['user_id'];$_SESSION['auth_session_id']=(int)$row['id'];$_SESSION['authenticated_at']=time();$_SESSION['auth_expires_at']=strtotime((string)$row['expires_at'].' UTC');self::$permissionCache=[];Csrf::rotate();}catch(\Throwable $e){error_log('[OpenLMS session restore] '.$e->getMessage());self::clearAuthCookie();}
    }

    private static function touchCurrentSession():void
    {
        $id=(int)($_SESSION['auth_session_id']??0);if($id<1)return;$last=(int)($_SESSION['_auth_touch']??0);if(time()-$last<300)return;$_SESSION['_auth_touch']=time();try{Database::connection()->prepare('UPDATE auth_sessions SET last_seen_at=? WHERE id=? AND revoked_at IS NULL')->execute([gmdate('Y-m-d H:i:s'),$id]);}catch(\Throwable){}
    }

    private static function revokeCurrentSession():void
    {
        $id=(int)($_SESSION['auth_session_id']??0);if($id>0){try{Database::connection()->prepare('UPDATE auth_sessions SET revoked_at=? WHERE id=? AND revoked_at IS NULL')->execute([gmdate('Y-m-d H:i:s'),$id]);}catch(\Throwable){}}
        elseif(!empty($_COOKIE[self::AUTH_COOKIE])){try{Database::connection()->prepare('UPDATE auth_sessions SET revoked_at=? WHERE token_hash=? AND revoked_at IS NULL')->execute([gmdate('Y-m-d H:i:s'),hash('sha256',(string)$_COOKIE[self::AUTH_COOKIE])]);}catch(\Throwable){}}
    }

    private static function invalidateCurrentSession(?string $notice):void
    {
        self::revokeCurrentSession();self::clearAuthCookie();self::$permissionCache=[];unset($_SESSION['user_id'],$_SESSION['auth_session_id'],$_SESSION['authenticated_at'],$_SESSION['auth_expires_at'],$_SESSION['_auth_touch']);session_regenerate_id(true);if($notice!==null)flash_set('danger',$notice);
    }

    private static function setAuthCookie(string $token,int $expiresAt):void
    {
        setcookie(self::AUTH_COOKIE,$token,['expires'=>$expiresAt,'path'=>'/','secure'=>self::secureRequest(),'httponly'=>true,'samesite'=>'Lax']);
    }
    private static function clearAuthCookie():void{setcookie(self::AUTH_COOKIE,'',['expires'=>time()-3600,'path'=>'/','secure'=>self::secureRequest(),'httponly'=>true,'samesite'=>'Lax']);unset($_COOKIE[self::AUTH_COOKIE]);}
    private static function secureRequest():bool{return (!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off')||(($_SERVER['HTTP_X_FORWARDED_PROTO']??'')==='https');}

    public static function deviceLabel(string $ua):string
    {
        $os='Unknown OS';if(stripos($ua,'Android')!==false)$os='Android';elseif(stripos($ua,'iPhone')!==false||stripos($ua,'iPad')!==false)$os='iOS/iPadOS';elseif(stripos($ua,'Windows')!==false)$os='Windows';elseif(stripos($ua,'Mac OS X')!==false)$os='macOS';elseif(stripos($ua,'Linux')!==false)$os='Linux';
        $browser='Browser';if(stripos($ua,'Edg/')!==false)$browser='Edge';elseif(stripos($ua,'Chrome/')!==false)$browser='Chrome';elseif(stripos($ua,'Firefox/')!==false)$browser='Firefox';elseif(stripos($ua,'Safari/')!==false)$browser='Safari';$device=(stripos($ua,'Mobile')!==false||stripos($ua,'Android')!==false||stripos($ua,'iPhone')!==false)?'Mobile':'Desktop';return mb_substr($browser.' · '.$os.' · '.$device,0,160);
    }
}
