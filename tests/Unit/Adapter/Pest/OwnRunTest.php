<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\OwnRun;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\RecordEvent;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Mutant\Prefix;
use NightWorksIO\MutationGate\Core\NotGiven;

/** A run of a mutant that ran three tests, none killing it. */
function ownRunOfThree(): OwnRun
{
    return OwnRun::of([], [], [], NotGiven::value(), preloaded: false, tests: 3);
}

it('reaches the end of the whole order it was to take, keyed by the files it loaded, through whatever it learns after', function (): void {
    $order = hash('sha256', 'order');
    $files = Paths::of(Path::of('tests/ASpec.php'));
    $run = ownRunOfThree()->orderedAs(3, $order)->startedWith(['pest'])->marked(RecordEvent::Unfiltered);

    expect($run->planned($files))->toEqual(Prefix::keyedAt(3, Prefix::keyOf($files, $order)))
        ->and($run->arguments())->toBe(['pest'])
        ->and($run->loadedUnfiltered())->toBe([]);
});

it('knows no whole order where none was recorded, or it was to run no test', function (): void {
    $files = Paths::none();

    expect(ownRunOfThree()->planned($files))->toBeInstanceOf(NotGiven::class)
        ->and(ownRunOfThree()->orderedAs(NotGiven::value(), hash('sha256', 'order'))->planned($files))->toBeInstanceOf(NotGiven::class)
        ->and(ownRunOfThree()->orderedAs(3, NotGiven::value())->planned($files))->toBeInstanceOf(NotGiven::class)
        ->and(ownRunOfThree()->orderedAs(0, hash('sha256', 'order'))->planned($files))->toBeInstanceOf(NotGiven::class);
});

it('reaches the end of a whole order of one test', function (): void {
    $order = hash('sha256', 'one');
    $files = Paths::of(Path::of('tests/ASpec.php'));

    expect(ownRunOfThree()->orderedAs(1, $order)->planned($files))->toEqual(Prefix::keyedAt(1, Prefix::keyOf($files, $order)));
});
