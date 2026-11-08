<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Outbreak\Bundled;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

// The binary the package ships for the machine, used only where its checksum
// is the one listed beside it.

$shipped = static function (string $sums): string {
    $directory = Scratch::directory();
    Scratch::write($directory, 'linux-x86_64/outbreak', 'the binary');
    Scratch::write($directory, 'windows-x86_64/outbreak.exe', 'the windows binary');
    Scratch::write($directory, Bundled::SUMS, $sums);

    return $directory;
};
$sum = static fn(string $text): string => hash('sha256', $text);

it('finds the machine\'s binary where its checksum is the one listed, and makes it runnable', function () use ($shipped, $sum): void {
    $directory = $shipped(sprintf(
        "%s  linux-x86_64/outbreak\n%s  windows-x86_64/outbreak.exe\nnot a line\n",
        $sum('the binary'),
        $sum('the windows binary'),
    ));
    chmod(sprintf('%s/linux-x86_64/outbreak', $directory), 0o644);

    expect(Bundled::for($directory, 'Linux', 'x86_64'))->toBe(sprintf('%s/linux-x86_64/outbreak', $directory))
        ->and(is_executable(sprintf('%s/linux-x86_64/outbreak', $directory)))->toBeTrue()
        ->and(Bundled::for($directory, 'Windows', 'AMD64'))->toBe(sprintf('%s/windows-x86_64/outbreak.exe', $directory));
});

it('finds none where the checksum differs, none is listed, the binary is missing or no list is there', function () use ($shipped, $sum): void {
    $differs = $shipped(sprintf("%s  linux-x86_64/outbreak\n", $sum('another binary')));
    $unlisted = $shipped(sprintf("%s  linux-aarch64/outbreak\n", $sum('the binary')));
    $missing = $shipped(sprintf("%s  darwin-aarch64/outbreak\n", $sum('the binary')));

    expect(Bundled::for($differs, 'Linux', 'x86_64'))->toEqual(NotGiven::value())
        ->and(Bundled::for($unlisted, 'Linux', 'x86_64'))->toEqual(NotGiven::value())
        ->and(Bundled::for($missing, 'Darwin', 'arm64'))->toEqual(NotGiven::value())
        ->and(Bundled::for(Scratch::directory(), 'Linux', 'x86_64'))->toEqual(NotGiven::value())
        ->and(Bundled::for($differs, 'BSD', 'x86_64'))->toEqual(NotGiven::value());
});

it('finds the binary for the machine it runs on', function (): void {
    expect(Bundled::in(Scratch::directory()))->toEqual(NotGiven::value());
});
