<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Verdict\Uncounted;

it('says why a unit the budget never started fails the verdict, and what judges it', function (Uncounted $why, string $said): void {
    expect($why->said(Path::of('src/Money.php')))->toBe(sprintf(
        "src/Money.php is unjudged: the time budget ran out before this run mutated it.\n%s More time judges it: vendor/bin/mutation-gate run --budget=<duration>",
        $said,
    ));
})->with([
    'no result' => [Uncounted::NoResult, 'No ledger holds a result of it to count.'],
    'no digests' => [Uncounted::NoDigests, 'Its newest result records no digests of its inputs to say it is this code\'s.'],
    'other source' => [Uncounted::SourceChanged, 'Its newest result is of other source, so its mutants are not this code\'s.'],
    'another mutant set' => [Uncounted::MutationChanged, 'Its newest result was made with another gate, config, runner or runner setup.'],
]);
