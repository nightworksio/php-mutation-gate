<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\Choice;
use NightWorksIO\MutationGate\Core\Config\GateMethod;
use NightWorksIO\MutationGate\Core\Config\Options;
use NightWorksIO\MutationGate\Core\Config\PhpCalls;
use NightWorksIO\MutationGate\Core\Config\ProjectRoot;
use NightWorksIO\MutationGate\Core\Config\Setup;

it('writes the phpunit tree source with no options by its builder method, with no fallback paths', function (): void {
    expect(Setup::of(treeSource: Choice::of('phpunit', Options::none()))->php(ProjectRoot::origin()))
        ->toEqual(PhpCalls::onGate(GateMethod::TreeSource, 'Source::phpunit()'));
});
