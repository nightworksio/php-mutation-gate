<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\GateVariable;
use NightWorksIO\MutationGate\Adapter\Pest\Warm\Client;
use NightWorksIO\MutationGate\Adapter\Pest\Warm\Finish;
use NightWorksIO\MutationGate\Adapter\Pest\Warm\Offsets;
use NightWorksIO\MutationGate\Adapter\Pest\Warm\Pool;
use NightWorksIO\MutationGate\Adapter\Pest\Warm\Seat;
use NightWorksIO\MutationGate\Adapter\Pest\Warm\WarmDirectory;
use NightWorksIO\MutationGate\Tests\Support\GateEnvironment;
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
 * What the client ran answered, and each warning PHP raised meanwhile, as
 * the starting of a script that cannot start raises one.
 *
 * @return array{code: int, warned: list<string>}
 */
$ran = static function (string $directory, string ...$command): array {
    $warned = [];
    set_error_handler(static function (int $level, string $message) use (&$warned): bool {
        $warned[] = $message;

        return true;
    }, E_WARNING);

    try {
        $code = Client::at($directory, 1)->ran(array_values($command));
    } finally {
        restore_error_handler();
    }

    return ['code' => $code, 'warned' => $warned];
};

/**
 * Place 1's worker of the run that records to results in a scratch
 * directory, started as Pest's process starts it, for this process, which
 * it outlives by no more than a look: the workers' directory.
 */
$serving = static function (): string {
    $directory = WarmDirectory::beside(sprintf('%s/results.jsonl', Scratch::directory()));
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
 * Place 1's worker of this directory stood in for by this shell script, run
 * with the directory and these arguments, in a process of its own outside
 * the suite, which the system reaps.
 */
$standIn = static function (string $directory, string $script, string ...$arguments): void {
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
};

/** The shell that takes the request a client leaves for place 1, setting `id` to its id. */
$taking = <<<'SH'
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

it('ends as the run its place\'s worker ran ended', function () use ($ran, $serving, $script): void {
    $directory = $serving();
    $answered = $ran($directory, $script('exit(3);'));
    WarmDirectory::cleared(sprintf('%s/results.jsonl', dirname($directory)));

    expect($answered)->toBe(['code' => 3, 'warned' => []]);
});

it('ends by the signal that ended its run, with the code a shell gives it', function () use ($ran, $standIn, $taking): void {
    $directory = WarmDirectory::beside(sprintf('%s/results.jsonl', Scratch::directory()));
    mkdir($directory);
    $started = sprintf('%s/started', Scratch::directory());
    $ended = sprintf('%s/ended', Scratch::directory());
    Offsets::of(0, 0)->writtenTo($started);
    Finish::signalled(SIGWINCH, Offsets::of(0, 0))->writtenTo($ended);
    $standIn($directory, sprintf('%s%s', $taking, <<<'SH'
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

    expect($answered)->toBe(['code' => 128 + SIGWINCH, 'warned' => []])
        ->and($caught)->toBe([SIGWINCH]);
});

it('stops no child, and starts the run fresh, where its worker went with no child it forked for the run', function (string $child) use ($ran, $standIn, $taking): void {
    $directory = WarmDirectory::beside(sprintf('%s/results.jsonl', Scratch::directory()));
    mkdir($directory);
    $standIn($directory, sprintf('%s%s', $taking, 'printf "%s" "$2" > "$1/1.$id.child"; [ -n "$2" ] || rm "$1/1.$id.child"'), $child);
    $answered = $ran($directory, sprintf('%s/missing/pest', Scratch::directory()));

    expect($answered['code'])->toBe(127)
        ->and($answered['warned'])->toHaveCount(1);
})->with([
    'none written' => [''],
    'one not a process id' => ['"none"'],
]);

it('starts a run fresh where its place has no worker, ending as a shell does where it cannot', function () use ($ran): void {
    $directory = WarmDirectory::beside(sprintf('%s/results.jsonl', Scratch::directory()));
    $answered = $ran($directory, sprintf('%s/missing/pest', Scratch::directory()), '--bail');

    expect($answered['code'])->toBe(127)
        ->and($answered['warned'])->toHaveCount(1)
        ->and($answered['warned'][0])->toContain('(errno 2)');
});

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
