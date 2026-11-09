<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\GateVariable;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\KillerFile;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\RecordEvent;
use NightWorksIO\MutationGate\Adapter\Pest\Split\OwnCovering;
use NightWorksIO\MutationGate\Adapter\Pest\Split\Parted;
use NightWorksIO\MutationGate\Core\Mutant\TestShare;
use NightWorksIO\MutationGate\Tests\Support\Mutations;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use Pest\Mutate\Event\Facade;
use Pest\Mutate\MutationTest;
use Pest\Mutate\Support\MutationTestResult;

afterEach(function (): void {
    putenv(GateVariable::Results->value);
    Scratch::sweep();
});

/**
 * What a shard's part made of a mutant, as this step left it: its result, its killer file, and the covering tests
 * its own run would select of these.
 *
 * @param  Closure(MutationTest): void                          $step
 * @param  list<string>                                         $covering
 * @return array{MutationTestResult, string, list<string>}
 */
function partedBy(string $id, Closure $step, array $covering): array
{
    $results = sprintf('%s/results.jsonl', Scratch::directory());
    putenv(sprintf('%s=%s', GateVariable::Results->value, $results));
    $test = new MutationTest(Mutations::mutation('src/Money.php', $id, 11, sprintf('%s/%s.php', Scratch::directory(), $id)));
    $events = new ReflectionProperty(Facade::class, 'instance');
    $held = $events->getValue();
    $events->setValue(null, null);

    try {
        $step($test);
    } finally {
        $events->setValue(null, $held);
    }

    $killers = KillerFile::beside($results, $test->mutation->modifiedSourcePath);

    return [
        $test->result(),
        is_file($killers) ? (string) file_get_contents($killers) : '',
        OwnCovering::of($test->mutation, $covering),
    ];
}

it('leaves a mutant to the parts that hold its tests: given a result, so it runs no own run here, and noted as not reached', function (): void {
    expect(partedBy('unreached', Parted::unreached(...), ['T::one']))
        ->toBe([MutationTestResult::Untested, KillerFile::marked(RecordEvent::Unreached), ['T::one']]);
});

it('notes a mutant its part judges by some covering tests, owned or not, and narrows its own run to them', function (TestShare $share, RecordEvent $event): void {
    $judged = static fn(MutationTest $test): null => Parted::judgedBy($test, $share, ['T::two']);

    expect(partedBy(sprintf('parted-%s', $event->value), $judged, ['T::one', 'T::two']))
        ->toBe([MutationTestResult::None, KillerFile::marked($event), ['T::two']]);
})->with([
    'its part owns it' => [TestShare::OwnedPart, RecordEvent::OwnedPart],
    'another part owns it' => [TestShare::OtherPart, RecordEvent::OtherPart],
]);

it('notes nothing of a mutant its part holds every covering test of, nor narrows its own run', function (): void {
    $judged = static fn(MutationTest $test): null => Parted::judgedBy($test, TestShare::All, ['T::one']);

    expect(partedBy('whole', $judged, ['T::one']))->toBe([MutationTestResult::None, '', ['T::one']]);
});

it('notes nothing where the gate records no run', function (): void {
    $test = new MutationTest(Mutations::mutation('src/Money.php', 'unrecorded', 11, sprintf('%s/unrecorded.php', Scratch::directory())));
    Parted::judgedBy($test, TestShare::OtherPart, ['T::two']);

    expect(glob(sprintf('%s/*.killers', Scratch::directory())))->toBe([])
        ->and(OwnCovering::of($test->mutation, ['T::one', 'T::two']))->toBe(['T::two']);
});
