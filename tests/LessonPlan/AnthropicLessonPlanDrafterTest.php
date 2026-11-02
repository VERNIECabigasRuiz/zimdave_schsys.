<?php

namespace App\Tests\LessonPlan;

use App\Entity\LessonPlan;
use App\LessonPlan\AiDraftFailed;
use App\LessonPlan\AnthropicLessonPlanDrafter;
use App\LessonPlan\DraftRequest;
use PHPUnit\Framework\TestCase;
use Symfony\Component\ErrorHandler\BufferingLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Psr18Client;
use Symfony\Component\HttpClient\Response\MockResponse;

/** The request and answer handling only; nothing here reaches the API. */
class AnthropicLessonPlanDrafterTest extends TestCase
{
    private static function request(bool $filipino = false): DraftRequest
    {
        return new DraftRequest(
            level: 'Junior High School',
            grade: 'Grade 8',
            subject: 'Mathematics',
            section: 'ARTISTIC',
            topic: 'Multiplying monomials',
            competencies: ['Multiplies simple monomials (M8AL-Ia-1)'],
            classNotes: 'Many slow readers',
            dates: ['2026-12-01', '2026-12-02'],
            filipino: $filipino,
        );
    }

    /** @return array<string, list<string>> */
    private static function day(): array
    {
        return array_fill_keys(LessonPlan::DAY_FIELDS, ['a point']);
    }

    /** @param list<array{int, string, string}> $responses status, body, and whether to record; returns the drafter and the request log */
    private static function drafter(array $responses, ?BufferingLogger $logger = null, ?\ArrayObject $sent = null): AnthropicLessonPlanDrafter
    {
        $queue = array_map(static fn (array $r) => new MockResponse($r[1], ['http_code' => $r[0], 'response_headers' => ['content-type' => 'application/json']]), $responses);
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$queue, $sent): MockResponse {
            $sent?->append(['url' => $url, 'body' => json_decode((string) $options['body'], true)]);

            return array_shift($queue) ?? throw new \LogicException('The drafter sent more requests than expected.');
        });

        return new AnthropicLessonPlanDrafter('sk-ant-test', 'claude-opus-5-5', $logger ?? new BufferingLogger(), new Psr18Client($http));
    }

    private static function message(array $answer): string
    {
        return json_encode([
            'id' => 'msg_test', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-opus-5-5',
            'content' => [['type' => 'text', 'text' => json_encode($answer)]],
            'stop_reason' => 'end_turn', 'stop_sequence' => null,
            'usage' => ['input_tokens' => 1500, 'output_tokens' => 4200],
        ]);
    }

    /** Structured outputs accept minItems of 0 or 1 only, and no maxItems: anything else is a 400 on every request. */
    public function testSchemaUsesOnlySupportedKeywords(): void
    {
        $walk = static function (array $node) use (&$walk): void {
            self::assertArrayNotHasKey('maxItems', $node);
            if (isset($node['minItems'])) {
                self::assertContains($node['minItems'], [0, 1]);
            }
            foreach ($node as $child) {
                if (\is_array($child)) {
                    $walk($child);
                }
            }
        };
        $walk(AnthropicLessonPlanDrafter::schema(5));
    }

    public function testDraftsThroughTheSdk(): void
    {
        $sent = new \ArrayObject();
        $drafter = self::drafter([[200, self::message(['references' => ['Module'], 'days' => [self::day(), self::day()]])]], sent: $sent);

        $result = $drafter->draft(self::request());

        self::assertCount(2, $result->days);
        self::assertSame(['a point'], $result->days[1]['flow']);
        self::assertSame([1500, 4200, 'claude-opus-5-5'], [$result->inputTokens, $result->outputTokens, $result->model]);
        self::assertCount(1, $sent);
        $body = $sent[0]['body'];
        self::assertStringEndsWith('/v1/messages', $sent[0]['url']);
        self::assertSame('claude-opus-5-5', $body['model']);
        self::assertSame('json_schema', $body['output_config']['format']['type']);
        self::assertStringContainsString('exactly 2', $body['output_config']['format']['schema']['properties']['days']['description']);
    }

    public function testApiErrorIsLoggedAndFriendly(): void
    {
        $logger = new BufferingLogger();
        $drafter = self::drafter([[400, json_encode(['type' => 'error', 'error' => ['type' => 'invalid_request_error', 'message' => 'output_config.format.schema: maxItems is not supported']])]], $logger);

        try {
            $drafter->draft(self::request());
            self::fail('Expected the draft to fail.');
        } catch (AiDraftFailed $e) {
            self::assertSame('the AI returned an error', $e->reason);
        }

        $logs = $logger->cleanLogs();
        self::assertCount(1, $logs);
        self::assertSame('warning', $logs[0][0]);
        self::assertStringContainsString('maxItems is not supported', json_encode($logs[0]));
        self::assertStringNotContainsString('sk-ant-test', json_encode($logs[0]));
    }

    /** A retry would run past PHP's time limit and could bill a second draft. */
    public function testOverloadedIsNotRetried(): void
    {
        $sent = new \ArrayObject();
        $drafter = self::drafter([[529, json_encode(['type' => 'error', 'error' => ['type' => 'overloaded_error', 'message' => 'Overloaded']])]], sent: $sent);

        try {
            $drafter->draft(self::request());
            self::fail('Expected the draft to fail.');
        } catch (AiDraftFailed) {
        }

        self::assertCount(1, $sent);
    }

    public function testSchemaAsksForExactDays(): void
    {
        $schema = AnthropicLessonPlanDrafter::schema(3);

        self::assertStringContainsString('exactly 3', $schema['properties']['days']['description']);
        self::assertSame(LessonPlan::DAY_FIELDS, $schema['properties']['days']['items']['required']);
        self::assertFalse($schema['properties']['days']['items']['additionalProperties']);
        self::assertSame(['references', 'days'], $schema['required']);
    }

    public function testPromptCarriesInputsAndLanguage(): void
    {
        $prompt = AnthropicLessonPlanDrafter::prompt(self::request());

        foreach (['Grade 8', 'Mathematics', 'ARTISTIC', 'Multiplying monomials', 'M8AL-Ia-1', 'Many slow readers', 'Day 1 (Tuesday, December 1, 2026)', 'Day 2 (Wednesday, December 2, 2026)', 'English'] as $text) {
            self::assertStringContainsString($text, $prompt);
        }
        self::assertStringContainsString('Filipino', AnthropicLessonPlanDrafter::prompt(self::request(filipino: true)));
    }

    public function testNoKeyFails(): void
    {
        $this->expectExceptionObject(new AiDraftFailed('the AI is not set up on this server'));

        (new AnthropicLessonPlanDrafter('', 'claude-opus-5-5', new BufferingLogger()))->draft(self::request());
    }

    public function testParseKeepsAWellFormedAnswer(): void
    {
        $parsed = AnthropicLessonPlanDrafter::parse(['references' => ['Module'], 'days' => [self::day(), self::day()]], 2);

        self::assertSame(['Module'], $parsed['references']);
        self::assertSame(['a point'], $parsed['days'][1]['flow']);
    }

    public function testIncompleteAnswerFails(): void
    {
        $missing = self::day();
        unset($missing['flow']);

        foreach ([
            ['references' => ['Module'], 'days' => [self::day()]],
            ['references' => ['Module'], 'days' => [self::day(), $missing]],
            ['days' => [self::day(), self::day()]],
            ['references' => ['Module'], 'days' => [self::day(), ['flow' => 'not a list'] + self::day()]],
        ] as $answer) {
            try {
                AnthropicLessonPlanDrafter::parse($answer, 2);
                self::fail('Expected an incomplete answer to fail.');
            } catch (AiDraftFailed $e) {
                self::assertSame('the AI answer was incomplete', $e->reason);
            }
        }
    }
}
