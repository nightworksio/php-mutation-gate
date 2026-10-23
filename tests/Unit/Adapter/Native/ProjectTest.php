<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Native\Project;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\BuiltinRunner;
use NightWorksIO\MutationGate\Core\File\DiskPath;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

$at = static fn(string $root): Project => Project::at($root, Paths::of(Path::of('tests')), Path::of('lib/vendor'), Path::of('.gate'), BuiltinRunner::PhpUnit);

it('names its vendor directory, its autoloader, its own paths and the gate\'s directory from its real root', function () use ($at): void {
    $root = (string) realpath(Scratch::directory());
    $project = $at(sprintf('%s/.', $root));

    expect($project->root())->toBe($root)
        ->and($project->vendor())->toEqual(Path::of('lib/vendor'))
        ->and($project->autoloader())->toBe(sprintf('%s/lib/vendor/autoload.php', $root))
        ->and($project->workspace())->toEqual(DiskPath::of(sprintf('%s/.gate', $root)))
        ->and($project->ownPath('coverage'))->toEqual(Path::of('.gate/phpunit/coverage'))
        ->and($project->own('coverage'))->toBe(sprintf('%s/.gate/phpunit/coverage', $root));
});

it('is a project in a package of its own', function () use ($at): void {
    $root = Scratch::directory();
    $billing = $at($root)->in(Path::of('packages/billing'), Paths::of(Path::of('spec')));

    expect($billing->root())->toBe(sprintf('%s/packages/billing', (string) realpath($root)))
        ->and($billing->tests())->toEqual(Paths::of(Path::of('spec')))
        ->and($billing->workspace())->toEqual(DiskPath::of(sprintf('%s/packages/billing/.gate', (string) realpath($root))));
});

it('writes a file of its own, with its directory made, or says it cannot', function () use ($at): void {
    $project = $at(Scratch::directory());
    $written = $project->written('a/b.txt', 'text');
    Scratch::write($project->root(), '.gate/phpunit/c', 'a file where the directory goes');
    $long = str_repeat('x', 300);
    set_error_handler(static fn(): bool => true);
    $blocked = $project->written('c/d.txt', 'text');
    $unwritable = $project->written($long, 'text');
    restore_error_handler();

    expect($written)->toBe($project->own('a/b.txt'))
        ->and((string) file_get_contents($project->own('a/b.txt')))->toBe('text')
        ->and($blocked)->toBeInstanceOf(CannotJudge::class)
        ->and($unwritable)->toEqual(CannotJudge::because(sprintf('The gate cannot write %s, which the run it starts reads.', $project->own($long))));
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

it('readies a file of its own for a run to write, with no earlier file or link left there, or says it cannot', function () use ($at): void {
    $scratch = Scratch::directory();
    $project = $at(sprintf('%s/project', $scratch));
    $stale = $project->written('stale.txt', 'an earlier run\'s');
    Scratch::write($scratch, 'kept.txt', 'kept');
    symlink(sprintf('%s/kept.txt', $scratch), $project->own('linked.txt'));
    mkdir($project->own('taken'));

    expect($project->fresh('stale.txt'))->toBe($stale)
        ->and(file_exists($project->own('stale.txt')))->toBeFalse()
        ->and($project->fresh('linked.txt'))->toBe($project->own('linked.txt'))
        ->and(is_link($project->own('linked.txt')))->toBeFalse()
        ->and((string) file_get_contents(sprintf('%s/kept.txt', $scratch)))->toBe('kept')
        ->and($project->fresh('new/b.txt'))->toBe($project->own('new/b.txt'))
        ->and(is_dir(dirname($project->own('new/b.txt'))))->toBeTrue()
        ->and($project->fresh('taken'))->toEqual(CannotJudge::because(sprintf(
            'The gate cannot write %s, which the run it starts reads.',
            $project->own('taken'),
        )));
});
