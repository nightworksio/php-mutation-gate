<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Project\PhpUnitTrees;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Score\Exempt;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Score\Undeclared;
use NightWorksIO\MutationGate\Core\Tree\Tree;
use NightWorksIO\MutationGate\Core\Tree\Trees;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

/**
 * Each tree a source finds, as its path and the floor it declares.
 *
 * @return list<array{string, mixed}>|CannotJudge
 */
$found = (static fn(Trees|CannotJudge $trees): array|CannotJudge => $trees instanceof Trees
    ? array_map(static fn(Tree $tree): array => [$tree->path()->value(), $tree->declared()], [...$trees])
    : $trees);

/** A phpunit.xml whose <source> includes and excludes these. */
$phpunit = static fn(string $source): string => sprintf(
    '<?xml version="1.0"?><phpunit><source>%s</source></phpunit>',
    $source,
);

it('leaves out what <source><exclude> excludes, and holds a path excluded in a tree at 0', function () use (
    $found,
    $phpunit,
): void {
    $project = Scratch::directory();
    Scratch::write($project, 'phpunit.xml', $phpunit(
        '<include><directory>src</directory><directory>legacy</directory><file>bootstrap/app.php</file></include>'
        . '<exclude><directory>src/Generated</directory><directory>legacy</directory>'
        . '<file>src/Kernel.php</file></exclude>',
    ));

    expect($found(PhpUnitTrees::in($project, Paths::none())->trees()))->toEqual([
        ['src', Undeclared::floor()],
        ['bootstrap/app.php', Undeclared::floor()],
        ['src/Generated', Exempt::because('phpunit.xml excludes it from <source>')],
        ['src/Kernel.php', Exempt::because('phpunit.xml excludes it from <source>')],
    ]);
});

it('holds anything excluded inside a tree at the root at 0', function () use ($found, $phpunit): void {
    $project = Scratch::directory();
    Scratch::write($project, 'phpunit.xml', $phpunit(
        '<include><directory>.</directory></include><exclude><directory>vendor</directory></exclude>',
    ));

    expect($found(PhpUnitTrees::in($project, Paths::none())->trees()))->toEqual([
        ['.', Undeclared::floor()],
        ['vendor', Exempt::because('phpunit.xml excludes it from <source>')],
    ]);
});

it('does not take a path that merely starts like an excluded one as inside it', function () use (
    $found,
    $phpunit,
): void {
    $project = Scratch::directory();
    Scratch::write($project, 'phpunit.xml', $phpunit(
        '<include><directory>src</directory><directory>srcgen</directory></include>'
        . '<exclude><directory>src</directory></exclude>',
    ));

    expect($found(PhpUnitTrees::in($project, Paths::none())->trees()))->toEqual([['srcgen', Undeclared::floor()]]);
});

it('reads the file PHPUnit itself would read', function (string $name) use ($found, $phpunit): void {
    $project = Scratch::directory();
    Scratch::write($project, $name, $phpunit('<include><directory>src</directory></include>'));
    Scratch::write($project, 'phpunit.xml.dist', $phpunit('<include><directory>dist</directory></include>'));

    expect($found(PhpUnitTrees::in($project, Paths::none())->trees()))->toEqual([['src', Undeclared::floor()]]);
})->with(['phpunit.xml', 'phpunit.dist.xml']);

it('falls back on the preset\'s paths without a <source>, and on the autoload without those', function () use (
    $found,
): void {
    $project = Scratch::directory();
    Scratch::write($project, 'phpunit.xml', '<?xml version="1.0"?><phpunit/>');
    Scratch::write($project, 'composer.json', '{"autoload": {"psr-4": {"App\\\\": "lib/"}}}');

    expect($found(PhpUnitTrees::in($project, Paths::of(Path::of('app')))->trees()))
        ->toEqual([['app', Undeclared::floor()]])
        ->and($found(PhpUnitTrees::in($project, Paths::none())->trees()))->toEqual([['lib', Undeclared::floor()]]);
});

it('falls back the same way with no phpunit.xml at all', function () use ($found): void {
    $project = Scratch::directory();
    Scratch::write($project, 'composer.json', '{"autoload": {"classmap": ["database/"]}}');

    expect($found(PhpUnitTrees::in($project, Paths::none())->trees()))->toEqual([['database', Undeclared::floor()]]);
});

it('cannot judge a project with no tree at all', function (): void {
    expect(PhpUnitTrees::in(Scratch::directory(), Paths::none())->trees())->toEqual(CannotJudge::because(
        'No tree found in the <source> of phpunit.xml or the autoload of composer.json; list trees in config.',
    ));
});

it('cannot judge a phpunit.xml that is not XML', function (): void {
    $project = Scratch::directory();
    Scratch::write($project, 'phpunit.xml', '<phpunit><source>');
    $source = PhpUnitTrees::in($project, Paths::none());
    $why = CannotJudge::because('phpunit.xml is not XML, so the trees and tests in it cannot be read.');

    expect($source->trees())->toEqual($why)
        ->and($source->testDirectories())->toEqual($why);
});

it('passes on a manifest it cannot read', function () use ($phpunit): void {
    $project = Scratch::directory();
    Scratch::write($project, 'phpunit.xml', $phpunit(
        '<include><directory>src</directory></include><exclude><directory>src/Gen</directory></exclude>',
    ));
    Scratch::write($project, 'src/composer.json', '[');

    expect(PhpUnitTrees::in($project, Paths::none())->trees())
        ->toEqual(CannotJudge::because('src/composer.json is not a JSON object.'));
});

it('finds the test directories of every test suite', function (): void {
    $project = Scratch::directory();
    Scratch::write($project, 'phpunit.xml', <<<'XML'
        <?xml version="1.0"?>
        <phpunit>
            <testsuites>
                <testsuite name="Unit"><directory>tests/Unit</directory></testsuite>
                <testsuite name="Feature">
                    <directory> ./tests/Feature/ </directory>
                    <file>tests/SmokeTest.php</file>
                </testsuite>
                <testsuite name="Again"><directory>tests/Unit</directory></testsuite>
            </testsuites>
        </phpunit>
        XML);
    $directories = PhpUnitTrees::in($project, Paths::none())->testDirectories();

    expect($directories instanceof Paths
        ? array_map(static fn(Path $path): string => $path->value(), [...$directories])
        : [])
        ->toBe(['tests/Unit', 'tests/Feature']);
});

it('finds no test directory without a phpunit.xml', function (): void {
    expect(PhpUnitTrees::in(Scratch::directory(), Paths::none())->testDirectories())->toEqual(Paths::none());
});

it('reads the floor a tree\'s nearest manifest declares, from the tree up', function () use ($found, $phpunit): void {
    $project = Scratch::directory();
    Scratch::write($project, 'phpunit.xml', $phpunit(
        '<include><directory>modules/Billing/src</directory><file>modules/Billing/boot.php</file>'
        . '<directory>app</directory></include>',
    ));
    Scratch::write($project, 'composer.json', '{"extra": {"mutation-gate": {"floor": 60}}}');
    Scratch::write(
        $project,
        'modules/Billing/composer.json',
        '{"name": "acme/billing", "extra": {"mutation-gate": {"floor": 90.5}}}',
    );
    Scratch::write($project, 'modules/Billing/src/composer.json', '{"name": "acme/billing-src"}');
    Scratch::write($project, 'modules/Billing/boot.php', '<?php');
    Scratch::write($project, 'app/.gitkeep', '');

    expect($found(PhpUnitTrees::in($project, Paths::none())->trees()))->toEqual([
        ['modules/Billing/src', Floor::of(90.5)],
        ['modules/Billing/boot.php', Floor::of(90.5)],
        ['app', Floor::of(60)],
    ]);
});

it('cannot judge a <source> that excludes every path it includes', function () use ($phpunit): void {
    $project = Scratch::directory();
    Scratch::write($project, 'phpunit.xml', $phpunit(
        '<include><directory>src</directory></include><exclude><directory>src</directory></exclude>',
    ));

    expect(PhpUnitTrees::in($project, Paths::none())->trees())->toEqual(CannotJudge::because(
        'phpunit.xml excludes every path its <source> includes, so no tree is left; list trees in the config.',
    ));
});
