<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Recording\Naming;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\Off;
use NightWorksIO\MutationGate\Tests\Support\Naming\AbstractNamedTest;
use NightWorksIO\MutationGate\Tests\Support\Naming\NamedPestTest;
use NightWorksIO\MutationGate\Tests\Support\Naming\NamedPhpUnitTest;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\Tree;

afterEach(function (): void {
    Scratch::sweep();
});

it('names nothing where the adapter named no file', function (): void {
    expect(Naming::to(file: false))->toBe(Off::NamingTests)
        ->and(Naming::to(''))->toBe(Off::NamingTests)
        ->and(Naming::to('/work/names.json'))->toBeInstanceOf(Naming::class);
});

it('names a Pest test by its TestDox and built file, and a PHPUnit test by its method and class file', function (): void {
    $file = sprintf('%s/names.json', Scratch::directory());
    $naming = Naming::to($file);

    if ($naming instanceof Naming) {
        $naming->write([NamedPestTest::class, NamedPhpUnitTest::class, AbstractNamedTest::class, stdClass::class, 'Gone\\Missing']);
    }

    expect(json_decode((string) file_get_contents($file), associative: true))->toBe([
        [
            'test' => sprintf('%s::__pest_evaluable_it_adds', NamedPestTest::class),
            'file' => '/work/tests/MoneySpec.php',
            'description' => 'it adds',
        ],
        [
            'test' => sprintf('%s::__pest_evaluable_it_subtracts', NamedPestTest::class),
            'file' => '/work/tests/MoneySpec.php',
            'description' => '__pest_evaluable_it_subtracts',
        ],
        [
            'test' => sprintf('%s::testAdds', NamedPhpUnitTest::class),
            'file' => Tree::at('tests/Support/Naming/NamedPhpUnitTest.php'),
            'description' => 'testAdds',
        ],
        [
            'test' => sprintf('%s::subtracts', NamedPhpUnitTest::class),
            'file' => Tree::at('tests/Support/Naming/NamedPhpUnitTest.php'),
            'description' => 'subtracts',
        ],
    ]);
});
