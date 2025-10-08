<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $username = trim($_POST['username'] ?? '');
  $email    = trim($_POST['email'] ?? '');
  $pass     = $_POST['password'] ?? '';

  if ($username === '' || !preg_match('/^[A-Za-z0-9_]{3,30}$/', $username)) {
    $errors[] = 'Username must be 3–30 chars (letters, numbers, underscore).';
  }
  if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'Invalid email.';
  }
  if (strlen($pass) < 6) {
    $errors[] = 'Password must be at least 6 characters.';
  }

  if (!$errors) {
    try {
      $pdo->beginTransaction();

      // Create user
      $stmt = $pdo->prepare('INSERT INTO users (username, email, password_hash) VALUES (?,?,?)');
      $stmt->execute([$username, $email, password_hash($pass, PASSWORD_DEFAULT)]);
      $userId = (int)$pdo->lastInsertId();

      // Seed wallet with 10,000
      $pdo->prepare('INSERT INTO wallets (user_id, balance) VALUES (?, 10000.00)')->execute([$userId]);

      $pdo->commit();

      // Log in
      $_SESSION['user'] = ['id' => $userId, 'username' => $username, 'email' => $email];

      header('Location: /sportsbet/public/index.php');
      exit;
    } catch (PDOException $e) {
      $pdo->rollBack();
      // Unique constraints
      $msg = $e->getMessage();
      if (str_contains($msg, 'users.username')) {
        $errors[] = 'Username already taken.';
      } elseif (str_contains($msg, 'users.email')) {
        $errors[] = 'Email already registered.';
      } else {
        $errors[] = 'Registration failed.';
      }
    }
  }
}
include __DIR__ . '/partials/header.php';
?>
<!doctype html>
<html>
<head><meta charset="utf-8"><title>Register</title></head>
<body>
  <h1>Create account</h1>

  <?php foreach ($errors as $err): ?>
    <p style="color:red"><?= htmlspecialchars($err) ?></p>
  <?php endforeach; ?>

  <form method="post" action="">
    <label>Username <input name="username" required></label><br>
    <label>Email <input name="email" type="email" required></label><br>
    <label>Password <input name="password" type="password" required></label><br>
    <button type="submit">Register</button>
  </form>

  <p>Already have an account? <a href="/sportsbet/public/login.php">Log in</a></p>
</body>
</html>
<?php include __DIR__ . '/partials/footer.php'; ?>