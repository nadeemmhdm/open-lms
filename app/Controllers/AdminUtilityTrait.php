<?php
namespace OpenLMS\Controllers;

use OpenLMS\Core\Auth;
use OpenLMS\Core\Csrf;
use OpenLMS\Core\Database;
use OpenLMS\Services\TotpService;

trait AdminUtilityTrait
{
    public static function features(): void
    {
        Auth::requireAdmin();
        $pdo = Database::connection();
        $message = null;
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && Csrf::validate($_POST['_csrf'] ?? null)) {
            $feature = (string) ($_POST['feature'] ?? '');
            $enabled = isset($_POST['enabled']) ? 1 : 0;
            $stmt = $pdo->prepare('UPDATE feature_settings SET enabled = ?, updated_at = ? WHERE feature_key = ?');
            $stmt->execute([$enabled, gmdate('Y-m-d H:i:s'), $feature]);
            Auth::audit('features.update', 'Feature ' . $feature . ' set to ' . ($enabled ? 'enabled' : 'disabled'));
            $message = 'Feature setting updated.';
        }
        $features = $pdo->query('SELECT feature_key, enabled, updated_at FROM feature_settings ORDER BY feature_key')->fetchAll();
        ob_start(); ?>
        <div class="page-head"><div><h1>Modules</h1><p class="muted">Enable or disable optional LMS capabilities.</p></div></div>
        <?php if($message): ?><div class="alert alert-success"><?= e($message) ?></div><?php endif; ?>
        <div class="feature-grid"><?php foreach($features as $f): ?><form class="card feature-card" method="post"><input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>"><input type="hidden" name="feature" value="<?= e($f['feature_key']) ?>"><div><h3><?= e(ucwords(str_replace('_',' ',$f['feature_key']))) ?></h3><small>Updated <?= e($f['updated_at']) ?> UTC</small></div><label class="switch"><input type="checkbox" name="enabled" <?= $f['enabled']?'checked':'' ?> onchange="this.form.submit()"><span></span></label></form><?php endforeach; ?></div>
        <?php render_page('Modules', ob_get_clean());
    }
    public static function audit(): void
    {
        Auth::requireAdmin();
        $pdo = Database::connection();
        $rows = $pdo->query('SELECT a.*, u.email actor_email FROM audit_logs a LEFT JOIN users u ON u.id=a.actor_user_id ORDER BY a.id DESC LIMIT 200')->fetchAll();
        ob_start(); ?>
        <div class="page-head"><div><h1>Audit log</h1><p class="muted">Recent privileged and authentication activity.</p></div></div>
        <section class="card table-card"><div class="table-scroll"><table><thead><tr><th>Time</th><th>Actor</th><th>Action</th><th>Description</th><th>IP</th></tr></thead><tbody><?php foreach($rows as $r): ?><tr><td><?= e($r['created_at']) ?></td><td><?= e($r['actor_email'] ?: 'system') ?></td><td><code><?= e($r['action']) ?></code></td><td><?= e($r['description']) ?></td><td><?= e($r['ip_address'] ?: '—') ?></td></tr><?php endforeach; ?></tbody></table></div></section>
        <?php render_page('Audit log', ob_get_clean());
    }
}
