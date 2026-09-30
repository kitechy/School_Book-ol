<?php
require_once __DIR__ . '/config/bootstrap.php';
app_start_session();

if (!empty($_SESSION['user_id'])) {
    $currentRole = $_SESSION['user']['role'] ?? '';
    header('Location: ' . ($currentRole === 'Admin' ? 'admin/dashboard.php' : 'dashboard.php'));
    exit;
}

if (empty($_SESSION['login_csrf_token'])) {
    $_SESSION['login_csrf_token'] = bin2hex(random_bytes(32));
}

$error = '';
$identifier = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $identifier = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $csrfToken = (string) ($_POST['csrf_token'] ?? '');

    if (!hash_equals($_SESSION['login_csrf_token'], $csrfToken)) {
        $error = 'Your session expired. Please refresh the page and try again.';
    } elseif ($identifier === '' || $password === '') {
        $error = 'Enter your school ID or email and password.';
    } else {
        try {
            $pdo = app_db();
            $loginLimit = max(1, (int) (getenv('LOGIN_ATTEMPT_LIMIT') ?: 10));
            $loginWindow = max(60, (int) (getenv('LOGIN_ATTEMPT_WINDOW') ?: 300));
            if (!app_rate_limit($pdo, 'login', $loginLimit, $loginWindow)) {
                http_response_code(429);
                $error = 'Too many sign-in attempts. Please wait and try again.';
            } else {
                $statement = $pdo->prepare(
                    'SELECT id, first_name, last_name, school_id, email, role, password_hash
                     FROM users WHERE school_id = :identifier LIMIT 1'
                );
                $statement->execute(['identifier' => $identifier]);
                $user = $statement->fetch();

                if (!$user) {
                    $statement = $pdo->prepare(
                        'SELECT id, first_name, last_name, school_id, email, role, password_hash
                         FROM users WHERE email = :identifier LIMIT 1'
                    );
                    $statement->execute(['identifier' => $identifier]);
                    $user = $statement->fetch();
                }

                if ($user && password_verify($password, $user['password_hash'])) {
                    app_rate_limit_reset($pdo, 'login');
                    session_regenerate_id(true);
                    $_SESSION['user_id'] = (int) $user['id'];
                    $_SESSION['user'] = [
                        'first_name' => $user['first_name'],
                        'last_name' => $user['last_name'],
                        'school_id' => $user['school_id'],
                        'email' => $user['email'],
                        'role' => $user['role'],
                    ];
                    $_SESSION['login_csrf_token'] = bin2hex(random_bytes(32));
                    app_audit($pdo, (int) $user['id'], 'login_success', 'user', (int) $user['id']);

                    header('Location: ' . ($user['role'] === 'Admin' ? 'admin/dashboard.php' : 'dashboard.php'));
                    exit;
                }

                $failedLogin = $pdo->prepare(
                    "INSERT INTO audit_logs (user_id, action, target_type, target_id, ip_address)
                     VALUES (NULL, 'login_failed', 'account', NULL, :ip_address)"
                );
                $failedLogin->execute(['ip_address' => $_SERVER['REMOTE_ADDR'] ?? null]);
                $error = 'The school ID, email, or password is incorrect.';
            }
        } catch (PDOException $exception) {
            error_log('Login database error: ' . $exception->getMessage());
            $error = 'We could not sign you in right now. Please try again later.';
        }
    }
}

function escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>School Book-ol | Log in</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,600;12..96,800&family=Figtree:wght@400;500;600&display=swap" rel="stylesheet">
<style>
  :root {
    --board: #1f3b33;
    --board-deep: #16302a;
    --chalk: #f2f0e6;
    --chalk-dim: rgba(242, 240, 230, 0.55);
    --pencil: #f2b632;
    --ink: #1d2a25;
    --muted: #5b6b64;
    --paper: #f6f7f3;
    --line: #c9d3cc;
    --display: "Bricolage Grotesque", "Trebuchet MS", sans-serif;
    --body: "Figtree", "Segoe UI", Arial, sans-serif;
  }

  * { box-sizing: border-box; margin: 0; padding: 0; }

  body {
    min-height: 100vh;
    display: grid;
    grid-template-columns: 1.1fr 1fr;
    font-family: var(--body);
    color: var(--ink);
    background: var(--paper);
  }

  /* Left: chalkboard */
  .board {
    background: var(--board);
    color: var(--chalk);
    padding: 56px 64px;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    gap: 48px;
    border-right: 10px solid #b98a52; /* wooden frame edge */
  }

  .brand h1 {
    font-family: var(--display);
    font-weight: 800;
    font-size: clamp(2.8rem, 5.5vw, 4.6rem);
    line-height: 1;
    letter-spacing: -0.03em;
  }

  .brand h1 span { color: var(--pencil); }

  .brand p {
    margin-top: 16px;
    max-width: 34ch;
    font-size: 1.1rem;
    line-height: 1.5;
    color: var(--chalk-dim);
  }

  /* Decorative weekly schedule */
  .schedule {
    display: grid;
    grid-template-columns: 56px repeat(5, 1fr);
    gap: 8px;
    max-width: 520px;
    font-size: 0.85rem;
    color: var(--chalk-dim);
  }

  .schedule .day { text-align: center; padding-bottom: 4px; }
  .schedule .time { align-self: center; }

  .slot {
    height: 38px;
    border: 1.5px dashed rgba(242, 240, 230, 0.3);
    border-radius: 6px;
  }

  .slot.booked {
    border: 1.5px solid var(--chalk);
    background: rgba(242, 240, 230, 0.12);
  }

  .slot.yours {
    border: 1.5px solid var(--pencil);
    background: var(--pencil);
  }

  /* Right: form */
  .panel {
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 48px 32px;
  }

  .card {
    width: 100%;
    max-width: 400px;
  }

  .card h2 {
    font-family: var(--display);
    font-weight: 600;
    font-size: 2rem;
    letter-spacing: -0.02em;
  }

  .card .lead {
    margin: 8px 0 32px;
    color: var(--muted);
    line-height: 1.5;
  }

  .field { margin-bottom: 20px; }

  label {
    display: block;
    margin-bottom: 6px;
    font-weight: 600;
    font-size: 0.95rem;
  }

  input[type="text"],
  input[type="password"] {
    width: 100%;
    padding: 13px 14px;
    font: inherit;
    color: var(--ink);
    background: #fff;
    border: 1.5px solid var(--line);
    border-radius: 8px;
    transition: border-color 0.15s, box-shadow 0.15s;
  }

  input::placeholder { color: #93a19a; }

  input[type="text"]:focus,
  input[type="password"]:focus {
    outline: none;
    border-color: var(--board);
    box-shadow: 0 0 0 4px rgba(31, 59, 51, 0.15);
  }

  .row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin: 4px 0 28px;
    font-size: 0.92rem;
  }

  .check {
    display: flex;
    align-items: center;
    gap: 8px;
    margin: 0;
    font-weight: 400;
    cursor: pointer;
  }

  .check input {
    width: 18px;
    height: 18px;
    accent-color: var(--board);
  }

  a {
    color: var(--board);
    font-weight: 600;
    text-underline-offset: 3px;
  }

  a:focus-visible,
  button:focus-visible,
  .check input:focus-visible {
    outline: 3px solid var(--pencil);
    outline-offset: 2px;
  }

  button {
    width: 100%;
    padding: 14px;
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

  button:hover { background: var(--board-deep); }

  .foot {
    margin-top: 28px;
    text-align: center;
    color: var(--muted);
    font-size: 0.95rem;
  }

  @media (max-width: 860px) {
    body { grid-template-columns: 1fr; }
    .board {
      padding: 36px 28px;
      gap: 28px;
      border-right: none;
      border-bottom: 10px solid #b98a52;
    }
    .schedule { display: none; }
  }

  @media (prefers-reduced-motion: reduce) {
    * { transition: none !important; }
  }
</style>
</head>
<body>

  <section class="board">
    <div class="brand">
      <h1>School <span>Book-ol</span></h1>
      <p>School booking online. Reserve a classroom, lab, gym, or hall without the paper sign-up sheet.</p>
    </div>

    <div class="schedule" aria-hidden="true">
      <span></span>
      <span class="day">Mon</span><span class="day">Tue</span><span class="day">Wed</span><span class="day">Thu</span><span class="day">Fri</span>

      <span class="time">8 am</span>
      <div class="slot booked"></div><div class="slot"></div><div class="slot booked"></div><div class="slot"></div><div class="slot"></div>

      <span class="time">10 am</span>
      <div class="slot"></div><div class="slot yours"></div><div class="slot"></div><div class="slot booked"></div><div class="slot booked"></div>

      <span class="time">1 pm</span>
      <div class="slot booked"></div><div class="slot"></div><div class="slot"></div><div class="slot"></div><div class="slot booked"></div>

      <span class="time">3 pm</span>
      <div class="slot"></div><div class="slot booked"></div><div class="slot booked"></div><div class="slot"></div><div class="slot"></div>
    </div>
  </section>

  <main class="panel">
    <div class="card">
      <h2>Welcome back</h2>
      <p class="lead">Log in to reserve a room or check your bookings.</p>

      <form method="post" action="index.php">
        <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['login_csrf_token']) ?>">
        <?php if ($error !== ''): ?>
          <p role="alert" aria-live="polite" style="margin-bottom: 18px; color: #a32929;"><?= escape($error) ?></p>
        <?php endif; ?>
        <div class="field">
          <label for="username">School ID or email</label>
          <input type="text" id="username" name="username" placeholder="e.g. 2024-00123" autocomplete="username" required value="<?= escape($identifier) ?>">
        </div>

        <div class="field">
          <label for="password">Password</label>
          <input type="password" id="password" name="password" placeholder="Enter your password" autocomplete="current-password" required>
        </div>

        <div class="row">
          <label class="check" for="remember">
            <input type="checkbox" id="remember" name="remember">
            Keep me logged in
          </label>
          <a href="#">Forgot password?</a>
        </div>

        <button type="submit">Log in</button>
      </form>

      <p class="foot">New here? <a href="registration.php">Create an account</a></p>
    </div>
  </main>

</body>
</html>