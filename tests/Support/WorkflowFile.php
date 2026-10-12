<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use Closure;

use function file_get_contents;
use function json_encode;

use NightWorksIO\MutationGate\Core\Format\Lenient;
use NightWorksIO\MutationGate\Core\Format\Node;
use Symfony\Component\Yaml\Yaml;

/** A GitHub workflow or action, read from its YAML, for the tests that hold what the package ships. */
final readonly class WorkflowFile
{
    /** A YAML file of the repository, read. */
    public static function at(string $path): Node
    {
        return self::of((string) file_get_contents(Tree::at($path)));
    }

    /** YAML text, read. */
    public static function of(string $yaml): Node
    {
        return Node::decode((string) json_encode(Yaml::parse($yaml)));
    }

    /**
     * The positions of the steps whose field a test picks, in order.
     *
     * @param Closure(string): bool $picks
     * @return list<int>
     */
    public static function stepsWhere(Node $steps, string $field, Closure $picks): array
    {
        $found = [];

        foreach (Lenient::items($steps) as $at => $step) {
            if ($picks(Lenient::text($step->field($field)))) {
                $found[] = $at;
            }
        }

        return $found;
    }
}
