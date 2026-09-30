<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Fingerprint;
use NightWorksIO\MutationGate\Core\File\Fingerprints;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Proof\Key\CiDefinition;
use NightWorksIO\MutationGate\Core\Proof\Key\CiDefinitions;
use NightWorksIO\MutationGate\Core\Proof\Key\Exceptions;
use NightWorksIO\MutationGate\Core\Proof\Key\Ignored;
use NightWorksIO\MutationGate\Core\Proof\Key\Source;
use NightWorksIO\MutationGate\Tests\Support\Stopwatch;

$fingerprint = static fn(string $path): Fingerprint => Fingerprint::of(Path::of($path), Digest::of('9c1e'));

it('reads every file outside the tests but the exceptions and the CI definitions it reads as they run', function () use ($fingerprint): void {
    $ci = CiDefinitions::of(
        CiDefinition::at(Path::of('.gitlab/mutation-gate.yml'), Contents::of('image: php')),
        CiDefinition::at(Path::of('ci/template.yml'), Contents::of('stages: [test]')),
    );
    $source = Source::of(
        Fingerprints::of(
            $fingerprint('src/Money.php'),
            $fingerprint('mutation-gate.json'),
            $fingerprint('.gitlab/mutation-gate.yml'),
            $fingerprint('ci/template.yml'),
            $fingerprint('composer.json'),
        ),
        $ci,
        Exceptions::of(Path::of('mutation-gate.json'), Path::of('mutation-gate-baseline.json'), Ignored::nothing(), Paths::none()),
    );

    expect($source->files())->toEqual(Fingerprints::of($fingerprint('src/Money.php'), $fingerprint('composer.json')))
        ->and($source->ci())->toBe($ci);
});

it('reads tens of thousands of files in linear time', function (): void {
    $each = array_map(static fn(int $at): Fingerprint => Fingerprint::of(Path::of(sprintf('src/F%d.php', $at)), Digest::of(sprintf('%d', $at))), range(1, 20_000));
    $source = Source::of(Fingerprints::none(), CiDefinitions::none(), Exceptions::of(Path::of('gate.json'), Path::of('baseline.json'), Ignored::nothing(), Paths::none()));

    $seconds = Stopwatch::seconds(static function () use ($each, &$source): void {
        $source = Source::of(Fingerprints::of(...$each, ...$each), CiDefinitions::none(), Exceptions::of(Path::of('gate.json'), Path::of('baseline.json'), Ignored::nothing(), Paths::none()));
    });

    expect($source->files())->toHaveCount(20_000)
        ->and($source->files()->digestOf(Path::of('src/F20000.php')))->toEqual(Digest::of('20000'))
        ->and($seconds)->toBeLessThan(Stopwatch::BOUND);
});

it('reads the files that define the runner, though proofs.ignore matches them', function () use ($fingerprint): void {
    $source = Source::of(
        Fingerprints::of($fingerprint('infection.json5'), $fingerprint('phpunit.xml'), $fingerprint('docs/index.md')),
        CiDefinitions::none(),
        Exceptions::of(
            Path::of('mutation-gate.json'),
            Path::of('mutation-gate-baseline.json'),
            Ignored::globs('*.json5', 'phpunit.xml', 'docs/**'),
            Paths::of(Path::of('infection.json5'), Path::of('phpunit.xml')),
        ),
    );

    expect($source->files())->toEqual(Fingerprints::of($fingerprint('infection.json5'), $fingerprint('phpunit.xml')));
});
