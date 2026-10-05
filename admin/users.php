<?php
require_once __DIR__ . '/../config/bootstrap.php';
app_require_admin();
$statement = app_db()->prepare(
    'SELECT id, first_name, last_name, school_id, email, role, created_at
     FROM users ORDER BY created_at DESC LIMIT 500'
);
$statement->execute();
$users = $statement->fetchAll();
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Users | School Book-ol</title>
<style>
body{margin:0;background:#f6f7f3;color:#1d2a25;font:16px "Segoe UI",Arial,sans-serif}main{max-width:1100px;margin:36px auto;padding:0 18px}h1{color:#1f3b33}.panel{padding:20px;background:#fff;border:1px solid #c9d3cc;border-radius:12px;overflow:auto}table{width:100%;border-collapse:collapse}th,td{text-align:left;padding:12px 9px;border-bottom:1px solid #e6ebe7}a{color:#1f3b33}
</style></head><body><main><p><a href="dashboard.php">← Admin dashboard</a> · <a href="bookings.php">Booking requests</a> · <a href="rooms.php">Rooms</a></p>
<h1>Users</h1><section class="panel"><table><thead><tr><th>Name</th><th>School ID</th><th>Email</th><th>Role</th><th>Registered</th></tr></thead><tbody>
<?php foreach ($users as $user): ?><tr><td><?= app_escape($user['first_name'] . ' ' . $user['last_name']) ?></td><td><?= app_escape($user['school_id']) ?></td><td><?= app_escape($user['email']) ?></td><td><?= app_escape($user['role']) ?></td><td><?= app_escape($user['created_at']) ?></td></tr><?php endforeach; ?>
</tbody></table></section></main></body></html>
