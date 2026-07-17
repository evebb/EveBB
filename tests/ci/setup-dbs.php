<?php

/**
 * CI helper: create the secondary test databases and grants that the
 * GitHub Actions service containers don't create on their own.
 * Expects the MariaDB and PostgreSQL services from .github/workflows/ci.yml.
 */

$my = new PDO('mysql:host=127.0.0.1', 'root', 'rootpass');
$my->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$my->exec('CREATE DATABASE IF NOT EXISTS evebb_test CHARACTER SET utf8mb4');
$my->exec("GRANT ALL ON evebb_test.* TO 'flux'@'%'");
$my->exec("GRANT ALL ON evebb_e2e.* TO 'flux'@'%'");
$my->exec('FLUSH PRIVILEGES');

$pg = new PDO('pgsql:host=127.0.0.1;dbname=evebb_e2e', 'flux', 'fluxpass');
$pg->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$exists = $pg->query("SELECT 1 FROM pg_database WHERE datname = 'evebb_test'")->fetchColumn();
if (!$exists)
	$pg->exec('CREATE DATABASE evebb_test');

echo "databases ready\n";
