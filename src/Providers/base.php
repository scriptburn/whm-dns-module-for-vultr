<?php
namespace Scriptburn\Dns;

use Curl\Curl as Curl;

class Base
{
    protected $config;

    public function __construct($config = null)
    {
        $this->config = $config;

    }
    public function getCurl()
    {
        return new Curl();
    }

    // verify the received data from whm after account is created
    public function prepare_create($data)
    {
        // get instance of our cpanel APi class
        $cpanel = cpanel();

        // Cannot continue if there is no domain
        if (empty($data['domain']))
        {
            throw new \Exception('No domain name found for new account');

        }
        elseif (empty($data['user'])) //  Cannot continue if there is no owner of that domain
        {
            throw new \Exception('Invalid domain owner');
        }

        // fetch ip associated with this domain which we need topass to vultr api when creating a new domain dns entry there
        $userdata = $cpanel->domainuserdata(isset($data['target_domain']) ? $data['target_domain'] : $data['domain']);

        // validate cpanel api response
        $userdata = $this->checkResponse($userdata);

        // can not contnue of we failed to receive IP
        if (empty($userdata['userdata']['ip']))
        {
            throw new \Exception('Invalid ip of domain: ' . $data['domain']);
        }

        // get all dns records from WHM for the newly created Domain.Which we will push to vultr.com
        $dns = $this->cpanel_domain_dns_records($data['user'], $data['domain']);

        $packet = ['domain' => $data['domain'], 'ip' => $userdata['userdata']['ip'], 'items' => $dns];

        return $packet;
    }
    // verify the received data from whm after account is removed
    public function prepare_remove($data)
    {
        // get instance of our cpanel APi class

        $cpanel = cpanel();

        //  Cannot continue if there is no owner of that domain
        if (empty($data['user']))
        {
            throw new \Exception('No domain owner name found to remove account');

        }

        if (isset($data['domain']))
        {
            // use the passed domain name if found
            $domain_data['data']['main_domain'] = $data['domain'];
        }
        else
        {
            // if domain name is not passed to us from whm we will find the domain associated with that passed username

            $domain_data = $this->cpanel_account_domain($data['user']);

            // abort if we are unable to find domain name associated to  owner
            if (empty($domain_data['data']['main_domain']))
            {
                throw new \Exception("Unable to find domain name of owner:" . $domain_data['data']['main_domain']);
            }
        }

        $packet = ['domain' => $domain_data['data']['main_domain']];

        return $packet;
    }

    // process and verify the data received from whem create new subdomain hook
    public function prepare_add_subdomain($data)
    {
        // make sure we got the all data we expect to receive
        if (!isset($data['output']) || !is_array($data['output']) || !isset($data['user']) || !isset($data['args']['rootdomain']))
        {
            throw new \Exception('Invalid response');

        }

        // loop through the result parameter of response and check if the add subdomain of WHM was successfull
        // otherwise abort
        foreach ($data['output'] as $output)
        {
            if (!$output['result'])
            {
                throw new \Exception('Canceled by response');
            }
        }

        // find the A record detailf of newly added domain name
        // which we will pass to vultr
        $ret = $this->cpanel_domain_dns_records(
            $data['user'],
            $data['args']['rootdomain'], [
                'type' => 'A',
                'name' => $data['args']['domain'] . "." . $data['args']['rootdomain'],
            ]
        );

        // abort if unable tofind A record of the subdomain
        if (!isset($ret[0]))
        {
            throw new \Exception('Unable to find subdomain ' . $data['args']['domain'] . "." . $data['args']['rootdomain'] . ' ip');

        }

        // create our APi packet to send
        $data = ['domain' => $data['args']['rootdomain'],
            'name'            => $ret[0]['name'],
            'data'            => $ret[0]['record'],
            'type'            => $ret[0]['type'],
        ];
        if (!empty($ret[0]['ttl']))
        {
            $data['ttl'] = $ret[0]['ttl'];
        }
        return $data;

    }

    // process and verify the data received from whm delete subdomain hook
    public function prepare_del_subdomain($data)
    {
        // make sure we got the all data we expect to receive

        if (!isset($data['output']) || !is_array($data['output']) || !isset($data['user']) || !isset($data['args']['domain']))
        {
            throw new \Exception('Invalid response');

        }

        // loop through the result parameter of response and check if the add subdomain of WHM was successfull
        // otherwise abort
        foreach ($data['output'] as $output)
        {
            if (!$output['result'])
            {
                throw new \Exception('Canceled by response');
            }
        }
        $domain = explode("_", $data['args']['domain']);
        return ['domain' => $domain[1], 'subdomain' => $domain[0]];

    }

    //find the domain associated with a cpanel account
    public function cpanel_account_domain($account)
    {
        // get instance of our cpanel APi class
        $cpanel = cpanel();

        // send api command to cpanel to get domain details
        return $cpanel->api2_query(
            $account,
            "DomainLookup",
            "getmaindomain",
            array('$CPDATA{\'DOMAIN\'}')
        );

    }

    //find all dns records of a domain name
    public function cpanel_domain_dns_records($account, $domain, $find = null)
    {
        // get instance of our cpanel APi class
        $cpanel = cpanel();

        // send api command to cpanel to get domain dns records
        $ret = $cpanel->api2_query(
            $account,
            "ZoneEdit",
            "fetchzone_records",
            array('domain' => $domain)
        );

        // make sure we we were able to find dns records from cpanel api
        if (!is_array(@$ret['data']) || !count(@$ret['data']))
        {
            return [];
        }

        $dns = []; // store returned dns records

        // only store these types of records as the API return many more entries in result
        $valid_records = ['A', 'AAA', 'CNAME', 'NS', 'TXT', 'MX', 'SRV'];
        $need_to_find  = is_array($find) && isset($find['type']);
        foreach ($ret['data'] as $item)
        {
            // if the record type is a  valid type
            if (isset($item['type']) && in_array(strtoupper($item['type']), $valid_records))
            {
                // remove trailing dot from record name
                if (substr($item['name'], strlen($item['name']) - 1) == '.')
                {
                    $item['name'] = substr($item['name'], 0, strlen($item['name']) - 1);
                }
                if ($need_to_find)
                {
                    if (strtoupper($find['type']) == strtoupper($item['type']))
                    {
                        if (isset($find['name']))
                        {
                            if ($find['name'] == $item['name'])
                            {
                                $dns[] = $item;
                            }
                        }
                        else
                        {
                            $dns[] = $item;
                        }
                    }

                }
                else
                {
                    $dns[] = $item;
                }
            }
        }
        return $dns;
    }

    // validate cpanel api response
    private function checkResponse($response)
    {
        if (!isset($response['result']['status']))
        {
            throw new \Exception('Unknown Api Result');
        }
        elseif (!($response['result']['status']))
        {
            if (empty($response['result']['statusmsg']))
            {
                throw new \Exception('Unknown Error ocured');

            }
            else
            {
                throw new \Exception($response['result']['statusmsg']);
            }
        }
        return $response;
    }

    // test data
    public function testscb_whostmgr_accounts_create($user = null)
    {
        $user = is_null($user) ? 'parkonthis' : $user;
        $arr  = [

            'context' =>
            [
                'stage'    => 'post',
                'point'    => 'main',
                'category' => 'Whostmgr',
                'event'    => 'Accounts::Create',
            ],

            'data'    =>
            [
                'useregns'                  => '',
                'dkim'                      => '',
                'locale'                    => '',
                'maxftp'                    => 'n',
                'max_defer_fail_percentage' => '',
                'maxaddon'                  => '0',
                'user'                      => $user,
                'plan'                      => 'default',
                'maxpop'                    => 'n',
                'uid'                       => '',
                'homeroot'                  => '/home',
                'is_restore'                => '0',
                'hasshell'                  => 'y',
                'useip'                     => 'n',
                'maxlst'                    => 'n',
                'gid'                       => '',
                'no_cache_update'           => '0',
                'quota'                     => '0',
                'bwlimit'                   => '0',
                'skip_mysql_dbowner_check'  => '0',
                'hascgi'                    => 'y',
                'domain'                    => $user . '.com',
                'contactemail'              => '',
                'mxcheck'                   => 'local',
                'featurelist'               => 'default',
                'spf'                       => '',
                'cpmod'                     => 'paper_lantern',
                'owner'                     => 'root',
                'pass'                      => 'o5m4e3g2a1',
                'maxpark'                   => '0',
                'max_email_per_hour'        => '',
                'digestauth'                => '',
                'forcedns'                  => '0',
                'homedir'                   => '/home/' . $user,
                'force'                     => '',
                'maxsql'                    => 'n',
                'maxsub'                    => 'n',
            ],

            'hook'    => [
                'stage'         => 'post',
                'escalateprivs' => '0',
                'weight'        => '200',
                'id'            => 'st3EdYJ4I5Uf2dZvbcnHQSZq',
                'exectype'      => 'script',
                'hook'          => '/usr/local/cpanel/3rdparty/bin/scb_custom_dns.php --create',
            ],

        ];

        return $arr;
    }
    // test data
    public function testscb_whostmgr_accounts_remove($user = null)
    {
        $user = is_null($user) ? 'parkonthis' : $user;
        $arr  = [
            'context' =>
            [
                'stage'    => 'pre',
                'point'    => 'main',
                'blocking' => '1',
                'category' => 'Whostmgr',
                'event'    => 'Accounts::Remove',
            ],
            'data'    => [

                'user'    => $user,
                'killdns' => '1',
            ],
            'hook'    => [
                'stage'         => 'pre',
                'blocking'      => '1',
                'escalateprivs' => '0',
                'weight'        => '100',
                'id'            => 'gQicMFMnTb8MiLmU8DZuRXR9',
                'exectype'      => 'script',
                'hook'          => '/usr/local/cpanel/3rdparty/bin/scb_custom_dns.php --remove',
            ],

        ];
        return $arr;
    }
    // test data
    public function testscb_whostmgr_domain_park()
    {

        $arr = [
            'context' => [
                'stage'    => 'pre',
                'point'    => 'main',
                'blocking' => '1',
                'category' => 'Whostmgr',
                'event'    => 'Domain::park',
            ],

            'data'    => [
                'new_domain'    => 'toparknext.com',
                'target_domain' => 'parkonthis.com',
                'user'          => 'parkonthis',
            ],

            'hook'    => [
                'stage'         => 'pre',
                'blocking'      => '1',
                'escalateprivs' => '0',
                'weight'        => '100',
                'id'            => '4pMg6F3efyxJq5lcjQPC6m2h',
                'exectype'      => 'script',
                'hook'          => '/usr/local/cpanel/3rdparty/bin/scb_custom_dns.php --action=park',
            ],

        ];
        return $arr;
    }
    // test data
    public function testscb_whostmgr_domain_unpark()
    {

        $arr = [
            'context' => [
                'stage'    => 'pre',
                'point'    => 'main',
                'blocking' => '1',
                'category' => 'Whostmgr',
                'event'    => 'Domain::unpark',
            ],

            'data'    => [
                'domain'        => 'toparknext.com',
                'parent_domain' => 'parkonthis.com',
                'user'          => 'parkonthis',
            ],

            'hook'    => [
                'stage'         => 'pre',
                'blocking'      => '1',
                'escalateprivs' => '0',
                'weight'        => '100',
                'id'            => '4pMg6F3efyxJq5lcjQPC6m2h',
                'exectype'      => 'script',
                'hook'          => '/usr/local/cpanel/3rdparty/bin/scb_custom_dns.php --action=unpark',
            ],

        ];
        return $arr;
    }
    // test data
    public function testcpanel_api2_subdomain_addsubdomain()
    {
        $arr = [
            'context' => [
                'stage'         => 'post',
                'point'         => 'main',
                'escalateprivs' => '1',
                'category'      => 'Cpanel',
                'event'         => 'Api2::SubDomain::addsubdomain',
            ],

            'data'    => [
                'user'   => 'dothisdomain',
                'args'   => [
                    'domain'      => 'testsubdomain1',
                    'rootdomain'  => 'dothisdomain.com',
                    'canoff'      => '1',
                    'dir'         => 'public_html/testsubdomain1',
                    'disallowdot' => '0',
                ],

                'output' => [
                    [
                        'reason' => 'The subdomain “mysubdomaintopark1.scriptburn.com” has been added.',
                        'result' => '1',
                    ],

                ],

            ],

            'hook'    => [
                'stage'         => 'post',
                'escalateprivs' => '1',
                'weight'        => '200',
                'id'            => 'nyy_zrjfwvKzo0l2YgzreIvQ',
                'exectype'      => 'script',
                'hook'          => '/home/scriptbu/www/scriptbu_subdomain/dev/vultr/scb_add_subdomain.php',
            ],

        ];
        return $arr;
    }
    // test data
    public function testcpanel_api2_subdomain_delsubdomain()
    {
        $arr = array
            (
            'context' => array
            (
                'stage'         => 'pre',
                'point'         => 'main',
                'escalateprivs' => '1',
                'category'      => 'Cpanel',
                'event'         => 'Api2::SubDomain::delsubdomain',
            ),

            'data'    => array
            (
                'user'   => 'dothisdomain',
                'args'   => array
                (
                    'domain' => 'subdomain1.subdomain2_dothisdomain.com',
                ),
                'output' => [array
                    (
                        'reason' => 'Bind reloading on server using rndc zone: [dothisdomain.com] The subdomain “subdomain1.subdomain2.dothisdomain.com” has been removed.',
                        'result' => 1,
                    )],
            ),

            'hook'    => array
            (
                'stage'         => 'pre',
                'escalateprivs' => '1',
                'weight'        => '100',
                'id'            => 'lv1WFEoiUhp7VszhrPcEG9ud',
                'exectype'      => 'script',
                'hook'          => '/home/scriptbu/www/scriptbu_subdomain/dev/vultr/scb_delete_subdomain.php',
            ),

        );
        return $arr;
    }
}
