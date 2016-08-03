<?php
namespace Scriptburn\Dns;

class Vultr extends Base
{
    private $api_endpoint = 'https://api.vultr.com/v1/dns/';

    // Return our curl instance
    public function getCurl()
    {
        $curl = parent::getCurl();
        $curl->setHeader('API-Key', $this->config['APP_KEY']);
        return $curl;
    }

    // get called when a new domain get created
    public function whostmgr_accounts_create()
    {

        list($context, $data, $hook) = func_get_args();

        // verify the received data from whm after account is created
        $packet = $this->prepare_create($data);

        // send our APi command to vultr to create new domain entry
        $response = $this->send_api_post('create_domain', ['domain' => $packet['domain'], 'serverip' => $packet['ip']]);

        $failed       = []; // holds no of records failed to add to vultr
        $without_dups = []; //store unique dns records
        foreach ($packet['items'] as $index => $item)
        {

            // create our  packet to send to vultr
            $data = ['domain' => $packet['domain'],
                'name'            => $item['name'],
                'data'            => $item['record'],
                'type'            => $item['type'],
            ];

            // this record have ttl? then store it in packet
            if (!empty($item['ttl']))
            {
                $data['ttl'] = $item['ttl'];
            }

            if (isset($item['preference']) && ($item['type'] == 'MX' || $item['type'] == 'SRV'))
            {
                $data['priority'] = $item['preference'];
            }
            if ($item['type'] == 'NS' && empty($item['record']))
            {
                $data['data'] = $item['nsdname'];
            }
            if ($item['type'] == 'MX' && empty($item['record']))
            {
                $data['data'] = $item['exchange'];
            }
            // whm includes trailing dot in record's name part which vultr do not likes so strip it
            if (substr($data['name'], strlen($data['name']) - 1) == '.')
            {
                $data['name'] = substr($data['name'], 0, strlen($data['name']) - 1);
            }
            if (empty($without_dups[$data['type']][$data['name']]))
            {
                $without_dups[$data['type']][$data['name']] = $data;
            }

        }

        // loop through all unique dns records  received from whm for newly created domain and add it to vultr
        foreach ($without_dups as $type => $items)
        {
            foreach ($items as $item)
            {
                try
                {
                    // send our API command
                    $response = $this->send_api_post('create_record', $item);
                }
                catch (\Exception $e)
                {
                    // skip duplicate record errors from vultr api response and store other errors
                    if (stripos($e->getMessage(), 'Duplicate records not allowed') === false)
                    {
                        $failed[] = $e->getMessage();
                    }
                }
            }
        }

        // throw error just to notify that few dns records were unable to add to vultr
        if (count($failed))
        {
            throw new \Exception('Unable to add few DNS records for domain:' . $packet['domain'] . "\n" . implode("\n", $failed));
        }

    }

    // get called when a  domain get deleted
    public function whostmgr_accounts_remove()
    {
        list($context, $data, $hook) = func_get_args();

        // only continue if the domain was delete from whm with remove dns zone file option set
        if (isset($data['killdns']) && $data['killdns'])
        {

            // verify the response data received from WHM hook
            $packet = $this->prepare_remove($data);

            // send our delete domain API command to vultr
            $response = $this->send_api_post('delete_domain', ['domain' => $packet['domain']]);
        }
    }
    // get called when a  domain is parked
    public function whostmgr_domain_park()
    {
        list($context, $data, $hook) = func_get_args();
        $data['domain']              = @$data['new_domain'];
        // we can use same function which we use when a new domain was created
        // basically parking a domain is almost same as creating a new domain
        $this->whostmgr_accounts_create($context, $data, $hook);
    }

    // get called when a  domain is unparked
    public function whostmgr_domain_unpark()
    {
        list($context, $data, $hook) = func_get_args();

        // make sure to add kill dns parameter otherwise whostmgr_accounts_remove function will not remove the unparked
        //domain from vultr
        $data['killdns'] = 1;

        // we can use same function which we use when a  domain was deleted
        // basically unparking a domain is almost same as deleting a domain
        $this->whostmgr_accounts_remove($context, $data, $hook);
    }

    // get called when a  new subdomain is created
    public function cpanel_api2_subdomain_addsubdomain()
    {
        list($context, $data, $hook) = func_get_args();

        // process and verify the data received from whm create new subdomain hook
        $packet = $this->prepare_add_subdomain($data);

        // send our API command to vultr to create a new A record for newly created subdomain
        $response = $this->send_api_post('create_record', $packet);

    }

    // get called when a subdomain is deleted
    public function cpanel_api2_subdomain_delsubdomain()
    {
        list($context, $data, $hook) = func_get_args();

        // process and verify the data received from whm delete subdomain hook
        $packet = $this->prepare_del_subdomain($data);

        //in order to delete any record from vultr we need it's  RECORDID
        $response = $this->find_records($packet['domain'], ['type' => 'A', 'name' => $packet['subdomain']]);

        // abort of unable to find RECORDID
        if (!isset($response[0]['RECORDID']))
        {
            throw new \Exception('Unable to find Subdomain record to delete "' . $packet['subdomain'] . '"');

        }

        // send or API command to vultr to remove A record for the deleted subdomain
        $response = $this->send_api_post('delete_record', ['domain' => $packet['domain'], 'RECORDID' => $response[0]['RECORDID']]);

    }

    // find all or selected record of a domain name from vultr
    private function find_records($domain, $input = null)
    {
        $items = [];

        // get all records of the domain name
        $response = $this->send_api_get('records', ['domain' => $domain]);
        if (!is_array($response))
        {
            return $items;
        }
        $need_to_find = is_array($input) && isset($input['type']);

        // loop through all records in case we asked to find a specfic kind of record(A,NS,NS)
        foreach ($response as $item)
        {
            $item = (array) $item;

            //  are we looking for any specfic record
            if (!$need_to_find)
            {
                $items[] = $item;
                continue;
            }

            // if it is not the records type we are looking for skip
            if (strtoupper($item['type']) != strtoupper($input['type']))
            {
                continue;
            }

            //  we are no looking for a record with specfic name (domain.example.com etc)
            if (!isset($input['name']))
            {
                $items[] = $item;
                continue;
            }

            // we are looking for a specfic record with this name
            if ($input['name'] == $item['name'])
            {
                // do we need to match its value too?
                if (isset($input['value']))
                {
                    if ($input['value'] = $item['data'])
                    {
                        $items[] = $item;
                    }
                }
                else
                {
                    $items[] = $item;
                }

            }

        }

        return $items;
    }

    // send the API request to vultr which expects a post request
    private function send_api_post($action, $data = null)
    {
        $curl = $this->getCurl();
        $curl->post($this->api_endpoint . $action, $data);

        // sleep for half a second to avoid sending too many requests in short time
        // otherwise vultr will throw error
        usleep(500000);

        // validate and send response
        return $this->validate_api_response($curl);

    }

    // sned the API request to vultr which expects a get request
    private function send_api_get($action, $data = null)
    {
        $curl = $this->getCurl();
        $curl->get($this->api_endpoint . $action, $data);

        // sleep for half a second to avoid sending too many requests in short time
        // otherwise vultr will throw error
        usleep(500000);

        // validate and send response
        return $this->validate_api_response($curl);

    }

    private function validate_api_response($curl)
    {
        $errors['400'] = 'Invalid API location. Check the URL that you are using.';
        $errors['403'] = 'Invalid or missing API key. Check that your API key is present and matches your assigned key.';
        $errors['405'] = 'Invalid HTTP method. Check that the method (POST|GET) matches what the documentation indicates.';
        $errors['412'] = 'Request failed. Check the response body for a more detailed description.';
        $errors['500'] = 'Internal server error. Try again at a later time.';
        $errors['503'] = 'Rate limit hit. API requests are limited to an average of 2/s. Try your request again later.';

        // is there was any error in API response?
        if ($curl->error)
        {
            // loop through all errors and translate the error to human redable form
            foreach ($errors as $code => $error)
            {
                if (stripos($curl->responseHeaders['status-line'], " " . $code . " ") !== false)
                {
                    // if error status code is 412 we need to find more detail about error fromresponse body
                    if ($code == "412")
                    {
                        $error = "Request failed(" . strip_tags(nl2br($curl->response)) . ")";
                    }
                    throw new \Exception('API Error: ' . $error);
                }
            }
            throw new \Exception($curl->errorMessage);
        }
        else
        {
            return $curl->response;
        }
    }

}
