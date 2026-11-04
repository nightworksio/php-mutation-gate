<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Registry;

use function array_map;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\BuiltinRunner;
use NightWorksIO\MutationGate\Core\Config\Definition\At;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\PathRules;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\NotGiven;

use function sprintf;

/**
 * The mutators each `paths` entry turns on and off, judged as the config's own are, laid over them (ADR-0074):
 * a set nothing registers, a mutator no set holds, and one only the runner's own mutants hold under a runner
 * that makes them, which the entry could not turn off, are refused at the entry's path.
 *
 * @internal
 */
final readonly class RuledMutators
{
    /** Where a problem with an entry is said: the entry's path, then the problem's. */
    private const string AT = '%s.%s';


    public static function checked(Lookup $lookup, Settings $settings): NotGiven|Invalid|CannotJudge
    {
        $runner = BuiltinRunner::tryFrom($settings->runner()->choice()->use()->value()) ?? NotGiven::value();

        foreach ($settings->effective()->setup()->paths()->rules() as $index => $rule) {
            $at = At::index(PathRules::KEY, $index);
            $mutators = $settings->effective()->over($rule->rules())->setup()->mutators();
            $enabled = EnabledMutators::in($lookup, $mutators, $runner);

            if ($enabled instanceof Invalid) {
                return Invalid::because(...array_map(
                    static fn(Problem $problem): Problem => Problem::at(
                        sprintf(self::AT, $at, $problem->path()),
                        $problem->message(),
                    ),
                    [...$enabled],
                ));
            }

            if ($enabled instanceof CannotJudge) {
                return CannotJudge::because(Problem::at($at, $enabled->why())->said());
            }
        }

        return NotGiven::value();
    }
}
