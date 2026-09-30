<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\PhpUnit\Installed;
use NightWorksIO\MutationGate\Tests\Support\Tree;

// The runner contracts job installs the PHPUnit runner's library at the lowest
// PHPUnit its manifest allows, so the manifest's lower bound is the runner's
// floor: a floor raised without the library proves nothing at the old one, and
// a bound lowered below it proves a PHPUnit the runner refuses.

it('allows no PHPUnit below the runner\'s floor in the PHPUnit runner\'s library, and every one from it', function (): void {
    $manifest = json_decode((string) file_get_contents(Tree::at('tests/Contract/Runner/phpunit-fixture/composer.json')), associative: true);

    expect($manifest)->toHaveKey('require.phpunit/phpunit', sprintf('^%s', Installed::FLOOR));
});
