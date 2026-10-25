<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\PhpCalls;
use NightWorksIO\MutationGate\Core\Config\ProjectRoot;
use NightWorksIO\MutationGate\Core\Config\Triage;
use NightWorksIO\MutationGate\Core\Time\Seconds;

it('writes the seconds a mutant may run and the most any may as the PHP config calls them', function (): void {
    expect(Triage::of(limit: Seconds::of(30), most: Seconds::of(600))->php(ProjectRoot::origin()))
        ->toEqual(PhpCalls::inWith('Timeouts::seconds(30)', 'Timeouts::most(600)'));
});
