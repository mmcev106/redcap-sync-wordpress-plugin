<?php

require_once __DIR__ . '/../../../wp-load.php';
require_once 'REDCapSync.php';
$redcapSync = new REDCapSync();

$url = $_POST['url'];
$pid = $_POST['project-id'];
$eventId = $_POST['event-id'];
$recordId = $_POST['record-id'];

$now = time();
// error_log("redcap_sync_cron_hook scheduled: $url, $pid, $eventId, $recordId, $now");
// The $now variable is only added to the argument list to force the hook to run even if this record was updated in the last 10 minutes (see wp_schedule_single_event() docs).
if(wp_schedule_single_event($now, REDCAP_SYNC_CRON_HOOK, [[$url, $pid, $eventId, $recordId, $now]]) === false) {
	error_log("The following REDCap Sync event could not be scheduled: $url, $pid, $eventId, $recordId");
}
else{
	// This will only trigger the hook that was just scheduled immediately if cron hasn't run within the last minute,
	// but it will keep us from getting a huge backlog of updates that won't run until the next time a user loads a page.
	spawn_cron();
}