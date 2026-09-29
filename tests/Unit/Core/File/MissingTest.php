<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Missing;
use NightWorksIO\MutationGate\Core\File\Path;

it('names the path that is not there', function (): void {
    expect(Missing::at(Path::of('mutation-gate.baseline.json'))->path()->value())->toBe('mutation-gate.baseline.json');
});
