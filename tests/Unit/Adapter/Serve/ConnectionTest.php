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
        ->and(Listener::ready(NotGiven::value(), [7 => $daemon], 1000))->toBe([]);

    $client->closed();

    expect(Listener::ready(NotGiven::value(), [7 => $daemon], 1_000_000))->toBe([7])
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
        ->and(Listener::ready($listener, [], 1000))->toBe([])
        ->and($listener->accepted())->toBe([]);

    $second = Connection::to(ServedProjects::SOCKET, 1.0);

    expect(Listener::ready($listener, [], 1_000_000))->toBe([Listener::KEY])
        ->and(Listener::ready(NotGiven::value(), [], 1000))->toBe([])
        ->and($second)->toBeInstanceOf(Connection::class);
});

it('connects to nothing where nothing listens, and binds nothing where the directory is missing', function (): void {
    ServedProjects::entered();

    expect(Connection::to('.mutation-gate/none.sock', 0.1))->toBeInstanceOf(NotGiven::class)
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
