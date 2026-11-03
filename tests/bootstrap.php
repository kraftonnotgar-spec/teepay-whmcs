<?php
/**
 * PHPUnit bootstrap: load Composer's autoloader for the tests and the module's own autoloader.
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/modules/gateways/teepay/lib/autoload.php';
