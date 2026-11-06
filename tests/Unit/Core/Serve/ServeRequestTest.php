<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Serve\ServeIdentity;
use NightWorksIO\MutationGate\Core\Serve\ServeRequest;

it('reads a request to run back from its line, its command line, variables, standard input and decoration', function (): void {
    $identity = ServeIdentity::of('/p', '1.0.0', 'lock', 'config');
    $request = ServeRequest::running($identity, ['bin/mutation-gate', 'pre-push', 'origin'], ['COLUMNS' => '80'], "a b c d\n", decorated: true);
    $read = ServeRequest::readFrom($request->line());

    expect($request->line())->toEndWith("\n")
        ->and($read)->toBeInstanceOf(ServeRequest::class)
        ->and($read instanceof ServeRequest ? [$read->stops(), $read->argv(), $read->environment(), $read->input(), $read->isDecorated(), $read->identity()->root()] : [])
        ->toBe([false, ['bin/mutation-gate', 'pre-push', 'origin'], ['COLUMNS' => '80'], "a b c d\n", true, '/p']);
});

it('reads a request to stop back from its line, with nothing to run', function (): void {
    $read = ServeRequest::readFrom(ServeRequest::stopping(ServeIdentity::of('/p', '1.0.0', '', ''))->line());

    expect($read instanceof ServeRequest ? [$read->stops(), $read->argv(), $read->environment(), $read->input(), $read->isDecorated()] : [])
        ->toBe([true, [], [], '', false]);
});

it('reads no request from a line that is not one', function (string $line): void {
    expect(ServeRequest::readFrom($line))->toBeInstanceOf(NotGiven::class);
})->with([
    'not JSON' => ['hello'],
    'no identity' => ['{"stop":true}'],
    'a command line that is no list' => ['{"identity":{"root":"/p","version":"1","lock":"","config":""},"stop":false,"argv":"run","environment":{},"input":"","decorated":false}'],
]);
