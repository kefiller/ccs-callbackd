#!/usr/bin/php -q
<?php

use CCS\HttpApiClient;
use CCS\Logger;
use CCS\ResultOK;
use CCS\ResultError;
use CCS\db\MyDB;

$realPath  = realpath(__FILE__);
$pathParts = pathinfo($realPath);
$myDir =  $pathParts['dirname'];
$myName = $pathParts['filename'];

// dynamic class load
//@phan-suppress-next-line PhanTypeMismatchArgumentInternal
spl_autoload_register(function ($class) {
    global $myDir;

    //echo "$class\n";
    // namespace -> file structure
    $relPath = str_replace(__NAMESPACE__, "", $class);
    $relPath .= ".class.php";
    $filename = "$myDir/lib/$relPath";
    $filename = str_replace("\\", "/", $filename);

    if (file_exists($filename)) {
        //echo "exists $filename\n";
        require_once $filename;
        return true;
    }
    //echo "not exists $filename\n";
    return false;
});

require_once('Mail.php');
require_once $myDir . '/_config.php';

foreach (['db','extra','timings','queues','campaign-settings', 'ccs-api'] as $reqCfgParam) {
    if (!isset($_CFG[$reqCfgParam])) {
        die("'$reqCfgParam' not set in config");
    }
}

Logger::setLogTime(false);

$intvlLoop = $_CFG['timings']['loop-intvl'];
$selectIntvl = $_CFG['timings']['select-intvl'];

// user should be in queue not less than $queueLeaveTime before leave
$queueLeaveTime = $_CFG['timings']['queue-leave-time'];

$aQueues = $_CFG['queues'];

$queues = "'" . implode("','", array_keys($aQueues)) . "'";

$campName = $_CFG['extra']['camp-name'];
$dryRun = $_CFG['extra']['dry-run'];
$dropCampOnStart = $_CFG['extra']['drop-campaign-on-start'];

$campSettings = $_CFG['campaign-settings'];

$apiUrl = $_CFG['ccs-api']['url'];
$apiToken = $_CFG['ccs-api']['auth-token'];

// Connect DB
$db = MyDB::getInstance();
$db->init($_CFG['db']['servers'], $_CFG['db']['conn-params'], $quiet = false);
$result = $db->connect();
if ($result->error()) {
    Logger::log("DB connection error: " . $result->errorDesc());
    exit(0);
}

$apiClient = new HttpApiClient($apiUrl, $apiToken);

//$campaign = $campPrefix . date("Ymd");
$campaign = $campName;

$rslt = null;
if ($dropCampOnStart) {
    Logger::log("Dropping campaign $campaign");
    $rslt = $apiClient->a2iCampaignDrop($campaign);
//    if ($rslt->error()) {
//        Logger::log("Exit. Error: " . $rslt->errorDesc());
//        exit(1);
//    }
}

// Check if we have to create new campaign
$exists = $apiClient->a2iCampaignExists($campaign);

if (!$exists) {
    Logger::log("Have to create campaign $campaign");
    $rslt = $apiClient->a2iCampaignCreate($campaign, $campSettings);
} else {
    Logger::log("Update campaign $campaign settings");
    $rslt = $apiClient->a2iCampaignUpdate($campaign, $campSettings);
}
if ($rslt->error()) {
    Logger::log("Exit. Error: " . $rslt->errorDesc());
    exit(1);
}

Logger::log("Configured on queues: $queues");

$sleepIntvl = 0;

$notWorkMsgShown = []; // for each queue

// main loop
while (true) {
    sleep($sleepIntvl);

    $sleepIntvl = $intvlLoop;

    $rslt = $apiClient->a2iCampaignDataGet($campaign);
    if ($rslt->error()) {
        Logger::log("Exit. Error: " . $rslt->errorDesc());
        exit(1);
    }
    $campNumsData = $rslt->data();
    $campNums = array_keys($campNumsData);

    $curDate = date("Y-m-d");
    $sql = "select qc.time,qc.callid,qc.queuename,ql.data2 from queue_calls as qc, queue_log as ql "
            ." where qc.queuename in ($queues) and qc.time > '$curDate 00:00:00'"
            ." and qc.time < '$curDate 23:59:59' and qc.disposition = 'ABANDON'"
            ." and qc.callid = ql.callid and ql.event='ENTERQUEUE'"
            ." and qc.wait_time > $queueLeaveTime and (now() - qc.time) < interval"
            ." '$selectIntvl' order by qc.time desc";

    //Logger::log($sql);

    $rows = $db->query($sql);

    $recallNums = [];
    foreach ($rows as $row) {
        $num  = $row['data2'];
        $num = str_replace("+", "", $num); // remove + sign from number

        $time = $row['time'];
        $q    = $row['queuename'];

        $firstDigit = substr($num, 0, 1);
        // check if number is valid
        if (!(($firstDigit == '7' || $firstDigit == '8') && strlen($num) == 11)) {
            continue; // skip number
        }
        if (!array_key_exists($num, $recallNums)) { // if not already set
            $recallNums[$num] = ['time' => $time, 'queue' => $q];
        }
    }

    $newNums = [];
    foreach ($recallNums as $num => $numData) {
        $numCalldate = $numData['time'];
        $numQueue    = $numData['queue'];

        // Check worktime per queue
        $perQWorktimeSetts = $aQueues[$numQueue]['worktime'];
        if (!checkWorkTime($perQWorktimeSetts)) {
            $msgShown = $notWorkMsgShown[$numQueue] ?? false;
            if (!$msgShown) {
                Logger::log("$numQueue: not work time");
                $notWorkMsgShown[$numQueue] = true;
            }
            continue;
        }
        $notWorkMsgShown[$numQueue] = false;

        // extract per-queue settings
        $perQSetts = $aQueues[$numQueue]['campaign-settings'];

        $newNum = ['number' => $num, 'queue' => $numQueue ];
        // Add additional fields to number
        foreach ($perQSetts as $k => $v) {
            if ($v) {
                $newNum[$k] = $v;
            }
        }

        /*
        $qClid = $perQSetts['callerid'];
        $qMsgTpl = $perQSetts['msg-template'];
        $qBridgeTgt = $perQSetts['bridge-target'];
        $qVirtOper = $perQSetts['virtual-oper'];
        $newNum = ['number' => $num, 'callerid' => $qClid, 'msg-template' => $qMsgTpl, 'bridge-target' => $qBridgeTgt, 'queue' => $numQueue, 'virtual-oper' => $qVirtOper];
        */

        if (isset($perQSetts['trunk'])) {
            $newNum['trunk'] = $perQSetts['trunk'];
        }

        if (isset($perQSetts['cardid'])) {
            $newNum['cardid'] = $perQSetts['cardid'];
        }

        if (!array_key_exists($num, $campNumsData)) {
            //Logger::log("No such number $num in campaign, add. Clid: $qClid,"
            //." bridge-target: {$qBridgeTgt['context']} {$qBridgeTgt['extension']}");
            Logger::log("No such number $num in campaign, add: " . str_replace("\n", '', var_export($newNum, true)));

            $newNums[] = $newNum;
            continue;
        }
        $numFinished = "false";
        if (isset($campNumsData[$num]['x-finished'])) {
            $numFinished = $campNumsData[$num]['x-finished'];
        }

        if ($numFinished == "false") {
            //Logger::log("Number $num is in campaign, but is not finished. Skip");
            continue;
        }

        // number is in campaign, and is finished. Check new recallNum calldate
        // and when number in campaign was finished

        $campNumCalldate =  $campNumsData[$num]['x-send-date'];

        $recallNumDT = DateTime::createFromFormat('Y-m-d H:i:s', $numCalldate);
        $campNumDT   = DateTime::createFromFormat('Y-m-d H:i:s', $campNumCalldate);

        if ($recallNumDT > $campNumDT) {
            Logger::log("Missed call from $num was later($numCalldate) that"
             ." ai2 task finished in campaign($campNumCalldate). Need to call it again: "
             .str_replace("\n", '', var_export($newNum, true)));

            //Logger::log("Missed call from $num was later($numCalldate) that"
            // ." ai2 task finished in campaign($campNumCalldate). Need to call it again: " . str_replace("\n", '', var_export($newNum, true)));
            // ." Clid: $qClid, bridge-target: {$qBridgeTgt['context']} {$qBridgeTgt['extension']}");

            $newNums[] = $newNum;
        } else {
            //Logger::log("A2I task call to $num was later that missed call. No need to call it again");
            continue;
        }
    }

    if (empty($newNums)) {
        //Logger::log("No new numbers exists to add to $campaign");
        continue;
    }

    // Delete numbers from campaign
    $apiClient->a2iCampaignDataCut($campaign, $newNums);

    // add new numbers to campaign
    //Logger::log("Adding new numbers to $campaign: " . implode(',', $newNums));
    $rslt = $apiClient->a2iCampaignDataAdd($campaign, $newNums);
    if ($rslt->error()) {
        Logger::log("Exit. Error: " . $rslt->errorDesc());
        exit(1);
    }

    if ($dryRun) {
        continue;
    }

    // check campaign status and start campaign if needed
    $rslt = $apiClient->a2iCampaignStatus($campaign);
    if ($rslt->error()) {
        Logger::log("Exit. Error: " . $rslt->errorDesc());
        exit(1);
    }

    $campStatus = $rslt->data()['status'];
    //Logger::log("Campaign $campaign status: $campStatus");

    if ($campStatus != 'running') {
        Logger::log("Starting campaign $campaign ($campStatus)");
        $rslt = $apiClient->a2iCampaignStart($campaign);
        if ($rslt->error()) {
            Logger::log("Exit. Error: " . $rslt->errorDesc());
            exit(1);
        }
    }
}

function checkWorkTime($allowedTime) {
    $wtDays      = $allowedTime['days'];
    $wtStartTime = $allowedTime['starttime'];
    $wtStopTime  = $allowedTime['stoptime'];

    $curDate = date("Y-m-d");

    // check allowed day
    $curDow = date("l"); // current day of week
    if (!in_array($curDow, $wtDays)) {
        return false;
    }

    // check allowed time
    $curTime = date("H:i");

    $curDT   = DateTime::createFromFormat('H:i', $curTime);
    $startDT = DateTime::createFromFormat('H:i', $wtStartTime);
    $stopDT  = DateTime::createFromFormat('H:i', $wtStopTime);

    if (!($curDT >= $startDT && $curDT <= $stopDT)) {
        return false;
    }

    return true;
}
