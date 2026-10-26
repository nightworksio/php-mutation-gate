<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Recording\Recorder;
use NightWorksIO\MutationGate\Adapter\Pest\Warm\Request;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

/** A run of Pest's script on a mutant of this file, or on none, asked by process 42. */
$request = static fn(string $mutated = '/app/src/Money.php'): Request => Request::of(
    '42-1',
    42,
    ['vendor/bin/pest', '--bail', '--filter="Money"'],
    $mutated === '' ? ['TEST_TOKEN' => '2'] : [Recorder::MUTANT => $mutated, 'TEST_TOKEN' => '2'],
    '/app',
);

it('is taken whole from the file it was left in, once, so no other worker takes it', function () use ($request): void {
    $file = sprintf('%s/1.request', Scratch::directory());
    $left = $request()->leftIn($file);

    expect($left)->toBeTrue()
        ->and(Request::takenFrom($file))->toEqual($request())
        ->and(Request::takenFrom($file))->toEqual(NotGiven::value())
        ->and(glob(sprintf('%s/*', dirname($file))))->toBe([]);
});

it('takes no request from a file that holds none', function (): void {
    $file = sprintf('%s/1.request', Scratch::directory());
    file_put_contents($file, '{"id": 3}');

    expect(Request::takenFrom($file))->toEqual(NotGiven::value());
});

it('names the file its run mutates, and none for a run that mutates none', function () use ($request): void {
    expect($request()->mutated())->toBe('/app/src/Money.php')
        ->and($request('')->mutated())->toBe('')
        ->and($request()->asker())->toBe(42)
        ->and($request()->id())->toBe('42-1')
        ->and($request()->directory())->toBe('/app')
        ->and($request()->argv())->toBe(['vendor/bin/pest', '--bail', '--filter="Money"']);
});

it('hands its child the environment its run was asked with', function () use ($request): void {
    expect($request()->environment())->toBe([Recorder::MUTANT => '/app/src/Money.php', 'TEST_TOKEN' => '2']);
});
