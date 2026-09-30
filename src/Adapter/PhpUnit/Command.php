<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\PhpUnit;

use function array_values;

use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unlimited;

/**
 * A program the adapter runs on the PHP that runs the gate: its arguments,
 * the variables it is told, those it never sees, and its deadline.
 */
final readonly class Command
{
    /**
     * @param list<string>          $arguments after the PHP binary
     * @param array<string, string> $told      the variables it is set
     */
    private function __construct(
        private array $arguments,
        private array $told,
        private Withheld $withheld,
        private Seconds|Unlimited $deadline,
    ) {
    }

    public static function php(string ...$arguments): self
    {
        return new self(array_values($arguments), [], Withheld::standard(), Unlimited::time());
    }

    public function telling(Variable $variable, string $value): self
    {
        $told = [...$this->told, $variable->value => $value];

        return new self($this->arguments, $told, $this->withheld, $this->deadline);
    }

    public function withholding(Withheld $withheld): self
    {
        return new self($this->arguments, $this->told, $this->withheld->and($withheld), $this->deadline);
    }

    public function within(Seconds|Unlimited $deadline): self
    {
        return new self($this->arguments, $this->told, $this->withheld, $deadline);
    }

    /** @return list<string> the PHP binary, then the arguments */
    public function arguments(): array
    {
        return [PHP_BINARY, ...$this->arguments];
    }

    /** @return array<string, string> */
    public function environment(): array
    {
        return $this->told;
    }

    public function withheld(): Withheld
    {
        return $this->withheld;
    }

    public function deadline(): Seconds|Unlimited
    {
        return $this->deadline;
    }
}
