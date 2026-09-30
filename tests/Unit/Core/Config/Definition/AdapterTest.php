<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\Definition\Adapter;
use NightWorksIO\MutationGate\Core\Config\Definition\Builtins;
use NightWorksIO\MutationGate\Core\Config\ProjectRoot;

it('says an adapter is chosen by a name, a class or an object', function (): void {
    expect(Adapter::choosing(Builtins::treeSources(ProjectRoot::origin()))->expected())
        ->toBe('a name, a class, or an object with use and with');
});
