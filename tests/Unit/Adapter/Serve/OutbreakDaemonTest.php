<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Serve\OutbreakDaemon;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Local\System;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Reach\GraphAnswer;
use NightWorksIO\MutationGate\Core\Reach\GraphQuestion;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Watch\Unwatched;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Tests\Support\AnsweringDaemon;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

it('asks the daemon beside its files where the path fits a socket\'s address, and in the user\'s short directory otherwise', function (): void {
    $long = sprintf('/srv/%s', str_repeat('a', 120));

    expect(OutbreakDaemon::of('/srv/app', 501, Seconds::of(1.0))->proofs('k'))
        ->toEqual(NotWritten::because('No outbreak serve answers on /srv/app/.mutation-gate/outbreak/state.sock.'))
        ->and(OutbreakDaemon::of($long, 501, Seconds::of(1.0))->proofs('k'))
        ->toEqual(NotWritten::because(sprintf(
            'No outbreak serve answers on /tmp/mutation-gate-501/%s.sock.',
            substr(hash('sha256', $long), 0, 16),
        )));
});

it('listens once its socket is there, and not where it never comes within the wait', function (): void {
    $root = sprintf('%s/%s', Scratch::directory(), str_repeat('a', 120));
    $directory = sprintf('/tmp/mutation-gate-%d', posix_geteuid());
    $socket = sprintf('%s/%s.sock', $directory, substr(hash('sha256', $root), 0, 16));
    $daemon = OutbreakDaemon::of($root, posix_geteuid(), Seconds::of(1.0));
    $silent = $daemon->listening(Seconds::of(0.02));

    if (! is_dir($directory)) {
        mkdir($directory, 0o700);
    }

    touch($socket);
    $listening = $daemon->listening(Seconds::of(0.02));
    unlink($socket);

    expect($silent)->toBeFalse()
        ->and($listening)->toBeTrue();
});

/** A root a daemon answered in removed, with the directories its socket lay in. */
function daemonGone(string $root): void
{
    foreach (['/.mutation-gate/outbreak', '/.mutation-gate', ''] as $directory) {
        $path = sprintf('%s%s', $root, $directory);

        if (is_dir($path)) {
            rmdir($path);
        }
    }
}

/** A root short enough that a daemon's socket lies beside its files, which nothing holds yet. */
function daemonRoot(): string
{
    return sprintf('/tmp/pmg-daemon-%s', sodium_bin2hex(random_bytes(4)));
}

/**
 * The daemon of a root short enough that its socket lies beside its files, answering each request so, and the root.
 *
 * @return array{AnsweringDaemon, OutbreakDaemon, string}
 */
function daemonAnswering(string ...$answers): array
{
    $root = daemonRoot();

    return [AnsweringDaemon::on(sprintf('%s/.mutation-gate/outbreak/state.sock', $root), ...$answers), OutbreakDaemon::of($root, posix_geteuid(), Seconds::of(2.0)), $root];
}

it('reads the generation of the next change the daemon tells of, or that it watches none', function (): void {
    [$answering, $daemon, $root] = daemonAnswering('{"outbreak":1,"changed":7}', '{"outbreak":1,"unwatched":"No power source is watched."}', '{"outbreak":1,"changed":"seven"}');

    try {
        $changed = $daemon->changed(['src'], 3, Seconds::of(0.1));
        $powered = $daemon->powered(3, Seconds::of(0.1));
        $garbled = $daemon->changed(['src'], 3, Seconds::of(0.1));
    } finally {
        $answering->stopped();
        daemonGone($root);
    }

    expect($changed)->toBe(7)
        ->and($powered)->toEqual(Unwatched::because('No power source is watched.'))
        ->and($garbled instanceof NotWritten ? $garbled->why() : $garbled)->toStartWith('outbreak serve answered in another shape');
});

it('reads no answer from a daemon that answers in another contract or shape, refuses, says nothing, or tells proofs and counts it cannot', function (): void {
    [$answering, $daemon, $root] = daemonAnswering(
        '{"outbreak":2}',
        '{"outbreak":1,"refused":"Not now."}',
        '{"outbreak":"one"}',
        '',
        '{"outbreak":1,"proofs":"none"}',
        '{"outbreak":1,"kept":"some"}',
    );

    try {
        $said = [
            $daemon->proofs('k'),
            $daemon->proofs('k'),
            $daemon->proofs('k'),
            $daemon->proofs('k'),
            $daemon->proofs('k'),
            $daemon->kept(['k' => 'proof']),
        ];
    } finally {
        $answering->stopped();
        daemonGone($root);
    }

    $read = array_map(static fn(array|Written|NotWritten $one): string => match (true) {
        ! $one instanceof NotWritten => 'answered',
        str_starts_with($one->why(), 'outbreak serve answered in another shape') => 'another shape',
        str_starts_with($one->why(), 'outbreak serve refused the request: Not now.') => 'refused',
        str_starts_with($one->why(), 'No outbreak serve answers on') => 'no answer',
        default => $one->why(),
    }, $said);

    expect($read)->toBe(['another shape', 'refused', 'another shape', 'no answer', 'another shape', 'another shape']);
});

it('asks the daemon\'s static graph a question', function (): void {
    [$answering, $daemon, $root] = daemonAnswering('{"outbreak":1,"reaching":{"src/Money.php":["tests/MoneyTest.php"]},"joined":{},"named":{},"wrong":[],"unplaced":{}}');

    try {
        $answer = $daemon->answered(GraphQuestion::over(Paths::of(Path::of('src/Money.php')), Paths::none(), Paths::none()));
    } finally {
        $answering->stopped();
        daemonGone($root);
    }

    expect($answer instanceof GraphAnswer ? $answer->reaching(Path::of('src/Money.php')) : $answer)->toEqual(Paths::of(Path::of('tests/MoneyTest.php')));
});

it('reads no generation where no daemon answers', function (): void {
    $changed = OutbreakDaemon::of(daemonRoot(), posix_geteuid(), Seconds::of(0.1))->changed(['src'], 3, Seconds::of(0.1));

    expect($changed instanceof NotWritten ? $changed->why() : $changed)->toStartWith('No outbreak serve answers on');
});

it('asks for the proofs of each key, keeps each proof or none, forgets keys, and waits for a change in the paths or the power since a generation, to the millisecond', function (): void {
    $root = daemonRoot();
    $answering = AnsweringDaemon::on(
        sprintf('%s/.mutation-gate/outbreak/state.sock', $root),
        '{"outbreak":1,"proofs":{}}',
        '{"outbreak":1,"kept":1}',
        '{"outbreak":1,"kept":0}',
        '{"outbreak":1,"forgot":2}',
        '{"outbreak":1,"changed":1}',
        '{"outbreak":1,"changed":1}',
        '{"outbreak":1,"changed":1}',
    );
    $daemon = OutbreakDaemon::of($root, posix_geteuid(), Seconds::of(2.0));

    try {
        $daemon->proofs('k1', 'k2');
        $daemon->kept(['k1' => 'p1']);
        $daemon->kept([]);
        $daemon->forgotten('k1', 'k2');
        $daemon->changed(['src', 'tests'], 3, Seconds::of(0.0016));
        $daemon->changed(['src'], 4, Seconds::of(0.0014));
        $daemon->powered(5, Seconds::of(0.25));
        $asked = $answering->asked();
    } finally {
        $answering->stopped();
        daemonGone($root);
    }

    expect($asked)->toBe([
        'proofs {"keys":["k1","k2"]}',
        'keep {"proofs":{"k1":"p1"}}',
        'keep {"proofs":{}}',
        'forget {"keys":["k1","k2"]}',
        'changed {"paths":["src","tests"],"since":3,"wait":2}',
        'changed {"paths":["src"],"since":4,"wait":1}',
        'power {"since":5,"wait":250}',
    ]);
});

it('waits for an answer for the timeout and the wait asked, rounded up to the second', function (float $timeout, float $wait): void {
    $root = daemonRoot();
    $answering = AnsweringDaemon::after(1_200_000, sprintf('%s/.mutation-gate/outbreak/state.sock', $root), '{"outbreak":1,"changed":9}');

    try {
        $changed = OutbreakDaemon::of($root, posix_geteuid(), Seconds::of($timeout))->changed(['src'], 1, Seconds::of($wait));
    } finally {
        $answering->stopped();
        daemonGone($root);
    }

    expect($changed)->toBe(9);
})->with([
    'a sum just past a second' => [0.6, 0.7],
]);

it('keeps the daemon\'s socket beside its files up to the longest address this system takes, and no longer', function (): void {
    $longest = System::here() === System::Linux ? 107 : 103;
    $suffix = '/.mutation-gate/outbreak/state.sock';
    $fits = sprintf('/srv/%s', str_repeat('a', $longest - strlen($suffix) - 5));
    $past = sprintf('%sa', $fits);

    expect(OutbreakDaemon::socketOf($fits, 501))->toBe(sprintf('%s%s', $fits, $suffix))
        ->and(OutbreakDaemon::socketOf($past, 501))->toBe(sprintf('/tmp/mutation-gate-501/%s.sock', substr(hash('sha256', $past), 0, 16)));
});
