<?php
namespace OpenLMS\Controllers;

use OpenLMS\Core\Auth;
use OpenLMS\Core\Csrf;
use OpenLMS\Core\Database;
use OpenLMS\Policies\CoursePolicy;
use OpenLMS\Services\BackupService;

trait SystemAdminToolsTrait
{
    public static function backup(): void
    {
        require_feature('backup_restore');
        Auth::requireAdmin();$u=Auth::user();$error=null;$message=null;if($_SERVER['REQUEST_METHOD']==='POST'){if(!Csrf::validate($_POST['_csrf']??null))$error='Security token expired.';elseif(!Auth::verifyCurrentPassword((string)($_POST['password']??'')))$error='Current password is required for backup operations.';else try{$action=(string)($_POST['action']??'');if($action==='create'){[$name,$size]=BackupService::createEncrypted((int)$u['id']);Auth::audit('backup.created','Encrypted application backup created',(int)$u['id']);$message='Backup created: '.$name.' ('.round($size/1024,1).' KB)';}elseif($action==='restore'){BackupService::restoreEncrypted((string)($_POST['file_name']??''),(int)$u['id']);Auth::audit('backup.restored','Encrypted application backup restored',(int)$u['id']);$message='Backup restored. Sign in again if your session changes.';}}catch(\Throwable $e){$error=$e->getMessage();}}$files=BackupService::listBackups();ob_start();?><div class="page-head"><div><h1>Backup & restore</h1><p class="muted">Authenticated encrypted backups of database records plus referenced private lesson/project uploads; no shell access or mysqldump required.</p></div></div><?php if($message):?><div class="alert alert-success"><?=e($message)?></div><?php endif;?><?php if($error):?><div class="alert alert-danger"><?=e($error)?></div><?php endif;?><section class="card"><h2>Create backup</h2><form method="post"><input type="hidden" name="_csrf" value="<?=e(Csrf::token())?>"><input type="hidden" name="action" value="create"><label>Current password<input type="password" name="password" required></label><button class="btn btn-primary">Create encrypted backup</button></form></section><section class="card"><h2>Stored backups</h2><div class="compact-list"><?php foreach($files as $f):?><div><span><strong><?=e($f['name'])?></strong><small><?=round($f['size']/1024,1)?> KB · <?=e(format_app_datetime(gmdate('Y-m-d H:i:s',$f['modified'])))?></small></span><form method="post" class="inline-form" onsubmit="return confirm('Restore this backup? Current database content will be replaced.')"><input type="hidden" name="_csrf" value="<?=e(Csrf::token())?>"><input type="hidden" name="action" value="restore"><input type="hidden" name="file_name" value="<?=e($f['name'])?>"><input type="password" name="password" placeholder="Current password" required><button class="btn btn-small">Restore</button></form></div><?php endforeach;?></div></section><?php render_page('Backup',ob_get_clean());
    }
    public static function search(): void
    {
        require_feature('search');
        Auth::requireAuth();$u=Auth::user();$q=trim((string)($_GET['q']??''));$rows=[];if(mb_strlen($q)>=2){$pdo=Database::connection();$like='%'.$q.'%';$st=$pdo->prepare('SELECT c.id course_id,c.title course_title,c.description,c.teacher_id,c.status course_status,s.status subject_status FROM courses c JOIN subjects s ON s.id=c.subject_id WHERE c.title LIKE ? OR c.description LIKE ? ORDER BY c.title LIMIT 50');$st->execute([$like,$like]);foreach($st->fetchAll() as $r)if(CoursePolicy::canViewCourse($r+['id'=>$r['course_id'],'status'=>$r['course_status']],$u)||CoursePolicy::canManage($r+['id'=>$r['course_id']],$u))$rows[]=['type'=>'Course','title'=>$r['course_title'],'url'=>app_url('?r=course&id='.(int)$r['course_id'])];$st=$pdo->prepare('SELECT l.id,l.title,cs.course_id,c.teacher_id,c.status course_status,s.status subject_status FROM lessons l JOIN course_sections cs ON cs.id=l.course_section_id JOIN courses c ON c.id=cs.course_id JOIN subjects s ON s.id=c.subject_id WHERE l.title LIKE ? OR l.content LIKE ? ORDER BY l.id DESC LIMIT 80');$st->execute([$like,$like]);foreach($st->fetchAll() as $r)if(CoursePolicy::canViewCourse(['id'=>$r['course_id'],'teacher_id'=>$r['teacher_id'],'status'=>$r['course_status'],'subject_status'=>$r['subject_status']],$u)||CoursePolicy::canManage($r+['id'=>$r['course_id']],$u))$rows[]=['type'=>'Lesson','title'=>$r['title'],'url'=>app_url('?r=lesson&id='.(int)$r['id'])];}
        ob_start();?><div class="page-head"><div><h1>Search</h1><p class="muted">Search only resources you are authorized to access.</p></div></div><form method="get" class="card"><input type="hidden" name="r" value="search"><label>Search<input name="q" value="<?=e($q)?>" minlength="2" required autofocus></label><button class="btn btn-primary">Search</button></form><section class="card"><div class="compact-list"><?php foreach($rows as $r):?><a href="<?=e($r['url'])?>"><span><strong><?=e($r['title'])?></strong><small><?=e($r['type'])?></small></span></a><?php endforeach;?><?php if($q!==''&&!$rows):?><p class="muted">No accessible results.</p><?php endif;?></div></section><?php render_page('Search',ob_get_clean());
    }
    public static function fees(): void
    {
        require_feature('fee_management');
        Auth::requireAuth();
        $u=Auth::user();$pdo=Database::connection();$admin=Auth::isPrivileged();$error=$message=null;
        if($_SERVER['REQUEST_METHOD']==='POST' && $admin){
            if(!Csrf::validate($_POST['_csrf']??null)) $error='Security token expired.';
            else try{
                $action=(string)($_POST['action']??'');
                if($action==='invoice'){
                    $uid=(int)($_POST['user_id']??0);$amount=round((float)($_POST['amount']??0),2);
                    if($uid<1||$amount<=0||!in_array('student',Auth::roles($uid),true)) throw new \RuntimeException('Student and positive amount are required.');
                    $title=mb_substr(trim((string)($_POST['title']??'Fee')),0,200);if($title==='')throw new \RuntimeException('Invoice title is required.');
                    $due=trim((string)($_POST['due_on']??''));
                    if($due!=='' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/',$due) || !($dt=\DateTimeImmutable::createFromFormat('!Y-m-d',$due)) || $dt->format('Y-m-d')!==$due)) throw new \RuntimeException('Due date is invalid.');
                    $pdo->prepare("INSERT INTO fee_invoices (user_id,title,amount,due_on,status,created_at) VALUES (?,?,?,?, 'unpaid',?)")->execute([$uid,$title,$amount,$due?:null,gmdate('Y-m-d H:i:s')]);
                    notify_user($uid,'New fee invoice',$title.' · Amount '.$amount.($due?' · Due '.$due:''),'fee',app_url('?r=fees'));
                    $message='Invoice created.';
                }elseif($action==='payment'){
                    $iid=(int)($_POST['invoice_id']??0);$amount=round((float)($_POST['amount']??0),2);if($amount<=0)throw new \RuntimeException('Payment amount must be positive.');
                    $st=$pdo->prepare('SELECT id,user_id,title,amount,(SELECT COALESCE(SUM(amount),0) FROM fee_payments WHERE invoice_id=fee_invoices.id) paid FROM fee_invoices WHERE id=?');$st->execute([$iid]);$x=$st->fetch();if(!$x)throw new \RuntimeException('Invoice not found.');
                    $outstanding=max(0,round((float)$x['amount']-(float)$x['paid'],2));if($amount>$outstanding+0.00001)throw new \RuntimeException('Payment cannot exceed the outstanding amount.');
                    $ref=mb_substr(trim((string)($_POST['reference']??'')),0,190);
                    $pdo->prepare('INSERT INTO fee_payments (invoice_id,amount,reference,paid_at,recorded_by) VALUES (?,?,?,?,?)')->execute([$iid,$amount,$ref,gmdate('Y-m-d H:i:s'),(int)$u['id']]);
                    $newPaid=round((float)$x['paid']+$amount,2);$newStatus=$newPaid+0.00001>=(float)$x['amount']?'paid':'partial';$pdo->prepare('UPDATE fee_invoices SET status=? WHERE id=?')->execute([$newStatus,$iid]);
                    notify_user((int)$x['user_id'],'Fee payment recorded',$x['title'].' · Payment '.$amount.' · Status '.$newStatus,'fee',app_url('?r=fees'));
                    $message='Payment recorded.';
                }
            }catch(\Throwable $e){$error=$e instanceof \RuntimeException?$e->getMessage():'Unable to update fees.';}
        }
        if($admin){$rows=$pdo->query('SELECT fi.*,u.name student_name,(SELECT COALESCE(SUM(amount),0) FROM fee_payments fp WHERE fp.invoice_id=fi.id) paid FROM fee_invoices fi JOIN users u ON u.id=fi.user_id ORDER BY fi.id DESC')->fetchAll();$students=$pdo->query("SELECT DISTINCT u.id,u.name FROM users u JOIN user_roles ur ON ur.user_id=u.id JOIN roles r ON r.id=ur.role_id WHERE r.name='student' AND u.status='active' ORDER BY u.name")->fetchAll();}
        else{$st=$pdo->prepare('SELECT fi.*,(SELECT COALESCE(SUM(amount),0) FROM fee_payments fp WHERE fp.invoice_id=fi.id) paid FROM fee_invoices fi WHERE fi.user_id=? ORDER BY fi.id DESC');$st->execute([(int)$u['id']]);$rows=$st->fetchAll();$students=[];}

        ob_start();?><div class="page-head"><div><h1>Fee management</h1><p class="muted">Optional invoice and payment recording module; no payment gateway is required.</p></div></div><?php if($message):?><div class="alert alert-success"><?=e($message)?></div><?php endif;?><?php if($error):?><div class="alert alert-danger"><?=e($error)?></div><?php endif;?>
        <?php if($admin):?><section class="card"><h2>Create invoice</h2><form method="post"><input type="hidden" name="_csrf" value="<?=e(Csrf::token())?>"><input type="hidden" name="action" value="invoice"><label>Student<select name="user_id" required><?php foreach($students as $s):?><option value="<?=(int)$s['id']?>"><?=e($s['name'])?></option><?php endforeach;?></select></label><div class="form-row"><label>Title<input name="title" maxlength="200" required></label><label>Amount<input type="number" name="amount" min="0.01" step="0.01" required></label></div><label>Due date<input type="date" name="due_on"></label><button class="btn">Create invoice</button></form></section><?php endif;?>
        <section class="card table-card"><div class="table-scroll"><table><thead><tr><?php if($admin):?><th>Student</th><?php endif;?><th>Invoice</th><th>Amount</th><th>Paid</th><th>Status</th><?php if($admin):?><th>Record payment</th><?php endif;?></tr></thead><tbody><?php foreach($rows as $r):?><tr><?php if($admin):?><td><?=e($r['student_name'])?></td><?php endif;?><td><?=e($r['title'])?><small><?=e($r['due_on']??'')?></small></td><td><?=e((string)$r['amount'])?></td><td><?=e((string)$r['paid'])?></td><td><?=e($r['status'])?></td><?php if($admin):?><td><form method="post" class="inline-form"><input type="hidden" name="_csrf" value="<?=e(Csrf::token())?>"><input type="hidden" name="action" value="payment"><input type="hidden" name="invoice_id" value="<?=(int)$r['id']?>"><input type="number" name="amount" step="0.01" min="0.01" required><input name="reference" maxlength="190" placeholder="Reference"><button class="btn btn-small">Record</button></form></td><?php endif;?></tr><?php endforeach;?><?php if(!$rows):?><tr><td colspan="6" class="muted">No fee records.</td></tr><?php endif;?></tbody></table></div></section>
        <?php render_page('Fees',ob_get_clean());
    }
}
