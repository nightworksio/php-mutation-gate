<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\GateVariable;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\Recorder;
use NightWorksIO\MutationGate\Adapter\Pest\SchemaSwitch;
use NightWorksIO\MutationGate\Adapter\Pest\ServedChildren;
use NightWorksIO\MutationGate\Adapter\Pest\ServedClasses;
use NightWorksIO\MutationGate\Tests\Support\Environment;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\ServedBox;

afterEach(function (): void {
    Scratch::sweep();
});

/** Whether the file a process started under this mark, or none, names is there once the process marked that it ran. */
function servedChildMarked(string|false $mark, string $file): bool
{
    Environment::during([ServedChildren::SPAWNED => $mark === false ? null : $mark], static function (): object {
        ServedChildren::marked();

        return new stdClass();
    });

    return is_file($file);
}

it('marks that its process ran in the file the process that started it names, and marks nothing where it names none', function (): void {
    $file = sprintf('%s/marked', Scratch::directory());

    expect(servedChildMarked(mark: false, file: $file))->toBeFalse()
        ->and(servedChildMarked('', $file))->toBeFalse()
        ->and(servedChildMarked($file, $file))->toBeTrue();
});

/**
 * What a process started under these variables serves, the gate naming its original and mutated files in two more.
 *
 * @param  array<string, string|null> $variables
 * @return list<string>
 */
function servedUnder(array $variables): array
{
    $served = Environment::during(
        [...$variables, 'SERVED_ORIGINAL' => '/gate/original.php', 'SERVED_MUTATED' => '/gate/mutated.php'],
        static fn(): object => new ArrayObject(ServedChildren::served('SERVED_ORIGINAL', 'SERVED_MUTATED')),
    );

    return array_values($served->getArrayCopy());
}

it('serves what the gate names where nothing was told, or the original or its copy is missing', function (): void {
    expect(servedUnder([ServedChildren::TOLD => null]))->toBe(['/gate/original.php', '/gate/mutated.php'])
        ->and(servedUnder([ServedChildren::TOLD => '["/p/Money.php"]']))->toBe(['/gate/original.php', '/gate/mutated.php'])
        ->and(servedUnder([ServedChildren::TOLD => '["", "/c/1.php"]']))->toBe(['/gate/original.php', '/gate/mutated.php']);
});

it('serves the original and the copy it was told of, whatever keys hold them and whatever else was told', function (string $told): void {
    expect(servedUnder([ServedChildren::TOLD => $told]))->toBe(['/p/Money.php', '/c/1.php']);
})->with([
    'in the order told' => ['["/p/Money.php", "/c/1.php", "", ""]'],
    'by name' => ['{"original": "/p/Money.php", "copy": "/c/1.php"}'],
    'among what is no text' => ['[7, "/p/Money.php", null, "/c/1.php"]'],
]);

it('serves the copy it was told of where the process serves the mutant\'s schema already', function (): void {
    $box = ServedBox::registered(body: 'public static function size(): int { return 2; }');

    try {
        ServedClasses::serve($box->file, $box->copy);
        $told = json_encode([$box->file, '/c/1.php', $box->copy, '1']);

        expect(servedUnder([ServedChildren::TOLD => is_string($told) ? $told : '']))->toBe([$box->file, '/c/1.php']);
    } finally {
        SchemaSwitch::off(basename($box->copy, '.php'));
        ServedClasses::forget($box->file);
        $box->loader->unregister();
    }
});

it('tells the processes it starts of the mutant its variables put in place, in the order they are told', function (): void {
    $told = Environment::during([ServedChildren::TOLD => null], static function (): object {
        ServedChildren::tellOf([
            GateVariable::SchemaOn->value => '3',
            Recorder::MUTATED => '/c/1.php',
            Recorder::MUTANT => '/p/Money.php',
            GateVariable::Schema->value => '/s/1.php',
        ]);

        return new ArrayObject([getenv(ServedChildren::TOLD)]);
    });

    expect($told->getArrayCopy())
        ->toBe([json_encode(['/p/Money.php', '/c/1.php', '/s/1.php', '3'])]);
});
