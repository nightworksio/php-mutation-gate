<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\PhpSpec\OwnFiles;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

it('keeps the files under the project\'s root, less those in its vendor directory, in their order', function (): void {
    $own = OwnFiles::under('/p', '/p/vendor');

    expect($own->among(['/p/src/A.php', '/p/vendor/v/B.php', '/elsewhere/C.php', '/project/D.php', '/p/vendors/E.php', '/p/F.php']))
        ->toBe(['/p/src/A.php', '/p/vendors/E.php', '/p/F.php']);
});

it('reads the root and the vendor directory by their real paths, as PHP lists the files it loaded', function (): void {
    $root = Scratch::directory();
    Scratch::write($root, 'project/src/A.php', '<?php');
    Scratch::write($root, 'project/vendor/v/B.php', '<?php');
    $real = (string) realpath(sprintf('%s/project', $root));
    $own = OwnFiles::under(sprintf('%s/project/src/..', $root), sprintf('%s/project/src/../vendor', $root));

    expect($own->among([sprintf('%s/src/A.php', $real), sprintf('%s/vendor/v/B.php', $real)]))->toBe([sprintf('%s/src/A.php', $real)]);
});
