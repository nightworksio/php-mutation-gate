<?php

declare(strict_types=1);
use NightWorksIO\MutationGate\Core\Runner\MemoryCap;
use NightWorksIO\MutationGate\Core\Runner\MemoryUnit;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use Symfony\Component\Process\Process;

$holds = [
    'holds:src/Core/Runner/MemoryCap.php',
];

afterEach(function (): void {
    Scratch::sweep();
});

it('caps a PHP process that reads it at the cap', function (): void {
    $directory = Scratch::directory();
    $cap = MemoryCap::parse('256M');
    file_put_contents(sprintf('%s/memory-cap.ini', $directory), $cap instanceof MemoryCap ? $cap->ini() : '');
    $php = new Process(
        [PHP_BINARY, '-r', 'echo ini_get("memory_limit");'],
        env: [MemoryCap::SCAN_DIR => MemoryCap::scanning(getenv(MemoryCap::SCAN_DIR), $directory)],
    );
    $php->run();

    expect($php->getOutput())->toBe('256M');
})->group(...$holds);

it('keeps every ini file and extension PHP loads from its own scan directory, or the one the gate inherited', function (): void {
    $capDirectory = Scratch::directory();
    $capFile = sprintf('%s/%s', $capDirectory, MemoryCap::FILE);
    file_put_contents($capFile, MemoryCap::of(256, MemoryUnit::Megabytes)->ini());
    $inherited = Scratch::directory();
    file_put_contents(sprintf('%s/project.ini', $inherited), "precision=7\n");

    /**
     * The ini files a PHP process with this scan directory scanned, its extensions, and two of its settings.
     *
     * @return list<string>
     */
    $described = static function (string|false $scanDirectory): array {
        $php = new Process([PHP_BINARY, '-r', <<<'PHP'
            echo implode("\n", [
                str_replace([",\n", "\n"], ',', trim((string) php_ini_scanned_files())),
                implode(',', get_loaded_extensions()),
                ini_get('precision'),
                ini_get('memory_limit'),
            ]);
            PHP], env: [MemoryCap::SCAN_DIR => $scanDirectory]);
        $php->mustRun();

        return explode("\n", $php->getOutput());
    };
    $own = $described(scanDirectory: false);
    $capped = $described(MemoryCap::scanning(inherited: false, directory: $capDirectory));
    $chosen = $described($inherited);
    $project = $described(MemoryCap::scanning($inherited, $capDirectory));
    $nothing = $described('');
    $capOnly = $described(MemoryCap::scanning('', $capDirectory));

    expect($capped[1])->toBe($own[1])
        ->and($capped[0])->toBe(ltrim(sprintf('%s,%s', $own[0], $capFile), ','))
        ->and($capped[3])->toBe('256M')
        ->and($project)->toBe([sprintf('%s,%s', $chosen[0], $capFile), $chosen[1], '7', '256M'])
        ->and($capOnly)->toBe([ltrim(sprintf('%s,%s', $nothing[0], $capFile), ','), $nothing[1], $nothing[2], '256M']);
})->group(...$holds);
