<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Split\CurrentTest;
use NightWorksIO\MutationGate\Adapter\Pest\Split\ForkFallbacks;
use NightWorksIO\MutationGate\Adapter\Pest\Split\ParentLines;
use NightWorksIO\MutationGate\Adapter\Pest\Split\Recorded;
use NightWorksIO\MutationGate\Adapter\Pest\Split\StreamPlanFile;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

/**
 * Fallbacks of a stream writing to a results file in a scratch directory, and that file.
 *
 * @return array{ForkFallbacks, string}
 */
function forkFallbacks(): array
{
    $root = Scratch::directory();
    $results = sprintf('%s/results.jsonl', $root);
    Scratch::write($root, 'plan.json', (string) json_encode(['results' => $results, 'sites' => []]));
    $plan = StreamPlanFile::read(sprintf('%s/plan.json', $root));

    if ($plan === false) {
        throw new RuntimeException('The plan cannot be read.');
    }

    return [new ForkFallbacks($plan, new ParentLines($plan, Recorded::inProcess())), $results];
}

it('forks a mutant at any test where it has no likely killer, otherwise at its killer alone', function (): void {
    [$fallbacks] = forkFallbacks();

    expect($fallbacks->forks('m', '', 'T::any'))->toBeTrue()
        ->and($fallbacks->forks('m', 'T::killer', 'T::killer'))->toBeTrue()
        ->and($fallbacks->forks('m', 'T::killer', 'T::later'))->toBeFalse();
});

it('falls back on a test it cannot fork in as it begins, or that runs on its own in the parent, and forks its mutants at later tests', function (): void {
    [$fallbacks, $results] = forkFallbacks();
    $fallbacks->began(new CurrentTest('T::held', forkable: false));
    $fallbacks->began(new CurrentTest('T::free', forkable: true));
    $own = new CurrentTest('T::own', forkable: true);
    $fallbacks->isolated($own, parent: true);
    $fallbacks->isolated($own, parent: true);
    $child = new CurrentTest('T::child', forkable: true);
    $fallbacks->isolated($child, parent: false);

    expect(file($results, FILE_IGNORE_NEW_LINES))->toBe(['{"k":"fallback","t":"T::held"}', '{"k":"fallback","t":"T::own"}'])
        ->and($own->isForkable())->toBeFalse()
        ->and($child->isForkable())->toBeFalse()
        ->and($fallbacks->forks('m', 'T::held', 'T::later'))->toBeTrue()
        ->and($fallbacks->forks('m', 'T::own', 'T::later'))->toBeTrue()
        ->and($fallbacks->forks('m', 'T::free', 'T::later'))->toBeFalse()
        ->and($fallbacks->forks('m', 'T::child', 'T::later'))->toBeFalse();
});

it('forks a mutant nowhere more in a test that opened a descriptor before its site, and at later tests', function (): void {
    [$fallbacks, $results] = forkFallbacks();
    $test = new CurrentTest('T::holds', forkable: true);
    $fallbacks->unforked($test, ran: false, file: 'f1', id: 1, mutant: 'm');

    expect(file($results, FILE_IGNORE_NEW_LINES))->toBe(['{"k":"fallback","t":"T::holds","m":"m"}'])
        ->and($test->hasForkedAt('f1', 1))->toBeTrue()
        ->and($test->isForkable())->toBeTrue()
        ->and($fallbacks->forks('m', 'T::holds', 'T::later'))->toBeTrue()
        ->and($fallbacks->forks('n', 'T::holds', 'T::later'))->toBeFalse();
});

it('forks nothing more in a test that started a PHP process, or began holding a descriptor, and falls back on it', function (bool $ran, bool $began): void {
    [$fallbacks, $results] = forkFallbacks();
    $test = new CurrentTest('T::held', forkable: true, heldAtBegin: $began);
    $fallbacks->unforked($test, ran: $ran, file: 'f1', id: 1, mutant: 'm');

    expect(file($results, FILE_IGNORE_NEW_LINES))->toBe(['{"k":"fallback","t":"T::held"}'])
        ->and($test->isForkable())->toBeFalse();
})->with([
    'a process ran' => [true, false],
    'held as it began' => [false, true],
]);
