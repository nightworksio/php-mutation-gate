<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Native\Candidate;
use NightWorksIO\MutationGate\Core\Native\Launched;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;

/** @return list<string> the options a launcher is told in these tests */
function launchedOptions(): array
{
    return ['--mutation-gate-group', '--mutation-gate-filter', '--mutation-gate-file'];
}

it('sets an option by its name, an equals sign and its value', function (): void {
    expect(Launched::setting('--mutation-gate-group', 'a=b'))->toBe('--mutation-gate-group=a=b');
});

it('takes the gate\'s options off the command line, every value of each in order, and leaves the framework its own', function (): void {
    $launched = Launched::split(launchedOptions(), [
        'run',
        '--mutation-gate-group=a=b',
        '--format=dot',
        '--mutation-gate-group=',
        '--mutation-gate-file=x.php',
    ]);

    expect($launched->own())->toBe(['run', '--format=dot'])
        ->and($launched->values('--mutation-gate-group'))->toBe(['a=b', ''])
        ->and($launched->last('--mutation-gate-group'))->toBe('')
        ->and($launched->last('--mutation-gate-file'))->toBe('x.php')
        ->and($launched->values('--mutation-gate-filter'))->toBe([])
        ->and($launched->last('--mutation-gate-filter'))->toEqual(NotGiven::value())
        ->and($launched->values('--mutation-gate-other'))->toBe([]);
});

it('leaves the framework an option named without its value, one of another name, and one written inside another word', function (string $argument): void {
    expect(Launched::split(launchedOptions(), [$argument])->own())->toBe([$argument]);
})->with(['--mutation-gate-group', '--mutation-gate-other=a', 'x--mutation-gate-group=a', '--group=a']);

it('selects the tests listed, in the group and matching the filter it was told last, declared in the files it names', function (): void {
    $launched = Launched::split(launchedOptions(), [
        '--mutation-gate-group=old',
        '--mutation-gate-group=slow',
        '--mutation-gate-filter=adds',
        '--mutation-gate-file=tests/MoneyTest.php',
    ]);
    $selected = $launched->selecting(
        TestIds::of(TestId::of('MoneyTest::adds'), TestId::of('MoneyTest::addsTwice')),
        '--mutation-gate-group',
        '--mutation-gate-filter',
        '--mutation-gate-file',
        static fn(string $file): string => sprintf('/p/%s', $file),
    );
    $slow = [Group::named('slow')];

    expect($selected->selects(Candidate::of('MoneyTest::adds', '/p/tests/MoneyTest.php', ...$slow)))->toBeTrue()
        ->and($selected->selects(Candidate::of('MoneyTest::adds', '/p/tests/MoneyTest.php', Group::named('old'))))->toBeFalse()
        ->and($selected->selects(Candidate::of('MoneyTest::adds', '/p/tests/LedgerTest.php', ...$slow)))->toBeFalse()
        ->and($selected->selects(Candidate::of('MoneyTest::drains', '/p/tests/MoneyTest.php', ...$slow)))->toBeFalse();
});

it('selects every test where it was told no list, group, filter or file', function (): void {
    $selected = Launched::split(launchedOptions(), [])->selecting(
        NotGiven::value(),
        '--mutation-gate-group',
        '--mutation-gate-filter',
        '--mutation-gate-file',
        static fn(string $file): string => $file,
    );

    expect($selected->selects(Candidate::of('MoneyTest::adds', '/p/tests/MoneyTest.php')))->toBeTrue();
});
