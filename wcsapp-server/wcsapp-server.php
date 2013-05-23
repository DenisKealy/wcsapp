<?php
require 'Match.php';
require 'Game.php';
require 'Schedule.php';
require 'Participant.php';
require 'S3Writer.php';

date_default_timezone_set('UTC');
$matchId = 0;

function getScheduleID(){
	global $scheduleIdMap, $scheduleDateMap;
	if(func_num_args() == 4){
		$region = func_get_arg(0);
		$division = func_get_arg(1);
		$round = func_get_arg(2);
		$scheduleName = func_get_arg(3);
		if(!array_key_exists($region, $scheduleIdMap) ||
		!array_key_exists($division, $scheduleIdMap[$region]) ||
		!array_key_exists($round, $scheduleIdMap[$region][$division]) ||
		!array_key_exists($scheduleName, $scheduleIdMap[$region][$division][$round])){
			file_put_contents('warnings.txt', date(DateTime::RFC1123) . " No Schedule ID found for $region - $division - $round - $scheduleName \n", FILE_APPEND);
			return false;
		} else
			return  $scheduleIdMap[$region][$division][$round][$scheduleName];
	} else {
		$unixtime = func_get_arg(0);
		$region = func_get_arg(1);
		if(!array_key_exists($unixtime, $scheduleDateMap[$region])){
			foreach(array_keys($scheduleDateMap[$region]) as $key){
				if(($key > $unixtime - 43200) && ($key < $unixtime + 43200)){ // 43200 = 12 hrs in seconds
					return $scheduleDateMap[$region][$key];
				}
			}
			file_put_contents('warnings.txt', date(DateTime::RFC1123) . " No  $region Schedule ID found within a day of $unixtime \n", FILE_APPEND);
			return false;
		} else{
			return $scheduleDateMap[$region][$unixtime];
		}
	}
}

function splitTitle($title){
	$title_arr = explode('/', $title);

	if(strpos($title_arr[0], 'America') !== false)
		$region = 'AM';
	else if(strpos($title_arr[0], 'Europe') !== false)
		$region = 'EU';
	else if(strpos($title_arr[0], 'Korea') !== false)
		$region = 'KR';
	else
		$region = 'XX';

	if(strpos($title_arr[1], 'Premier') !== false || strpos($title_arr[1], 'Code S') !== false)
		$division = 'P';
	else if(strpos($title_arr[1], 'Challenger') !== false || strpos($title_arr[1], 'Code A') !== false)
		$division = 'C';
	else
		$region = 'X';

	if(count($title_arr) > 2)
		$round = $title_arr[2];
	else
		$round = 'N/A';

	return array($region, $division, $round);
}

function parseSchedule($mwtext_str){
	global $db, $scheduleIdMap, $scheduleDateMap;
	$mwsched_arr = explode('Countdown',$mwtext_str);
	$st = $db->prepare('INSERT INTO schedule (id, time, division, region, name, round) values (:id, :time, :division, :region, :name, :round)');
	$sched = new Schedule();
	$id = 0;
	foreach ($mwsched_arr as $s) {
		if(substr($s, 0, 9) != "\n|<small>")
			continue;
		$id++;
		$sched->id = $id;
		$sched->region = Schedule::getRegion($s);
		$sched->time = Schedule::getTime($s) * 1000;
		$sched->division = Schedule::getDivision($s);
		$roundAndName = Schedule::getRoundAndName($s);
		$sched->name = Schedule::getName($roundAndName);
		$sched->round = Schedule::getRound($roundAndName);
		if(strpos($sched->name, 'Ro') !== false && strpos($sched->round, 'Day') !== false){
			$temp = $sched->name;
			$sched->name = $sched->round;
			$sched->round = $temp;
		}
		$scheduleIdMap[$sched->region][$sched->division][$sched->round][$sched->name] = $id;
		$scheduleDateMap[$sched->region][($sched->time) / 1000] = $id;
		$st->execute((array)$sched);
	}
}

function parseMatchesFromGroup($m, $title, $mwtext_str, $st){
	global $scheduleIdMap, $matchId;
	$group_arr = explode('{{HiddenSort|', $mwtext_str);
	list($region, $division, $round) = splitTitle($title);
	$m->matchtype = 'group';
	foreach($group_arr as $group_str){
		if(substr($group_str, 0, 5) != 'Group')
			continue;
		$scheduleName = Match::getName($group_str);
		$scheduleId = getScheduleId($region, $division, $round, $scheduleName);
		if ($scheduleId === false)
			continue;
		$match_arr = explode('|match', $group_str);
		foreach($match_arr as $match_str){
			if(strpos($match_str, 'MatchMaps') === false)
				continue;
			$matchId++;
			$m->id = $matchId;
			$m->matchname = $scheduleName;
			$m->matchnum = Match::getNum($match_str);
			$m->scheduleid = $scheduleId;
			$m->player1name = Match::getGroupPlayerName($match_str, 1);
			$m->player2name = Match::getGroupPlayerName($match_str, 2);
			$m->player1race = Match::getGroupPlayerRace($match_str, 1);
			$m->player2race = Match::getGroupPlayerRace($match_str, 2);
			$m->player1flag = Match::getGroupPlayerFlag($match_str, 1);
			$m->player2flag = Match::getGroupPlayerFlag($match_str, 2);
			$m->winner = Match::getGroupWinner($match_str);
			$m->numgames = Match::getNumGames($match_str);
			$m->player1wins = 1;
			$m->player2wins = 1;
			$st->execute((array)$m);
			$gamesToParse[] = array($match_str, $m->numgames, $matchId);
		}
	}

	$g = new Game();
	$st = getInsertQuery('games', 'mapname', 'mapwinner', 'vodlink', 'matchid');
	foreach($gamesToParse as $unparsedGame){
		$parsedGames = parseGames($unparsedGame[0], $unparsedGame[1], $unparsedGame[2], $st, $g);
	}
}

function parseMatchesFromBracket($m, $title, $mwtext_str, $st){
	global $matchId, $scheduleDateMap;
	list($region, $division, $round) = splitTitle($title);
	$scheduleDateMap['AM'][0] = 0;
	$scheduleDateMap['KR'][0] = 0;
	$scheduleDateMap['EU'][0] = 0;
	$bracket_arr = explode('{{WCSChallengerBracket', $mwtext_str);
	if(count($bracket_arr) == 1)
		$bracket_arr = explode('{{CodeABracket', $mwtext_str);
	if(count($bracket_arr) == 1){
		$bracket_arr = explode('{{8SEBracket', $mwtext_str);
// 		$bracket_arr = array_merge($bracket_arr, explode('{{4SEBracket', $bracket_arr[1]));
		$bracket_arr = explode('{{4SEBracket', $bracket_arr[1]);
// 		var_dump($bracket_arr[1]);
// 		var_dump($bracket_arr[2]);
	}
	
	$m->matchtype = 'bracket';
	foreach($bracket_arr as $bracket_str){
		if((strpos(substr($bracket_str, 0, 15), 'Round') === false) && (strpos(substr($bracket_str, 0, 10), '|R1') === false))
			continue;
		$bracketVals_arr = Match::getBracketVals($bracket_str, false);
		$keys = array();
		$newMatch = false;
		$prefixAndCount = array('init', 1);
		foreach(array_keys($bracketVals_arr) as $key){
			if(strlen($key) > 6)
				continue;
			if(preg_match('[R[\d][W|w|D|d][\d]]', $key) !== 1)
				continue;
			if($newMatch){
				$prefix = preg_split('[[W|w|D|d][\d]]', $key);
				if($prefix == $prefixAndCount[0]){
					$prefixAndCount[1]++;
				} else {
					$prefixAndCount[0] = $prefix;
					$prefixAndCount[1] = 1;
				}
				$keys[] = array($oldkey, $key, $prefix[0], $prefixAndCount[1]);
			} else {
				$oldkey = $key;
			}
			$newMatch = !$newMatch;
		}

		foreach($keys as $keypair){
			$matchId++;
			$m->id = $matchId;
			$m->player1name = $bracketVals_arr[$keypair[0]];
			$m->player2name = $bracketVals_arr[$keypair[1]];
			$m->player1race = $bracketVals_arr[$keypair[0] . 'race'];
			$m->player2race = $bracketVals_arr[$keypair[1] . 'race'];
			$m->player1flag = $bracketVals_arr[$keypair[0] . 'flag'];
			$m->player2flag = $bracketVals_arr[$keypair[1] . 'flag'];
			$m->player1wins = $bracketVals_arr[$keypair[0] . 'score'];
			$m->player2wins = $bracketVals_arr[$keypair[1] . 'score'];
			if($m->player1wins == 'Q' || $m->player2wins == 'Q') // Listing qualified players, no match played.
				continue;
			$m->scheduleid = getScheduleId(Match::getTime($bracketVals_arr[$keypair[2] . 'G' . $keypair[3] . 'details']['date']), $region);
			if(isset($bracketVals_arr[$keypair[0] . 'win']) && $bracketVals_arr[$keypair[0] . 'win'] == '1')
				$m->winner = '1';
			else if(isset($bracketVals_arr[$keypair[1] . 'win']))
				$m->winner = '2';
			$i = 1;
			while(isset($bracketVals_arr[$keypair[0] . 'details']["map$i"]))
				$i++;
			$m->numgames = $i - 1;
			if($m->scheduleid !== false)
				$st->execute((array)$m);
		}
	}
}

function parseMatches($title, $mwtext_str, $bracketType){
	$st = getInsertQuery('matches', 'id', 'winner', 'player1name', 'player2name', 'player1race', 'player2race', 'player1flag', 'player2flag', 'numgames', 'matchname', 'scheduleid', 'matchnum', 'matchtype', 'player1wins', 'player2wins');
	$m = new Match();
	if($bracketType == 'group'){
		parseMatchesFromGroup($m, $title, $mwtext_str, $st);
	} else if($bracketType == 'bracket') {
		parseMatchesFromBracket($m, $title, $mwtext_str, $st);
	}
}

function getInsertQuery($tableName){
	global $db;
	$fields = func_get_args();
	array_shift($fields);
	$colNames = implode (', ', $fields);
	$valNames = ':' . implode (', :', $fields);
	return $db->prepare("INSERT INTO $tableName ($colNames) values ($valNames)");
}

function parseParticipants($title, $mwtext_str){
	global $scheduleIdMap;
	list($region, $division, $round) = splitTitle($title);
	$st = getInsertQuery('participants', 'name', 'flag', 'race', 'place', 'matcheswon', 'matcheslost', 'mapswon', 'mapslost', 'result', 'scheduleid');
	$group_arr = explode('{{HiddenSort|', $mwtext_str);
	foreach ($group_arr as $group_str){
		if(substr($group_str, 0, 5) != "Group")
			continue;
		$scheduleName = Participant::getGroupName($group_str);
		$partipant_arr = explode('{{GroupTableSlot|', $group_str);
		$p = new Participant();
		foreach($partipant_arr as $s){
			if(!(substr($s, 0, 9) == " {{player" || substr($s, 0, 9) == " {{TA|201"))
				continue;
			$p->name = Participant::getName($s);
			$p->flag = Participant::getValue($s, 'flag');
			$p->race = Participant::getValue($s, 'race');
			$p->place = Participant::getValue($s, 'place');
			$p->matcheswon = Participant::getValue($s, 'win_m');
			$p->matcheslost = Participant::getValue($s, 'lose_m');
			$p->mapswon = Participant::getValue($s, 'win_g');
			$p->mapslost = Participant::getValue($s, 'lose_g');
			$p->result = Participant::getResult($s);
			$p->scheduleid = getScheduleId($region, $division, $round, $scheduleName);
			if ($p->scheduleid === false)
				continue;
			$st->execute((array)$p);
		}
	}
}

function parseGames($s, $numGames, $matchId, $st, $g){
	for($i = 1; $i <= $numGames; $i++){
		$g->mapname = Game::getMapName($s, $i);
		$g->mapwinner = Game::getMapWinner($s, $i);
		$g->vodlink = Game::getVodLink($s, $i);
		$g->matchid = $matchId;
		$st->execute((array)$g);
	}
}

if(file_exists('warnings.txt'))
	unlink('warnings.txt');

$titles[] = '2013_StarCraft_II_World_Championship_Series/Schedule';
$titles[] = '2013_WCS_Season_1_America/Premier/Ro32';
$titles[] = '2013_WCS_Season_1_America/Premier/Ro16';
$titles[] = '2013_WCS_Season_1_America/Premier';
$titles[] = '2013_WCS_Season_1_Europe/Premier/Ro32';
$titles[] = '2013_WCS_Season_1_Europe/Premier/Ro16';
$titles[] = '2013_WCS_Season_1_Europe/Premier';
$titles[] = '2013 WCS Season 1 Korea GSL/Code S/Ro32';
$titles[] = '2013 WCS Season 1 Korea GSL/Code S/Ro16';
$titles[] = '2013 WCS Season 1 Korea GSL/Code S';
$titles[] = '2013_WCS_Season_1_America/Premier';
$titles[] = '2013_WCS_Season_1_America/Challenger';
$titles[] = '2013_WCS_Season_1_Europe/Challenger';
$titles[] = '2013_WCS_Season_1_Korea_GSL/Challenger';

$url = 'http://wiki.teamliquid.net/starcraft2/api.php?action=query&export&exportnowrap&titles=' . implode ('|', $titles);

try{
	$mediawiki_obj = simplexml_load_file($url);
	$db = new PDO("sqlite:wcsapp.sqlite");
	$db->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
	$db->exec('DROP TABLE IF EXISTS games');
	$db->exec('DROP TABLE IF EXISTS matches');
	$db->exec('DROP TABLE IF EXISTS schedule');
	$db->exec('DROP TABLE IF EXISTS participants');
	$db->exec('CREATE TABLE "games" ("id" INTEGER PRIMARY KEY  NOT NULL ,"mapname" TEXT,"mapwinner" INTEGER DEFAULT (null) ,"vodlink" TEXT,"matchid" INTEGER NOT NULL  DEFAULT (null) );');
	$db->exec('CREATE TABLE "matches" ("id" INTEGER PRIMARY KEY  NOT NULL ,"winner" TEXT,"player1name" TEXT,"player2name" TEXT,"player1race" TEXT,"player2race" TEXT,"player1flag" TEXT,"player2flag" TEXT,"numgames" INTEGER DEFAULT (null) ,"matchname" TEXT,"scheduleid" INTEGER NOT NULL  DEFAULT (null) , "matchnum" INTEGER, "matchtype" TEXT, "player1wins" TEXT, "player2wins" TEXT);');
	$db->exec('CREATE TABLE "schedule" ("id" INTEGER PRIMARY KEY NOT NULL ,"time" INTEGER,"division" TEXT,"region" TEXT,"name" TEXT, "round" TEXT);');
	$db->exec('CREATE TABLE "participants" ("id" INTEGER PRIMARY KEY NOT NULL, "name" TEXT, "flag" TEXT, "race" TEXT, "place" INTEGER, "matcheswon" INTEGER, "matcheslost" INTEGER, "mapswon" INTEGER, "mapslost" INTEGER, "result" TEXT, "scheduleid" INTEGER)');
	parseSchedule($mediawiki_obj->page[0]->revision->text);
	for($i = 1; $i < count($mediawiki_obj->page); $i++){
		if(strpos($mediawiki_obj->page[$i]->title, 'Ro') !== false){
			parseMatches($mediawiki_obj->page[$i]->title, $mediawiki_obj->page[$i]->revision->text, 'group');
			parseParticipants($mediawiki_obj->page[$i]->title, $mediawiki_obj->page[$i]->revision->text, 'group');
		} else {
			parseMatches($mediawiki_obj->page[$i]->title, $mediawiki_obj->page[$i]->revision->text, 'bracket');
		}
	}
	$db = null;
	`echo .dump | sqlite3 wcsapp.sqlite | gzip -c > wcsapp.dump.gz`;
	uploadToS3();
} catch (Exception $e){
	file_put_contents('errors.txt', date(DateTime::RFC1123) . ' -- Line ' . $e->getLine() . ": " . $e->getMessage() . "\n", FILE_APPEND);
}
?>
