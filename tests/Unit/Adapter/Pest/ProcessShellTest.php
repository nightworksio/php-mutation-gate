<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Filesystem\PackageDirectory;
use NightWorksIO\MutationGate\Adapter\Pest\ArchiveLoading;
use NightWorksIO\MutationGate\Adapter\Pest\Command;
use NightWorksIO\MutationGate\Adapter\Pest\GatePlugins;
use NightWorksIO\MutationGate\Adapter\Pest\ProcessShell;
use NightWorksIO\MutationGate\Adapter\Pest\Project;
use NightWorksIO\MutationGate\Cli\PestPlugin;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\OwnArchive;
use NightWorksIO\MutationGate\Core\Runner\EnvironmentRead;
use NightWorksIO\MutationGate\Core\Runner\MemoryCap;
use NightWorksIO\MutationGate\Core\Runner\ProcessCommand;
use NightWorksIO\MutationGate\Core\Runner\ProcessCount;
use NightWorksIO\MutationGate\Core\Runner\ProcessEnds;
use NightWorksIO\MutationGate\Core\Runner\ProcessWatch;
use NightWorksIO\MutationGate\Core\Runner\Ran;
use NightWorksIO\MutationGate\Core\Runner\SilenceLimit;
use NightWorksIO\MutationGate\Core\Runner\Unwatched;
use NightWorksIO\MutationGate\Core\Runner\WorkerSlots;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unlimited;
use NightWorksIO\MutationGate\Port\Processes;
use NightWorksIO\MutationGate\Tests\Fakes\ProcessesFake;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

it('runs a command through the processes, in its directory, with its environment and deadline, and ends as it ends', function (): void {
    $processes = new ProcessesFake(static fn(): Ran => Ran::exited(3, 'no'));
    $command = Command::of(PHP_BINARY, '-v')->with(['GATE' => 'on'])->within(Seconds::of(4.0));

    $ran = new ProcessShell($processes, '/project')->run($command);

    expect($ran)->toEqual(Ran::exited(3, 'no'))
        ->and($processes->ran())->toEqual([
            ProcessCommand::of('/project', PHP_BINARY, '-v')->with(EnvironmentRead::of($command->environment()))->within(Seconds::of(4.0)),
        ]);
});

it('runs a command in another directory once moved there', function (): void {
    $processes = new ProcessesFake(static fn(ProcessCommand $command): Ran => Ran::exited(0, $command->directory()));

    expect(new ProcessShell($processes, '/')->in('/elsewhere')->run(Command::of(PHP_BINARY, '-v'))->output())
        ->toBe('/elsewhere');
});

it('runs commands side by side through the processes, each told its place, with its ends in the order given', function (): void {
    $processes = new ProcessesFake(static fn(ProcessCommand $command): Ran => Ran::exited(0, (string) iterator_to_array($command->environment(), preserve_keys: true)['TEST_TOKEN']));
    $command = Command::of(PHP_BINARY, '-v');

    $ends = new ProcessShell($processes, '/project')
        ->sideBySide(WorkerSlots::of(ProcessCount::of(2), 'run'), Unlimited::time(), $command, $command, $command);

    expect(array_map(static fn(Ran $ran): string => $ran->output(), [...$ends]))->toBe(['1', '2', '1'])
        ->and(array_map(static fn(ProcessCommand $ran): string => $ran->directory(), $processes->ran()))
        ->toBe(['/project', '/project', '/project']);
});

it('stops a command at its silence limit where it has one, and only at its deadline where it has none', function (): void {
    $processes = new ProcessesFake(static fn(): Ran => Ran::exited(0, ''));
    $silence = SilenceLimit::of(Seconds::of(2.0), '/project/events.txt');

    $shell = new ProcessShell($processes, '/project');
    $shell->run(Command::of(PHP_BINARY, '-v')->silencedAfter($silence)->within(Seconds::of(4.0))->with(['GATE' => 'on']));
    $shell->run(Command::of(PHP_BINARY, '-v'));
    [$silenced, $plain] = $processes->ran();

    expect([$silenced->silence(), $plain->silence(), $silenced->deadline()])->toEqual([$silence, NotGiven::value(), Seconds::of(4.0)]);
});

it('starts commands side by side through processes that start them, and runs them to their ends at once through others', function (): void {
    $program = static fn(ProcessCommand $command): Ran => Ran::exited(0, $command->directory());
    $starting = new ProcessesFake($program);
    $running = new ProcessesFake($program);
    $only = new readonly class ($running) implements Processes {
        public function __construct(private Processes $processes)
        {
        }

        public function run(ProcessCommand $command, ProcessWatch $watch = new Unwatched()): Ran
        {
            return $this->processes->run($command, $watch);
        }

        public function sideBySide(WorkerSlots $slots, Seconds|Unlimited $startingWithin, ProcessCommand ...$commands): ProcessEnds
        {
            return $this->processes->sideBySide($slots, $startingWithin, ...$commands);
        }
    };
    $command = Command::of(PHP_BINARY, '-v');

    $started = new ProcessShell($starting, '/project')->started(WorkerSlots::alone(), Unlimited::time(), $command, $command);
    $ran = new ProcessShell($only, '/project')->started(WorkerSlots::alone(), Unlimited::time(), $command, $command);

    expect([...$started->ends()])->toEqual([...$ran->ends()])
        ->and(array_map(static fn(Ran $end): string => $end->output(), [...$ran->ends()]))->toBe(['/project', '/project'])
        ->and([$starting->starts(), $running->starts()])->toBe([[2], []])
        ->and($running->ran())->toEqual($starting->ran());
});

it('has each command\'s PHP processes load the gate\'s archive first, where it runs from one, in this directory and another', function (): void {
    $root = (string) realpath(Scratch::directory());
    Scratch::write($root, 'vendor/pest-plugins.json', (string) json_encode([PestPlugin::class]));
    Scratch::write($root, 'composer.json', (string) json_encode([
        'name' => 'nightworksio/mutation-gate',
        'autoload' => ['psr-4' => ['NightWorksIO\\MutationGate\\' => 'src/']],
    ]));
    $project = Project::at($root, Paths::of(Path::of('tests')), Path::of('.mutation-gate'), Path::of('vendor'));
    $loading = ArchiveLoading::writtenFor($project, OwnArchive::at('/opt/mutation-gate-judge.phar'), GatePlugins::listedIn($project, new PackageDirectory()), PestPlugin::class);
    $processes = new ProcessesFake(static fn(): Ran => Ran::exited(0, ''));
    $shell = new ProcessShell($processes, '/project', $loading instanceof ArchiveLoading ? $loading : NotGiven::value());

    $shell->run(Command::of(PHP_BINARY, '-v'));
    $shell->in('/elsewhere')->run(Command::of(PHP_BINARY, '-v'));
    $scanned = MemoryCap::scanning(getenv(MemoryCap::SCAN_DIR), sprintf('%s/archive/php', $project->workspace()));

    expect(array_map(
        static fn(ProcessCommand $ran): string => (string) iterator_to_array($ran->environment(), preserve_keys: true)[MemoryCap::SCAN_DIR],
        $processes->ran(),
    ))->toBe([$scanned, $scanned]);
});
