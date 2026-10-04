<?php
/**
 * §43 acceptance test — subprocess launcher.
 *
 * The test itself (test_signup_acceptance.php) drives the real sign-up wizard
 * through a child process, because setup.php ends in redirect()+exit. This
 * file exists only so the .bat wrapper can hand the scratch database name on
 * through the environment.
 */

$bat = __DIR__ . '/_wizard_probe.bat';
$db  = $argv[1] ?? '';

if ($db === '') {
    fwrite(STDERR, "usage: _wizard_launcher.php <dbname>\n");
    exit(2);
}

$out  = [];
$code = 0;
exec('cmd /c call "' . $bat . '" ' . escapeshellarg($db) . ' 2>&1', $out, $code);

fwrite(STDOUT, implode("\n", $out) . "\n");