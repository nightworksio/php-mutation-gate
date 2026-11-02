<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Testo\LaunchOption;
use NightWorksIO\MutationGate\Adapter\Testo\Told;
use NightWorksIO\MutationGate\Core\Native\Candidate;
use NightWorksIO\MutationGate\Core\Native\Selected;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

it('takes the gate\'s options off the command line, and leaves Testo its own in their order', function (): void {
    $told = Told::from(['--no-ansi', '--mutation-gate-stop=after-the-first-failure', '--suite=unit', '--mutation-gate-times=/t.txt']);

    expect($told->arguments())->toBe(['--no-ansi', '--suite=unit'])
        ->and($told->stops())->toBeTrue()
        ->and($told->times())->toBe('/t.txt')
        ->and($told->listing())->toEqual(NotGiven::value())
        ->and(Told::from(['--no-ansi'])->stops())->toBeFalse()
        ->and(Told::from([])->times())->toEqual(NotGiven::value());
});

it('selects the tests its file lists, a line each, and none where the file is not there', function (): void {
    $root = Scratch::directory();
    Scratch::write($root, 'tests.txt', "A::a\n\nB::b\n");
    $selected = Told::from([sprintf('--mutation-gate-tests=%s/tests.txt', $root)])->selected();
    $nowhere = Told::from([sprintf('--mutation-gate-tests=%s/none.txt', $root)])->selected();

    expect($selected->selects(Candidate::of('A::a', '/a.php')))->toBeTrue()
        ->and($selected->selects(Candidate::of('B::b', '/b.php')))->toBeTrue()
        ->and($selected->selects(Candidate::of('C::c', '/c.php')))->toBeFalse()
        ->and($nowhere->selects(Candidate::of('A::a', '/a.php')))->toBeFalse()
        ->and(Told::from([])->selected()->selects(Candidate::of('C::c', '/c.php')))->toBeTrue();
});

it('selects by the last group and filter it was told, and by the files it names by their real paths', function (): void {
    $root = (string) realpath(Scratch::directory());
    Scratch::write($root, 'tests/ATest.php', '<?php');
    $told = Told::from([
        '--mutation-gate-group=first',
        '--mutation-gate-group=holds:src/A.php',
        '--mutation-gate-filter=A',
        sprintf('--mutation-gate-file=%s/tests/../tests/ATest.php', $root),
    ]);
    $held = Candidate::of('A::a', sprintf('%s/tests/ATest.php', $root), Group::holding('src/A.php'));

    expect($told->selected()->selects($held))->toBeTrue()
        ->and($told->selected()->selects(Candidate::of('A::a', sprintf('%s/tests/ATest.php', $root))))->toBeFalse()
        ->and($told->selected()->selects(Candidate::of('B::b', sprintf('%s/tests/ATest.php', $root), Group::holding('src/A.php'))))->toBeFalse()
        ->and($told->selected()->selects(Candidate::of('A::a', '/elsewhere/ATest.php', Group::holding('src/A.php'))))->toBeFalse();
});

it('selects no test in a listing, which runs none', function (): void {
    $told = Told::from(['--mutation-gate-listing=/l.json']);

    expect($told->listing())->toBe('/l.json')
        ->and($told->selected())->toEqual(Selected::listing());
});

it('names the options the launcher keeps from Testo, each set by its name and value', function (): void {
    expect(LaunchOption::names())->toBe([
        '--mutation-gate-tests',
        '--mutation-gate-group',
        '--mutation-gate-filter',
        '--mutation-gate-file',
        '--mutation-gate-stop',
        '--mutation-gate-times',
        '--mutation-gate-listing',
    ])
        ->and(LaunchOption::Times->with('/t.txt'))->toBe('--mutation-gate-times=/t.txt');
});
