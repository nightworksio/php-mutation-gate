<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Warm\WholeFile;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

it('writes a file whole, leaving nothing beside it, and reads back the JSON it holds', function (): void {
    $directory = Scratch::directory();
    $file = sprintf('%s/1.request', $directory);
    $written = WholeFile::written($file, '{"id": "42-1"}');
    $read = WholeFile::read($file);

    expect($written)->toBeTrue()
        ->and($read instanceof Node ? $read->field('id')->text() : $read)->toBe('42-1')
        ->and(glob(sprintf('%s/*', $directory)))->toBe([$file]);
});

it('reads nothing where there is no file, or it holds no JSON', function (): void {
    $file = sprintf('%s/1.request', Scratch::directory());
    $missing = WholeFile::read($file);
    file_put_contents($file, '{"id":');

    expect($missing)->toEqual(NotGiven::value())
        ->and(WholeFile::read($file))->toEqual(NotGiven::value());
});
