<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\PhpUnit\Project;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\DiskPath;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\ErrorDisplay;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

$at = static fn(string $root): Project => Project::at($root, Paths::of(Path::of('tests')), Path::of('lib/vendor'), Path::of('.gate'));

it('names its vendor directory, its PHPUnit, Composer\'s list, its own paths and the gate\'s directory from its real root', function () use ($at): void {
    $root = (string) realpath(Scratch::directory());
    $project = $at(sprintf('%s/.', $root));

    expect($project->root())->toBe($root)
        ->and($project->vendor())->toEqual(Path::of('lib/vendor'))
        ->and($project->phpunit())->toBe(sprintf('%s/lib/vendor/bin/phpunit', $root))
        ->and($project->installed())->toBe(sprintf('%s/lib/vendor/composer/installed.json', $root))
        ->and($project->workspace())->toEqual(DiskPath::of(sprintf('%s/.gate', $root)))
        ->and($project->ownPath('coverage'))->toEqual(Path::of('.gate/phpunit/coverage'))
        ->and($project->own('coverage'))->toBe(sprintf('%s/.gate/phpunit/coverage', $root));
});

it('holds a PHPUnit to run only where its script is in the vendor directory, and is a project in a package of its own', function () use ($at): void {
    $root = Scratch::directory();
    Scratch::write($root, 'packages/billing/lib/vendor/bin/phpunit', '<?php');
    $project = $at($root);
    $billing = $project->in(Path::of('packages/billing'), Paths::of(Path::of('spec')));

    expect($project->hasPhpUnit())->toBeFalse()
        ->and($billing->hasPhpUnit())->toBeTrue()
        ->and($billing->root())->toBe(sprintf('%s/packages/billing', (string) realpath($root)))
        ->and($billing->tests())->toEqual(Paths::of(Path::of('spec')))
        ->and($billing->workspace())->toEqual(DiskPath::of(sprintf('%s/packages/billing/.gate', (string) realpath($root))));
});

it('reads where the PHPUnit config PHPUnit reads has PHP print errors', function (string $name, string $text, ErrorDisplay|NotGiven $display) use ($at): void {
    $root = Scratch::directory();
    Scratch::write($root, $name, $text);

    expect($at($root)->errorDisplay())->toEqual($display);
})->with([
    'phpunit.xml, hiding them' => ['phpunit.xml', '<phpunit><php><ini name="display_errors" value="0"/></php></phpunit>', ErrorDisplay::Nowhere],
    'phpunit.xml.dist, on standard error' => ['phpunit.xml.dist', '<phpunit><php><ini name="display_errors" value="stderr"/></php></phpunit>', ErrorDisplay::Stderr],
    'a config that sets none' => ['phpunit.dist.xml', '<phpunit/>', fn(): NotGiven => NotGiven::value()],
]);

it('reads the first of PHPUnit\'s names, and none where the project has no config', function () use ($at): void {
    $root = Scratch::directory();
    Scratch::write($root, 'phpunit.xml', '<phpunit><php><ini name="display_errors" value="0"/></php></phpunit>');
    Scratch::write($root, 'phpunit.dist.xml', '<phpunit><php><ini name="display_errors" value="1"/></php></phpunit>');

    expect($at($root)->errorDisplay())->toBe(ErrorDisplay::Nowhere)
        ->and($at(Scratch::directory())->errorDisplay())->toEqual(NotGiven::value());
});

it('writes a file of its own, with its directory made, or says it cannot', function () use ($at): void {
    $project = $at(Scratch::directory());
    $written = $project->written('a/b.txt', 'text');
    Scratch::write($project->root(), '.gate/phpunit/c', 'a file where the directory goes');
    set_error_handler(static fn(): bool => true);
    $blocked = $project->written('c/d.txt', 'text');
    restore_error_handler();

    expect($written)->toBe($project->own('a/b.txt'))
        ->and((string) file_get_contents($project->own('a/b.txt')))->toBe('text')
        ->and($blocked)->toBeInstanceOf(CannotJudge::class);
});

it('writes a file of its own in place of a link there, dangling or not, and never through it', function () use ($at): void {
    $scratch = Scratch::directory();
    $project = $at(sprintf('%s/project', $scratch));
    mkdir(dirname($project->own('a.txt')), recursive: true);
    Scratch::write($scratch, 'kept.txt', 'kept');
    symlink(sprintf('%s/planted.txt', $scratch), $project->own('a.txt'));
    symlink(sprintf('%s/kept.txt', $scratch), $project->own('b.txt'));

    expect($project->written('a.txt', 'text'))->toBe($project->own('a.txt'))
        ->and($project->written('b.txt', 'text'))->toBe($project->own('b.txt'))
        ->and(is_link($project->own('a.txt')) || is_link($project->own('b.txt')))->toBeFalse()
        ->and((string) file_get_contents($project->own('a.txt')))->toBe('text')
        ->and(file_exists(sprintf('%s/planted.txt', $scratch)))->toBeFalse()
        ->and((string) file_get_contents(sprintf('%s/kept.txt', $scratch)))->toBe('kept');
});
