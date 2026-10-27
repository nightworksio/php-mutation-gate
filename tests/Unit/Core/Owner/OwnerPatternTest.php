<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Owner\OwnerPattern;

it('matches a path as GitHub reads its CODEOWNERS', function (string $pattern, string $path, bool $matches): void {
    expect(OwnerPattern::of($pattern)->matches(Path::of($path)))->toBe($matches);
})->with([
    'a star, every file' => ['*', 'src/Money/Price.php', true],
    'a star and an extension, at any depth' => ['*.js', 'web/assets/app.js', true],
    'a star and an extension, not another' => ['*.js', 'web/assets/app.ts', false],
    'a name without a slash, a file at any depth' => ['Makefile', 'tools/Makefile', true],
    'a name without a slash, everything under a directory at any depth' => ['logs', 'build/logs/today.log', true],
    'a name without a slash, not a longer name' => ['logs', 'build/logsheet.txt', false],
    'a directory, everything under it at any depth' => ['apps/', 'src/apps/web/index.php', true],
    'a directory, not a file of its name' => ['apps/', 'src/apps', false],
    'a leading slash, at the root' => ['/docs/', 'docs/build-app/troubleshooting.md', true],
    'a leading slash, nowhere deeper' => ['/docs/', 'src/docs/index.md', false],
    'a slash within, at the root' => ['src/Money', 'src/Money/Price.php', true],
    'a slash within, nowhere deeper' => ['src/Money', 'lib/src/Money/Price.php', false],
    'a slash within, the file itself' => ['src/Money.php', 'src/Money.php', true],
    'a lone star last, a file directly in its directory' => ['docs/*', 'docs/getting-started.md', true],
    'a lone star last, nothing deeper' => ['docs/*', 'docs/build-app/troubleshooting.md', false],
    'a lone star at the root, a file there' => ['/*', 'README.md', true],
    'a lone star at the root, nothing deeper' => ['/*', 'src/Money.php', false],
    'a star within a segment last, a directory of that name too' => ['*.d', 'conf.d/app.ini', true],
    'a double star first, at any depth' => ['**/logs', 'deeply/nested/logs/today.log', true],
    'a double star first, at the root' => ['**/logs', 'logs/today.log', true],
    'a double star within, no directory between' => ['src/**/Money.php', 'src/Money.php', true],
    'a double star within, several between' => ['src/**/Money.php', 'src/A/B/Money.php', true],
    'a double star last, everything under' => ['src/**', 'src/A/Money.php', true],
    'a question mark, one character' => ['v?.php', 'v1.php', true],
    'a question mark, not two' => ['v?.php', 'v12.php', false],
    'a question mark, not a slash' => ['src?Money.php', 'src/Money.php', false],
    'case, as it is spelt' => ['/Docs/', 'docs/index.md', false],
    'a dot, as a dot' => ['*.md', 'README_md', false],
]);

it('keeps the pattern as the rule writes it', function (): void {
    expect(OwnerPattern::of('/build/logs/')->value())->toBe('/build/logs/');
});
