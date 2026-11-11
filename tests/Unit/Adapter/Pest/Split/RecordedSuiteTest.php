<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Split\RecordedSuite;
use NightWorksIO\MutationGate\Adapter\Pest\Split\StreamLine;
use NightWorksIO\MutationGate\Adapter\Pest\Split\TestEnded;
use NightWorksIO\MutationGate\Core\Coverage\StreamedSuite;
use NightWorksIO\MutationGate\Core\File\Root;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

$recorded = static function (string $project, string ...$streams): string {
    $directory = sprintf('%s/recorded', $project);
    mkdir($directory);

    foreach ($streams as $at => $lines) {
        file_put_contents(sprintf('%s/recorded-1-%d.jsonl', $directory, $at), $lines);
    }

    return $directory;
};

it('reads how each test ended and every reported project line, once every stream finished', function () use ($recorded): void {
    $project = Scratch::directory();
    $money = sprintf('%s/src/Money.php', $project);
    $directory = $recorded(
        $project,
        StreamLine::covered('MoneyTest::adds', [$money => [11]], new TestEnded(true, false, false, 0.5))
        . StreamLine::finished([$money => [10, 11, 12], '/elsewhere/pest.php' => [3]]),
        StreamLine::covered('MoneyTest::subtracts', [$money => [12]], new TestEnded(true, false, false, 0.25))
        . StreamLine::finished([$money => [12, 13]]),
    );
    $suite = RecordedSuite::in($directory, Root::of($project));

    expect($suite)->toBeInstanceOf(StreamedSuite::class)
        ->and($suite->reported())->toBe(['src/Money.php' => [10, 11, 12, 13]])
        ->and($suite->secondsByTest())->toBe(['MoneyTest::adds' => 0.5, 'MoneyTest::subtracts' => 0.25])
        ->and($suite->ofSuites(['Unit'])->standsIn())->toBeTrue();
});

it('stands in for nothing where a stream stopped short, a test failed, raised an issue or ran on its own', function (Closure $second) use ($recorded): void {
    $project = Scratch::directory();
    $directory = $recorded(
        $project,
        StreamLine::covered('MoneyTest::adds', [], new TestEnded(true, false, false, 0.5)) . StreamLine::finished([]),
        $second(),
    );
    $suite = RecordedSuite::in($directory, Root::of($project));

    expect($suite)->toBeInstanceOf(StreamedSuite::class)
        ->and($suite->ofSuites(['Unit'])->standsIn())->toBeFalse();
})->with([
    'stopped short' => [static fn(): string => StreamLine::covered('MoneyTest::subtracts', [], new TestEnded(true, false, false, 0.1))],
    'never started' => [static fn(): string => ''],
    'failed' => [static fn(): string => StreamLine::covered('MoneyTest::subtracts', [], new TestEnded(false, false, false, 0.1)) . StreamLine::finished([])],
    'troubled' => [static fn(): string => StreamLine::covered('MoneyTest::subtracts', [], new TestEnded(true, true, false, 0.1)) . StreamLine::finished([])],
    'own process' => [static fn(): string => StreamLine::covered('MoneyTest::subtracts', [], new TestEnded(true, false, true, 0.1)) . StreamLine::finished([])],
]);

it('reads no suite where the streams recorded nothing', function (): void {
    expect(RecordedSuite::in(Scratch::directory(), Root::of(Scratch::directory())))->toBeInstanceOf(NotGiven::class);
});
