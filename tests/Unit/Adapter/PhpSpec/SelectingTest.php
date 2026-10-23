<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\PhpSpec\NotSelected;
use NightWorksIO\MutationGate\Adapter\PhpSpec\Selected;
use NightWorksIO\MutationGate\Adapter\PhpSpec\Selecting;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Tests\Fixtures\PhpSpec\HeldSpec;
use NightWorksIO\MutationGate\Tests\Support\PhpSpecExamples;
use PhpSpec\Exception\Example\SkippingException;
use PhpSpec\Runner\CollaboratorManager;
use PhpSpec\Runner\MatcherManager;

$selecting = static fn(): Selecting => new Selecting(new Selected(
    [PhpSpecExamples::id('it_is_held_by_its_spec')],
    NotGiven::value(),
    NotGiven::value(),
    NotGiven::value(),
    listing: false,
));

/** Whether a maintainer that prepares an example of HeldSpec, then tears it down, lets it run. */
function letsRun(Selecting $selecting, string $method): bool|SkippingException
{
    $example = PhpSpecExamples::held($method);
    $matchers = new MatcherManager(PhpSpecExamples::presenter());
    $collaborators = new CollaboratorManager(PhpSpecExamples::presenter());

    try {
        $selecting->prepare($example, new HeldSpec(), $matchers, $collaborators);
    } catch (SkippingException $skipped) {
        return $skipped;
    }

    $selecting->teardown($example, new HeldSpec(), $matchers, $collaborators);

    return true;
}

it('lets an example the run selects run, and skips one it does not, before anything of it runs', function () use ($selecting): void {
    $skipped = letsRun($selecting(), 'it_is_held_by_its_own_path_too');

    expect(letsRun($selecting(), 'it_is_held_by_its_spec'))->toBeTrue()
        ->and($skipped)->toBeInstanceOf(NotSelected::class)
        ->and($skipped instanceof SkippingException ? $skipped->getMessage() : '')->toBe('skipped: The gate runs only the examples it selects.');
});

it('prepares every example, first of PhpSpec\'s maintainers, the highest of which prepares at 999', function () use ($selecting): void {
    expect($selecting()->supports(PhpSpecExamples::held('it_is_held_by_its_spec')))->toBeTrue()
        ->and($selecting()->getPriority())->toBe(1000);
});
