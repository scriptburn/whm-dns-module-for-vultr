<?php
include_once "base.php";

function scb_execute($mod, $action, $params)
{
    static $mods;
    try
    {
        $class = 'Scriptburn\\Dns\\' . ucwords($mod);
        if (empty($mods[$mod]))
        {
            $file = __DIR__ . "/" . $mod . "_mod.php";
            if (!file_exists($file))
            {
                throw new Exception('Handler file ' . $file . ' for module ' . $mod . " does not exists", 1);
            }
            else
            {
                include_once $file;
            }
            if (!class_exists($class))
            {
                throw new Exception('Handler for module ' . $mod . " does not exists", 1);
            }

            $mods[$mod] = new $class(scb_get_config('modules.' . $mod));
        }
        if (!method_exists($mods[$mod], $action))
        {
            throw new Exception('Invalid action ' . $action . ' for module ' . $mod . " Handler", 2);
        }

        if (!is_array($params) || is_null($params))
        {
            if (method_exists($mods[$mod], "test" . $action))
            {

                $params = call_user_func_array([$mods[$mod], "test" . $action], []);
            }
        }

        $ret = call_user_func_array([$mods[$mod], $action], ['context' => @$params['context'], 'data' => @$params['data'], 'hook' => @$params['hook']]);
        if (is_array($ret))
        {
            return $ret;
        }
        else
        {
            return [0, "Action $mod:$action performed successfully"];
        }
    }
    catch (Exception $e)
    {
        if (scb_get_config('email_on_error') && scb_get_config('error_email_address'))
        {
            $data['mod']    = $mod;
            $data['action'] = $action;
            $data['params'] = $params;
            $msg            = [];
            $msg[]          = "<h3>" . $e->getMessage() . "</h3>";
            $msg[]          = "<h4>Technical Info</h4>";
            ob_start();
            ob_start();
            echo ("<pre>");
            print_r($data);
            echo ("</pre>");
            $msg[] = ob_get_clean();

            send_email(scb_get_config('error_email_address'), 'API Error Log', implode("</br>", $msg));
        }
        return [$e->getCode(), $e->getMessage()];
    }
}
