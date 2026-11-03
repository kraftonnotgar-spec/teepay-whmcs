<?php
/**
 * Minimal PSR-4 style autoloader for the bundled TeePay WHMCS library.
 *
 * The module ships without Composer so it can be copied straight into a WHMCS
 * installation.
 *
 * @license MIT
 */

spl_autoload_register(static function ($class) {
    $prefix = 'TeePay\\Whmcs\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    if (!preg_match('/^[A-Za-z0-9_]+$/', $relative)) {
        return;
    }
    $file = __DIR__ . DIRECTORY_SEPARATOR . $relative . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});
