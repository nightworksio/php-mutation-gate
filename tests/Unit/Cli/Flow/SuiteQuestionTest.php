<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Cli\Flow\Suite;
use NightWorksIO\MutationGate\Cli\Flow\SuiteQuestion;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Fingerprint;
use NightWorksIO\MutationGate\Core\File\Fingerprints;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\ReadSuite;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

/** @return list<string> */
function questionPaths(Paths $paths): array
{
    return array_map(static fn(Path $path): string => $path->value(), [...$paths]);
}

it('asks about every PHP source, support and loaded file as a source, each test case file as a test, and each loaded one as loaded by every test', function (): void {
    $project = Flows::project();
    Scratch::write($project, 'tests/Pest.php', "<?php\n\nuses()->group('unit');\n");
    Scratch::write($project, 'tests/Support/Helper.php', "<?php\n\nfunction helper(): int\n{\n    return 1;\n}\n");
    Scratch::write($project, 'tests/fixtures/rates.json', "{}\n");
    $files = array_map(
        static fn(string $file): Fingerprint => Fingerprint::of(Path::of($file), Digest::sha256Of($file)),
        ['src/Money.php', 'composer.json', 'tests/MoneyTest.php', 'tests/Pest.php', 'tests/Support/Helper.php', 'tests/fixtures/rates.json'],
    );
    $suite = ReadSuite::of(Flows::trees(), Fingerprints::of(...$files), Directory::at($project));

    expect($suite)->toBeInstanceOf(Suite::class);

    if (! $suite instanceof Suite) {
        return;
    }

    $question = SuiteQuestion::over($suite);

    expect([questionPaths($question->sources()), questionPaths($question->tests()), questionPaths($question->always())])->toBe([
        ['src/Money.php', 'tests/Pest.php', 'tests/Support/Helper.php'],
        ['tests/MoneyTest.php'],
        ['tests/Pest.php'],
    ]);
});
