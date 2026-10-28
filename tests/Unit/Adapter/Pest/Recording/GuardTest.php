<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Recording\Guard;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\Loaded;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\Off;
use NightWorksIO\MutationGate\Adapter\Pest\ServedClasses;
use NightWorksIO\MutationGate\Core\Runner\Opcache;
use NightWorksIO\MutationGate\Tests\Support\GateEnvironment;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\ServedBox;

beforeEach(function (): void {
    GateEnvironment::clear();
});

afterEach(function (): void {
    GateEnvironment::restore();
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
    $guard = Guard::watching($file, $original, Loaded::of($before));

    if ($guard instanceof Guard) {
        $guard->write($after, $opcache);
    }

    return (string) file_get_contents($file);
}

it('guards nothing unless the adapter names a file and the override an original', function (): void {
    expect(Guard::watching(file: false, original: '/p/src/A.php', loaded: Loaded::of([])))->toBe(Off::Guarding)
        ->and(Guard::watching('', '/p/src/A.php', Loaded::of([])))->toBe(Off::Guarding)
        ->and(Guard::watching('/g.json', original: false, loaded: Loaded::of([])))->toBe(Off::Guarding)
        ->and(Guard::fromEnvironment([]))->toBe(Off::Guarding);
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

it('writes the original as loaded where the last error PHP raised stands in it, as one a mutated copy that cannot compile raises', function (): void {
    $root = Scratch::directory();
    Scratch::write($root, 'src/A.php', '<?php');
    $original = sprintf('%s/src/A.php', realpath($root));
    $file = sprintf('%s/guard.json', $root);
    $guard = Guard::watching($file, $original, Loaded::of([]));

    expect($guard)->toBeInstanceOf(Guard::class);

    /** @var Guard $guard */
    $guard->write([], Opcache::of('0', ''), $original);
    $erred = (string) file_get_contents($file);
    $guard->write([], Opcache::of('0', ''), '/nowhere/B.php');

    expect($erred)->toBe('{"before":false,"loaded":true,"opcache":false}')
        ->and((string) file_get_contents($file))->toBe('{"before":false,"loaded":false,"opcache":false}');
});

it('writes a served copy as the original loaded, and the original loaded by its path as loaded before the override', function (): void {
    $box = ServedBox::registered();
    $served = sprintf('%s.served.php', $box->copy);
    $guard = sprintf('%s/guard.json', dirname($box->file, 2));

    try {
        ServedClasses::serve($box->file, $box->copy);

        expect(guardWritten($guard, $box->file, [], [$served], Opcache::of('0', '')))
            ->toBe('{"before":false,"loaded":true,"opcache":false}')
            ->and(guardWritten($guard, $box->file, [], [$box->file], Opcache::of('0', '')))
            ->toBe('{"before":true,"loaded":false,"opcache":false}');
    } finally {
        $box->loader->unregister();
    }
});
