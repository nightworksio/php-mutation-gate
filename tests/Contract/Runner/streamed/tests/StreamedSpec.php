<?php

declare(strict_types=1);

use Library\Streamed;

it('adds', function (): void {
    expect(new Streamed()->added(2, 3))->toBe(5);
});

it('counts', function (): void {
    expect(new Streamed()->counted(3))->toBe(3);
});

it('doubles while it holds a socket', function (): void {
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

    expect(new Streamed()->doubled(4))->toBe(8);

    if (is_array($pair)) {
        fclose($pair[0]);
        fclose($pair[1]);
    }
});
