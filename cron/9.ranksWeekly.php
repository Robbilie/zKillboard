<?php

require_once "../init.php";

$today = date('YmdH');
$todaysKey = "RC:weeklyRanksCalculated:$today";
if ($redis->get($todaysKey) == true) exit();

$statClasses = ['ships', 'isk', 'points'];
$statTypes = ['Destroyed', 'Lost'];

$minKillID = $mdb->findField("oneWeek", "killID", [], ['killID' => 1]);

$typeLocation = ['characterID' => 'involved.characterID', 'corporationID' => 'involved.characterID', 'allianceID' => 'involved.allianceID', 'factionID' => 'involved.factionID', 'groupID' => 'involved.groupID', 'solarSystemID' => 'system.solarSystemID', 'locationID' => 'locationID', 'regionID' => 'system.regionID', 'shipTypeID' => 'involved.shipTypeID'];
$statistics = $mdb->getCollection("statistics");

Util::out("weekly time ranks - first iteration");
$types = $mdb->getCollection("statistics")->distinct("type");
foreach ($types as $type) {

	$dbLocation = $typeLocation[$type];
	if ($dbLocation == null) exit("unknown location for $type\n");
	$ids = $mdb->getCollection("oneWeek")->distinct("involved.$type");
	foreach ($ids as $id) {
		if ($type == 'corporationID' && $id <= 1999999) continue;
		if ($type == 'shipTypeID' && Info::getGroupID($id) == 29) continue;

		//$killID = getLatestKillID($type, $id, $minKillID);
		//if ($killID < $minKillID) continue;

		$types[$type] = true;
		$key = "tq:ranks:weekly:$type:$today";

		$row = $mdb->findDoc("statistics", ['type' => $type, 'id' => $id]);
		$weeklyKills = getWeekly($row['type'], $row['id'], false, $minKillID); 
		if ($weeklyKills['killIDCount'] == 0) continue;
		$weeklyLosses = getWeekly($row['type'], $row['id'], true, $minKillID); 

		$multi = $redis->multi();
		zAdd($multi, "$key:shipsDestroyed", $weeklyKills['killIDCount'], $id);
		zAdd($multi, "$key:pointsDestroyed", $weeklyKills['zkb_pointsSum'], $id);
		zAdd($multi, "$key:iskDestroyed", $weeklyKills['zkb_totalValueSum'], $id);
		zAdd($multi, "$key:shipsLost", $weeklyLosses['killIDCount'], $id);
		zAdd($multi, "$key:pointsLost", $weeklyLosses['zkb_pointsSum'], $id);
		zAdd($multi, "$key:iskLost", $weeklyLosses['zkb_totalValueSum'], $id);
		$multi->exec();
	}
}

Util::out("weekly time ranks - second iteration");
foreach ($types as $type=>$value)
{
	$key = "tq:ranks:weekly:$type:$today";
	$indexKey = "$key:shipsDestroyed";
	$max = $redis->zCard($indexKey);
	$redis->del("tq:ranks:weekly:$type:$today");

	$it = NULL;
	while($arr_matches = $redis->zScan($indexKey, $it)) {
		foreach($arr_matches as $id => $score) {
			$redis->get('foo'); // Keeps the connection alive
			$shipsDestroyed = $redis->zScore("$key:shipsDestroyed", $id); 
			$shipsDestroyedRank = rankCheck($max, $redis->zRevRank("$key:shipsDestroyed", $id));
			$shipsLost = $redis->zScore("$key:shipsLost", $id); 
			$shipsLostRank = rankCheck($max, $redis->zRevRank("$key:shipsLost", $id));
			$shipsEff = ($shipsDestroyed / ($shipsDestroyed + $shipsLost));

			$iskDestroyed = $redis->zScore("$key:iskDestroyed", $id); 
			if ($iskDestroyed == 0) continue;
			$iskDestroyedRank = rankCheck($max, $redis->zRevRank("$key:iskDestroyed", $id));
			$iskLost = $redis->zScore("$key:iskLost", $id); 
			$iskLostRank = rankCheck($max, $redis->zRevRank("$key:iskLost", $id));
			$iskEff = ($iskDestroyed / ($iskDestroyed + $iskLost));

			$pointsDestroyed = $redis->zScore("$key:pointsDestroyed", $id); 
			$pointsDestroyedRank = rankCheck($max, $redis->zRevRank("$key:pointsDestroyed", $id));
			$pointsLost = $redis->zScore("$key:pointsLost", $id); 
			$pointsLostRank = rankCheck($max, $redis->zRevRank("$key:pointsLost", $id));
			$pointsEff = ($pointsDestroyed / ($pointsDestroyed + $pointsLost));

			$avg = ceil(($shipsDestroyedRank + $iskDestroyedRank + $pointsDestroyedRank) / 3);
			$adjuster = (1 + $shipsEff + $iskEff + $pointsEff) / 4;
			$score = ceil($avg / $adjuster);

			$redis->zAdd("tq:ranks:weekly:$type:$today", $score, $id);
		}
	}
}

foreach ($types as $type=>$value) {
	$multi = $redis->multi();
	$multi->del("tq:ranks:weekly:$type");
	$multi->zUnion("tq:ranks:weekly:$type", ["tq:ranks:weekly:$type:$today"]);
	$multi->expire("tq:ranks:weekly:$type", 100000);
	$multi->expire("tq:ranks:weekly:$type:$today", (7 * 86400));
	moveAndExpire($multi, $today, "tq:ranks:weekly:$type:$today:shipsDestroyed");
	moveAndExpire($multi, $today, "tq:ranks:weekly:$type:$today:shipsLost");
	moveAndExpire($multi, $today, "tq:ranks:weekly:$type:$today:iskDestroyed");
	moveAndExpire($multi, $today, "tq:ranks:weekly:$type:$today:iskLost");
	moveAndExpire($multi, $today, "tq:ranks:weekly:$type:$today:pointsDestroyed");
	moveAndExpire($multi, $today, "tq:ranks:weekly:$type:$today:pointsLost");
	$multi->exec();
}

function moveAndExpire(&$multi, $today, $key) {
	$newKey = str_replace(":$today", "", $key);
	$multi->rename($key, $newKey);
	$multi->expire($newKey, 9000);
}

$redis->setex($todaysKey, 9000, true);
Util::out("Weekly rankings complete");

function zAdd(&$multi, $key, $value, $id) {
	$value = max(1, (int) $value);
	$multi->zAdd($key, $value, $id);
	$multi->expire($key, 9000);
}

function rankCheck($max, $rank) {
	return $rank === false ? $max : ($rank + 1);
}

function getWeekly($type, $id, $isVictim, $minKillID) {
	global $mdb;

	// build the query
	$query = [$type => $id, 'isVictim' => $isVictim];
	$query = MongoFilter::buildQuery($query);
	// set the proper sequence values
	$query = ['$and' => [['killID' => ['$gte' => $minKillID]], $query]];

	$result = $mdb->group('killmails', [], $query, 'killID', ['zkb.points', 'zkb.totalValue']);
	return sizeof($result) ? $result[0] : ['killIDCount' => 0, 'zkb_pointsSum' => 0, 'zkb_totalValueSum' => 0];
}

function getLatestKillID($type, $id, $minKillID) {
	global $mdb;

	// build the query
	$query = [$type => $id, 'isVictim' => false];
	$query = MongoFilter::buildQuery($query);
	// set the proper sequence values
	$query = ['$and' => [['killID' => ['$gte' => $minKillID]], $query]];

	$killmail = $mdb->findDoc("killmails", $query);
	if ($killmail == null) return 0;
	return $killmail['killID'];
}
