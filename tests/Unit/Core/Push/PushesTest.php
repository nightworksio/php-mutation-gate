<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Push\PushedRef;
use NightWorksIO\MutationGate\Core\Push\Pushes;

/** The refs these lines name, which the test requires to be read. */
function pushesOf(string $text): Pushes
{
    $pushes = Pushes::read($text);

    return $pushes instanceof Pushes ? $pushes : throw new LogicException($pushes->why());
}

/** A SHA-1 object name of one hex digit, repeated. */
function pushedCommit(string $digit): string
{
    return str_repeat($digit, 40);
}

it('reads one ref from each line git hands the hook, its commits named by SHA-1 or SHA-256', function (): void {
    $sha256 = str_repeat('c', 64);
    $pushes = pushesOf(sprintf(
        "refs/heads/a %s refs/heads/a %s\nrefs/tags/v1 %s refs/tags/v1 %s\n",
        pushedCommit('1'),
        pushedCommit('2'),
        $sha256,
        str_repeat('0', 64),
    ));

    expect([...$pushes])->toEqual([
        PushedRef::of('refs/heads/a', pushedCommit('1'), pushedCommit('2')),
        PushedRef::of('refs/tags/v1', $sha256, str_repeat('0', 64)),
    ])
        ->and(count(pushesOf('')))->toBe(0)
        ->and(count(pushesOf("\n")))->toBe(0);
});

it('cannot read a line that is not a ref, its commit, a ref and its commit', function (string $line): void {
    $read = Pushes::read(sprintf("refs/heads/a %s refs/heads/a %s\n%s\n", pushedCommit('1'), pushedCommit('2'), $line));

    expect($read)->toEqual(CannotJudge::because(sprintf(
        'The pre-push hook was handed a line that is not a local ref, its commit, a remote ref and its commit: "%s".',
        trim($line),
    )));
})->with([
    'three fields' => [fn(): string => sprintf('refs/heads/a %s refs/heads/a', pushedCommit('1'))],
    'five fields' => [fn(): string => sprintf('refs/heads/a %s refs/heads/a %s extra', pushedCommit('1'), pushedCommit('2'))],
    'two spaces' => [fn(): string => sprintf('refs/heads/a  %s refs/heads/a %s', pushedCommit('1'), pushedCommit('2'))],
    'a ref for a commit' => [fn(): string => sprintf('refs/heads/a HEAD refs/heads/a %s', pushedCommit('2'))],
    'a remote commit not in hex' => [fn(): string => sprintf('refs/heads/a %s refs/heads/a base', pushedCommit('1'))],
    'a short commit' => [fn(): string => sprintf('refs/heads/a 111 refs/heads/a %s', pushedCommit('2'))],
    'upper-case hex' => [fn(): string => sprintf('refs/heads/a %s refs/heads/a %s', pushedCommit('A'), pushedCommit('2'))],
    'a commit of 41 digits' => [fn(): string => sprintf('refs/heads/a %s1 refs/heads/a %s', pushedCommit('1'), pushedCommit('2'))],
    'a quoted line' => [fn(): string => sprintf("'refs/heads/a %s refs/heads/a %s'", pushedCommit('1'), pushedCommit('2'))],
]);

it('judges each ref that sends the commit the working tree is at, once for each base, leaving deletions out', function (): void {
    $main = Revision::ref('refs/remotes/origin/main');
    [$head, $remote, $gone] = [pushedCommit('1'), pushedCommit('2'), pushedCommit('3')];
    $none = pushedCommit('0');
    $judged = pushesOf(implode("\n", [
        sprintf('refs/heads/a %s refs/heads/a %s', $head, $remote),
        sprintf('refs/heads/b %s refs/heads/b %s', $head, $remote),
        sprintf('refs/heads/c %s refs/heads/c %s', $head, $none),
        sprintf('(delete) %s refs/heads/gone %s', $none, $gone),
        sprintf('refs/heads/d %s refs/heads/d %s', $head, str_repeat('0', 64)),
    ]))->judged(Revision::ref($head), $main);

    expect($judged instanceof Pushes ? [...$judged] : $judged)->toEqual([
        PushedRef::of('refs/heads/a', $head, $remote),
        PushedRef::of('refs/heads/c', $head, $none),
    ]);
});

it('cannot judge a ref that sends another commit than the one the working tree is at', function (): void {
    [$head, $remote, $other] = [pushedCommit('1'), pushedCommit('2'), pushedCommit('4')];
    $judged = pushesOf(sprintf(
        "refs/heads/a %s refs/heads/a %s\nrefs/heads/other %s refs/heads/other %s\n",
        $head,
        $remote,
        $other,
        $remote,
    ))->judged(Revision::ref($head), Revision::ref('refs/remotes/origin/main'));

    expect($judged)->toEqual(CannotJudge::because(sprintf(<<<'SAID'
        refs/heads/other is pushed at %s, and the working tree is at %s.
        The gate judges the working tree, so it cannot judge that push. Check out what you push, and push again.
        SAID, $other, $head)));
});
