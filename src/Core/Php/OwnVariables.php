<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Php;

use function array_key_exists;
use function count;

use PhpToken;

/**
 * The plain variables a scope assigns only what runs nothing, which reading
 * then runs nothing either: each assigned, by value, a value that runs
 * nothing, reading at most variables that are its own in turn.
 */
final readonly class OwnVariables
{
    /** @param array<string, true> $names by name, `$` included */
    private function __construct(private array $names)
    {
    }

    public static function none(): self
    {
        return new self([]);
    }

    /**
     * The variables these statements assign what runs nothing, reading at
     * most such variables of theirs, however the assignments are ordered.
     *
     * @param list<non-empty-list<PhpToken>> $statements
     */
    public static function assignedIn(array $statements): self
    {
        $own = self::none();

        do {
            $known = $own;

            foreach ($statements as $statement) {
                $own = Assignment::keepsToItsScope($statement, $known)
                    ? $own->with($statement[0]->text)
                    : $own;
            }
        } while (count($own->names) > count($known->names));

        return $own;
    }

    /** Whether a token is a variable of this scope's own. */
    public function has(PhpToken $token): bool
    {
        return $token->is(T_VARIABLE) && array_key_exists($token->text, $this->names);
    }

    private function with(string $name): self
    {
        return new self([...$this->names, $name => true]);
    }
}
