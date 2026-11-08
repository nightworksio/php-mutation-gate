<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Outbreak\Outbreak;
use NightWorksIO\MutationGate\Core\Config\Options;
use NightWorksIO\MutationGate\Core\Config\Outbreak as OutbreakSettings;
use NightWorksIO\MutationGate\Core\Coverage\MapSlice;
use NightWorksIO\MutationGate\Core\Coverage\MapSlices;
use NightWorksIO\MutationGate\Core\Coverage\MapVouch;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Tests\Support\OutbreakStandIn;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

$holds = ['holds:src/Adapter/Outbreak/Outbreak.php', 'holds:src/Adapter/Outbreak/Command.php'];

$slices = static fn(): MapSlices => MapSlices::of(
    MapSlice::of(Path::of('.mutation-gate/coverage/shard-1/map.json.gz'), Paths::of(Path::of('src/A.php'))),
    MapSlice::of(Path::of('.mutation-gate/coverage/verdict/map.json.gz'), Paths::none()),
);
$whole = static fn(): Path => Path::of('.mutation-gate/coverage/map.json.gz');

it('vouches for a map only where the binary says so in its contract, handing it the text', function (): void {
    $vouching = OutbreakStandIn::answering('{"outbreak":1,"vouched":true}');
    $engine = Outbreak::at($vouching->binary(), '/project');

    expect($engine->isInUse())->toBeTrue()
        ->and($engine->vouches(Contents::of('{"format":1}')))->toBe(MapVouch::Vouched)
        ->and($vouching->read())->toBe('{"format":1}');

    foreach (['{"outbreak":1,"vouched":false,"why":"x"}', '{"outbreak":1}', '{"outbreak":1,"vouched":"yes"}', 'not json'] as $answer) {
        expect(Outbreak::at(OutbreakStandIn::answering($answer)->binary(), '/project')->vouches(Contents::of('{}')))
            ->toBe(MapVouch::Unvouched);
    }
})->group(...$holds);

it('vouches for nothing where the binary ends badly or answers in another contract', function (): void {
    $failing = Outbreak::at(OutbreakStandIn::answering('{"outbreak":1,"vouched":true}', exit: 1)->binary(), '/project');
    $other = Outbreak::at(OutbreakStandIn::answering('{"outbreak":2,"vouched":true}')->binary(), '/project');

    expect($failing->vouches(Contents::of('{}')))->toBe(MapVouch::Unvouched)
        ->and($other->vouches(Contents::of('{}')))->toBe(MapVouch::Unvouched);
})->group(...$holds);

it('uses no binary that speaks another contract, cannot say which, or cannot start', function (): void {
    expect(Outbreak::at(OutbreakStandIn::answering('', contract: '{"outbreak":2}')->binary(), '/p')->isInUse())->toBeFalse()
        ->and(Outbreak::at(OutbreakStandIn::answering('', contract: 'nothing')->binary(), '/p')->isInUse())->toBeFalse()
        ->and(Outbreak::at(sprintf('%s/absent', Scratch::directory()), '/p')->isInUse())->toBeFalse()
        ->and(Outbreak::none('/p')->isInUse())->toBeFalse()
        ->and(Outbreak::none('/p')->vouches(Contents::of('{}')))->toBe(MapVouch::Unvouched);
})->group(...$holds);

it('asks the binary to split the whole map into each slice, by paths from the project\'s root', function () use ($slices, $whole): void {
    $splitting = OutbreakStandIn::answering('{"outbreak":1,"written":2}');

    expect(Outbreak::at($splitting->binary(), '/project')->split($whole(), $slices()))->toEqual(Written::noting(
        '.mutation-gate/coverage/map.json.gz',
        'Outbreak wrote 2 coverage maps from .mutation-gate/coverage/map.json.gz.',
    ))->and(json_decode($splitting->read(), associative: true))->toBe([
        'whole' => '/project/.mutation-gate/coverage/map.json.gz',
        'slices' => [
            ['into' => '/project/.mutation-gate/coverage/shard-1/map.json.gz', 'files' => ['src/A.php']],
            ['into' => '/project/.mutation-gate/coverage/verdict/map.json.gz', 'files' => []],
        ],
    ]);
})->group(...$holds);

it('splits on the threads the settings give, and on every core where they give none', function () use ($slices, $whole): void {
    $splitting = OutbreakStandIn::answering('{"outbreak":1,"written":2}');
    Outbreak::at($splitting->binary(), '/project', OutbreakSettings::of(threads: 3))->split($whole(), $slices());
    $given = $splitting->arguments();
    Outbreak::at($splitting->binary(), '/project')->split($whole(), $slices());

    expect($given)->toBe('map split --threads 3')
        ->and($splitting->arguments())->toBe('map split');
})->group(...$holds);

it('says why the binary wrote no slice: it declined, failed, answered otherwise, or there is none', function () use ($slices, $whole): void {
    $split = static fn(string $answer, int $exit = 0): Written|NotWritten => Outbreak::at(
        OutbreakStandIn::answering($answer, $exit)->binary(),
        '/project',
    )->split($whole(), $slices());

    expect($split('{"outbreak":1,"declined":"expected a compact map at byte 0"}'))
        ->toEqual(NotWritten::because('Outbreak declined to split the map: expected a compact map at byte 0'))
        ->and($split('{"outbreak":1,"written":"two"}'))
        ->toEqual(NotWritten::because('Outbreak did not split the map: it answered {"outbreak":1,"written":"two"}'))
        ->and($split('{"outbreak":1}'))
        ->toEqual(NotWritten::because('Outbreak did not split the map: it answered {"outbreak":1}'))
        ->and($split('broken', exit: 1))
        ->toEqual(NotWritten::because('Outbreak did not split the map: broken'))
        ->and(Outbreak::none('/project')->split($whole(), $slices()))
        ->toEqual(NotWritten::because('No Outbreak binary is in use.'));
})->group(...$holds);

it('vouches for and splits nothing where the binary runs past its time', function () use ($slices, $whole): void {
    $root = Scratch::directory();
    $slow = sprintf('%s/slow', $root);
    file_put_contents($slow, "#!/bin/sh\nif [ \"$1\" = contract ]; then echo '{\"outbreak\":1}'; exit 0; fi\nsleep 30\n");
    chmod($slow, 0o755);
    $engine = Outbreak::at($slow, $root, OutbreakSettings::of(timeout: Seconds::of(3)));

    expect($engine->isInUse())->toBeTrue()
        ->and($engine->vouches(Contents::of('{}')))->toBe(MapVouch::Unvouched)
        ->and($engine->split($whole(), $slices()))->toBeInstanceOf(NotWritten::class);
})->group(...$holds);

it('runs the binary a config names, from the root where relative, and none where it is off', function (): void {
    $root = Scratch::directory();
    $standIn = OutbreakStandIn::answering('{"outbreak":1,"vouched":true}');
    rename($standIn->binary(), sprintf('%s/outbreak', $root));
    $shipped = Scratch::directory();

    expect(Outbreak::configured(OutbreakSettings::of(binary: 'outbreak'), $root, $shipped)->isInUse())->toBeTrue()
        ->and(Outbreak::configured(OutbreakSettings::of(binary: sprintf('%s/outbreak', $root)), '/elsewhere', $shipped)->isInUse())
        ->toBeTrue()
        ->and(Outbreak::configured(OutbreakSettings::of(enabled: false, binary: 'outbreak'), $root, $shipped)->isInUse())
        ->toBeFalse()
        ->and(Outbreak::configured(OutbreakSettings::of(), $root, $shipped)->isInUse())->toBeFalse();
})->group(...$holds);

it('reads the settings beneath a runner\'s options, and the defaults where they hold none', function (): void {
    $root = Scratch::directory();
    $standIn = OutbreakStandIn::answering('{"outbreak":1,"vouched":true}');
    rename($standIn->binary(), sprintf('%s/outbreak', $root));
    $beneath = static fn(bool $enabled): Options => Options::of(Json::object(Member::of(
        OutbreakSettings::OPTION,
        Json::object(
            Member::of(OutbreakSettings::ENABLED, $enabled),
            Member::of(OutbreakSettings::BINARY, 'outbreak'),
            Member::of(OutbreakSettings::TIMEOUT, 90.0),
            Member::of(OutbreakSettings::THREADS, 2),
        ),
    )));
    $engine = Outbreak::beneath($beneath(enabled: true), $root, Scratch::directory());
    $engine->split(Path::of('whole.json.gz'), MapSlices::of());
    $given = file_get_contents(sprintf('%s/outbreak.arguments', $root));

    expect($engine)->toEqual(Outbreak::at(
        sprintf('%s/outbreak', $root),
        $root,
        OutbreakSettings::of(binary: 'outbreak', timeout: Seconds::of(90.0), threads: 2),
    ))->and($given)->toBe('map split --threads 2')
        ->and(Outbreak::beneath($beneath(enabled: true), $root, Scratch::directory())->isInUse())->toBeTrue()
        ->and(Outbreak::beneath($beneath(enabled: false), $root, Scratch::directory())->isInUse())->toBeFalse()
        ->and(Outbreak::beneath(Options::of(Json::object()), $root, Scratch::directory())->isInUse())->toBeFalse();
})->group(...$holds);
