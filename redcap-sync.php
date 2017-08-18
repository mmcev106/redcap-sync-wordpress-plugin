<?php

/**
 * @wordpress-plugin
 * Plugin Name:       REDCap Sync
 * Description:       Allows for syncing REDCap data to your WordPress site.
 * Version:           1.0.0
 * Author:            Mark McEver
 * Text Domain:       redcap-sync
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

require_once __DIR__ . '/REDCapSync.php';

$redcapSync = new REDCapSync();
$redcapSync->initializePlugin();