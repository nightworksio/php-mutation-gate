<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Psalm\Frames;

it('frames a message with its length in bytes', function (): void {
    expect(Frames::framed('{"a":"é"}'))->toBe("Content-Length: 10\r\n\r\n{\"a\":\"é\"}");
});

it('reads every whole message, whatever its header says besides its length, and keeps what is not yet whole', function (): void {
    $stream = sprintf(
        "%sContent-Type: application/vscode-jsonrpc; charset=utf8\r\ncontent-length: 10\r\n\r\n{\"b\":\"é\"}%s",
        Frames::framed('{"a":1}'),
        "Content-Length: 7\r\n\r\n{\"c\"",
    );
    $read = Frames::read($stream);

    expect($read->bodies())->toBe(['{"a":1}', '{"b":"é"}'])
        ->and($read->rest())->toBe("Content-Length: 7\r\n\r\n{\"c\"")
        ->and(Frames::read(sprintf('%s:2}', $read->rest()))->bodies())->toBe(['{"c":2}']);
});

it('keeps a header not yet whole, and reads a header without a length as an empty message', function (): void {
    expect(Frames::read('Content-Len')->bodies())->toBe([])
        ->and(Frames::read('Content-Len')->rest())->toBe('Content-Len')
        ->and(Frames::read("X: 1\r\n\r\n")->bodies())->toBe([''])
        ->and(Frames::read("X: 1\r\n\r\n")->rest())->toBe('')
        ->and(Frames::read(sprintf("X: 1\r\n\r\n%s", Frames::framed('{"a":1}')))->bodies())->toBe(['', '{"a":1}'])
        ->and(Frames::read('')->rest())->toBe('');
});
