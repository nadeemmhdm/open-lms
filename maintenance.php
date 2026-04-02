<?php
require_once 'config.php';

// If maintenance is OFF, redirect to login
if (!getMaintenanceStatus($pdo)) {
    header("Location: index.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Under Maintenance</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <script>
        // Check maintenance status every 10 seconds
        setInterval(function () {
            fetch('check_maintenance.php')
                .then(response => response.json())
                .then(data => {
                    if (data.maintenance === false) {
                        window.location.href = 'index.php';
                    }
                });
        }, 10000);
    </script>
</head>

<body
    style="display: flex; align-items: center; justify-content: center; height: 100vh; background: #f0f2f5; text-align: center; font-family: 'Segoe UI', sans-serif;">
    <div class="white-card"
        style="padding: 50px; border-radius: 20px; box-shadow: 0 10px 30px rgba(0,0,0,0.1); max-width: 500px; width: 90%;">
        <div
            style="background: rgba(78, 84, 200, 0.1); width: 100px; height: 100px; line-height: 100px; border-radius: 50%; margin: 0 auto 30px; color: var(--primary);">
            <i class="fas fa-tools" style="font-size: 3rem;"></i>
        </div>
        <h1 style="color: var(--dark); margin-bottom: 15px;">We'll be back soon!</h1>
        <p style="color: #666; line-height: 1.6; margin-bottom: 30px;">
            Our Learning Management System is currently undergoing scheduled maintenance to improve your experience.
            <strong>This page will auto-refresh once we're back online.</strong>
        </p>
        <div style="font-size: 0.8rem; color: #999;">
            <i class="fas fa-sync fa-spin"></i> Checking status...
        </div>

        <div style="margin-top: 40px; border-top: 1px solid #eee; padding-top: 20px;">
            <p style="font-size: 0.9rem;">Staff member? <a href="index.php"
                    style="color: var(--primary); font-weight: bold;">Login here</a></p>
        </div>
    </div>
</body>

</html>