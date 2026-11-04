<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Filesystem\PackageDirectory;
use NightWorksIO\MutationGate\Core\Composer\Hunk;
use NightWorksIO\MutationGate\Core\Composer\PackageSource;
use NightWorksIO\MutationGate\Core\Composer\VendorPatch;
use NightWorksIO\MutationGate\Tests\Support\RefusingPackageFiles;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

/** A vendor directory whose acme/lib ships two files, each with a line the patch rewrites. */
$vendor = static function (): string {
    $vendor = Scratch::directory();
    Scratch::write($vendor, 'acme/lib/src/One.php', "<?php\nreturn 1;\n");
    Scratch::write($vendor, 'acme/lib/src/Two.php', "<?php\nreturn 2;\n");

    return $vendor;
};

$patch = static fn(): VendorPatch => VendorPatch::of(
    'pest:patch',
    'acme/lib',
    Hunk::in('One.php', 'return 1;', 'return 10;'),
    Hunk::in('Two.php', 'return 2;', 'return 20;'),
);

it('writes every file it changes, and says how many', function () use ($vendor, $patch): void {
    $at = $vendor();

    expect(PackageSource::of(new PackageDirectory())->applyIn($at, $patch()))->toBe($patch()->done(2))
        ->and(file_get_contents(sprintf('%s/acme/lib/src/Two.php', $at)))->toContain('return 20;');
});

it('says the files could not be written where one write fails, though the others are written', function () use ($vendor, $patch): void {
    $at = $vendor();
    $written = [];
    $refuses = static function (string $file) use (&$written): bool {
        $written[] = basename($file);

        return count($written) === 1;
    };

    expect(PackageSource::of(new RefusingPackageFiles($refuses))->applyIn($at, $patch()))->toEqual($patch()->unwritten($at))
        ->and($written)->toBe(['One.php', 'Two.php']);
});
