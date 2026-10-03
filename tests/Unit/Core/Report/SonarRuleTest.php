<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Report\ResultRule;
use NightWorksIO\MutationGate\Core\Report\SonarQuality;
use NightWorksIO\MutationGate\Core\Report\SonarRule;
use NightWorksIO\MutationGate\Core\Troubleshooting\Guide;

it('raises a mutant under the rule SARIF reports it by', function (ResultRule $result, SonarRule $rule): void {
    expect(SonarRule::of($result))->toBe($rule);
})->with([
    [ResultRule::Survived, SonarRule::Survived],
    [ResultRule::Uncovered, SonarRule::Uncovered],
    [ResultRule::Unjudged, SonarRule::Unjudged],
    [ResultRule::Flaky, SonarRule::Flaky],
]);

it('names and describes each rule, linking the guide\'s section on its judgement, with the quality it affects', function (): void {
    $guide = 'https://github.com/nightworksio/php-mutation-gate/blob/v0.1.0/.docs/guide/troubleshooting.md';

    expect(array_map(
        static fn(SonarRule $rule): array => [$rule->value, $rule->title(), $rule->description(Guide::ofInstalled('0.1.0')), $rule->quality()],
        SonarRule::cases(),
    ))->toBe([
        ['survived', 'Surviving mutant', sprintf('A mutant no test fails on. See %s#survived', $guide), SonarQuality::Reliability],
        ['uncovered', 'Uncovered mutant', sprintf('A mutant on a line no test runs. See %s#uncovered', $guide), SonarQuality::Reliability],
        ['unjudged', 'Unjudged mutant', sprintf('A mutant the run did not judge, or could not judge in its time or memory limit. See %s#unjudged', $guide), SonarQuality::Reliability],
        ['flaky', 'Flaky mutant', sprintf('A mutant its tests killed on one run and not on another. See %s#flaky', $guide), SonarQuality::Reliability],
        ['survived-security', 'Surviving security mutant', sprintf('A mutant a security-tagged mutator made, which no test fails on. See %s#survived', $guide), SonarQuality::Security],
    ]);
});
