<?php

namespace App\LessonPlan;

use Anthropic\Client;
use Anthropic\Core\Exceptions\AnthropicException;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\RateLimitException;
use App\Entity\LessonPlan;
use Psr\Http\Client\ClientInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\Psr18Client;

/**
 * Drafts a week's ILAW cells with Claude through the official SDK. The answer is constrained to a JSON schema with
 * one object per school day, and checked again here before anything is written to the plan.
 */
#[AsAlias(LessonPlanDrafter::class)]
class AnthropicLessonPlanDrafter implements LessonPlanDrafter
{
    /** Under XAMPP's 120 s max_execution_time, which the controller raises for drafts. */
    private const TIMEOUT_SECONDS = 110.0;

    /** What a cell is for, shown to the model in the schema. */
    private const FIELD_HINTS = [
        'objectives' => 'Learning objectives for the day: smaller knowledge, skills and values targets from the competency that learners can show by the end of the session. Measurable verbs.',
        'learnerContext' => 'Plausible observations of learners of this grade for this lesson: strengths, interests, possible barriers to learning. The teacher will revise them.',
        'preLesson' => 'How the teacher gets learners ready: review of the previous lesson, motivation, unlocking of difficulties, stating the objectives.',
        'flow' => 'The activities of the session in order, guided before independent practice, with collaboration and a check of understanding.',
        'resources' => 'Learning resources for the day, available in a public school, with an alternative if electricity or internet is out.',
        'integration' => 'Meaningful links to another learning area, a special topic, or technology. "N/A" if none fits.',
        'formativeAssessment' => 'A task or questions to check learning with feedback, and accommodations so all learners can show understanding.',
        'extendedLearning' => 'Learning outside class hours that reinforces the lesson or supports learners who struggled.',
        'reflections' => 'Short teacher reflection prompts or likely notes about what to adjust next session. The teacher will revise them after teaching.',
    ];

    private const SYSTEM = <<<'TXT'
        You draft DepEd Philippines ILAW lesson plans (Daily Lesson Log – ILAW format, DO 16 s. 2026) for a public
        school teacher, who will review and edit every cell before using it.

        Follow the Learning Design Principles: make the objectives clear to learners; guide learners before letting
        them try a task on their own; check learners' well-being, understanding and mastery; connect new concepts to
        past competencies; encourage collaboration; invite learners to reflect on why the lesson matters to them; and
        ensure inclusion of varied abilities, learning styles and contexts.

        Rules:
        - Stay within the learning competencies the teacher gives. Do not add competencies or codes of your own.
        - Spread the competencies sensibly across the days, building from simpler to harder; the last day may
          consolidate and assess.
        - Objectives cover knowledge, skills and values/attitudes, each with a measurable verb (identify, explain,
          solve, compare, demonstrate...), not "understand" or "learn".
        - Keep every bullet short and concrete, the way a teacher writes in a lesson log. 2 to 5 bullets per cell.
        - Suggest only resources a Philippine public school is likely to have (DepEd modules and learner's materials,
          LR Portal, textbooks, manila paper, chalkboard, TV or projector with a no-electricity alternative).
        - Do not invent learner names, test scores or statistics.
        - References: DepEd curriculum guides, learner's materials, and widely available references for the topic.
          Do not invent URLs.
        TXT;

    /**
     * @param ?ClientInterface $transporter only tests pass one (a simulated API); otherwise an HTTP client with the
     *                                     time limits above is used
     */
    public function __construct(
        #[Autowire(env: 'ANTHROPIC_API_KEY')]
        private readonly string $apiKey,
        #[Autowire(env: 'AI_MODEL')]
        private readonly string $model,
        private readonly LoggerInterface $logger,
        #[Autowire(expression: 'null')]
        private readonly ?ClientInterface $transporter = null,
    ) {
    }

    public function draft(DraftRequest $request): DraftResult
    {
        if ('' === trim($this->apiKey)) {
            throw new AiDraftFailed('the AI is not set up on this server');
        }

        try {
            // The SDK leaves time limits to the HTTP client. No retry: a second wait would pass PHP's time limit
            // and could bill a second draft.
            $transporter = $this->transporter ?? new Psr18Client(HttpClient::create(['timeout' => self::TIMEOUT_SECONDS, 'max_duration' => self::TIMEOUT_SECONDS]));
            $client = new Client(apiKey: $this->apiKey, requestOptions: ['transporter' => $transporter, 'maxRetries' => 0]);
            $message = $client->messages->create(
                maxTokens: 16000,
                messages: [['role' => 'user', 'content' => self::prompt($request)]],
                model: $this->model,
                system: [['type' => 'text', 'text' => self::SYSTEM]],
                outputConfig: [
                    'effort' => 'medium',
                    'format' => ['type' => 'json_schema', 'schema' => self::schema(\count($request->dates))],
                ],
            );
        } catch (APIConnectionException $e) {
            throw $this->failed('the AI did not answer in time or could not be reached', $e);
        } catch (AuthenticationException $e) {
            throw $this->failed('the AI key on this server is not valid', $e);
        } catch (RateLimitException $e) {
            throw $this->failed('the AI is busy right now', $e);
        } catch (AnthropicException $e) {
            throw $this->failed('the AI returned an error', $e);
        }

        if ('refusal' === $message->stopReason) {
            throw new AiDraftFailed('the AI declined this request');
        }
        if ('end_turn' !== $message->stopReason) {
            throw new AiDraftFailed('the AI answer was incomplete');
        }

        $json = null;
        foreach ($message->content as $block) {
            if ('text' === $block->type) {
                $json = json_decode($block->text, true);
                break;
            }
        }
        $parsed = self::parse(\is_array($json) ? $json : [], \count($request->dates));

        return new DraftResult($parsed['references'], $parsed['days'], $message->model, $message->usage->inputTokens, $message->usage->outputTokens);
    }

    /** Logs what went wrong for the owner (never the key), and returns the teacher's message. */
    private function failed(string $reason, AnthropicException $e): AiDraftFailed
    {
        $this->logger->warning('AI lesson plan draft failed: {reason}', [
            'reason' => $reason,
            'error' => $e::class,
            'message' => $e->getMessage(),
            'model' => $this->model,
        ]);

        return new AiDraftFailed($reason, $e);
    }

    /** @return array<string, mixed> JSON schema: references, and exactly $days day objects with every ILAW cell */
    public static function schema(int $days): array
    {
        $bullets = static fn (string $description) => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => $description];
        $properties = [];
        foreach (LessonPlan::DAY_FIELDS as $field) {
            $properties[$field] = $bullets(self::FIELD_HINTS[$field]);
        }

        return [
            'type' => 'object',
            'properties' => [
                'references' => $bullets('Books, modules, websites and toolkits for the whole week.'),
                'days' => [
                    'type' => 'array',
                    // structured outputs accept no maxItems and minItems of only 0 or 1; parse() checks the count
                    'description' => \sprintf('One object per school day, in the order given: exactly %d objects.', $days),
                    'items' => [
                        'type' => 'object',
                        'properties' => $properties,
                        'required' => LessonPlan::DAY_FIELDS,
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['references', 'days'],
            'additionalProperties' => false,
        ];
    }

    /** The teacher's request, as the user message. */
    public static function prompt(DraftRequest $request): string
    {
        $days = [];
        foreach ($request->dates as $i => $date) {
            $days[] = \sprintf('Day %d (%s)', $i + 1, (new \DateTimeImmutable($date))->format('l, F j, Y'));
        }

        $lines = [
            'Draft this week\'s ILAW lesson plan.',
            '',
            'School level: '.$request->level,
            'Grade: '.$request->grade,
            'Learning area: '.$request->subject,
            'Section: '.$request->section,
            'Topic / name of lesson: '.$request->topic,
            'Learning competencies:',
            ...array_map(static fn (string $c) => '- '.$c, $request->competencies),
            'School days to plan ('.\count($days).'): '.implode('; ', $days),
        ];
        if (null !== $request->classNotes) {
            $lines[] = 'Teacher\'s notes about this class: '.$request->classNotes;
        }
        $lines[] = '';
        $lines[] = $request->filipino
            ? 'Write every cell in Filipino, as this learning area is taught in Filipino.'
            : 'Write every cell in English.';

        return implode("\n", $lines);
    }

    /**
     * The decoded answer, checked: references and exactly $days days, each with every cell as a list of strings.
     *
     * @param array<mixed> $json
     *
     * @return array{references: list<string>, days: list<array<string, list<string>>>}
     */
    public static function parse(array $json, int $days): array
    {
        $isList = static fn (mixed $v) => \is_array($v) && array_is_list($v) && [] === array_filter($v, static fn (mixed $s) => !\is_string($s));
        if (!$isList($json['references'] ?? null) || !\is_array($json['days'] ?? null) || \count($json['days']) !== $days) {
            throw new AiDraftFailed('the AI answer was incomplete');
        }

        $parsed = [];
        foreach (array_values($json['days']) as $day) {
            $cells = [];
            foreach (LessonPlan::DAY_FIELDS as $field) {
                if (!\is_array($day) || !$isList($day[$field] ?? null)) {
                    throw new AiDraftFailed('the AI answer was incomplete');
                }
                $cells[$field] = array_values(array_map('trim', $day[$field]));
            }
            $parsed[] = $cells;
        }

        return ['references' => array_values(array_map('trim', $json['references'])), 'days' => $parsed];
    }
}
