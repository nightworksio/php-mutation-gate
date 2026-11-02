<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Composer;

use function in_array;

use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Kind;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\ThisPackage;

use function sprintf;

/**
 * The root script `composer mutate` is (ADR-0024): Composer's process
 * timeout turned off, then the gate, which a whole run outlasts. It is added
 * to a `composer.json` only where nothing else in the file changes, so only
 * to one laid out as Composer writes it, which has no `mutate` script yet.
 *
 * @internal
 */
final readonly class MutateScript
{
    public const string NAME = 'mutate';

    private const string SCRIPTS = 'scripts';

    /** What runs first, so Composer stops no run after its default 300 seconds. */
    private const string NO_TIMEOUT = 'Composer\\Config::disableProcessTimeout';

    private const string ENDED = "%s\n";

    private const string MEMBER = '"%s": %s';

    /** The script, as the line that adds it to the scripts of a `composer.json`. */
    public static function line(): string
    {
        return sprintf(self::MEMBER, self::NAME, Json::items(...self::runs())->line());
    }

    /** Whether a `composer.json` already has a `mutate` script, which is left as it is. */
    public static function isIn(Contents $manifest): bool
    {
        return Node::decode($manifest->text())->field(self::SCRIPTS)->field(self::NAME)->isPresent();
    }

    /** The `composer.json` with the script added; none where adding it would change anything else in the file. */
    public static function addedTo(Contents $manifest): Contents|NotGiven
    {
        $read = Json::parse($manifest->text());
        $script = Json::at(sprintf('%s.%s', self::SCRIPTS, self::NAME), Json::items(...self::runs()));

        return $read instanceof Json && self::takes($manifest, $read)
            ? Contents::of(sprintf(self::ENDED, $read->merged($script)->pretty()))
            : NotGiven::value();
    }

    /** Whether the script can be added to a `composer.json` read so, changing nothing else in it. */
    private static function takes(Contents $manifest, Json $read): bool
    {
        $top = Node::decode($manifest->text());

        return $top->kind() === Kind::Map
            && in_array($top->field(self::SCRIPTS)->kind(), [Kind::Nothing, Kind::Map], strict: true)
            && sprintf(self::ENDED, $read->pretty()) === $manifest->text()
            && ! self::isIn($manifest);
    }

    /** @return list<string> */
    private static function runs(): array
    {
        return [self::NO_TIMEOUT, ThisPackage::NAME];
    }
}
