<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Turbo\Sidecar;
use NightWorksIO\MutationGate\Adapter\Turbo\Unavailable;
use NightWorksIO\MutationGate\Core\Coverage\EntryKeys;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Proof\Key\ContentKeys;
use NightWorksIO\MutationGate\Core\Proof\Key\EntryKeying;
use NightWorksIO\MutationGate\Core\Proof\Keys;
use NightWorksIO\MutationGate\Core\Runner\Environment;
use NightWorksIO\MutationGate\Core\Turbo\EntryKeysAsked;
use NightWorksIO\MutationGate\Core\Turbo\NotAccelerated;
use NightWorksIO\MutationGate\Core\Turbo\UnitKeysAsked;
use NightWorksIO\MutationGate\Port\Accelerator;
use NightWorksIO\MutationGate\Tests\Fakes\AcceleratorFake;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\TurboFixtures;
use NightWorksIO\MutationGate\Tests\Support\TurboHelper;

// What every accelerator answers: each test file's entry key exactly as
// EntryKeying, the definition, computes it, or nothing at all. An answer that
// differs in one key is a broken accelerator. The helper's rows are the
// parity test between the Rust helper and the gate's PHP (ADR-0029).

afterEach(function (): void {
    Scratch::sweep();
});

// Each row: how to build the accelerator, whether it answers every request
// this suite makes, and whether it is absent from this checkout.
$accelerators = [
    'the fake' => [static fn(): Accelerator => new AcceleratorFake(), true, false],
    'the helper' => [
        static fn(): Accelerator => Sidecar::of(
            TurboHelper::processes(),
            Scratch::directory(),
            [TurboHelper::binary()],
            Environment::none(),
        ),
        true,
        ! TurboHelper::isBuilt(),
    ],
    'no helper' => [static fn(): Accelerator => Unavailable::because(NotAccelerated::because('turned off')), false, false],
];

$fixtures = [
    'a test, its subject, a cycle of names and what every entry reads' => static fn(): array => TurboFixtures::project(
        ['src/Money.php' => ['App\\Money'], 'src/Currency.php' => ['App\\Currency'], 'tests/Support/Helper.php' => ['Tests\\Helper']],
        [
            'tests/Unit/MoneyTest.php' => ['App\\Money', 'Tests\\Helper'],
            'src/Money.php' => ['App\\Currency'],
            'src/Currency.php' => ['App\\Money'],
            'tests/Support/Helper.php' => [],
        ],
        [
            'tests/Unit/MoneyTest.php' => 'aa',
            'src/Money.php' => 'bb',
            'src/Currency.php' => 'cc',
            'tests/Support/Helper.php' => 'dd',
            'tests/Pest.php' => 'ee',
        ],
        ['tests/Pest.php'],
        ['tests/Unit/MoneyTest.php' => ['src/Money.php'], 'tests/Unit/OtherTest.php' => []],
    ),
    'paths in other scripts, one that reads as a number, and files with no digest' => static fn(): array => TurboFixtures::project(
        ['src/Größe.php' => ['App\\Größe'], 'src/日本.php' => ['App\\日本']],
        ['tests/GrößeTest.php' => ['App\\Größe', 'App\\日本'], 'src/Größe.php' => [], 'src/日本.php' => []],
        ['tests/GrößeTest.php' => 'a1', 'src/Größe.php' => 'b2'],
        ['10'],
        ['tests/GrößeTest.php' => ['src/Größe.php', 'src/gone.php']],
    ),
    'three hundred files naming each other' => TurboFixtures::wide(...),
    'no test files' => static fn(): array => TurboFixtures::project([], [], [], [], []),
];

foreach ($accelerators as $name => [$accelerator, $answers, $absent]) {
    foreach ($fixtures as $fixture => $made) {
        it(sprintf('answers every entry key the definition computes, or nothing, %s, as %s does', $fixture, $name), function () use (
            $accelerator,
            $answers,
            $made,
        ): void {
            [$names, $files, $always, $entries] = $made();
            $base = Digest::sha256Of('the base every entry key is built on');
            $asked = EntryKeysAsked::of($base, $names->edges(), $files, $always, $entries);
            $definition = EntryKeying::of($base, $names, $files, $always);
            $request = $asked->request();
            $answered = $request instanceof NotAccelerated ? $request : $asked->keysIn($accelerator()->answer($request));

            $expected = EntryKeys::none();

            foreach ($entries as [$test, $executed]) {
                $expected = $expected->with($test, $definition->keyOf($test, $executed));
            }

            expect($answered)->toEqual($answers ? $expected : $answered)
                ->and($answers || $answered instanceof NotAccelerated)->toBeTrue();
        })->skip($absent, TurboHelper::NOT_BUILT);
    }

    it(sprintf('answers the definition\'s keys or nothing where PHP\'s order of the paths mixes numbers with words, as %s does', $name), function () use (
        $accelerator,
    ): void {
        [$names, $files, $always, $entries] = TurboFixtures::project([], ['tests/9' => []], ['10' => 'a', '9' => 'b', 'tests/9' => 'c'], ['10', '9'], ['tests/9' => []]);
        $base = Digest::sha256Of('base');
        $asked = EntryKeysAsked::of($base, $names->edges(), $files, $always, $entries);
        $request = $asked->request();
        $answered = $request instanceof NotAccelerated ? $request : $asked->keysIn($accelerator()->answer($request));
        $key = EntryKeying::of($base, $names, $files, $always)->keyOf(Path::of('tests/9'), Paths::of());

        expect($answered instanceof NotAccelerated ? $answered : $answered->keyOf(Path::of('tests/9')))
            ->toEqual($answered instanceof NotAccelerated ? $answered : $key);
    })->skip($absent, TurboHelper::NOT_BUILT);
}

foreach ($accelerators as $name => [$accelerator, $answers, $absent]) {
    it(sprintf('answers each unit key as the fifth format frames it, or nothing, as %s does', $name), function () use (
        $accelerator,
        $answers,
    ): void {
        $asked = UnitKeysAsked::of(
            Digest::of('b4'),
            [
                [Path::of('src/A.php'), '', Digest::of('r1'), [[3, 'wide'], [7, 'narrow'], [9, 'wide']]],
                [Path::of('src/B.php'), 'group', Digest::of('r2'), []],
            ],
            ['wide' => ['b::t', 'a::t', 'é::t', 'B::t', '10', '9'], 'narrow' => ['a::t']],
        );
        $request = $asked->request();
        $answered = $request instanceof NotAccelerated ? $request : $asked->keysIn($accelerator()->answer($request));
        $wide = hash('sha256', "1:6\n2:10\n1:9\n4:B::t\n4:a::t\n4:b::t\n5:é::t\n");
        $narrow = hash('sha256', "1:1\n4:a::t\n");
        $format = sprintf('%d:%s', strlen(ContentKeys::FORMAT), ContentKeys::FORMAT);
        $expected = Keys::none()
            ->with(Path::of('src/A.php'), Digest::of(hash('sha256', sprintf(
                "%s\n2:b4\n2:r1\n4:unit\n9:src/A.php\n0:\n1:3\n1:3\n64:%s\n1:7\n64:%s\n1:9\n64:%s\n",
                $format,
                $wide,
                $narrow,
                $wide,
            ))))
            ->with(Path::of('src/B.php'), Digest::of(hash('sha256', sprintf("%s\n2:b4\n2:r2\n4:unit\n9:src/B.php\n5:group\n1:0\n", $format))));

        expect($answered)->toEqual($answers ? $expected : $answered)
            ->and($answers || $answered instanceof NotAccelerated)->toBeTrue();
    })->skip($absent, TurboHelper::NOT_BUILT);
}
