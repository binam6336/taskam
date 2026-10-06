<?php
if (!defined('BASE_URL')) { define('BASE_URL', '/tapin/task'); }
define('DEBUG_MODE', true);
if (DEBUG_MODE) { error_reporting(E_ALL); ini_set('display_errors', 1); }
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.use_strict_mode', 1);
    session_start();
}
