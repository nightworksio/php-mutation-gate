<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\ThisPackage;
use NightWorksIO\MutationGate\Tests\Support\Tree;

it('names each of its scripts by its path in its own directory', function (string $name): void {
    expect(ThisPackage::script($name))->toBe(Tree::at(sprintf('bin/%s', $name)))
        ->and(is_file(ThisPackage::script($name)))->toBeTrue();
})->with(['mutation-gate', 'mutation-gate-worker', 'mutation-gate-phpspec']);
