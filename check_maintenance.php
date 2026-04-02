<?php
header('Content-Type: application/json');
require_once 'config.php';

// getMaintenanceStatus is defined in includes/security.php, which is included by config.php
$is_maintenance = getMaintenanceStatus($pdo);

echo json_encode(['maintenance' => $is_maintenance]);
?>