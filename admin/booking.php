<?php
require_once __DIR__ . '/../config/bootstrap.php';
$admin = app_require_admin();
$pdo = app_db();
$bookingId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$bookingId || $bookingId < 1) {
    http_response_code(404);
    exit('Booking not found.');
}

$message = '';
$messageType = 'error';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    app_verify_csrf();
    $decision = (string) ($_POST['decision'] ?? '');
    if (!in_array($decision, ['approved', 'rejected'], true)) {
        http_response_code(422);
        $message = 'Choose a valid booking decision.';
    } else {
        try {
            $pdo->beginTransaction();
            $lockBooking = $pdo->prepare(
                'SELECT id, room_id, booking_date, start_time, end_time, status
                 FROM bookings WHERE id = :id FOR UPDATE'
            );
            $lockBooking->execute(['id' => $bookingId]);
            $booking = $lockBooking->fetch();
            if (!$booking) {
                $pdo->rollBack();
                http_response_code(404);
                $message = 'Booking not found.';
            } elseif ($booking['status'] !== 'pending') {
                $pdo->rollBack();
                http_response_code(409);
                $message = 'This request has already been reviewed.';
            } else {
                $lockRoom = $pdo->prepare('SELECT id, status FROM rooms WHERE id = :id FOR UPDATE');
                $lockRoom->execute(['id' => $booking['room_id']]);
                $room = $lockRoom->fetch();
                if (!$room) {
                    throw new RuntimeException('The booking references a missing room.');
                }

                if ($decision === 'approved') {
                    if ($room['status'] !== 'active') {
                        $pdo->rollBack();
                        http_response_code(409);
                        $message = 'This room is inactive and the request cannot be approved.';
                    }
                    $conflict = $pdo->prepare(
                        "SELECT id FROM bookings
                         WHERE room_id = :room_id AND booking_date = :booking_date
                           AND status = 'approved' AND id <> :booking_id
                           AND start_time < :end_time AND end_time > :start_time
                         LIMIT 1"
                    );
                    if ($pdo->inTransaction()) {
                    $conflict->execute([
                        'room_id' => $booking['room_id'],
                        'booking_date' => $booking['booking_date'],
                        'booking_id' => $bookingId,
                        'end_time' => $booking['end_time'],
                        'start_time' => $booking['start_time'],
                    ]);
                    if ($conflict->fetchColumn()) {
                        $pdo->rollBack();
                        http_response_code(409);
                        $message = 'This room already has an approved booking that overlaps this time.';
                    }
                    }
                }

                if ($pdo->inTransaction()) {
                    $update = $pdo->prepare(
                        'UPDATE bookings
                         SET status = :status, reviewed_by = :reviewed_by, reviewed_at = CURRENT_TIMESTAMP
                         WHERE id = :id AND status = \'pending\''
                    );
                    $update->execute([
                        'status' => $decision,
                        'reviewed_by' => $admin['id'],
                        'id' => $bookingId,
                    ]);
                    if ($update->rowCount() !== 1) {
                        $pdo->rollBack();
                        http_response_code(409);
                        $message = 'This request has already been reviewed.';
                    } else {
                        app_audit($pdo, (int) $admin['id'], 'booking_' . $decision, 'booking', $bookingId);
                        $pdo->commit();
                        $message = 'Booking ' . $decision . ' successfully.';
                        $messageType = 'success';
                    }
                }
            }
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Admin booking review error: ' . $exception->getMessage());
            if ($message === '') {
                http_response_code(500);
                $message = 'We could not update this booking. Please try again.';
            }
        }
    }
}

$detail = $pdo->prepare(
    'SELECT b.*, r.name AS room_name, r.location AS room_location,
            u.first_name, u.last_name, u.school_id, u.email,
            reviewer.first_name AS reviewer_first, reviewer.last_name AS reviewer_last
     FROM bookings b JOIN rooms r ON r.id = b.room_id JOIN users u ON u.id = b.user_id
     LEFT JOIN users reviewer ON reviewer.id = b.reviewed_by
     WHERE b.id = :id'
);
$detail->execute(['id' => $bookingId]);
$booking = $detail->fetch();
if (!$booking) {
    http_response_code(404);
    exit('Booking not found.');
}
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Booking details | School Book-ol</title>
<style>
body{margin:0;background:#f6f7f3;color:#1d2a25;font:16px "Segoe UI",Arial,sans-serif}main{max-width:800px;margin:40px auto;padding:0 20px}h1{color:#1f3b33}.card{padding:24px;background:white;border:1px solid #c9d3cc;border-radius:12px}.facts{display:grid;grid-template-columns:180px 1fr;gap:12px}.facts dt{color:#5b6b64}.facts dd{margin:0;overflow-wrap:anywhere}.message{padding:12px;background:#f7e0e0;border-radius:8px}.message.success{background:#e1f1e8}.actions{display:flex;gap:12px;margin-top:24px}button{padding:12px 18px;border:0;border-radius:8px;background:#1f3b33;color:#fff;font:inherit;font-weight:600;cursor:pointer}button.reject{background:#fff;color:#a32929;border:1px solid #a32929}
</style></head><body><main>
<p><a href="bookings.php">← Booking requests</a></p><h1>Booking #<?= (int) $booking['id'] ?></h1><section class="card">
<?php if ($message !== ''): ?><p class="message<?= $messageType === 'success' ? ' success' : '' ?>" role="status"><?= app_escape($message) ?></p><?php endif; ?>
<dl class="facts">
<dt>Requester</dt><dd><?= app_escape($booking['first_name'] . ' ' . $booking['last_name']) ?> (<?= app_escape($booking['school_id']) ?>)</dd>
<dt>Email</dt><dd><?= app_escape($booking['email']) ?></dd><dt>Room</dt><dd><?= app_escape($booking['room_name']) ?><?= $booking['room_location'] ? ' — ' . app_escape($booking['room_location']) : '' ?></dd>
<dt>Date</dt><dd><?= app_escape($booking['booking_date']) ?></dd><dt>Time</dt><dd><?= app_escape(substr($booking['start_time'], 0, 5) . '–' . substr($booking['end_time'], 0, 5)) ?></dd>
<dt>Purpose</dt><dd><?= nl2br(app_escape($booking['purpose'])) ?></dd><dt>People</dt><dd><?= $booking['people_count'] === null ? 'Not specified' : (int) $booking['people_count'] ?></dd>
<dt>Status</dt><dd><?= app_escape(ucfirst($booking['status'])) ?></dd><dt>Submitted</dt><dd><?= app_escape($booking['created_at']) ?></dd>
<?php if ($booking['reviewed_at']): ?><dt>Reviewed by</dt><dd><?= app_escape(trim(($booking['reviewer_first'] ?? '') . ' ' . ($booking['reviewer_last'] ?? ''))) ?></dd><dt>Reviewed at</dt><dd><?= app_escape($booking['reviewed_at']) ?></dd><?php endif; ?>
</dl>
<?php if ($booking['status'] === 'pending'): ?><form method="post">
<input type="hidden" name="csrf_token" value="<?= app_escape(app_csrf_token()) ?>">
<div class="actions"><button type="submit" name="decision" value="approved">Approve</button><button class="reject" type="submit" name="decision" value="rejected">Reject</button></div>
</form><?php endif; ?>
</section></main></body></html>
