<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Pruning\MutatorWindow;
use NightWorksIO\MutationGate\Core\Pruning\Outcome;
use NightWorksIO\MutationGate\Core\Pruning\Window;

it('keeps the newest outcomes up to its window, and the last mutant that let the change through', function (): void {
    $keep = Window::of(3);
    $window = MutatorWindow::of('Plus')
        ->after(Outcome::through('Plus', 'a'), $keep)
        ->after(Outcome::killed('Plus', 'b'), $keep)
        ->after(Outcome::killed('Plus', 'c'), $keep)
        ->after(Outcome::killed('Plus', 'd'), $keep);

    expect($window->outcomes())->toBe('000')
        ->and($window->last())->toBe('a')
        ->and($window->mutator())->toBe('Plus');
});

it('is clean only where its newest mutants fill the window and none let the change through', function (string $outcomes, int $size, bool $clean): void {
    $window = MutatorWindow::written('Plus', $outcomes, NotGiven::value());

    expect($window instanceof MutatorWindow && $window->isClean(Window::of($size)))->toBe($clean);
})->with([
    'full and clean' => ['000', 3, true],
    'a survivor among the newest' => ['010', 3, false],
    'a survivor older than the window' => ['1000', 3, true],
    'too few to fill it' => ['00', 3, false],
    'no window' => ['0', 0, false],
]);

it('reads only outcomes written as 0s and 1s', function (string $outcomes, bool $read): void {
    expect(MutatorWindow::written('Plus', $outcomes, NotGiven::value()) instanceof MutatorWindow)->toBe($read);
})->with([
    'nothing yet' => ['', true],
    'kills and survivors' => ['0101', true],
    'another digit' => ['012', false],
    'a line end after them' => ["01\n", false],
]);

it('names nobody where no mutant ever let the change through', function (): void {
    expect(MutatorWindow::of('Plus')->after(Outcome::killed('Plus', 'a'), Window::of(5))->last())->toBeInstanceOf(NotGiven::class);
});
