<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Tests\Support\Tree;

// K1, over every comment in src, tests and phpstan: the words that tell a
// history or make a promise. What else a comment gets wrong is for review.

/** The phrases that narrate what used to be or promise what will be. */
const HISTORY_AND_PROMISE = [
    'used to be',
    'previously',
    'formerly',
    'was changed',
    'for now',
    'in the future',
    'todo',
    'fixme',
    'xxx',
];

/** The files that hold these phrases in order to refuse them. */
const THAT_HOLD_THE_PHRASES = ['tests/Arch/CommentsTest.php', 'tests/Support/Fixtures.php'];

it('keeps every comment to what is true now', function (): void {
    $offenders = [];
    $read = 0;

    foreach ([...Tree::filesUnder('src'), ...Tree::filesUnder('tests'), ...Tree::filesUnder('phpstan')] as $path) {
        if (in_array($path, THAT_HOLD_THE_PHRASES, strict: true)) {
            continue;
        }

        foreach (token_get_all((string) file_get_contents(Tree::at($path))) as $token) {
            if (! is_array($token) || ! in_array($token[0], [T_COMMENT, T_DOC_COMMENT], strict: true)) {
                continue;
            }

            $read++;

            foreach (HISTORY_AND_PROMISE as $phrase) {
                if (preg_match(sprintf('/\b%s\b/iu', preg_quote($phrase, '/')), $token[1]) === 1) {
                    $offenders[] = sprintf('%s:%d says "%s"', $path, $token[2], $phrase);
                }
            }
        }
    }

    expect($read)->toBeGreaterThan(0, 'no comment was read, so none was judged');

    // K1
    expect($offenders)->toBe([], sprintf(
        "These comments tell a history or make a promise:\n  %s\n\nA comment is read as a description of the code beside it. Say what is true now, and put history in the commit message (K1).",
        implode("\n  ", $offenders),
    ));
});
