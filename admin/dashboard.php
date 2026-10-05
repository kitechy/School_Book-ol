<?php
require_once __DIR__ . '/../config/bootstrap.php';
$admin = app_require_admin();
$pdo = app_db();

$pendingCountStatement = $pdo->prepare('SELECT COUNT(*) FROM bookings WHERE status = :status');
$pendingCountStatement->execute(['status' => 'pending']);
$counts = [
    'pending' => (int) $pendingCountStatement->fetchColumn(),
];

$approvedCount = $pdo->prepare('SELECT COUNT(*) FROM bookings WHERE status = :status');
$approvedCount->execute(['status' => 'approved']);
$counts['approved'] = (int) $approvedCount->fetchColumn();

$rejectedCount = $pdo->prepare('SELECT COUNT(*) FROM bookings WHERE status = :status');
$rejectedCount->execute(['status' => 'rejected']);
$counts['rejected'] = (int) $rejectedCount->fetchColumn();

$userCount = $pdo->prepare('SELECT COUNT(*) FROM users');
$userCount->execute();
$counts['users'] = (int) $userCount->fetchColumn();

$roomCount = $pdo->prepare("SELECT COUNT(*) FROM rooms WHERE status = :status");
$roomCount->execute(['status' => 'active']);
$counts['rooms'] = (int) $roomCount->fetchColumn();

$pendingStatement = $pdo->prepare(
    'SELECT b.id, b.booking_date, b.start_time, r.name AS room_name,
            u.first_name, u.last_name
     FROM bookings b
     JOIN rooms r ON r.id = b.room_id
     JOIN users u ON u.id = b.user_id
     WHERE b.status = :status
     ORDER BY b.created_at ASC
     LIMIT 10'
);
$pendingStatement->execute(['status' => 'pending']);
$pending = $pendingStatement->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>School Book-ol | Admin dashboard</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,600;12..96,800&family=Figtree:wght@400;500;600&display=swap" rel="stylesheet">
<style>
  :root { --board:#1f3b33; --deep:#16302a; --chalk:#f2f0e6; --gold:#f2b632; --ink:#1d2a25; --muted:#5b6b64; --paper:#f6f7f3; --line:#c9d3cc; }
  * { box-sizing:border-box; }
  body { margin:0; min-height:100vh; display:grid; grid-template-columns:250px 1fr; color:var(--ink); background:var(--paper); font:16px "Figtree","Segoe UI",sans-serif; }
  aside { padding:32px 22px; background:var(--board); color:var(--chalk); border-right:8px solid #b98a52; }
  aside h2 { margin:0 0 28px; font:800 1.6rem "Bricolage Grotesque",sans-serif; }
  aside h2 span { color:var(--gold); }
  nav { display:grid; gap:8px; }
  nav a, .logout { padding:11px 14px; color:var(--chalk); text-decoration:none; border-radius:8px; }
  nav a:hover { background:rgba(255,255,255,.1); }
  .who { margin-top:32px; padding-top:18px; border-top:1px dashed #789087; color:var(--chalk); }
  .who small { display:block; color:#c6d1cb; }
  .logout { margin-top:12px; padding-left:0; background:none; border:0; font:inherit; text-decoration:underline; cursor:pointer; }
  main { min-width:0; padding:38px; }
  h1 { margin:0; font:600 2rem "Bricolage Grotesque",sans-serif; }
  .muted { color:var(--muted); }
  .stats { display:grid; grid-template-columns:repeat(5,minmax(120px,1fr)); gap:14px; margin:26px 0; }
  .stat, .panel { padding:20px; background:#fff; border:1px solid var(--line); border-radius:12px; }
  .stat b { display:block; font-size:1.8rem; color:var(--board); }
  .stat span { color:var(--muted); }
  .panel { overflow:auto; }
  table { width:100%; border-collapse:collapse; }
  th,td { padding:13px 10px; border-bottom:1px solid #e6ebe7; text-align:left; }
  a { color:var(--board); font-weight:600; }
  @media(max-width:980px) { body{grid-template-columns:1fr} aside{border-right:0;border-bottom:8px solid #b98a52} .stats{grid-template-columns:repeat(2,1fr)} main{padding:24px 16px} }
</style>
</head>
<body>
<aside>
  <h2>School <span>Book-ol</span></h2>
  <nav>
    <a href="dashboard.php">Admin dashboard</a>
    <a href="bookings.php">Booking requests<?php if ($counts['pending']): ?> (<?= $counts['pending'] ?>)<?php endif; ?></a>
    <a href="rooms.php">Rooms / facilities</a>
    <a href="users.php">Users</a>
  </nav>
  <div class="who"><?= app_escape($admin['first_name'] . ' ' . $admin['last_name']) ?><small>Administrator</small>
    <form method="post" action="../logout.php">
      <input type="hidden" name="csrf_token" value="<?= app_escape(app_csrf_token()) ?>">
      <button class="logout" type="submit">Log out</button>
    </form>
  </div>
</aside>
<main>
  <h1>Admin dashboard</h1>
  <p class="muted">Monitor room reservations and manage the booking system.</p>
  <section class="stats">
    <div class="stat"><b><?= $counts['pending'] ?></b><span>Pending requests</span></div>
    <div class="stat"><b><?= $counts['approved'] ?></b><span>Approved bookings</span></div>
    <div class="stat"><b><?= $counts['rejected'] ?></b><span>Rejected bookings</span></div>
    <div class="stat"><b><?= $counts['users'] ?></b><span>Registered users</span></div>
    <div class="stat"><b><?= $counts['rooms'] ?></b><span>Active rooms</span></div>
  </section>
  <section class="panel">
    <h2>Requests waiting for review</h2>
    <?php if (!$pending): ?><p class="muted">There are no pending requests.</p><?php else: ?>
    <table>
      <thead><tr><th>Requester</th><th>Room</th><th>Date</th><th>Start</th><th></th></tr></thead>
      <tbody><?php foreach ($pending as $booking): ?>
        <tr>
          <td><?= app_escape($booking['first_name'] . ' ' . $booking['last_name']) ?></td>
          <td><?= app_escape($booking['room_name']) ?></td>
          <td><?= app_escape($booking['booking_date']) ?></td>
          <td><?= app_escape(substr($booking['start_time'], 0, 5)) ?></td>
          <td><a href="booking.php?id=<?= (int) $booking['id'] ?>">Review request</a></td>
        </tr>
      <?php endforeach; ?></tbody>
    </table><?php endif; ?>
  </section>
</main>
</body>
</html>
