<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Tests\Support\Rules;

// R1: ARCHITECTURE.md against the code, in both directions. Every artifact that
// enforces a rule carries its identifier: an arch test beside its expectation,
// a PHPStan rule in its message, configuration in a comment.

/**
 * The rules whose claimed kind of mechanism does not carry them.
 *
 * @param  array<string, string>       $claims
 * @param  array<string, list<string>> $kinds
 * @return list<string>
 */
function rulesNotWhereTheyClaim(array $claims, array $kinds): array
{
    $wrong = [];

    foreach ($claims as $id => $claim) {
        foreach ($kinds as $kind => $sources) {
            if (Rules::claimsTheKind($claim, $kind) && ! Rules::carriesTheRule($id, $sources)) {
                $wrong[] = sprintf('%s claims "%s" and nothing under %s carries it', $id, $claim, $kind);
            }
        }
    }

    return $wrong;
}

it('enforces every rule the architecture documents', function (): void {
    $unenforced = [];

    foreach (array_keys(Rules::documented()) as $id) {
        if (! Rules::carriesTheRule($id, Rules::enforcementSources())) {
            $unenforced[] = $id;
        }
    }

    expect($unenforced)->toBe([], sprintf(
        "ARCHITECTURE.md documents these and nothing carries their identifier:\n  %s\n\nWrite the rule and tag it with its identifier, or take the row out.",
        implode(', ', $unenforced),
    ));
});

it('enforces every rule by the kind of mechanism it claims', function (): void {
    expect(rulesNotWhereTheyClaim(Rules::documented(), Rules::whereEachKindLives()))->toBe([]);
});

it('finds a rule that claims a mechanism nothing of that kind carries', function (): void {
    $kinds = ['arch' => ['// A1'], 'phpstan' => ['B1 — time']];

    expect(rulesNotWhereTheyClaim(['A1' => 'arch', 'B1' => 'phpstan', 'C1' => 'arch: reflection'], $kinds))
        ->toBe(['C1 claims "arch: reflection" and nothing under arch carries it']);
});

it('reads a claimed mechanism as a word, not as part of another', function (): void {
    expect(Rules::claimsTheKind('arch: over every file', 'arch'))->toBeTrue()
        ->and(Rules::claimsTheKind('phpstan: own rule + arch', 'arch'))->toBeTrue()
        ->and(Rules::claimsTheKind('the architecture of a file', 'arch'))->toBeFalse()
        ->and(Rules::claimsTheKind('the @phpstan-type line', 'phpstan'))->toBeFalse();
});

it('reads an identifier bounded, so H1 is not found in H10', function (): void {
    expect(Rules::carriesTheRule('H1', ['(H10)']))->toBeFalse()
        ->and(Rules::carriesTheRule('H1', ['(H1, C9)']))->toBeTrue()
        ->and(Rules::carriesTheRule('R1', ['GOV-R1']))->toBeFalse();
});

it('documents every rule the codebase enforces', function (): void {
    $documented = array_keys(Rules::documented());
    $undocumented = [];

    foreach (Rules::enforcementSources() as $source) {
        preg_match_all('/(?<![-A-Za-z0-9])([A-Z]\d{1,2})(?=\s*(?:—|\)|,\s*[A-Z]\d))/u', $source, $found);

        foreach ($found[1] as $id) {
            if (! in_array($id, $documented, strict: true)) {
                $undocumented[$id] = $id;
            }
        }
    }

    // R1
    expect(array_values($undocumented))->toBe([], sprintf(
        "These identifiers are enforced somewhere and appear in no rule table of ARCHITECTURE.md:\n  %s",
        implode(', ', $undocumented),
    ));
});

it('reads rules out of a table and refuses a document with none', function (): void {
    expect(Rules::read("| **A1** | says | arch |\n| Rule | Says | Enforced by |"))->toBe(['A1' => 'arch'])
        ->and(static fn(): array => Rules::read('# no tables'))->toThrow(RuntimeException::class);
});
