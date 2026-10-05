<?php
require_once __DIR__ . '/../config/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

if ($argc !== 5) {
    fwrite(STDERR, "Usage: php bin/create_admin.php <school-id> <email> <first-name> <last-name>\n");
    exit(2);
}

$schoolId = trim($argv[1]);
$email = trim($argv[2]);
$firstName = trim($argv[3]);
$lastName = trim($argv[4] ?? '');
$password = getenv('ADMIN_PASSWORD');
if ($schoolId === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || $firstName === '' || $lastName === ''
    || !$password || strlen($password) < 12) {
    fwrite(STDERR, "Provide a valid school ID, email, first and last name, and ADMIN_PASSWORD of at least 12 characters.\n");
    exit(2);
}

try {
    $pdo = require __DIR__ . '/../config/database.php';
    $pdo->beginTransaction();
    $find = $pdo->prepare('SELECT id FROM users WHERE school_id = :school_id FOR UPDATE');
    $find->execute(['school_id' => $schoolId]);
    $userId = $find->fetchColumn();
    $passwordHash = app_password_hash($password);
    if ($userId) {
        $statement = $pdo->prepare(
            "UPDATE users SET first_name = :first_name, last_name = :last_name,
                    email = :email, role = 'Admin', password_hash = :password_hash
             WHERE id = :id"
        );
        $statement->execute([
            'first_name' => $firstName, 'last_name' => $lastName,
            'email' => $email, 'password_hash' => $passwordHash, 'id' => $userId,
        ]);
        $userId = (int) $userId;
    } else {
        $checkEmail = $pdo->prepare('SELECT id FROM users WHERE email = :email');
        $checkEmail->execute(['email' => $email]);
        if ($checkEmail->fetchColumn()) {
            throw new RuntimeException('That email is already attached to another school ID.');
        }
        $statement = $pdo->prepare(
            "INSERT INTO users (first_name, last_name, school_id, email, role, password_hash)
             VALUES (:first_name, :last_name, :school_id, :email, 'Admin', :password_hash)"
        );
        $statement->execute([
            'first_name' => $firstName, 'last_name' => $lastName,
            'school_id' => $schoolId, 'email' => $email, 'password_hash' => $passwordHash,
        ]);
        $userId = (int) $pdo->lastInsertId();
    }
    $audit = $pdo->prepare(
        "INSERT INTO audit_logs (user_id, action, target_type, target_id)
         VALUES (:user_id, 'admin_provisioned', 'user', :target_id)"
    );
    $audit->execute(['user_id' => $userId, 'target_id' => $userId]);
    $pdo->commit();
    fwrite(STDOUT, "Administrator account created or updated.\n");
} catch (Throwable $exception) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, "Could not provision administrator: " . $exception->getMessage() . "\n");
    exit(1);
}
