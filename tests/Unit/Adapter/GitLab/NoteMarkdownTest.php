<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\GitLab\NoteMarkdown;
use NightWorksIO\MutationGate\Core\Report\Escape;
use NightWorksIO\MutationGate\Core\Report\Markdown;

it('links the pipeline where the comment holds the line of the HTML report', function (): void {
    expect(NoteMarkdown::of(sprintf("before\n\n%s\n", Markdown::REPORT_SLOT), 'https://gitlab.example/p/-/pipelines/9'))
        ->toBe(sprintf("before\n\n%s\n", Markdown::runLine('https://gitlab.example/p/-/pipelines/9')));
});

it('stands each reference the project wrote in an element of its own, so GitLab mentions and links no one', function (): void {
    $escaped = Escape::text('ping @team about #12, !3 and ~bug, and `@code`');

    expect(NoteMarkdown::of($escaped, ''))->toBe(
        'ping <span>&#64;</span>team about <span>&#35;</span>12, <span>&#33;</span>3 and <span>&#126;</span>bug, '
        . 'and <code><span>&#64;</span>code</code>',
    );
});

it('leaves the markup the gate writes itself as it is', function (): void {
    $comment = sprintf("%s\n## mutation-gate: failed\n\n<details><summary>What this run cost</summary>\n\n</details>\n", Markdown::MARKER);

    expect(NoteMarkdown::of($comment, ''))->toBe($comment);
});
