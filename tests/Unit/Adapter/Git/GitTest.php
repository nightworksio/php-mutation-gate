<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Git\CommitObject;
use NightWorksIO\MutationGate\Adapter\Git\GitVersion;
use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Change\JudgedCommit;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

it('reads the version git prints, and one it cannot read as older than every version', function (string $printed, bool $mergesTrees): void {
    expect(GitVersion::printed($printed)->isAtLeast(GitVersion::printed('git version 2.38')))->toBe($mergesTrees);
})->with([
    'a release' => ['git version 2.38.0', true],
    'a vendor\'s build' => ['git version 2.50.1 (Apple Git-155)', true],
    'a later major' => ['git version 3.0.0', true],
    'one minor short' => ['git version 2.37.9', false],
    'an earlier major' => ['git version 1.99.0', false],
    'not a version' => ['hub version 2.40.0', false],
]);

it('reads a commit\'s tree and parents from its headers alone, never from its message', function (): void {
    $printed = sprintf(
        "tree %s\nparent %s\nparent %s\nauthor Gate <gate@example.com> 1 +0000\n\nMerge\n\nparent %s\ntree %s\n",
        str_repeat('e', 40),
        str_repeat('a', 40),
        str_repeat('b', 40),
        str_repeat('c', 40),
        str_repeat('f', 40),
    );
    $read = CommitObject::read(str_repeat('d', 40), $printed);

    expect($read instanceof JudgedCommit ? [
        $read->tree()->id(),
        array_map(static fn(Revision $parent): string => $parent->name(), [...$read->parents()]),
    ] : $read)->toBe([str_repeat('e', 40), [str_repeat('a', 40), str_repeat('b', 40)]])
        ->and(CommitObject::read(str_repeat('d', 40), "author Gate\n\ntree x\n"))->toBeInstanceOf(CannotTell::class);
});
