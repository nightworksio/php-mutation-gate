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
    'bytes' => ['1048576', '1048576'],
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
        ->and(MemoryCap::scanning('/etc/php.d', '/w/php'))->toBe(sprintf('/etc/php.d%s/w/php', PATH_SEPARATOR));
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

it('leaves room for what a suite needed where it holds twice that, as no cap always does', function (): void {
    $cap = MemoryCap::standard();

    expect($cap->leavesRoomFor(MemoryCap::of(512, MemoryUnit::Megabytes)))->toBeTrue()
        ->and($cap->leavesRoomFor(MemoryCap::of(513, MemoryUnit::Megabytes)))->toBeFalse()
        ->and(MemoryCap::none()->leavesRoomFor(MemoryCap::of(8, MemoryUnit::Gigabytes)))->toBeTrue()
        ->and(MemoryCap::of(600, MemoryUnit::Megabytes)->withRoom()->written())->toBe('1200M');
});
