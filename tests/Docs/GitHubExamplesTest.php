<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\Ci;
use NightWorksIO\MutationGate\Core\Format\Lenient;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Tests\Support\Tree;
use NightWorksIO\MutationGate\Tests\Support\WorkflowFile;

// The GitHub Actions guide's examples, held to the check the verdict is read by
// (ci.check, ADR-0007) and to what the reusable workflow uploads.

/** The page of the guide that shows the action and the reusable workflow. */
const GITHUB_GUIDE = '.docs/guide/ci/github-actions.md';

/** The guide's YAML example that holds a line, read. */
function guideExample(string $holding): Node
{
    preg_match_all('/^```yaml\n(?<yaml>.*?)^```$/msu', (string) file_get_contents(Tree::at(GITHUB_GUIDE)), $blocks);
    $found = array_values(array_filter($blocks['yaml'], static fn(string $yaml): bool => str_contains($yaml, $holding)));

    return WorkflowFile::of($found[0] ?? '');
}

/** The id of an example's one job. */
function exampleJobId(Node $example): string
{
    return (string) array_key_first(Lenient::entries($example->field('jobs')));
}

/** An example's one job. */
function exampleJob(Node $example): Node
{
    return $example->field('jobs')->field(exampleJobId($example));
}

it('names the action\'s first job as the check a merged pull request\'s verdict is read by', function (): void {
    $job = exampleJob(guideExample('uses: nightworksio/php-mutation-gate@'));

    expect(Lenient::text($job->field('name')))->toBe(Ci::standard()->check());
});

it('calls the reusable workflow from a job whose verdict shows as that check', function (): void {
    $id = exampleJobId(guideExample('uses: nightworksio/php-mutation-gate/.github/workflows/mutation-gate.yml@'));

    expect(sprintf('%s / verdict', $id))->toBe(Ci::standard()->check());
});

it('names only artifacts the reusable workflow uploads and the environment it enters', function (): void {
    $guide = (string) file_get_contents(Tree::at(GITHUB_GUIDE));
    preg_match('/^# GitHub Actions\n(?<section>.*?)^## Sharding with a matrix of your own$/msu', $guide, $github);
    preg_match_all('/`(?<name>mutation-gate-[a-z-]+)`/u', $github['section'] ?? '', $named);
    $uploaded = [];

    foreach (Lenient::entries(WorkflowFile::at('.github/workflows/mutation-gate.yml')->field('jobs')) as $job) {
        preg_match_all('/(?<name>mutation-gate-[a-z-]+)/u', Lenient::text($job->field('environment')->field('name')), $entered);
        $uploaded = [...$uploaded, ...$entered['name']];

        foreach (Lenient::items($job->field('steps')) as $step) {
            $uploaded[] = str_starts_with(Lenient::text($step->field('uses')), 'actions/upload-artifact@')
                ? Lenient::text($step->field('with')->field('name'))
                : '';
        }
    }

    expect($named['name'])->not->toBe([])
        ->and(array_values(array_diff($named['name'], $uploaded)))->toBe([]);
});
