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
			throw new \Exception('Invalid ip of domain: '.$data['domain']);
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
				throw new \Exception("Unable to find domain name of owner:".$domain_data['data']['main_domain']);
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
				'name' => $data['args']['domain'].".".$data['args']['rootdomain'],
			]
		);

		// abort if unable tofind A record of the subdomain
		if (!isset($ret[0]))
		{
			throw new \Exception('Unable to find subdomain '.$data['args']['domain'].".".$data['args']['rootdomain'].' ip');
		}

		// create our APi packet to send
		$data = ['domain' => $data['args']['rootdomain'],
			'name' => $ret[0]['name'],
			'data' => $ret[0]['record'],
			'type' => $ret[0]['type'],
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
		$need_to_find = is_array($find) && isset($find['type']);
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
}
