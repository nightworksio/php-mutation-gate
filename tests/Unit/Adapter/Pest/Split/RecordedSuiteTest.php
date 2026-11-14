<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Split\RecordedSuite;
use NightWorksIO\MutationGate\Adapter\Pest\Split\StreamLine;
use NightWorksIO\MutationGate\Core\Coverage\StreamedSuite;
use NightWorksIO\MutationGate\Core\Coverage\TestEnded;
use NightWorksIO\MutationGate\Core\File\Root;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

$ended = static fn(float $seconds, bool $passed = true, bool $troubled = false, bool $own = false): TestEnded => new TestEnded(
    passed: $passed,
    troubled: $troubled,
    own: $own,
    seconds: $seconds,
);

$recorded = static function (string $project, string ...$streams): string {
    $directory = sprintf('%s/recorded', $project);
    mkdir($directory);

    foreach ($streams as $at => $lines) {
        file_put_contents(sprintf('%s/recorded-1-%d.jsonl', $directory, $at), $lines);
    }

    return $directory;
};

it('reads how each test ended and every reported project line, once every stream finished', function () use ($recorded, $ended): void {
    $project = Scratch::directory();
    $money = sprintf('%s/src/Money.php', $project);
    $directory = $recorded(
        $project,
        sprintf(
            '%s%s',
            StreamLine::covered('MoneyTest::adds', [$money => [11]], $ended(0.5)),
            StreamLine::finished([$money => [10, 11, 12], '/elsewhere/pest.php' => [3]]),
        ),
        sprintf(
            '%s%s',
            StreamLine::covered('MoneyTest::subtracts', [$money => [12]], $ended(0.25)),
            StreamLine::finished([$money => [12, 13]]),
        ),
    );
    $read = RecordedSuite::in($directory, Root::of($project));
    $suite = $read instanceof StreamedSuite ? $read : StreamedSuite::none();

    expect($read)->toBeInstanceOf(StreamedSuite::class)
        ->and($suite->reported())->toBe(['src/Money.php' => [10, 11, 12, 13]])
        ->and($suite->secondsByTest())->toBe(['MoneyTest::adds' => 0.5, 'MoneyTest::subtracts' => 0.25])
        ->and($suite->ofSuites(['Unit'])->standsIn())->toBeTrue();
});

it('stands in for nothing where a stream stopped short, a test failed, raised an issue or ran on its own', function (bool $started, bool $finished, bool $passed, bool $troubled, bool $own) use ($recorded, $ended): void {
    $project = Scratch::directory();
    $second = $started ? StreamLine::covered('MoneyTest::subtracts', [], $ended(0.1, $passed, $troubled, $own)) : '';
    $directory = $recorded(
        $project,
        sprintf('%s%s', StreamLine::covered('MoneyTest::adds', [], $ended(0.5)), StreamLine::finished([])),
        sprintf('%s%s', $second, $finished ? StreamLine::finished([]) : ''),
    );
    $suite = RecordedSuite::in($directory, Root::of($project));

    expect($suite)->toBeInstanceOf(StreamedSuite::class)
        ->and($suite instanceof StreamedSuite && $suite->ofSuites(['Unit'])->standsIn())->toBeFalse();
})->with([
    'stopped short' => [true, false, true, false, false],
    'never started' => [false, false, true, false, false],
    'failed' => [true, true, false, false, false],
    'troubled' => [true, true, true, true, false],
    'own process' => [true, true, true, false, true],
]);

it('reads no suite where the streams recorded nothing', function (): void {
    expect(RecordedSuite::in(Scratch::directory(), Root::of(Scratch::directory())))->toBeInstanceOf(NotGiven::class);
});

it('reads past a line that says not what it is, a covered line short of a field, and a finished line\'s lines that are no map or no numbers', function () use ($recorded, $ended): void {
    $project = Scratch::directory();
    $money = sprintf('%s/src/Money.php', $project);
    $directory = $recorded(
        $project,
        implode("\n", [
            '{"t":"MoneyTest::unsaid"}',
            '{"k":"covered","t":"MoneyTest::short","p":true}',
            '{"k":"finished","l":3}',
        ]),
        sprintf('%s%s', StreamLine::covered('MoneyTest::adds', [], $ended(0.5)), sprintf('{"k":"finished","l":{"%s":"none"}}', $money)),
    );
    $read = RecordedSuite::in($directory, Root::of($project));
    $suite = $read instanceof StreamedSuite ? $read : StreamedSuite::none();

    expect($suite->secondsByTest())->toBe(['MoneyTest::adds' => 0.5])
        ->and($suite->reported())->toBe(['src/Money.php' => []])
        ->and($suite->ofSuites(['Unit'])->standsIn())->toBeTrue();
});
