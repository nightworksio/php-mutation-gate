<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Warm\Booted;
use NightWorksIO\MutationGate\Adapter\Pest\Warm\BootStage;
use Pest\Bootstrappers\BootFiles;
use Pest\Bootstrappers\BootKernelDump;

// Booted holds the process's own stage, which only moves forward, so one test
// walks a worker's stages in order, as a booting worker and its child do.
it('skips nothing unbooted, the kernel dump while booting, and all else once forked, keeping each file only while booting, and boots plugins once', function (): void {
    $unbooted = [Booted::stage(), Booted::isForked(), Booted::skips(BootKernelDump::class), Booted::skips(BootFiles::class), Booted::bootsPlugins()];
    Booted::forked();
    $stillUnbooted = Booted::stage();
    Booted::kept('/tests/BeforeSpec.php', 'P\Tests\BeforeSpec');
    Booted::booting();
    Booted::kept('/tests/MoneySpec.php', 'P\Tests\MoneySpec');
    $booting = [Booted::stage(), Booted::isForked(), Booted::skips(BootKernelDump::class), Booted::skips(BootFiles::class), Booted::loaded('/tests/MoneySpec.php'), Booted::bootsPlugins()];
    Booted::leavingPlugins();
    $leaving = Booted::bootsPlugins();
    Booted::forked();
    Booted::kept('/tests/AfterSpec.php', 'P\Tests\AfterSpec');
    $forked = [Booted::stage(), Booted::isForked(), Booted::skips(BootKernelDump::class), Booted::skips(BootFiles::class), Booted::bootsPlugins()];

    expect($unbooted)->toBe([BootStage::Unbooted, false, false, false, true])
        ->and($stillUnbooted)->toBe(BootStage::Unbooted)
        ->and($booting)->toBe([BootStage::Booting, false, true, false, '', true])
        ->and($leaving)->toBeFalse()
        ->and($forked)->toBe([BootStage::Forked, true, false, true, true])
        ->and([Booted::loaded('/tests/MoneySpec.php'), Booted::loaded('/tests/BeforeSpec.php'), Booted::loaded('/tests/AfterSpec.php')])
        ->toBe(['P\Tests\MoneySpec', '', '']);
});
