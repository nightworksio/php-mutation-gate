<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\GitHub\OwnerMentions;
use NightWorksIO\MutationGate\Core\Owner\OwnerGroup;
use NightWorksIO\MutationGate\Core\Owner\OwnerMention;
use NightWorksIO\MutationGate\Tests\Support\Owners;

$heading = static fn(string ...$owners): string => sprintf(
    '#### %s (1)',
    OwnerMentions::headings(Owners::verdicts()->mentioning(OwnerMention::First))(
        OwnerGroup::of(...array_map(Owners::owner(...), $owners)),
    ),
);

it('heads a group with a placeholder for each user and team and each email address as text, under first', function () use ($heading): void {
    $first = OwnerMentions::headings(Owners::verdicts()->mentioning(OwnerMention::First));
    $never = OwnerMentions::headings(Owners::verdicts());

    expect($heading('@acme/payments', 'jo@acme.example', '@jo'))->toBe(
        '#### <!-- mutation-gate: mention @acme/payments --> jo&#64;acme.example <!-- mutation-gate: mention @jo --> (1)',
    )
        ->and($first(OwnerGroup::unowned()))->toBe('unowned')
        ->and($never(OwnerGroup::of(Owners::owner('@acme/payments'), Owners::owner('@jo'))))
        ->toBe('&#64;acme/payments &#64;jo');
});

it('mentions each user and team the first time, and records them at the end', function () use ($heading): void {
    $markdown = sprintf("## mutation-gate: failed\n\n%s\n\nbody\n", $heading('@acme/payments', '@jo'));

    expect(OwnerMentions::in('', teams: true)->into($markdown))->toBe(implode("\n", [
        '## mutation-gate: failed',
        '',
        '#### @acme/payments @jo (1)',
        '',
        'body',
        '<!-- mutation-gate: mentioned @acme/payments @jo -->',
        '',
    ]));
});

it('names an owner the record holds without a mention, in any case, and adds only the new ones to it', function () use ($heading): void {
    $before = "old\n<!-- mutation-gate: mentioned @ACME/payments -->\n";
    $markdown = sprintf("%s\n%s\n", $heading('@acme/payments', '@jo'), $heading('@jo'));

    expect(OwnerMentions::in($before, teams: true)->into($markdown))->toBe(implode("\n", [
        '#### &#64;acme/payments @jo (1)',
        '#### &#64;jo (1)',
        '<!-- mutation-gate: mentioned @ACME/payments @jo -->',
        '',
    ]));
});

it('names a team without a mention, and records none, where the comment\'s author cannot mention a team', function () use ($heading): void {
    expect(OwnerMentions::in('', teams: false)->into(sprintf("%s\n", $heading('@acme/payments', '@jo'))))
        ->toBe("#### &#64;acme/payments @jo (1)\n<!-- mutation-gate: mentioned @jo -->\n");
});

it('keeps the record through an update that names no owner', function (): void {
    expect(OwnerMentions::in("x\n<!-- mutation-gate: mentioned @jo -->\n", teams: true)->into("## planned\n"))
        ->toBe("## planned\n<!-- mutation-gate: mentioned @jo -->\n")
        ->and(OwnerMentions::in("x\n<!-- mutation-gate: mentioned @jo -->", teams: true)->into('## planned'))
        ->toBe("## planned\n<!-- mutation-gate: mentioned @jo -->\n")
        ->and(OwnerMentions::in('', teams: true)->into("## planned\n"))->toBe("## planned\n");
});

it('reads the last record a body holds, and only users and teams from it', function (): void {
    $before = implode("\n", [
        '~~~',
        '<!-- mutation-gate: mentioned @jo -->',
        '~~~',
        '<!-- mutation-gate: mentioned @kim jo@acme.example nobody -->',
    ]);

    expect(OwnerMentions::in($before, teams: true)->into("#### <!-- mutation-gate: mention @jo --> (1)\n"))
        ->toBe("#### @jo (1)\n<!-- mutation-gate: mentioned @kim @jo -->\n");
});

it('leaves a placeholder anywhere but on a heading as it is, mentioning and recording no one', function (): void {
    $markdown = "~~~diff\n+ <!-- mutation-gate: mention @jo -->\n~~~\n";

    expect(OwnerMentions::in('', teams: true)->into($markdown))->toBe($markdown);
});
