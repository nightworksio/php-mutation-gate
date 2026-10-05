<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\GitHub\Markdown;
use NightWorksIO\MutationGate\Adapter\GitHub\MarkdownItems;
use NightWorksIO\MutationGate\Adapter\GitHub\RecheckedMarkdown;
use NightWorksIO\MutationGate\Core\Recheck\Recheck;
use NightWorksIO\MutationGate\Core\Recheck\Rechecked;
use NightWorksIO\MutationGate\Core\Runner\Uncovered;
use NightWorksIO\MutationGate\Core\Verdict\MutantJudgement;
use NightWorksIO\MutationGate\Tests\Support\Rechecks;
use NightWorksIO\MutationGate\Tests\Support\Verdicts;

it('writes the comment between planned and the verdict: how many still survive, each that does in full, and each gone', function (): void {
    $gone = Rechecks::judged('src/Cart.php:4', MutantJudgement::Survived)->mutant()->id()->value();

    expect(RecheckedMarkdown::comment(Rechecks::mixed(), 'https://github.com/octo/gate/actions/runs/7'))->toBe(implode("\n\n", [
        Markdown::MARKER,
        '## mutation-gate: survivors re-checked',
        'Survivors re-checked: 1 of 3 still survives. 1 killed now. 1 gone: the run made no mutant with their id again.',
        '### Still surviving (1)',
        ...MarkdownItems::details([Verdicts::survivor()], 1),
        '### Gone (1)',
        sprintf('- <code>src/Cart.php:4</code> Plus <code>%s</code>', $gone),
        "[The run](https://github.com/octo/gate/actions/runs/7) replaces this with its verdict.\n",
    ]));
});

it('leaves out the lists it has nothing for, and the run where there is none', function (): void {
    $killed = Rechecks::judged('src/Price.php:9', MutantJudgement::Survived);
    $rechecked = Rechecked::of(Uncovered::Count, Recheck::found($killed, Rechecks::judged('src/Price.php:9', MutantJudgement::Killed)));

    expect(RecheckedMarkdown::comment($rechecked, ''))->toBe(implode("\n\n", [
        Markdown::MARKER,
        '## mutation-gate: survivors re-checked',
        "Survivors re-checked: 0 of 1 still survive. 1 killed now.\n",
    ]));
});

it('lists up to 20 of each, then says how many more', function (): void {
    $rechecks = [];

    foreach (range(1, 22) as $line) {
        $survivor = Rechecks::judged(sprintf('src/Money.php:%d', $line), MutantJudgement::Survived);
        $rechecks[] = Recheck::found($survivor, $survivor);
        $rechecks[] = Recheck::gone(Rechecks::judged(sprintf('src/Cart.php:%d', $line), MutantJudgement::Survived));
    }

    $comment = RecheckedMarkdown::comment(Rechecked::of(Uncovered::Count, ...$rechecks), '');

    expect(substr_count($comment, '<details><summary>'))->toBe(20)
        ->and(substr_count($comment, '- <code>src/Cart.php:'))->toBe(20)
        ->and(substr_count($comment, 'And 2 more'))->toBe(2);
});

it('writes a place a ledger holds as text, never as markup or a link', function (): void {
    $hostile = Rechecks::judged('src/<img src=x onerror=alert(1)>`|[a](https://evil.example).php:4', MutantJudgement::Survived);
    $comment = RecheckedMarkdown::comment(Rechecked::of(Uncovered::Count, Recheck::gone($hostile)), '');

    expect($comment)->not->toContain('<img')
        ->and($comment)->not->toContain('](https://evil.example)')
        ->and($comment)->toContain('&lt;img src=x onerror=alert(1)&gt;');
});
