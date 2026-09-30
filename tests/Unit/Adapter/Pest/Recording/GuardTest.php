<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Recording\Guard;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\Off;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\Opcache;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

it('guards nothing unless the adapter names a file and the override an original', function (): void {
    expect(Guard::watching(false, '/p/src/A.php', []))->toBe(Off::Guarding)
        ->and(Guard::watching('', '/p/src/A.php', []))->toBe(Off::Guarding)
        ->and(Guard::watching('/g.json', false, []))->toBe(Off::Guarding)
        ->and(Guard::fromEnvironment())->toBe(Off::Guarding);
});

it('writes whether the original was loaded before the override, at all, and whether opcache could serve it', function (): void {
    $root = Scratch::directory();
    Scratch::write($root, 'src/A.php', '<?php');
    $original = sprintf('%s/src/A.php', realpath($root));
    $guard = sprintf('%s/guard.json', $root);

    Guard::watching($guard, $original, [$original])->write([$original], Opcache::of('1', ''));
    $early = file_get_contents($guard);
    Guard::watching($guard, sprintf('%s/./src/A.php', $root), [])->write([], Opcache::of('0', ''));
    $never = file_get_contents($guard);
    Guard::watching($guard, '/nowhere/B.php', [])->write(['/nowhere/B.php'], Opcache::of(false, false));

    expect($early)->toBe('{"before":true,"loaded":true,"opcache":true}')
        ->and($never)->toBe('{"before":false,"loaded":false,"opcache":false}')
        ->and(file_get_contents($guard))->toBe('{"before":false,"loaded":true,"opcache":false}');
});
