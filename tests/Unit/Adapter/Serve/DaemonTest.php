<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Filesystem\Waiting;
use NightWorksIO\MutationGate\Adapter\Serve\Connection;
use NightWorksIO\MutationGate\Adapter\Serve\Daemon;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Serve\ServeFrame;
use NightWorksIO\MutationGate\Core\Serve\ServeIdentity;
use NightWorksIO\MutationGate\Core\Serve\ServeRequest;
use NightWorksIO\MutationGate\Core\Serve\ServeStream;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Tests\Support\InlineForking;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\ServedClients;
use NightWorksIO\MutationGate\Tests\Support\ServedProjects;

afterEach(function (): void {
    ServedProjects::left();
    Scratch::sweep();
});

/** The identity the daemon of these tests started from. */
$started = static fn(): ServeIdentity => ServeIdentity::of('/p', '1.0.0', 'lock', 'config');

/** A request of an identity to run a pre-commit or a watch. */
$runRequest = static fn(ServeIdentity $identity, string $command): string => ServeRequest::running(
    $identity,
    ['gate', $command],
    [],
    '',
    decorated: false,
)->line();

/** A daemon of the tests' project, forking this way, idling this long, answering each request with its command line and 4. */
$daemonOf = static fn(InlineForking $forking, float $idle): Daemon => new Daemon(
    ServedProjects::socket(),
    $started(),
    Seconds::of($idle),
    static function (ServeRequest $request, Connection $connection): int {
        $connection->sent(ServeFrame::written(ServeStream::Output, implode(' ', $request->argv()))->line());

        return 4;
    },
    $forking,
);

it('runs each request in a child, which answers with what it wrote and its exit code, and stops once it idles', function () use ($started, $runRequest, $daemonOf): void {
    ServedProjects::entered();
    $kept = ServedClients::clients([$runRequest($started(), 'pre-commit'), $runRequest($started(), 'pre-push')]);
    $forking = new InlineForking();

    expect($daemonOf($forking, 2.0)->served())->toBeInstanceOf(NotGiven::class)
        ->and(ServedClients::answersIn($kept))->toBe([
            [['printed', 'gate pre-commit', 0], ['exit', '', 4]],
            [['printed', 'gate pre-push', 0], ['exit', '', 4]],
        ])
        ->and($forking->codes)->toBe([4, 4])
        ->and(file_exists(ServedProjects::SOCKET))->toBeFalse();
});

it('refuses a request it cannot read, one of another root, and one it cannot fork a child for', function () use ($started, $runRequest, $daemonOf): void {
    ServedProjects::entered();
    $kept = ServedClients::clients(["not a request\n", $runRequest(ServeIdentity::of('/q', '1.0.0', 'lock', 'config'), 'pre-commit')]);

    expect($daemonOf(new InlineForking(), 2.0)->served())->toBeInstanceOf(NotGiven::class)
        ->and(ServedClients::answersIn($kept))->toBe([
            [['refused', 'The daemon could not read the request.', 0]],
            [['refused', 'The daemon serves /p, not /q.', 0]],
        ]);

    $kept = ServedClients::clients([$runRequest($started(), 'pre-commit')]);

    expect($daemonOf(InlineForking::failing(), 2.0)->served())->toBeInstanceOf(NotGiven::class)
        ->and(ServedClients::answersIn($kept))->toBe([[['refused', 'The daemon could not fork a child for the request.', 0]]]);
});

it('stops at once where a client finds the version, the lock or the config changed, or asks it to stop', function () use ($started, $runRequest, $daemonOf): void {
    ServedProjects::entered();
    $kept = ServedClients::clients([$runRequest(ServeIdentity::of('/p', '1.0.0', 'lock', 'edited'), 'watch'), $runRequest($started(), 'pre-commit')]);

    expect($daemonOf(new InlineForking(), 60.0)->served())->toBeInstanceOf(NotGiven::class)
        ->and(ServedClients::answersIn($kept))->toBe([[['refused', 'The project\'s config changed since the daemon started, so it stopped.', 0]], []]);

    $kept = ServedClients::clients([ServeRequest::stopping($started())->line()]);

    expect($daemonOf(new InlineForking(), 60.0)->served())->toBeInstanceOf(NotGiven::class)
        ->and(ServedClients::answersIn($kept))->toBe([[['refused', 'The daemon stopped, as asked.', 0]]]);
});

it('stops a child whose client hangs up', function () use ($started, $runRequest, $daemonOf): void {
    ServedProjects::entered();
    $forking = InlineForking::holding();
    ServedClients::clients([sprintf('%s%s', $runRequest($started(), 'watch'), ServedClients::HANG_UP)]);
    $served = ServedProjects::within(4, static fn(): CannotJudge|NotGiven => $daemonOf($forking, 2.0)->served());

    expect($served)->toBeInstanceOf(NotGiven::class)
        ->and($forking->stopped)->toBe([1000]);
});

it('stops every child running where it is asked to stop', function () use ($started, $runRequest, $daemonOf): void {
    ServedProjects::entered();
    $forking = InlineForking::holding();
    $kept = ServedClients::clients([sprintf('%s%s', $runRequest($started(), 'watch'), ServedClients::HOLD), ServeRequest::stopping($started())->line()]);

    $began = hrtime(as_number: true);
    $served = $daemonOf($forking, 60.0)->served();
    $took = (hrtime(as_number: true) - $began) / Seconds::NANOSECONDS;

    expect($served)->toBeInstanceOf(NotGiven::class)
        ->and($forking->stopped)->toBe([1000])
        ->and(ServedClients::answersIn($kept))->toBe([[], [['refused', 'The daemon stopped, as asked.', 0]]])
        ->and($took)->toBeLessThan(1.5);
});

it('refuses to start where another daemon serves the project', function () use ($daemonOf): void {
    ServedProjects::entered();
    $first = ServedProjects::socket()->listened();

    expect($first)->not->toBeInstanceOf(CannotJudge::class)
        ->and($daemonOf(new InlineForking(), 1.0)->served())
        ->toEqual(CannotJudge::because('A daemon already serves this project on .mutation-gate/serve.sock.'));
});

it('stops as soon as it has idled, waiting no longer than its idle time left', function () use ($daemonOf): void {
    ServedProjects::entered();
    $began = hrtime(as_number: true);
    $served = ServedProjects::within(1, static fn(): CannotJudge|NotGiven => $daemonOf(new InlineForking(), 0.2)->served());
    $took = (hrtime(as_number: true) - $began) / Seconds::NANOSECONDS;

    expect($served)->toBeInstanceOf(NotGiven::class)
        ->and($took)->toBeLessThan(0.8);
});

it('waits up to five seconds for a client to send its request', function () use ($started, $runRequest, $daemonOf): void {
    ServedProjects::entered();
    $kept = sprintf('%s/slow-answer', (string) getcwd());
    $request = $runRequest($started(), 'pre-commit');
    $client = ServedProjects::aside(static function () use ($kept, $request): void {
        ServedProjects::until(static fn(): bool => ServedProjects::socket()->isThere());
        $connection = ServedProjects::socket()->connected();

        if ($connection instanceof Connection) {
            Waiting::every(Seconds::of(4.5))();
            $connection->sent($request);
            file_put_contents($kept, ServedProjects::lineOf($connection->waitingAtMost(5)));
        }
    });

    expect($daemonOf(new InlineForking(), 6.0)->served())->toBeInstanceOf(NotGiven::class);

    ServedProjects::waitedFor($client);
    $frame = ServeFrame::readFrom((string) file_get_contents($kept));

    expect($frame instanceof ServeFrame ? $frame->text() : '')->toBe('gate pre-commit');
});

it('refuses a request its client takes longer than five seconds to send', function () use ($started, $runRequest, $daemonOf): void {
    ServedProjects::entered();
    $kept = sprintf('%s/late-answer', (string) getcwd());
    $request = $runRequest($started(), 'pre-commit');
    $client = ServedProjects::aside(static function () use ($kept, $request): void {
        ServedProjects::until(static fn(): bool => ServedProjects::socket()->isThere());
        $connection = ServedProjects::socket()->connected();

        if ($connection instanceof Connection) {
            Waiting::every(Seconds::of(5.6))();
            $connection->sent($request);
            file_put_contents($kept, ServedProjects::lineOf($connection->waitingAtMost(5)));
        }
    });

    expect($daemonOf(new InlineForking(), 6.0)->served())->toBeInstanceOf(NotGiven::class);

    ServedProjects::waitedFor($client);

    expect(file_get_contents($kept))->toBe(ServeFrame::refused('The daemon could not read the request.')->line());
});
