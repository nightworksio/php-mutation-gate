<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Extension\LibraryChange;
use NightWorksIO\MutationGate\Tests\Contract\Runner\Library;
use NightWorksIO\MutationGate\Tests\Support\Tree;

/** The shipped library's code, where a runner's author copies it from. */
const SHIPPED_LIBRARY = 'resources/contract/runner';

it('names each change on the line of the shipped library that it changes', function (): void {
    foreach (LibraryChange::cases() as $change) {
        $lines = file(Tree::at(sprintf('%s/%s', SHIPPED_LIBRARY, $change->file()->value())), FILE_IGNORE_NEW_LINES);

        expect(trim(is_array($lines) ? $lines[$change->line() - 1] : ''))->toBe($change->removed())
            ->and($change->diff())->toBe(sprintf("@@ @@\n-%s\n+%s", $change->removed(), $change->added()));
    }
});

it('says what every runner reports each change as, and the family of its mutator', function (): void {
    expect(array_map(static fn(LibraryChange $change): array => [$change->status(), $change->family()], LibraryChange::cases()))->toBe([
        [MutantStatus::Killed, MutatorFamily::Arithmetic],
        [MutantStatus::Survived, MutatorFamily::Boundary],
        [MutantStatus::Uncovered, MutatorFamily::Arithmetic],
        [MutantStatus::TimedOut, MutatorFamily::Arithmetic],
        [MutantStatus::Survived, MutatorFamily::Arithmetic],
    ]);
});

it('holds the shipped library\'s code in every runner fixture, byte for byte', function (string $library): void {
    foreach (['src/Money.php', 'src/Held.php'] as $file) {
        expect(file_get_contents(Tree::at(sprintf('%s/%s', $library, $file))))
            ->toBe(file_get_contents(Tree::at(sprintf('%s/%s', SHIPPED_LIBRARY, $file))));
    }
})->with([
    'Pest' => fn(): string => Library::DIRECTORY,
    'Infection' => fn(): string => Library::INFECTION_DIRECTORY,
    'PHPUnit' => fn(): string => Library::PHPUNIT_DIRECTORY,
    'PhpSpec' => fn(): string => Library::PHPSPEC_DIRECTORY,
    'Codeception' => fn(): string => Library::CODECEPTION_DIRECTORY,
]);
