<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Opcache\KeptOpcodes;
use NightWorksIO\MutationGate\Adapter\Opcache\Opcodes;
use NightWorksIO\MutationGate\Adapter\Opcache\Uncompiled;

$opcodes = static fn(int $returned): Opcodes|Uncompiled => Opcodes::dumped(sprintf("\n\$_main:\n0000 RETURN int(%d)\n", $returned), '/tmp/program.php');

it('keeps the programs that compiled, by their keys, and none that failed or found no opcache', function () use ($opcodes): void {
    $kept = new KeptOpcodes(10);
    $kept->keep(['one' => 'a', 'two' => 'b', 'three' => 'c'], ['one' => $opcodes(1), 'two' => Uncompiled::Failed, 'three' => Uncompiled::NoOpcache]);

    expect($kept->of(['first' => 'a', 'second' => 'b', 'third' => 'c', 'fourth' => 'd']))->toEqual(['first' => $opcodes(1)]);
});

it('keeps the most recently used, as many as it may, and none where that is none', function () use ($opcodes): void {
    $kept = new KeptOpcodes(2);
    $none = new KeptOpcodes(0);
    $kept->keep(['a' => 'a', 'b' => 'b'], ['a' => $opcodes(1), 'b' => $opcodes(2)]);
    $kept->of(['a' => 'a']);
    $kept->keep(['c' => 'c'], ['c' => $opcodes(3)]);
    $none->keep(['a' => 'a'], ['a' => $opcodes(1)]);

    expect(array_keys($kept->of(['a' => 'a', 'b' => 'b', 'c' => 'c'])))->toBe(['a', 'c'])
        ->and($none->of(['a' => 'a']))->toBe([]);
});

it('is one store for the process, keeping as many as it was last told', function () use ($opcodes): void {
    $process = KeptOpcodes::inProcess(2);
    $process->keep(['a' => 'kept-test-a', 'b' => 'kept-test-b'], ['a' => $opcodes(1), 'b' => $opcodes(2)]);
    $narrowed = KeptOpcodes::inProcess(1);

    expect($narrowed)->toBe($process)
        ->and(array_keys($narrowed->of(['a' => 'kept-test-a', 'b' => 'kept-test-b'])))->toBe(['b']);
});
