<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Recording\Guard;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\Off;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\Opcache;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

/**
 * What a guard of an original writes, given the files loaded when it started
 * and when the run ended.
 *
 * @param list<string> $before
 * @param list<string> $after
 */
function guardWritten(string $file, string $original, array $before, array $after, Opcache $opcache): string
{
    $guard = Guard::watching($file, $original, $before);

    if ($guard instanceof Guard) {
        $guard->write($after, $opcache);
    }

    return (string) file_get_contents($file);
}

it('guards nothing unless the adapter names a file and the override an original', function (): void {
    expect(Guard::watching(file: false, original: '/p/src/A.php', loaded: []))->toBe(Off::Guarding)
        ->and(Guard::watching('', '/p/src/A.php', []))->toBe(Off::Guarding)
        ->and(Guard::watching('/g.json', original: false, loaded: []))->toBe(Off::Guarding)
        ->and(Guard::fromEnvironment())->toBe(Off::Guarding);
});

it('writes whether the original was loaded before the override, at all, and whether opcache could serve it', function (): void {
    $root = Scratch::directory();
    Scratch::write($root, 'src/A.php', '<?php');
    $original = sprintf('%s/src/A.php', realpath($root));
    $guard = sprintf('%s/guard.json', $root);

    $early = guardWritten($guard, $original, [$original], [$original], Opcache::of('1', ''));
    $never = guardWritten($guard, sprintf('%s/./src/A.php', $root), [], [], Opcache::of('0', ''));
    $late = guardWritten($guard, '/nowhere/B.php', [], ['/nowhere/B.php'], Opcache::of(cli: false, fileCache: false));

    expect($early)->toBe('{"before":true,"loaded":true,"opcache":true}')
        ->and($never)->toBe('{"before":false,"loaded":false,"opcache":false}')
        ->and($late)->toBe('{"before":false,"loaded":true,"opcache":false}');
});
