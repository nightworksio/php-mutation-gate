<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Serve\ServeFrame;
use NightWorksIO\MutationGate\Core\Serve\ServeStream;

it('reads each frame back from its line: written text, an exit code and a refusal', function (string $stream, string $text, int $code): void {
    $kind = ServeStream::from($stream);
    $frame = match ($kind) {
        ServeStream::Exit => ServeFrame::exited($code),
        ServeStream::Refusal => ServeFrame::refused($text),
        ServeStream::Output, ServeStream::Error => ServeFrame::written($kind, $text),
    };
    $read = ServeFrame::readFrom($frame->line());

    expect($frame->line())->toEndWith("\n")
        ->and($read instanceof ServeFrame ? [$read->stream(), $read->text(), $read->code()] : [])
        ->toBe([$kind, $text, $code]);
})->with([
    'output' => ['printed', "line\n", 0],
    'error output' => ['warned', 'oops', 0],
    'an exit code' => ['exit', '', 3],
    'a refusal' => ['refused', 'Stopped.', 0],
]);

it('reads no frame from a line that is not one, or names a stream there is none of', function (string $line): void {
    expect(ServeFrame::readFrom($line))->toBeInstanceOf(NotGiven::class);
})->with([
    'not JSON' => ['hello'],
    'an unknown stream' => ['{"stream":"elsewhere","text":"","code":0}'],
    'no code' => ['{"stream":"exit","text":""}'],
]);
