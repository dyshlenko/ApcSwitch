<?php
/**
 * Example of use ApcSwitch class.
 *
 * @author Igor Dyshlenko
 * @category Console
 * @see example.html
 * @license https://opensource.org/licenses/MIT MIT
 */

?>
<!DOCTYPE html>
<html>
	<head>
		<title>Example of use ApcSwitch class</title>
		<meta charset="UTF-8">
		<meta name="viewport" content="width=device-width, initial-scale=1.0">
	</head>
	<body>
		<pre>
<?php

$start = time();

ini_set('request_order', 'CGP');

// Report all PHP errors
error_reporting(-1);
ini_set('error_reporting', E_ALL);

require_once 'LogWrapper.php';
require_once 'ShellConnector.php';
require_once 'Ssh2Connector.php';
require_once 'Shell.php';
require_once 'ApcSwitch.php';

// Logger initialization
require_once 'Log.php';
$logger = Log::singleton('console');
$logger->setMask(PEAR_LOG_ALL);

const LANE_IP = 'xxx.xxx.xxx.xxx';
const OUTLET_ID = 'Server 1-A';

const
	LOGIN = 'username',
	PASSWORD = 'password';

$logger->info('Run main code.');

$apc = new ApcSwitch(LANE_IP, LOGIN, PASSWORD, $logger);

$s = $apc->getInfo();
echo "\nMain info = ";
var_dump($s);

$b = $apc->getBanksInfo();
echo "\nBanks info = ";
var_dump($b);

echo "\nOutlets list = ";
$ol = $apc->getIds();
var_dump($ol);

echo "\nOutlet info = ";
$o = $apc->getOutletInfo(OUTLET_ID);
var_dump($o);

echo "\nTurn ON Outlet.\n";
$apc->turnOn(OUTLET_ID);
echo "\nOutlet info = ";
$oOn = $apc->getOutletInfo(OUTLET_ID);
var_dump($oOn);

sleep(3);

echo "\nTurn OFF Outlet.\n";
$apc->turn(OUTLET_ID, 'Off');
echo "\nOutlet info = ";
$oOff = $apc->getOutletInfo(OUTLET_ID);
var_dump($oOff);

$apc->disconnect();

echo "\n\nScript finished. Runing time = ", time() - $start, ' seconds.';

?>

		</pre>
	</body>
</html>
