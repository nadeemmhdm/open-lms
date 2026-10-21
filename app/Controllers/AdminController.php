<?php
namespace OpenLMS\Controllers;

use OpenLMS\Core\Auth;
use OpenLMS\Core\Csrf;
use OpenLMS\Core\Database;

final class AdminController
{
    public static function users(): void
    {
        Auth::requireAdmin(); $pdo=Database::connection(); $message=$error=null;
        if ($_SERVER['REQUEST_METHOD']==='POST') {
            if (!Csrf::validate($_POST['_csrf'] ?? null)) $error='Invalid security token.';
            else {
                $action=$_POST['action'] ?? '';
                if ($action==='create') {
                    $name=trim((string)($_POST['name'] ?? '')); $email=mb_strtolower(trim((string)($_POST['email'] ?? ''))); $password=(string)($_POST['password'] ?? ''); $role=(string)($_POST['role'] ?? 'student');
                    if ($name==='' || !filter_var($email,FILTER_VALIDATE_EMAIL) || strlen($password)<10) $error='Use a valid name/email and a password of at least 10 characters.';
                    else try {
                        $pdo->beginTransaction(); $now=gmdate('Y-m-d H:i:s');
                        $pdo->prepare('INSERT INTO users (name,email,password_hash,status,created_at,updated_at) VALUES (?,?,?,?,?,?)')->execute([$name,$email,password_hash($password,PASSWORD_DEFAULT),'active',$now,$now]);
                        $uid=(int)$pdo->lastInsertId(); $stmt=$pdo->prepare('SELECT id FROM roles WHERE name = ? LIMIT 1'); $stmt->execute([$role]); $rid=(int)$stmt->fetchColumn(); if(!$rid) throw new \RuntimeException('Invalid role.');
                        $pdo->prepare('INSERT INTO user_roles (user_id,role_id) VALUES (?,?)')->execute([$uid,$rid]); $pdo->commit(); Auth::audit('users.create','Created user '.$email,null,'user',$uid); $message='User created.';
                    } catch(\Throwable $e) { if($pdo->inTransaction()) $pdo->rollBack(); $error=(str_contains($e->getMessage(),'UNIQUE')||str_contains($e->getMessage(),'Duplicate'))?'That email already exists.':'Unable to create user.'; }
                } elseif($action==='status') {
                    $uid=(int)($_POST['user_id'] ?? 0); $status=($_POST['status'] ?? '')==='suspended'?'suspended':'active'; $targetRoles=Auth::roles($uid);
                    if(in_array('super_admin',$targetRoles,true)) $error='Super Admin suspension is blocked from this screen.';
                    else { $pdo->prepare('UPDATE users SET status = ?, updated_at = ? WHERE id = ?')->execute([$status,gmdate('Y-m-d H:i:s'),$uid]); Auth::audit('users.status','Changed account status to '.$status,null,'user',$uid); $message='Account status updated.'; }
                }
            }
        }
        $groupConcat=\OpenLMS\Core\Config::get('database.driver')==='sqlite' ? "GROUP_CONCAT(r.name, ', ')" : "GROUP_CONCAT(r.name SEPARATOR ', ')";
        $users=$pdo->query("SELECT u.id,u.name,u.email,u.status,u.created_at,{$groupConcat} AS roles FROM users u LEFT JOIN user_roles ur ON ur.user_id=u.id LEFT JOIN roles r ON r.id=ur.role_id GROUP BY u.id ORDER BY u.id DESC")->fetchAll();
        $roles=$pdo->query("SELECT name,label FROM roles WHERE name <> 'super_admin' ORDER BY id")->fetchAll();
        ob_start(); ?>
        <div class="page-head"><div><h1>User management</h1><p class="muted">Create accounts and manage access status.</p></div></div><?php if($message): ?><div class="alert alert-success"><?= e($message) ?></div><?php endif; ?><?php if($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
        <div class="grid-2 admin-users-grid"><section class="card"><h2>Create user</h2><form method="post"><input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>"><input type="hidden" name="action" value="create"><label>Name<input name="name" required></label><label>Email<input type="email" name="email" required></label><label>Temporary password<input type="password" name="password" minlength="10" required></label><label>Role<select name="role"><?php foreach($roles as $role): ?><option value="<?= e($role['name']) ?>"><?= e($role['label']) ?></option><?php endforeach; ?></select></label><button class="btn btn-primary">Create user</button></form></section>
        <section class="card table-card"><h2>Accounts</h2><div class="table-scroll"><table><thead><tr><th>User</th><th>Role</th><th>Status</th><th>Action</th></tr></thead><tbody><?php foreach($users as $u): ?><tr><td><strong><?= e($u['name']) ?></strong><small><?= e($u['email']) ?></small></td><td><?= e($u['roles'] ?: '—') ?></td><td><span class="badge <?= $u['status']==='active'?'badge-ok':'badge-warn' ?>"><?= e($u['status']) ?></span></td><td><?php if(!str_contains((string)$u['roles'],'super_admin')): ?><form method="post" class="inline-form"><input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>"><input type="hidden" name="action" value="status"><input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>"><input type="hidden" name="status" value="<?= $u['status']==='active'?'suspended':'active' ?>"><button class="btn btn-small"><?= $u['status']==='active'?'Suspend':'Activate' ?></button></form><?php else: ?>Protected<?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div></section></div>
        <?php render_page('Users',ob_get_clean());
    }

    public static function features(): void
    {
        Auth::requireAdmin(); $pdo=Database::connection(); $message=null;
        if($_SERVER['REQUEST_METHOD']==='POST' && Csrf::validate($_POST['_csrf'] ?? null)) { $feature=(string)($_POST['feature'] ?? ''); $enabled=isset($_POST['enabled'])?1:0; $pdo->prepare('UPDATE feature_settings SET enabled = ?, updated_at = ? WHERE feature_key = ?')->execute([$enabled,gmdate('Y-m-d H:i:s'),$feature]); Auth::audit('features.update','Feature '.$feature.' set to '.($enabled?'enabled':'disabled')); $message='Feature setting updated.'; }
        $features=$pdo->query('SELECT feature_key, enabled, updated_at FROM feature_settings ORDER BY feature_key')->fetchAll();
        ob_start(); ?><div class="page-head"><div><h1>Modules</h1><p class="muted">Enable or disable optional LMS capabilities.</p></div></div><?php if($message): ?><div class="alert alert-success"><?= e($message) ?></div><?php endif; ?><div class="feature-grid"><?php foreach($features as $f): ?><form class="card feature-card" method="post"><input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>"><input type="hidden" name="feature" value="<?= e($f['feature_key']) ?>"><div><h3><?= e(ucwords(str_replace('_',' ',$f['feature_key']))) ?></h3><small>Updated <?= e($f['updated_at']) ?> UTC</small></div><label class="switch"><input type="checkbox" name="enabled" <?= $f['enabled']?'checked':'' ?> onchange="this.form.submit()"><span></span></label></form><?php endforeach; ?></div><?php render_page('Modules',ob_get_clean());
    }

    public static function audit(): void
    {
        Auth::requireAdmin(); $rows=Database::connection()->query('SELECT a.*, u.email actor_email FROM audit_logs a LEFT JOIN users u ON u.id=a.actor_user_id ORDER BY a.id DESC LIMIT 200')->fetchAll();
        ob_start(); ?><div class="page-head"><div><h1>Audit log</h1><p class="muted">Recent privileged and authentication activity.</p></div></div><section class="card table-card"><div class="table-scroll"><table><thead><tr><th>Time</th><th>Actor</th><th>Action</th><th>Description</th><th>IP</th></tr></thead><tbody><?php foreach($rows as $r): ?><tr><td><?= e($r['created_at']) ?></td><td><?= e($r['actor_email'] ?: 'system') ?></td><td><code><?= e($r['action']) ?></code></td><td><?= e($r['description']) ?></td><td><?= e($r['ip_address'] ?: '—') ?></td></tr><?php endforeach; ?></tbody></table></div></section><?php render_page('Audit log',ob_get_clean());
    }
}
