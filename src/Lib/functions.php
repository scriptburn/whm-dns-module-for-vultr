<?php

/**
 * Return paramaters passed to script by WHM API Hook
 *
 * @since 1.0.0
 *
 * @param None
 * @return array or boolean
 */
function get_passed_data()
{

    // Get input from STDIN.
    $raw_data = "";
    $stdin_fh = fopen('php://stdin', 'r');
    if (is_resource($stdin_fh))
    {
        stream_set_blocking($stdin_fh, 0);
        while (($line = fgets($stdin_fh, 1024)) !== false)
        {
            $raw_data .= trim($line);
        }
        fclose($stdin_fh);
    }

    // Process and JSON-decode the raw output.
    if ($raw_data)
    {
        $input_data = json_decode($raw_data, true);
    }
    else
    {
        $input_data = false;
    }

    // Return the output.
    return $input_data;
}

/**
 * Log our message to error_log
 *
 * @since 1.0.0
 *
 * @param  string,array ,number $item Error Content.
 * @param  boolean $dump Use var_dump or print_r. Default value is false
 * @return nothing
 */
function scb_log($item, $dump = false)
{
    // only output debug data if debug mode is enabled
    if (!defined('SCB_DEBUG') || !SCB_DEBUG)
    {
        return;
    }

    if ($dump) // do we need to use var_dump tooutput or variable?
    {
        ob_start();
        var_dump($item);
        $item = ob_get_clean();
    }
    elseif (is_array($item))
    {
        ob_start();
        print_r($item);
        $item = ob_get_clean();
    }
    error_log($item);

}

/**
 * Initialize The script config data and store in static var to use later
 *
 * @since 1.0.0
 *
 * @param  mix $configData It can be mix data

 * @return array
 */
function scb_config($configData = null)
{
    static $config;
    if (!is_null($configData))
    {
        $config = $configData;
    }

    return $config;
}

/**
 * Return the config data which was initliazed in static var previoiusly
 *
 * @since 1.0.0
 *
 * @param  string $key Config name to return value of
 * @param  mix $default Defualt value to return incase requested config data was not found. Default is null


 * @return mix
 */
function scb_get_config($key, $default = null)
{
    $config = scb_config();
    $keys   = explode(".", $key);
    foreach ($keys as $key)
    {
        if (isset($config[$key]))
        {
            $config = $config[$key];
        }
        else
        {
            return $default;
        }
    }
    return $config;
}

/**
 * Execute an external coomand return the result
 *
 * @since 1.0.0
 *
 * @param  string $cmd Command to execute

 * @return array $args {
 *     @type int [0] Status Of executed command
 *     @type string [1] Error message if any or command output
 * }
 */
function scb_exec($cmd)
{
    $output     = "";
    $return_var = 0;
    exec($cmd . ' 2>&1', $output, $return_var);

    return [$return_var ? 0 : 1, implode("\n", $output)];
}

/**
 * Return the array of hooks which the script is ready to process as defined in config.php
 *
 * @since 1.0.0
 *

 * @return array Array of hook items
 */

function hooks()
{

    $describes = scb_get_config('describe');

    if (!is_array($describes) || !count($describes))
    {
        return false;
    }

    return $describes;
}

/**
 * Return Our Cpanel API object
 *
 * @since 1.0.0
 *
 * @param  boolean $silent Throw exception in case of error or return as array [status,error mesage]
 * @param  mix $default Defualt value to return incase requested config data was not found. Default is null


 * @return object
 */
function cpanel($silent = false)
{
    static $xmlapi;
    if ($xmlapi)
    {
        return $xmlapi;
    }
    try
    {
        $hash = file_get_contents("/root/.accesshash");
        if ($hash === false)
        {
            scb_log($hash, true);
            throw new \Exception('Unable to read hash');
        }

        $account = "root";
        $xmlapi  = new xmlapi('127.0.0.1');
        $xmlapi->set_output('array');
        $xmlapi->set_user($account);
        $xmlapi->set_hash($hash);
        return $xmlapi;
    }
    catch (\Exception $e)
    {
        if (!$silent)
        {
            throw $e;
        }
        else
        {
            return [0, $e->getMessage()];
        }

    }

}

/**
 * Main function which executes apropriate functions acording to command line arguments
 *
 * @since 1.0.0
 *
 * @param  array $options array of Command line arguments which were passed to script
 * @param  array $input Array of data which was passed by whm to script via STDIN. Default is null


 * @return nothing
 */
function scb_run($options, $input = null)
{

    if (isset($options['install']) || isset($options['uninstall']))
    {

        if (isset($options['install']))
        {
            // Get the  array of hooks which we need to register with WHM
            $items = install();
        }
        elseif (isset($options['uninstall']) || isset($options['remove']))
        {
            // Get the  array of hooks which we previously registred inside WHM
            $items = uninstall();

        }

        // when registering or unregistering a hook inside WHM, It expects Json encoded array of hooks from STDOUT 
        // which WHM will use to register or unregister hooks
        echo json_encode($items);
        exit();
    }
    else
    {

        scb_log($input);
        $action = @$options['action'];
        $silent = isset($options['silent']);

        // this function is not very necessary but i used a seprate function to make it look clean
        // What is the module name which wil be used to handle the WHM hooks
        // this name we defined in config.php in key named 'modules'.Which also holds the API key of module Vultr
        $module=scb_get_config('in_use');
        scb_perform_action($module, $action, $input, $silent);

    }
}

/**
 * Perform requested class action
 *
 * @since 1.0.0
 *
 * @param  string $module Name of Module which handles this action like(vultr.com ).
 * @param  string $action Name of function which will be used to handle this task. Ex:whostmgr_accounts_create
 * @param  array  $input Array of arguments which was passed to script.
 * @param  boolean  $silent Output the data or not. Default is false


 * @return nothing
 */
function scb_perform_action($module, $action, $input, $silent = false)
{

    //call the function which will find the appropriate function from the Module handler class
    $ret  = scb_execute($module, $action, $input);
    $echo = ($ret[0] ? 0 : 1) . " " . $ret[1];
    if (!$silent)
    {
        echo ($echo);
    }

    scb_log($echo);

    if (!$ret[0])
    {

        exit($ret[0]);
    }
    else
    {
        exit;
    }
}

/**
 * Used to send emails
 *
 * @since 1.0.0
 *
 * @param  string $to Email address towhich send the email to
 * @param  string $subject  Subject part of email
 * @param  string  $message Email body


 * @return nothing
 */
function send_email($to, $subject, $message)
{
    $headers   = [];
    $headers[] = 'MIME-Version: 1.0';
    $headers[] .= 'Content-type: text/html; charset=iso-8859-1';
    $headers[] = "X-Mailer: Scriptburn.com/PHP/" . phpversion();

    mail($to, $subject, $message, implode("\r\n", $headers));
}
