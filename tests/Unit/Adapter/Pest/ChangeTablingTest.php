<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Bridges;
use NightWorksIO\MutationGate\Adapter\Pest\ChangeTable;
use NightWorksIO\MutationGate\Adapter\Pest\ChangeTableMade;
use NightWorksIO\MutationGate\Adapter\Pest\ChangeTabling;
use NightWorksIO\MutationGate\Adapter\Pest\Command;
use NightWorksIO\MutationGate\Adapter\Pest\GateVariable;
use NightWorksIO\MutationGate\Adapter\Pest\Patching;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Cost\MutantSites;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Mutant\Mutators;
use NightWorksIO\MutationGate\Core\Runner\Leftover;
use NightWorksIO\MutationGate\Core\Runner\Ran;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Mutator\Engine\Enabled;
use NightWorksIO\MutationGate\Mutator\MutatorSet;
use NightWorksIO\MutationGate\Tests\Support\GateEnvironment;
use NightWorksIO\MutationGate\Tests\Support\PestCases;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\ShellFake;
use NightWorksIO\MutationGateSecurity\Mutators\HashEqualsToTrue;
use Pest\Mutate\Cache\NullStore;
use Pest\Mutate\Mutation;
use Pest\Mutate\Mutators\Sets\DefaultSet;
use Pest\Mutate\Support\MutationGenerator;
use Pest\Support\Container;
use Psr\SimpleCache\CacheInterface;
use Symfony\Component\Finder\SplFileInfo;

beforeEach(function (): void {
    GateEnvironment::clear();
    Container::getInstance()->add(CacheInterface::class, new NullStore());
});

afterEach(function (): void {
    GateEnvironment::restore();
    Scratch::sweep();
});

/** A shell whose one run is the plan's tabling run, made in this process as the patched plugin makes it. */
function tablingShell(): ShellFake
{
    return new ShellFake(static function (Command $command): Ran {
        foreach ([GateVariable::Tabling, GateVariable::Places] as $variable) {
            putenv(sprintf('%s=%s', $variable->value, $command->environment()[$variable->value]));
        }

        $generator = new MutationGenerator();
        ChangeTableMade::tabled(static fn(SplFileInfo $file, array $lines): array => $generator->generate(
            $file,
            DefaultSet::mutators(),
            $lines,
        ));
        putenv(GateVariable::Tabling->value);
        putenv(GateVariable::Places->value);

        return Ran::finished(succeeded: true, output: '');
    });
}

it('makes every mutant of the files on disk once, in one patched run on the canary group that judges none, and says where each starts', function (): void {
    $at = PestCases::project();
    Scratch::write($at->root(), 'src/Rates.php', "<?php\n\nfinal class Rates\n{\n    public static function of(int \$a): int\n    {\n        return \$a + 1 + \$a + 1;\n    }\n}\n");
    $shell = tablingShell();
    $made = new ChangeTabling($at, $shell, PestCases::canary(), new Bridges())->made(
        Paths::of(Path::of('src/Gone.php'), Path::of('src/Rates.php'), Path::of('src/Money.php')),
        Mutators::all(),
        Withheld::nothing(),
    );
    $whole = new MutationGenerator()->generate(
        new SplFileInfo(sprintf('%s/src/Rates.php', $at->root()), 'src', 'src/Rates.php'),
        DefaultSet::mutators(),
    );
    $commands = $shell->commands();
    $arguments = $commands === [] ? [] : $commands[0]->arguments();

    expect($made instanceof MutantSites ? [$made->count(), $made->has(Path::of('src/Money.php')), $made->has(Path::of('src/Gone.php'))] : $made->why())
        ->toBe([count($whole), true, false])
        ->and($made instanceof MutantSites
            ? array_map(static fn(Line $line): int => $line->number(), [...$made->linesOf(Path::of('src/Rates.php'))])
            : [])
        ->toBe(array_values(array_unique(array_map(static fn(Mutation $mutant): int => $mutant->startLine, $whole))))
        ->and(count($commands))->toBe(1)
        ->and(array_values(array_intersect(['--mutate', '--everything', '--group', 'mutation-canary', '--path=src/Rates.php'], $arguments)))
        ->toBe(['--mutate', '--everything', '--group', 'mutation-canary', '--path=src/Rates.php'])
        ->and(is_file(sprintf('%s/.mutation-gate/%s', $at->root(), ChangeTable::FILE)))->toBeTrue()
        ->and(is_file(sprintf('%s/.mutation-gate/change-places.files', $at->root())))->toBeFalse();
});

it('makes no table where Pest is not patched or no file is on disk, and says why where the run left none', function (): void {
    $at = PestCases::project();
    $shell = tablingShell();
    $tabling = static fn(Patching $patching, ShellFake $shell, string ...$files): MutantSites|CannotJudge => new ChangeTabling(
        $at,
        $shell,
        $patching,
        new Bridges(),
    )->made(Paths::of(...array_map(Path::of(...), $files)), Mutators::all(), Withheld::nothing());
    $silent = $tabling(PestCases::canary(), ShellFake::answering(Ran::finished(succeeded: false, output: 'Pest stopped')), 'src/Money.php');

    expect($tabling(Patching::off(), $shell, 'src/Money.php'))->toEqual(MutantSites::none())
        ->and($tabling(PestCases::canary(), $shell, 'src/Gone.php'))->toEqual(MutantSites::none())
        ->and($shell->commands())->toBe([])
        ->and($silent instanceof CannotJudge ? $silent->why() : $silent)
        ->toBe('Pest left no table of the change places it was to make, and printed: Pest stopped');
});

it('makes no table and says why where the list of files it is to table cannot be written', function (): void {
    $at = PestCases::project();
    $listing = sprintf('%s/.mutation-gate/change-places.files', $at->root());
    mkdir($listing, recursive: true);
    $shell = tablingShell();
    $made = new ChangeTabling($at, $shell, PestCases::canary(), new Bridges())
        ->made(Paths::of(Path::of('src/Money.php')), Mutators::all(), Withheld::nothing());

    expect($made)->toEqual(Leftover::at($listing))
        ->and($shell->commands())->toBe([]);
});

it('loads the bridges to the registered mutators the config turns on, as a mutation run does, and says why where it cannot', function (): void {
    $at = PestCases::project();
    $shell = tablingShell();
    $bridged = Bridges::to(Enabled::of(MutatorSet::of(HashEqualsToTrue::class)));
    $why = CannotJudge::because('The pest runner cannot make mutants with stdClass, which is not a mutator.');
    $tabling = static fn(Bridges $bridges): MutantSites|CannotJudge => new ChangeTabling($at, $shell, PestCases::canary(), $bridges)
        ->made(Paths::of(Path::of('src/Money.php')), Mutators::all(), Withheld::nothing());
    $refused = $tabling(Bridges::refusing($why));
    $commands = $shell->commands();
    $made = $tabling($bridged);
    $environment = $shell->commands() === [] ? [] : $shell->commands()[0]->environment();

    expect($refused)->toBe($why)
        ->and($commands)->toBe([])
        ->and(is_file(sprintf('%s/.mutation-gate/change-places.files', $at->root())))->toBeFalse()
        ->and($made)->toBeInstanceOf(MutantSites::class)
        ->and($environment[GateVariable::Mutators->value] ?? '')
        ->toBe(sprintf('%s/.mutation-gate/mutators/pest/bridges.php', $at->root()));
});
