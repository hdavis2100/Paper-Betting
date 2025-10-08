<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';


$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $userOrEmail = trim($_POST['username_or_email'] ?? '');
  $pass        = $_POST['password'] ?? '';

  if ($userOrEmail === '' || $pass === '') {
    $errors[] = 'Please enter your username/email and password.';
  } else {
    $stmt = $pdo->prepare('SELECT id, username, email, password_hash FROM users WHERE username = ? OR email = ? LIMIT 1');
    $stmt->execute([$userOrEmail, $userOrEmail]);
    $u = $stmt->fetch();

    if ($u && password_verify($pass, $u['password_hash'])) {
      $_SESSION['user'] = ['id' => (int)$u['id'], 'username' => $u['username'], 'email' => $u['email']];
      header('Location: /sportsbet/public/index.php');
      exit;
    } else {
      $errors[] = 'Invalid credentials.';
    }
  }
}
include __DIR__ . '/partials/header.php';
?>
<!doctype html>
<html>
<head><meta charset="utf-8"><title>Login</title></head>
<body>
  <h1>Login</h1>
  <?php foreach ($errors as $err): ?>
    <p style="color:red"><?= htmlspecialchars($err) ?></p>
  <?php endforeach; ?>

  <form method="post" action="">
    <label>Username or Email <input name="username_or_email" required></label><br>
    <label>Password <input name="password" type="password" required></label><br>
    <button type="submit">Login</button>
  </form>

  <p>No account? <a href="/sportsbet/public/register.php">Create one</a></p>
</body>
</html>
