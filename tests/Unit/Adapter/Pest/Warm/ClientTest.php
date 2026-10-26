<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\GateVariable;
use NightWorksIO\MutationGate\Adapter\Pest\Warm\Client;
use NightWorksIO\MutationGate\Adapter\Pest\Warm\Finish;
use NightWorksIO\MutationGate\Adapter\Pest\Warm\Offsets;
use NightWorksIO\MutationGate\Adapter\Pest\Warm\Pool;
use NightWorksIO\MutationGate\Adapter\Pest\Warm\Seat;
use NightWorksIO\MutationGate\Adapter\Pest\Warm\WarmDirectory;
use NightWorksIO\MutationGate\Core\Runner\Polling;
use NightWorksIO\MutationGate\Tests\Support\GateEnvironment;
use NightWorksIO\MutationGate\Tests\Support\Passing;
use NightWorksIO\MutationGate\Tests\Support\Pauses;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use Symfony\Component\Process\Process;

beforeEach(function (): void {
    GateEnvironment::clear();
});

afterEach(function (): void {
    GateEnvironment::restore();
    Scratch::sweep();
});

/**
 * What the client of place 1 answered for a run of this script with these
 * arguments, what it printed on each stream, how many times it paused, and
 * each warning PHP raised meanwhile, as starting a script that cannot start
 * raises one.
 *
 * @return array{code: int, out: string, errors: string, paused: list<int>, warned: list<string>}
 */
$ran = static function (string $directory, string $script, string ...$arguments): array {
    $warned = [];
    $printed = ['out' => '', 'errors' => ''];
    $pauses = new Pauses();
    set_error_handler(static function (int $level, string $message) use (&$warned): bool {
        $warned[] = $message;

        return true;
    }, E_WARNING);

    try {
        $code = Client::at(
            $directory,
            1,
            $pauses->pausing(),
            static function (string $bytes) use (&$printed): void {
                $printed['out'] = sprintf('%s%s', $printed['out'], $bytes);
            },
            static function (string $bytes) use (&$printed): void {
                $printed['errors'] = sprintf('%s%s', $printed['errors'], $bytes);
            },
        )->ran($script, array_values($arguments));
    } finally {
        restore_error_handler();
    }

    return [
        'code' => $code,
        'out' => $printed['out'],
        'errors' => $printed['errors'],
        'paused' => $pauses->count() > 0 ? $pauses->lengths() : [],
        'warned' => $warned,
    ];
};

/**
 * Place 1's worker of the run that records to results in a scratch
 * directory, started as Pest's process starts it, for this process, which
 * it outlives by no more than a look: the workers' directory.
 */
$serving = static function (): string {
    $directory = WarmDirectory::made(sprintf('%s/results.jsonl', Scratch::directory()));
    putenv(sprintf('%s=%s', GateVariable::Warm->value, $directory));
    Pool::prefix(1);

    return $directory;
};

/** A PHP script in a scratch file of its own, which a worker's child runs as it runs Pest's: its path. */
$script = static function (string $code): string {
    $file = sprintf('%s/script.php', Scratch::directory());
    file_put_contents($file, sprintf("<?php\n\n%s\n", $code));

    return $file;
};

/**
 * Place 1's worker of a scratch directory, made, stood in for by this shell
 * script, run with the directory and these arguments, in a process of its
 * own outside the suite, which the system reaps: the directory.
 */
$standIn = static function (string $script, string ...$arguments): string {
    $directory = WarmDirectory::made(sprintf('%s/results.jsonl', Scratch::directory()));
    $between = pcntl_fork();

    if ($between === 0) {
        $worker = pcntl_fork();

        if ($worker === 0) {
            pcntl_exec('/bin/sh', ['-c', $script, 'sh', $directory, ...array_values($arguments)]);
        }

        file_put_contents(Seat::at($directory, 1)->pid(), (string) $worker);
        pcntl_exec('/usr/bin/true');
    }

    pcntl_waitpid($between, $status);

    return $directory;
};

/** The shell that makes place 1's output files and takes the request a client leaves for it, setting `id` to its id. */
$taking = <<<'SH'
    touch "$1/1.out" "$1/1.err"
    for _ in $(seq 250); do [ -f "$1/1.request" ] && break; sleep 0.02; done
    [ -f "$1/1.request" ] || exit 1
    id=$(sed 's/^{"id":"\([^"]*\)".*/\1/' "$1/1.request")
    rm "$1/1.request"

    SH;

/** Whether a process still runs: `ps` lists it, and not as one ended and waiting to be reaped. */
$runs = static function (int $pid): bool {
    $asked = new Process(['ps', '-o', 'stat=', '-p', (string) $pid]);
    $asked->run();
    $state = trim($asked->getOutput());

    return $state !== '' && ! str_starts_with($state, 'Z');
};

it('prints what its worker\'s child printed, on the stream it printed it on, as it printed it, and ends as it ended', function () use ($ran, $serving, $script): void {
    $directory = $serving();
    $answered = $ran($directory, $script(<<<'PHP'
        echo 'a';
        usleep(300_000);
        fwrite(STDERR, 'b');
        usleep(300_000);
        echo 'c';
        echo implode(' ', array_slice($_SERVER['argv'], 1));
        exit(3);
        PHP), '--bail', '--filter=adds');
    $left = glob(sprintf('%s/1.*', $directory));
    WarmDirectory::cleared(sprintf('%s/results.jsonl', dirname($directory)));

    expect($answered)->toBe([
        'code' => 3,
        'out' => 'ac--bail --filter=adds',
        'errors' => 'b',
        'paused' => [Polling::interval()->microseconds()],
        'warned' => [],
    ])->and($left)->toBe([
        Seat::at($directory, 1)->err(),
        Seat::at($directory, 1)->out(),
        Seat::at($directory, 1)->pid(),
    ]);
});

it('ends by the signal that ended its run, with the code a shell gives it', function () use ($ran, $standIn, $taking): void {
    $started = sprintf('%s/started', Scratch::directory());
    $ended = sprintf('%s/ended', Scratch::directory());
    Offsets::of(0, 0)->writtenTo($started);
    Finish::signalled(SIGWINCH, Offsets::of(0, 0))->writtenTo($ended);
    $directory = $standIn(sprintf('%s%s', $taking, <<<'SH'
        cp "$2" "$1/1.$id.started"
        cp "$3" "$1/1.$id.writing" && mv "$1/1.$id.writing" "$1/1.$id.ended"
        sleep 5
        SH), $started, $ended);
    $caught = [];
    pcntl_signal(SIGWINCH, static function (int $signal) use (&$caught): void {
        $caught[] = $signal;
    });

    try {
        $answered = $ran($directory, 'vendor/bin/pest');
        pcntl_signal_dispatch();
    } finally {
        pcntl_signal(SIGWINCH, SIG_DFL);
    }

    expect($answered['code'])->toBe(128 + SIGWINCH)
        ->and($caught)->toBe([SIGWINCH]);
});

it('stops no child, and starts the run fresh, where its worker went with no child it forked for the run', function (string $child) use ($ran, $standIn, $taking): void {
    $directory = $standIn(sprintf('%s%s', $taking, 'printf "%s" "$2" > "$1/1.$id.child"; [ -n "$2" ] || rm "$1/1.$id.child"'), $child);
    $answered = $ran($directory, sprintf('%s/missing/pest', Scratch::directory()));

    expect($answered['code'])->toBe(127)
        ->and($answered['warned'])->toHaveCount(1);
})->with([
    'none written' => [''],
    'one not a process id' => ['"none"'],
]);

it('leaves no request behind that its worker never took, as it starts the run fresh', function () use ($ran): void {
    $directory = WarmDirectory::made(sprintf('%s/results.jsonl', Scratch::directory()));
    file_put_contents(Seat::at($directory, 1)->pid(), (string) Passing::for('0.3'));
    $answered = $ran($directory, sprintf('%s/missing/pest', Scratch::directory()));

    expect($answered['code'])->toBe(127)
        ->and(glob(sprintf('%s/1.*', $directory)))->toBe([Seat::at($directory, 1)->pid()]);
});

it('starts a run fresh where its place has no worker, or names none it can read, ending as a shell does where it cannot', function (string $pid) use ($ran): void {
    $directory = WarmDirectory::made(sprintf('%s/results.jsonl', Scratch::directory()));

    if ($pid !== '') {
        file_put_contents(Seat::at($directory, 1)->pid(), $pid);
    }

    $answered = $ran($directory, sprintf('%s/missing/pest', Scratch::directory()), '--bail');

    expect($answered['code'])->toBe(127)
        ->and($answered['warned'])->toHaveCount(1)
        ->and($answered['warned'][0])->toContain('(errno 2)')
        ->and(glob(sprintf('%s/1.request', $directory)))->toBe([]);
})->with([
    'no worker' => [''],
    'none it can read' => ['"worker"'],
]);

it('starts fresh a script found on the system\'s path where Pest names it without a directory', function () use ($ran): void {
    $directory = Scratch::directory();
    touch(sprintf('%s/pest-stand-in', $directory));
    chmod(sprintf('%s/pest-stand-in', $directory), 0o755);
    $path = (string) getenv('PATH');
    putenv(sprintf('PATH=/nowhere:%s', $directory));

    try {
        $found = $ran(sprintf('%s/warm', $directory), 'pest-stand-in');
        $unfound = $ran(sprintf('%s/warm', $directory), 'pest-nowhere');
    } finally {
        putenv(sprintf('PATH=%s', $path));
    }

    expect($found['warned'])->toHaveCount(1)
        ->and($found['warned'][0])->toContain('(errno 8)')
        ->and($unfound['warned'])->toHaveCount(1)
        ->and($unfound['warned'][0])->toContain('(errno 2)');
});

it('stops the child its worker forked before it went, then starts the run fresh', function () use ($ran, $serving, $script, $runs): void {
    $directory = $serving();
    $child = sprintf('%s/child', Scratch::directory());
    $orphaning = $script(sprintf(
        'file_put_contents(%s, (string) getmypid()); usleep(200_000); posix_kill(posix_getppid(), SIGKILL); sleep(30);',
        var_export($child, return: true),
    ));
    $answered = $ran($directory, $orphaning);

    expect($answered['code'])->toBe(127)
        ->and($runs((int) file_get_contents($child)))->toBeFalse();
});
