<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\PhpStan\Parameters;
use NightWorksIO\MutationGate\Core\Analysis\AnalyserSettings;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\File\Root;

/**
 * PHPStan's resolved parameters for a project at `/project`, as `dump-parameters` prints them, with these members
 * besides its level.
 *
 * @param array<string, mixed> $members
 */
function parametersOf(array $members): AnalyserSettings
{
    $settings = Parameters::settings((string) json_encode(['level' => 9, ...$members]), Root::of('/project'));

    return $settings instanceof AnalyserSettings ? $settings : throw new LogicException($settings->why());
}

it('writes the parameters alike on every machine, leaving out each member that differs between machines and runs', function (string $member): void {
    expect(parametersOf([$member => '/tmp/runner'])->written())->toBe(parametersOf([])->written());
})->with(['env', 'tmpDir', 'sysGetTempDir', 'resultCachePath', 'pro']);

it('references every file PHPStan reads besides the code, from each member that names them', function (): void {
    $settings = parametersOf([
        'allConfigFiles' => ['/project/phpstan.neon', '/project/phpstan-baseline.neon'],
        'bootstrapFiles' => ['/project/bootstrap.php'],
        'stubFiles' => ['/project/stubs/Model.stub'],
        'scanFiles' => ['/project/legacy.php'],
        'scanDirectories' => ['/project/generated'],
    ]);

    expect($settings->references())->toEqual(Paths::of(
        Path::of('phpstan.neon'),
        Path::of('phpstan-baseline.neon'),
        Path::of('bootstrap.php'),
        Path::of('stubs/Model.stub'),
        Path::of('legacy.php'),
        Path::of('generated'),
    ));
});
