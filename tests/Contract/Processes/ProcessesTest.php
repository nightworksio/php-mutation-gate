<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Process\LocalProcesses;
use NightWorksIO\MutationGate\Cli\SystemClock;
use NightWorksIO\MutationGate\Core\Runner\Environment;
use NightWorksIO\MutationGate\Core\Runner\ProcessCommand;
use NightWorksIO\MutationGate\Core\Runner\ProcessCount;
use NightWorksIO\MutationGate\Core\Runner\ProcessWatch;
use NightWorksIO\MutationGate\Core\Runner\Ran;
use NightWorksIO\MutationGate\Core\Runner\SilenceLimit;
use NightWorksIO\MutationGate\Core\Runner\WorkerSlots;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unlimited;
use NightWorksIO\MutationGate\Port\Processes;
use NightWorksIO\MutationGate\Tests\Fakes\ProcessesFake;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

$holds = [
    'holds:src/Adapter/Process',
    'holds:src/Core/Runner/Environment.php',
    'holds:src/Core/Runner/ProcessCommand.php',
    'holds:src/Core/Runner/Ran.php',
    'holds:src/Core/Runner/WorkerSlot.php',
];

// What every way of running processes answers for the contract program:
// one line per implementation.

afterEach(function (): void {
    Scratch::sweep();
});

/** The contract program as the fake runs it: what it prints and how it ends, with no process. */
function processesProgram(ProcessCommand $command): Ran
{
    $arguments = [...$command->arguments()];
    $verb = count($arguments) > 2 ? $arguments[2] : '';
    $first = count($arguments) > 3 ? $arguments[3] : '';
    $told = iterator_to_array($command->environment(), preserve_keys: true);
    $deadline = $command->deadline();

    $ended = match (true) {
        $arguments[0] === 'sleep' => processesSlept((float) $arguments[1], $deadline),
        $arguments[0] !== PHP_BINARY => Ran::finished(succeeded: false, output: 'The program cannot be started.'),
        $verb === 'say' => Ran::exited((int) (count($arguments) > 4 ? $arguments[4] : '0'), $first),
        $verb === 'both' => Ran::exited(0, sprintf('%s%s', $first, $arguments[4] ?? ''), $first),
        $verb === 'await' => Ran::exited(0, is_file($first) ? (string) file_get_contents($first) : ''),
        $verb === 'tell' => Ran::exited(0, array_key_exists($first, $told) ? (string) $told[$first] : ''),
        $verb === 'stall' => $command->silence() instanceof SilenceLimit ? Ran::silenced('') : processesSlept(30.0, $deadline),
        default => Ran::exited(0, (string) realpath($command->directory())),
    };

    return $ended->took(Seconds::of(0.0));
}

/** How `sleep` ends, as the fake runs it: stopped where its deadline comes first. */
function processesSlept(float $seconds, Seconds|Unlimited $deadline): Ran
{
    return $deadline instanceof Seconds && $deadline->seconds() < $seconds ? Ran::stopped('') : Ran::exited(0, '');
}

/** The contract program, run in a directory with these arguments. */
function processesCommand(string $directory, string ...$arguments): ProcessCommand
{
    return ProcessCommand::of($directory, PHP_BINARY, sprintf('%s/program.php', __DIR__), ...$arguments);
}

/**
 * @param  iterable<Ran> $ends
 * @return list<string>  what each process printed, in the order the commands were given
 */
function processesSaid(iterable $ends): array
{
    $said = [];

    foreach ($ends as $end) {
        $said[] = $end->output();
    }

    return $said;
}

$implementations = [
    'the fake' => fn(): Processes => new ProcessesFake(processesProgram(...)),
    'local processes' => fn(): Processes => new LocalProcesses(new SystemClock()),
];

it('runs a program to its end in its directory, with what it printed, its exit code and how long it ran', function (Processes $processes): void {
    $directory = (string) realpath(Scratch::directory());
    $failed = $processes->run(processesCommand($directory, 'say', 'hello', '3'));
    $passed = $processes->run(processesCommand($directory, 'say', 'fine', '0'));

    expect([$failed->output(), $failed->exitCode(), $failed->succeeded()])->toBe(['hello', 3, false])
        ->and([$passed->output(), $passed->succeeded()])->toBe(['fine', true])
        ->and($passed->duration())->toBeInstanceOf(Seconds::class)
        ->and($processes->run(processesCommand($directory))->output())->toBe($directory);
})->with($implementations)->group(...$holds);

it('tells a program what its command sets, and nothing it unsets', function (Processes $processes): void {
    $told = processesCommand(Scratch::directory(), 'tell', 'MUTATION_GATE_CONTRACT');

    expect($processes->run($told->with(Environment::telling('MUTATION_GATE_CONTRACT', 'told')))->output())->toBe('told')
        ->and($processes->run($told->with(Environment::telling('MUTATION_GATE_CONTRACT', 'told'))->with(Environment::unsetting('MUTATION_GATE_CONTRACT')))->output())
        ->toBe('');
})->with($implementations)->group(...$holds);

it('stops a program still running at its deadline', function (Processes $processes): void {
    $stopped = $processes->run(ProcessCommand::of(Scratch::directory(), 'sleep', '30')->within(Seconds::of(0.2)));

    expect($stopped->wasStopped())->toBeTrue()
        ->and($stopped->succeeded())->toBeFalse();
})->with($implementations)->group(...$holds);

it('stops a program at its silence limit where the file it names has not grown for that long since it last grew, before its deadline', function (Processes $processes): void {
    $directory = Scratch::directory();
    $progress = sprintf('%s/progress', $directory);
    $silenced = $processes->run(processesCommand($directory, 'stall', $progress)
        ->within(Seconds::of(20.0))
        ->silencedAfter(SilenceLimit::of(Seconds::of(0.3), $progress)));

    expect([$silenced->wasSilenced(), $silenced->wasStopped(), $silenced->succeeded()])->toBe([true, true, false])
        ->and($silenced->duration() instanceof Seconds ? $silenced->duration()->seconds() : 20.0)->toBeLessThan(10.0);
})->with($implementations)->group(...$holds);

it('says a program that cannot be started did not succeed', function (Processes $processes): void {
    $ran = $processes->run(ProcessCommand::of(Scratch::directory(), '/no/such/program'));

    expect($ran->succeeded())->toBeFalse();
})->with($implementations)->group(...$holds);

it('runs commands side by side, the first ones each in a place of its own and told which, their ends in the order given', function (Processes $processes): void {
    $told = processesCommand(Scratch::directory(), 'tell', 'TEST_TOKEN');
    $said = processesSaid($processes->sideBySide(WorkerSlots::of(ProcessCount::of(2), 'run'), Unlimited::time(), $told, $told, $told));

    expect(array_slice($said, 0, 2))->toBe(['1', '2'])
        ->and($said[2])->toBeIn(['1', '2']);
})->with($implementations)->group(...$holds);

it('tells a command in the one place of a runner that runs one at a time nothing of places', function (Processes $processes): void {
    $told = processesCommand(Scratch::directory(), 'tell', 'TEST_TOKEN')->with(Environment::unsetting('TEST_TOKEN'));

    expect(processesSaid($processes->sideBySide(WorkerSlots::alone(), Unlimited::time(), $told)))->toBe(['']);
})->with($implementations)->group(...$holds);

it('runs as many commands at once as there are places', function (Processes $processes): void {
    // One after the other, they would take four seconds; side by side, two and the time to start them.
    $sleeping = ProcessCommand::of(Scratch::directory(), 'sleep', '2');
    $started = hrtime(as_number: true);
    $ends = $processes->sideBySide(WorkerSlots::of(ProcessCount::of(2), 'run'), Unlimited::time(), $sleeping, $sleeping);

    expect(count($ends))->toBe(2)
        ->and((hrtime(as_number: true) - $started) / 1e9)->toBeLessThan(3.6);
})->with($implementations)->group(...$holds);

it('starts no command once the time to start them in has run out', function (Processes $processes): void {
    $said = processesCommand(Scratch::directory(), 'say', 'started', '0');

    expect(count($processes->sideBySide(WorkerSlots::of(ProcessCount::of(2), 'run'), Seconds::of(0.0), $said, $said)))->toBe(0)
        ->and(processesSaid($processes->sideBySide(WorkerSlots::alone(), Seconds::of(60.0), $said)))->toBe(['started']);
})->with($implementations)->group(...$holds);

it('watches a running program, so the gate can answer what it waits on while it runs', function (Processes $processes): void {
    $directory = (string) realpath(Scratch::directory());
    $answer = sprintf('%s/answer', $directory);
    $watch = new class ($answer) implements ProcessWatch {
        public private(set) int $looks = 0;

        public function __construct(private readonly string $answer)
        {
        }

        public function look(): void
        {
            ++$this->looks;

            if (! is_file($this->answer)) {
                file_put_contents($this->answer, 'answered');
            }
        }
    };

    $ran = $processes->run(processesCommand($directory, 'await', $answer), $watch);

    expect([$ran->succeeded(), $ran->output()])->toBe([true, 'answered'])
        ->and($watch->looks)->toBeGreaterThanOrEqual(1)
        ->and($processes->run(processesCommand($directory, 'say', 'unwatched'))->output())->toBe('unwatched');
})->with($implementations)->group(...$holds);

it('keeps what a program printed on its standard output apart from its errors, after them in its output', function (Processes $processes): void {
    $ran = $processes->run(processesCommand((string) realpath(Scratch::directory()), 'both', '{"report":1}', 'a warning'));

    expect([$ran->printed(), $ran->output()])->toBe(['{"report":1}', '{"report":1}a warning']);
})->with($implementations)->group(...$holds);
