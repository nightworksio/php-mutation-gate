<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Outbreak;

use function array_map;

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Format\Kind;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Reach\GraphAnswer;

use function sprintf;

/**
 * What `outbreak graph reach` answered (ADR-0132): `{"reaching": {<path>:
 * [<test file>, …] | null, …}, "joined": {<path>: [<path>, …] | null, …},
 * "named": {<path>: [<path>, …] | null, …}, "wrong": [<test file>, …],
 * "unplaced": {<path>: <why>, …}}`, each path relative to the project's root.
 * An answer in any other shape answers nothing.
 *
 * @internal
 */
final readonly class GraphAnswered
{
    /** Why the graph answered nothing, where its answer is in another shape. */
    public const string UNREAD = 'Outbreak\'s graph answered in another shape: %s';

    public static function of(Node $said): GraphAnswer|NotWritten
    {
        try {
            $unplaced = [];

            foreach ($said->field('unplaced')->entries() as $path => $why) {
                $unplaced[$path] = $why->text();
            }

            return GraphAnswer::of(
                self::answers($said->field('reaching')),
                self::answers($said->field('joined')),
                self::answers($said->field('named')),
                self::paths($said->field('wrong')),
                $unplaced,
            );
        } catch (NotInShape $unread) {
            return NotWritten::because(sprintf(self::UNREAD, $unread->getMessage()));
        }
    }

    /**
     * @return array<string, Paths|NotGiven>
     *
     * @throws NotInShape
     */
    private static function answers(Node $asked): array
    {
        $answers = [];

        foreach ($asked->entries() as $path => $answer) {
            $answers[$path] = $answer->kind() === Kind::Null ? NotGiven::value() : self::paths($answer);
        }

        return $answers;
    }

    /** @throws NotInShape */
    private static function paths(Node $listed): Paths
    {
        return Paths::of(...array_map(static fn(Node $path): Path => Path::of($path->text()), $listed->items()));
    }
}
