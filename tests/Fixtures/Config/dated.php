<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Config\Gate;
use NightWorksIO\MutationGate\Config\Ignore;
use NightWorksIO\MutationGate\Config\Runner;

return Gate::configure()
    ->runner(Runner::pest())
    ->ignoring(Ignore::mutator('Plus', in: 'src/**', because: 'Equivalent', until: '2027-01-31'));
