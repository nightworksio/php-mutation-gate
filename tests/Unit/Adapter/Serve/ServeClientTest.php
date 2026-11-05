<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Serve\ChildEnvironment;
use NightWorksIO\MutationGate\Adapter\Serve\ServeClient;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Serve\ServeFrame;
use NightWorksIO\MutationGate\Core\Serve\ServeIdentity;
use NightWorksIO\MutationGate\Core\Serve\ServeRefusal;
use NightWorksIO\MutationGate\Core\Serve\ServeRequest;
use NightWorksIO\MutationGate\Core\Serve\ServeStream;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\ServedProjects;

afterEach(function (): void {
    ServedProjects::left();
    Scratch::sweep();
});

/** A client that keeps what each answer writes to each stream, and the streams it keeps them in. */
$keeping = static function (): array {
    $kept = new ArrayObject(['output' => '', 'error' => '']);
    $client = new ServeClient(
        ServedProjects::socket(),
        static function (string $text) use ($kept): void {
            $kept['output'] = sprintf('%s%s', $kept['output'], $text);
        },
        static function (string $text) use ($kept): void {
            $kept['error'] = sprintf('%s%s', $kept['error'], $text);
        },
    );

    return [$client, $kept];
};

/** A request to run pre-commit. */
$preCommit = static fn(): ServeRequest => ServeRequest::running(ServeIdentity::of('/p', '1', '', ''), ['gate', 'pre-commit'], [], '', decorated: false);

it('sends the request, writes each stream\'s text where it goes as it arrives, skips a line that is no frame, and ends with the exit code', function () use ($keeping, $preCommit): void {
    ServedProjects::entered();
    $heard = ServedProjects::answering(
        ServeFrame::written(ServeStream::Output, "out\n")->line(),
        "noise\n",
        ServeFrame::written(ServeStream::Error, "err\n")->line(),
        ServeFrame::exited(3)->line(),
        ServeFrame::written(ServeStream::Output, 'after')->line(),
    );
    [$client, $kept] = $keeping();

    expect($client->answered($preCommit()))->toBe(3)
        ->and($kept->getArrayCopy())->toBe(['output' => "out\n", 'error' => "err\n"])
        ->and(file_get_contents($heard))->toBe($preCommit()->line());
});

it('says why the daemon refused, and that the answer broke off where it ends with no exit code', function () use ($keeping, $preCommit): void {
    ServedProjects::entered();
    ServedProjects::answering(ServeFrame::refused('Stopped.')->line());
    [$client, $kept] = $keeping();

    expect($client->answered($preCommit()))->toEqual(ServeRefusal::because('Stopped.'));

    ServedProjects::answering(ServeFrame::written(ServeStream::Output, 'half')->line());

    expect($client->answered($preCommit()))
        ->toEqual(CannotJudge::because('The daemon ended the run before it said its exit code.'))
        ->and($kept['output'])->toBe('half');
});

it('sends nothing where no daemon listens, or where the socket is not its user\'s own', function () use ($keeping, $preCommit): void {
    ServedProjects::entered();
    [$client] = $keeping();

    expect($client->answered($preCommit()))->toBeInstanceOf(NotGiven::class);

    chmod('.mutation-gate', 0o755);
    touch(ServedProjects::SOCKET);

    expect($client->answered($preCommit()))->toBeInstanceOf(ServeRefusal::class);
});

it('makes a child\'s environment the daemon\'s, with each variable a client sends taken from it and the rest of those unset', function (): void {
    putenv('COLUMNS=10');
    putenv('LINES=20');
    putenv('PRE_COMMIT_TO_REF');
    putenv('SERVE_TEST_SECRET=kept');

    ChildEnvironment::madeAs(['COLUMNS' => '120', 'PRE_COMMIT_TO_REF' => 'abc', 'SERVE_TEST_SECRET' => 'sent']);
    $seen = [getenv('COLUMNS'), getenv('LINES'), getenv('PRE_COMMIT_TO_REF'), getenv('SERVE_TEST_SECRET'), getenv('MUTATION_GATE_SERVED')];

    foreach (['COLUMNS', 'LINES', 'PRE_COMMIT_TO_REF', 'SERVE_TEST_SECRET', 'MUTATION_GATE_SERVED'] as $name) {
        putenv($name);
    }

    expect($seen)->toBe(['120', false, 'abc', 'kept', '1']);
});
