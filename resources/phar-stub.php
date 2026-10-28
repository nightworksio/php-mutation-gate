#!/usr/bin/env php
<?php

/*
 * The gate's PHAR (ADR-0022). Run as PHP's script, it is the gate. Prepended to another script with
 * auto_prepend_file, as the gate prepends it to a framework's run, it loads the archive's autoloader alone, so
 * the gate's classes the run loads are found, and hands back to the script PHP runs.
 */

Phar::mapPhar('mutation-gate.phar');

if (realpath((string) $_SERVER['SCRIPT_FILENAME']) !== realpath(__FILE__)) {
    require 'phar://mutation-gate.phar/vendor/autoload.php';

    return;
}

require 'phar://mutation-gate.phar/.box/bin/check-requirements.php';

$_SERVER['SCRIPT_FILENAME'] = 'phar://mutation-gate.phar/bin/mutation-gate';

require 'phar://mutation-gate.phar/bin/mutation-gate';

__HALT_COMPILER();
