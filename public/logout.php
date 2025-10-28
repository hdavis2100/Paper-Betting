<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

$_SESSION = [];
if (ini_get('session.use_cookies')) {
  $p = session_get_cookie_params();
  setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'] ?? false, $p['httponly'] ?? true);
}
session_destroy();

header('Location: ' . app_url('login.php'));
exit;
