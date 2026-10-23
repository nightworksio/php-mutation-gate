<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\PhpSpec\Example;
use NightWorksIO\MutationGate\Adapter\PhpSpec\Selected;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Test\Filter;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Tests\Support\PhpSpecExamples;

$spec = static fn(): Example => Example::of(PhpSpecExamples::held('it_is_held_by_its_spec'));
$own = static fn(): Example => Example::of(PhpSpecExamples::held('it_is_held_by_its_own_path_too'));
$none = static fn(): NotGiven => NotGiven::value();

it('selects every example where it is told nothing', function () use ($spec, $none): void {
    expect(new Selected($none(), $none(), $none(), $none(), listing: false)->selects($spec()))->toBeTrue();
});

it('selects only an example that every one of its narrowings selects', function (string $listed, string $held, string $pattern, string $spec, bool $selected) use ($own): void {
    $fixture = (string) realpath(sprintf('%s/Fixtures/PhpSpec/HeldSpec.php', dirname(__DIR__, 3)));
    $narrowed = new Selected(
        $listed === '' ? NotGiven::value() : [PhpSpecExamples::id($listed)],
        $held === '' ? NotGiven::value() : Group::holding($held),
        $pattern === '' ? NotGiven::value() : Filter::matching($pattern),
        $spec === '' ? NotGiven::value() : [$spec === 'fixture' ? $fixture : $spec],
        listing: false,
    );

    expect($narrowed->selects($own()))->toBe($selected);
})->with([
    'listed' => ['it_is_held_by_its_own_path_too', '', '', '', true],
    'not listed' => ['it_is_held_by_its_spec', '', '', '', false],
    'in the group' => ['', 'src/Held.php', '', '', true],
    'outside the group' => ['', 'src/Other.php', '', '', false],
    'matching the filter' => ['', '', 'own_path', '', true],
    'not matching the filter' => ['', '', 'nothing', '', false],
    'of a spec file told' => ['', '', '', 'fixture', true],
    'of no spec file told' => ['', '', '', '/elsewhere/HeldSpec.php', false],
    'listed, outside the group' => ['it_is_held_by_its_own_path_too', 'src/Other.php', '', '', false],
]);

it('selects no example for a listing', function () use ($spec, $none): void {
    expect(new Selected($none(), $none(), $none(), $none(), listing: true)->selects($spec()))->toBeFalse();
});
