<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Recording\Killers;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\Loaded;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\Off;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\Placed;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\Recorder;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\RecordLine;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\RunIssues;
use NightWorksIO\MutationGate\Tests\Support\Beats;
use NightWorksIO\MutationGate\Tests\Support\PhpUnitEvents;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use PHPUnit\Event\Facade;

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

it('writes each killer as a line of its own, with the mutated copy it ran on', function (): void {
    $results = sprintf('%s/results.jsonl', Scratch::directory());
    $killers = Killers::listening($results, '/tmp/mutations/abc', new Facade(), original: false, loaded: Loaded::of([]), heartbeat: new Beats()->heartbeat());

    if ($killers instanceof Killers) {
        $killers->killedBy('P\Tests\MoneySpec::__pest_evaluable_it_adds');
        $killers->killedBy("Tests\\LegacySpec::testAdds#(1)\xff");
    }

    expect(file_get_contents($results))->toBe(sprintf(
        "{\"event\":\"killed\",\"mutated\":\"/tmp/mutations/abc\",\"test\":\"P\\\\Tests\\\\MoneySpec::__pest_evaluable_it_adds\",\"run\":%d}\n"
        . "{\"event\":\"killed\",\"mutated\":\"/tmp/mutations/abc\",\"test\":\"Tests\\\\LegacySpec::testAdds#(1)\\ufffd\",\"run\":%d}\n",
        getmypid(),
        getmypid(),
    ));
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

    expect(file_get_contents($results))->toBe(sprintf(
        "{\"event\":\"preloaded\",\"mutated\":\"/tmp/mutations/abc\"}\n"
        . "{\"event\":\"killed\",\"mutated\":\"/tmp/mutations/abc\",\"test\":\"T::adds\",\"run\":%d}\n",
        getmypid(),
    ))->and(is_file($clean))->toBeFalse();
});

it('writes a test that errored apart from one that failed, with the mutated copy it ran on', function (): void {
    $results = sprintf('%s/results.jsonl', Scratch::directory());
    $killers = Killers::listening($results, '/tmp/mutations/abc', new Facade(), original: false, loaded: Loaded::of([]), heartbeat: new Beats()->heartbeat());

    if ($killers instanceof Killers) {
        $killers->erroredBy('T::adds');
    }

    expect(file_get_contents($results))->toBe(sprintf(
        "{\"event\":\"errored\",\"mutated\":\"/tmp/mutations/abc\",\"test\":\"T::adds\",\"run\":%d}\n",
        getmypid(),
    ));
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

it('names as killers, once PHPUnit ends its run, the tests whose issues failed it, before how many tests it ran', function (): void {
    $results = sprintf('%s/results.jsonl', Scratch::directory());
    $events = new Facade();
    $issues = new class implements RunIssues {
        public function killers(): array
        {
            return ['P\Tests\MoneySpec::__pest_evaluable_it_adds'];
        }
    };
    Killers::listening($results, '/tmp/mutations/abc', $events, original: false, loaded: Loaded::of([]), heartbeat: new Beats()->heartbeat(), issues: $issues);

    PhpUnitEvents::executionFinished($events);

    expect(file_get_contents($results))->toBe(sprintf(
        '%s%s',
        RecordLine::killed('/tmp/mutations/abc', 'P\Tests\MoneySpec::__pest_evaluable_it_adds', Placed::unplaced((int) getmypid())),
        RecordLine::ran('/tmp/mutations/abc', 0),
    ));
});
