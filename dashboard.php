<?php
require_once __DIR__ . '/config/bootstrap.php';
$user = app_require_user();
if ($user['role'] === 'Admin') {
    header('Location: admin/dashboard.php');
    exit;
}

$pdo = app_db();
$bookingError = '';
$bookingSuccess = '';
$values = ['room_id' => '', 'date' => '', 'start' => '', 'end' => '', 'people' => '', 'purpose' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    app_verify_csrf();
    foreach ($values as $key => $_) {
        $values[$key] = trim((string) ($_POST[$key] ?? ''));
    }
    $roomId = filter_var($values['room_id'], FILTER_VALIDATE_INT);
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $values['date']);
    $start = DateTimeImmutable::createFromFormat('!H:i', $values['start']);
    $end = DateTimeImmutable::createFromFormat('!H:i', $values['end']);
    $people = $values['people'] === '' ? null : filter_var($values['people'], FILTER_VALIDATE_INT);

    if (!$roomId || !$date || $date->format('Y-m-d') !== $values['date'] || $date < new DateTimeImmutable('today')) {
        $bookingError = 'Choose an active room and a valid date that is not in the past.';
    } elseif (!$start || $start->format('H:i') !== $values['start'] || !$end || $end->format('H:i') !== $values['end'] || $end <= $start) {
        $bookingError = 'Choose valid start and end times. The end time must be later than the start time.';
    } elseif (mb_strlen($values['purpose']) < 3 || mb_strlen($values['purpose']) > 1000) {
        $bookingError = 'Enter a purpose between 3 and 1000 characters.';
    } elseif ($values['people'] !== '' && (!$people || $people < 1 || $people > 10000)) {
        $bookingError = 'Enter a valid number of people.';
    } elseif (!app_rate_limit($pdo, 'booking', 20, 3600)) {
        http_response_code(429);
        $bookingError = 'Too many booking requests. Please wait and try again.';
    } else {
        try {
            $room = $pdo->prepare("SELECT id FROM rooms WHERE id = :id AND status = 'active'");
            $room->execute(['id' => $roomId]);
            if (!$room->fetchColumn()) {
                $bookingError = 'That room is no longer available. Please choose an active room.';
            } else {
                $pdo->beginTransaction();
                $insert = $pdo->prepare(
                    'INSERT INTO bookings (user_id, room_id, booking_date, start_time, end_time, people_count, purpose)
                     VALUES (:user_id, :room_id, :booking_date, :start_time, :end_time, :people_count, :purpose)'
                );
                $insert->execute([
                    'user_id' => $user['id'],
                    'room_id' => $roomId,
                    'booking_date' => $values['date'],
                    'start_time' => $values['start'],
                    'end_time' => $values['end'],
                    'people_count' => $people,
                    'purpose' => $values['purpose'],
                ]);
                $bookingId = (int) $pdo->lastInsertId();
                app_audit($pdo, (int) $user['id'], 'booking_created', 'booking', $bookingId);
                $pdo->commit();
                $bookingSuccess = 'Your booking request was submitted for administrator review.';
                $values = ['room_id' => '', 'date' => '', 'start' => '', 'end' => '', 'people' => '', 'purpose' => ''];
            }
        } catch (PDOException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Booking request database error: ' . $exception->getMessage());
            http_response_code(500);
            $bookingError = 'We could not submit your request right now. Please try again later.';
        }
    }
}

$rooms = $pdo->query("SELECT id, name FROM rooms WHERE status = 'active' ORDER BY name")->fetchAll();
$bookingsStatement = $pdo->prepare(
    'SELECT b.id, b.booking_date, b.start_time, b.end_time, b.purpose, b.status, r.name AS room_name
     FROM bookings b JOIN rooms r ON r.id = b.room_id
     WHERE b.user_id = :user_id ORDER BY b.created_at DESC LIMIT 100'
);
$bookingsStatement->execute(['user_id' => $user['id']]);
$bookings = $bookingsStatement->fetchAll();
$pendingCount = count(array_filter($bookings, static fn(array $booking): bool => $booking['status'] === 'pending'));
$upcomingBookings = $pdo->query(
    "SELECT b.booking_date, b.start_time, b.end_time, b.purpose, r.name AS room_name
     FROM bookings b JOIN rooms r ON r.id = b.room_id
     WHERE b.status = 'approved' AND b.booking_date >= CURRENT_DATE
     ORDER BY b.booking_date, b.start_time LIMIT 10"
)->fetchAll();
$fullName = trim($user['first_name'] . ' ' . $user['last_name']);
$initials = strtoupper(
    mb_substr($user['first_name'], 0, 1) . mb_substr($user['last_name'], 0, 1)
);
$today = new DateTimeImmutable();

function escape(string $value): string
{
    return app_escape($value);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>School Book-ol | Dashboard</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,600;12..96,800&family=Figtree:wght@400;500;600&display=swap" rel="stylesheet">
<style>
  :root {
    --board: #1f3b33;
    --board-deep: #16302a;
    --chalk: #f2f0e6;
    --chalk-dim: rgba(242, 240, 230, 0.6);
    --pencil: #f2b632;
    --ink: #1d2a25;
    --muted: #5b6b64;
    --paper: #f6f7f3;
    --line: #c9d3cc;
    --ok: #2f7d57;
    --wait: #a8700a;
    --display: "Bricolage Grotesque", "Trebuchet MS", sans-serif;
    --body: "Figtree", "Segoe UI", Arial, sans-serif;
  }

  * { box-sizing: border-box; margin: 0; padding: 0; }

  body {
    min-height: 100vh;
    display: grid;
    grid-template-columns: 250px 1fr;
    font-family: var(--body);
    color: var(--ink);
    background: var(--paper);
  }

  /* Sidebar */
  .side {
    background: var(--board);
    color: var(--chalk);
    padding: 32px 22px;
    display: flex;
    flex-direction: column;
    gap: 32px;
    border-right: 8px solid #b98a52;
    position: sticky;
    top: 0;
    height: 100vh;
  }

  .logo {
    font-family: var(--display);
    font-weight: 800;
    font-size: 1.7rem;
    letter-spacing: -0.03em;
    line-height: 1;
  }
  .logo span { color: var(--pencil); }

  .nav { list-style: none; display: grid; gap: 4px; flex: 1; }

  .nav a {
    display: block;
    padding: 11px 14px;
    border-radius: 8px;
    color: var(--chalk-dim);
    text-decoration: none;
    font-weight: 500;
  }
  .nav a:hover { background: rgba(242, 240, 230, 0.08); color: var(--chalk); }
  .nav a.on { background: var(--chalk); color: var(--board-deep); font-weight: 600; }

  .me {
    display: flex;
    align-items: center;
    gap: 12px;
    padding-top: 20px;
    border-top: 1px dashed rgba(242, 240, 230, 0.3);
    font-size: 0.92rem;
  }
  .avatar {
    width: 40px; height: 40px;
    border-radius: 50%;
    background: var(--pencil);
    color: var(--board-deep);
    display: grid; place-items: center;
    font-weight: 700;
  }
  .me small { display: block; color: var(--chalk-dim); }
  .sign-out {
    margin-top: 6px;
    padding: 0;
    color: var(--chalk-dim);
    background: none;
    border: 0;
    font: inherit;
    font-size: 0.85rem;
    text-decoration: underline;
    text-underline-offset: 3px;
    cursor: pointer;
  }
  .sign-out:hover { color: var(--chalk); }

  /* Main */
  main { padding: 36px 40px 56px; min-width: 0; }

  .top h1 {
    font-family: var(--display);
    font-weight: 600;
    font-size: 2rem;
    letter-spacing: -0.02em;
  }
  .top p { margin-top: 4px; color: var(--muted); }

  .stats {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 16px;
    margin: 28px 0;
  }
  .stat {
    background: #fff;
    border: 1.5px solid var(--line);
    border-radius: 12px;
    padding: 18px 20px;
  }
  .stat b {
    display: block;
    font-family: var(--display);
    font-size: 2rem;
    line-height: 1.1;
  }
  .stat span { color: var(--muted); font-size: 0.92rem; }
  .stat.hl { background: var(--board); border-color: var(--board); color: var(--chalk); }
  .stat.hl span { color: var(--chalk-dim); }

  .grid {
    display: grid;
    grid-template-columns: 1.15fr 1fr;
    gap: 24px;
  }

  .card {
    background: #fff;
    border: 1.5px solid var(--line);
    border-radius: 14px;
    padding: 26px;
  }
  .card h2 {
    font-family: var(--display);
    font-weight: 600;
    font-size: 1.3rem;
    letter-spacing: -0.01em;
  }
  .card .sub { margin: 4px 0 22px; color: var(--muted); font-size: 0.95rem; }

  /* Form */
  .field { margin-bottom: 16px; }
  .two { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }

  label { display: block; margin-bottom: 6px; font-weight: 600; font-size: 0.92rem; }

  input, select, textarea {
    width: 100%;
    padding: 12px 13px;
    font: inherit;
    color: var(--ink);
    background: #fff;
    border: 1.5px solid var(--line);
    border-radius: 8px;
    transition: border-color 0.15s, box-shadow 0.15s;
  }
  textarea { resize: vertical; min-height: 84px; }
  input::placeholder, textarea::placeholder { color: #93a19a; }

  select {
    appearance: none;
    padding-right: 38px;
    background-image:
      linear-gradient(45deg, transparent 50%, var(--muted) 50%),
      linear-gradient(135deg, var(--muted) 50%, transparent 50%);
    background-position: calc(100% - 19px) 50%, calc(100% - 13px) 50%;
    background-size: 6px 6px;
    background-repeat: no-repeat;
  }

  input:focus, select:focus, textarea:focus {
    outline: none;
    border-color: var(--board);
    box-shadow: 0 0 0 4px rgba(31, 59, 51, 0.15);
  }

  .btn {
    width: 100%;
    padding: 14px;
    margin-top: 6px;
    font: inherit;
    font-weight: 600;
    font-size: 1.02rem;
    color: var(--chalk);
    background: var(--board);
    border: none;
    border-radius: 8px;
    cursor: pointer;
    transition: background 0.15s;
  }
  .btn:hover { background: var(--board-deep); }

  a:focus-visible, .btn:focus-visible {
    outline: 3px solid var(--pencil);
    outline-offset: 2px;
  }

  /* Availability */
  .legend {
    display: flex; flex-wrap: wrap; gap: 14px;
    margin-bottom: 16px;
    font-size: 0.85rem; color: var(--muted);
  }
  .legend i {
    display: inline-block; width: 12px; height: 12px;
    border-radius: 3px; margin-right: 6px; vertical-align: -1px;
  }
  .k-free { border: 1.5px dashed var(--line); }
  .k-busy { background: var(--board); }
  .k-mine { background: var(--pencil); }

  .day { display: grid; gap: 8px; }
  .hour { display: grid; grid-template-columns: 58px 1fr; align-items: center; gap: 10px; }
  .hour time { font-size: 0.85rem; color: var(--muted); }
  .blk {
    padding: 9px 12px;
    border-radius: 8px;
    font-size: 0.88rem;
    border: 1.5px dashed var(--line);
    color: var(--muted);
  }
  .blk.busy { background: var(--board); border: 1.5px solid var(--board); color: var(--chalk); }
  .blk.mine { background: var(--pencil); border: 1.5px solid var(--pencil); color: var(--board-deep); font-weight: 600; }

  /* Bookings table */
  .wide { grid-column: 1 / -1; }

  .table { width: 100%; border-collapse: collapse; }
  .table th {
    text-align: left;
    padding: 0 12px 10px;
    font-size: 0.85rem;
    font-weight: 600;
    color: var(--muted);
    border-bottom: 1.5px solid var(--line);
  }
  .table td { padding: 14px 12px; border-bottom: 1px solid #e6ebe7; font-size: 0.95rem; }
  .table tr:last-child td { border-bottom: none; }
  .table .room { font-weight: 600; }

  .pill {
    display: inline-block;
    padding: 4px 12px;
    border-radius: 999px;
    font-size: 0.82rem;
    font-weight: 600;
  }
  .pill.ok { background: #e1f1e8; color: var(--ok); }
  .pill.wait { background: #fbf0d6; color: var(--wait); }

  .tlink { color: var(--board); font-weight: 600; text-underline-offset: 3px; }

  .scroll { overflow-x: auto; }

  @media (max-width: 1000px) {
    .grid { grid-template-columns: 1fr; }
  }

  @media (max-width: 860px) {
    body { grid-template-columns: 1fr; }
    .side {
      position: static; height: auto;
      flex-direction: row; flex-wrap: wrap; align-items: center;
      gap: 14px 24px; padding: 18px 20px;
      border-right: none; border-bottom: 8px solid #b98a52;
    }
    .nav { display: flex; flex-wrap: wrap; order: 3; width: 100%; flex: none; }
    .me { border-top: none; padding-top: 0; margin-left: auto; }
    main { padding: 28px 20px 48px; }
  }

  @media (max-width: 520px) {
    .stats { grid-template-columns: 1fr; }
    .two { grid-template-columns: 1fr; gap: 0; }
    .two .field { margin-bottom: 16px; }
  }

  @media (prefers-reduced-motion: reduce) { * { transition: none !important; } }
</style>
</head>
<body>

  <aside class="side">
    <div class="logo">School <span>Book-ol</span></div>

    <ul class="nav">
      <li><a href="dashboard.php" class="on">Dashboard</a></li>
      <li><a href="#booking-form">Book a room</a></li>
      <li><a href="#my-bookings">My bookings</a></li>
    </ul>

    <div class="me">
      <div class="avatar"><?= escape($initials) ?></div>
      <div><?= escape($fullName) ?><small><?= escape($user['role']) ?> · <?= escape($user['school_id']) ?></small>
        <form method="post" action="logout.php">
          <input type="hidden" name="csrf_token" value="<?= escape(app_csrf_token()) ?>">
          <button class="sign-out" type="submit">Log out</button>
        </form>
      </div>
    </div>
  </aside>

  <main>
    <header class="top">
      <h1>Welcome, <?= escape($user['first_name']) ?></h1>
      <p><?= escape($today->format('l, F j')) ?>. Pick a room, a time, and send your request.</p>
    </header>

    <section class="stats">
      <div class="stat hl"><b><?= count(array_filter($bookings, static fn(array $booking): bool => $booking['status'] === 'approved')) ?></b><span>Approved bookings</span></div>
      <div class="stat"><b><?= $pendingCount ?></b><span>Waiting for approval</span></div>
      <div class="stat"><b><?= count($bookings) ?></b><span>Recent bookings</span></div>
    </section>

    <div class="grid">

      <section class="card" id="booking-form">
        <h2>Book a room</h2>
        <p class="sub">Fill in the details and we'll send it to the admin for approval.</p>

        <?php if ($bookingError !== ''): ?><p role="alert" style="margin:12px 0;color:#a32929;"><?= escape($bookingError) ?></p><?php endif; ?>
        <?php if ($bookingSuccess !== ''): ?><p role="status" style="margin:12px 0;color:#1f5a3e;"><?= escape($bookingSuccess) ?></p><?php endif; ?>
        <form method="post" action="dashboard.php#booking-form">
          <input type="hidden" name="csrf_token" value="<?= escape(app_csrf_token()) ?>">
          <div class="field">
            <label for="room">Room or place</label>
            <select id="room" name="room_id" required>
              <option value="" disabled <?= $values['room_id'] === '' ? 'selected' : '' ?>>Choose a room</option>
              <?php foreach ($rooms as $room): ?>
                <option value="<?= (int) $room['id'] ?>" <?= $values['room_id'] === (string) $room['id'] ? 'selected' : '' ?>><?= escape($room['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="field">
            <label for="date">Date</label>
            <input type="date" id="date" name="date" required value="<?= escape($values['date']) ?>">
          </div>

          <div class="two">
            <div class="field">
              <label for="start">Start time</label>
              <input type="time" id="start" name="start" required value="<?= escape($values['start']) ?>">
            </div>
            <div class="field">
              <label for="end">End time</label>
              <input type="time" id="end" name="end" required value="<?= escape($values['end']) ?>">
            </div>
          </div>

          <div class="field">
            <label for="people">Number of people</label>
            <input type="number" id="people" name="people" min="1" max="10000" placeholder="e.g. 30" value="<?= escape($values['people']) ?>">
          </div>

          <div class="field">
            <label for="purpose">Purpose</label>
            <textarea id="purpose" name="purpose" maxlength="1000" required placeholder="What will the room be used for?"><?= escape($values['purpose']) ?></textarea>
          </div>

          <button type="submit" class="btn">Request booking</button>
        </form>
      </section>

      <section class="card">
        <h2>Upcoming approved bookings</h2>
        <p class="sub">Confirmed room reservations across the school.</p>

        <div class="day">
          <?php if (!$upcomingBookings): ?><p class="sub">There are no upcoming approved bookings.</p><?php endif; ?>
          <?php foreach ($upcomingBookings as $booking): ?>
          <div class="hour">
            <time><?= escape(date('M j', strtotime($booking['booking_date']))) ?></time>
            <div class="blk busy"><?= escape($booking['room_name']) ?> · <?= escape(date('g:i a', strtotime($booking['start_time'])) . '–' . date('g:i a', strtotime($booking['end_time']))) ?> — <?= escape($booking['purpose']) ?></div>
          </div>
          <?php endforeach; ?>
        </div>
      </section>

      <section class="card wide" id="my-bookings">
        <h2>My bookings</h2>
        <p class="sub">Your latest requests and their status.</p>

        <div class="scroll">
          <table class="table">
            <thead>
              <tr>
                <th>Room</th><th>Date</th><th>Time</th><th>Purpose</th><th>Status</th><th></th>
              </tr>
            </thead>
            <tbody>
              <?php if (!$bookings): ?><tr><td colspan="6">You have not submitted any booking requests yet.</td></tr><?php endif; ?>
              <?php foreach ($bookings as $booking): ?>
              <tr>
                <td class="room"><?= escape($booking['room_name']) ?></td>
                <td><?= escape(date('M j, Y', strtotime($booking['booking_date']))) ?></td>
                <td><?= escape(date('g:i a', strtotime($booking['start_time'])) . ' to ' . date('g:i a', strtotime($booking['end_time']))) ?></td>
                <td><?= escape($booking['purpose']) ?></td>
                <td><span class="pill <?= $booking['status'] === 'approved' ? 'ok' : 'wait' ?>"><?= escape(ucfirst($booking['status'])) ?></span></td>
                <td></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </section>

    </div>
  </main>

</body>
</html>