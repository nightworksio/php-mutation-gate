<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Config\Floor;
use NightWorksIO\MutationGate\Config\Gate;
use NightWorksIO\MutationGate\Config\Runner;
use NightWorksIO\MutationGate\Config\Tree;

return Gate::configure()
    ->runner(Runner::pest())
    ->trees(Tree::at('src', floor: 100))
    ->newCode(Floor::of(100));
