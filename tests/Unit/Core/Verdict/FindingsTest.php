<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Assertion\Assertion;
use NightWorksIO\MutationGate\Core\Assertion\AssertionKind;
use NightWorksIO\MutationGate\Core\Assertion\AssertionStyle;
use NightWorksIO\MutationGate\Core\Assertion\WeaklyAsserted;
use NightWorksIO\MutationGate\Core\Assertion\WeakTest;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Php\Declared;
use NightWorksIO\MutationGate\Core\Php\Nameless;
use NightWorksIO\MutationGate\Core\Removal\Removable;
use NightWorksIO\MutationGate\Core\Test\TestName;
use NightWorksIO\MutationGate\Core\Verdict\Findings;
use NightWorksIO\MutationGate\Core\Verdict\NoFinding;
use NightWorksIO\MutationGate\Tests\Support\Removing;
use NightWorksIO\MutationGate\Tests\Support\Weakly;

/** A mutant id of digits alone, which PHP would read as a number where it keys an array. */
function digitsOnly(string $id): MutantId
{
    $parsed = MutantId::parse($id);

    return $parsed instanceof MutantId ? $parsed : MutantId::hash(Path::of(Removing::CART), 'Unparsed', $id, 1);
}

it('adds those others found to what was found, the others\' in place where both found something of a survivor', function (): void {
    $id = digitsOnly('000000000001');
    $other = digitsOnly('000000000002');
    $removable = Removable::callee(Declared::in(Path::of(Removing::CART), 'record', Line::of(1), Line::of(2)));
    $weak = WeaklyAsserted::by(Nameless::code(), WeakTest::of(
        Weakly::weakTest(),
        TestName::in(Path::of(Weakly::TESTS), 'it fits'),
        Assertion::of('->toBeBool()', AssertionKind::Shape, AssertionStyle::Pest),
    ));

    $found = Findings::none()->with($id, $weak)->with($other, $weak)->and(Findings::none()->with($id, $removable));

    expect($found->of($id))->toBe($removable)
        ->and($found->of($other))->toBe($weak)
        ->and(Findings::none()->and(Findings::none())->of($id))->toEqual(NoFinding::survivor());
});
