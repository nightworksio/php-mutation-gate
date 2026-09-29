<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Proof\NotRecorded;

it('says why a result is not kept as a proof', function (): void {
    expect(NotRecorded::because('src/A.php did not run to the end.')->why())->toBe('src/A.php did not run to the end.');
});
