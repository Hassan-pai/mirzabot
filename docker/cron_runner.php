<?php
declare(strict_types=1);
/**
 * Supervisor-managed scheduler for cronbot/*.php.
 * Why not plain crond? cron strips the container environment and needs
 * syslog; a small PHP loop keeps everything in one log stream (Railway logs).
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
$appDir  = rtrim(getenv('APP_DIR') ?: '/var/www/html', '/');
$cronDir = $appDir . '/cronbot';
$timeout = max(30, (int) (getenv('CRON_JOB_TIMEOUT') ?: 300));
$logFile = getenv('CRON_LOG') ?: '/dev/stdout';

date_default_timezone_set(getenv('TZ') ?: 'Asia/Tehran');

function say(string $m): void { fwrite(STDOUT, '[mirza][cron] ' . date('H:i:s') . " {$m}\n"); }

/** file => seconds interval, or 'at:HH:MM' for once a day */
$schedule = [
    'croncard.php'            => 60,      // card-to-card payment checks
    'iranpay1.php'            => 60,      // IranPay gateway polling
    'sendmessage.php'         => 60,      // broadcast queue
    'plisio.php'              => 120,     // crypto payments
    'payment_expire.php'      => 300,     // expire unpaid invoices
    'on_hold.php'             => 300,     // on-hold services
    'activeconfig.php'        => 300,     // activate paid configs
    'disableconfig.php'       => 300,     // disable expired configs
    'uptime_panel.php'        => 300,     // panel monitoring
    'uptime_node.php'         => 300,     // node monitoring
    'NoticationsService.php'  => 600,     // expiry / traffic reminders
    'configtest.php'          => 1800,    // trial config cleanup
    'gift.php'                => 3600,    // gifts / rewards
    'lottery.php'             => 3600,    // wheel of luck / lottery
    'expireagent.php'         => 'at:00:05',
    'statusday.php'           => 'at:23:55',   // daily report
    'backupbot.php'           => 'at:03:00',   // mysqldump → Telegram
];

/* ------------------------------------------------------------- env overrides */
foreach (array_filter(array_map('trim', explode(',', (string) getenv('CRON_DISABLE')))) as $off) {
    unset($schedule[$off]);
}
$overrides = json_decode((string) (getenv('CRON_OVERRIDES') ?: '{}'), true);
if (is_array($overrides)) {
    foreach ($overrides as $file => $spec) {
        if (isset($schedule[$file]) || is_file($cronDir . '/' . $file)) {
            $schedule[$file] = is_numeric($spec) ? (int) $spec : (string) $spec;
        }
    }
}

$schedule = array_filter(
    $schedule,
    static fn ($spec, $file) => is_file($cronDir . '/' . $file),
    ARRAY_FILTER_USE_BOTH
);

say('scheduler started with ' . count($schedule) . ' jobs');
foreach ($schedule as $file => $spec) {
    say(sprintf('  · %-24s %s', $file, is_int($spec) ? "every {$spec}s" : $spec));
}

$last    = [];   // file => timestamp of last start
$running = [];   // file => ['proc'=>resource,'started'=>int]

/* --------------------------------------------------------------- main loop -- */
while (true) {
    $now = time();

    // reap finished jobs
    foreach ($running as $file => $job) {
        $status = proc_get_status($job['proc']);
        if ($status && $status['running'] === false) {
            proc_close($job['proc']);
            unset($running[$file]);
            $code = $status['exitcode'];
            $took = $now - $job['started'];
            if ($code !== 0) {
                say("{$file} finished with exit code {$code} after {$took}s");
            }
        }
    }

    foreach ($schedule as $file => $spec) {
        if (isset($running[$file])) { continue; }   // no overlap

        $due = false;
        if (is_int($spec)) {
            $due = !isset($last[$file]) || ($now - $last[$file]) >= $spec;
        } elseif (str_starts_with((string) $spec, 'at:')) {
            [$h, $m] = array_pad(explode(':', substr((string) $spec, 3)), 2, '0');
            $target  = mktime((int) $h, (int) $m, 0);
            $due     = $now >= $target && ($last[$file] ?? 0) < $target;
        }
        if (!$due) { continue; }

        $cmd = sprintf(
            'timeout -k 10 %d %s %s',
            $timeout,
            escapeshellarg(PHP_BINARY),
            escapeshellarg($file)
        );
        $desc = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', $logFile, 'a'],
            2 => ['file', $logFile, 'a'],
        ];
        $proc = proc_open($cmd, $desc, $pipes, $cronDir);
        if (is_resource($proc)) {
            $running[$file] = ['proc' => $proc, 'started' => $now];
            $last[$file]    = $now;
        } else {
            say("could not start {$file}");
        }
    }

    sleep(5);
}
