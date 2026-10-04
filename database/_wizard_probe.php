<?php
/**
 * Subprocess: drives the §43 sign-up scenario through the real wizard.
 * setup.php ends in redirect()+exit, so this must be its own process.
 */

$root = 'C:/xampp/htdocs/Beauty salon';

session_id('accept43');
session_start();
$_SESSION['csrf_token'] = str_repeat('s', 64);

$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = [
    'csrf_token'       => $_SESSION['csrf_token'],
    'salon_name'        => 'GOSCHA Beauty Salon',
    'owner_first_name' => 'Betty',
    'owner_last_name'  => 'Owner',
    'first_name'        => 'Betty',
    'last_name'         => 'Admin',
    'email'             => 'betty@goscha.test',
    'phone'             => '0911000000',
    'password'          => 'Betty@12345',
    'password_confirm'  => 'Betty@12345',
    /* Mon-Fri 09:00-18:00, Sat 10:00-16:00, Sun closed */
    'setup_hours' => [
        1 => ['is_open' => 1, 'opening_time' => '09:00', 'closing_time' => '18:00'],
        2 => ['is_open' => 1, 'opening_time' => '09:00', 'closing_time' => '18:00'],
        3 => ['is_open' => 1, 'opening_time' => '09:00', 'closing_time' => '18:00'],
        4 => ['is_open' => 1, 'opening_time' => '09:00', 'closing_time' => '18:00'],
        5 => ['is_open' => 1, 'opening_time' => '09:00', 'closing_time' => '18:00'],
        6 => ['is_open' => 1, 'opening_time' => '10:00', 'closing_time' => '16:00'],
        7 => ['is_open' => 0, 'opening_time' => '', 'closing_time' => ''],
    ],
];

require $root . '/signup.php';