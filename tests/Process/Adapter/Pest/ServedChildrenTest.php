<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Native\Placement;
use NightWorksIO\MutationGate\Adapter\Pest\Command;
use NightWorksIO\MutationGate\Adapter\Pest\Patching;
use NightWorksIO\MutationGate\Adapter\Pest\PestSteps;
use NightWorksIO\MutationGate\Adapter\Pest\SchemaSwitch;
use NightWorksIO\MutationGate\Adapter\Pest\ServedChildren;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\ChildProcesses;
use NightWorksIO\MutationGate\Core\Config\Duplicates;
use NightWorksIO\MutationGate\Core\Config\LongFilter;
use NightWorksIO\MutationGate\Core\Config\NarrowedKillTrust;
use NightWorksIO\MutationGate\Core\Config\Preloaded;
use NightWorksIO\MutationGate\Core\Config\Toggles;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Native\Variable;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\MemoryCap;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use Symfony\Component\Process\Process;

$holds = ['holds:src/Adapter/Pest/ServedChildren.php'];

afterEach(function (): void {
    putenv(ServedChildren::TOLD);
    Scratch::sweep();
});

/** A patched run whose `pest.childProcesses` says this. */
function servedChildrenPatching(ChildProcesses $children): Patching
{
    return Patching::on(Group::named('mutation-canary'))->stepping(PestSteps::of(
        Toggles::none(),
        NarrowedKillTrust::Vouch,
        LongFilter::Files,
        Duplicates::JudgeOnce,
        preloaded: Preloaded::Fresh,
        childProcesses: $children,
    ));
}

/** The placement of a project at this root, as the Pest runner serves it. */
function servedChildrenPlacement(string $root): Placement
{
    return Placement::inPestRunner($root, Paths::of(Path::of('tests')), Path::of('vendor'), Path::of('.mutation-gate'));
}

it('serves nothing where the project places no mutant', function (): void {
    $served = ServedChildren::beside(sprintf('%s/results.jsonl', Scratch::directory()), servedChildrenPatching(ChildProcesses::Serve), NotGiven::value(), NotGiven::value());

    expect($served instanceof ServedChildren ? $served->onto(Command::of('pest')) : $served)->toEqual(Command::of('pest'));
})->group(...$holds);

/** What a PHP script run under this command's variables prints of the original's value; PHP prepends nothing to `-r`. */
function servedChildrenPrinted(Command $command, string $original): string
{
    $script = sprintf('%s/print.php', dirname($original, 2));
    file_put_contents($script, sprintf("<?php\n\necho (require %s)();\n", var_export($original, return: true)));
    $process = new Process([PHP_BINARY, $script], null, [...getenv(), ...$command->environment()]);
    $process->run();

    return sprintf('%s%s', $process->getOutput(), $process->getErrorOutput());
}

it('serves the mutant a run told of to each PHP process it starts under serve, and the file on disk under off', function (): void {
    $root = Scratch::directory();
    $original = sprintf('%s/src/Value.php', $root);
    $copy = sprintf('%s/copies/Value.php', $root);
    Scratch::write($root, 'src/Value.php', "<?php\n\nreturn static fn(): string => 'original';\n");
    Scratch::write($root, 'copies/Value.php', "<?php\n\nreturn static fn(): string => 'mutant';\n");
    $results = sprintf('%s/.mutation-gate/pest/results.jsonl', $root);
    $printed = [];

    ServedChildren::tell($original, $copy, '', '');

    foreach ([ChildProcesses::Serve, ChildProcesses::Off] as $children) {
        $served = ServedChildren::beside($results, servedChildrenPatching($children), servedChildrenPlacement($root), NotGiven::value());
        $printed[$children->value] = $served instanceof ServedChildren
            ? servedChildrenPrinted($served->onto(Command::of('pest')), $original)
            : $served->why();

        if ($served instanceof ServedChildren) {
            $served->remove();
        }
    }

    expect($printed)->toBe(['serve' => 'mutant', 'off' => 'original'])
        ->and(is_dir(sprintf('%s/.mutation-gate/pest/children', $root)) ? scandir(sprintf('%s/.mutation-gate/pest/children', $root)) : [])
        ->toBe(['.', '..']);
})->group(...$holds);

it('serves nothing in a process no run told of a mutant, nor where the run is not patched', function (): void {
    $root = Scratch::directory();
    Scratch::write($root, 'src/Value.php', "<?php\n\nreturn static fn(): string => 'original';\n");
    $results = sprintf('%s/.mutation-gate/pest/results.jsonl', $root);
    $served = ServedChildren::beside($results, servedChildrenPatching(ChildProcesses::Serve), servedChildrenPlacement($root), NotGiven::value());
    $unpatched = ServedChildren::beside($results, Patching::off(), servedChildrenPlacement($root), NotGiven::value());
    $command = Command::of('pest');

    expect($served instanceof ServedChildren ? servedChildrenPrinted($served->onto($command), sprintf('%s/src/Value.php', $root)) : $served)
        ->toBe('original')
        ->and($unpatched instanceof ServedChildren ? $unpatched->onto($command) : $unpatched)->toEqual($command)
        ->and($served instanceof ServedChildren ? $served->onto($command->with([MemoryCap::SCAN_DIR => '/cap']))->environment()[MemoryCap::SCAN_DIR] : '')
        ->toStartWith('/cap:');
})->group(...$holds);

it('marks that each PHP process it starts ran, in the file a stream\'s test names, and nothing where it names none', function (): void {
    $root = Scratch::directory();
    Scratch::write($root, 'src/Value.php', "<?php\n\nreturn static fn(): string => 'original';\n");
    $served = ServedChildren::beside(sprintf('%s/.mutation-gate/pest/results.jsonl', $root), servedChildrenPatching(ChildProcesses::Serve), servedChildrenPlacement($root), NotGiven::value());
    $marked = sprintf('%s/started', $root);
    $command = $served instanceof ServedChildren ? $served->onto(Command::of('pest')) : Command::of('pest');

    $unmarked = servedChildrenPrinted($command, sprintf('%s/src/Value.php', $root));
    $before = file_exists($marked);
    $printed = servedChildrenPrinted($command->with([ServedChildren::SPAWNED => $marked]), sprintf('%s/src/Value.php', $root));

    expect([$unmarked, $before, $printed])->toBe(['original', false, 'original'])
        ->and($marked)->toBeFile();
})->group(...$holds);

it('tells a stream child\'s processes the mutant its variables put in place', function (): void {
    ServedChildren::tellOf(['PEST_MUTATION_TESTING' => '/src/A.php', 'PEST_MUTATION_FILE' => '/copies/A.php']);

    expect(json_decode((string) getenv(ServedChildren::TOLD), associative: true))->toBe(['/src/A.php', '/copies/A.php', '', '']);
})->group(...$holds);

it('serves the file the gate names to a fresh run to each PHP process the fresh run starts', function (): void {
    $root = Scratch::directory();
    $original = sprintf('%s/src/Value.php', $root);
    Scratch::write($root, 'src/Value.php', "<?php\n\nreturn static fn(): string => 'original';\n");
    Scratch::write($root, 'copies/Value.php', "<?php\n\nreturn static fn(): string => 'mutant';\n");
    $served = ServedChildren::beside(sprintf('%s/fresh/beside', $root), servedChildrenPatching(ChildProcesses::Serve), servedChildrenPlacement($root), NotGiven::value());
    $fresh = Command::of('pest')->with([
        Variable::Mutant->value => $original,
        Variable::Mutated->value => sprintf('%s/copies/Value.php', $root),
    ]);

    expect($served instanceof ServedChildren ? servedChildrenPrinted($served->onto($fresh), $original) : $served)->toBe('mutant');
})->group(...$holds);

it('refuses a run whose results lie where an ini file cannot name the script', function (): void {
    $results = sprintf('%s/a"quote/results.jsonl', Scratch::directory());
    $served = ServedChildren::beside($results, servedChildrenPatching(ChildProcesses::Serve), servedChildrenPlacement(Scratch::directory()), NotGiven::value());

    expect($served instanceof CannotJudge ? $served->why() : '')->toContain('PHP cannot prepend')
        ->and(is_dir(dirname($results)))->toBeFalse();
})->group(...$holds);

it('serves what a process was told of: its copy, the schema whose switch it puts in force, or else what the gate names', function (): void {
    $root = Scratch::directory();
    $schema = sprintf('%s/schemas/keyed.php', $root);
    Scratch::write($root, 'schemas/keyed.php', "<?php\n");
    $served = [];
    putenv(sprintf('%s=/named/A.php', Variable::Mutant->value));
    putenv(sprintf('%s=/named/copy.php', Variable::Mutated->value));

    $served['named'] = ServedChildren::served(Variable::Mutant->value, Variable::Mutated->value);
    ServedChildren::tell('/src/A.php', '/copies/A.php', '', '');
    $served['copy'] = ServedChildren::served(Variable::Mutant->value, Variable::Mutated->value);
    ServedChildren::tell('/src/A.php', '/copies/A.php', $schema, '3');
    $served['schema'] = ServedChildren::served(Variable::Mutant->value, Variable::Mutated->value);
    $inForce = SchemaSwitch::is('keyed', 3);
    SchemaSwitch::off('keyed');
    putenv(Variable::Mutant->value);
    putenv(Variable::Mutated->value);

    expect($served)->toBe([
        'named' => ['/named/A.php', '/named/copy.php'],
        'copy' => ['/src/A.php', '/copies/A.php'],
        'schema' => ['/src/A.php', $schema],
    ])->and($inForce)->toBeTrue();
})->group(...$holds);
