<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\GateVariable;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\Recorder;
use NightWorksIO\MutationGate\Adapter\Pest\SchemaSwitch;
use NightWorksIO\MutationGate\Adapter\Pest\Split\Stream;
use NightWorksIO\MutationGate\Adapter\Pest\Split\TestHanding;
use NightWorksIO\MutationGate\Tests\Support\Databases;
use NightWorksIO\MutationGate\Tests\Support\FreshProcess;
use NightWorksIO\MutationGate\Tests\Support\OutbreakCalls;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

/**
 * The lines a stream started from a plan wrote, each decoded, its seconds left out.
 *
 * @param  array<string, mixed> $plan
 * @param  Closure(): void      $run
 * @return list<array<mixed>>
 */
function streamedLines(array $plan, Closure $run): array
{
    $root = Scratch::directory();
    $results = sprintf('%s/results.jsonl', $root);
    Scratch::write($root, 'plan.json', (string) json_encode(['results' => $results, ...$plan]));
    $started = Stream::from(sprintf('%s/plan.json', $root));

    try {
        $run();
    } finally {
        Stream::finished();
    }

    return $started ? streamWritten($results) : [['not started']];
}

/**
 * The lines a stream wrote to this results file, each decoded, its seconds left out.
 *
 * @return list<array<mixed>>
 */
function streamWritten(string $results): array
{
    $lines = [];
    $written = is_file($results) ? file($results, FILE_IGNORE_NEW_LINES) : [];

    foreach (is_array($written) ? $written : [] as $line) {
        $read = json_decode($line, associative: true);

        if (is_array($read)) {
            unset($read['s']);
            $lines[] = $read;
        }
    }

    return $lines;
}

/**
 * What a program printed, run in a process of its own once a stream started there from a plan, after what comes
 * before the stream; the lines the stream wrote to its results, and, where it records, the lines it recorded; each
 * decoded, its seconds left out.
 *
 * @param  array<string, mixed>                                  $plan
 * @return array{string, list<array<mixed>>, list<array<mixed>>}
 */
function streamedApart(array $plan, string $program, string $before = '', bool $recording = false): array
{
    $root = Scratch::directory();
    $results = sprintf('%s/results.jsonl', $root);
    $recorded = sprintf('%s/recorded.jsonl', $root);
    Scratch::write($root, 'plan.json', (string) json_encode([
        'results' => $results,
        ...$recording ? ['recording' => $recorded] : [],
        ...$plan,
    ]));
    $said = FreshProcess::answer(sprintf(<<<'PHP'
        %s
        use NightWorksIO\MutationGate\Adapter\Pest\SchemaSwitch;
        use NightWorksIO\MutationGate\Adapter\Pest\ServedChildren;
        use NightWorksIO\MutationGate\Adapter\Pest\Split\Stream;
        use NightWorksIO\MutationGate\Adapter\Pest\Split\TestHanding;

        Stream::from(%s);
        %s
        PHP, $before, var_export(sprintf('%s/plan.json', $root), return: true), $program));

    return [$said, streamWritten($results), streamWritten($recorded)];
}

/**
 * Each of these lines as the fields named.
 *
 * @param  list<array<mixed>> $lines
 * @return list<list<mixed>>
 */
function streamFields(array $lines, string ...$fields): array
{
    return array_map(
        static fn(array $line): array => array_map(static fn(string $field): mixed => $line[$field] ?? null, array_values($fields)),
        $lines,
    );
}

it('writes how the parent came out of each test it ran', function (): void {
    $root = Scratch::directory();
    $results = sprintf('%s/results.jsonl', $root);
    Scratch::write($root, 'plan.json', (string) json_encode(['results' => $results, 'sites' => []]));

    expect(FreshProcess::answer(sprintf(<<<'PHP'
        use NightWorksIO\MutationGate\Adapter\Pest\Split\Stream;

        $started = Stream::from(%s);
        Stream::began('T::passes');
        Stream::ended();
        Stream::began('T::fails');
        Stream::failed();
        Stream::ended();
        Stream::ended();
        Stream::finished();
        $lines = [];

        foreach (is_file(%s) ? file(%s, FILE_IGNORE_NEW_LINES) : [] as $line) {
            $read = json_decode($line, associative: true);
            unset($read['s']);
            $lines[] = $read;
        }

        echo json_encode($started ? $lines : [['not started']]);
        PHP, var_export(sprintf('%s/plan.json', $root), return: true), var_export($results, return: true), var_export($results, return: true))))->json()->toBe([
        ['k' => 'parent', 't' => 'T::passes', 'p' => true],
        ['k' => 'parent', 't' => 'T::fails', 'p' => false],
    ]);
})->skip(fn(): bool => ! function_exists('pcntl_fork') || ! function_exists('posix_kill'), 'a split stream needs a PHP that forks');

it('falls back for a test that runs in a process of its own, or holds a socket the stream did not as it started', function (): void {
    $pair = [];

    expect(streamedLines(['sites' => []], static function () use (&$pair): void {
        Stream::began('T::isolated');
        Stream::isolated();
        Stream::isolated();
        Stream::ended();
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        Stream::began('T::holds');
        Stream::ended();
    }))->toBe([
        ['k' => 'fallback', 't' => 'T::isolated'],
        ['k' => 'parent', 't' => 'T::isolated', 'p' => true],
        ['k' => 'fallback', 't' => 'T::holds'],
        ['k' => 'parent', 't' => 'T::holds', 'p' => true],
    ]);

    foreach (is_array($pair) ? $pair : [] as $end) {
        fclose($end);
    }
})->skip(fn(): bool => ! function_exists('pcntl_fork') || ! function_exists('posix_kill'), 'a split stream needs a PHP that forks');

it('leaves for its own run each mutant of a file the process loaded before its schema could be served', function (): void {
    expect(streamedLines([
        'sites' => ['f1' => [1 => ['m1', '', []]], 'f2' => [1 => ['m2', '', []]]],
        'paths' => ['f1' => __FILE__, 'f2' => '/nowhere/Unloaded.php'],
    ], static function (): void {
    }))->toBe([['k' => 'unserved', 'm' => 'm1']]);
})->skip(fn(): bool => ! function_exists('pcntl_fork') || ! function_exists('posix_kill'), 'a split stream needs a PHP that forks');

it('forks no mutant whose site runs while the stream boots the application once, and leaves it to a stream that boots in every test', function (): void {
    expect(streamedLines(['sites' => ['f1' => [1 => ['m1', '', []]]], 'bootOnce' => true], static function (): void {
        Stream::booting(booting: true);
        $forked = SchemaSwitch::is('f1', 1);
        SchemaSwitch::is('f1', 1);
        Stream::booting(booting: false);

        expect($forked)->toBeFalse();
    }))->toBe([['k' => 'booted', 'm' => 'm1']]);
})->skip(fn(): bool => ! function_exists('pcntl_fork') || ! function_exists('posix_kill'), 'a split stream needs a PHP that forks');

it('keeps a test and forks no mutant where PHP can start no process', function (): void {
    $root = Scratch::directory();
    $results = sprintf('%s/results.jsonl', $root);
    Scratch::write($root, 'plan.json', (string) json_encode(['results' => $results, 'sites' => ['f1' => [1 => ['m1', '', []]]]]));

    expect(FreshProcess::answer(sprintf(<<<'PHP'
        use NightWorksIO\MutationGate\Adapter\Pest\SchemaSwitch;
        use NightWorksIO\MutationGate\Adapter\Pest\Split\Stream;

        Stream::from(%s);
        Stream::began('T::kept');
        posix_setrlimit(POSIX_RLIMIT_NPROC, 1, 1);
        $handed = Stream::forkedTest();
        echo json_encode([$handed->name, SchemaSwitch::is('f1', 1)]);
        Stream::ended();
        Stream::finished();
        PHP, var_export(sprintf('%s/plan.json', $root), return: true))))->toBe('["Kept",false]');
})->skip(fn(): bool => ! function_exists('pcntl_fork') || ! function_exists('posix_setrlimit') || posix_getuid() === 0, 'only a PHP that forks, run by a user other than root, is held to a limit of processes');

it('waits for no child from a child forked at a site, and writes how that child exited and nothing of a process it did not fork', function (): void {
    $root = Scratch::directory();
    Scratch::write($root, 'plan.json', (string) json_encode([
        'results' => sprintf('%s/results.jsonl', $root),
        'sites' => ['f1' => [1 => ['m1', '', []]]],
        'floor' => 10,
        'longest' => 10,
    ]));

    $said = FreshProcess::answer(sprintf(<<<'PHP'
        use NightWorksIO\MutationGate\Adapter\Pest\SchemaSwitch;
        use NightWorksIO\MutationGate\Adapter\Pest\Split\Stream;

        Stream::from(%s);

        if (pcntl_fork() === 0) {
            exit(0);
        }

        Stream::began('T::forks');

        if (SchemaSwitch::is('f1', 1)) {
            Stream::finished();
            echo 'child;';
            exit(0);
        }

        Stream::ended();
        Stream::finished();
        echo 'stream;';
        PHP, var_export(sprintf('%s/plan.json', $root), return: true)));

    expect($said)->toBe('child;stream;')
        ->and(array_column(streamWritten(sprintf('%s/results.jsonl', $root)), 'k'))->toBe(['parent', 'exited']);
})->skip(fn(): bool => ! function_exists('pcntl_fork') || ! function_exists('posix_kill'), 'a split stream needs a PHP that forks');

it('starts no stream from a file that holds no plan', function (): void {
    expect(Stream::from('/nowhere/plan.json'))->toBeFalse();
});

it('boots nothing, keeps every test and is troubled by none where no stream runs', function (): void {
    Stream::booting(booting: true);
    Stream::troubled();
    Stream::booting(booting: false);

    expect(Stream::forkedTest())->toBe(TestHanding::Kept);
});

it('hands a test to a process forked from the booted application, which writes how it ended, while the stream waits for it', function (): void {
    $root = Scratch::directory();
    $results = sprintf('%s/results.jsonl', $root);
    Scratch::write($root, 'plan.json', (string) json_encode(['results' => $results, 'sites' => []]));

    expect(FreshProcess::answer(sprintf(<<<'PHP'
        use NightWorksIO\MutationGate\Adapter\Pest\Split\Stream;

        Stream::from(%s);
        Stream::booting(booting: true);
        Stream::booting(booting: false);
        Stream::began('T::forked');
        Stream::troubled();
        $handed = Stream::forkedTest();
        Stream::ended();
        Stream::finished();
        $lines = [];

        foreach (is_file(%s) ? file(%s, FILE_IGNORE_NEW_LINES) : [] as $line) {
            $read = json_decode($line, associative: true);
            unset($read['s']);
            $lines[] = $read;
        }

        echo json_encode([$handed->name, $lines]);
        PHP, var_export(sprintf('%s/plan.json', $root), return: true), var_export($results, return: true), var_export($results, return: true))))->json()->toBe([
        'Waited',
        [['k' => 'parent', 't' => 'T::forked', 'p' => true]],
    ]);
})->skip(fn(): bool => ! function_exists('pcntl_fork') || ! function_exists('posix_kill'), 'a split stream needs a PHP that forks');

it('holds a child that ran past its steps to have timed out, where outbreak.so counts them', function (): void {
    $root = Scratch::directory();
    $results = sprintf('%s/results.jsonl', $root);
    Scratch::write($root, 'plan.json', (string) json_encode([
        'results' => $results,
        'sites' => ['f1' => [1 => ['m1', '', []]]],
        'multiple' => 1,
        'floor' => 10,
        'longest' => 10,
        'stepFloor' => 100,
    ]));

    OutbreakCalls::asked(sprintf(<<<'CODE'
        NightWorksIO\MutationGate\Adapter\Pest\Split\Stream::from(%s);
        NightWorksIO\MutationGate\Adapter\Pest\Split\Stream::began('T::steps');

        if (NightWorksIO\MutationGate\Adapter\Pest\SchemaSwitch::is('f1', 1)) {
            posix_kill(posix_getpid(), SIGXCPU);
        }

        NightWorksIO\MutationGate\Adapter\Pest\Split\Stream::ended();
        NightWorksIO\MutationGate\Adapter\Pest\Split\Stream::finished();
        CODE, var_export(sprintf('%s/plan.json', $root), return: true)), $results);
    $written = file($results, FILE_IGNORE_NEW_LINES);
    $kinds = [];

    foreach (is_array($written) ? $written : [] as $line) {
        $read = json_decode($line, associative: true);
        $kinds[] = is_array($read) ? $read['k'] ?? null : $read;
    }

    expect($kinds)->toBe(['state', 'parent', 'timeout']);
})->skip(fn(): bool => ! function_exists('pcntl_fork') || ! function_exists('posix_kill'), 'a split stream needs a PHP that forks');

it('points the application at the one it boots once, only where the stream boots once and records no lines', function (bool $recording, string $base): void {
    $application = Scratch::directory();
    Scratch::write(sprintf('%s/bootstrap', $application), 'app.php', "<?php\n\nreturn null;\n");
    [$said, $results] = streamedApart(
        ['bootOnce' => true],
        "echo \$_ENV['APP_BASE_PATH'];",
        sprintf("\$_ENV['APP_BASE_PATH'] = %s;", var_export($application, return: true)),
        $recording,
    );

    expect($said)->toBe($base === 'zygote' ? sprintf('%s/results.jsonl.zygote', dirname($said)) : $application)
        ->and($results)->toBe([]);
})->with([
    'booted once' => [false, 'zygote'],
    'booted once, its lines recorded' => [true, 'application'],
])->skip(fn(): bool => ! function_exists('pcntl_fork') || ! function_exists('posix_kill'), 'a split stream needs a PHP that forks');

it('records how each test of the parent ended, troubled, failed or in a process of its own, and every line its tests loaded once the run ends', function (): void {
    [, $results, $recorded] = streamedApart([], <<<'PHP'
        Stream::began('T::troubled');
        Stream::troubled();
        Stream::ended();
        Stream::began('T::fails');
        Stream::failed();
        Stream::ended();
        Stream::began('T::isolated');
        Stream::isolated();
        Stream::ended();
        Stream::finished();
        PHP, <<<'PHP'
        function outbreak_lines_start(): bool { return true; }
        function outbreak_lines_stop(): array { return []; }
        PHP, recording: true);

    expect(streamFields($recorded, 'k', 't', 'p', 'i', 'o'))->toBe([
        ['covered', 'T::troubled', true, true, false],
        ['covered', 'T::fails', false, false, false],
        ['covered', 'T::isolated', false, false, true],
        ['covered', 'T::isolated', true, false, true],
        ['finished', null, null, null, null],
    ])->and(streamFields($results, 'k', 't'))->toBe([
        ['parent', 'T::troubled'],
        ['parent', 'T::fails'],
        ['fallback', 'T::isolated'],
        ['parent', 'T::isolated'],
    ]);
})->skip(fn(): bool => ! function_exists('pcntl_fork') || ! function_exists('posix_kill'), 'a split stream needs a PHP that forks');

it('hands no test over and forks at no site outside a test, before the first or after one it handed over', function (): void {
    [$said, $results] = streamedApart(['sites' => ['f1' => [1 => ['m1', '', []]]], 'floor' => 10, 'longest' => 10], <<<'PHP'
        $outside = [Stream::forkedTest()->name, SchemaSwitch::is('f1', 1)];
        Stream::began('T::handed');

        if (Stream::forkedTest() === TestHanding::Taken) {
            Stream::ended();
        }

        Stream::ended();
        echo json_encode([...$outside, Stream::forkedTest()->name, SchemaSwitch::is('f1', 1)]);
        Stream::finished();
        PHP);

    expect($said)->toBe('["Kept",false,"Kept",false]')
        ->and($results)->toBe([['k' => 'parent', 't' => 'T::handed', 'p' => true]]);
})->skip(fn(): bool => ! function_exists('pcntl_fork') || ! function_exists('posix_kill'), 'a split stream needs a PHP that forks');

it('forks at no site once the run finished, and leaves the processes a later test starts unmarked', function (): void {
    [$said] = streamedApart(['sites' => ['f1' => [1 => ['m1', '', []]]], 'floor' => 10, 'longest' => 10], <<<'PHP'
        Stream::began('T::first');
        $watched = getenv(ServedChildren::SPAWNED);
        Stream::ended();
        Stream::finished();
        $unwatched = getenv(ServedChildren::SPAWNED);
        Stream::began('T::after');
        echo json_encode([is_string($watched), $unwatched, SchemaSwitch::is('f1', 1)]);
        PHP);

    expect($said)->toBe('[true,false,false]');
})->skip(fn(): bool => ! function_exists('pcntl_fork') || ! function_exists('posix_kill'), 'a split stream needs a PHP that forks');

it('reads only what a test\'s own process wrote once it was handed over, and falls back for a test that process never ended', function (): void {
    [, $results] = streamedApart([], <<<'PHP'
        Stream::began('T::same');

        if (Stream::forkedTest() === TestHanding::Taken) {
            Stream::ended();
        }

        Stream::ended();
        Stream::began('T::same');
        Stream::failed();
        Stream::ended();
        Stream::began('T::same');

        if (Stream::forkedTest() === TestHanding::Taken) {
            exit(0);
        }

        Stream::ended();
        Stream::finished();
        PHP);

    expect($results)->toBe([
        ['k' => 'parent', 't' => 'T::same', 'p' => true],
        ['k' => 'parent', 't' => 'T::same', 'p' => false],
        ['k' => 'fallback', 't' => 'T::same'],
    ]);
})->skip(fn(): bool => ! function_exists('pcntl_fork') || ! function_exists('posix_kill'), 'a split stream needs a PHP that forks');

it('kills a mutant whose child failed by the end of its test, and forks it in no later test', function (): void {
    [$said, $results] = streamedApart(['sites' => ['f1' => [1 => ['m1', '', []]]], 'floor' => 10, 'longest' => 10], <<<'PHP'
        Stream::began('T::first');

        if (SchemaSwitch::is('f1', 1)) {
            Stream::failed();
            Stream::ended();
        }

        for ($waited = 0; ! $GLOBALS['gone'] && $waited < 10000; $waited++) {
            usleep(1000);
        }

        Stream::ended();
        Stream::began('T::second');
        echo json_encode(SchemaSwitch::is('f1', 1));
        Stream::ended();
        Stream::finished();
        PHP, <<<'PHP'
        $GLOBALS['gone'] = false;
        pcntl_async_signals(true);
        pcntl_signal(SIGCHLD, static function (): void {
            $GLOBALS['gone'] = true;
        });
        PHP);

    expect($said)->toBe('false')
        ->and(streamFields($results, 'k', 'm', 't'))->toBe([
            ['child', 'm1', 'T::first'],
            ['parent', null, 'T::first'],
            ['parent', null, 'T::second'],
        ]);
})->skip(fn(): bool => ! function_exists('pcntl_fork') || ! function_exists('posix_kill'), 'a split stream needs a PHP that forks');

it('goes on to the next test while a child of the last one still runs, and stops that child at its limit', function (): void {
    [, $results] = streamedApart(['sites' => ['f1' => [1 => ['m1', '', []]]], 'multiple' => 1, 'floor' => 0.3, 'longest' => 10], <<<'PHP'
        Stream::began('T::first');

        if (SchemaSwitch::is('f1', 1)) {
            sleep(2);
        }

        Stream::ended();
        Stream::began('T::second');
        Stream::ended();
        Stream::finished();
        PHP);

    expect(streamFields($results, 'k', 't'))->toBe([
        ['parent', 'T::first'],
        ['parent', 'T::second'],
        ['timeout', 'T::first'],
    ]);
})->skip(fn(): bool => ! function_exists('pcntl_fork') || ! function_exists('posix_kill'), 'a split stream needs a PHP that forks');

it('waits for each child of a test its own process ran before that process ends, so the stream reads their kills', function (): void {
    [$said, $results] = streamedApart(['sites' => ['f1' => [1 => ['m1', '', []]]], 'floor' => 10, 'longest' => 10], <<<'PHP'
        Stream::began('T::handed');

        if (Stream::forkedTest() === TestHanding::Taken) {
            if (SchemaSwitch::is('f1', 1)) {
                usleep(300000);
                Stream::failed();
                Stream::ended();
            }

            Stream::ended();
        }

        Stream::ended();
        Stream::began('T::later');
        echo json_encode(SchemaSwitch::is('f1', 1));
        Stream::ended();
        Stream::finished();
        PHP);

    expect($said)->toBe('false')
        ->and(streamFields($results, 'k', 'm', 't'))->toBe([
            ['parent', null, 'T::handed'],
            ['child', 'm1', 'T::handed'],
            ['parent', null, 'T::later'],
        ]);
})->skip(fn(): bool => ! function_exists('pcntl_fork') || ! function_exists('posix_kill'), 'a split stream needs a PHP that forks');

it('ends a child that failed by its signal, though the test listens for that signal', function (): void {
    [$said, $results] = streamedApart(['sites' => ['f1' => [1 => ['m1', '', []]]], 'floor' => 10, 'longest' => 10], <<<'PHP'
        Stream::began('T::listens');

        if (SchemaSwitch::is('f1', 1)) {
            Stream::failed();
        }

        Stream::ended();
        Stream::finished();
        echo 'ended;';
        PHP, <<<'PHP'
        pcntl_async_signals(true);
        pcntl_signal(SIGUSR1, static function (): void {
        });
        PHP);

    expect($said)->toBe('ended;')
        ->and(streamFields($results, 'k', 't'))->toEqualCanonicalizing([['child', 'T::listens'], ['parent', 'T::listens']]);
})->skip(fn(): bool => ! function_exists('pcntl_fork') || ! function_exists('posix_kill'), 'a split stream needs a PHP that forks');

it('decides no site that code run while the stream forks reaches', function (): void {
    [$said] = streamedApart([
        'sites' => ['f1' => [1 => ['m1', '', []]], 'f2' => [1 => ['m2', '', []]]],
        'floor' => 10,
        'longest' => 10,
    ], <<<'PHP'
        Stream::began('T::forks');

        if (SchemaSwitch::is('f1', 1)) {
            Stream::ended();
        }

        Stream::ended();
        Stream::finished();
        echo json_encode($GLOBALS['reached']);
        PHP, <<<'PHP'
        $GLOBALS['reached'] = [];

        function outbreak_state_watch(string $gate): int
        {
            $GLOBALS['reached'][] = NightWorksIO\MutationGate\Adapter\Pest\SchemaSwitch::is('f2', 1);

            return 0;
        }
        PHP);

    expect($said)->toBe('[false]');
})->skip(fn(): bool => ! function_exists('pcntl_fork') || ! function_exists('posix_kill'), 'a split stream needs a PHP that forks');

it('writes the state each forked call left, in the child and the parent, for the test that forked it alone', function (): void {
    [, $results] = streamedApart(['sites' => ['f1' => [1 => ['m1', '', []]]], 'floor' => 10, 'longest' => 10], <<<'PHP'
        Stream::began('T::forks');

        if (SchemaSwitch::is('f1', 1)) {
            Stream::ended();
        }

        Stream::ended();
        Stream::began('T::later');
        Stream::ended();
        Stream::finished();
        PHP, <<<'PHP'
        function outbreak_state_watch(string $gate): int { return 7; }
        function outbreak_state_digest(int $watch): string { return "d{$watch}"; }
        PHP);

    expect(streamFields(array_values(array_filter($results, static fn(array $line): bool => $line['k'] === 'state')), 'm', 't', 'c', 'd'))
        ->toEqualCanonicalizing([['m1', 'T::forks', true, 'd7'], ['m1', 'T::forks', false, 'd7']]);
})->skip(fn(): bool => ! function_exists('pcntl_fork') || ! function_exists('posix_kill'), 'a split stream needs a PHP that forks');

it('turns a mutant on in the child forked for it alone, tells the processes that child starts of it, and has them mark nothing', function (): void {
    $variables = [
        Recorder::MUTANT => '/project/src/Money.php',
        Recorder::MUTATED => '/copies/Money.php',
        GateVariable::Schema->value => '/schemas/f1.php',
        GateVariable::SchemaOn->value => '1',
    ];
    [$said] = streamedApart([
        'sites' => ['f1' => [1 => ['m1', '', $variables]], 'f2' => [1 => ['m2', '', []]]],
        'floor' => 10,
        'longest' => 10,
    ], sprintf(<<<'PHP'
        Stream::began('T::forks');

        if (SchemaSwitch::is('f1', 1)) {
            echo json_encode([getenv(%s), getenv(ServedChildren::TOLD), getenv(ServedChildren::SPAWNED), SchemaSwitch::is('f2', 1)]);
            Stream::ended();
        }

        Stream::ended();
        Stream::finished();
        PHP, var_export(Recorder::MUTATED, return: true)));

    expect($said)->json()->toBe(['/copies/Money.php', json_encode(array_values($variables)), false, false]);
})->skip(fn(): bool => ! function_exists('pcntl_fork') || ! function_exists('posix_kill'), 'a split stream needs a PHP that forks');

it('points a child at its copy of each database its test holds, where the stream reconnects', function (): void {
    $database = sprintf('%s/main.sqlite', Scratch::directory());
    Databases::made(sprintf('sqlite:%s', $database), 'CREATE TABLE money (cents INTEGER)');
    [$said] = streamedApart(['sites' => ['f1' => [1 => ['m1', '', []]]], 'reconnect' => true, 'floor' => 10, 'longest' => 10], sprintf(<<<'PHP'
        $manager = NightWorksIO\MutationGate\Tests\Support\LaravelTesting::managing(['main' => ['driver' => 'sqlite', 'database' => %s]]);
        $manager->getConnections()['main']->getPdo()->exec('SELECT 1');
        Stream::began('T::holds');

        if (SchemaSwitch::is('f1', 1)) {
            echo $manager->getConnections()['main']->getConfig()['database'];
            Stream::ended();
        }

        Stream::ended();
        Stream::finished();
        PHP, var_export($database, return: true)));

    expect($said)->not->toBe($database)
        ->and(sprintf('%s/results.jsonl', dirname($said)))->toBeFile();
})->skip(
    fn(): bool => ! function_exists('pcntl_fork') || (! is_dir('/proc/self/fd') && ! function_exists('outbreak_descriptors')),
    'only a PHP that forks, where Linux or outbreak.so lists the descriptors a process holds, sees a database a test holds',
);

it('forks no mutant whose site runs while the stream boots in a test, nor that test once it booted', function (): void {
    [$said, $results] = streamedApart(['sites' => ['f1' => [1 => ['m1', '', []]]], 'bootOnce' => true, 'floor' => 10, 'longest' => 10], <<<'PHP'
        Stream::began('T::boots');
        Stream::booting(booting: true);
        $booting = SchemaSwitch::is('f1', 1);
        Stream::booting(booting: false);
        echo json_encode([$booting, SchemaSwitch::is('f1', 1)]);
        Stream::ended();
        Stream::finished();
        PHP);

    expect($said)->toBe('[false,false]')
        ->and($results)->toBe([['k' => 'booted', 'm' => 'm1'], ['k' => 'parent', 't' => 'T::boots', 'p' => true]]);
})->skip(fn(): bool => ! function_exists('pcntl_fork') || ! function_exists('posix_kill'), 'a split stream needs a PHP that forks');

it('runs no mutant in a test that started a PHP process before it reached the site, and forks nothing more in it', function (): void {
    [$said, $results] = streamedApart(['sites' => ['f1' => [1 => ['m1', '', []]]], 'floor' => 10, 'longest' => 10], <<<'PHP'
        Stream::began('T::spawns');
        touch((string) getenv(ServedChildren::SPAWNED));
        echo json_encode(SchemaSwitch::is('f1', 1));
        Stream::ended();
        Stream::finished();
        PHP);

    expect($said)->toBe('false')
        ->and($results)->toBe([['k' => 'fallback', 't' => 'T::spawns'], ['k' => 'parent', 't' => 'T::spawns', 'p' => true]]);
})->skip(fn(): bool => ! function_exists('pcntl_fork') || ! function_exists('posix_kill'), 'a split stream needs a PHP that forks');

it('forks at each site once in a test', function (): void {
    [, $results] = streamedApart(['sites' => ['f1' => [1 => ['m1', '', []]]], 'floor' => 10, 'longest' => 10], <<<'PHP'
        Stream::began('T::twice');

        foreach ([1, 2] as $reach) {
            if (SchemaSwitch::is('f1', 1)) {
                Stream::ended();
            }
        }

        Stream::ended();
        Stream::finished();
        PHP);

    expect(streamFields($results, 'k', 't'))->toEqualCanonicalizing([['child', 'T::twice'], ['parent', 'T::twice']]);
})->skip(fn(): bool => ! function_exists('pcntl_fork') || ! function_exists('posix_kill'), 'a split stream needs a PHP that forks');

it('forks a mutant at a later test that reaches it once the stream fell back on its likely killer, and only at that killer before', function (string $fallingBack): void {
    [, $results] = streamedApart(['sites' => ['f1' => [1 => ['m1', 'T::killer', []]]], 'floor' => 10, 'longest' => 10], sprintf(<<<'PHP'
        foreach (['T::earlier', 'T::killer', 'T::later'] as $test) {
            $pair = $test === 'T::killer' && %1$s ? stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP) : [];
            Stream::began($test);

            if ($test === 'T::killer' && ! %1$s) {
                Stream::isolated();
            }

            if (SchemaSwitch::is('f1', 1)) {
                Stream::ended();
            }

            Stream::ended();
            array_map(fclose(...), is_array($pair) ? $pair : []);
        }

        Stream::finished();
        PHP, $fallingBack));

    expect(streamFields($results, 'k', 'm', 't'))->toEqualCanonicalizing([
        ['parent', null, 'T::earlier'],
        ['fallback', null, 'T::killer'],
        ['parent', null, 'T::killer'],
        ['child', 'm1', 'T::later'],
        ['parent', null, 'T::later'],
    ]);
})->with([
    'holding a socket as it begins' => ['true'],
    'running in a process of its own' => ['false'],
])->skip(fn(): bool => ! function_exists('pcntl_fork') || ! function_exists('posix_kill'), 'a split stream needs a PHP that forks');

it('forks no mutant at a site its likely killer reaches holding a writable file, there or later in that test, and forks the next site, and that mutant at later tests', function (): void {
    $root = Scratch::directory();
    [, $results] = streamedApart(['sites' => ['f1' => [1 => ['m1', 'T::holds', []], 2 => ['m2', '', []]]], 'floor' => 10, 'longest' => 10], sprintf(<<<'PHP'
        Stream::began('T::holds');
        $file = fopen(%s, 'w');

        if (SchemaSwitch::is('f1', 1)) {
            Stream::ended();
        }

        fclose($file);

        foreach ([1, 2] as $site) {
            if (SchemaSwitch::is('f1', $site)) {
                Stream::ended();
            }
        }

        Stream::ended();
        Stream::began('T::later');

        if (SchemaSwitch::is('f1', 1)) {
            Stream::ended();
        }

        Stream::ended();
        Stream::finished();
        PHP, var_export(sprintf('%s/written', $root), return: true)));

    expect(streamFields($results, 'k', 'm', 't'))->toEqualCanonicalizing([
        ['fallback', 'm1', 'T::holds'],
        ['child', 'm2', 'T::holds'],
        ['parent', null, 'T::holds'],
        ['child', 'm1', 'T::later'],
        ['parent', null, 'T::later'],
    ]);
})->skip(fn(): bool => ! function_exists('pcntl_fork') || ! function_exists('posix_kill'), 'a split stream needs a PHP that forks');

it('records a test that held a writable file at sites once as run in a process of its own', function (): void {
    $root = Scratch::directory();
    [, , $recorded] = streamedApart(['sites' => ['f1' => [1 => ['m1', '', []], 2 => ['m2', '', []]]], 'floor' => 10, 'longest' => 10], sprintf(<<<'PHP'
        Stream::began('T::holds');
        $file = fopen(%s, 'w');

        foreach ([1, 2] as $site) {
            if (SchemaSwitch::is('f1', $site)) {
                Stream::ended();
            }
        }

        fclose($file);
        Stream::ended();
        Stream::finished();
        PHP, var_export(sprintf('%s/written', $root), return: true)), <<<'PHP'
        function outbreak_lines_start(): bool { return true; }
        function outbreak_lines_stop(): array { return []; }
        PHP, recording: true);

    expect(streamFields($recorded, 'k', 't', 'p', 'o'))->toBe([
        ['covered', 'T::holds', false, true],
        ['covered', 'T::holds', true, true],
        ['finished', null, null, null],
    ]);
})->skip(fn(): bool => ! function_exists('pcntl_fork') || ! function_exists('posix_kill'), 'a split stream needs a PHP that forks');
