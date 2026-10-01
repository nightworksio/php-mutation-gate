<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Runner\MemoryCap;
use NightWorksIO\MutationGate\Core\Runner\MemoryUnit;
use NightWorksIO\MutationGate\Core\Runner\Uncapped;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use Symfony\Component\Process\Process;

afterEach(function (): void {
    Scratch::sweep();
});

it('caps each mutant\'s process at 1G where the config names no cap', function (): void {
    expect(MemoryCap::standard()->written())->toBe('1G')
        ->and(MemoryCap::standard()->caps())->toBeTrue();
});

it('reads a cap as PHP\'s memory_limit writes one, in any case', function (string $written, string $as): void {
    $cap = MemoryCap::parse($written);

    expect($cap instanceof MemoryCap ? $cap->written() : $cap)->toBe($as);
})->with([
    'bytes' => ['1048577', '1048577'],
    'kilobytes' => ['512k', '512K'],
    'megabytes' => ['512M', '512M'],
    'gigabytes' => ['2g', '2G'],
    'no cap' => ['-1', '-1'],
]);

it('caps nothing at -1', function (): void {
    $none = MemoryCap::parse('-1');

    expect($none)->toEqual(MemoryCap::none())
        ->and(MemoryCap::none()->caps())->toBeFalse();
});

it('cannot read what is no amount of memory', function (string $written): void {
    expect(MemoryCap::parse($written))->toEqual(CannotJudge::because(sprintf(
        '"%s" is not an amount of memory. Write it as PHP\'s memory_limit does, such as 512M or 1G, or -1 for none.',
        $written,
    )));
})->with(['', '0', '1.5G', '1T', 'G', '-2', '01G', ' 1G']);

it('knows a memory_limit a project sets that lets a process past the cap', function (string $cap, string $limit, bool $exceeds): void {
    $capped = MemoryCap::parse($cap);
    $set = MemoryCap::parse($limit);

    expect($capped instanceof MemoryCap && $set instanceof MemoryCap && $capped->isExceededBy($set))->toBe($exceeds);
})->with([
    'no limit' => ['1G', '-1', true],
    'a larger one' => ['1G', '1025M', true],
    'the same' => ['1G', '1024M', false],
    'a smaller one' => ['1G', '512M', false],
    'bytes above' => ['1K', '1025', true],
    'bytes at' => ['1K', '1024', false],
    'with no cap' => ['-1', '-1', false],
]);

it('writes the ini file that sets it', function (): void {
    expect(MemoryCap::standard()->ini())->toBe("memory_limit=1G\n");
});

it('scans a directory of its own after the ini directories PHP would scan', function (): void {
    expect(MemoryCap::scanning(inherited: false, directory: '/w/php'))->toBe(sprintf('%s/w/php', PATH_SEPARATOR))
        ->and(MemoryCap::scanning('/etc/php.d', '/w/php'))->toBe(sprintf('/etc/php.d%s/w/php', PATH_SEPARATOR))
        ->and(MemoryCap::scanning('', '/w/php'))->toBe('/w/php');
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
});

it('is a whole number of a unit, as the builder writes it, or no cap', function (): void {
    $cap = MemoryCap::of(512, MemoryUnit::Megabytes);

    expect([$cap->written(), $cap->number(), $cap->unit()])->toBe(['512M', 512, MemoryUnit::Megabytes])
        ->and(MemoryCap::none()->unit())->toBe(Uncapped::Memory)
        ->and(MemoryCap::parse('512M'))->toEqual($cap);
});

it('holds a number of bytes in the fewest whole megabytes, one at the least', function (): void {
    expect(MemoryCap::atLeast(512 * 1024 * 1024)->written())->toBe('512M')
        ->and(MemoryCap::atLeast(512 * 1024 * 1024 + 1)->written())->toBe('513M')
        ->and(MemoryCap::atLeast(0)->written())->toBe('1M');
});

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
});

it('keeps one cap in one form, the largest unit it is a whole number of, so it is keyed one way', function (): void {
    expect(MemoryCap::parse('1024M'))->toEqual(MemoryCap::standard())
        ->and(MemoryCap::parse('1073741824'))->toEqual(MemoryCap::standard())
        ->and(MemoryCap::parse('1048576k'))->toEqual(MemoryCap::standard())
        ->and(MemoryCap::of(1536, MemoryUnit::Megabytes)->written())->toBe('1536M')
        ->and(MemoryCap::of(2048, MemoryUnit::Kilobytes)->written())->toBe('2M')
        ->and(MemoryCap::of(1000, MemoryUnit::Bytes)->written())->toBe('1000');
});

it('refuses an amount more than PHP can count, and one that ends in a newline', function (): void {
    expect(MemoryCap::parse('9999999999G'))
        ->toEqual(CannotJudge::because('"9999999999G" is more memory than PHP can count. Write -1 for no cap.'))
        ->and(MemoryCap::parse('99999999999999999999'))->toBeInstanceOf(CannotJudge::class)
        ->and(MemoryCap::parse("512M\n"))->toBeInstanceOf(CannotJudge::class)
        ->and(MemoryCap::parse('8589934591G'))->toEqual(MemoryCap::of(8589934591, MemoryUnit::Gigabytes));
});
