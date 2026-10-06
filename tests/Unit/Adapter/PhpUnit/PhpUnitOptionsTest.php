<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\PhpUnit\PhpUnitOptions;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Options;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Runner\LimitBounds;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Mutator\Engine\Engine;
use NightWorksIO\MutationGate\Mutator\Engine\MadeMutant;
use NightWorksIO\MutationGate\Mutator\Engine\MadeMutants;
use NightWorksIO\MutationGate\Tests\Support\Configs;
use NightWorksIO\MutationGate\Tests\Support\Mutators\PlusToMinus;

/** @return list<string> the mutators an engine makes the mutants of `$a + $b` with */
function mutatingWith(Engine|CannotJudge $engine): array
{
    $made = $engine instanceof Engine
        ? $engine->mutantsOf(Path::of('src/Money.php'), Contents::of("<?php\n\nreturn \$a + \$b;\n"))
        : $engine;

    return $made instanceof MadeMutants ? array_map(static fn(MadeMutant $mutant): string => $mutant->mutation()->mutator(), [...$made]) : [];
}

it('finds the tests in tests, allows each mutant timeouts.seconds\'s own default, and has no mutator, by default', function (): void {
    $options = PhpUnitOptions::read(Options::none());

    expect($options instanceof PhpUnitOptions ? [$options->tests(), $options->bounds(), $options->engine()] : [])->toEqual([
        Paths::of(Path::of('tests')),
        LimitBounds::between(Seconds::of(10.0), Seconds::of(300.0)),
        CannotJudge::because('The phpunit runner makes its mutants with the default mutator set, and no extension registers one.'),
    ]);
});

it('takes the test directories, the timeout, the most and the mutators the flows write', function (): void {
    $options = PhpUnitOptions::read(Configs::options(sprintf(
        '{"tests": ["tests/Unit", "tests/Feature"], "timeout": 30, "most": 120, "mutators": [%s]}',
        json_encode(PlusToMinus::class),
    )));

    expect($options instanceof PhpUnitOptions ? [$options->tests(), $options->bounds()] : [])
        ->toEqual([
            Paths::of(Path::of('tests/Unit'), Path::of('tests/Feature')),
            LimitBounds::between(Seconds::of(30.0), Seconds::of(120.0)),
        ])
        ->and(mutatingWith($options instanceof PhpUnitOptions ? $options->engine() : CannotJudge::because('')))->toBe(['acme/PlusToMinus']);
});

it('finds the tests in tests where the flows write none', function (): void {
    $options = PhpUnitOptions::read(Configs::options('{"tests": []}'));

    expect($options instanceof PhpUnitOptions ? $options->tests() : $options)->toEqual(Paths::of(Path::of('tests')));
});

it('cannot make a mutant with a class that is not a mutator', function (): void {
    $options = PhpUnitOptions::read(Configs::options(sprintf('{"mutators": [%s, "Nowhere\\\\Missing"]}', json_encode(PlusToMinus::class))));

    expect($options instanceof PhpUnitOptions ? $options->engine() : $options)
        ->toEqual(CannotJudge::because('The phpunit runner cannot make mutants with Nowhere\Missing, which is not a mutator.'));
});

it('is invalid where an option is not in its shape', function (string $options): void {
    expect(PhpUnitOptions::read(Configs::options($options)))->toBeInstanceOf(Invalid::class);
})->with([
    'tests' => ['{"tests": "tests"}'],
    'timeout' => ['{"timeout": "long"}'],
    'mutators' => ['{"mutators": "all"}'],
]);
