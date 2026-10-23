<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\PhpUnit\Extension;
use NightWorksIO\MutationGate\Core\Native\Outcome;
use NightWorksIO\MutationGate\Core\Native\Variable;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\Tree;
use Symfony\Component\Process\Process;

$holds = [
    'holds:src/Adapter/PhpUnit/Extension.php',
];

afterEach(function (): void {
    Scratch::sweep();
});

/**
 * What a PHPUnit started with the extension, with opcache on or off on its
 * command line, recorded of its one passing test and said in the guard file.
 *
 * @return array{string, string}
 */
function extensionRecorded(string $opcache): array
{
    $directory = Scratch::directory();
    Scratch::write(
        $directory,
        'ProbeTest.php',
        "<?php\n\nfinal class ProbeTest extends PHPUnit\\Framework\\TestCase\n{\n    public function testPasses(): void\n    {\n        self::assertTrue(true);\n    }\n}\n",
    );
    $results = sprintf('%s/results.txt', $directory);
    $guard = sprintf('%s/guard.txt', $directory);
    $phpunit = new Process(
        [
            PHP_BINARY,
            '-d',
            sprintf('opcache.enable_cli=%s', $opcache),
            Tree::at('vendor/bin/phpunit'),
            '--no-configuration',
            '--bootstrap',
            Tree::at('vendor/autoload.php'),
            '--extension',
            Extension::class,
            sprintf('%s/ProbeTest.php', $directory),
        ],
        env: [Variable::Results->value => $results, Variable::Guard->value => $guard],
    );
    $phpunit->run();

    return [
        is_file($results) ? (string) file_get_contents($results) : '',
        is_file($guard) ? (string) file_get_contents($guard) : '',
    ];
}

it('records each test of the PHPUnit it is started in, to the results file its variable names', function (): void {
    expect(extensionRecorded('0')[0])->toBe(sprintf(
        '%s%s',
        Outcome::Started->line('ProbeTest::testPasses'),
        Outcome::Passed->line('ProbeTest::testPasses'),
    ));
})->group(...$holds);

it('says in the guard file that opcache could serve an original where opcache is on, and nothing where it is off', function (): void {
    expect([extensionRecorded('1')[1], extensionRecorded('0')[1]])->toBe(["cached\n", '']);
})->group(...$holds);
