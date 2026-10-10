<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Project;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\KillerFile;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\Recorder;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\Verdicts;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Pruning\PrunedList;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

/** A project in a new directory, spelt with a trailing slash. */
$project = static fn(): Project => Project::at(
    sprintf('%s/', Scratch::directory()),
    Paths::of(Path::of('tests')),
    Path::of('.mutation-gate'),
    Path::of('vendor'),
);

it('holds its root as its real path, as Pest reports files', function (): void {
    $root = Scratch::directory();
    mkdir(sprintf('%s/real', $root));
    symlink(sprintf('%s/real', $root), sprintf('%s/linked', $root));

    expect(Project::at(sprintf('%s/linked', $root), Paths::none(), Path::of('.mutation-gate'), Path::of('vendor'))->root())
        ->toBe((string) realpath(sprintf('%s/real', $root)));
});

it('holds a root that is not there as it is spelt, less a trailing slash', function (): void {
    expect(Project::at('/nowhere/at/all/', Paths::none(), Path::of('.mutation-gate'), Path::of('vendor'))->root())->toBe('/nowhere/at/all');
});

it('names the directories its tests live in', function () use ($project): void {
    expect($project()->tests())->toEqual(Paths::of(Path::of('tests')));
});

it('finds a path of the project on disk, and an absolute path where it says', function (): void {
    $project = Project::at('/nowhere', Paths::none(), Path::of('.mutation-gate'), Path::of('vendor'));

    expect($project->absolute(Path::of('src/Money.php')))->toBe('/nowhere/src/Money.php')
        ->and($project->absolute(Path::of('/elsewhere/Money.php')))->toBe('/elsewhere/Money.php');
});

it('spells a file inside it as the project does, and one outside as it is', function (): void {
    $project = Project::at('/nowhere', Paths::none(), Path::of('.mutation-gate'), Path::of('vendor'));

    expect($project->relative('/nowhere/src/Money.php'))->toEqual(Path::of('src/Money.php'))
        ->and($project->relative('/nowhere-else/src/Money.php'))->toEqual(Path::of('/nowhere-else/src/Money.php'));
});

it('makes a directory where it is not there, and leaves one that is', function () use ($project): void {
    $at = $project();
    $made = $at->directory(Path::of('.mutation-gate/coverage/shard'));
    $again = $at->directory(Path::of('.mutation-gate/coverage/shard'));

    expect($made)->toBe(sprintf('%s/.mutation-gate/coverage/shard', $at->root()))
        ->and($again)->toBe($made)
        ->and(is_dir($made))->toBeTrue();
});

it('names a results file with no earlier run\'s results, map, list of mutants, log of events, verdicts, pruned list, mutated copies, error logs or killer files left beside it', function () use ($project): void {
    $at = $project();
    $results = sprintf('%s/.mutation-gate/pest/results.jsonl', $at->root());
    mkdir(dirname($results), recursive: true);
    file_put_contents($results, 'earlier');
    file_put_contents(sprintf('%s.coverage.php', $results), 'earlier');
    file_put_contents(sprintf('%s.only', $results), 'earlier');
    file_put_contents(sprintf('%s.events', $results), 'earlier');
    file_put_contents(Verdicts::beside($results), 'earlier');
    file_put_contents(PrunedList::beside($results), 'earlier');
    mkdir(sprintf('%s/mutants', dirname($results)));
    file_put_contents(sprintf('%s/mutants/n1.php', dirname($results)), 'earlier');
    file_put_contents(Recorder::errorsBeside($results, '/tmp/mutations/n1.php'), 'earlier');
    file_put_contents(KillerFile::beside($results, '/tmp/mutations/n1.php'), 'earlier');

    expect($at->freshResults())->toBe($results)
        ->and(is_file($results))->toBeFalse()
        ->and(is_file(sprintf('%s.coverage.php', $results)))->toBeFalse()
        ->and(is_file(sprintf('%s.only', $results)))->toBeFalse()
        ->and(is_file(sprintf('%s.events', $results)))->toBeFalse()
        ->and(is_file(Verdicts::beside($results)))->toBeFalse()
        ->and(is_file(PrunedList::beside($results)))->toBeFalse()
        ->and(is_file(sprintf('%s/mutants/n1.php', dirname($results))))->toBeFalse()
        ->and(is_file(Recorder::errorsBeside($results, '/tmp/mutations/n1.php')))->toBeFalse()
        ->and(is_file(KillerFile::beside($results, '/tmp/mutations/n1.php')))->toBeFalse()
        ->and($at->freshResults())->toBe($results);
});

it('makes the directory a results file goes in', function () use ($project): void {
    $at = $project();

    $results = $at->freshResults();

    expect(is_string($results) && is_dir(dirname($results)))->toBeTrue();
});

it('cannot name a results file where an earlier run\'s cannot be removed', function () use ($project): void {
    $at = $project();
    $results = sprintf('%s/.mutation-gate/pest/results.jsonl', $at->root());
    mkdir(sprintf('%s.coverage.php', $results), recursive: true);

    expect($at->freshResults())->toEqual(CannotJudge::because(sprintf(
        'An earlier run left %s or the map beside it, and the gate cannot remove them.',
        $results,
    )));
});

it('names the order directory with no earlier run\'s plan or orders left in it, or why they are', function () use ($project): void {
    $at = $project();
    $order = sprintf('%s/.mutation-gate/order', $at->root());
    mkdir(sprintf('%s/m1', $order), recursive: true);
    file_put_contents(sprintf('%s/plan.json', $order), 'earlier');
    file_put_contents(sprintf('%s/m1/test-run-history', $order), 'earlier');
    $fresh = $at->freshOrder();
    $emptied = ! is_file(sprintf('%s/plan.json', $order)) && ! is_file(sprintf('%s/m1/test-run-history', $order));
    mkdir(sprintf('%s/plan.json', $order));

    expect($fresh)->toBe($order)
        ->and($emptied)->toBeTrue()
        ->and($at->freshOrder())->toEqual(CannotJudge::because(sprintf(
            'An earlier run left orders in %s, and the gate cannot remove them.',
            $order,
        )));
});

it('removes a link where a results file, the map or a mutated copy goes, dangling or not, and leaves where it leads as it was', function () use ($project): void {
    $at = $project();
    $results = sprintf('%s/.mutation-gate/pest/results.jsonl', $at->root());
    mkdir(sprintf('%s/mutants', dirname($results)), recursive: true);
    Scratch::write($at->root(), 'kept.php', 'kept');
    symlink(sprintf('%s/planted.jsonl', $at->root()), $results);
    symlink(sprintf('%s/kept.php', $at->root()), Recorder::coverageBeside($results));
    symlink(sprintf('%s/planted.php', $at->root()), Recorder::mutantBeside($results, 'n1'));

    expect($at->freshResults())->toBe($results)
        ->and(is_link($results) || is_link(Recorder::coverageBeside($results)))->toBeFalse()
        ->and(is_link(Recorder::mutantBeside($results, 'n1')))->toBeFalse()
        ->and((string) file_get_contents(sprintf('%s/kept.php', $at->root())))->toBe('kept');
});

it('cannot name a results file where an earlier run\'s link cannot be removed', function () use ($project): void {
    $at = $project();
    $results = sprintf('%s/.mutation-gate/pest/results.jsonl', $at->root());
    mkdir(dirname($results), recursive: true);
    symlink(sprintf('%s/planted.jsonl', $at->root()), $results);
    chmod(dirname($results), 0o555);
    set_error_handler(static fn(): bool => true);
    $fresh = $at->freshResults();
    restore_error_handler();
    chmod(dirname($results), 0o755);

    expect($fresh)->toBeInstanceOf(CannotJudge::class);
});
