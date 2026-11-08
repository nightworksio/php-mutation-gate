<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Split\Sockets;

it('counts the sockets this process holds open, and no more once they close', function (): void {
    $before = Sockets::open();
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    $opened = Sockets::open();

    foreach (is_array($pair) ? $pair : [] as $socket) {
        fclose($socket);
    }

    expect($opened)->toBe($before + 2)
        ->and(Sockets::open())->toBe($before);
});
