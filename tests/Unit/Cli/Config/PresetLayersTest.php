<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Config\PresetLayers;
use NightWorksIO\MutationGate\Cli\FirstParty;
use NightWorksIO\MutationGate\Core\Config\Listed;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\Registry\Origin;
use NightWorksIO\MutationGate\Core\ThisPackage;
use NightWorksIO\MutationGate\Extension\Extensions;

it('has a problem at the path of each preset nothing registers, in order', function (): void {
    $registry = new FirstParty()->extend(Extensions::of(Origin::of(ThisPackage::COMPOSER)));
    $problems = PresetLayers::named(Listed::of('library', 'nowhere', 'elsewhere'), $registry)->problems();

    expect(array_map(static fn(Problem $problem): string => sprintf('%s: %s', $problem->path(), $problem->message()), $problems))
        ->toBe([
            'preset[1]: No preset is registered as "nowhere".',
            'preset[2]: No preset is registered as "elsewhere".',
        ]);
});
