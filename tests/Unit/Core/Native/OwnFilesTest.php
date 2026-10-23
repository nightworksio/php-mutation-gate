<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Native\OwnFiles;

it('keeps the files under the project\'s root, less those in its vendor directory, in their order', function (): void {
    $own = OwnFiles::under('/p', '/p/vendor');

    expect($own->among(['/p/src/A.php', '/p/vendor/v/B.php', '/elsewhere/C.php', '/project/D.php', '/p/vendors/E.php', '/p/F.php']))
        ->toBe(['/p/src/A.php', '/p/vendors/E.php', '/p/F.php']);
});

it('reads the root and the vendor directory with each `..` taken back, as PHP lists the files it loaded', function (): void {
    $own = OwnFiles::under('/p/library/..', '/p/library/../vendor');

    expect($own->among(['/p/src/A.php', '/p/vendor/v/B.php', '/q/C.php']))->toBe(['/p/src/A.php']);
});
