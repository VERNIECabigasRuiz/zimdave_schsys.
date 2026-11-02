<?php

namespace App\Tests\LessonPlan;

use App\Entity\LessonPlan;
use App\LessonPlan\AiDraftFailed;
use App\LessonPlan\AnthropicLessonPlanDrafter;
use App\LessonPlan\DraftRequest;
use PHPUnit\Framework\TestCase;

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

    public function testSchemaAsksForExactDays(): void
    {
        $schema = AnthropicLessonPlanDrafter::schema(3);

        self::assertSame(3, $schema['properties']['days']['minItems']);
        self::assertSame(3, $schema['properties']['days']['maxItems']);
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

        (new AnthropicLessonPlanDrafter('', 'claude-opus-5-5'))->draft(self::request());
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
