<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use function array_map;
use function implode;

use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Kind;
use NightWorksIO\MutationGate\Core\Format\Node;

use function sprintf;

/** The options written beside an adapter, as the builder's `Option` calls that write them. */
final readonly class PhpOptions
{
    /** @return list<string> each option as the `Option` call that writes it */
    public static function of(Json $options): array
    {
        $node = Node::config($options->line());

        return $node->kind() === Kind::Map ? self::each($node) : [];
    }

    /** @return list<string> */
    private static function each(Node $object): array
    {
        $written = [];

        foreach ($object->entries() as $key => $value) {
            [$method, $arguments] = match ($value->kind()) {
                Kind::List, Kind::Empty => ['list', array_map(self::scalar(...), $value->items())],
                Kind::Map => ['nested', self::each($value)],
                Kind::Text, Kind::Integer, Kind::Number, Kind::Boolean, Kind::Null, Kind::Nothing => [
                    'of',
                    [self::scalar($value)],
                ],
            };
            $written[] = sprintf(
                'Option::%s(%s)',
                $method,
                implode(', ', [PhpCalls::literal(sprintf('%s', $key)), ...$arguments]),
            );
        }

        return $written;
    }

    private static function scalar(Node $value): string
    {
        return match ($value->kind()) {
            Kind::Text => PhpCalls::literal($value->text()),
            Kind::Integer => PhpCalls::literal($value->integer()),
            Kind::Number => PhpCalls::literal($value->number()),
            Kind::Boolean => PhpCalls::literal($value->boolean()),
            Kind::Map, Kind::List, Kind::Empty, Kind::Null, Kind::Nothing => 'null',
        };
    }
}
