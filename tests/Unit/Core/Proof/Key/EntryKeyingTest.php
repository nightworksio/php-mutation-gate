<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Fingerprint;
use NightWorksIO\MutationGate\Core\File\Fingerprints;
use NightWorksIO\MutationGate\Core\File\Missing;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Php\NamedFiles;
use NightWorksIO\MutationGate\Core\Proof\Key\EntryKeying;
use NightWorksIO\MutationGate\Tests\Support\Growth;

const ENTRY_FILES = [
    'tests/MoneyTest.php' => 't1',
    'tests/bootstrap.php' => 'b1',
    'tests/Support/Builder.php' => 's1',
    'src/Money.php' => 'm1',
    'src/Config.php' => 'c1',
    'src/Rate.php' => 'r1',
    'src/Other.php' => 'o1',
];

/**
 * The key of tests/MoneyTest.php's entries, which executed src/Money.php: the test names its builder, Money names
 * Config, and Config names Rate.
 *
 * @param array<string, string> $digests each file by its digest
 */
function entryKeyOf(array $digests = ENTRY_FILES, string $base = 'base', string $executed = 'src/Money.php'): Digest
{
    $files = Fingerprints::none();

    foreach ($digests as $path => $digest) {
        $files = $files->with(Fingerprint::of(Path::of($path), Digest::sha256Of($digest)));
    }

    $names = NamedFiles::byName(
        ['tests/Support/Builder.php' => ['Builder'], 'src/Config.php' => ['Config'], 'src/Rate.php' => ['Rate']],
        ['tests/MoneyTest.php' => ['Builder'], 'src/Money.php' => ['Config'], 'src/Config.php' => ['Rate']],
    );

    return EntryKeying::of(Digest::sha256Of($base), $names, $files, Paths::of(Path::of('tests/bootstrap.php')))
        ->keyOf(Path::of('tests/MoneyTest.php'), Paths::of(Path::of($executed)));
}

it('hashes the base, then each file the entries read, in byte order, by its digest, a missing one as missing', function (): void {
    $framed = static fn(string ...$fields): string => implode('', array_map(
        static fn(string $field): string => sprintf("%d:%s\n", strlen($field), $field),
        $fields,
    ));
    $read = ['src/Config.php', 'src/Money.php', 'src/Rate.php', 'tests/MoneyTest.php', 'tests/Support/Builder.php', 'tests/bootstrap.php'];
    $digests = [...ENTRY_FILES];
    unset($digests['src/Rate.php']);
    $expected = $framed(EntryKeying::FORMAT, Digest::sha256Of('base')->value(), '6');

    foreach ($read as $path) {
        $expected .= $framed($path, $path === 'src/Rate.php' ? Missing::DIGESTED : Digest::sha256Of(ENTRY_FILES[$path])->value());
    }

    expect(entryKeyOf($digests))->toEqual(Digest::sha256Of($expected));
});

it('moves with the base, the test file, what runs first, its support, what it executed, and what those name', function (string $path): void {
    expect(entryKeyOf([...ENTRY_FILES, $path => 'changed']))->not->toEqual(entryKeyOf());
})->with(['tests/MoneyTest.php', 'tests/bootstrap.php', 'tests/Support/Builder.php', 'src/Money.php', 'src/Config.php', 'src/Rate.php']);

it('stays where a file the entries neither execute nor name changes, and moves where the base or what ran does', function (): void {
    expect(entryKeyOf([...ENTRY_FILES, 'src/Other.php' => 'changed']))->toEqual(entryKeyOf())
        ->and(entryKeyOf([...ENTRY_FILES, 'src/Added.php' => 'new']))->toEqual(entryKeyOf())
        ->and(entryKeyOf(base: 'other'))->not->toEqual(entryKeyOf())
        ->and(entryKeyOf(executed: 'src/Other.php'))->not->toEqual(entryKeyOf());
});

it('keys a test file in time linear in the files its entries read', function (): void {
    $keying = static function (int $size): Closure {
        $files = Fingerprints::none();
        $declares = [];
        $mentions = [];

        foreach (range(1, $size) as $at) {
            $files = $files->with(Fingerprint::of(Path::of(sprintf('src/F%d.php', $at)), Digest::sha256Of((string) $at)));
            $declares[sprintf('src/F%d.php', $at)] = [sprintf('F%d', $at)];
            $mentions[sprintf('src/F%d.php', $at)] = [sprintf('F%d', $at + 1)];
        }

        $keys = EntryKeying::of(Digest::sha256Of('base'), NamedFiles::byName($declares, $mentions), $files, Paths::none());

        return static fn(): int => strlen($keys->keyOf(Path::of('tests/T.php'), Paths::of(Path::of('src/F1.php')))->value());
    };

    expect($keying(10)())->toBe(64)
        ->and(Growth::of(500, $keying))->toBeLessThan(Growth::LINEAR);
});
