<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\ByPath;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Missing;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Tests\Support\Growth;

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

it('holds nothing to begin with', function (): void {
    expect(ByPath::none())->toHaveCount(0)
        ->and(ByPath::none()->at(Path::of('src/A.php'), Missing::at(Path::of('src/A.php'))))->toEqual(Missing::at(Path::of('src/A.php')));
});

it('takes a value for a path in place of the one it had, where the path came, and leaves itself as it was', function () use ($held): void {
    $before = $held();
    $after = $before->with(Path::of('src/B.php'), Missing::at(Path::of('src/B.php')))->with(Path::of('src/C.php'), Contents::of('c'));

    expect($after->paths())->toEqual(Paths::of(Path::of('src/B.php'), Path::of('src/A.php'), Path::of('src/C.php')))
        ->and($after->at(Path::of('src/B.php'), Contents::of('')))->toEqual(Missing::at(Path::of('src/B.php')))
        ->and($before->at(Path::of('src/B.php'), Missing::at(Path::of('src/B.php'))))->toEqual(Contents::of('<?php // src/B.php'))
        ->and($before)->toHaveCount(2);
});

it('joins others, a later value of a path replacing the earlier where it came', function () use ($held): void {
    $joined = ByPath::none()
        ->with(Path::of('src/A.php'), Contents::of('first'))
        ->and($held(), ByPath::none()->with(Path::of('123'), Contents::of('digits')));

    expect($joined->paths())->toEqual(Paths::of(Path::of('src/A.php'), Path::of('src/B.php'), Path::of('123')))
        ->and($joined->at(Path::of('src/A.php'), Contents::of('')))->toEqual(Contents::of('<?php // src/A.php'))
        ->and($joined->at(Path::of('123'), Contents::of('')))->toEqual(Contents::of('digits'));
});

it('joins others in time linear in their paths', function (): void {
    $joined = static function (int $size): Closure {
        $each = array_map(static fn(int $at): ByPath => ByPath::none()->with(Path::of(sprintf('src/F%d.php', $at)), Contents::of('')), range(1, $size));

        return static fn(): ByPath => ByPath::none()->and(...$each);
    };

    expect($joined(10)())->toHaveCount(10)
        ->and(Growth::of(1250, $joined))->toBeLessThan(Growth::LINEAR);
});
