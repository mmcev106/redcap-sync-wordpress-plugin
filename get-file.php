<?php
require_once 'REDCapSync.php';
$redcapSync = new REDCapSync();

$domain = $_GET['domain'];
$pid = $_GET['pid'];
$recordId = $_GET['record-id'];
$field = $_GET['field'];

$dirPath = $redcapSync->getFileCacheDir($domain, $pid, $recordId);
@mkdir($dirPath, 0777, true);
$filePath = "$dirPath/$field";

if(!file_exists($filePath)){
	require_once __DIR__ . '/../../../wp-load.php';
	$query = $redcapSync->getProjectQuery(['pid' => $pid]);
	$query->the_post();

	$url = $redcapSync->get_post_meta('url');
	$token = $redcapSync->get_post_meta('token');
	$response = $redcapSync->request($url, $token, [
		'content' => 'file',
		'action' => 'export',
		'record' => $recordId,
		'field' => $field
	]);

	file_put_contents($filePath, $response);
}

header('Content-Type: ' . mime_content_type($filePath));
readfile($filePath);