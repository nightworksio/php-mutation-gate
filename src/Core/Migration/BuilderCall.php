<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Migration;

use function count;
use function explode;
use function sprintf;

/**
 * A call of the PHP config's builder, as a step names it: a class of the
 * config's API and its method, `Reach::everything`. `Gate`'s own methods are
 * called along the chain `Gate::configure()` starts.
 */
final readonly class BuilderCall
{
    private function __construct(private string $class, private string $method)
    {
    }

    /** A call as PHP spells it: `Reach::everything`. */
    public static function of(string $spelt): self
    {
        $parts = explode('::', $spelt, 2);

        return new self($parts[0], count($parts) > 1 ? $parts[1] : '');
    }

    /** The class's name within the config's API: `Reach`. */
    public function className(): string
    {
        return $this->class;
    }

    public function method(): string
    {
        return $this->method;
    }

    /** Whether it is a method of `Gate`, called along the chain rather than statically. */
    public function isOnTheChain(): bool
    {
        return $this->class === 'Gate';
    }

    public function spelt(): string
    {
        return sprintf('%s::%s', $this->class, $this->method);
    }
}
