<?php

/* 
 * Common data for phpUnit testsuites.
 */

const COMMAND = 'whoami';

if (file_exists('./realTestLoginData.php')) {
    include_once './realTestLoginData.php';
} else {
    define('HOSTNAME', 'localhost');
    define('HOSTPORT', 22);
    define('USERNAME', 'username');
    define('USERPASS', 'userpassword');
}
