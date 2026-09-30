<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Config\Floor;
use NightWorksIO\MutationGate\Config\Gate;

return Gate::configure()->newCode(Floor::of(120));
