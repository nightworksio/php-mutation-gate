<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Serve;

use function array_map;

use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\NotGiven;

use function sprintf;

/**
 * What a client asks a daemon of `mutation-gate serve` for, on one line of
 * JSON (ADR-0078): to run a command line, the gate's script first, in the
 * project's root, with the variables of the client's environment a daemon takes
 * (see ServedVariables), what it reads on its standard input, and its output
 * decorated as the client's terminal is; or to stop. It carries the
 * client's identity, which the daemon checks first.
 *
 * @internal
 */
final readonly class ServeRequest
{
    /**
     * @param list<string>          $argv        the command line, the gate's script first
     * @param array<string, string> $environment each variable, by its name
     */
    private function __construct(
        private ServeIdentity $identity,
        private bool $stops,
        private array $argv,
        private array $environment,
        private string $input,
        private bool $decorated,
    ) {
    }

    /**
     * @param list<string>          $argv        the command line, the gate's script first
     * @param array<string, string> $environment each variable, by its name
     */
    public static function running(
        ServeIdentity $identity,
        array $argv,
        array $environment,
        string $input,
        bool $decorated,
    ): self {
        return new self(
            $identity,
            stops: false,
            argv: $argv,
            environment: $environment,
            input: $input,
            decorated: $decorated,
        );
    }

    public static function stopping(ServeIdentity $identity): self
    {
        return new self($identity, stops: true, argv: [], environment: [], input: '', decorated: false);
    }

    /** The request on this line; or none, where the line is not one. */
    public static function readFrom(string $line): self|NotGiven
    {
        try {
            return self::read(Node::decode($line));
        } catch (NotInShape) {
            return NotGiven::value();
        }
    }

    public function identity(): ServeIdentity
    {
        return $this->identity;
    }

    /** Whether it asks the daemon to stop. */
    public function stops(): bool
    {
        return $this->stops;
    }

    /** @return list<string> */
    public function argv(): array
    {
        return $this->argv;
    }

    /** @return array<string, string> */
    public function environment(): array
    {
        return $this->environment;
    }

    /** What the client read on its standard input, for the command to read as its own. */
    public function input(): string
    {
        return $this->input;
    }

    /** Whether its output is decorated, as the client's terminal is. */
    public function isDecorated(): bool
    {
        return $this->decorated;
    }

    /** The request on one line of JSON, ended by a newline. */
    public function line(): string
    {
        $environment = [];

        foreach ($this->environment as $name => $value) {
            $environment[] = Member::of($name, $value);
        }

        $json = Json::object(
            Member::of('identity', $this->identity->written()),
            Member::of('stop', $this->stops),
            Member::of('argv', Json::items(...$this->argv)),
            Member::of('environment', Json::object(...$environment)),
            Member::of('input', $this->input),
            Member::of('decorated', $this->decorated),
        );

        return sprintf("%s\n", $json->line());
    }

    /** @throws NotInShape */
    private static function read(Node $at): self
    {
        $environment = [];

        foreach ($at->field('environment')->entries() as $name => $value) {
            $environment[sprintf('%s', $name)] = $value->text();
        }

        return new self(
            ServeIdentity::read($at->field('identity')),
            $at->field('stop')->boolean(),
            array_map(static fn(Node $argument): string => $argument->text(), $at->field('argv')->items()),
            $environment,
            $at->field('input')->text(),
            $at->field('decorated')->boolean(),
        );
    }
}
