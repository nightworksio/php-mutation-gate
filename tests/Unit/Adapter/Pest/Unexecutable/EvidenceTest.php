<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Unexecutable\Evidence;
use NightWorksIO\MutationGate\Adapter\Pest\Unexecutable\FailedFirst;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\Ran;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

/** The first failing test of a log naming one, of a test that failed saying this. */
function evidenceFailure(string $said): FailedFirst
{
    $log = sprintf('%s/junit.xml', Scratch::directory());
    file_put_contents($log, sprintf('<testsuites><testcase name="it" file="tests/A.php::it"><failure>%s</failure></testcase></testsuites>', $said));
    $failed = FailedFirst::in($log);

    return $failed instanceof FailedFirst ? $failed : throw new LogicException('The log names a failure.');
}

it('says how a run ended, the first test that failed, and the files it ran', function (): void {
    $tests = Paths::of(Path::of('tests/A.php'), Path::of('tests/B.php'));

    expect(Evidence::of(Ran::exited(2, 'out'), Seconds::of(6.0), evidenceFailure('itBroke.'), $tests)->text())
        ->toBe('exit code 2; first failing test tests/A.php::it: Broke.; ran tests/A.php, tests/B.php');
});

it('says a run was stopped at its limit, or that its exit code is not known', function (): void {
    $tests = Paths::of(Path::of('tests/A.php'));

    expect(Evidence::of(Ran::stopped(''), Seconds::of(90.0), NotGiven::value(), $tests)->text())
        ->toBe('stopped at its limit of 1m30s; ran tests/A.php')
        ->and(Evidence::of(Ran::stopped(''), Unmeasured::duration(), NotGiven::value(), $tests)->text())
        ->toBe('no exit code; ran tests/A.php')
        ->and(Evidence::of(Ran::finished(succeeded: false, output: ''), Seconds::of(6.0), NotGiven::value(), $tests)->text())
        ->toBe('no exit code; ran tests/A.php');
});

it('says the last line a failed run printed where no test failed, and nothing printed of a run that succeeded', function (): void {
    $tests = Paths::of(Path::of('tests/A.php'));

    expect(Evidence::of(Ran::exited(255, "first\nPHP  Fatal:\tgone\n  \n"), Seconds::of(6.0), NotGiven::value(), $tests)->text())
        ->toBe('exit code 255; last printed PHP Fatal: gone; ran tests/A.php')
        ->and(Evidence::of(Ran::exited(0, 'fine'), Seconds::of(6.0), NotGiven::value(), $tests)->text())
        ->toBe('exit code 0; ran tests/A.php');
});

it('keeps two hundred characters of a line a run printed, and names three files and how many more it ran', function (): void {
    $tests = Paths::of(Path::of('tests/A.php'), Path::of('tests/B.php'), Path::of('tests/C.php'), Path::of('tests/D.php'), Path::of('tests/E.php'));
    $text = Evidence::of(Ran::exited(255, str_repeat('x', 300)), Seconds::of(6.0), evidenceFailure(str_repeat('y', 300)), $tests)->text();

    expect($text)->toBe(sprintf('exit code 255; first failing test tests/A.php::it: %s…; ran tests/A.php, tests/B.php, tests/C.php and 2 more', str_repeat('y', 199)));
});
