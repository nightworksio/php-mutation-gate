<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\OwnArchive;
use NightWorksIO\MutationGate\Core\OwnScript;
use NightWorksIO\MutationGate\Tests\Support\Tree;

it('starts each script by its path in the gate\'s own directory where Composer installed the gate', function (): void {
    foreach (OwnScript::cases() as $script) {
        expect($script->startedFrom(NotGiven::value()))->toBe([Tree::at(sprintf('bin/%s', $script->value))])
            ->and($script->started())->toBe([Tree::at(sprintf('bin/%s', $script->value))])
            ->and(is_file(Tree::at(sprintf('bin/%s', $script->value))))->toBeTrue();
    }
});

it('starts each script as the gate\'s PHAR with the script\'s name first, from the PHAR', function (): void {
    foreach (OwnScript::cases() as $script) {
        expect($script->startedFrom(OwnArchive::at('/opt/mutation-gate.phar')))->toBe(['/opt/mutation-gate.phar', $script->value]);
    }
});
