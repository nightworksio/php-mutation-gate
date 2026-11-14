<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Split\RecordedMap;
use NightWorksIO\MutationGate\Adapter\Pest\Split\StreamLine;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\TestEnded;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Root;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

it('reads each project line every test executed in a stream\'s parent, and the test\'s seconds, leaving out other files', function (): void {
    $project = Scratch::directory();
    $recorded = sprintf('%s/recorded', $project);
    mkdir($recorded);
    file_put_contents(
        sprintf('%s/recorded-1-0.jsonl', $recorded),
        sprintf(
            '%s%s%s',
            StreamLine::covered('MoneyTest::adds', [sprintf('%s/src/Money.php', $project) => [11, 12], '/vendor/pest.php' => [3]], new TestEnded(passed: true, troubled: false, own: false, seconds: 0.5)),
            StreamLine::covered('MoneyTest::subtracts', [sprintf('%s/src/Money.php', $project) => [12]], new TestEnded(passed: true, troubled: false, own: false, seconds: 0.25)),
            StreamLine::finished([sprintf('%s/src/Money.php', $project) => [10, 11, 12]]),
        ),
    );
    $map = RecordedMap::in($recorded, Root::of($project));

    expect($map)->toEqual(
        CoverageMap::of()
            ->covered(Path::of('src/Money.php'), Line::of(11), TestId::of('MoneyTest::adds'))
            ->covered(Path::of('src/Money.php'), Line::of(12), TestId::of('MoneyTest::adds'))
            ->timed(TestId::of('MoneyTest::adds'), Seconds::of(0.5))
            ->covered(Path::of('src/Money.php'), Line::of(12), TestId::of('MoneyTest::subtracts'))
            ->timed(TestId::of('MoneyTest::subtracts'), Seconds::of(0.25)),
    );
});

it('reads no map where the streams recorded nothing', function (): void {
    expect(RecordedMap::in(Scratch::directory(), Root::of(Scratch::directory())))->toBeInstanceOf(NotGiven::class);
});

it('removes every file of lines the streams recorded, and nothing else of the directory', function (): void {
    $directory = Scratch::directory();
    Scratch::write($directory, 'recorded-1-0.jsonl', '{}');
    Scratch::write($directory, 'recorded-2-1.jsonl', '{}');
    Scratch::write($directory, 'kept.json', '{}');

    RecordedMap::cleared($directory);

    expect(glob(sprintf('%s/*', $directory)))->toBe([sprintf('%s/kept.json', $directory)]);
});
