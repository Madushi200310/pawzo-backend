<?php

namespace Tests\Feature;

use App\Services\ChatbotService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ChatbotRulesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.openai.api_key' => null,
        ]);

        Http::preventStrayRequests();
    }

    public function test_microchip_does_not_match_the_hi_greeting(): void
    {
        $result = app(ChatbotService::class)
            ->respond('Explain microchip registration.');

        $this->assertSame('fallback', $result['source']);
        $this->assertStringNotContainsString('Hello!', $result['message']);

        Http::assertNothingSent();
    }

    public function test_hi_matches_as_a_separate_word(): void
    {
        $result = app(ChatbotService::class)->respond('Hi!');

        $this->assertSame('rule', $result['source']);
        $this->assertStringContainsString('Hello!', $result['message']);

        Http::assertNothingSent();
    }

    public function test_grooming_question_matches_its_rule(): void
    {
        $result = app(ChatbotService::class)
            ->respond('Give me grooming tips.');

        $this->assertSame('rule', $result['source']);
        $this->assertStringContainsString('Grooming Tips', $result['message']);

        Http::assertNothingSent();
    }

    public function test_keywords_inside_other_words_do_not_match(): void
    {
        foreach (['This', 'child', 'village', 'lostness'] as $message) {
            $result = app(ChatbotService::class)->respond($message);

            $this->assertSame(
                'fallback',
                $result['source'],
                "Unexpected rule match for: {$message}",
            );
        }

        Http::assertNothingSent();
    }

    public function test_sinhala_keyword_matches(): void
    {
        $result = app(ChatbotService::class)->respond('ආහාර');

        $this->assertSame('rule', $result['source']);
        $this->assertStringContainsString(
            'Feeding Guidelines',
            $result['message'],
        );

        Http::assertNothingSent();
    }

    public function test_rules_work_without_calling_a_configured_provider(): void
    {
        config([
            'services.openai.api_key' => 'test-key-not-a-real-credential',
        ]);

        $result = app(ChatbotService::class)->respond('Hello!');

        $this->assertSame('rule', $result['source']);

        Http::assertNothingSent();
    }
}