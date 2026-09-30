<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Git\History;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Time\Instant;

/** Each path the log says changed, with when, as its instant is written. */
$said = static function (string $printed, Paths $asked): array {
    $said = [];

    foreach (History::lastChanged($printed, $asked) as $path => $at) {
        $said[$path->value()] = $at->value();
    }

    return $said;
};

it('asks git for the log of HEAD over the paths it reads, each as it is, each commit its time and its files', function (): void {
    expect(History::arguments())->toBe(['--literal-pathspecs', 'log', '--stdin', '-z', '--format=%x01%ct', '--name-only', '--no-renames', '--relative'])
        ->and(History::input(Paths::of(Path::of('src/Money.php'), Path::of('src/Held'))))->toBe("HEAD\n--\nsrc/Money.php\nsrc/Held\n");
});

it('gives each asked path its newest commit, a directory by its newest file, and none to a path no commit changed', function () use ($said): void {
    $printed = "\x011790000200\0\nsrc/Held/B.php\0src/Other.php\0\x011790000100\0\nsrc/Money.php\0src/Held/A.php\0src/Held/Deep/C.php\0";
    $asked = Paths::of(Path::of('src/Money.php'), Path::of('src/Held'), Path::of('src/Held/Deep'), Path::of('src/Never.php'));

    expect($said($printed, $asked))->toBe([
        'src/Held' => Instant::at(new DateTimeImmutable('@1790000200'))->value(),
        'src/Money.php' => Instant::at(new DateTimeImmutable('@1790000100'))->value(),
        'src/Held/Deep' => Instant::at(new DateTimeImmutable('@1790000100'))->value(),
    ]);
});

it('says nothing of a log that printed nothing', function () use ($said): void {
    expect($said('', Paths::of(Path::of('src/Money.php'))))->toBe([]);
});
