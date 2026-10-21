<?php
namespace OpenLMS\Controllers;

use OpenLMS\Core\Auth;
use OpenLMS\Core\Database;

final class DashboardController
{
    public static function show(): void
    {
        Auth::requireAuth();
        $pdo = Database::connection();
        $counts=[]; foreach(['users','roles','audit_logs'] as $table) $counts[$table]=(int)$pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
        $features=(int)$pdo->query('SELECT COUNT(*) FROM feature_settings WHERE enabled = 1')->fetchColumn();
        $user=Auth::user(); $recent=$pdo->query('SELECT action, description, created_at FROM audit_logs ORDER BY id DESC LIMIT 8')->fetchAll();
        ob_start(); ?>
        <div class="page-head"><div><h1>Dashboard</h1><p class="muted">Welcome, <?= e($user['name']) ?>.</p></div><span class="badge"><?= e(implode(', ', $user['roles'])) ?></span></div>
        <div class="stats-grid"><div class="stat card"><span>Users</span><strong><?= $counts['users'] ?></strong></div><div class="stat card"><span>Roles</span><strong><?= $counts['roles'] ?></strong></div><div class="stat card"><span>Enabled modules</span><strong><?= $features ?></strong></div><div class="stat card"><span>Audit events</span><strong><?= $counts['audit_logs'] ?></strong></div></div>
        <div class="grid-2"><section class="card"><h2>Platform status</h2><ul class="status-list"><li><span class="dot ok"></span> Authentication active</li><li><span class="dot ok"></span> CSRF protection active</li><li><span class="dot ok"></span> RBAC active</li><li><span class="dot ok"></span> Install lock active</li></ul></section><section class="card"><h2>Recent activity</h2><?php if(!$recent): ?><p class="muted">No activity yet.</p><?php else: ?><div class="activity-list"><?php foreach($recent as $item): ?><div><strong><?= e($item['action']) ?></strong><span><?= e($item['description']) ?></span><small><?= e($item['created_at']) ?> UTC</small></div><?php endforeach; ?></div><?php endif; ?></section></div>
        <?php render_page('Dashboard', ob_get_clean());
    }
}
