<?php

$pid = 1;
$max = 50;
$threadNum = 0;
for ($i = 0; $i < $max; ++$i) {
        $pid = pcntl_fork();
        if ($pid == -1) {
                exit();
        }
        if ($pid == 0) {
                break;
        }
	$threadNum++;
}

require_once '../init.php';

$collection = $threadNum > floor($max * 0.8) ? "Corporation" : "Character";
$type = substr(strtolower($collection), 0, 4);
$field = strtolower($collection) . "ID";
$collection = "api" . $collection;

$minute = date('Hi');
$timeQueue = new RedisTimeQueue("zkb:{$type}s", 3600);

if ($threadNum == 0 || $threadNum == $max) {
	$ids = $mdb->getCollection($collection)->distinct($field);
	foreach ($ids as $id) {
		$timeQueue->add($id);
	}
}

while ($minute == date('Hi')) {
	$id = (int) $timeQueue->next();
	if ($id > 0) {
		$api = $mdb->findDoc($collection, [$field => $id], ['lastFetched' => 1]);
		if ($api === null) {
			$timeQueue->remove($id);
			continue;
		}
		try {
			processCharApi($mdb, $apiServer, $type, $api);
			$mdb->set($collection, $api, ['lastFetched' => time()]);
			updateApiRow($mdb, $collection, $api, 0);
		} catch (Exception $ex) {
			updateApiRow($mdb, $collection, $api, $ex->getCode());
			$mdb->remove($collection, $api);
		}
		sleep(1);
	}
}

function updateApiRow($mdb, $collection, $api, $errorCode)
{
	$ttlName = $errorCode == 0 ? 'ttlc:XmlSuccess' : 'ttlc:XmlFailure';
	$ttlCounter = new RedisTtlCounter($ttlName, 300);
	$ttlCounter->add(uniqid());
	$mdb->set($collection, $api, ['errorCode' => (int) $errorCode, 'lastFetched' => time()]);
}

function processCharApi($mdb, $apiServer, $type, $row) {
	$charID = $row['characterID'];
	$corpID = $row['corporationID'];
	$keyID = $row['keyID'];
	$vCode = $row['vCode'];
	$killmails = fetchKillmails($apiServer, $type, $charID, $keyID, $vCode);
	$added = processKillmails($mdb, $killmails);
	$name = $type == 'char' ? Info::getInfoField('characterID', $charID, 'name')  : Info::getInfoField('corporationID', $corpID, 'name');
	if ($added) {
		while (strlen("$added") < 3) {
			$added = " " . $added;
		}
		Util::out("$added kills added by $type $name");
	}
}

function fetchKillmails($apiServer, $type, $charID, $keyID, $vCode)
{
	$url = "$apiServer/$type/KillMails.xml.aspx?characterID=$charID&keyID=$keyID&vCode=$vCode";
	$response = RemoteApi::getData($url);
	$content = $response['content'];
	$xml = simplexml_load_string($content);

	$rows = @$xml->result->rowset->row;
	$killmails = [];
	if ($rows != null) foreach ($rows as $c=>$row) {
		$killmails[] = $row;
	}
	return $killmails;
}

function processKillmails($mdb, $killmails)
{
	$added = 0;
	foreach ($killmails as $killmail) {
		$killID = (int) $killmail['killID'];
		$hash = getHash($killmail);
		$added += addKillmail($mdb, $killID, $hash);
	}
	return $added;
}

function addKillmail($mdb, $killID, $hash)
{
	if ($mdb->count("crestmails", ['killID' => $killID, 'hash' => $hash]) == 0) {
		$mdb->insert("crestmails", ['killID' => $killID, 'hash' => $hash, 'source' => 'api', 'processed' => false, 'added' => $mdb->now()]);
		return 1;
	}
	return 0;
}

function getHash($killmail)
{
	$victim = $killmail->victim;
	$victimID = $victim['characterID'] == 0 ? 'None' : $victim['characterID'];

	$attackers = $killmail->rowset->row;
	$first = null;
	$attacker = null;
	foreach ($attackers as $att) {
		$first = $first == null ? $att : $first;
		if ($att['finalBlow'] != 0) {
			$attacker = $att;
		}
	}
	$attacker = $attacker == null ? $first : $attacker;
	$attackerID = $attacker['characterID'] == 0 ? 'None' : $attacker['characterID'];

	$shipTypeID = $victim['shipTypeID'];
	$dttm = (strtotime($killmail['killTime']) * 10000000) + 116444736000000000;

	$string = "$victimID$attackerID$shipTypeID$dttm";
	$hash = sha1($string);

	return $hash;
}
