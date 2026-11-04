<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config\Definition;

use function array_map;

use NightWorksIO\MutationGate\Core\Config\Context;
use NightWorksIO\MutationGate\Core\Config\Contexts;
use NightWorksIO\MutationGate\Core\Config\Effect;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Layer;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Kind;
use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\Format\Node;

use function sprintf;

/**
 * How `contexts` is read into a layer's `Contexts` part (ADR-0071): a block per context, each holding the keys
 * of a layer but those `Placement::ContextBlock` refuses. A block's settings are judged once a run lays it, so
 * the key itself is the one setting it declares, and the schema names a block's keys once, under `$defs`.
 *
 * @internal
 *
 * @implements Shape<Layer>
 */
final readonly class ContextsKeys implements Shape
{
    /** The name a block's schema is defined under in `$defs`. */
    public const string BLOCK = 'contextBlock';

    private const string DESCRIPTION
        = 'What a run lays over the rest in each context: local, else ci with pr or main over it.';

    /** @param Section<Layer> $contexts */
    private function __construct(private Section $contexts)
    {
    }

    /**
     * The `contexts` key, each of whose blocks reads as this section reads a layer.
     *
     * @param  Section<Layer>     $block
     * @return list<Field<Layer>>
     */
    public static function fields(Section $block): array
    {
        return [Field::optional('contexts', self::shape($block), Effect::AffectsResults)];
    }

    /**
     * An object of a block per context, each read as this section reads a layer into its context's part.
     *
     * @param Section<Layer> $block
     */
    public static function shape(Section $block): self
    {
        $fields = array_map(
            static fn(Context $context): Field => Field::section($context->value, $block),
            Context::cases(),
        );

        return new self(Section::of(static function (Node $contexts) use ($fields): Layer|Invalid {
            $readings = array_map(static fn(Field $field): Reading => $field->read($contexts), $fields);

            return Reading::built(static function () use ($readings, $contexts): Layer {
                $laid = Layer::of();

                foreach (Context::cases() as $index => $context) {
                    $written = $contexts->field($context->value)->kind() !== Kind::Nothing;
                    $block = $readings[$index]->value();
                    $laid = $written && $block instanceof Layer
                        ? $laid->over(Layer::of(Contexts::of($context, $block)))
                        : $laid;
                }

                return $laid;
            }, ...$readings);
        }, ...$fields));
    }

    public function read(Node $at): Reading
    {
        return $this->contexts->read($at);
    }

    public function expected(): string
    {
        return $this->contexts->expected();
    }

    public function schema(): Json
    {
        $block = Json::object(Member::of('$ref', sprintf('#/$defs/%s', self::BLOCK)));
        $properties = Json::object();

        foreach (Context::cases() as $context) {
            $properties = $properties->with(Member::of($context->value, $block));
        }

        return Json::object(Member::of('type', 'object'))
            ->with(Member::of('description', self::DESCRIPTION))
            ->with(Member::of('properties', $properties))
            ->with(Member::of('additionalProperties', value: false));
    }

    public function effects(): array
    {
        return [];
    }
}
