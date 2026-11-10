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

it('triples while it holds a writable file', function (): void {
    $file = tmpfile();

    expect(new Streamed()->tripled(6))->toBe(18);

    if (is_resource($file)) {
        fwrite($file, 'held');
        fclose($file);
    }
});

it('quadruples while it holds a file-backed SQLite database', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'streamed');
    $database = new PDO(sprintf('sqlite:%s', $path));
    $database->exec('CREATE TABLE held (value INTEGER)');

    expect(new Streamed()->quadrupled(5))->toBe(20);

    unset($database);
    unlink($path);
});

it('shifts while it reads a file', function (): void {
    $file = fopen(__FILE__, 'r');

    expect(new Streamed()->shifted(1))->toBe(11);

    if (is_resource($file)) {
        fclose($file);
    }
});
