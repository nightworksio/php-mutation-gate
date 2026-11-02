<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Composer\ManifestMoves;
use NightWorksIO\MutationGate\Core\File\Contents;

/**
 * A composer.json of these keys.
 *
 * @param array<string, mixed> $keys
 */
function manifestOf(array $keys): Contents
{
    return Contents::of((string) json_encode($keys, JSON_PRETTY_PRINT));
}

it('names each package whose line moved, added or removed, in any list, once, in byte order', function (): void {
    $moves = ManifestMoves::between(
        manifestOf(['require' => ['php' => '^8.5', 'psr/log' => '^3.0'], 'conflict' => ['pestphp/pest' => '<5.1']]),
        manifestOf([
            'require' => ['php' => '^8.5', 'symfony/console' => '^8.1'],
            'require-dev' => ['psr/log' => '^3.0'],
            'conflict' => ['pestphp/pest' => '<5.2'],
            'replace' => ['symfony/polyfill-php85' => '*'],
        ]),
    );

    expect($moves instanceof ManifestMoves ? [$moves->packages(), $moves->beyondPackages()] : $moves)
        ->toBe([['pestphp/pest', 'psr/log', 'symfony/console', 'symfony/polyfill-php85'], false]);
});

it('moves nothing where only the keys that describe the package or name its commands changed', function (): void {
    $moves = ManifestMoves::between(
        manifestOf(['description' => 'a', 'keywords' => ['a'], 'support' => [], 'scripts' => ['test' => 'pest'], 'require' => ['php' => '^8.5']]),
        manifestOf([
            'description' => 'b',
            'keywords' => ['a', 'b'],
            'support' => ['docs' => 'https://example.org'],
            'scripts' => ['test' => 'pest --parallel'],
            'scripts-descriptions' => ['test' => 'Every suite.'],
            'funding' => [],
            'authors' => [],
            'require' => ['php' => '^8.5'],
        ]),
    );

    expect($moves instanceof ManifestMoves ? [$moves->packages(), $moves->beyondPackages()] : $moves)->toBe([[], false]);
});

it('names a package whose name is all digits by its name, as text', function (): void {
    $moves = ManifestMoves::between(manifestOf(['require' => []]), manifestOf(['require' => ['2048' => '^1.0']]));

    expect($moves instanceof ManifestMoves ? $moves->packages() : $moves)->toBe(['2048']);
});

it('moves nothing where the keys that decide only changed places', function (): void {
    $moves = ManifestMoves::between(
        manifestOf(['autoload' => ['psr-4' => ['App\\' => 'src/']], 'config' => ['sort-packages' => true]]),
        manifestOf(['config' => ['sort-packages' => true], 'autoload' => ['psr-4' => ['App\\' => 'src/']]]),
    );

    expect($moves instanceof ManifestMoves ? [$moves->packages(), $moves->beyondPackages()] : $moves)->toBe([[], false]);
});

it('says a key that decides how the project installs or loads moved', function (string $key, mixed $value): void {
    $moves = ManifestMoves::between(manifestOf(['require' => []]), manifestOf(['require' => [], $key => $value]));

    expect($moves instanceof ManifestMoves && $moves->beyondPackages())->toBeTrue();
})->with([
    'autoload' => ['autoload', ['psr-4' => ['App\\' => 'src/']]],
    'autoload-dev' => ['autoload-dev', ['psr-4' => ['Tests\\' => 'tests/']]],
    'config' => ['config', ['platform' => ['php' => '8.5.0']]],
    'extra' => ['extra', ['mutation-gate' => []]],
    'minimum-stability' => ['minimum-stability', 'dev'],
    'repositories' => ['repositories', [['type' => 'path', 'url' => '../gate']]],
    'a key Composer adds later' => ['bin-compat', 'full'],
]);

it('cannot judge a manifest that is no JSON object', function (): void {
    expect(ManifestMoves::between(manifestOf([]), Contents::of('{"require":')))->toBeInstanceOf(CannotJudge::class);
});
