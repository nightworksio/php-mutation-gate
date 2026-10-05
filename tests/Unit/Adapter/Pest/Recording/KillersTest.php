<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Recording\KillerFile;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\Killers;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\Loaded;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\Off;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\Placed;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\Recorder;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\RecordEvent;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\RecordLine;
use NightWorksIO\MutationGate\Tests\Support\Beats;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use PHPUnit\Event\Facade;
use Symfony\Component\Process\Process;

afterEach(function (): void {
    // Killers logs this process's errors to the mutant's own file, as it does in a mutant's own process.
    ini_restore('error_log');
    ini_restore('log_errors');
    Scratch::sweep();
});

it('names no killer unless the adapter names a results file and Pest a mutated copy', function (): void {
    expect(Killers::listening(results: false, mutated: '/tmp/m', events: new Facade(), original: false, loaded: Loaded::of([]), heartbeat: new Beats()->heartbeat()))->toBe(Off::NamingKillers)
        ->and(Killers::listening('', '/tmp/m', new Facade(), original: false, loaded: Loaded::of([]), heartbeat: new Beats()->heartbeat()))->toBe(Off::NamingKillers)
        ->and(Killers::listening('/r/results.jsonl', mutated: false, events: new Facade(), original: false, loaded: Loaded::of([]), heartbeat: new Beats()->heartbeat()))->toBe(Off::NamingKillers)
        ->and(Killers::listening('/r/results.jsonl', '', new Facade(), original: false, loaded: Loaded::of([]), heartbeat: new Beats()->heartbeat()))->toBe(Off::NamingKillers)
        ->and(Killers::fromEnvironment([]))->toBe(Off::NamingKillers);
});

it('names no killer where PHPUnit takes no more subscribers', function (): void {
    $sealed = new Facade();
    $sealed->seal();

    expect(Killers::listening('/r/results.jsonl', '/tmp/m', $sealed, original: false, loaded: Loaded::of([]), heartbeat: new Beats()->heartbeat()))->toBe(Off::NamingKillers);
});

it('writes each killer as a line of its own in the file of the mutated copy it ran on, and none in the results', function (): void {
    $results = sprintf('%s/results.jsonl', Scratch::directory());
    $killers = Killers::listening($results, '/tmp/mutations/abc', new Facade(), original: false, loaded: Loaded::of([]), heartbeat: new Beats()->heartbeat());

    if ($killers instanceof Killers) {
        $killers->killedBy('P\Tests\MoneySpec::__pest_evaluable_it_adds');
        $killers->killedBy("Tests\\LegacySpec::testAdds#(1)\xff");
    }

    expect(file_get_contents(KillerFile::beside($results, '/tmp/mutations/abc')))->toBe(sprintf(
        '%s%s',
        KillerFile::line(RecordEvent::Killed, 'P\Tests\MoneySpec::__pest_evaluable_it_adds', Placed::unplaced((int) getmypid())),
        KillerFile::line(RecordEvent::Killed, "Tests\\LegacySpec::testAdds#(1)\xff", Placed::unplaced((int) getmypid())),
    ))->and(is_file($results))->toBeFalse();
});

it('writes first that its process had loaded the original before the override, by its real path, and nothing where it had not', function (): void {
    $directory = Scratch::directory();
    Scratch::write($directory, 'src/Money.php', '<?php');
    $original = sprintf('%s/src/Money.php', realpath($directory));
    $results = sprintf('%s/results.jsonl', $directory);
    $clean = sprintf('%s/clean.jsonl', $directory);

    $killers = Killers::listening($results, '/tmp/mutations/abc', new Facade(), sprintf('%s/./src/Money.php', $directory), Loaded::of([$original]), new Beats()->heartbeat());
    Killers::listening($clean, '/tmp/mutations/abc', new Facade(), $original, Loaded::of(['/p/src/Other.php']), new Beats()->heartbeat());
    Killers::listening($clean, '/tmp/mutations/abc', new Facade(), original: false, loaded: Loaded::of([$original]), heartbeat: new Beats()->heartbeat());

    if ($killers instanceof Killers) {
        $killers->killedBy('T::adds');
    }

    expect(file_get_contents(KillerFile::beside($results, '/tmp/mutations/abc')))
        ->toBe(sprintf('%s%s', KillerFile::preloaded(), KillerFile::line(RecordEvent::Killed, 'T::adds', Placed::unplaced((int) getmypid()))))
        ->and(is_file($results))->toBeFalse()
        ->and(is_file(KillerFile::beside($clean, '/tmp/mutations/abc')))->toBeFalse();
});

it('writes a test that errored apart from one that failed, in the file of the mutated copy it ran on', function (): void {
    $results = sprintf('%s/results.jsonl', Scratch::directory());
    $killers = Killers::listening($results, '/tmp/mutations/abc', new Facade(), original: false, loaded: Loaded::of([]), heartbeat: new Beats()->heartbeat());

    if ($killers instanceof Killers) {
        $killers->erroredBy('T::adds');
        $killers->killedBy('T::subtracts');
    }

    expect(KillerFile::taken(KillerFile::beside($results, '/tmp/mutations/abc'), '/tmp/mutations/abc'))->toBe([
        RecordLine::errored('/tmp/mutations/abc', 'T::adds', Placed::unplaced((int) getmypid())),
        RecordLine::killed('/tmp/mutations/abc', 'T::subtracts', Placed::unplaced((int) getmypid())),
    ])->and(is_file($results))->toBeFalse();
});

it('logs its process\'s errors to the mutant\'s own file, emptied of what an earlier run left', function (): void {
    $results = sprintf('%s/results.jsonl', Scratch::directory());
    $log = Recorder::errorsBeside($results, '/tmp/mutations/abc');
    file_put_contents($log, "an earlier run's error\n");

    Killers::listening($results, '/tmp/mutations/abc', new Facade(), original: false, loaded: Loaded::of([]), heartbeat: new Beats()->heartbeat());
    error_log('logged now');

    expect((string) file_get_contents($log))->toContain('logged now')
        ->and((string) file_get_contents($log))->not->toContain("an earlier run's error");
});

it('keeps in the mutant\'s log the memory limit its process ran out of, where PHP shows and logs its errors nowhere', function (): void {
    $results = sprintf('%s/results.jsonl', Scratch::directory());
    $php = new Process([PHP_BINARY, '-d', 'memory_limit=32M', '-d', 'display_errors=0', '-d', 'log_errors=0', '-r', sprintf(<<<'PHP_WRAP'
    require %s;
    NightWorksIO\MutationGate\Adapter\Pest\Recording\Killers::listening(%s, '/tmp/mutations/abc', new PHPUnit\Event\Facade(), original: false, loaded: NightWorksIO\MutationGate\Adapter\Pest\Recording\Loaded::of([]), heartbeat: NightWorksIO\MutationGate\Adapter\Pest\Recording\Heartbeat::onErrorOutput());
    register_shutdown_function(static function (): void {
        $held = [];
        for ($megabytes = 0; $megabytes < 64; $megabytes++) {
            $held[] = str_repeat('x', 1048576);
        }
    });
    $held = [];
    while (true) {
        $held[] = str_repeat('x', 16);
    }
    PHP_WRAP, var_export(sprintf('%s/vendor/autoload.php', dirname(__DIR__, 5)), return: true), var_export($results, return: true))]);
    $php->run();

    expect($php->getOutput())->toBe('')
        ->and((string) file_get_contents(Recorder::errorsBeside($results, '/tmp/mutations/abc')))
        ->toContain('Allowed memory size of 33554432 bytes exhausted');
});

it('logs nothing to a mutant\'s log it cannot empty', function (): void {
    $directory = Scratch::directory();
    $results = sprintf('%s/results.jsonl', $directory);
    chmod($directory, 0o555);

    try {
        Killers::listening($results, '/tmp/mutations/abc', new Facade(), original: false, loaded: Loaded::of([]), heartbeat: new Beats()->heartbeat());
        $logged = ini_get('error_log');
    } finally {
        chmod($directory, 0o755);
    }

    expect($logged)->not->toBe(Recorder::errorsBeside($results, '/tmp/mutations/abc'))
        ->and(is_file(Recorder::errorsBeside($results, '/tmp/mutations/abc')))->toBeFalse();
});
