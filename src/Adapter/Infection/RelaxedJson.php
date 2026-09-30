<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Infection;

use ColinODell\Json5\Json5Decoder;

use function json_encode;

use JsonException;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Format\JsonText;
use NightWorksIO\MutationGate\Core\Format\Node;

use function sprintf;

use stdClass;

/**
 * An Infection config file's JSON5 text, read as the object it must hold. The
 * JSON5 library's untyped result stops here: every reader gets a Node.
 */
final readonly class RelaxedJson
{
    private const string NOT_JSON5 = '%s cannot be read, so the gate cannot say what Infection would mutate: %s';

    private const string NOT_AN_OBJECT
        = '%s does not hold an object, so the gate cannot say what Infection would mutate.';

    /** The object a file's text holds, or why it holds none. */
    public static function decode(string $name, string $text): Node|CannotJudge
    {
        try {
            $decoded = Json5Decoder::decode($text);

            return $decoded instanceof stdClass
                ? Node::decode(json_encode($decoded, JsonText::FLAGS))
                : CannotJudge::because(sprintf(self::NOT_AN_OBJECT, $name));
        } catch (JsonException $error) {
            return CannotJudge::because(sprintf(self::NOT_JSON5, $name, $error->getMessage()));
        }
    }
}
