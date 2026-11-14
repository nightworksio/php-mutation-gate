<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Opcache\PrintedDeclarations;
use NightWorksIO\MutationGate\Adapter\Outbreak\OpArrays;
use NightWorksIO\MutationGate\Adapter\Outbreak\ProvenBeforeRun;
use NightWorksIO\MutationGate\Adapter\Outbreak\ShippedExtension;
use NightWorksIO\MutationGate\Core\Config\Options;
use NightWorksIO\MutationGate\Core\Config\Outbreak as OutbreakSettings;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Tests\Support\Configs;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\ShippedExtensions;

afterEach(function (): void {
    Scratch::sweep();
});

$shipped = static fn(Options $options, string $directory): ShippedExtension|NotGiven
    => ShippedExtension::beneath($options, $directory, new PrintedDeclarations());

it('loads the shipped extension where a setting asks for it, proving mutants before they run and telling a stream what it watches', function () use ($shipped): void {
    $directory = ShippedExtensions::directory();
    $extension = $shipped(Configs::options('{"outbreak": {"forkStates": true, "stepFloor": 500}}'), $directory);
    $results = Scratch::directory();

    expect($extension)->toBeInstanceOf(ShippedExtension::class)
        ->and($extension instanceof ShippedExtension ? [
            $extension->recordsLines(),
            $extension->stepFloor(),
            $extension->forStreams(recording: true),
            $extension->forStreams(recording: false),
        ] : [])->toBe([true, 500, [ShippedExtensions::in($directory), 'states,touches,lines,steps'], [ShippedExtensions::in($directory), 'states,touches,steps']])
        ->and($extension instanceof ShippedExtension ? $extension->prover($results) : null)->toEqual(new ProvenBeforeRun(
            new OpArrays(PHP_BINARY, ShippedExtensions::in($directory), sprintf('%s/outbreak-programs', $results), OutbreakSettings::of()->timeout()->seconds()),
            new PrintedDeclarations(),
        ));
})->skip(PHP_ZTS === 1, 'no extension ships for a thread-safe PHP');

it('proves nothing, counts no steps and has a stream watch nothing where each setting is off', function () use ($shipped): void {
    $extension = $shipped(Configs::options('{"outbreak": {"equivalence": true, "recheckTouchedKills": false, "lines": false, "steps": false}}'), ShippedExtensions::directory());
    $quiet = $shipped(Configs::options('{"outbreak": {"equivalence": false, "lines": false, "steps": false}}'), ShippedExtensions::directory());

    expect($extension instanceof ShippedExtension ? [$extension->recordsLines(), $extension->stepFloor(), $extension->forStreams(recording: true)] : [])
        ->toEqual([false, NotGiven::value(), NotGiven::value()])
        ->and($quiet instanceof ShippedExtension ? $quiet->prover(Scratch::directory()) : null)->toEqual(NotGiven::value());
})->skip(PHP_ZTS === 1, 'no extension ships for a thread-safe PHP');

it('loads none where Outbreak is off, no setting asks for it, or none ships for this PHP', function () use ($shipped): void {
    expect($shipped(Configs::options('{"outbreak": {"enabled": false}}'), ShippedExtensions::directory()))->toEqual(NotGiven::value())
        ->and($shipped(Configs::options('{"outbreak": {"equivalence": false, "recheckTouchedKills": false, "lines": false, "steps": false}}'), ShippedExtensions::directory()))->toEqual(NotGiven::value())
        ->and($shipped(Options::none(), Scratch::directory()))->toEqual(NotGiven::value());
});
