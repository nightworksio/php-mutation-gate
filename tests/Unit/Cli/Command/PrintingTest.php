<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Cli\Command\Printing;
use NightWorksIO\MutationGate\Cli\Command\VerdictOutput;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Report\Problems;
use NightWorksIO\MutationGate\Core\Report\ProblemsShown;
use NightWorksIO\MutationGate\Core\Report\Sources;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\Verdicts;
use Symfony\Component\Console\Output\BufferedOutput;

afterEach(function (): void {
    Scratch::sweep();
});

it('prints the console\'s report, saying nothing before the run', function (): void {
    $output = new BufferedOutput();
    $project = Directory::at(Scratch::directory());

    Printing::console()->begin($output, $project);
    Printing::console()->verdict(Verdicts::failing(), $output, $project);

    expect($output->fetch())->toStartWith("mutation-gate: failed\n");
});

it('prints problems for an editor, framed by the lines a background matcher waits for, with the files read from the project', function (): void {
    $output = new BufferedOutput();
    $project = Scratch::directory();
    Scratch::write($project, 'src/Money.php', Verdicts::MONEY);
    $printing = Printing::of(VerdictOutput::Problems, ProblemsShown::Changed);

    $printing->begin($output, Directory::at($project));
    $printing->verdict(Verdicts::failing(), $output, Directory::at($project));

    expect(explode("\n", rtrim($output->fetch(), "\n")))->toBe([
        Problems::JUDGING,
        rtrim(Problems::text(Verdicts::failing(), Sources::none()->with(Path::of('src/Money.php'), Contents::of(Verdicts::MONEY)), ProblemsShown::Changed), "\n"),
        Problems::JUDGED,
    ]);
});

it('says a line to the console\'s reader, and leaves it out of problems', function (): void {
    $console = new BufferedOutput();
    $problems = new BufferedOutput();

    Printing::console()->note('src scores 40.00%.', $console);
    Printing::of(VerdictOutput::Problems, ProblemsShown::All)->note('src scores 40.00%.', $problems);

    expect($console->fetch())->toBe("src scores 40.00%.\n")
        ->and($problems->fetch())->toBe('');
});

it('prints no verdict as events, which said every mutant as it was judged, and says no line to the console\'s reader', function (): void {
    $output = new BufferedOutput();
    $project = Directory::at(Scratch::directory());
    $events = Printing::of(VerdictOutput::Events, ProblemsShown::All);

    $events->begin($output, $project);
    $events->note('A change arrived.', $output);
    $events->verdict(Verdicts::failing(), $output, $project);

    expect($output->fetch())->toBe('')
        ->and($events->events())->toBeTrue()
        ->and($events->endsRounds(asked: true))->toBeFalse();
});
