<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\PhpUnit\Outcome;
use NightWorksIO\MutationGate\Adapter\PhpUnit\Recorded;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Mutant\OrderDigest;
use NightWorksIO\MutationGate\Core\Mutant\Prefix;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

/** What a results file with these lines records. */
function recordedFrom(string $lines, TestId ...$selected): Recorded
{
    $results = sprintf('%s/results.txt', Scratch::directory());
    file_put_contents($results, $lines);

    return Recorded::in($results, TestIds::of(...$selected));
}

/** @return list<string> */
function killersIn(Recorded $recorded): array
{
    return array_map(static fn(TestId $test): string => $test->value(), [...$recorded->killers()]);
}

it('names the tests that failed or errored, and each that started and never finished, as killers', function (): void {
    $recorded = recordedFrom(
        implode('', [Outcome::Started->line('T::passes'), Outcome::Passed->line('T::passes'), Outcome::Started->line("T::fails#with a\nbreak"), Outcome::Failed->line("T::fails#with a\nbreak"), Outcome::Started->line('T::errs'), Outcome::Errored->line('T::errs'), Outcome::Started->line('T::dies')]),
    );

    expect(killersIn($recorded))->toBe(["T::fails#with a\nbreak", 'T::errs', 'T::dies'])
        ->and($recorded->ranAny())->toBeTrue()
        ->and($recorded->skippedEach())->toBeFalse();
});

it('credits a stopped run\'s kill to the selected tests that failed or errored, and to none that never finished', function (): void {
    $recorded = recordedFrom(
        implode('', [Outcome::Started->line('T::fails'), Outcome::Failed->line('T::fails'), Outcome::Started->line('T::errs'), Outcome::Errored->line('T::errs'), Outcome::Started->line('U::beside'), Outcome::Failed->line('U::beside'), Outcome::Started->line('T::dies')]),
        TestId::of('T::fails'),
        TestId::of('T::errs'),
        TestId::of('T::dies'),
    );

    expect(array_map(static fn(TestId $test): string => $test->value(), [...$recorded->creditedFailures()]))->toBe(['T::fails', 'T::errs'])
        ->and(array_map(static fn(TestId $test): string => $test->value(), [...$recorded->credited()]))->toBe(['T::fails', 'T::errs', 'T::dies']);
});

it('says every test that ran was skipped only where none passed, failed or died', function (string $lines, bool $skipped): void {
    expect(recordedFrom($lines, TestId::of('T::a'), TestId::of('T::b'))->skippedEach())->toBe($skipped);
})->with([
    'every one skipped' => [implode('', [Outcome::Started->line('T::a'), Outcome::Neither->line('T::a')]), true],
    'one passed' => [implode('', [Outcome::Neither->line('T::a'), Outcome::Passed->line('T::b')]), false],
    'one skipped before it was prepared' => [implode('', [Outcome::Started->line('T::a'), Outcome::Neither->line('T::a')]), true],
    'one died' => [implode('', [Outcome::Neither->line('T::a'), Outcome::Started->line('T::b')]), false],
    'none ran' => ['', false],
]);

it('lets a test the run did not select kill, and not pass', function (): void {
    $passed = recordedFrom(implode('', [Outcome::Neither->line('T::a'), Outcome::Passed->line('U::b')]), TestId::of('T::a'));
    $failed = recordedFrom(implode('', [Outcome::Neither->line('T::a'), Outcome::Failed->line('U::b')]), TestId::of('T::a'));

    expect($passed->skippedEach())->toBeTrue()
        ->and(killersIn($passed))->toBe([])
        ->and($failed->skippedEach())->toBeFalse()
        ->and(killersIn($failed))->toBe(['U::b']);
});

it('records nothing where there is no file, or no line it reads', function (): void {
    $none = Recorded::in(sprintf('%s/none.txt', Scratch::directory()), TestIds::none());
    $unread = recordedFrom("unknown T%3A%3Aa\nstarted\n\n");

    expect([$none->ranAny(), count($none->killers())])->toBe([false, 0])
        ->and(killersIn($unread))->toBe([''])
        ->and($unread->ranAny())->toBeTrue();
});

it('names each selected test of a class whose setUpBeforeClass failed a killer, and no other', function (): void {
    $recorded = recordedFrom(
        Outcome::ClassFailed->line('Tests\\A'),
        TestId::of('Tests\\A::one'),
        TestId::of('Tests\\A::two#0'),
        TestId::of('Tests\\B::three'),
    );

    expect(killersIn($recorded))->toBe(['Tests\\A::one', 'Tests\\A::two#0'])
        ->and($recorded->ranAny())->toBeTrue()
        ->and($recorded->skippedEach())->toBeFalse();
});

it('says where its first killer stood in the order its tests started, keyed by that order up to its last killer', function (): void {
    $recorded = recordedFrom(implode('', [
        Outcome::Started->line('T::passes'),
        Outcome::Passed->line('T::passes'),
        Outcome::Started->line('T::fails'),
        Outcome::Failed->line('T::fails'),
        Outcome::Started->line('T::also'),
        Outcome::Passed->line('T::also'),
        Outcome::Started->line('T::dies'),
        Outcome::Started->line('T::after'),
        Outcome::Passed->line('T::after'),
    ]));

    expect($recorded->prefix())->toEqual(
        Prefix::keyedAt(2, Prefix::keyOf(Paths::none(), OrderDigest::of(TestId::of('T::passes'), TestId::of('T::fails'), TestId::of('T::also'), TestId::of('T::dies'))->value())),
    );
});

it('gives no prefix where no killer started, as one whose class failed before it, or none killed', function (string $lines): void {
    expect(recordedFrom($lines, TestId::of('C::selected'))->prefix())->toEqual(NotGiven::value());
})->with([
    'a class that failed before its tests' => [Outcome::ClassFailed->line('C')],
    'every test passed' => [implode('', [Outcome::Started->line('T::passes'), Outcome::Passed->line('T::passes')])],
    'no line' => [''],
]);
