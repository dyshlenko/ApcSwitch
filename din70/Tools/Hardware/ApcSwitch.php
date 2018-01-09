<?php

/**
 * ApcSwitch manages the power switch of the APC Switched Rack PDU over ssh2
 * connection.
 * The device allows users to assign different access to outlets. Because of
 * this, the device menus vary depending on the access rights of the particular
 * user. Therefore, access to the outlets is carried out not by the number of
 * menu items, but by the string identifiers of the outlets assigned in the
 * administrative panel of the device.
 *
 * @author Igor Dyshlenko
 * @category Console
 * @license https://opensource.org/licenses/MIT MIT
 */

namespace din70\Tools\Hardware;

class ApcSwitch
{

    protected
            $shell,
            $info    = array(), // Main device info (version, uptime, etc.)
            $ids     = array(), // Outlets string ID's
            $outlets = array(), // Outlet parameters (associative array)
            $banks   = array(), // Banks info
            $logger,
            $host,
            $currentMenu;

    const
            NMC_AOS = 'Network Management Card AOS',
            RPDU_APP = 'Rack PDU APP',
            ESCAPE = "\x1B",
            COMMAND_PROMPT = '> ';
    const
            DEVICE_MANAGER = 'Device Manager',
            BANK_MONITOR = 'Bank Monitor',
            OUTLET_MANAGAMENT = 'Outlet Management',
            OUTLET_CONTROL = 'Outlet Control/Configuration';

    protected static
            $getInfo  = array('1', '2', '1'),
            $getIds   = array(
                self::DEVICE_MANAGER,
                self::OUTLET_MANAGAMENT,
                self::OUTLET_CONTROL
                    ),
            $getBanks = array(
                self::DEVICE_MANAGER
    );

    /**
     * Constructor
     * @param string $host - host name or IP address
     * @param string $username - user name for login to host
     * @param string $password - password for login to host
     * @param Log $logger - logger (PEAR Log object or null)
     * @throws RuntimeException - error connect to host or login error
     */
    public function __construct($host, $username, $password, $logger = null)
    {
        $this->host   = $host;
        $this->logger = new LogWrapper($logger);

        try {
            $this->shell = new Shell(new Ssh2Connector($host, 22, $logger),
                                                       self::COMMAND_PROMPT,
                                                       null, $logger);
            $this->shell->login($username, $password);
            $this->shell->eol("\r");
            $this->shell->goAhead();
        } catch (Exception $exc) {
            $msg = 'Error communicate to ' . $host . '.';
            $this->logger->err(__METHOD__ . ': ' . $msg . "\n" . $exc->getTraceAsString());
            throw new RuntimeException($msg, null, $exc);
        }

        $this->logger->debug(__METHOD__ . ': Shell connected.');
        $array = $this->filterScreenArray(explode("\n",
                                                  $this->shell->getResult()));

        $this->prepareVersion(self::NMC_AOS, array_shift($array));
        $this->prepareVersion(self::RPDU_APP, array_shift($array));
        array_shift($array);

        $this->logger->debug(__METHOD__ . ': Versions prepared.');

        while (FALSE === strpos(($str = array_shift($array)), '-----')) {
            $this->prepareParamString($str);
        }

        $this->logger->debug(__METHOD__ . ': Parameters prepared.');
        $this->parseMenu($array);
        $this->logger->debug(__METHOD__ . ': Menu prepared. ' . var_export($this->currentMenu,
                                                                           true));
    }

    protected function prepareVersion($needle, $haystack)
    {
        if (FALSE !== ($pos = strpos($haystack, $needle))) {
            $this->info[$needle] = trim(substr($haystack, $pos + strlen($needle)));
        }
    }

    protected function prepareParamString($paramString)
    {
        if (FALSE === ($pos = strpos($paramString, '          '))) {
            $this->prepareParam($paramString);
        } else {
            $this->prepareParam(substr($paramString, 0, $pos));
            $this->prepareParam(substr($paramString, $pos));
        }
    }

    protected function prepareParam($str)
    {
        $arr = explode(': ', $str);
        if (isset($arr[0]) && isset($arr[1])) {
            $this->info[trim($arr[0])] = trim($arr[1]);
        }
    }

    /**
     * Get main device info.
     * @return array - associative array with main information of device.
     */
    public function getInfo()
    {
        return $this->info;
    }

    /**
     * Get outlets ID list.
     * @return array Outlets ID list.
     * @throws RuntimeException - communicate error to host
     */
    public function getIds()
    {
        if (empty($this->ids)) {
            $this->gotoPage(self::$getIds);

            foreach ($this->currentMenu as $key => $value) {
                $this->logger->debug(__METHOD__ . ': pair ' .
                        var_export($key, true) . ' => ' .
                        var_export($value, true));
                $state = strtoupper(trim(strrchr($key, ' ')));
                if ($state === 'ON' || $state === 'OFF') {
                    $id = trim(substr($key, 0, strlen($key) - strlen($state)));
                } else {
                    $id    = trim($key);
                    $state = null;
                }
                $this->logger->debug(__METHOD__ . ': ID ' .
                        var_export($id, true) . ' => state ' .
                        var_export($state, true));
                $this->ids[$value] = $id;
                if (isset($this->outlets[$id]) && is_array($this->outlets[$id])) {
                    $this->outlets[$id]['State'] = $state;
                } else {
                    $this->outlets[$id] = array('State' => $state, 'Name' => $id);
                }
            }

            $this->returnToFirstPage(self::$getIds);
        }

        return $this->ids;
    }

    /**
     * Get power banks main info.
     * @return array - associative array with banks main info.
     * @throws RuntimeException - communicate error to host
     */
    public function getBanksInfo()
    {
        if (empty($this->banks)) {
            $this->gotoPage(self::$getBanks);

            try {
                $result = $this->shell->exec($this->currentMenu[self::BANK_MONITOR]);
            } catch (Exception $exc) {
                $msg = 'Error communicate to ' . $this->host . '.';
                $this->logger->err(__METHOD__ . ': ' . $msg . "\n" . $exc->getTraceAsString());
                throw new RuntimeException($msg, null, $exc);
            }
            $array = explode("\n", $result);
            $this->parseMenu($array);
            $this->prepareBanksParams($array);

            $arr = array_fill(0, count(self::$getBanks) + 1, null);
            $this->returnToFirstPage($arr);
            $this->logger->debug(__METHOD__ . ': ' . self::BANK_MONITOR . ' data loaded successfully.');
        }

        return $this->banks;
    }

    protected function prepareBanksParams($array)
    {
        foreach ($array as $key => $string) {
            if (FALSE !== stripos($string, self::BANK_MONITOR)) {
                unset($array[$key]);
                break;
            }
            unset($array[$key]);
        }
        foreach ($array as $key => $string) {
            if (FALSE !== strpos($string, '----')) {
                unset($array[$key]);
                break;
            }
            unset($array[$key]);
        }
        foreach ($array as $string) {
            $length = strlen($str    = str_replace('  ', ' ', trim($string)));
            while ($length > ($l      = strlen($str    = str_replace('  ', ' ',
                                                                     $str)))) {
                $length = $l;
            }
            $values = explode(' ', $str);
            if (count($values) < 7) {
                break;
            }
            $this->banks[$values[0]] = array(
                'Restrictions' => $values[1],
                'Load'         => $values[2],
                'Low'          => $values[3],
                'NearOver'     => $values[4],
                'Over'         => $values[5],
                'State'        => implode(' ', array_slice($values, 6))
            );
        }
    }

    protected function gotoPage(&$path)
    {
        foreach ($path as $command) {
            try {
                $result = $this->shell->exec($this->currentMenu[$command]);
            } catch (Exception $exc) {
                $msg = 'Error communicate to ' . $this->host . '.';
                $this->logger->err(__METHOD__ . ': ' . $msg . "\n" . $exc->getTraceAsString());
                throw new RuntimeException($msg, null, $exc);
            }
            $this->parseMenu(explode("\n", $result));
        }
    }

    protected function returnToFirstPage(&$path)
    {
        foreach ($path as $nothing) {
            try {
                $this->shell->write(self::ESCAPE);
                $this->shell->goAhead();
            } catch (Exception $exc) {
                $msg = 'Error communicate to ' . $this->host . '.';
                $this->logger->err(__METHOD__ . ': ' . $msg . "\n" . $exc->getTraceAsString());
                throw new RuntimeException($msg, null, $exc);
            }
            $this->parseMenu(explode("\n", $this->shell->getResult()));
        }
    }

    protected function parseMenu($stringsArray)
    {
        $this->currentMenu = array();
        foreach ($stringsArray as $string) {
            $pair = explode('- ', $string);
            if (isset($pair[0]) && isset($pair[1]) && (intval(trim($pair[0])) > 0)) {
                $this->currentMenu[trim($pair[1])] = intval(trim($pair[0]));
            }
        }
    }

    /**
     * Get detail outlet info.
     * @param string $outletId - string outlet ID returned $this->getIds().
     * @return associative array with detail information of outlet.
     * @throws RuntimeException - communicate error to host
     */
    public function getOutletInfo($outletId)
    {
        if ($this->isOutletIdOk($outletId)) {
            $this->outletOperation($outletId);
            return $this->outlets[$outletId];
        }

        return null;
    }

    /**
     * Turn outlet to "ON" or "OFF".
     * @param string $outletId - string outlet ID returned $this->getIds().
     * @param mixed $newState - string "on" or "yes", int 1 or bool true for turn
     * 				"ON"; string "off", "no" or "" (empty string), int 0 or bool
     * 				false for turn "OFF"; otherwise do nothing.
     * @param mixed $delayed - use delayed operation if string "on" or "yes",
     * 				int 1 or bool true (default FALSE).
     * @return mixed new state: boolean true if turned ON, false if turned OFF,
     * 				NULL if operation incomplette or outlet ID is incorrect.
     * @throws RuntimeException - communicate error to host
     */
    public function turn($outletId, $newState, $delayed = false)
    {
        $stateTo = filter_var($newState, FILTER_VALIDATE_BOOLEAN,
                              FILTER_NULL_ON_FAILURE);

        if (is_bool($stateTo) && $this->isOutletIdOk($outletId)) {
            $useDelayed = filter_var($delayed, FILTER_VALIDATE_BOOLEAN);
            return $this->outletOperation($outletId, $stateTo ? 'On' : 'Off',
                                          $useDelayed);
        }

        return null;
    }

    /**
     * Turn outlet to "ON".
     * @param string $outletId - string outlet ID returned $this->getIds().
     * 				returned $this->getIds().
     * @param mixed $delayed - use delayed operation if string "on" or "yes",
     * 				int 1 or bool true (default FALSE).
     * @return mixed new state: boolean true if turned ON, false if turned OFF,
     * 				NULL if operation incomplette or outlet ID is incorrect.
     * @throws RuntimeException - communicate error to host
     */
    public function turnOn($outletId, $delayed = false)
    {
        return $this->turn($outletId, true, $delayed);
    }

    /**
     * Turn outlet to "OFF".
     * @param string $outletId - string outlet ID returned $this->getIds().
     * @param mixed $delayed - use delayed operation if string "on" or "yes",
     * 				int 1 or bool true (default FALSE).
     * @return mixed new state: boolean true if turned ON, false if turned OFF,
     * 				NULL if operation incomplette or outlet ID is incorrect.
     * @throws RuntimeException - communicate error to host
     */
    public function turnOff($outletId, $delayed = false)
    {
        return $this->turn($outletId, false, $delayed);
    }

    /**
     * Reboot operation. Turn "OFF" the outlet, then after pause - turn it "ON".
     * @param string $outletId - string outlet ID returned $this->getIds().
     * @param mixed $delayed - use delayed operation if string "on" or "yes",
     * 				int 1 or bool true (default FALSE).
     * @return mixed new state: boolean true if turned ON, false if turned OFF,
     * 				NULL if operation incomplette or outlet ID is incorrect.
     * @throws RuntimeException - communicate error to host
     */
    public function reboot($outletId, $delayed = false)
    {
        if ($this->isOutletIdOk($outletId)) {
            $useDelayed = filter_var($delayed, FILTER_VALIDATE_BOOLEAN);
            return $this->outletOperation($outletId, 'Reboot', $useDelayed);
        }

        return null;
    }

    /**
     * Cancel all pending (delayed) commands for outlet.
     * @param string $outletId - string outlet ID returned $this->getIds().
     * @throws RuntimeException - communicate error to host
     */
    public function cancelDelayed($outletId)
    {
        if ($this->isOutletIdOk($outletId)) {
            return $this->outletOperation($outletId, 'Cancel');
        }

        return null;
    }

    /**
     * Get APC Switch host name or IP.
     * @return string host name or IP.
     */
    public function getHost()
    {
        return $this->host;
    }

    protected function isOutletIdOk($outletId)
    {
        $this->getIds();
        return is_int($outletId) && array_key_exists($outletId, $this->ids) ||
                is_string($outletId) && in_array($outletId, $this->ids);
    }

    protected function outletOperation($outletId, $operation = null,
                                       $delayed = false)
    {
        $complette = false;
        $command   = is_int($outletId) ? $outletId : array_search($outletId,
                                                                  $this->ids);
        $this->gotoPage(self::$getIds);

        try {
            $result = $this->shell->exec($command);
        } catch (Exception $exc) {
            $msg = 'Error communicate to ' . $this->host . '.';
            $this->logger->err(__METHOD__ . ': ' . $msg . "\n" . $exc->getTraceAsString());
            throw new RuntimeException($msg, null, $exc);
        }

        $this->prepareOutletOperationScreenArray($this->ids[$command],
                                                 explode("\n", $result));
        $menuItem = $this->switchOutletOperationName($operation, $delayed,
                                                     $command);

        $this->logger->debug(__METHOD__ . ': Operation "' .
                (is_null($menuItem) ? 'nothing' : $menuItem) . '".');
        if (isset($this->currentMenu[$menuItem])) {
            try {
                $this->shell->write($this->currentMenu[$menuItem] .
                        $this->shell->eol() . 'yes' . $this->shell->eol());
                $this->shell->read('Press <ENTER> to continue...');
                $this->shell->getResult();
                $this->shell->write($this->shell->eol());
                $this->shell->goAhead();
            } catch (Exception $exc) {
                $msg = 'Error communicate to ' . $this->host . '.';
                $this->logger->err(__METHOD__ . ': ' . $msg . "\n" . $exc->getTraceAsString());
                throw new RuntimeException($msg, null, $exc);
            }
            $this->prepareOutletOperationScreenArray($this->ids[$command],
                                                     explode("\n",
                                                             $this->shell->getResult()));
            $complette = true;
            $this->logger->debug(__METHOD__ . ': Operation "' . $menuItem . '" complette.');
        } elseif (!is_null($menuItem)) {
            $msg = 'Incorrect operation "' . var_export($menuItem, true) . '".';
            $this->logger->err(__METHOD__ . ': ' . $msg);
            throw new RuntimeException($msg);
        }

        $arr = array_fill(0, count(self::$getIds) + 1, null);
        $this->returnToFirstPage($arr);

        return $complette;
    }

    protected function prepareOutletParam($outletId, $str)
    {
        $arr = explode(': ', $str);
        if (isset($arr[0]) && isset($arr[1])) {
            $this->outlets[$outletId][trim($arr[0])] = trim($arr[1]);
            return true;
        }
        return false;
    }

    protected function filterScreenArray($screenArray)
    {
        unset($screenArray[0]);
        $prompt = trim($this->shell->prompt());
        foreach ($screenArray as $i => $str) {
            $str = trim($str);
            if (empty($str) || ($prompt === $str)) {
                unset($screenArray[$i]);
            }
        }
        return $screenArray;
    }

    protected function prepareOutletOperationScreenArray($strOutletId,
                                                         $screenArray)
    {
        $array = $this->filterScreenArray($screenArray);

        array_shift($array);
        while ($this->prepareOutletParam($strOutletId,
                                         ($str = array_shift($array)))) {
            
        }
        array_unshift($array, $str);

        $this->parseMenu($array);
    }

    protected function switchOutletOperationName($operationName, $delayed,
                                                 $intOutletId)
    {
        switch (strtoupper($operationName)) {
            case 'ON':
                return ($delayed ? 'Delayed' : 'Immediate') . ' On';

            case 'OFF':
                return ($delayed ? 'Delayed' : 'Immediate') . ' Off';

            case 'REBOOT':
                return ($delayed ? 'Delayed' : 'Immediate') . ' Reboot';

            case 'CANCEL':
                $menuItem = 'Cancel';
                if ($this->ids[$intOutletId] === 'ALL Accessible Outlets') {
                    $menuItem .= ' Pending Commands';
                }
                return $menuItem;
        }

        return null;
    }

    public function disconnect()
    {
        try {
            $this->shell->logout();
        } catch (Exception $ex) {
            
        }
    }

}
