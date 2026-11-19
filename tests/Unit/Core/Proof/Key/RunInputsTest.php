<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Fingerprint;
use NightWorksIO\MutationGate\Core\File\Fingerprints;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Proof\Key\RunInputs;

/**
 * The digest of a run whose inputs are the standard ones, but for those given.
 *
 * @param array<string, string> $files       each file's content, by path
 * @param array<string, string> $image
 * @param array<string, string> $environment
 */
function runInputs(
    string $reads = 'reads',
    array $files = ['src/Money.php' => '<?php class Money {}', 'tests/MoneyTest.php' => '<?php test();', 'composer.lock' => '{}'],
    string $release = '1.4.0',
    array $image = ['ImageOS' => 'ubuntu24', 'ImageVersion' => '20261117.1'],
    array $environment = ['TEST_SERVER_MYSQL' => '127.0.0.1:33061'],
): string {
    $fingerprints = Fingerprints::none();

    foreach ($files as $path => $content) {
        $fingerprints = $fingerprints->with(Fingerprint::of(Path::of($path), Digest::sha256Of($content)));
    }

    return RunInputs::of(Digest::sha256Of($reads), $fingerprints, $release, $image, $environment)->value();
}

it('is the same for the same inputs, in whatever order they are named', function (): void {
    expect(runInputs())->toBe(runInputs())
        ->and(runInputs(
            files: ['composer.lock' => '{}', 'tests/MoneyTest.php' => '<?php test();', 'src/Money.php' => '<?php class Money {}'],
            image: ['ImageVersion' => '20261117.1', 'ImageOS' => 'ubuntu24'],
        ))->toBe(runInputs());
});

it('changes with any one input', function (Closure $changed): void {
    expect($changed())->not->toBe(runInputs());
})->with([
    'what every key reads: the gate, the config, the runner and its PHP, the analyser, what is installed' => [fn(): string => runInputs(reads: 'other reads')],
    'a source file' => [fn(): string => runInputs(files: ['src/Money.php' => '<?php class Money { }', 'tests/MoneyTest.php' => '<?php test();', 'composer.lock' => '{}'])],
    'a test file' => [fn(): string => runInputs(files: ['src/Money.php' => '<?php class Money {}', 'tests/MoneyTest.php' => '<?php test(); ', 'composer.lock' => '{}'])],
    'the lock' => [fn(): string => runInputs(files: ['src/Money.php' => '<?php class Money {}', 'tests/MoneyTest.php' => '<?php test();', 'composer.lock' => '{ }'])],
    'a file added' => [fn(): string => runInputs(files: ['src/Money.php' => '<?php class Money {}', 'tests/MoneyTest.php' => '<?php test();', 'composer.lock' => '{}', 'tests/fixtures/a.json' => '{}'])],
    'a file moved' => [fn(): string => runInputs(files: ['src/Cash.php' => '<?php class Money {}', 'tests/MoneyTest.php' => '<?php test();', 'composer.lock' => '{}'])],
    'the gate\'s release' => [fn(): string => runInputs(release: '1.4.1')],
    'the runner\'s image' => [fn(): string => runInputs(image: ['ImageOS' => 'ubuntu24', 'ImageVersion' => '20261118.1'])],
    'a variable read' => [fn(): string => runInputs(environment: ['TEST_SERVER_MYSQL' => '127.0.0.1:33062'])],
    'a variable read named' => [fn(): string => runInputs(environment: ['TEST_SERVER_MYSQL' => '127.0.0.1:33061', 'TZ' => 'UTC'])],
    'a value moved between two names' => [fn(): string => runInputs(image: ['ImageOS' => '20261117.1', 'ImageVersion' => 'ubuntu24'])],
]);
