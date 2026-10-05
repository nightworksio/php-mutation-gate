<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Keyed;

use function basename;
use function dirname;

use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\Proof\Scope;

use function sprintf;

/**
 * The default branch's scope, as a command that holds the gate's keys learns it from its own run (ADR-0007
 * decision 5): the branch the GitHub Actions event payload names, else the one `MUTATION_GATE_DEFAULT_BRANCH`
 * names, since a schedule's payload names none; with neither, none.
 */
final readonly class DefaultBranch
{
    /** The variable the workflow hands the default branch's name in. */
    public const string VARIABLE = 'MUTATION_GATE_DEFAULT_BRANCH';

    private const string UNNAMED = 'Neither the event payload nor %s names the default branch.';

    public static function scopeIn(Variables $environment): Scope|CannotJudge
    {
        $named = self::payloadNameIn($environment);
        $name = $named !== '' ? $named : $environment->valueOf(self::VARIABLE);

        return $name === ''
            ? CannotJudge::because(sprintf(self::UNNAMED, self::VARIABLE))
            : Scope::parse(Scope::branch($name)->ref());
    }

    /** The default branch the event payload `GITHUB_EVENT_PATH` names; empty where there is none. */
    private static function payloadNameIn(Variables $environment): string
    {
        $event = $environment->valueOf('GITHUB_EVENT_PATH');
        $payload = $event === '' ? '' : Directory::at(dirname($event))->read(Path::of(basename($event)));

        try {
            return $payload instanceof Contents
                ? Node::decode($payload->text())->field('repository')->field('default_branch')->text()
                : '';
        } catch (NotInShape) {
            return '';
        }
    }
}
