<?php

require_once __DIR__ . '/../../../wp-load.php';
require_once 'REDCapSync.php';
$redcapSync = new REDCapSync();


$params = $_POST;
$timestamp = time();

// The timestamp is only added to the argument list to force the hook to run even if this record was updated in the last 10 minutes (see wp_schedule_single_event() docs).
$params['timestamp'] = $timestamp;

$params = json_encode($params);

if(wp_schedule_single_event($timestamp, REDCAP_SYNC_CRON_HOOK, [$params]) === false) {
	$redcapSync->sendErrorEmail("The following REDCap Sync event could not be scheduled: " . $params);
}
else{
	// This will only trigger the hook that was just scheduled immediately if cron hasn't run within the last minute,
	// but it will keep us from getting a huge backlog of updates that won't run until the next time a user loads a page.
	spawn_cron();
}

echo 'success';