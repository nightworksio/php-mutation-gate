<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Bitbucket\CommentMarkdown;
use NightWorksIO\MutationGate\Core\Report\Escape;
use NightWorksIO\MutationGate\Core\Report\Markdown;
use NightWorksIO\MutationGate\Core\Report\PlannedMarkdown;
use NightWorksIO\MutationGate\Tests\Support\ShardedPlan;
use NightWorksIO\MutationGate\Tests\Support\Verdicts;

it('writes the marker as a link reference definition, which Bitbucket shows as nothing', function (): void {
    expect(CommentMarkdown::of(sprintf("%s\n\n## mutation-gate: passed\n", Markdown::MARKER), ''))
        ->toBe("[//]: # (mutation-gate)\n\n## mutation-gate: passed\n");
});

it('links the pipeline where the comment holds the line of the HTML report', function (): void {
    expect(CommentMarkdown::of(Markdown::REPORT_SLOT, 'https://bitbucket.org/acme/shop/pipelines/results/9'))
        ->toBe(Markdown::runLine('https://bitbucket.org/acme/shop/pipelines/results/9'));
});

it('shows a folded block as its summary in bold, with everything under it', function (): void {
    expect(CommentMarkdown::of("<details><summary>Units (2)</summary>\n\n- a\n\n</details>\n", ''))
        ->toBe("**Units (2)**\n\n- a\n\n\n");
});

it('writes inline code as a code span of its text, fenced by more backticks than it holds', function (string $code, string $span): void {
    expect(CommentMarkdown::of(sprintf('run %s now', Escape::code($code)), ''))->toBe(sprintf('run %s now', $span));
})->with([
    'plain' => ['vendor/bin/mutation-gate reproduce 3f9a', '` vendor/bin/mutation-gate reproduce 3f9a `'],
    'markup and a mention' => ['<b>@team</b> | *x*', '` <b>@team</b> | *x* `'],
    'quotes' => ['it\'s "x"', '` it\'s "x" `'],
    'a backtick' => ['a`b', '`` a`b ``'],
    'a run of backticks' => ['```', '```` ``` ````'],
]);

it('keeps every other entity the escaping wrote, which Bitbucket shows as its character and reads as no mention', function (): void {
    expect(CommentMarkdown::of(Escape::text('ping @{557058:abc} and [x](y)'), ''))
        ->toBe('ping &#64;{557058:abc} and &#91;x&#93;(y)');
});

it('leaves no raw HTML in the comments the gate writes', function (string $comment): void {
    expect(CommentMarkdown::of($comment, 'https://bitbucket.org/acme/shop/pipelines/results/9'))->not->toMatch('/<\/?(details|summary|code)>|<!--/');
})->with([
    'the verdict' => [fn(): string => Markdown::comment(Verdicts::failing())],
    'the planned state' => [fn(): string => PlannedMarkdown::comment(ShardedPlan::planned(30), 'https://bitbucket.org/acme/shop/pipelines/results/9')],
]);
