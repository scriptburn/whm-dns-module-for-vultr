#!/usr/local/cpanel/3rdparty/bin/php -q
<?php

include_once "init.php";
$options['uninstall'] = '1';
scb_run($options, $input);
