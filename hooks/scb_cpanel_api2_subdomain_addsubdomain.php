#!/usr/local/cpanel/3rdparty/bin/php -q 
<?php
include_once "/home/scriptbu/public_html/scriptbu_subdomain/dev/vultr/init.php";
$options["action"] = "cpanel_api2_subdomain_addsubdomain";
scb_run($options, $input);;
