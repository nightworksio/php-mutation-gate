<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Composer\DecidingPackages;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Glob;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Registry\FirstPartyPackage;
use NightWorksIO\MutationGate\Tests\Support\Configs;
use NightWorksIO\MutationGate\Tests\Support\Locks;
use NightWorksIO\MutationGate\Tests\Support\Tree;

it('decides on any move where the config names no package', function (): void {
    expect(DecidingPackages::every()->decide(Locks::of(['psr/log' => '3.0.0']), Locks::of(['psr/log' => '3.0.1'])))->toBeTrue();
});

it('decides only where a moved package matches a name or a pattern of names', function (Contents $now, bool $decides): void {
    $deciding = DecidingPackages::named(Glob::of('pestphp/*'), Glob::of('nikic/php-parser'));
    $before = Locks::of(['pestphp/pest' => '5.2.1', 'nikic/php-parser' => '5.6.0', 'symfony/console' => '8.1.7']);

    expect($deciding->decide($before, $now))->toBe($decides);
})->with([
    'a package a pattern names' => [fn(): Contents => Locks::of(['pestphp/pest' => '5.2.2', 'nikic/php-parser' => '5.6.0', 'symfony/console' => '8.1.7']), true],
    'a package named whole' => [fn(): Contents => Locks::of(['pestphp/pest' => '5.2.1', 'nikic/php-parser' => '5.7.0', 'symfony/console' => '8.1.7']), true],
    'a named package removed' => [fn(): Contents => Locks::of(['pestphp/pest' => '5.2.1', 'symfony/console' => '8.1.7']), true],
    'a package the pattern names added' => [fn(): Contents => Locks::of(['pestphp/pest' => '5.2.1', 'pestphp/pest-plugin-arch' => '5.0.0', 'nikic/php-parser' => '5.6.0', 'symfony/console' => '8.1.7']), true],
    'only a package no pattern names' => [fn(): Contents => Locks::of(['pestphp/pest' => '5.2.1', 'nikic/php-parser' => '5.6.0', 'symfony/console' => '8.1.9']), false],
    'nothing' => [fn(): Contents => Locks::of(['pestphp/pest' => '5.2.1', 'nikic/php-parser' => '5.6.0', 'symfony/console' => '8.1.7']), false],
]);

it('decides where the gate or a plugin it ships moved, whatever the config names', function (string $package): void {
    $deciding = DecidingPackages::named(Glob::of('pestphp/*'));

    expect($deciding->decide(Locks::of([$package => '0.1.0']), Locks::of([$package => '0.1.1'])))->toBeTrue();
})->with(fn(): array => array_map(static fn(FirstPartyPackage $package): string => $package->value, FirstPartyPackage::cases()));

it('decides where a key besides the package lists moved, or either lock cannot be read', function (Contents $now): void {
    $deciding = DecidingPackages::named(Glob::of('pestphp/*'));

    expect($deciding->decide(Locks::of(['psr/log' => '3.0.0']), $now))->toBeTrue();
})->with([
    'the platform' => [fn(): Contents => Locks::of(['psr/log' => '3.0.0'], [], ['platform' => ['php' => '^8.6']])],
    'not a lock' => [fn(): Contents => Contents::of('not a lock')],
]);

/** Each package, or pattern of packages, whose move decides the gate's own verdict, and why, in the config's order. */
const DECIDING_THE_GATES_OWN_VERDICT = [
    ['brianium/paratest', 'runs the coverage run across processes'],
    ['ergebnis/phpstan-rules', 'a rule phpstan.neon loads, which can reject a survivor'],
    ['nikic/php-parser', 'parses the code the gate and the mutate plugin mutate'],
    ['pestphp/*', 'Pest runs every mutant, and its mutate plugin makes them'],
    ['phpstan/*', 'PHPStan judges every survivor, with the rules phpstan.neon loads'],
    ['phpunit/*', 'PHPUnit runs the tests under Pest, and php-code-coverage maps what each runs'],
    ['sebastian/*', 'PHPUnit\'s comparisons and diffs decide whether an assertion fails'],
    ['shipmonk/phpstan-rules', 'a rule phpstan.neon loads, which can reject a survivor'],
    ['spaze/phpstan-disallowed-calls', 'a rule phpstan.neon loads, which can reject a survivor'],
    ['staabm/side-effects-detector', 'decides which tests PHPUnit reports as risky, which phpunit.xml fails'],
    ['symfony/process', 'starts the process each of Pest\'s mutants runs in'],
    ['tomasvotruba/*', 'a rule phpstan.neon loads, which can reject a survivor'],
];

/**
 * The name of each package a lock pins, under either list.
 *
 * @return list<string>
 */
function pinnedIn(Contents $lock): array
{
    $decoded = Node::decode($lock->text());

    return array_map(
        static fn(Node $package): string => $package->field('name')->text(),
        [...$decoded->field('packages')->items(), ...$decoded->field('packages-dev')->items()],
    );
}

it('lists in the gate\'s own config the packages that decide its verdict, each with its reason here', function (): void {
    $listed = Node::decode((string) file_get_contents(Tree::at('mutation-gate.json')))->field('reach')->field('lockPackages')->items();

    expect(array_map(static fn(Node $entry): string => $entry->text(), $listed))->toBe(array_column(DECIDING_THE_GATES_OWN_VERDICT, 0));
});

it('decides the gate\'s own verdict where the lock moves a package the config lists', function (string $pattern, string $reason): void {
    $lock = Contents::of((string) file_get_contents(Tree::at('composer.lock')));
    $deciding = Configs::settings((string) file_get_contents(Tree::at('mutation-gate.json')))->reach()->lockPackages();
    $named = (string) array_find(pinnedIn($lock), static fn(string $name): bool => Glob::of($pattern)->matches(Path::of($name)));
    $moved = str_replace(sprintf('"name": "%s",', $named), sprintf('"name": "%s", "moved": true,', $named), $lock->text());

    expect($reason)->not->toBeEmpty()
        ->and($moved)->not->toBe($lock->text())
        ->and($deciding->decide($lock, Contents::of($moved)))->toBeTrue();
})->with(fn(): array => DECIDING_THE_GATES_OWN_VERDICT);

it('leaves the gate\'s own verdict standing where the lock moves only a package the config does not list', function (): void {
    $lock = Contents::of((string) file_get_contents(Tree::at('composer.lock')));
    $deciding = Configs::settings((string) file_get_contents(Tree::at('mutation-gate.json')))->reach()->lockPackages();
    $moved = str_replace('"name": "infection/infection",', '"name": "infection/infection", "moved": true,', $lock->text());

    expect($moved)->not->toBe($lock->text())
        ->and($deciding->decide($lock, Contents::of($moved)))->toBeFalse();
});
