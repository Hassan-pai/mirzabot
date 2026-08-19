<?php
declare(strict_types=1);
/**
 * Runs the repository's own schema builder (table.php) non-interactively.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
$appDir = rtrim(getenv('APP_DIR') ?: '/var/www/html', '/');
chdir($appDir);

date_default_timezone_set(getenv('TZ') ?: 'Asia/Tehran');
ini_set('display_errors', '0');
ini_set('memory_limit', '512M');
set_time_limit(0);
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING);

function say(string $m): void { fwrite(STDOUT, "[mirza][migrate] {$m}\n"); }

require_once $appDir . '/config.php';
if (is_file($appDir . '/vendor/autoload.php')) {
    require_once $appDir . '/vendor/autoload.php';
}
foreach (['botapi.php', 'jdf.php', 'function.php'] as $dep) {
    if (is_file($appDir . '/' . $dep)) { require_once $appDir . '/' . $dep; }
}

say('running table.php …');
try {
    require_once $appDir . '/table.php';
} catch (Throwable $t) {
    fwrite(STDERR, '[mirza][migrate][error] ' . $t->getMessage() . "\n");
}

/* --------------------------------------------------------------- verification */
$core = ['user', 'setting', 'admin', 'marzban_panel', 'invoice'];
$have = $pdo->query(
    'SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE()'
)->fetchAll(PDO::FETCH_COLUMN) ?: [];
$have = array_map('strtolower', $have);
$missing = array_values(array_diff($core, $have));

say('tables in schema: ' . count($have));

if ($missing !== []) {
    fwrite(STDERR, '[mirza][migrate][error] missing core tables: '
        . implode(', ', $missing) . "\n");
    exit(1);
}

/* -------------------------------------------------- web panel credentials ---- */
if (strtolower((string) (getenv('SHOW_PANEL_CREDENTIALS') ?: 'true')) === 'true') {
    try {
        $row = $pdo->query('SELECT id_admin, username, password FROM admin LIMIT 1')
                   ->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            say('web panel  : ' . rtrim((string) ($domainhosts ?? ''), '/') . '/panel');
            say('panel user : ' . ($row['username'] ?? 'admin'));
            say('panel pass : ' . ($row['password'] ?? '(set from the bot)'));
            say('↑ change it from the bot admin menu, then set SHOW_PANEL_CREDENTIALS=false');
        }
    } catch (Throwable $t) {
        // non fatal
    }
}
say('done');
exit(0);
