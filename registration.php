<?php
require_once __DIR__ . '/config/bootstrap.php';
app_start_session();

if (empty($_SESSION['registration_csrf_token'])) {
    $_SESSION['registration_csrf_token'] = bin2hex(random_bytes(32));
}

$errors = [];
$success = false;
$values = [
    'first' => '',
    'last' => '',
    'school_id' => '',
    'email' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($values as $field => $_) {
        $values[$field] = trim((string) ($_POST[$field] ?? ''));
    }

    if (!hash_equals($_SESSION['registration_csrf_token'], (string) ($_POST['csrf_token'] ?? ''))) {
        $errors[] = 'Your session expired. Please refresh the page and try again.';
    }

    if ($values['first'] === '' || mb_strlen($values['first']) > 100) {
        $errors[] = 'Enter a first name of no more than 100 characters.';
    }
    if ($values['last'] === '' || mb_strlen($values['last']) > 100) {
        $errors[] = 'Enter a last name of no more than 100 characters.';
    }
    if ($values['school_id'] === '' || mb_strlen($values['school_id']) > 50) {
        $errors[] = 'Enter a school ID of no more than 50 characters.';
    }
    if (!filter_var($values['email'], FILTER_VALIDATE_EMAIL) || mb_strlen($values['email']) > 254) {
        $errors[] = 'Enter a valid email address.';
    }
    $password = (string) ($_POST['password'] ?? '');
    $confirm = (string) ($_POST['confirm'] ?? '');
    if (strlen($password) < 8) {
        $errors[] = 'Your password must be at least 8 characters long.';
    }
    if ($password !== $confirm) {
        $errors[] = 'The passwords do not match.';
    }
    if (empty($_POST['terms'])) {
        $errors[] = 'You must agree to the reservation rules.';
    }

    if (!$errors) {
        try {
            $pdo = app_db();
            $limit = max(1, (int) (getenv('REGISTRATION_ATTEMPT_LIMIT') ?: 5));
            $window = max(60, (int) (getenv('REGISTRATION_ATTEMPT_WINDOW') ?: 3600));
            if (!app_rate_limit($pdo, 'registration', $limit, $window)) {
                http_response_code(429);
                $errors[] = 'Too many registration attempts. Please wait and try again.';
            } else {
                $pdo->beginTransaction();
                $statement = $pdo->prepare(
                    'INSERT INTO users (first_name, last_name, school_id, email, role, password_hash)
                     VALUES (:first_name, :last_name, :school_id, :email, \'Student\', :password_hash)'
                );
                $statement->execute([
                    'first_name' => $values['first'],
                    'last_name' => $values['last'],
                    'school_id' => $values['school_id'],
                    'email' => $values['email'],
                    'password_hash' => app_password_hash($password),
                ]);
                $newUserId = (int) $pdo->lastInsertId();
                app_audit($pdo, $newUserId, 'registration_success', 'user', $newUserId);
                $pdo->commit();
                $success = true;
                app_rate_limit_reset($pdo, 'registration');
                $_SESSION['registration_csrf_token'] = bin2hex(random_bytes(32));
            }
        } catch (PDOException $exception) {
            if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($exception->getCode() === '23000') {
                $errors[] = 'That school ID or email is already registered.';
            } else {
                error_log('Registration database error: ' . $exception->getMessage());
                $errors[] = 'We could not create your account right now. Please try again later.';
            }
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
<title>School Book-ol | Create account</title>
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
    border-right: 10px solid #b98a52;
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

  .places h2 {
    font-family: var(--display);
    font-weight: 600;
    font-size: 1.15rem;
    margin-bottom: 14px;
  }

  .places ul {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    list-style: none;
    max-width: 460px;
  }

  .places li {
    padding: 8px 16px;
    border: 1.5px dashed rgba(242, 240, 230, 0.4);
    border-radius: 999px;
    font-size: 0.95rem;
  }

  .places li.pick {
    border: 1.5px solid var(--pencil);
    background: var(--pencil);
    color: var(--board-deep);
    font-weight: 600;
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
    max-width: 440px;
  }

  .card h2 {
    font-family: var(--display);
    font-weight: 600;
    font-size: 2rem;
    letter-spacing: -0.02em;
  }

  .card .lead {
    margin: 8px 0 28px;
    color: var(--muted);
    line-height: 1.5;
  }

  .field { margin-bottom: 18px; }

  .two {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
  }

  label {
    display: block;
    margin-bottom: 6px;
    font-weight: 600;
    font-size: 0.95rem;
  }

  input[type="text"],
  input[type="email"],
  input[type="password"],
  select {
    width: 100%;
    padding: 13px 14px;
    font: inherit;
    color: var(--ink);
    background: #fff;
    border: 1.5px solid var(--line);
    border-radius: 8px;
    transition: border-color 0.15s, box-shadow 0.15s;
  }

  select {
    appearance: none;
    padding-right: 40px;
    background-image:
      linear-gradient(45deg, transparent 50%, var(--muted) 50%),
      linear-gradient(135deg, var(--muted) 50%, transparent 50%);
    background-position: calc(100% - 20px) 50%, calc(100% - 14px) 50%;
    background-size: 6px 6px, 6px 6px;
    background-repeat: no-repeat;
  }

  input::placeholder { color: #93a19a; }

  input[type="text"]:focus,
  input[type="email"]:focus,
  input[type="password"]:focus,
  select:focus {
    outline: none;
    border-color: var(--board);
    box-shadow: 0 0 0 4px rgba(31, 59, 51, 0.15);
  }

  .hint {
    margin-top: 6px;
    font-size: 0.85rem;
    color: var(--muted);
  }

  .check {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    margin: 6px 0 26px;
    font-weight: 400;
    font-size: 0.92rem;
    line-height: 1.45;
    cursor: pointer;
  }

  .check input {
    flex: none;
    width: 18px;
    height: 18px;
    margin-top: 2px;
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
    margin-top: 24px;
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
    .places { display: none; }
  }

  @media (max-width: 480px) {
    .two { grid-template-columns: 1fr; gap: 0; }
    .two .field { margin-bottom: 18px; }
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
      <p>School booking online. Create an account to start reserving rooms and places around campus.</p>
    </div>

    <div class="places" aria-hidden="true">
      <h2>What you can book</h2>
      <ul>
        <li>Classrooms</li>
        <li class="pick">Science lab</li>
        <li>Computer lab</li>
        <li>Library room</li>
        <li>Gymnasium</li>
        <li>Auditorium</li>
        <li>Covered court</li>
      </ul>
    </div>
  </section>

  <main class="panel">
    <div class="card">
      <h2>Create your account</h2>
      <p class="lead">It takes a minute. Use your school details so we can confirm who you are.</p>

      <?php if ($success): ?>
        <p role="status">Your account has been created. You can now <a href="index.php">log in</a>.</p>
      <?php else: ?>
        <?php if ($errors): ?>
          <div role="alert" aria-live="polite">
            <ul>
              <?php foreach ($errors as $error): ?>
                <li><?= escape($error) ?></li>
              <?php endforeach; ?>
            </ul>
          </div>
        <?php endif; ?>

      <form method="post" action="registration.php">
        <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['registration_csrf_token']) ?>">
        <div class="two">
          <div class="field">
            <label for="first">First name</label>
            <input type="text" id="first" name="first" placeholder="Juan" autocomplete="given-name" maxlength="100" required value="<?= escape($values['first']) ?>">
          </div>
          <div class="field">
            <label for="last">Last name</label>
            <input type="text" id="last" name="last" placeholder="Dela Cruz" autocomplete="family-name" maxlength="100" required value="<?= escape($values['last']) ?>">
          </div>
        </div>

        <div class="field">
          <label for="school-id">School ID</label>
          <input type="text" id="school-id" name="school_id" placeholder="e.g. 2024-00123" maxlength="50" required value="<?= escape($values['school_id']) ?>">
        </div>

        <div class="field">
          <label for="email">Email</label>
          <input type="email" id="email" name="email" placeholder="you@school.edu" autocomplete="email" maxlength="254" required value="<?= escape($values['email']) ?>">
        </div>

        <div class="two">
          <div class="field">
            <label for="password">Password</label>
            <input type="password" id="password" name="password" placeholder="Create a password" autocomplete="new-password" minlength="8" required>
          </div>
          <div class="field">
            <label for="confirm">Confirm password</label>
            <input type="password" id="confirm" name="confirm" placeholder="Repeat it" autocomplete="new-password" minlength="8" required>
          </div>
        </div>
        <p class="hint" style="margin: -8px 0 18px;">Use at least 8 characters.</p>

        <label class="check" for="terms">
          <input type="checkbox" id="terms" name="terms" required>
          <span>I agree to follow the school's room reservation rules.</span>
        </label>

        <button type="submit">Create account</button>
      </form>
      <?php endif; ?>

      <p class="foot">Already have an account? <a href="index.php">Log in</a></p>
    </div>
  </main>

</body>
</html>