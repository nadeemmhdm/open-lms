<?php
namespace OpenLMS\Controllers;

use OpenLMS\Core\Auth;
use OpenLMS\Core\Csrf;
use OpenLMS\Core\Database;

final class SupportController
{
    public static function index(): void
    {
        require_feature('support');
        Auth::requireAuth();
        $pdo=Database::connection();$u=Auth::user();$admin=Auth::isPrivileged();$error=$message=null;
        $ticketPublicId=(int)($_GET['ticket']??0);

        if($_SERVER['REQUEST_METHOD']==='POST'){
            if(!Csrf::validate($_POST['_csrf']??null))$error='Security token expired.';
            else try{
                $action=(string)($_POST['action']??'');
                if($action==='create'){
                    $subject=mb_substr(trim((string)($_POST['subject']??'')),0,180);$body=mb_substr(trim((string)($_POST['body']??'')),0,8000);$category=(string)($_POST['category']??'general');$priority=(string)($_POST['priority']??'normal');
                    if($subject===''||$body==='')throw new \RuntimeException('Subject and issue details are required.');
                    if(!in_array($category,['general','account','course','exam','payment','technical'],true))$category='general';
                    if(!in_array($priority,['low','normal','high'],true))$priority='normal';
                    $publicId=self::newPublicId($pdo);$now=gmdate('Y-m-d H:i:s');$pdo->beginTransaction();
                    try{$pdo->prepare("INSERT INTO support_tickets (public_id,user_id,subject,category,priority,status,created_at,updated_at) VALUES (?,?,?,?,?,'open',?,?)")->execute([$publicId,(int)$u['id'],$subject,$category,$priority,$now,$now]);$id=(int)$pdo->lastInsertId();$pdo->prepare('INSERT INTO support_replies (ticket_id,sender_user_id,body,created_at) VALUES (?,?,?,?)')->execute([$id,(int)$u['id'],$body,$now]);$pdo->commit();}
                    catch(\Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
                    self::notifyAdmins('New support ticket #'.$publicId,$subject,app_url('?r=support&ticket='.$publicId));Auth::audit('support.create','Created support ticket '.$publicId,(int)$u['id'],'support_ticket',$id);header('Location: '.app_url('?r=support&ticket='.$publicId));exit;
                }
                if($action==='reply'){
                    $publicId=(int)($_POST['ticket_public_id']??0);$ticket=self::ticketByPublicId($pdo,$publicId);if(!$ticket||(!$admin&&(int)$ticket['user_id']!==(int)$u['id']))throw new \RuntimeException('Support ticket not found.');
                    if($ticket['status']==='resolved')throw new \RuntimeException('This ticket is resolved and no longer accepts replies.');
                    $body=mb_substr(trim((string)($_POST['body']??'')),0,8000);if($body==='')throw new \RuntimeException('Reply cannot be empty.');$now=gmdate('Y-m-d H:i:s');$pdo->prepare('INSERT INTO support_replies (ticket_id,sender_user_id,body,created_at) VALUES (?,?,?,?)')->execute([(int)$ticket['id'],(int)$u['id'],$body,$now]);$pdo->prepare('UPDATE support_tickets SET updated_at=? WHERE id=?')->execute([$now,(int)$ticket['id']]);
                    if($admin)notify_user((int)$ticket['user_id'],'Support reply received','Ticket #'.$publicId.' has a new administrator reply.','support',app_url('?r=support&ticket='.$publicId));else self::notifyAdmins('Support ticket updated #'.$publicId,(string)$ticket['subject'],app_url('?r=support&ticket='.$publicId));Auth::audit('support.reply','Replied to support ticket '.$publicId,(int)$u['id'],'support_ticket',(int)$ticket['id']);$message='Reply sent.';$ticketPublicId=$publicId;
                }
                if($action==='resolve'){
                    if(!$admin)throw new \RuntimeException('Administrator access required.');$publicId=(int)($_POST['ticket_public_id']??0);$ticket=self::ticketByPublicId($pdo,$publicId);if(!$ticket)throw new \RuntimeException('Support ticket not found.');if($ticket['status']==='resolved')throw new \RuntimeException('Ticket is already resolved.');$now=gmdate('Y-m-d H:i:s');$pdo->prepare("UPDATE support_tickets SET status='resolved',resolved_at=?,resolved_by=?,updated_at=? WHERE id=?")->execute([$now,(int)$u['id'],$now,(int)$ticket['id']]);notify_user((int)$ticket['user_id'],'Support ticket resolved','Ticket #'.$publicId.' was marked resolved. Replies are now closed.','support',app_url('?r=support&ticket='.$publicId));Auth::audit('support.resolve','Resolved support ticket '.$publicId,(int)$u['id'],'support_ticket',(int)$ticket['id']);$message='Ticket resolved. Replies are now locked.';$ticketPublicId=$publicId;
                }
            }catch(\Throwable $e){$error=$e instanceof \RuntimeException?$e->getMessage():'Unable to update support ticket.';}
        }

        $selected=$ticketPublicId?self::ticketByPublicId($pdo,$ticketPublicId):null;if($selected&&!$admin&&(int)$selected['user_id']!==(int)$u['id'])$selected=null;
        $replies=[];if($selected){$st=$pdo->prepare('SELECT r.*,u.name sender_name,u.email sender_email FROM support_replies r JOIN users u ON u.id=r.sender_user_id WHERE r.ticket_id=? ORDER BY r.id');$st->execute([(int)$selected['id']]);$replies=$st->fetchAll();}
        if($admin)$tickets=$pdo->query("SELECT t.*,u.name user_name,u.email user_email FROM support_tickets t JOIN users u ON u.id=t.user_id ORDER BY CASE WHEN t.status='open' THEN 0 ELSE 1 END,t.updated_at DESC LIMIT 200")->fetchAll();
        else{$st=$pdo->prepare('SELECT t.*,u.name user_name,u.email user_email FROM support_tickets t JOIN users u ON u.id=t.user_id WHERE t.user_id=? ORDER BY t.updated_at DESC LIMIT 100');$st->execute([(int)$u['id']]);$tickets=$st->fetchAll();}

        ob_start();?><div class="page-head"><div><span class="eyebrow">Help desk</span><h1>Support</h1><p class="muted"><?= $admin?'Review, reply to, and resolve issues raised from any dashboard.':'Raise an issue and follow administrator replies here.' ?></p></div></div><?php if($message):?><div class="alert alert-success"><?=e($message)?></div><?php endif;?><?php if($error):?><div class="alert alert-danger"><?=e($error)?></div><?php endif;?>
        <?php if(!$admin):?><section class="card"><h2>Raise a support request</h2><form method="post"><input type="hidden" name="_csrf" value="<?=e(Csrf::token())?>"><input type="hidden" name="action" value="create"><div class="form-row"><label>Category<select name="category"><option value="general">General</option><option value="account">Account</option><option value="course">Course</option><option value="exam">Exam</option><option value="payment">Payment</option><option value="technical">Technical</option></select></label><label>Priority<select name="priority"><option value="normal">Normal</option><option value="low">Low</option><option value="high">High</option></select></label></div><label>Subject<input name="subject" maxlength="180" required></label><label>Issue details<textarea name="body" rows="5" maxlength="8000" required></textarea></label><button class="btn btn-primary">Submit issue</button></form></section><?php endif;?>
        <div class="grid-2"><section class="card table-card"><h2><?= $admin?'All tickets':'My tickets' ?></h2><div class="table-scroll"><table><thead><tr><th>ID</th><th>Issue</th><?php if($admin):?><th>User</th><?php endif;?><th>Status</th></tr></thead><tbody><?php foreach($tickets as $t):?><tr><td><a class="text-link" href="<?=e(app_url('?r=support&ticket='.(int)$t['public_id']))?>">#<?=e((string)$t['public_id'])?></a></td><td><strong><?=e($t['subject'])?></strong><small><?=e(ucfirst($t['category']).' · '.ucfirst($t['priority']).' · '.format_app_datetime($t['updated_at']))?></small></td><?php if($admin):?><td><?=e($t['user_name'])?><small><?=e($t['user_email'])?></small></td><?php endif;?><td><span class="badge <?=$t['status']==='resolved'?'badge-ok':'badge-warn'?>"><?=e($t['status'])?></span></td></tr><?php endforeach;?><?php if(!$tickets):?><tr><td colspan="4" class="muted">No support tickets.</td></tr><?php endif;?></tbody></table></div></section>
        <section class="card"><?php if(!$selected):?><div class="empty-state"><h2>Select a ticket</h2><p class="muted">Open a ticket from the list to view the conversation.</p></div><?php else:?><div class="section-title"><div><h2>#<?=e((string)$selected['public_id'])?> · <?=e($selected['subject'])?></h2><p class="muted"><?=e($selected['user_name'].' · '.$selected['user_email'])?></p></div><span class="badge <?=$selected['status']==='resolved'?'badge-ok':'badge-warn'?>"><?=e($selected['status'])?></span></div><div class="support-thread"><?php foreach($replies as $r):?><article class="support-message"><strong><?=e($r['sender_name'])?></strong><small><?=e(format_app_datetime($r['created_at']))?></small><p><?=nl2br(e($r['body']))?></p></article><?php endforeach;?></div><?php if($selected['status']!=='resolved'):?><form method="post"><input type="hidden" name="_csrf" value="<?=e(Csrf::token())?>"><input type="hidden" name="action" value="reply"><input type="hidden" name="ticket_public_id" value="<?=(int)$selected['public_id']?>"><label>Reply<textarea name="body" rows="4" maxlength="8000" required></textarea></label><button class="btn btn-primary">Send reply</button></form><?php if($admin):?><form method="post" class="inline-form" onsubmit="return confirm('Resolve this ticket? No further replies will be accepted.')"><input type="hidden" name="_csrf" value="<?=e(Csrf::token())?>"><input type="hidden" name="action" value="resolve"><input type="hidden" name="ticket_public_id" value="<?=(int)$selected['public_id']?>"><button class="btn">Resolve ticket</button></form><?php endif;?><?php else:?><div class="alert alert-success">This ticket is resolved. Replies are closed for both the user and administrators.</div><?php endif;?><?php endif;?></section></div>
        <?php render_page('Support',ob_get_clean());
    }

    private static function ticketByPublicId($pdo,int $publicId):?array{$st=$pdo->prepare('SELECT t.*,u.name user_name,u.email user_email FROM support_tickets t JOIN users u ON u.id=t.user_id WHERE t.public_id=? LIMIT 1');$st->execute([$publicId]);$r=$st->fetch();return $r?:null;}
    private static function newPublicId($pdo):int{for($i=0;$i<50;$i++){$id=random_int(100000,99999999);$st=$pdo->prepare('SELECT COUNT(*) FROM support_tickets WHERE public_id=?');$st->execute([$id]);if(!(int)$st->fetchColumn())return $id;}throw new \RuntimeException('Unable to allocate support ticket ID.');}
    private static function notifyAdmins(string $title,string $body,string $link):void{try{$rows=Database::connection()->query("SELECT DISTINCT u.id FROM users u JOIN user_roles ur ON ur.user_id=u.id JOIN roles r ON r.id=ur.role_id WHERE u.status='active' AND r.name IN ('super_admin','administrator')")->fetchAll(\PDO::FETCH_COLUMN);foreach($rows as $id)notify_user((int)$id,$title,$body,'support',$link);}catch(\Throwable){}}
}
