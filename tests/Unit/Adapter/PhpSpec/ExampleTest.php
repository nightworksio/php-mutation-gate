<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\PhpSpec\Example;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Tests\Support\PhpSpecExamples;

it('names an example by its spec\'s class and its method, in the file its spec is declared in', function (): void {
    $example = Example::of(PhpSpecExamples::held('it_is_held_by_its_spec'));

    expect($example->id())->toBe(PhpSpecExamples::id('it_is_held_by_its_spec'))
        ->and($example->file())->toBe((string) realpath(sprintf('%s/Fixtures/PhpSpec/HeldSpec.php', dirname(__DIR__, 3))));
});

it('puts an example in the group of each path its spec holds, then each its method holds', function (): void {
    $spec = Example::of(PhpSpecExamples::held('it_is_held_by_its_spec'));
    $own = Example::of(PhpSpecExamples::held('it_is_held_by_its_own_path_too'));

    expect($spec->groups())->toEqual([Group::holding('src/Money.php')])
        ->and($own->groups())->toEqual([Group::holding('src/Money.php'), Group::holding('src/Held.php')])
        ->and($own->isIn(Group::named('holds:src/Held.php')))->toBeTrue()
        ->and($spec->isIn(Group::named('holds:src/Held.php')))->toBeFalse()
        ->and($spec->isIn(Group::named('holds:src/Money.php')))->toBeTrue();
});
