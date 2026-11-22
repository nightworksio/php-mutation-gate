<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Command;
use NightWorksIO\MutationGate\Adapter\Pest\CoveringFiles;
use NightWorksIO\MutationGate\Adapter\Pest\GateVariable;
use NightWorksIO\MutationGate\Adapter\Pest\PcovTests;
use NightWorksIO\MutationGate\Core\Runner\IniScan;
use NightWorksIO\MutationGate\Tests\Support\Environment;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    CoveringFiles::forget();
    Scratch::sweep();
});

it('finds the tests that execute a file that reads the coverage driver, in each way one reads it, and no other', function (string $reads): void {
    $directory = Scratch::directory();
    Scratch::write($directory, 'Reads.php', sprintf("<?php\n%s\n", $reads));
    Scratch::write($directory, 'Plain.php', "<?php\necho 'pcov';\n");
    $found = PcovTests::found([
        sprintf('%s/Reads.php', $directory) => [2 => ['T::reads', 'T::both']],
        sprintf('%s/Plain.php', $directory) => [2 => ['T::plain', 'T::both']],
    ]);

    expect([$found->readBy(['T::reads']), $found->readBy(['T::both']), $found->readBy(['T::plain', 'T::none']), $found->readBy([])])
        ->toBe([true, true, false, false]);
})->with([
    'starts pcov' => ['\\pcov\\start();'],
    'asks for a pcov function by name' => ["function_exists('pcov\\\\collect');"],
    'reads Xdebug\'s coverage' => ['xdebug_get_code_coverage();'],
    'picks a driver' => ['new SebastianBergmann\\CodeCoverage\\Driver\\Selector();'],
    'asks whether pcov is on' => ["ini_get( 'pcov.enabled');"],
    'asks whether pcov is loaded' => ['extension_loaded("pcov");'],
]);

it('finds the tests that execute a file, or are declared in one, that pest.pcovKept names, from the project\'s root or as their paths are, and no other', function (): void {
    $directory = Scratch::directory();
    Scratch::write($directory, 'Kept.php', "<?php\necho 'plain';\n");
    Scratch::write($directory, 'Plain.php', "<?php\necho 'plain';\n");
    Scratch::write($directory, 'KeptCase.php', "<?php\nfinal class PcovKeptCase extends PHPUnit\\Framework\\TestCase\n{\n}\n");

    if (! class_exists('PcovKeptCase', autoload: false)) {
        require sprintf('%s/KeptCase.php', $directory);
    }

    CoveringFiles::forget($directory);
    $declared = 'PcovKeptCase::declared';
    $map = [
        sprintf('%s/Kept.php', realpath($directory)) => [2 => ['T::kept']],
        sprintf('%s/Plain.php', realpath($directory)) => [2 => ['T::plain', $declared]],
    ];
    $found = static fn(string $globs): ArrayObject => Environment::during(
        [GateVariable::PcovKept->value => $globs],
        static function () use ($map, $declared, $directory): ArrayObject {
            $was = (string) getcwd();
            chdir($directory);

            try {
                return new ArrayObject(array_map(PcovTests::found($map)->readBy(...), [['T::kept'], ['T::plain'], [$declared]]));
            } finally {
                chdir($was);
            }
        },
    );

    expect($found('**/Kept.php')->getArrayCopy())->toBe([true, false, false])
        ->and($found('Kept.php')->getArrayCopy())->toBe([true, false, false])
        ->and($found("\n**/KeptCase.php\n")->getArrayCopy())->toBe([false, false, true])
        ->and($found('')->getArrayCopy())->toBe([false, false, false]);
});

it('takes a source file that names a run collecting coverage for no reader, as the gate starts such a run with pcov as configured', function (): void {
    $directory = Scratch::directory();
    Scratch::write($directory, 'Option.php', "<?php\n\$run = ['--coverage-php=x', '--path-coverage'];\n");

    expect(PcovTests::found([sprintf('%s/Option.php', $directory) => [2 => ['T::option']]])->readBy(['T::option']))->toBeFalse();
});

it('starts a process of tests that read the driver with pcov as PHP is configured, and every other with pcov off where the run turns it off', function (): void {
    $directory = Scratch::directory();
    Scratch::write($directory, 'Reads.php', "<?php\n\\pcov\\start();\n");
    $found = PcovTests::found([sprintf('%s/Reads.php', $directory) => [2 => ['T::reads']]]);
    $told = Environment::during(
        [GateVariable::PcovOff->value => '/off', IniScan::VARIABLE => '/etc/php.d', IniScan::KEPT => null, IniScan::ADDED => null],
        static fn(): ArrayObject => new ArrayObject([$found->variablesFor(['T::reads']), $found->variablesFor(['T::other'])]),
    );
    $untold = Environment::during(
        [GateVariable::PcovOff->value => null, IniScan::KEPT => null, IniScan::ADDED => null],
        static fn(): ArrayObject => new ArrayObject($found->variablesFor(['T::other'])),
    );

    expect($told->getArrayCopy())->toBe([
        [PcovTests::KEPT => '1'],
        IniScan::adding('/off', [IniScan::VARIABLE => '/etc/php.d']),
    ])->and($untold->getArrayCopy())->toBe([]);
});

it('marks a command of the gate\'s as keeping pcov where a test of it is listed beside the run\'s results as reading the driver', function (): void {
    $results = sprintf('%s/results.jsonl', Scratch::directory());
    file_put_contents(sprintf('%s.pcov-tests', $results), "T::reads\nT::too");

    expect(PcovTests::keptFor($results, ['T::other', 'T::too']))->toBe([PcovTests::KEPT => '1'])
        ->and(PcovTests::keptFor($results, ['T::other']))->toBe([])
        ->and(PcovTests::keptFor(sprintf('%s/none', Scratch::directory()), ['T::reads']))->toBe([])
        ->and(PcovTests::keeps(Command::of('php')->with([PcovTests::KEPT => '1'])))->toBeTrue()
        ->and(PcovTests::keeps(Command::of('php')))->toBeFalse();
});

it('cuts units into those whose tests read the driver and the others, and gives the readers their share of the processes, at least one and leaving one', function (): void {
    $directory = Scratch::directory();
    Scratch::write($directory, 'Reads.php', "<?php\n\\pcov\\start();\n");
    $found = PcovTests::found([sprintf('%s/Reads.php', $directory) => [2 => ['R::one']]]);
    $units = [
        'tests/R.php' => ['files' => ['tests/R.php'], 'tests' => ['R::one']],
        'tests/A.php' => ['files' => ['tests/A.php'], 'tests' => ['A::one']],
    ];
    [$reading, $others] = $found->split($units);
    $times = ['R::one' => 1.0, 'A::one' => 3.0];

    expect([array_keys($reading), array_keys($others)])->toBe([['tests/R.php'], ['tests/A.php']])
        ->and(PcovTests::processesFor($reading, $others, $times, 8))->toBe(2)
        ->and(PcovTests::processesFor($reading, $others, ['R::one' => 0.01, 'A::one' => 100.0], 8))->toBe(1)
        ->and(PcovTests::processesFor($reading, $others, ['R::one' => 100.0, 'A::one' => 0.01], 8))->toBe(7)
        ->and(PcovTests::processesFor($reading, [], $times, 8))->toBe(8)
        ->and(PcovTests::processesFor([], $others, $times, 8))->toBe(0)
        ->and(PcovTests::processesFor($reading, $others, [], 1))->toBe(1);
});
