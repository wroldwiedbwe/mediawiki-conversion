<?php

/**
 * WebPlatform MediaWiki Conversion.
 *
 * Autoloaded by composer
 **/

// set to run indefinitely if needed
set_time_limit(0);
ini_set('memory_limit', '3024M');

/* Optional. It’s better to do it in the php.ini file */
date_default_timezone_set('America/Montreal');

$_SERVER['REMOTE_ADDR']='127.0.0.1';

//define('DATA_DIR', '/vagrant/mediawiki-conversion/data');
define('DATA_DIR', '/Users/renoirb/workspaces/webplatform/service-wiki/mediawiki-conversion/data');
//define('DATA_DIR', '/vagrant/mediawiki-conversion/out');
define('GIT_OUTPUT_DIR', '/Users/renoirb/workspaces/webplatform/service-wiki/mediawiki-conversion/out');
