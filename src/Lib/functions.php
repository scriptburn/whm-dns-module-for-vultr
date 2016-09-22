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
    $fp = fopen(SCB_BASE . "/log.txt", "a");
    fwrite($fp, $item . "\n");
    fclose($fp);

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

function install($return = false)
{
    try
    {
        $ret = uninstall(false, true);

        if (!$ret[0])
        {
            throw new Exception("Failed to run pre install: " . "\n" . $ret[1]);
        }
        $items = hooks();
        if (!$items)
        {
            throw new Exception("No items to install ");
        }
        $failed = [];
        $passed = [];
        $tosave = [];
        foreach ($items as $index => $item)
        {

            $cmd = make_item($item, 'add');

            if ($cmd)
            {
                if ($cmd['auto_file'])
                {
                    $execstr = '';
                    $execstr .= "#!/usr/local/cpanel/3rdparty/bin/php -q \n";
                    $execstr .= '<?php' . "\n";
                    $execstr .= 'include_once "' . SCB_BASE . '/init.php";' . "\n";
                    $execstr .= '$options["action"] = "' . $cmd['action'] . '";' . "\n";
                    $execstr .= 'scb_run($options, $input);;' . "\n";

                    if (file_put_contents($cmd['hook'], $execstr) === false)
                    {
                        $failed[] = [$cmd['class'], 'Unable to auto generate hook file'];
                    }
                    else
                    {
                        chmod($cmd['hook'], 0755);
                    }
                }

                $exec = $cmd['cmd'];

                $ret = scb_exec($exec);

                if (!$ret[0])
                {
                    $failed[] = [$cmd['class'], $ret[1], $exec];
                }
                else
                {
                    $items[$index]['hook'] = $cmd['hook'];
                    $items[$index]['cmd']  = $cmd['cmd'];
                    $passed[]              = $cmd['class'];
                    $tosave[]              = $items[$index];
                }

            }
            else
            {

                $failed[] = [implode(",", $item), 'Invalid parameter data'];
            }
        }
        $err = '';
        if (count($failed))
        {

            foreach ($failed as $fail)
            {
                $err = $err . "\n" . implode("\n", $fail) . "\n";
            }
            if (count($failed) == count($items))
            {

                $err = 'Failed to Install: ' . $err;
            }
            else
            {
                $err = 'Few items failed to install: ' . $err;
            }
            $err = "\n" . $err;
        }
        $msg = '';
        if (count($passed))
        {
            $msg = "Install completed : \n";
            if (@file_put_contents(SCB_BASE . "/.tmp", serialize($tosave)) === false)
            {
                //uninstall($tosave, false);
                throw new Exception('Unable to save install data');
            }

        }
        $msg = $msg . implode("\n", $passed);
        if ($return)
        {
            return [count($failed) ? 0 : 1, $msg . $err, $items];
        }
        else
        {
            scb_status(count($failed) ? 0 : 1, $msg . $err);
            return true;
        }

    }
    catch (Exception $e)
    {
        if ($return)
        {
            return [0, $e->getMessage()];
        }
        else
        {
            scb_status(0, $e->getMessage());
            return false;
        }

    }

}

function uninstall($to_remove = false, $return = false)
{
    try
    {
        if ($to_remove)
        {

            $items = $to_remove;
        }
        else
        {
            $items = @file_get_contents(SCB_BASE . "/.tmp");

            $items = @unserialize($items);
        }

        if (!is_array($items) || !count($items))
        {
            $msg = 'Nothing to uninstall';
            if ($return)
            {
                return [1, $msg];
            }
            else
            {
                scb_status(1, $msg);
                return false;
            }

        }
        $failed = [];
        $passed = [];
        foreach ($items as $index => $item)
        {

            $item_ret = make_item($item, 'delete');
            //print_r($item);
            if ($item_ret)
            {
                //scb_log($item_ret['cmd']);
                $ret = scb_exec($item_ret['cmd']);

                if (!$ret[0])
                {
                    $failed[] = [$item_ret['class'], $ret[1]];
                }
                else
                {
                    $passed[]                   = $item_ret['class'];
                    $items[$index]['hook']      = $item_ret['hook'];
                    $items[$index]['cmd']       = $item_ret['cmd'];
                    $items[$index]['auto_file'] = $item_ret['auto_file'];
                    if ($item_ret['auto_file'] && file_exists($item_ret['hook']))
                    {
                        @unlink($item_ret['hook']);
                    }
                }
            }
        }
        $err = '';
        if (count($failed))
        {

            foreach ($failed as $fail)
            {
                $err = $err . "\n" . implode("\n", $fail) . "\n";
            }
            if (count($failed) == count($items))
            {

                $err = 'Failed to uninstall: ' . $err;
            }
            else
            {
                $err = 'Few items failed to uninstall: ' . $err;
            }
            $err = "\n" . $err;
        }

        $msg = "Uninstall completed: \n" . implode("\n", $passed);
        if ($return)
        {
            return [1, $msg . $err, $items];
        }
        else
        {
            scb_status(1, $msg . $err);
            return true;
        }
    }
    catch (Exception $e)
    {
        if ($return)
        {
            return [0, $e->getMessage()];
        }
        else
        {
            scb_status(0, $e->getMessage());
            return false;
        }
    }
}
function make_item($item, $action)
{

    $params = [];
    if (!(is_array($item) && isset($item['category']) && isset($item['event']) && isset($item['stage'])))
    {
        return "";
    }
    $valid = ['category', 'event', 'stage', 'escalateprivs', 'blocking'];

    foreach ($item as $key => $data)
    {
        if (in_array($key, $valid))
        {
            $params[] = "$key=$data";
        }
    }
    if (!count($params))
    {
        return "";
    }
    $script = [];

    $parts = array_merge([$item['category']], explode("::", $item['event']));
    foreach ($parts as $part)
    {

        $script[] = strtolower($part);

    }
    if (!empty($item['hook']))
    {
        $script_path = $item['hook'];
    }
    else
    {

        $script_path = SCB_BASE . "/hooks/scb_" . implode("_", $script) . ".php";
    }

    return [
        'cmd'       => "/usr/local/cpanel/bin/manage_hooks $action script " . $script_path . " --" . implode(" --", $params) . " --manual",
        'class'     => implode("::", $parts),
        'hook'      => $script_path,
        'auto_file' => empty($item['hook']),
        'action'    => implode("_", $script),
    ];
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

    $return        = array();
    $stdout        = null;
    $stderr        = null;
    $return['cmd'] = $cmd;
    $proc          = proc_open($cmd, [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes);
    $return['output'] = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $return['error'] = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $return['status'] = proc_close($proc);

    return array($return['status'] ? 0 : 1, $return['status'] ? $return['error'] : $return['output']);
}

function scb_status($status, $msg)
{
    echo (($status ? "Info:" : 'Error:') . $msg . "\n");
}

function describe()
{

    $describes = scb_get_config('describe');

    if (!is_array($describes) || !count($describes))
    {
        return false;
    }

    return $describes;
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
        $module = scb_get_config('in_use');
        scb_perform_action($module, $action, $input, $silent);

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
