<?php

// Current version no
define('SCB_VERSION', "1.0.0");

// are we in debug mode?
define('SCB_DEBUG', 1);

define('SCB_CPANEL_PATH', '/usr/local/cpanel');

// base path of our script
define('SCB_BASE', __DIR__);

$longopts = array(
    "action:", // Required value
    "category:", // Required value
    "describe", // No value
    "install::", // Optional value
    "remove::", // Optional value
    "uninstall::", // Optional value
    "reinstall::", // Optional value
    "silent::", // Optional value

);

$options = getopt('', $longopts);

// enable error reporting if in debug mode and silent mode is not set in command line arguments
if (defined('SCB_DEBUG') && SCB_DEBUG && empty($options['silent']))
{
    error_reporting(E_ALL);
    @ini_set('display_errors', 1);
}
else
{
    error_reporting(0);
    @ini_set('display_errors', 0);
}

// load our core functions
require_once "src/Lib/autoload.php";

// return all the data passed to script by cpanel
$input = get_passed_data();

// initiliaze our configuration data
scb_config(include_once ("config.php"));

// load curl library
require_once "src/Curl/autoload.php";

// load vultr API module
require_once "src/Providers/autoload.php";

// load cpanel API module
require_once "src/Cpanel/autoload.php";
