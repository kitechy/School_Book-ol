<?php
require_once __DIR__ . '/config/bootstrap.php';
app_start_session();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: dashboard.php');
    exit;
}

$submittedToken = (string) ($_POST['csrf_token'] ?? '');
$loginToken = (string) ($_SESSION['login_csrf_token'] ?? '');
$appToken = (string) ($_SESSION['csrf_token'] ?? '');

if (
    ($loginToken === '' || !hash_equals($loginToken, $submittedToken))
    && ($appToken === '' || !hash_equals($appToken, $submittedToken))
) {
    http_response_code(403);
    exit('Invalid logout request. Please return to the dashboard and try again.');
}

$userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
if ($userId !== null) {
    try {
        app_audit(app_db(), $userId, 'logout', 'user', $userId);
    } catch (PDOException $exception) {
        error_log('Logout audit error: ' . $exception->getMessage());
    }
}

$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $cookie = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires' => time() - 42000,
        'path' => $cookie['path'],
        'domain' => $cookie['domain'],
        'secure' => $cookie['secure'],
        'httponly' => $cookie['httponly'],
        'samesite' => $cookie['samesite'] ?? 'Lax',
    ]);
}

session_destroy();
header('Location: index.php');
exit;
