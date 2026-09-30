<?php
require_once __DIR__ . '/../config/bootstrap.php';
app_require_admin();

$status = (string) ($_GET['status'] ?? 'pending');
$allowedStatuses = ['pending', 'approved', 'rejected', 'cancelled', 'all'];
if (!in_array($status, $allowedStatuses, true)) {
    $status = 'pending';
}

$pdo = app_db();
if ($status === 'all') {
    $statement = $pdo->query(
        'SELECT b.id, b.booking_date, b.start_time, b.end_time, b.purpose, b.status,
                r.name AS room_name, u.first_name, u.last_name
         FROM bookings b JOIN rooms r ON r.id = b.room_id JOIN users u ON u.id = b.user_id
         ORDER BY b.created_at DESC LIMIT 200'
    );
} else {
    $statement = $pdo->prepare(
        'SELECT b.id, b.booking_date, b.start_time, b.end_time, b.purpose, b.status,
                r.name AS room_name, u.first_name, u.last_name
         FROM bookings b JOIN rooms r ON r.id = b.room_id JOIN users u ON u.id = b.user_id
         WHERE b.status = :status ORDER BY b.created_at DESC LIMIT 200'
    );
    $statement->execute(['status' => $status]);
}
$bookings = $statement->fetchAll();
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Booking requests | School Book-ol</title>
<style>
body{margin:0;background:#f6f7f3;color:#1d2a25;font:16px "Segoe UI",Arial,sans-serif}main{max-width:1100px;margin:40px auto;padding:0 20px}h1{color:#1f3b33}nav{display:flex;gap:14px;flex-wrap:wrap;margin:20px 0}a{color:#1f3b33}section{padding:20px;background:white;border:1px solid #c9d3cc;border-radius:12px;overflow:auto}table{width:100%;border-collapse:collapse}th,td{text-align:left;padding:12px 9px;border-bottom:1px solid #e6ebe7}
</style></head><body><main>
<p><a href="dashboard.php">← Admin dashboard</a></p><h1>Booking requests</h1>
<nav aria-label="Filter bookings"><?php foreach ($allowedStatuses as $filter): ?><a href="?status=<?= app_escape($filter) ?>"<?= $status === $filter ? ' aria-current="page"' : '' ?>><?= app_escape(ucfirst($filter)) ?></a><?php endforeach; ?></nav>
<section><?php if (!$bookings): ?><p>No bookings in this category.</p><?php else: ?>
<table><thead><tr><th>Requester</th><th>Room</th><th>Date</th><th>Time</th><th>Status</th><th>Purpose</th><th></th></tr></thead><tbody>
<?php foreach ($bookings as $booking): ?><tr>
<td><?= app_escape($booking['first_name'] . ' ' . $booking['last_name']) ?></td><td><?= app_escape($booking['room_name']) ?></td>
<td><?= app_escape($booking['booking_date']) ?></td><td><?= app_escape(substr($booking['start_time'], 0, 5) . '–' . substr($booking['end_time'], 0, 5)) ?></td>
<td><?= app_escape(ucfirst($booking['status'])) ?></td><td><?= app_escape($booking['purpose']) ?></td>
<td><a href="booking.php?id=<?= (int) $booking['id'] ?>">Details</a></td></tr><?php endforeach; ?>
</tbody></table><?php endif; ?></section></main></body></html>
