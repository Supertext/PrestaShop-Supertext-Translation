<?php
// Railway allows only a few volumes per project, so the demo keeps no files between
// deploys: PrestaShop's app/config/parameters.php (database access and secret keys) is
// stored in the database next to the shop.
//   php state.php db           creates the database if missing; prints "host port user name"
//   php state.php get          prints the saved parameters.php (exit 1 if none)
//   php state.php put < file   saves it
//   php state.php domain <host> <ssl 0|1>   points the shop at the current public address
$url = parse_url((string) getenv('DATABASE_URL'));
if (!$url || !in_array($url['scheme'] ?? '', ['mysql', 'mariadb'], true)) {
    fwrite(STDERR, "DATABASE_URL must be a mysql:// URL\n");
    exit(1);
}
$host = $url['host'];
$port = (int) ($url['port'] ?? 3306);
$user = rawurldecode($url['user'] ?? '');
$pass = rawurldecode($url['pass'] ?? '');
$name = getenv('PRESTASHOP_DB_NAME') ?: 'prestashop';
if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
    fwrite(STDERR, "PRESTASHOP_DB_NAME may only contain letters, digits and _\n");
    exit(1);
}

for ($i = 0; ; $i++) {
    try {
        $pdo = new PDO("mysql:host=$host;port=$port;charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        break;
    } catch (PDOException $e) {
        if ($i >= 20) {
            fwrite(STDERR, "Cannot reach MySQL: {$e->getMessage()}\n");
            exit(1);
        }
        sleep(3);
    }
}
$pdo->exec("CREATE DATABASE IF NOT EXISTS `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$pdo->exec("USE `$name`");
$pdo->exec('CREATE TABLE IF NOT EXISTS supertext_demo_state (name VARCHAR(64) PRIMARY KEY, value MEDIUMTEXT NOT NULL)');

switch ($argv[1] ?? '') {
    case 'db':
        echo "$host $port $user $name\n";
        break;
    case 'get':
        $value = $pdo->query("SELECT value FROM supertext_demo_state WHERE name = 'parameters.php'")->fetchColumn();
        if ($value === false) {
            exit(1);
        }
        echo $value;
        break;
    case 'put':
        $value = stream_get_contents(STDIN);
        if (!str_contains($value, "'parameters'")) {
            fwrite(STDERR, "Not a PrestaShop parameters.php\n");
            exit(1);
        }
        $pdo->prepare("REPLACE INTO supertext_demo_state (name, value) VALUES ('parameters.php', ?)")->execute([$value]);
        break;
    case 'domain':
        $prefix = getenv('PRESTASHOP_DB_PREFIX') ?: 'ps_';
        $domain = (string) ($argv[2] ?? '');
        $ssl    = ($argv[3] ?? '1') === '1' ? '1' : '0';
        if (!preg_match('/^[a-z0-9.:-]+$/i', $domain)) {
            fwrite(STDERR, "Invalid domain\n");
            exit(1);
        }
        $pdo->prepare("UPDATE {$prefix}shop_url SET domain = ?, domain_ssl = ?")->execute([$domain, $domain]);
        $pdo->prepare("UPDATE {$prefix}configuration SET value = ? WHERE name IN ('PS_SHOP_DOMAIN', 'PS_SHOP_DOMAIN_SSL')")->execute([$domain]);
        $pdo->prepare("UPDATE {$prefix}configuration SET value = ? WHERE name IN ('PS_SSL_ENABLED', 'PS_SSL_ENABLED_EVERYWHERE')")->execute([$ssl]);
        break;
    default:
        fwrite(STDERR, "usage: state.php db|get|put|domain\n");
        exit(2);
}
