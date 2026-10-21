<?php
namespace OpenLMS\Controllers;

use OpenLMS\Core\Auth;
use OpenLMS\Core\Csrf;
use OpenLMS\Core\Database;
use OpenLMS\Services\TotpService;

trait AdminRolesTrait
{
    public static function roles(): void
    {
        Auth::requirePermission('roles.manage');
        if (!Auth::hasAnyRole(['super_admin'])) {
            http_response_code(403);
            render_page('Access denied', '<div class="card"><h2>403</h2><p>Only a Super Admin can manage roles and permission mappings.</p></div>');
            return;
        }

        $pdo = Database::connection();
        $message = $error = null;
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!Csrf::validate($_POST['_csrf'] ?? null)) {
                $error = 'Invalid security token.';
            } else {
                try {
                    $action = (string)($_POST['action'] ?? '');
                    if ($action === 'save_mfa_policy') {
                        $valid=$pdo->query('SELECT name FROM roles')->fetchAll(\PDO::FETCH_COLUMN);
                        $chosen=array_values(array_intersect($valid,array_map('strval',(array)($_POST['mfa_roles']??[]))));
                        if(!in_array('super_admin',$chosen,true))$chosen[]='super_admin';
                        $json=json_encode(array_values(array_unique($chosen)));
                        $st=$pdo->prepare("SELECT COUNT(*) FROM app_settings WHERE setting_key='mfa_required_roles'");$st->execute();
                        if((int)$st->fetchColumn())$pdo->prepare("UPDATE app_settings SET setting_value=?,is_public=0,updated_at=? WHERE setting_key='mfa_required_roles'")->execute([$json,gmdate('Y-m-d H:i:s')]);
                        else $pdo->prepare('INSERT INTO app_settings (setting_key,setting_value,is_public,updated_at) VALUES (?,?,0,?)')->execute(['mfa_required_roles',$json,gmdate('Y-m-d H:i:s')]);
                        Auth::audit('security.mfa_policy','Updated mandatory MFA role policy');
                        $message='MFA policy updated. Affected users will be required to enroll at their next request.';
                    } elseif ($action === 'create_role') {
                        $name = strtolower(trim((string)($_POST['name'] ?? '')));
                        $name = preg_replace('/[^a-z0-9_]+/', '_', $name) ?: '';
                        $label = trim((string)($_POST['label'] ?? ''));
                        if ($name === '' || $label === '' || strlen($name) > 80 || mb_strlen($label) > 120) throw new \RuntimeException('Enter a valid role name and label.');
                        if (in_array($name, ['super_admin','administrator','teacher','student','hod','examiner','parent'], true)) throw new \RuntimeException('That role name is reserved.');
                        $pdo->prepare('INSERT INTO roles (name,label,is_system) VALUES (?,?,0)')->execute([$name,$label]);
                        Auth::audit('roles.create','Created custom role '.$name,null,'role',(int)$pdo->lastInsertId());
                        $message = 'Custom role created.';
                    } elseif ($action === 'save_permissions') {
                        $roleId = (int)($_POST['role_id'] ?? 0);
                        $st=$pdo->prepare('SELECT name FROM roles WHERE id=?');$st->execute([$roleId]);$roleName=(string)$st->fetchColumn();
                        if ($roleId < 1 || $roleName === '' || $roleName === 'super_admin') throw new \RuntimeException('That role permission set cannot be edited here.');
                        $allowed = array_map('intval',$pdo->query('SELECT id FROM permissions')->fetchAll(\PDO::FETCH_COLUMN));
                        $chosen = array_values(array_intersect($allowed,array_map('intval',(array)($_POST['permission_ids']??[]))));
                        $pdo->beginTransaction();
                        $pdo->prepare('DELETE FROM role_permissions WHERE role_id=?')->execute([$roleId]);
                        $ins=$pdo->prepare('INSERT INTO role_permissions (role_id,permission_id) VALUES (?,?)');foreach($chosen as $pid)$ins->execute([$roleId,$pid]);
                        $pdo->commit();
                        Auth::audit('roles.permissions','Updated permissions for '.$roleName,null,'role',$roleId);
                        $message = 'Role permissions updated.';
                    } elseif ($action === 'assign_role') {
                        $uid=(int)($_POST['user_id']??0);$rid=(int)($_POST['role_id']??0);
                        $st=$pdo->prepare('SELECT name FROM roles WHERE id=?');$st->execute([$rid]);$roleName=(string)$st->fetchColumn();
                        if($uid<1||$rid<1||$roleName==='')throw new \RuntimeException('Choose a valid user and role.');
                        if($roleName==='super_admin'&&!Auth::verifyCurrentPassword((string)($_POST['password']??'')))throw new \RuntimeException('Current password is required to appoint a Super Admin.');
                        $st=$pdo->prepare('SELECT COUNT(*) FROM user_roles WHERE user_id=? AND role_id=?');$st->execute([$uid,$rid]);
                        if(!(int)$st->fetchColumn())$pdo->prepare('INSERT INTO user_roles (user_id,role_id) VALUES (?,?)')->execute([$uid,$rid]);
                        Auth::audit('roles.assign','Assigned role '.$roleName,null,'user',$uid);$message='Role assigned.';
                    } elseif ($action === 'remove_role') {
                        $uid=(int)($_POST['user_id']??0);$rid=(int)($_POST['role_id']??0);
                        $st=$pdo->prepare('SELECT name FROM roles WHERE id=?');$st->execute([$rid]);$roleName=(string)$st->fetchColumn();
                        if($uid<1||$rid<1||$roleName==='')throw new \RuntimeException('Invalid role assignment.');
                        if($roleName==='super_admin'){
                            if($uid===(int)(Auth::user()['id']??0))throw new \RuntimeException('You cannot remove your own Super Admin role from this screen.');
                            if(!Auth::verifyCurrentPassword((string)($_POST['password']??'')))throw new \RuntimeException('Current password is required to remove a Super Admin role.');
                            $st=$pdo->query("SELECT COUNT(DISTINCT ur.user_id) FROM user_roles ur JOIN roles r ON r.id=ur.role_id JOIN users u ON u.id=ur.user_id WHERE r.name='super_admin' AND u.status='active'");
                            if((int)$st->fetchColumn()<=1)throw new \RuntimeException('The final active Super Admin role cannot be removed.');
                        }
                        $pdo->prepare('DELETE FROM user_roles WHERE user_id=? AND role_id=?')->execute([$uid,$rid]);
                        Auth::audit('roles.remove','Removed role '.$roleName,null,'user',$uid);$message='Role removed.';
                    }
                } catch (\Throwable $e) {
                    if($pdo->inTransaction())$pdo->rollBack();
                    $msg=$e->getMessage();
                    $error=$e instanceof \RuntimeException?$msg:(str_contains($msg,'UNIQUE')||str_contains($msg,'Duplicate')?'That role already exists.':'Unable to update roles.');
                }
            }
        }

        $roles=$pdo->query('SELECT * FROM roles ORDER BY is_system DESC,id')->fetchAll();
        $permissions=$pdo->query('SELECT * FROM permissions ORDER BY name')->fetchAll();
        $map=[];foreach($pdo->query('SELECT role_id,permission_id FROM role_permissions')->fetchAll() as $rp)$map[(int)$rp['role_id']][]=(int)$rp['permission_id'];
        $users=$pdo->query('SELECT id,name,email,status FROM users ORDER BY name')->fetchAll();
        $assignments=$pdo->query('SELECT ur.user_id,ur.role_id,u.name user_name,u.email,r.name role_name,r.label role_label FROM user_roles ur JOIN users u ON u.id=ur.user_id JOIN roles r ON r.id=ur.role_id ORDER BY u.name,r.label')->fetchAll();

        ob_start(); ?>
        <div class="page-head"><div><h1>Roles & permissions</h1><p class="muted">Granular server-side permissions. Super Admin retains implicit access to all permissions and is protected from ordinary-admin escalation.</p></div></div>
        <?php if($message):?><div class="alert alert-success"><?=e($message)?></div><?php endif;?><?php if($error):?><div class="alert alert-danger"><?=e($error)?></div><?php endif;?>
        <section class="card"><h2>Mandatory MFA policy</h2><p class="hint">Super Admin is always required. Select any additional roles that must enroll TOTP before using the LMS.</p><form method="post"><input type="hidden" name="_csrf" value="<?=e(Csrf::token())?>"><input type="hidden" name="action" value="save_mfa_policy"><div class="permission-list"><?php $mfaPolicy=mfa_required_roles(); foreach($roles as $role):?><label class="check-row"><input type="checkbox" name="mfa_roles[]" value="<?=e($role['name'])?>" <?=in_array($role['name'],$mfaPolicy,true)?'checked':''?> <?= $role['name']==='super_admin'?'disabled':'' ?>> <?=e($role['label'])?></label><?php if($role['name']==='super_admin'):?><input type="hidden" name="mfa_roles[]" value="super_admin"><?php endif;?><?php endforeach;?></div><button class="btn">Save MFA policy</button></form></section>
        <div class="grid-2"><section class="card"><h2>Create custom role</h2><form method="post"><input type="hidden" name="_csrf" value="<?=e(Csrf::token())?>"><input type="hidden" name="action" value="create_role"><label>Role key<input name="name" required maxlength="80" placeholder="lab_assistant"></label><label>Display label<input name="label" required maxlength="120" placeholder="Lab Assistant"></label><button class="btn">Create role</button></form></section>
        <section class="card"><h2>Assign role</h2><form method="post"><input type="hidden" name="_csrf" value="<?=e(Csrf::token())?>"><input type="hidden" name="action" value="assign_role"><label>User<select name="user_id" required><option value="">Select</option><?php foreach($users as $usr):?><option value="<?=(int)$usr['id']?>"><?=e($usr['name'].' · '.$usr['email'])?></option><?php endforeach;?></select></label><label>Role<select name="role_id" required><option value="">Select</option><?php foreach($roles as $role):?><option value="<?=(int)$role['id']?>"><?=e($role['label'])?></option><?php endforeach;?></select></label><label>Current password <small>Required only when assigning Super Admin.</small><input type="password" name="password"></label><button class="btn btn-primary">Assign role</button></form></section></div>
        <div class="card-grid"><?php foreach($roles as $role):?><section class="card"><div class="page-head"><div><h2><?=e($role['label'])?></h2><code><?=e($role['name'])?></code></div><span class="badge"><?= $role['is_system']?'System':'Custom' ?></span></div><?php if($role['name']==='super_admin'):?><p class="hint">Implicitly has all permissions. This mapping cannot be reduced.</p><?php else:?><form method="post"><input type="hidden" name="_csrf" value="<?=e(Csrf::token())?>"><input type="hidden" name="action" value="save_permissions"><input type="hidden" name="role_id" value="<?=(int)$role['id']?>"><div class="permission-list"><?php foreach($permissions as $perm):?><label class="check-row"><input type="checkbox" name="permission_ids[]" value="<?=(int)$perm['id']?>" <?=in_array((int)$perm['id'],$map[(int)$role['id']]??[],true)?'checked':''?>> <?=e($perm['name'])?></label><?php endforeach;?></div><button class="btn">Save permissions</button></form><?php endif;?></section><?php endforeach;?></div>
        <section class="card table-card"><h2>Current role assignments</h2><div class="table-scroll"><table><thead><tr><th>User</th><th>Role</th><th>Remove</th></tr></thead><tbody><?php foreach($assignments as $a):?><tr><td><strong><?=e($a['user_name'])?></strong><small><?=e($a['email'])?></small></td><td><?=e($a['role_label'])?></td><td><?php if(!($a['role_name']==='super_admin'&&(int)$a['user_id']===(int)(Auth::user()['id']??0))):?><form method="post" class="inline-form"><input type="hidden" name="_csrf" value="<?=e(Csrf::token())?>"><input type="hidden" name="action" value="remove_role"><input type="hidden" name="user_id" value="<?=(int)$a['user_id']?>"><input type="hidden" name="role_id" value="<?=(int)$a['role_id']?>"><?php if($a['role_name']==='super_admin'):?><input type="password" name="password" placeholder="Current password" required><?php endif;?><button class="btn btn-small">Remove</button></form><?php else:?><span class="muted">Current Super Admin</span><?php endif;?></td></tr><?php endforeach;?></tbody></table></div></section>
        <?php render_page('Roles & permissions',ob_get_clean());
    }
}
