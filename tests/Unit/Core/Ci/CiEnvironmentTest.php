<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Ci\CiEnvironment;
use NightWorksIO\MutationGate\Core\Ci\CiMarker;
use NightWorksIO\MutationGate\Core\Ci\Marking;
use NightWorksIO\MutationGate\Core\Ci\Variables;

it('shows a marker set to true, one set to anything, and never none', function (
    CiMarker $marker,
    string $value,
    bool $shown,
): void {
    expect(CiEnvironment::of(Variables::of(['MARK' => $value]))->shows($marker))->toBe($shown);
})->with([
    'true' => [CiMarker::saying('MARK'), 'true', true],
    'True, which is not true' => [CiMarker::saying('MARK'), 'True', false],
    'True, set to something' => [CiMarker::setting('MARK'), 'True', true],
    'not true' => [CiMarker::saying('MARK'), '1', false],
    'unset' => [CiMarker::saying('OTHER'), 'true', false],
    'set to anything' => [CiMarker::setting('MARK'), '42', true],
    'set to nothing' => [CiMarker::setting('MARK'), '', false],
    'no marker' => [CiMarker::none(), 'true', false],
]);

it('names the variable a marker reads, and how it reads it', function (): void {
    expect([CiMarker::saying('GITLAB_CI')->variable(), CiMarker::saying('GITLAB_CI')->marking()])
        ->toBe(['GITLAB_CI', Marking::SaysTrue])
        ->and([CiMarker::setting('JENKINS_URL')->variable(), CiMarker::setting('JENKINS_URL')->marking()])
        ->toBe(['JENKINS_URL', Marking::IsSet])
        ->and(CiMarker::none()->marking())->toBe(Marking::Never);
});
