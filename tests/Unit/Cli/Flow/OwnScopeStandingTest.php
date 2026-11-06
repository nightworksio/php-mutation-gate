<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Flow\OwnScopeStanding;
use NightWorksIO\MutationGate\Core\Reach\Reason;
use NightWorksIO\MutationGate\Core\Reach\Reasons;

it('says how many units the scope\'s earlier runs stand for after the reasons, and nothing where they stand for none', function (): void {
    $reasons = Reasons::of(Reason::that('src/A.php changed, so its unit is reached.'));
    $said = array_map(static fn(Reason $reason): string => $reason->text(), [...OwnScopeStanding::said($reasons, 3)]);

    expect($said)->toBe([
        'src/A.php changed, so its unit is reached.',
        "This scope's earlier runs stand for 3 units whose code and tests have not moved since,\nso they are not mutated again. Set proofs.ownScope to false to run them again.",
    ])
        ->and([...OwnScopeStanding::said($reasons, 1)][1]->text())
        ->toStartWith("This scope's earlier runs stand for 1 unit whose code")
        ->and(OwnScopeStanding::said($reasons, 0))->toEqual($reasons);
});
