<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Change\Commit;
use NightWorksIO\MutationGate\Core\Change\Revision;

it('reads a full commit id in either of git\'s object formats', function (string $id): void {
    $commit = Commit::parse($id);

    expect($commit instanceof Commit ? $commit->id() : '')->toBe($id)
        ->and($commit instanceof Commit ? $commit->revision() : null)->toEqual(Revision::ref($id));
})->with([
    'SHA-1' => [fn(): string => str_repeat('a1', 20)],
    'SHA-256' => [fn(): string => str_repeat('b2', 32)],
]);

it('says why what a file holds is no commit id', function (string $id): void {
    expect(Commit::parse($id))->toEqual(CannotTell::because(sprintf('"%s" is not the full id of a commit.', $id)));
})->with([
    'an option git would read' => ['--output=/tmp/x'],
    'a branch' => ['main'],
    'abbreviated' => ['5eeca8f'],
    'uppercase' => [fn(): string => str_repeat('A1', 20)],
    'a line break after it' => [fn(): string => sprintf("%s\n", str_repeat('a1', 20))],
    'between the formats' => [fn(): string => str_repeat('a', 50)],
]);
