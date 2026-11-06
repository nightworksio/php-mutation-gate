<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Serve\Connection;
use NightWorksIO\MutationGate\Adapter\Serve\Listener;
use NightWorksIO\MutationGate\Cli\Serve\ServedProject;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Serve\ServedVariables;
use NightWorksIO\MutationGate\Core\Serve\ServeFrame;
use NightWorksIO\MutationGate\Core\Serve\ServeRequest;
use NightWorksIO\MutationGate\Tests\Support\FlowCommands;
use NightWorksIO\MutationGate\Tests\Support\InlineForking;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\ServedCommands;
use NightWorksIO\MutationGate\Tests\Support\ServedProjects;

afterEach(function (): void {
    foreach ([...ServedVariables::names(), ServedVariables::SERVED] as $name) {
        putenv($name);
    }

    ServedProjects::left();
    Scratch::sweep();
});

it('says no daemon serves the project where it is asked to stop one and none runs', function (): void {
    expect(ServedCommands::serveWith('', new InlineForking(), ['--stop' => true]))->toBe([0, "No daemon serves this project.\n"]);
});

it('stops the daemon that serves the project, and says so', function (): void {
    $project = ServedProjects::enteredAt(FlowCommands::project());
    $listener = ServedProjects::socket()->listened();
    $child = ServedProjects::aside(static function () use ($listener): void {
        $connection = $listener instanceof Listener ? ServedProjects::acceptedFrom($listener) : NotGiven::value();

        if ($connection instanceof Connection) {
            $connection->waitingAtMost(5)->line();
            $connection->sent(ServeFrame::refused('The daemon stopped, as asked.')->line());
            $connection->closed();
        }
    });

    expect(ServedCommands::serve($project, new InlineForking(), ['--stop' => true]))->toBe([0, "The daemon stopped, as asked.\n"]);

    ServedProjects::waitedFor($child);
});

it('refuses to serve where this process cannot fork', function (): void {
    [$code, $printed] = ServedCommands::serveWith('', NotGiven::value());

    expect($code)->toBe(2)
        ->and($printed)->toContain('This process cannot fork a daemon\'s children; start the daemon with the gate\'s script.');
});

it('serves the project until it idles, leaving the starter\'s session where it is started detached', function (): void {
    $forking = new InlineForking();
    [$code, $printed] = ServedProjects::within(
        2,
        static fn(): array => ServedCommands::serveWith('"local": {"serveIdle": "1s"}', $forking, ['--detached' => true]),
    );

    expect($code)->toBe(0)
        ->and($printed)->toEndWith("on .mutation-gate/serve.sock. It stops after 1s with no request, or on `serve --stop`.\n")
        ->and($forking->detached)->toBeTrue()
        ->and(file_exists('.mutation-gate/serve.sock'))->toBeFalse();
});

it('says why it cannot serve: a config it cannot read, or another daemon serving the project', function (): void {
    [$code] = ServedCommands::serveWith('"local": {"serveIdle": "soon"}', new InlineForking());

    expect($code)->toBe(2);

    ServedProjects::left();
    $project = ServedProjects::enteredAt(FlowCommands::project());
    $listener = ServedProjects::socket()->listened();
    [$code, $printed] = ServedCommands::serve($project, new InlineForking());

    expect($code)->toBe(2)
        ->and($printed)->toContain('A daemon already serves this project on .mutation-gate/serve.sock.');
});

it('runs a request\'s command line in the gate, in the project\'s root, answering with its output and exit code', function (): void {
    $project = ServedProjects::enteredAt(FlowCommands::project('"local": {"serveIdle": "2s"}'));
    $answer = sprintf('%s/answer', $project);
    $child = ServedProjects::aside(static function () use ($project, $answer): void {
        ServedProjects::until(static fn(): bool => ServedProjects::socket()->isThere());
        $connection = ServedProjects::socket()->connected();
        $lines = [];

        if ($connection instanceof Connection) {
            $connection->sent(ServeRequest::running(
                ServedProject::identity($project, NotGiven::value()),
                ['gate', '--version'],
                ['COLUMNS' => '100'],
                '',
                decorated: false,
            )->line());

            while (is_string($line = $connection->waitingAtMost(30)->line())) {
                $frame = ServeFrame::readFrom($line);
                $lines[] = $frame instanceof ServeFrame ? [$frame->stream()->value, $frame->text(), $frame->code()] : [];
            }
        }

        file_put_contents($answer, (string) json_encode($lines));
    });
    $forking = new InlineForking();
    [$code] = ServedCommands::serve($project, $forking);
    ServedProjects::waitedFor($child);
    $frames = json_decode((string) file_get_contents($answer), associative: true);
    $frames = is_array($frames) ? $frames : [];

    expect($code)->toBe(0)
        ->and($forking->codes)->toBe([0])
        ->and(end($frames))->toBe(['exit', '', 0])
        ->and((string) json_encode($frames))->toContain('mutation-gate');
});
