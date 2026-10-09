<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Init;

use function is_string;

use NightWorksIO\MutationGate\Core\Ci\Variables;

use function sprintf;

use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\ConfirmationQuestion;

/**
 * How `init` asks what detection cannot settle (ADR-0017, decision 1): at
 * the console, each question offering the detected answer as its default.
 * With `--no-interaction`, with no terminal, or with `CI` set, it asks
 * nothing, and each question takes its default.
 */
final readonly class Asking
{
    private const string YES_NO = '%s [%s] ';

    /** A question of choices, with the one Enter takes. */
    private const string OFFERED = '%s [%s]';

    private const string YES = 'Y/n';

    private const string NO = 'y/N';

    private function __construct(
        private InputInterface $input,
        private OutputInterface $output,
        private bool $interactive,
    ) {
    }

    /** Asking at this console, unless it takes no answers or runs in CI. */
    public static function at(InputInterface $input, OutputInterface $output, Variables $environment): self
    {
        return new self($input, $output, $input->isInteractive() && ! $environment->inCi());
    }

    /** Whether a question is put to someone, rather than taking its default. */
    public function isInteractive(): bool
    {
        return $this->interactive;
    }

    /**
     * The answer chosen of these, this one where nobody is asked.
     *
     * @param non-empty-list<string> $choices
     */
    public function choice(string $question, array $choices, string $default): string
    {
        if (! $this->interactive) {
            return $default;
        }

        $asked = new ChoiceQuestion(sprintf(self::OFFERED, $question, $default), $choices, $default);
        $answer = new QuestionHelper()->ask($this->input, $this->output, $asked);

        return is_string($answer) ? $answer : $default;
    }

    /** Whether the answer is yes; the default where nobody is asked. */
    public function confirms(string $question, bool $default): bool
    {
        if (! $this->interactive) {
            return $default;
        }

        $asked = new ConfirmationQuestion(sprintf(self::YES_NO, $question, $default ? self::YES : self::NO), $default);

        return new QuestionHelper()->ask($this->input, $this->output, $asked) === true;
    }
}
