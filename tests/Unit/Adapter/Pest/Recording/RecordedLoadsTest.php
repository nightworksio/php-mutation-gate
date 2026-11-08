<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Recording\PackageWatch;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\RecordedLoads;
use NightWorksIO\MutationGate\Core\Coverage\LoadedPackages;
use NightWorksIO\MutationGate\Core\Coverage\ProcessLoads;
use NightWorksIO\MutationGate\Core\Coverage\TestLoad;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

it('reads what each process loaded, test by test, in the order it wrote them', function (): void {
    $root = Scratch::directory();
    Scratch::write($root, 'timed.jsonl', implode("\n", [
        '["A::a",0.1,{"process":1,"firstLoaded":["acme/one"],"startedProcesses":false}]',
        '["B::a",0.1,{"process":2,"firstLoaded":[],"startedProcesses":true}]',
        '["A::b",0.1,{"process":1,"firstLoaded":[],"startedProcesses":false}]',
        '["C::a",0.1]',
        'not json',
        '',
    ]));

    expect(RecordedLoads::in(sprintf('%s/timed.jsonl', $root)))->toEqual(LoadedPackages::of(
        ProcessLoads::of(TestLoad::of('A::a', 'acme/one'), TestLoad::of('A::b')),
        ProcessLoads::of(TestLoad::of('B::a')->startingProcesses()),
    ));
});

it('reads no record of a file that holds none, or is not there, and takes one it cannot read as starting a process', function (): void {
    $root = Scratch::directory();
    Scratch::write($root, 'bare.jsonl', "[\"A::a\",0.1]\n");
    Scratch::write($root, 'odd.jsonl', "[\"A::a\",0.1,{\"process\":1}]\n");

    expect(RecordedLoads::in(sprintf('%s/bare.jsonl', $root)))->toEqual(LoadedPackages::unrecorded())
        ->and(RecordedLoads::in(sprintf('%s/none.jsonl', $root)))->toEqual(LoadedPackages::unrecorded())
        ->and(RecordedLoads::in(sprintf('%s/odd.jsonl', $root)))->toEqual(LoadedPackages::of(ProcessLoads::of(TestLoad::of('A::a')->startingProcesses())));
});

it('records this process with what its watch says', function (): void {
    $watch = PackageWatch::over(['/v/one' => 'acme/one'], static fn(): array => ['/v/one/A.php'], static fn(): string => '');

    expect(RecordedLoads::now($watch))->toBe(['process' => getmypid(), 'firstLoaded' => ['acme/one'], 'startedProcesses' => false]);
});
