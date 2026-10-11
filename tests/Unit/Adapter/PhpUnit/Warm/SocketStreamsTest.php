<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\PhpUnit\Warm\SocketStreams;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

it('counts the sockets among some streams, each once, and no other stream', function (): void {
    $server = stream_socket_server('tcp://127.0.0.1:0');
    $file = fopen(sprintf('%s/a.txt', Scratch::directory()), 'w');
    $memory = fopen('php://memory', 'r+');
    $streams = array_values(array_filter([$server, $file, $memory], is_resource(...)));
    $counted = [SocketStreams::among($streams), SocketStreams::among([]), SocketStreams::among(array_slice($streams, 1))];

    foreach ($streams as $stream) {
        fclose($stream);
    }

    expect($counted)->toBe([1, 0, 0]);
});
