<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use function array_key_exists;

use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;

use function sprintf;
use function str_replace;

/**
 * The blocks a layer writes under `contexts` (ADR-0071): for each context, the settings a run in it lays over
 * the rest of the layer. A later layer's block is laid over an earlier one's of the same context.
 *
 * @internal
 */
final readonly class Contexts implements Part
{
    /** How much deeper a block's builder calls are written than the calls around them. */
    private const string NESTED = "\n        ";

    /** @param array<string, Layer> $blocks by the context's key */
    private function __construct(private array $blocks)
    {
    }

    /** The block a run in this context lays. */
    public static function of(Context $context, Layer $block): self
    {
        return new self([$context->value => $block]);
    }

    public static function none(): self
    {
        return new self([]);
    }

    public static function standard(): self
    {
        return self::none();
    }

    public function over(Part $later): self
    {
        if (! $later instanceof self) {
            return $this;
        }

        $blocks = $this->blocks;

        foreach ($later->blocks as $key => $block) {
            $blocks[$key] = array_key_exists($key, $blocks) ? $blocks[$key]->over($block) : $block;
        }

        return new self($blocks);
    }

    /** What a run in this context lays, which is nothing where the layer writes no block for it. */
    public function block(Context $context): Layer
    {
        return array_key_exists($context->value, $this->blocks) ? $this->blocks[$context->value] : Layer::of();
    }

    public function written(PathOrigin $origin): Json
    {
        $blocks = Json::object();

        foreach (Context::cases() as $context) {
            $blocks = array_key_exists($context->value, $this->blocks)
                ? $blocks->with(Member::of($context->value, $this->blocks[$context->value]->written($origin)))
                : $blocks;
        }

        return Json::object(Member::unlessEmpty('contexts', $blocks));
    }

    public function php(PathOrigin $origin): PhpCalls
    {
        $calls = [];

        foreach (Context::cases() as $context) {
            $calls = array_key_exists($context->value, $this->blocks)
                ? [...$calls, sprintf(
                    'Context::%s(Gate::configure()%s)',
                    $context->builder(),
                    str_replace("\n", self::NESTED, $this->blocks[$context->value]->php($origin)->code()),
                )]
                : $calls;
        }

        return PhpCalls::inWith(...$calls);
    }
}
