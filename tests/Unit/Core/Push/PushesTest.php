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

it('reads one ref from each line git hands the hook', function (): void {
    $pushes = pushesOf("refs/heads/a 111 refs/heads/a 222\nrefs/tags/v1 111 refs/tags/v1 0000\n");

    expect([...$pushes])->toEqual([
        PushedRef::of('refs/heads/a', '111', '222'),
        PushedRef::of('refs/tags/v1', '111', '0000'),
    ])
        ->and(count(pushesOf('')))->toBe(0)
        ->and(count(pushesOf("\n")))->toBe(0);
});

it('cannot read a line git does not write', function (string $line): void {
    expect(Pushes::read(sprintf("refs/heads/a 111 refs/heads/a 222\n%s\n", $line)))
        ->toEqual(CannotJudge::because(sprintf('Git handed the pre-push hook a line it does not write: "%s".', trim($line))));
})->with(['refs/heads/a 111 refs/heads/a', 'refs/heads/a 111 refs/heads/a 222 extra', 'refs/heads/a  111 refs/heads/a 222']);

it('judges each ref that sends the commit the working tree is at, once for each base, leaving deletions out', function (): void {
    $main = Revision::ref('refs/remotes/origin/main');
    $judged = pushesOf(<<<'GIT'
        refs/heads/a 111 refs/heads/a 222
        refs/heads/b 111 refs/heads/b 222
        refs/heads/c 111 refs/heads/c 0000
        (delete) 0000 refs/heads/gone 333
        refs/heads/d 111 refs/heads/d 000000
        GIT)->judged(Revision::ref('111'), $main);

    expect($judged instanceof Pushes ? [...$judged] : $judged)->toEqual([
        PushedRef::of('refs/heads/a', '111', '222'),
        PushedRef::of('refs/heads/c', '111', '0000'),
    ]);
});

it('cannot judge a ref that sends another commit than the one the working tree is at', function (): void {
    $judged = pushesOf("refs/heads/a 111 refs/heads/a 222\nrefs/heads/other 444 refs/heads/other 222\n")
        ->judged(Revision::ref('111'), Revision::ref('refs/remotes/origin/main'));

    expect($judged)->toEqual(CannotJudge::because(<<<'SAID'
        refs/heads/other is pushed at 444, and the working tree is at 111.
        The gate judges the working tree, so it cannot judge that push. Check out what you push, and push again.
        SAID));
});
