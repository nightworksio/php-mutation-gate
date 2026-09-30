<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli;

use function array_key_exists;
use function array_map;
use function explode;
use function is_array;
use function is_string;

use NightWorksIO\MutationGate\Core\Config\Definition;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Layer;
use NightWorksIO\MutationGate\Core\Config\ProjectRoot;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\NotGiven;
use Symfony\Component\Console\Input\InputInterface;

/**
 * What the command line says about the config: the file `--config` names,
 * and the settings `--runner`, `--report`, `--budget` and `--ci` lay over
 * everything else (ADR-0002).
 */
final readonly class CommandLine
{
    /** @param list<string> $reports each as `--report` writes it, `<name>:<path>` */
    private function __construct(
        public string|NotGiven $config,
        public string|NotGiven $runner,
        public array $reports,
        public string|NotGiven $budget,
        public string|NotGiven $ci,
        public bool $firstPartyOnly,
    ) {
    }

    /** What a command's options say, where the command takes them. */
    public static function from(InputInterface $input): self
    {
        return new self(
            self::text(self::option($input, 'config')),
            self::text(self::option($input, 'runner')),
            self::texts(self::option($input, 'report')),
            self::text(self::option($input, 'budget')),
            self::text(self::option($input, 'ci')),
            $input->hasParameterOption('--no-extensions', onlyParams: true),
        );
    }

    /** A command line that says nothing, with every extension. */
    public static function nothing(): self
    {
        $none = NotGiven::value();

        return new self($none, $none, [], $none, $none, firstPartyOnly: false);
    }

    /** What the command line says, and `--config` naming a file. */
    public function withConfig(string $file): self
    {
        return clone($this, ['config' => $file]);
    }

    /** What the command line says, and `--runner`. */
    public function withRunner(string $runner): self
    {
        return clone($this, ['runner' => $runner]);
    }

    /** What the command line says, and one `--report` more, `<name>:<path>`. */
    public function withReport(string $report): self
    {
        return clone($this, ['reports' => [...$this->reports, $report]]);
    }

    /** What the command line says, and `--budget`. */
    public function withBudget(string $budget): self
    {
        return clone($this, ['budget' => $budget]);
    }

    /** What the command line says, and `--ci`. */
    public function withCi(string $plan): self
    {
        return clone($this, ['ci' => $plan]);
    }

    /** What the command line says, and `--no-extensions`: this package's own extension and no other. */
    public function firstPartyOnly(): self
    {
        return clone($this, ['firstPartyOnly' => true]);
    }

    /** What the command line says, but for a config file to read. */
    public function withoutConfig(): self
    {
        return clone($this, ['config' => NotGiven::value()]);
    }

    /** This command line, choosing this runner where it chooses none. */
    public function choosing(string $runner): self
    {
        return $this->runner instanceof NotGiven ? $this->withRunner($runner) : $this;
    }

    /** The settings the command line sets, as the last layer of the config, its paths named from the project. */
    public function layer(): Layer|Invalid
    {
        return Definition::layer(Node::config($this->written()->line()), ProjectRoot::origin());
    }

    /** The settings the command line sets, as a config file would write them. */
    public function written(): Json
    {
        $layer = Json::object();
        $layer = $this->runner instanceof NotGiven ? $layer : $layer->with(Member::of('runner', $this->runner));
        $layer = $this->reports === []
            ? $layer
            : $layer->with(Member::of('reports', Json::items(...array_map($this->report(...), $this->reports))));
        $layer = $this->budget instanceof NotGiven ? $layer : $layer->with(Member::of('budget', $this->budget));

        return $this->ci instanceof NotGiven
            ? $layer
            : $layer->with(Member::of('ci', Json::object(Member::of('plan', $this->ci))));
    }

    private function report(string $report): Json
    {
        $parts = explode(':', $report, 2);
        $written = Json::object(Member::of('use', $parts[0]));

        return array_key_exists(1, $parts) ? $written->with(Member::of('path', $parts[1])) : $written;
    }

    private static function option(InputInterface $input, string $name): mixed
    {
        return $input->hasOption($name) ? $input->getOption($name) : NotGiven::value();
    }

    /** @return list<string> */
    private static function texts(mixed $values): array
    {
        $texts = [];

        foreach (is_array($values) ? $values : [] as $value) {
            if (is_string($value)) {
                $texts[] = $value;
            }
        }

        return $texts;
    }

    /** An option's text, or nothing given where the command does not take it or it is left off. */
    private static function text(mixed $value): string|NotGiven
    {
        return is_string($value) ? $value : NotGiven::value();
    }
}
