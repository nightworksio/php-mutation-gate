<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Config\Gate;
use NightWorksIO\MutationGate\Config\Option;
use NightWorksIO\MutationGate\Config\Proofs;

return Gate::configure()->with(Proofs::uses('Acme\Store', Option::of('path', '../cache')));
