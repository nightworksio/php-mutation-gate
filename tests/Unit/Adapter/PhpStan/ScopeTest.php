<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\PhpStan\Scope;
use NightWorksIO\MutationGate\Core\CannotJudge;

$holds = static function (string $parameters, string $file): bool {
    $scope = Scope::dumped($parameters);

    return $scope instanceof Scope && $scope->holds($file);
};

it('holds a file under one of its paths, or a path that is that file', function () use ($holds): void {
    $parameters = '{"paths": ["/p/src", "/p/bin/tool.php"], "excludePaths": {"analyseAndScan": [], "analyse": []}}';

    expect($holds($parameters, '/p/src/Money.php'))->toBeTrue()
        ->and($holds($parameters, '/p/bin/tool.php'))->toBeTrue()
        ->and($holds($parameters, '/p/srcs/Money.php'))->toBeFalse()
        ->and($holds($parameters, '/p/lib/Money.php'))->toBeFalse();
});

it('holds no file it excludes, by a path, a pattern or an older config\'s list, of either kind', function () use ($holds): void {
    $parameters = '{"paths": ["/p/src"], "excludePaths": {"analyseAndScan": ["/p/src/Legacy/"], "analyse": ["/p/src/*Test.php"]}}';
    $listed = '{"paths": ["/p/src"], "excludePaths": ["/p/src/Legacy"]}';
    $unexcluded = '{"paths": ["/p/src"]}';

    expect($holds($parameters, '/p/src/Legacy/Old.php'))->toBeFalse()
        ->and($holds($parameters, '/p/src/MoneyTest.php'))->toBeFalse()
        ->and($holds($parameters, '/p/src/Money.php'))->toBeTrue()
        ->and($holds($listed, '/p/src/Legacy/Old.php'))->toBeFalse()
        ->and($holds($listed, '/p/src/Money.php'))->toBeTrue()
        ->and($holds($unexcluded, '/p/src/Money.php'))->toBeTrue();
});

it('cannot say what it holds from parameters that are not in their shape', function (): void {
    expect(Scope::dumped('not JSON'))->toEqual(CannotJudge::because('PHPStan did not say which files it analyses: the parameters.paths is missing.'))
        ->and(Scope::dumped('{"paths": [1]}'))->toEqual(CannotJudge::because('PHPStan did not say which files it analyses: the parameters.paths[0] is not text.'))
        ->and(Scope::dumped('{"paths": [], "excludePaths": {"analyse": "x"}}'))
        ->toEqual(CannotJudge::because('PHPStan did not say which files it analyses: the parameters.excludePaths.analyse is not a list.'));
});
