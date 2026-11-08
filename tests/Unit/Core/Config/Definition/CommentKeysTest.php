<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Comment\Detail;
use NightWorksIO\MutationGate\Core\Comment\Lines;
use NightWorksIO\MutationGate\Core\Comment\Section;
use NightWorksIO\MutationGate\Core\Config\CommentSettings;
use NightWorksIO\MutationGate\Core\Config\CommentShows;
use NightWorksIO\MutationGate\Core\Config\CommentWrites;
use NightWorksIO\MutationGate\Core\Config\Definition\CommentKeys;
use NightWorksIO\MutationGate\Core\Config\Listed;
use NightWorksIO\MutationGate\Core\Config\Options;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Tests\Support\Configs;
use NightWorksIO\MutationGate\Tests\Support\Decoded;

it('lays the run\'s comment under a comment reporter\'s own with.comment, and leaves any other reporter\'s options as they are', function (
    string $use,
    bool $laid,
): void {
    $options = Options::of(Json::object(Member::of('path', 'build/out')));
    $comment = CommentSettings::of(CommentShows::of(open: Listed::of(Section::Survivors)), CommentWrites::of());
    $shaped = CommentKeys::forReporter($use, $options, $comment)->written()->line();

    expect(Decoded::at($shaped, 'path'))->toBe('build/out')
        ->and(Decoded::at($shaped, 'comment', 'open'))->toBe($laid ? ['survivors'] : null);
})->with([
    'the GitHub comment' => ['github-comment', true],
    'the Buildkite annotation' => ['buildkite-annotation', true],
    'the JSON report' => ['json', false],
    'the Azure summary' => ['azure-summary', false],
    'an extension\'s reporter' => ['Acme\\Reporter', false],
]);

it('reads how the survivors are listed, and refuses those keys under any other list', function (): void {
    $shows = Configs::settings(['runner' => 'pest', 'comment' => ['survivors' => ['lines' => 'all', 'detail' => 'row']]])
        ->effective()->reports()->comment()->shows();

    expect([$shows->lines(), $shows->detail()])->toBe([Lines::All, Detail::Row])
        ->and(Configs::problems(Configs::validated(['runner' => 'pest', 'comment' => ['security' => ['lines' => 'all']]])))->not->toBe([]);
});

it('takes an empty list of open sections, and refuses an empty list of states or occasions', function (): void {
    $folded = Configs::settings(['runner' => 'pest', 'comment' => ['open' => []]])->effective()->reports()->comment()->shows();

    expect($folded->isOpen(Section::Verdict))->toBeFalse()
        ->and(Configs::problems(Configs::validated(['runner' => 'pest', 'comment' => ['states' => []]])))->not->toBe([])
        ->and(Configs::problems(Configs::validated(['runner' => 'pest', 'comment' => ['on' => []]])))->not->toBe([]);
});
