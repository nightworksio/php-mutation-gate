<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use function array_intersect;
use function is_string;
use function json_encode;

use NightWorksIO\MutationGate\Core\Format\Lenient;
use NightWorksIO\MutationGate\Core\Format\Node;

use function sprintf;

use Symfony\Component\Yaml\Yaml;

/**
 * The workflows an event starts from main with a token that can write, read
 * as the bot's rules judge them (ADR-0019, decision 3).
 *
 * @phpstan-type Step array{uses: string, run: string, with: array<array-key, Node>}
 * @phpstan-type Job array{permissions: bool, calls: bool, steps: list<Step>}
 * @phpstan-type Workflow array{permissions: bool, jobs: array<string, Job>}
 */
final readonly class Privileged
{
    /** The events that run main's workflow with a token that can write, and secrets. */
    public const array EVENTS = ['issue_comment', 'pull_request_target', 'workflow_run'];

    /** Where the workflows live. */
    private const string WORKFLOWS = '.github/workflows';

    /**
     * Every workflow one of those events starts, by its path.
     *
     * @return array<string, Workflow>
     */
    public static function workflows(): array
    {
        $workflows = [];

        foreach (Tree::filesUnder(self::WORKFLOWS, '.yml') as $path) {
            $workflow = Node::decode((string) json_encode(Yaml::parseFile(Tree::at($path))));

            if (self::startedByAWriter($workflow->field('on'))) {
                $workflows[$path] = self::workflow($workflow);
            }
        }

        return $workflows;
    }

    /** Whether a workflow's `on` names one of those events, as a word, a list or a map's key. */
    private static function startedByAWriter(Node $on): bool
    {
        $events = [Lenient::text($on)];

        foreach (Lenient::entries($on) as $key => $value) {
            $events[] = is_string($key) ? $key : Lenient::text($value);
        }

        return array_intersect($events, self::EVENTS) !== [];
    }

    /** @return Workflow */
    private static function workflow(Node $workflow): array
    {
        $jobs = [];

        foreach (Lenient::entries($workflow->field('jobs')) as $id => $job) {
            $steps = [];

            foreach (Lenient::items($job->field('steps')) as $step) {
                $steps[] = [
                    'uses' => Lenient::text($step->field('uses')),
                    'run' => Lenient::text($step->field('run')),
                    'with' => Lenient::entries($step->field('with')),
                ];
            }

            $jobs[sprintf('%s', $id)] = [
                'permissions' => $job->field('permissions')->isPresent(),
                'calls' => $job->field('uses')->isPresent(),
                'steps' => $steps,
            ];
        }

        return [
            'permissions' => Lenient::holdsMembers($workflow->field('permissions')) && Lenient::entries($workflow->field('permissions')) === [],
            'jobs' => $jobs,
        ];
    }
}
