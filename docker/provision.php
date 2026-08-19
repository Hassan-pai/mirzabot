<?php
declare(strict_types=1);
/**
 * MirzaBot on Railway – database provisioning & config.php renderer.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * 1. resolves connection settings from env (DB_URL / MYSQL* / DB_*)
 * 2. waits for the MySQL service to accept connections
 * 3. creates the database when it is missing
 * 4. creates a least-privilege application user (see NOTE below)
 * 5. renders <app>/config.php from environment variables (no hardcoded secrets)
 *
 * NOTE: table.php checks `information_schema.tables WHERE table_name = ?`
 * without filtering by table_schema. Connecting as root would expose
 * `mysql.user` and make the bot believe its own `user` table already exists.
 * A dedicated, database-scoped user avoids that completely.
 */

function env_(string $key, ?string $default = null): ?string
{
    $v = getenv($key);
    return ($v === false || $v === '') ? $default : $v;
}
function out(string $m): void  { fwrite(STDOUT, "[mirza][db] {$m}\n"); }
function warn_(string $m): void { fwrite(STDERR, "[mirza][db][warn] {$m}\n"); }
function fail(string $m): never { fwrite(STDERR, "[mirza][db][fatal] {$m}\n"); exit(1); }

$appDir = rtrim(env_('APP_DIR', '/var/www/html'), '/');
$home   = rtrim(env_('MIRZA_HOME', '/opt/mirza'), '/');

/* ------------------------------------------------ 1. connection settings ---- */
$host = null; $port = 3306; $name = null; $user = null; $pass = null;

foreach (['DB_URL', 'DATABASE_URL', 'MYSQL_URL', 'MYSQL_PUBLIC_URL'] as $key) {
    $url = env_($key);
    if ($url === null) { continue; }
    $p = @parse_url($url);
    if (!$p || empty($p['host'])) { continue; }
    $host = $p['host'];
    $port = (int) ($p['port'] ?? 3306);
    $user = isset($p['user']) ? rawurldecode((string) $p['user']) : null;
    $pass = isset($p['pass']) ? rawurldecode((string) $p['pass']) : null;
    $name = isset($p['path']) ? trim((string) $p['path'], '/') : null;
    out("connection details parsed from {$key}");
    break;
}

$host = env_('DB_HOST', env_('MYSQLHOST', env_('MYSQL_HOST', $host)));
$port = (int) env_('DB_PORT', env_('MYSQLPORT', env_('MYSQL_PORT', (string) $port)));
$name = env_('DB_NAME', env_('MYSQLDATABASE', env_('MYSQL_DATABASE', $name ?: 'mirzabot')));
$user = env_('DB_USER', env_('MYSQLUSER', env_('MYSQL_USER', $user ?: 'root')));
$pass = env_('DB_PASS', env_('DB_PASSWORD', env_('MYSQLPASSWORD',
        env_('MYSQL_ROOT_PASSWORD', $pass ?? ''))));

if (!$host)  { fail('no database host found. Set DB_URL=${{MySQL.MYSQL_URL}} or DB_HOST/DB_USER/DB_PASS.'); }
if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', (string) $name)) {
    fail('DB_NAME may only contain letters, digits and underscores');
}
if ($port !== 3306) {
    warn_("database port is {$port} (not 3306). Prefer the *internal* Railway host "
        . '(mysql.railway.internal:3306) – some legacy modules ignore the port.');
}

/* --------------------------------------------------- 2. wait for the server -- */
$attempts = max(1, (int) env_('DB_WAIT_ATTEMPTS', '30'));
$delay    = max(1, (int) env_('DB_WAIT_DELAY', '3'));
$admin    = null;

for ($i = 1; $i <= $attempts; $i++) {
    try {
        $admin = new PDO(
            sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $host, $port),
            $user,
            $pass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]
        );
        break;
    } catch (PDOException $e) {
        out("waiting for mysql ({$i}/{$attempts}): " . $e->getMessage());
        sleep($delay);
    }
}
if (!$admin instanceof PDO) {
    fail("cannot reach mysql at {$host}:{$port} as '{$user}'. Check the MySQL service and variables.");
}
out("connected to {$host}:{$port} as '{$user}'");

/* ------------------------------------------------------------ 3. database ---- */
try {
    $admin->exec("CREATE DATABASE IF NOT EXISTS `{$name}` "
        . 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    out("database `{$name}` is ready");
} catch (PDOException $e) {
    $exists = $admin
        ->query('SELECT SCHEMA_NAME FROM information_schema.schemata WHERE SCHEMA_NAME = '
            . $admin->quote($name))
        ->fetchColumn();
    if (!$exists) {
        fail("database `{$name}` is missing and could not be created: " . $e->getMessage());
    }
    out("database `{$name}` exists (no CREATE privilege – fine)");
}

/* -------------------------------------------- 4. least-privilege app user ---- */
$appUser = $user;
$appPass = (string) $pass;

if (strtolower((string) env_('DB_LEAST_PRIVILEGE', 'true')) === 'true') {
    $candidate = (string) env_('DB_APP_USER', 'mirza_app');
    $secret    = (string) env_('DB_APP_PASS', bin2hex(random_bytes(16)));

    if (!preg_match('/^[A-Za-z0-9_]{1,32}$/', $candidate)) {
        warn_('DB_APP_USER is invalid – keeping the admin credentials');
    } elseif ($candidate === $user) {
        out('DB_APP_USER equals DB_USER – nothing to create');
    } else {
        $ident = "'{$candidate}'@'%'";
        $quoted = $admin->quote($secret);
        try {
            try {
                $admin->exec("CREATE USER IF NOT EXISTS {$ident} "
                    . "IDENTIFIED WITH mysql_native_password BY {$quoted}");
                $admin->exec("ALTER USER {$ident} "
                    . "IDENTIFIED WITH mysql_native_password BY {$quoted}");
            } catch (PDOException $e) {
                // MariaDB / older servers
                $admin->exec("CREATE USER IF NOT EXISTS {$ident} IDENTIFIED BY {$quoted}");
                $admin->exec("ALTER USER {$ident} IDENTIFIED BY {$quoted}");
            }
            $admin->exec("GRANT ALL PRIVILEGES ON `{$name}`.* TO {$ident}");
            $admin->exec('FLUSH PRIVILEGES');

            // verify before we commit to it
            $probe = new PDO(
                sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $name),
                $candidate,
                $secret,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]
            );
            $probe->query('SELECT 1');
            $appUser = $candidate;
            $appPass = $secret;
            out("application user '{$candidate}' is ready (scoped to `{$name}`)");
        } catch (PDOException $e) {
            warn_('could not create the scoped user (' . $e->getMessage()
                . ') – falling back to the admin credentials');
        }
    }
}

/* ------------------------------------------ 4b. information_schema sanity ---- */
try {
    $check = new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $name),
        $appUser,
        $appPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $leaks = (int) $check->query(
        "SELECT COUNT(*) FROM information_schema.tables
          WHERE table_name IN ('user','setting','admin') AND table_schema <> DATABASE()"
    )->fetchColumn();
    if ($leaks > 0) {
        warn_('this DB user can see tables named user/setting/admin in OTHER schemas; '
            . 'table.php may skip creating them. Set DB_LEAST_PRIVILEGE=true or use a dedicated user.');
    }
} catch (PDOException $e) {
    fail('the effective application credentials do not work: ' . $e->getMessage());
}

/* ---------------------------------------------------- 5. bot identity vars --- */
$token = (string) env_('BOT_TOKEN', '');
$adminId = (string) env_('ADMIN_CHAT_ID', '');
$domain = rtrim((string) env_('DOMAIN', ''), '/');
$botUser = ltrim((string) env_('BOT_USERNAME', ''), '@');

if ($token === '' || $adminId === '') { fail('BOT_TOKEN / ADMIN_CHAT_ID are required'); }

if ($botUser === '') {
    $raw = @file_get_contents(
        "https://api.telegram.org/bot{$token}/getMe",
        false,
        stream_context_create(['http' => ['timeout' => 10, 'ignore_errors' => true]])
    );
    $me = is_string($raw) ? json_decode($raw, true) : null;
    if (!empty($me['ok']) && !empty($me['result']['username'])) {
        $botUser = (string) $me['result']['username'];
        out("bot username auto-detected: @{$botUser}");
    } else {
        warn_('could not auto-detect the bot username – set BOT_USERNAME manually');
    }
}

$timeoutEnv = env_('REQUEST_EXEC_TIMEOUT');
$timeoutLiteral = ($timeoutEnv === null || !is_numeric($timeoutEnv))
    ? 'null'
    : (string) (int) $timeoutEnv;

/* -------------------------------------------------- 6. render config.php ----- */
$e = static fn (string $v): string => var_export($v, true);

$config = <<<PHP
<?php
/**
 * GENERATED FILE – DO NOT EDIT.
 * Rendered on every container start by docker/provision.php from the
 * Railway environment variables. Change the variables, not this file.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// Extra timeout for slow VPN panels (null = library default)
\$request_exec_timeout = {$timeoutLiteral};

\$dbhost     = {$e($host)};
\$dbport     = {$e((string) $port)};
\$dbname     = {$e((string) $name)};
\$usernamedb = {$e($appUser)};
\$passworddb = {$e($appPass)};

\$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
];
\$dsn = "mysql:host=\$dbhost;port=\$dbport;dbname=\$dbname;charset=utf8mb4";
try {
    \$pdo = new PDO(\$dsn, \$usernamedb, \$passworddb, \$options);
} catch (\\PDOException \$e) {
    error_log("Database connection failed: " . \$e->getMessage());
    die("error: database connection failed");
}

// Legacy mysqli handle – some older modules still expect \$connect
if (extension_loaded('mysqli')) {
    \$connect = @mysqli_connect(\$dbhost, \$usernamedb, \$passworddb, \$dbname, (int) \$dbport);
    if (\$connect instanceof mysqli) {
        mysqli_set_charset(\$connect, "utf8mb4");
    }
}

\$APIKEY       = {$e($token)};
\$adminnumber  = {$e($adminId)};
\$domainhosts  = {$e($domain)};
\$usernamebot  = {$e($botUser)};
PHP;

$target = $appDir . '/config.php';
if (file_put_contents($target, $config . "\n") === false) {
    fail("cannot write {$target}");
}
@chmod($target, 0640);
@chown($target, 'www-data');
@chgrp($target, 'www-data');
out('config.php rendered from environment variables');

/* --------------------------------------- 7. env file for the mysqldump shim -- */
$envFile = $home . '/db.env';
$lines = [
    'MIRZA_DB_HOST=' . escapeshellarg($host),
    'MIRZA_DB_PORT=' . escapeshellarg((string) $port),
    'MIRZA_DB_NAME=' . escapeshellarg((string) $name),
    'MIRZA_DB_USER=' . escapeshellarg($appUser),
];
file_put_contents($envFile, implode("\n", $lines) . "\n");
@chmod($envFile, 0640);
@chgrp($envFile, 'www-data');
out('provisioning finished');
