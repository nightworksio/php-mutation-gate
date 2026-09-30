<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use function array_key_exists;
use function array_keys;
use function array_map;
use function file_get_contents;
use function json_encode;

use NightWorksIO\MutationGate\Core\Format\Lenient;
use NightWorksIO\MutationGate\Core\Format\Node;

use function preg_match;
use function sprintf;
use function str_replace;

use Symfony\Component\Yaml\Yaml;

/**
 * The CI jobs, as the workflows declare them, and their entries in
 * .github/gates.json, read into shapes the arch tests compare (V1–V3).
 *
 * @phpstan-type Step array{id: string, if: string, run: string, uses: string, with: array<string, string>}
 * @phpstan-type Job array{check: string, steps: list<Step>}
 * @phpstan-type Producer array{step: string, tool: string, raw: string}
 * @phpstan-type Entry array{check: string, checks: string, reproduce: string, troubleshooting: string, none: bool, why: string, evidence: list<Producer>, rules: array<string, string>}
 */
final readonly class Gates
{
    /** The workflow every CI job starts from. */
    public const string CI = '.github/workflows/ci.yml';

    /** The table of the gates. */
    public const string TABLE = '.github/gates.json';

    /** What gates.json says in place of a job's producers where it leaves no evidence. */
    private const string NONE = 'none';

    /** A job that calls another workflow, and the workflow it calls. */
    private const string CALLED = '#^\./(\.github/workflows/[\w.-]+\.ya?ml)$#';

    /**
     * Every CI job by the name its evidence goes by: a job of ci.yml by its
     * id, and a job of a workflow ci.yml calls as `<caller>/<job>`.
     *
     * @return array<string, Job>
     */
    public static function jobs(): array
    {
        $jobs = [];

        foreach (self::jobsOf(self::CI) as $id => $job) {
            if (preg_match(self::CALLED, Lenient::text($job->field('uses')), $workflow) !== 1) {
                $jobs[$id] = self::job(self::named($job, $id), $job);

                continue;
            }

            foreach (self::jobsOf($workflow[1]) as $inner => $called) {
                $jobs[sprintf('%s/%s', $id, $inner)] = self::job(sprintf('%s / %s', $id, self::named($called, $inner)), $called);
            }
        }

        return $jobs;
    }

    /**
     * The ids of the jobs ci.yml runs, a called workflow as the job that calls it.
     *
     * @return list<string>
     */
    public static function ciJobIds(): array
    {
        return array_keys(self::jobsOf(self::CI));
    }

    /** @return list<string> what a job of ci.yml waits for */
    public static function needsOf(string $id): array
    {
        $jobs = self::jobsOf(self::CI);
        $needs = array_key_exists($id, $jobs) ? $jobs[$id]->field('needs') : Node::decode('{}');

        return array_map(Lenient::text(...), Lenient::items($needs));
    }

    /**
     * Every entry of gates.json, by its gate.
     *
     * @return array<string, Entry>
     */
    public static function entries(): array
    {
        $table = Node::decode((string) file_get_contents(Tree::at(self::TABLE)));
        $entries = [];

        foreach (Lenient::entries($table->field('gates')) as $gate => $entry) {
            $entries[sprintf('%s', $gate)] = self::entry($entry);
        }

        return $entries;
    }

    /** The name a gate's evidence files and artifact go by: `hygiene/typos` is `hygiene-typos`. */
    public static function fileName(string $gate): string
    {
        return str_replace('/', '-', $gate);
    }

    /** @return Job */
    private static function job(string $check, Node $job): array
    {
        $steps = [];

        foreach (Lenient::items($job->field('steps')) as $step) {
            $with = [];

            foreach (Lenient::entries($step->field('with')) as $input => $value) {
                $with[sprintf('%s', $input)] = Lenient::text($value);
            }

            $steps[] = [
                'id' => Lenient::text($step->field('id')),
                'if' => Lenient::text($step->field('if')),
                'run' => Lenient::text($step->field('run')),
                'uses' => Lenient::text($step->field('uses')),
                'with' => $with,
            ];
        }

        return ['check' => $check, 'steps' => $steps];
    }

    /** @return Entry */
    private static function entry(Node $entry): array
    {
        $producers = [];

        foreach (Lenient::items($entry->field('evidence')) as $producer) {
            $producers[] = [
                'step' => Lenient::text($producer->field('step')),
                'tool' => Lenient::text($producer->field('tool')),
                'raw' => Lenient::text($producer->field('raw')),
            ];
        }

        $rules = [];

        foreach (Lenient::entries($entry->field('rules')) as $rule => $says) {
            $rules[sprintf('%s', $rule)] = Lenient::text($says);
        }

        return [
            'check' => Lenient::text($entry->field('check')),
            'checks' => Lenient::text($entry->field('checks')),
            'reproduce' => Lenient::text($entry->field('reproduce')),
            'troubleshooting' => Lenient::text($entry->field('troubleshooting')),
            'none' => Lenient::text($entry->field('evidence')) === self::NONE,
            'why' => Lenient::text($entry->field('why')),
            'evidence' => $producers,
            'rules' => $rules,
        ];
    }

    /** A job's name, or its id where it has none, as GitHub names its check. */
    private static function named(Node $job, string $id): string
    {
        $name = Lenient::text($job->field('name'));

        return $name === '' ? $id : $name;
    }

    /** @return array<string, Node> the jobs of a workflow, by their ids */
    private static function jobsOf(string $workflow): array
    {
        $parsed = Node::decode((string) json_encode(Yaml::parseFile(Tree::at($workflow))));
        $jobs = [];

        foreach (Lenient::entries($parsed->field('jobs')) as $id => $job) {
            $jobs[sprintf('%s', $id)] = $job;
        }

        return $jobs;
    }
}
