<?php
declare(strict_types=1);

$path = __DIR__ . '/../src/db.php';
if (!is_file($path)) {
	  http_response_code(500);
	    header('Content-Type: text/plain');
	    echo "Cannot find db.php at: {$path}\n";
	      exit;
}

require $path;

if (!isset($pdo) || !$pdo instanceof PDO) {
	  http_response_code(500);
	    header('Content-Type: text/plain');
	    echo "\$pdo is not set. Check src/db.php and /var/www/secure_config/betleague_config.php.\n";
	      exit;
}

$stmt = $pdo->query("SELECT NOW() AS db_time");
$row  = $stmt->fetch();

header('Content-Type: text/plain');
echo "OK\n";
echo "PHP: " . PHP_VERSION . "\n";
echo "DB time: " . $row['db_time'] . "\n";

