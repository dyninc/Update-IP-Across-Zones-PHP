<?php
//Turn php error reporting on. Turn this off in production
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', dirname(__FILE__) . '/error_log.txt');
error_reporting(E_ERROR);

// check that we have the correct number of arguments
if(count($argv) != 3)
{
	echo ("Incorrect number of parameters\nUseage: php UpdateIPInZones.php [old_ip] [new_ip]");
	exit;
}

// set the ip's we took in to what we expect them to be
$oldip = $argv[1];
$newip = $argv[2];

$token = null;
$login_credentials = array();

// setup the credentials... probably want to move this to a config file in production
$login_credentials = array(
  'customer_name' => 'customer_name',
  'user_name' => 'user_name',
  'password' => 'password');

// do login then parse the result for the token
$result = MakeRestCall("POST", "Session", $login_credentials, null, "");

if($result["status"] == 'success')
{
	$token = $result["data"]["token"];
}
else
{
	echo("Error Logging In. Check Credentials");
}

// since publish will always be publish, lets set this up outside the array
$publish = array();
$publish = array( 'publish' => 'true' );

// call our fuction to return back the zones with their associated nodes
$nodes = GetAllNodes($token);

// loop through each node under each zone
foreach($nodes as $zone => $subnodes)
{
	foreach($subnodes as $node)
	{
		// get all the records for each node and if there are any, call "IsOriginalIP" to test if it is a record type we care about and if it is an ip that shoud dbe updated
		$result = MakeRestCall("GET", "ANYRecord", null, $token, $zone . "/" . $node);
		if(count($result['data']) > 0)
		{
			foreach($result['data'] as $record)
			{
				$parts = explode('/', $record);
				if(IsOriginalIP($token, $zone, $node, $parts[2], $parts[count($parts)-1], $oldip))
				{
					// if this is a record to update, do it
					UpdateRecord($token, $zone, $node, $parts[2], $parts[count($parts)-1], $newip);
				}
			}
		}
		
	}
	
	$result = MakeRestCall("PUT", "Zone", $publish, $token, $zone);
}

// function will get the ip address by record type and if it matches the address we wish to update, returns true
function IsOriginalIP($token, $zone, $node, $recordType, $id, $oldip)
{
	$result = null;
	switch ($recordType) 
	{
		case 'ARecord':
			$result = MakeRestCall("GET", "ARecord", null, $token, $zone . "/" . $node . "/" . $id);
			break;
		case 'AAAARecord':
			$result = MakeRestCall("GET", "AAAARecord", null, $token, $zone . "/" . $node . "/" . $id);
			break;
	}
	
	if(strcasecmp($result['data']['rdata']['address'], $oldip) == 0)
	{
		return true;
	}
	
	return false;
}

// function will take in the id of the record to update, the type of record and the new ip address then handle the actual updating
function UpdateRecord($token, $zone, $node, $recordType, $id, $newip)
{
	$result = null;
	$data = Array();
	
	$data['rdata'] = Array();
	switch ($recordType) 
	{
		case 'ARecord':
			$data['rdata']['address'] = $newip;
			$result = MakeRestCall("PUT", "ARecord", $data, $token, $zone . "/" . $node . "/" .$id);
			break;
		case 'AAAARecord':
			$data['rdata']['address'] = $newip;
			$result = MakeRestCall("PUT", "AAAARecord", $data, $token, $zone . "/" . $node . "/" .$id);
			break;
	}
}

// get all the nodes for all zones... 
function GetAllNodes($token)
{
	// get all the zones
	$result = MakeRestCall("GET", "Zone", null, $token, "");
	$zones = $result['data'];
	$AllNodeList = Array();
	
	// loop through the list of zones and get the subnodes for each
	foreach( $zones as $zone)
	{
		$parts = explode('/', $zone);
		$zone_name = $parts[count($parts)-2];
		$AllNodeList[$zone_name] = GetSubNodes($token, $zone_name);
	}
	
	// return an associative array of the nodes keyed off the zones
	return $AllNodeList;
}

// just get the node list for the zone
function GetSubNodes($token, $node)
{
	$result = MakeRestCall("GET", "NodeList", null, $token, $node);
	return $result['data'];
}

// utility function to wrap the curl options and setup for doing a REST call
function MakeRestCall($method, $path, $args, $token, $ending)
{
	$BASE_URL = "https://api2.dynect.net/REST/";
	
	// Get the curl session object
	$session = curl_init();

	curl_setopt($session, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($session, CURLOPT_FAILONERROR, false);
	curl_setopt($session, CURLOPT_HEADER, false);

	if($token)
	{
		if($method == "PUT")
			curl_setopt($session, CURLOPT_HTTPHEADER, array('Auth-Token: ' . $token, 'Content-Type: application/json', 'API-Version: 3.2.0', 'Content-Length: ' . strlen(json_encode($args))));
		else
			curl_setopt($session, CURLOPT_HTTPHEADER, array('Auth-Token: ' . $token, 'Content-Type: application/json', 'API-Version: 3.2.0'));
	}
	else
	{
		 curl_setopt($session, CURLOPT_HTTPHEADER, array('Content-Type: application/json', 'API-Version: 3.2.0'));
	}

	curl_setopt ($session, CURLOPT_CUSTOMREQUEST, $method);
	curl_setopt($session, CURLOPT_URL, $BASE_URL . $path . "/" . $ending);

	curl_setopt($session, CURLOPT_SSL_VERIFYHOST, 0);
	curl_setopt($session, CURLOPT_SSL_VERIFYPEER, 0);


	if($method == "POST" || $method == "PUT")
		curl_setopt ($session, CURLOPT_POSTFIELDS, json_encode($args));

	$response = curl_exec($session);
	$decoded_result = json_decode($response, true);
	curl_close($session);
	
	return $decoded_result;
}

?>