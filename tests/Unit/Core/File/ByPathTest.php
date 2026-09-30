<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\ByPath;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Missing;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;

$held = static fn(): ByPath => ByPath::mapping(
    Paths::of(Path::of('src/B.php'), Path::of('src/A.php')),
    static fn(Path $path): Contents => Contents::of(sprintf('<?php // %s', $path->value())),
);

it('gives each path the value made for it', function () use ($held): void {
    expect($held()->at(Path::of('src/A.php'), Missing::at(Path::of('src/A.php'))))->toEqual(Contents::of('<?php // src/A.php'))
        ->and($held()->at(Path::of('./src/B.php'), Missing::at(Path::of('src/B.php'))))->toEqual(Contents::of('<?php // src/B.php'));
});

it('answers what it is told to for a path it holds no value for', function () use ($held): void {
    $otherwise = Missing::at(Path::of('src/C.php'));

    expect($held()->at(Path::of('src/C.php'), $otherwise))->toBe($otherwise);
});

it('holds its paths in the order they came, each once', function () use ($held): void {
    $values = [];

    foreach ($held() as $path => $value) {
        $values[] = [$path->value(), $value->text()];
    }

    expect($held()->paths())->toEqual(Paths::of(Path::of('src/B.php'), Path::of('src/A.php')))
        ->and($held())->toHaveCount(2)
        ->and($values)->toBe([['src/B.php', '<?php // src/B.php'], ['src/A.php', '<?php // src/A.php']]);
});

it('holds nothing for no paths', function (): void {
    $none = ByPath::mapping(Paths::none(), static fn(Path $path): Missing => Missing::at($path));

    expect($none)->toHaveCount(0)
        ->and($none->paths())->toEqual(Paths::none());
});
