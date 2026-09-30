<?php
require_once __DIR__ . '/../config/bootstrap.php';
$admin = app_require_admin();
$pdo = app_db();
$message = '';
$isError = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    app_verify_csrf();
    $action = (string) ($_POST['action'] ?? '');
    $rawRoomId = (string) ($_POST['room_id'] ?? '');
    $roomId = $rawRoomId === '' ? null : filter_var($rawRoomId, FILTER_VALIDATE_INT);
    try {
        if ($rawRoomId !== '' && (!$roomId || $roomId < 1)) {
            http_response_code(422);
            throw new InvalidArgumentException('Choose a valid room.');
        }
        if ($action === 'save') {
            $name = trim((string) ($_POST['name'] ?? ''));
            $description = trim((string) ($_POST['description'] ?? ''));
            $location = trim((string) ($_POST['location'] ?? ''));
            $capacityInput = trim((string) ($_POST['capacity'] ?? ''));
            $capacity = $capacityInput === '' ? null : filter_var($capacityInput, FILTER_VALIDATE_INT);
            if ($name === '' || mb_strlen($name) > 120 || mb_strlen($description) > 500 || mb_strlen($location) > 120
                || ($capacityInput !== '' && (!$capacity || $capacity < 1 || $capacity > 100000))) {
                http_response_code(422);
                $message = 'Check the room fields and try again.';
                $isError = true;
            } else {
                $pdo->beginTransaction();
                if ($roomId) {
                    $statement = $pdo->prepare(
                        'UPDATE rooms SET name = :name, description = :description, capacity = :capacity, location = :location
                         WHERE id = :id'
                    );
                    $statement->execute([
                        'name' => $name, 'description' => $description, 'capacity' => $capacity,
                        'location' => $location, 'id' => $roomId,
                    ]);
                    if ($statement->rowCount() === 0) {
                        $exists = $pdo->prepare('SELECT id FROM rooms WHERE id = :id');
                        $exists->execute(['id' => $roomId]);
                        if (!$exists->fetchColumn()) {
                            throw new DomainException('Room not found.');
                        }
                    }
                    app_audit($pdo, (int) $admin['id'], 'room_updated', 'room', $roomId);
                } else {
                    $statement = $pdo->prepare(
                        'INSERT INTO rooms (name, description, capacity, location)
                         VALUES (:name, :description, :capacity, :location)'
                    );
                    $statement->execute([
                        'name' => $name, 'description' => $description,
                        'capacity' => $capacity, 'location' => $location,
                    ]);
                    $roomId = (int) $pdo->lastInsertId();
                    app_audit($pdo, (int) $admin['id'], 'room_created', 'room', $roomId);
                }
                $pdo->commit();
                $message = 'Room saved.';
            }
        } elseif (in_array($action, ['disable', 'activate'], true) && $roomId) {
            $pdo->beginTransaction();
            $newStatus = $action === 'disable' ? 'inactive' : 'active';
            $statement = $pdo->prepare(
                'UPDATE rooms SET status = :status WHERE id = :id AND status <> :current_status'
            );
            $statement->execute([
                'status' => $newStatus,
                'current_status' => $newStatus,
                'id' => $roomId,
            ]);
            if ($statement->rowCount() !== 1) {
                throw new DomainException('Room not found or already has that status.');
            }
            app_audit($pdo, (int) $admin['id'], 'room_' . $action . 'd', 'room', $roomId);
            $pdo->commit();
            $message = 'Room ' . ($action === 'disable' ? 'disabled' : 'activated') . '.';
        } else {
            http_response_code(422);
            $message = 'Choose a valid room action.';
            $isError = true;
        }
    } catch (InvalidArgumentException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        http_response_code(422);
        $message = $exception->getMessage();
        $isError = true;
    } catch (DomainException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        http_response_code(404);
        $message = $exception->getMessage();
        $isError = true;
    } catch (PDOException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Admin room management error: ' . $exception->getMessage());
        http_response_code($exception->getCode() === '23000' ? 409 : 500);
        $message = $exception->getCode() === '23000' ? 'A room with that name already exists.' : 'We could not save the room.';
        $isError = true;
    }
}

$rooms = $pdo->query('SELECT id, name, description, capacity, location, status FROM rooms ORDER BY name')->fetchAll();
$editingRoom = null;
$editId = filter_input(INPUT_GET, 'edit', FILTER_VALIDATE_INT);
if ($editId) {
    $editStatement = $pdo->prepare('SELECT id, name, description, capacity, location FROM rooms WHERE id = :id');
    $editStatement->execute(['id' => $editId]);
    $editingRoom = $editStatement->fetch() ?: null;
}
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Rooms | School Book-ol</title>
<style>
body{margin:0;background:#f6f7f3;color:#1d2a25;font:16px "Segoe UI",Arial,sans-serif}main{max-width:1100px;margin:36px auto;padding:0 18px}h1,h2{color:#1f3b33}.layout{display:grid;grid-template-columns:minmax(260px,1fr) 2fr;gap:18px}.panel{background:#fff;border:1px solid #c9d3cc;border-radius:12px;padding:20px;margin-bottom:18px}label{display:block;margin:12px 0 5px}input,textarea{width:100%;padding:10px;border:1px solid #c9d3cc;border-radius:7px;font:inherit}button{margin-top:12px;padding:10px 14px;border:0;border-radius:7px;background:#1f3b33;color:#fff;font:inherit;cursor:pointer}.disable{background:#fff;color:#a32929;border:1px solid #a32929}table{width:100%;border-collapse:collapse}td,th{text-align:left;padding:10px 8px;border-bottom:1px solid #e6ebe7}.notice{padding:12px;background:<?= isset($isError) && $isError ? "'#f7e0e0'" : "'#e1f1e8'" ?>;border-radius:8px}
@media(max-width:800px){.layout{grid-template-columns:1fr}.panel{overflow:auto}}
</style></head><body><main>
<p><a href="dashboard.php">← Admin dashboard</a> · <a href="bookings.php">Booking requests</a> · <a href="users.php">Users</a></p>
<h1>Rooms and facilities</h1><?php if ($message !== ''): ?><p class="notice" role="status"><?= app_escape($message) ?></p><?php endif; ?>
<div class="layout"><section class="panel"><h2><?= $editingRoom ? 'Edit room' : 'Add room' ?></h2><form method="post">
<input type="hidden" name="csrf_token" value="<?= app_escape(app_csrf_token()) ?>"><input type="hidden" name="action" value="save">
<?php if ($editingRoom): ?><input type="hidden" name="room_id" value="<?= (int) $editingRoom['id'] ?>"><?php endif; ?>
<label for="name">Name</label><input id="name" name="name" maxlength="120" required value="<?= app_escape($editingRoom['name'] ?? '') ?>">
<label for="description">Description</label><textarea id="description" name="description" maxlength="500"><?= app_escape($editingRoom['description'] ?? '') ?></textarea>
<label for="location">Location</label><input id="location" name="location" maxlength="120" value="<?= app_escape($editingRoom['location'] ?? '') ?>">
<label for="capacity">Capacity</label><input id="capacity" name="capacity" type="number" min="1" max="100000" value="<?= app_escape((string) ($editingRoom['capacity'] ?? '')) ?>">
<button type="submit"><?= $editingRoom ? 'Save changes' : 'Add room' ?></button><?php if ($editingRoom): ?> <a href="rooms.php">Cancel</a><?php endif; ?></form></section>
<section class="panel"><h2>Manage rooms</h2><table><thead><tr><th>Room</th><th>Description</th><th>Capacity</th><th>Location</th><th>Status</th><th>Actions</th></tr></thead><tbody>
<?php foreach ($rooms as $room): ?><tr><td><?= app_escape($room['name']) ?></td><td><?= app_escape($room['description']) ?></td><td><?= $room['capacity'] === null ? '—' : (int) $room['capacity'] ?></td><td><?= app_escape($room['location']) ?></td><td><?= app_escape(ucfirst($room['status'])) ?></td><td>
<a href="rooms.php?edit=<?= (int) $room['id'] ?>">Edit</a>
<form method="post"><input type="hidden" name="csrf_token" value="<?= app_escape(app_csrf_token()) ?>"><input type="hidden" name="action" value="<?= $room['status'] === 'active' ? 'disable' : 'activate' ?>"><input type="hidden" name="room_id" value="<?= (int) $room['id'] ?>"><button class="<?= $room['status'] === 'active' ? 'disable' : '' ?>" type="submit"><?= $room['status'] === 'active' ? 'Disable' : 'Activate' ?></button></form>
</td></tr><?php endforeach; ?>
</tbody></table></section></div></main></body></html>
