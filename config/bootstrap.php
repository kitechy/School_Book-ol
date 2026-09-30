<?php

function app_start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.gc_maxlifetime', '1800');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
    if (isset($_SESSION['last_activity']) && time() - (int) $_SESSION['last_activity'] > 1800) {
        $_SESSION = [];
        session_regenerate_id(true);
    }
    $_SESSION['last_activity'] = time();
}

if (!headers_sent()) {
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; img-src 'self' data:; connect-src 'self'; form-action 'self'; base-uri 'self'; object-src 'none'; frame-ancestors 'none'");
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

set_exception_handler(static function (Throwable $exception): void {
    error_log('Unhandled application error: ' . $exception->getMessage());
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
    }
    echo 'An unexpected error occurred. Please try again later.';
});

function app_db(): PDO
{
    static $pdo;

    if (!$pdo) {
        $pdo = require __DIR__ . '/database.php';
    }

    return $pdo;
}

function app_escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function app_csrf_token(): string
{
    app_start_session();

    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function app_verify_csrf(): void
{
    $sessionToken = (string) ($_SESSION['csrf_token'] ?? '');
    $submittedToken = (string) ($_POST['csrf_token'] ?? '');

    if ($sessionToken === '' || !hash_equals($sessionToken, $submittedToken)) {
        http_response_code(403);
        exit('Invalid or expired request. Please refresh and try again.');
    }
}

function app_rate_limit(PDO $pdo, string $scope, int $limit, int $windowSeconds): bool
{
    $address = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $bucket = hash('sha256', $scope . '|' . $address);
    $pdo->beginTransaction();
    try {
        $insert = $pdo->prepare(
            'INSERT IGNORE INTO rate_limits (bucket, window_started, attempts)
             VALUES (:bucket, CURRENT_TIMESTAMP, 0)'
        );
        $insert->execute(['bucket' => $bucket]);
        $select = $pdo->prepare('SELECT window_started, attempts FROM rate_limits WHERE bucket = :bucket FOR UPDATE');
        $select->execute(['bucket' => $bucket]);
        $row = $select->fetch();
        $elapsed = time() - strtotime($row['window_started']);
        if ($elapsed >= $windowSeconds) {
            $update = $pdo->prepare(
                'UPDATE rate_limits SET window_started = CURRENT_TIMESTAMP, attempts = 1 WHERE bucket = :bucket'
            );
            $update->execute(['bucket' => $bucket]);
            $allowed = true;
        } elseif ((int) $row['attempts'] >= $limit) {
            $allowed = false;
        } else {
            $update = $pdo->prepare('UPDATE rate_limits SET attempts = attempts + 1 WHERE bucket = :bucket');
            $update->execute(['bucket' => $bucket]);
            $allowed = true;
        }
        $pdo->commit();
        return $allowed;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

function app_rate_limit_reset(PDO $pdo, string $scope): void
{
    $bucket = hash('sha256', $scope . '|' . (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    $statement = $pdo->prepare('DELETE FROM rate_limits WHERE bucket = :bucket');
    $statement->execute(['bucket' => $bucket]);
}

function app_require_user(): array
{
    app_start_session();

    if (empty($_SESSION['user_id'])) {
        http_response_code(401);
        exit('Please log in to continue.');
    }

    $statement = app_db()->prepare(
        'SELECT id, first_name, last_name, school_id, email, role
         FROM users WHERE id = :id LIMIT 1'
    );
    $statement->execute(['id' => (int) $_SESSION['user_id']]);
    $user = $statement->fetch();

    if (!$user) {
        $_SESSION = [];
        session_destroy();
        http_response_code(401);
        exit('Please log in to continue.');
    }

    $_SESSION['user'] = $user;
    return $user;
}

function app_require_admin(): array
{
    $user = app_require_user();

    if ($user['role'] !== 'Admin') {
        error_log(sprintf(
            'Admin permission denied: user_id=%d ip=%s',
            (int) $user['id'],
            (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown')
        ));
        http_response_code(403);
        exit('You do not have permission to access this page.');
    }

    return $user;
}

function app_audit(PDO $pdo, int $userId, string $action, string $targetType, ?int $targetId): void
{
    $statement = $pdo->prepare(
        'INSERT INTO audit_logs (user_id, action, target_type, target_id, ip_address)
         VALUES (:user_id, :action, :target_type, :target_id, :ip_address)'
    );
    $statement->execute([
        'user_id' => $userId,
        'action' => $action,
        'target_type' => $targetType,
        'target_id' => $targetId,
        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
    ]);
}
