<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Runner\Uncovered;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutants;
use NightWorksIO\MutationGate\Core\Verdict\NewCodeVerdict;
use NightWorksIO\MutationGate\Core\Verdict\NewCodeVerdicts;

$verdict = static fn(string $path): NewCodeVerdict => NewCodeVerdict::judged(Package::at(Path::of($path)), Floor::of(100), JudgedMutants::none(), Uncovered::Count);
$paths = static fn(NewCodeVerdicts $verdicts): array => array_map(static fn(NewCodeVerdict $verdict): string => $verdict->package()->path()->value(), iterator_to_array($verdicts, preserve_keys: true));

it('holds nothing to begin with', function (): void {
    expect(NewCodeVerdicts::none())->toHaveCount(0);
});

it('keeps verdicts in the order they were judged, numbered from nought', function () use ($verdict, $paths): void {
    expect($paths(NewCodeVerdicts::of(...['b' => $verdict('b'), 'a' => $verdict('a')])))->toBe(['b', 'a']);
});

it('adds a verdict without changing the verdicts it came from', function () use ($verdict, $paths): void {
    $verdicts = NewCodeVerdicts::of($verdict('a'));

    expect($paths($verdicts->with($verdict('b'))))->toBe(['a', 'b'])
        ->and($verdicts)->toHaveCount(1);
});
