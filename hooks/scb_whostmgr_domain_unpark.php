#!/usr/local/cpanel/3rdparty/bin/php -q 
<?php
include_once "/home/scriptbu/public_html/scriptbu_subdomain/dev/vultr/init.php";
$options["action"] = "whostmgr_domain_unpark";
scb_run($options, $input);;
