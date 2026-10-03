<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Http;

use function is_string;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Format\Lenient;
use NightWorksIO\MutationGate\Core\Format\Node;

use function sprintf;

/** What a token endpoint answered: its body, or the text of one field of its JSON, such as `access_token`. */
final readonly class Answer
{
    private const string NO_FIELD = '%s answered without %s';

    /** The body of an accepted answer from what the reader knows as `$from`; or why there is none. */
    public static function body(Reply|CannotJudge $answer, string $from): string|CannotJudge
    {
        return match (true) {
            $answer instanceof CannotJudge => $answer,
            $answer->isAccepted() => $answer->body(),
            default => CannotJudge::because($answer->refusedBy($from)->why()),
        };
    }

    /** The text under this field of an accepted JSON answer; or why there is none, an answer without it among. */
    public static function field(Reply|CannotJudge $answer, TokenField $field, string $from): string|CannotJudge
    {
        $body = self::body($answer, $from);
        $text = is_string($body) ? Lenient::text(Node::decode($body)->field($field->value)) : $body;

        return $text === '' ? CannotJudge::because(sprintf(self::NO_FIELD, $from, $field->value)) : $text;
    }
}
