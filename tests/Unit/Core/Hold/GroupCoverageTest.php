<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\CoveredLine;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Hold\Covered;
use NightWorksIO\MutationGate\Core\Hold\GroupCoverage;
use NightWorksIO\MutationGate\Core\Hold\NotCovered;
use NightWorksIO\MutationGate\Core\Test\Filter;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Tests\Support\Growth;

$map = static function (string ...$lines): CoverageMap {
    $map = CoverageMap::empty();

    foreach ($lines as $covered) {
        [$file, $line] = explode(':', $covered);
        $map = $map->covered(Path::of($file), Line::of((int) $line), TestId::of('KernelTest::boots'));
    }

    return $map;
};

$kernel = static fn(): Unit => Unit::held(Path::of('src/Kernel.php'), Group::named('holds:src/Kernel.php'));

it('lets the group judge what it holds when it covers every line the suite covers', function () use ($map, $kernel): void {
    expect(GroupCoverage::of($kernel(), $map('src/Kernel.php:10', 'src/Kernel.php:12'), $map('src/Kernel.php:10', 'src/Kernel.php:12', 'src/Kernel.php:14')))
        ->toEqual(Covered::by($kernel()));
});

it('names every line the suite covers and the group misses', function () use ($map, $kernel): void {
    $suite = $map('src/Kernel.php:10', 'src/Kernel.php:12', 'src/Kernel.php:48', 'src/Other.php:3');

    expect(GroupCoverage::of($kernel(), $suite, $map('src/Kernel.php:10')))->toEqual(NotCovered::because(
        $kernel(),
        "holds:src/Kernel.php does not cover src/Kernel.php, so its mutants cannot be judged by it.\nNot reached: src/Kernel.php:12, src/Kernel.php:48\nAdd the test that runs them to the group.",
    ));
});

it('judges a held directory by every file in it, and by no file outside it', function () use ($map): void {
    $http = Unit::held(Path::of('src/Http'), Group::named('holds:src/Http'));
    $suite = $map('src/Http/Controller.php:3', 'src/Http/Kernel.php:5', 'src/HttpClient.php:7');

    expect(GroupCoverage::of($http, $suite, $map('src/Http/Controller.php:3')))->toEqual(NotCovered::because(
        $http,
        "holds:src/Http does not cover src/Http, so its mutants cannot be judged by it.\nNot reached: src/Http/Kernel.php:5\nAdd the test that runs them to the group.",
    ));
});

it('finds a held path unreached as a whole when the group runs none of it', function () use ($map, $kernel): void {
    expect(GroupCoverage::of($kernel(), $map('src/Kernel.php:10'), $map('src/Other.php:10')))->toEqual(NotCovered::because(
        $kernel(),
        "holds:src/Kernel.php does not cover src/Kernel.php, so its mutants cannot be judged by it.\nNot reached: src/Kernel.php, all of it\nAdd the test that runs them to the group.",
    ))
        ->and(GroupCoverage::of($kernel(), CoverageMap::empty(), CoverageMap::empty()))->toEqual(NotCovered::because(
            $kernel(),
            "holds:src/Kernel.php does not cover src/Kernel.php, so its mutants cannot be judged by it.\nNot reached: src/Kernel.php, all of it\nAdd the test that runs them to the group.",
        ));
});

it('names a #[Holds] by the attribute', function () use ($map): void {
    $held = Unit::held(Path::of('src/Kernel.php'), Filter::matching('/^(?:Tests\\\\KernelTest::)/'));

    expect(GroupCoverage::of($held, $map('src/Kernel.php:10', 'src/Kernel.php:12'), $map('src/Kernel.php:12')))->toEqual(NotCovered::because(
        $held,
        "#[Holds('src/Kernel.php')] does not cover src/Kernel.php, so its mutants cannot be judged by it.\nNot reached: src/Kernel.php:10\nAdd the test that runs them to the group.",
    ));
});

it('checks a held file in time linear in its lines', function () use ($kernel): void {
    $lines = static fn(int $last): CoverageMap => CoverageMap::of(...array_map(
        static fn(int $line): CoveredLine => CoveredLine::of(Path::of('src/Kernel.php'), $line, 'KernelTest::boots'),
        range(1, $last),
    ));
    $checked = static function (int $size) use ($kernel, $lines): Closure {
        $suite = $lines($size);
        $group = $lines($size - 1);

        return static fn(): Covered|NotCovered => GroupCoverage::of($kernel(), $suite, $group);
    };

    expect($checked(10)())->toEqual(NotCovered::because(
        $kernel(),
        "holds:src/Kernel.php does not cover src/Kernel.php, so its mutants cannot be judged by it.\nNot reached: src/Kernel.php:10\nAdd the test that runs them to the group.",
    ))
        ->and(Growth::of(625, $checked))->toBeLessThan(Growth::LINEAR);
});
