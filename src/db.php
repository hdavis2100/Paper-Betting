<?php
declare(strict_types=1);

/**
 *  * Creates a PDO instance in $pdo using /var/www/secure_config/betleague_config.php
 *   * Usage: require __DIR__ . '/db.php';  // then use $pdo
 *    */

$config = require '/var/www/secure_config/betleague_config.php';

$dsn = sprintf(
	  'mysql:host=%s;port=%d;dbname=%s;charset=%s',
	    $config['db']['host'],
	      $config['db']['port'],
	        $config['db']['name'],
		  $config['db']['charset']
);

try {
	  $pdo = new PDO($dsn, $config['db']['user'], $config['db']['pass'], [
		      PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
		          PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
			    ]);
} catch (PDOException $e) {
	  http_response_code(500);
	    echo "DB connection failed: " . htmlspecialchars($e->getMessage());
	    exit;
}

