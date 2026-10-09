<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Push\PreCommitPush;

it('gives git\'s line for the push the pre-commit framework names', function (Variables $set, string $line): void {
    $head = Revision::ref(str_repeat('1', 40));

    expect(PreCommitPush::line($set, $head))->toBe($line);
})->with([
    'the commits and the refs' => [
        Variables::of([
            'PRE_COMMIT_FROM_REF' => str_repeat('2', 40),
            'PRE_COMMIT_TO_REF' => str_repeat('3', 40),
            'PRE_COMMIT_LOCAL_BRANCH' => 'refs/heads/feature',
            'PRE_COMMIT_REMOTE_BRANCH' => 'refs/heads/topic',
        ]),
        sprintf('refs/heads/feature %s refs/heads/topic %s', str_repeat('3', 40), str_repeat('2', 40)),
    ],
    'the commits alone' => [
        Variables::of(['PRE_COMMIT_FROM_REF' => str_repeat('2', 40), 'PRE_COMMIT_TO_REF' => str_repeat('3', 40)]),
        sprintf('HEAD %s HEAD %s', str_repeat('3', 40), str_repeat('2', 40)),
    ],
    'the refs alone, a history the remote holds none of' => [
        Variables::of(['PRE_COMMIT_LOCAL_BRANCH' => 'refs/heads/main', 'PRE_COMMIT_REMOTE_BRANCH' => 'refs/heads/main']),
        sprintf('refs/heads/main %s refs/heads/main %s', str_repeat('1', 40), str_repeat('0', 40)),
    ],
    'one commit' => [Variables::of(['PRE_COMMIT_TO_REF' => str_repeat('3', 40)]), ''],
    'the other commit' => [Variables::of(['PRE_COMMIT_FROM_REF' => str_repeat('2', 40)]), ''],
    'a remote ref alone' => [Variables::of(['PRE_COMMIT_REMOTE_BRANCH' => 'refs/heads/main']), ''],
    'empty values' => [
        Variables::of(['PRE_COMMIT_FROM_REF' => '', 'PRE_COMMIT_TO_REF' => '', 'PRE_COMMIT_LOCAL_BRANCH' => '']),
        '',
    ],
    'nothing' => [Variables::of([]), ''],
]);

it('names no commit as long as the working tree\'s commit is named', function (): void {
    $head = Revision::ref(str_repeat('a', 64));
    $environment = Variables::of(['PRE_COMMIT_LOCAL_BRANCH' => 'refs/heads/main']);

    expect(PreCommitPush::line($environment, $head))
        ->toBe(sprintf('refs/heads/main %s HEAD %s', str_repeat('a', 64), str_repeat('0', 64)));
});
