<?php

declare(strict_types=1);

// The config the gate's Testo launcher has Testo read: the project's own
// testo.php, with the gate's plugin added, told what is on the launcher's
// own command line.

use NightWorksIO\MutationGate\Adapter\Testo\GateConfig;

$given = is_array($_SERVER['argv']) ? $_SERVER['argv'] : [];
$arguments = [];

foreach ($given as $argument) {
    $arguments[] = is_string($argument) ? $argument : '';
}

return GateConfig::ofLauncher($arguments);
