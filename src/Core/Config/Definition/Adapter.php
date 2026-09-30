<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config\Definition;

use function is_string;

use NightWorksIO\MutationGate\Core\Config\Effect;

/**
 * A setting that chooses an adapter (ADR-0002): a registered name such as
 * `pest`, a class, or `{"use": <name or class>, "with": <options>}`.
 */
final readonly class Adapter implements Node
{
    /** @param Section<Fields> $written */
    private function __construct(private Builtins $builtins, private Section $written)
    {
    }

    public static function choosing(Builtins $builtins): self
    {
        return new self($builtins, Section::fields(
            Field::required('use', Text::of('a name or a class'), Effect::JudgesOrReportsOnly),
            Field::setting('with', OpenObject::any(), Effect::JudgesOrReportsOnly, []),
        ));
    }

    public function read(mixed $value, string $at): Reading
    {
        if (is_string($value) && $value !== '') {
            return $this->builtins->choose($value, [], $at);
        }

        if (! Json::isMap($value)) {
            return Reading::mismatch($at, $this->expected(), $value);
        }

        $written = $this->written->read($value, $at);
        $fields = $written->value();

        return $fields instanceof Fields
            ? $this->builtins->choose($fields->string('use'), Json::decode($fields->string('with')), $at)
            : $written;
    }

    public function expected(): string
    {
        return 'a name, a class, or an object with use and with';
    }

    public function schema(): array
    {
        return ['anyOf' => [['type' => 'string', 'minLength' => 1], ...$this->builtins->schemas([], [])]];
    }

    public function effects(): array
    {
        return $this->builtins->effects();
    }
}
