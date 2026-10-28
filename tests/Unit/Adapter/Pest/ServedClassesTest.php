<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\GateVariable;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\Recorder;
use NightWorksIO\MutationGate\Adapter\Pest\ServedClasses;
use NightWorksIO\MutationGate\Tests\Support\Environment;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\ServedBox;

afterEach(function (): void {
    Scratch::sweep();
});

it('serves the copy of a class file Composer autoloads in its place, its __FILE__ and __DIR__ the original\'s', function (): void {
    $box = ServedBox::registered();

    try {
        expect(ServedClasses::serve($box->file, $box->copy))->toBeTrue()
            ->and($box->read('SIZE'))->toBe('2')
            ->and($box->read('DIRECTORY'))->toBe(dirname($box->file))
            ->and($box->read('FILE'))->toBe($box->file)
            ->and(file_get_contents(sprintf('%s.served.php', $box->copy)))->toContain(var_export($box->file, return: true));
    } finally {
        $box->loader->unregister();
    }
});

it('names the served copy as loaded in the original\'s place, and the original loaded by its path as bypassing it', function (): void {
    $box = ServedBox::registered();
    $served = sprintf('%s.served.php', $box->copy);

    try {
        $before = [ServedClasses::inPlaceOf($box->file), ServedClasses::bypassed([$box->file])];
        ServedClasses::serve($box->file, $box->copy);
        class_exists(sprintf('%s\\Elsewhere', substr($box->class, 0, -4)));

        expect($before)->toBe([$box->file, false])
            ->and(class_exists($box->class, autoload: false))->toBeFalse()
            ->and(ServedClasses::inPlaceOf(sprintf('%s/../src/Box.php', dirname($box->file))))->toBe($served)
            ->and(ServedClasses::inPlaceOf('/nowhere/Box.php'))->toBe('/nowhere/Box.php')
            ->and(ServedClasses::bypassed([$served, $box->file]))->toBeTrue()
            ->and(ServedClasses::bypassed([$served]))->toBeFalse();
    } finally {
        $box->loader->unregister();
    }
});

it('leaves to the wrapper a file Composer does not autoload, one whose class is loaded already, one that declares none, or one that is gone', function (): void {
    $box = ServedBox::registered();
    $root = dirname($box->file, 2);
    Scratch::write($root, 'src/helpers.php', "<?php\nfunction served_helper(): int { return 1; }\n");
    Scratch::write($root, 'src/Unloaded.php', "<?php\nnamespace Elsewhere;\nfinal class Unloaded {}\n");
    Scratch::write($root, 'src/Twin.php', sprintf("<?php\nnamespace %s;\nfinal class Box {}\n", substr($box->class, 0, -4)));
    mkdir(sprintf('%s/mutations/unwritable.php.served.php', $root));
    copy($box->copy, sprintf('%s/mutations/unwritable.php', $root));
    set_error_handler(static fn(): bool => true);
    $unwritten = ServedClasses::serve($box->file, sprintf('%s/mutations/unwritable.php', $root));
    restore_error_handler();

    $raised = [];
    set_error_handler(static function (int $level, string $message) use (&$raised): bool {
        $raised[] = $message;

        return true;
    });
    $gone = [
        ServedClasses::serve(sprintf('%s/src/Gone.php', $root), $box->copy),
        ServedClasses::serve(sprintf('%s/src', $root), $box->copy),
        ServedClasses::serve($box->file, sprintf('%s/mutations/gone.php', $root)),
    ];
    restore_error_handler();

    try {
        expect(ServedClasses::serve(sprintf('%s/src/helpers.php', $root), $box->copy))->toBeFalse()
            ->and(ServedClasses::serve(sprintf('%s/src/Unloaded.php', $root), $box->copy))->toBeFalse()
            ->and($gone)->toBe([false, false, false])
            ->and($raised)->toBe([])
            ->and(ServedClasses::serve(sprintf('%s/src/Twin.php', $root), $box->copy))->toBeFalse()
            ->and($unwritten)->toBeFalse()
            ->and(ServedClasses::inPlaceOf($box->file))->toBe($box->file);

        class_exists($box->class);

        expect(ServedClasses::serve($box->file, $box->copy))->toBeFalse()
            ->and($box->read('SIZE'))->toBe('1');
    } finally {
        $box->loader->unregister();
    }
});

it('leaves to the wrapper a file whose interface or trait is loaded already', function (string $kind): void {
    $box = ServedBox::registered($kind);

    try {
        expect(interface_exists($box->class) || trait_exists($box->class))->toBeTrue()
            ->and(ServedClasses::serve($box->file, $box->copy))->toBeFalse();
    } finally {
        $box->loader->unregister();
    }
})->with([
    'an interface' => ['interface'],
    'a trait' => ['trait'],
]);

it('serves the copy pest-plugin-mutate names unless the mutant\'s mutator is pinned by source, which the wrapper serves', function (): void {
    $pinned = ServedBox::registered();
    $served = ServedBox::registered();
    $named = static fn(ServedBox $box, string $pin): array => [
        Recorder::MUTANT => $box->file,
        Recorder::MUTATED => $box->copy,
        GateVariable::SourcePinned->value => $pin,
    ];

    try {
        $declined = Environment::during($named($pinned, '1'), static fn(): ArrayObject => new ArrayObject([ServedClasses::fromEnvironment()]));
        $accepted = Environment::during($named($served, ''), static fn(): ArrayObject => new ArrayObject([ServedClasses::fromEnvironment()]));

        expect($declined->getArrayCopy())->toBe([false])
            ->and($pinned->read('SIZE'))->toBe('1')
            ->and($accepted->getArrayCopy())->toBe([true])
            ->and($served->read('SIZE'))->toBe('2');
    } finally {
        $pinned->loader->unregister();
        $served->loader->unregister();
    }
});
