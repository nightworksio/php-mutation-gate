<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Report\Term;

it('says what each term means as one plain line', function (): void {
    foreach (Term::cases() as $term) {
        expect($term->meaning())->not->toContain("\n")
            ->and($term->meaning())->toEndWith('.')
            ->and($term->meaning())->not->toContain('  ');
    }
});

it('says what mutation testing is, and what a floor is, in plain English', function (): void {
    expect(Term::MutationTesting->meaning())->toBe('Mutation testing checks your tests, not your code: the gate makes small changes to your code, called mutants, and runs your tests against each one. A good test suite fails on every change.')
        ->and(Term::Floor->meaning())->toBe('The lowest score a part of the code may have. A run fails when a score drops below its floor, and the floor rises as the score does.');
});
