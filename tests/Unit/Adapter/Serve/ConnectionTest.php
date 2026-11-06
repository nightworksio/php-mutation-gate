<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Serve\Connection;
use NightWorksIO\MutationGate\Adapter\Serve\FramedOutput;
use NightWorksIO\MutationGate\Adapter\Serve\Listener;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Serve\ServeFrame;
use NightWorksIO\MutationGate\Core\Serve\ServeStream;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\ServedProjects;

afterEach(function (): void {
    ServedProjects::left();
    Scratch::sweep();
});

it('carries lines each way, and tells that the other end has closed only once it has', function (): void {
    ServedProjects::entered();
    [$listener, $client, $daemon] = ServedProjects::connectedPair();

    expect($client->sent("hello\n"))->toBeTrue()
        ->and($daemon->waitingAtMost(1)->line())->toBe("hello\n")
        ->and($daemon->sent("back\n"))->toBeTrue()
        ->and($client->line())->toBe("back\n")
        ->and(Listener::ready(NotGiven::value(), [7 => $daemon], 1000))->toBe([false, []]);

    $client->closed();

    expect(Listener::ready(NotGiven::value(), [7 => $daemon], 1_000_000))->toBe([false, [7]])
        ->and($daemon->hasEnded())->toBeTrue()
        ->and($daemon->line())->toBeInstanceOf(NotGiven::class);

    $daemon->closed();
    $daemon->closed();
    $listener->closed();
    $listener->closed();
});

it('tells a client still connected has not ended, and the listener ready once a client waits', function (): void {
    ServedProjects::entered();
    [$listener, $client, $daemon] = ServedProjects::connectedPair();
    $client->sent('x');

    expect($daemon->hasEnded())->toBeFalse()
        ->and(Listener::ready($listener, [], 1000))->toBe([false, []])
        ->and($listener->accepted())->toBe([]);

    $second = Connection::to(ServedProjects::SOCKET);

    expect(Listener::ready($listener, [], 1_000_000))->toBe([true, []])
        ->and(Listener::ready(NotGiven::value(), [], 1000))->toBe([false, []])
        ->and($second)->toBeInstanceOf(Connection::class);
});

it('connects to nothing where nothing listens, and binds nothing where the directory is missing', function (): void {
    ServedProjects::entered();

    expect(Connection::to('.mutation-gate/none.sock'))->toBeInstanceOf(NotGiven::class)
        ->and(Listener::boundTo('missing/serve.sock'))->toBeInstanceOf(CannotJudge::class)
        ->and(Listener::directoryMade('.mutation-gate/inner'))->toBeTrue()
        ->and(fileperms('.mutation-gate/inner') & 0o777)->toBe(0o700);

    touch('blocked');

    expect(Listener::directoryMade('blocked/inner'))->toBeFalse();
});

it('says a write to a peer that has gone was not sent', function (): void {
    ServedProjects::entered();
    [, $client, $daemon] = ServedProjects::connectedPair();
    $client->closed();
    $sent = true;

    foreach (range(1, 50) as $ignored) {
        $sent = $sent && $daemon->sent(str_repeat('x', 65536));
    }

    expect($sent)->toBeFalse();
});

it('sends each write of a child\'s output as a frame of its stream, a line ended where it ends one', function (): void {
    ServedProjects::entered();
    [, $client, $daemon] = ServedProjects::connectedPair();
    $output = new FramedOutput($daemon, ServeStream::Error, decorated: true);
    $output->write('half');
    $output->writeln('<info>whole</info>');
    $first = ServeFrame::readFrom(ServedProjects::lineOf($client));
    $second = ServeFrame::readFrom(ServedProjects::lineOf($client));

    expect($output->isDecorated())->toBeTrue()
        ->and($first instanceof ServeFrame ? [$first->stream(), $first->text()] : [])->toBe([ServeStream::Error, 'half'])
        ->and($second instanceof ServeFrame ? $second->text() : '')->toBe(sprintf("\e[32mwhole\e[39m%s", PHP_EOL));
});

it('tells the process at the other end, its own on both ends of a pair, and none of a stream that is no socket', function (): void {
    ServedProjects::entered();
    [, $client, $daemon] = ServedProjects::connectedPair();
    $file = fopen(sprintf('%s/plain', Scratch::directory()), 'w+b');
    error_clear_last();
    $plain = is_resource($file) ? Connection::over($file)->peer() : 0;

    expect([$client->peer(), $daemon->peer()])->toBe([getmypid(), getmypid()])
        ->and($plain)->toEqual(NotGiven::value())
        ->and(error_get_last())->toBeNull()
        ->and(Connection::tellsPeers())->toBeTrue();
});

it('names the socket option that tells a peer on Linux and on macOS, and none elsewhere', function (): void {
    expect([Connection::peerOptionOn('Linux'), Connection::peerOptionOn('Darwin'), Connection::peerOptionOn('Windows')])
        ->toEqual([[1, 17], [0, 2], NotGiven::value()]);
});

it('raises no warning where nothing listens, or a write finds the peer gone', function (): void {
    ServedProjects::entered();
    error_clear_last();
    $none = Connection::to('.mutation-gate/none.sock');
    [, $client, $daemon] = ServedProjects::connectedPair();
    $client->closed();
    $sent = true;

    foreach (range(1, 50) as $ignored) {
        $sent = $sent && $daemon->sent(str_repeat('x', 65536));
    }

    expect($none)->toEqual(NotGiven::value())
        ->and($sent)->toBeFalse()
        ->and(error_get_last())->toBeNull();
});

it('gives up a read on a quiet peer once its wait is up, and tells a quiet peer has not ended', function (): void {
    ServedProjects::entered();
    [, $client, $daemon] = ServedProjects::connectedPair();
    $started = hrtime(as_number: true);
    $line = $daemon->waitingAtMost(1)->line();
    $ended = $daemon->hasEnded();
    $waited = (hrtime(as_number: true) - $started) / 1e9;

    expect($line)->toEqual(NotGiven::value())
        ->and($ended)->toBeFalse()
        ->and($client->sent(''))->toBeTrue()
        ->and($waited)->toBeLessThan(5.0);
});

it('tells a client waiting on the listener apart from a connection that has something to read, by the connection\'s key', function (): void {
    ServedProjects::entered();
    [$listener, $client, $daemon] = ServedProjects::connectedPair();
    $other = Connection::to(ServedProjects::SOCKET);
    $accepted = array_first($listener->accepted());
    $client->sent("x\n");
    if ($other instanceof Connection) {
        $other->sent("y\n");
    }
    $waiting = Connection::to(ServedProjects::SOCKET);
    $connections = $accepted instanceof Connection ? [4242 => $daemon, 4343 => $accepted] : [4242 => $daemon];

    expect(Listener::ready($listener, $connections, 1_000_000))->toBe([true, [4242, 4343]])
        ->and($waiting)->toBeInstanceOf(Connection::class);
});

it('makes a missing directory with the ones above it, and raises no warning where it cannot make one, bind, accept or wait', function (): void {
    ServedProjects::entered();
    touch('blocked');
    $mask = umask(0o002);
    error_clear_last();
    $made = Listener::directoryMade('deep/er/inner');
    $blocked = Listener::directoryMade('blocked/inner');
    $unbound = Listener::boundTo('missing/serve.sock');
    $listener = Listener::boundTo(ServedProjects::SOCKET);
    $started = hrtime(as_number: true);
    $accepted = $listener instanceof Listener ? $listener->accepted() : ['unbound'];
    $waited = (hrtime(as_number: true) - $started) / 1e9;
    pcntl_async_signals(enable: true);
    pcntl_signal(SIGALRM, static function (): void {
    });
    pcntl_alarm(1);
    $interrupted = $listener instanceof Listener ? Listener::ready($listener, [], 3_000_000) : [true, []];
    pcntl_signal(SIGALRM, SIG_DFL);

    expect([$made, $blocked, is_dir('deep/er/inner')])->toBe([true, false, true])
        ->and($unbound instanceof CannotJudge ? $unbound->why() : '')
        ->toMatch('/^The daemon could not listen on missing\/serve\.sock: stream_socket_server\(\): \S/')
        ->and(umask($mask))->toBe(0o002)
        ->and($accepted)->toBe([])
        ->and($waited)->toBeLessThan(0.5)
        ->and($interrupted)->toBe([false, []])
        ->and(error_get_last())->toBeNull();
});
