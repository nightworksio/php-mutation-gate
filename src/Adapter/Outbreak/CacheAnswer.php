<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Outbreak;

use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Written;

use function sprintf;

/**
 * What `outbreak cache pull` or `outbreak cache push` answered of the proof
 * cache's packs: how many it moved, `{"pulled": <count>}` or
 * `{"pushed": <count>}`.
 *
 * @internal
 */
final readonly class CacheAnswer
{
    private const string UNCACHED = 'Outbreak did not %s the proof cache\'s packs: %s';

    private const string CACHED = 'Outbreak %s %d of the proof cache\'s packs.';

    /** How many packs the binary moved, under the field it did that by; or why none. */
    public static function of(Node|NotWritten $said, string $verb, string $done): Written|NotWritten
    {
        try {
            return $said instanceof Node
                ? Written::noting($done, sprintf(self::CACHED, $done, $said->field($done)->integer()))
                : NotWritten::because(sprintf(self::UNCACHED, $verb, $said->why()));
        } catch (NotInShape) {
            return NotWritten::because(sprintf(self::UNCACHED, $verb, sprintf(BinaryRun::ANSWERED, $said->json())));
        }
    }
}
