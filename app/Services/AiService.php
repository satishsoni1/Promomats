<?php

namespace App\Services;

use App\Models\AiSetting;
use Illuminate\Support\Facades\Http;

/**
 * Thin wrapper around whichever AI provider is configured at Admin > AI Settings.
 * Every AI feature in the app (compliance pre-check, claim matching, revision
 * suggestions, natural-language search) goes through this one class - no key
 * configured means isAvailable() is false and callers fall back gracefully.
 *
 * Supports:
 *  - groq      (default) - OpenAI-compatible chat completions API, very fast inference.
 *  - anthropic - native Messages API.
 */
class AiService
{
    protected const GROQ_URL = 'https://api.groq.com/openai/v1/chat/completions';
    protected const ANTHROPIC_URL = 'https://api.anthropic.com/v1/messages';
    protected const ANTHROPIC_VERSION = '2023-06-01';

    public const DEFAULT_MODELS = [
        'groq' => 'llama-3.3-70b-versatile',
        'anthropic' => 'claude-sonnet-5',
    ];

    public function isAvailable(): bool
    {
        return AiSetting::current()->isConfigured();
    }

    /**
     * Send a single-turn prompt and return the model's text response.
     *
     * @throws \RuntimeException if AI isn't configured or the API call fails.
     */
    public function chat(string $systemPrompt, string $userPrompt, int $maxTokens = 1024): string
    {
        $settings = AiSetting::current();

        if (! $settings->isConfigured()) {
            throw new \RuntimeException('AI is not configured. Add an API key at Admin > AI Settings.');
        }

        return match ($settings->provider) {
            'anthropic' => $this->chatAnthropic($settings, $systemPrompt, $userPrompt, $maxTokens),
            default => $this->chatGroq($settings, $systemPrompt, $userPrompt, $maxTokens),
        };
    }

    protected function chatGroq(AiSetting $settings, string $systemPrompt, string $userPrompt, int $maxTokens): string
    {
        $response = Http::withToken($settings->api_key)
            ->timeout(60)
            ->post(self::GROQ_URL, [
                'model' => $settings->model ?: self::DEFAULT_MODELS['groq'],
                'max_tokens' => $maxTokens,
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $userPrompt],
                ],
            ]);

        if ($response->failed()) {
            $message = $response->json('error.message') ?? $response->body();
            throw new \RuntimeException("AI request failed ({$response->status()}): {$message}");
        }

        $text = trim((string) $response->json('choices.0.message.content'));

        if (blank($text)) {
            throw new \RuntimeException('AI returned an empty response.');
        }

        return $text;
    }

    protected function chatAnthropic(AiSetting $settings, string $systemPrompt, string $userPrompt, int $maxTokens): string
    {
        $response = Http::withHeaders([
            'x-api-key' => $settings->api_key,
            'anthropic-version' => self::ANTHROPIC_VERSION,
            'content-type' => 'application/json',
        ])->timeout(60)->post(self::ANTHROPIC_URL, [
            'model' => $settings->model ?: self::DEFAULT_MODELS['anthropic'],
            'max_tokens' => $maxTokens,
            'system' => $systemPrompt,
            'messages' => [
                ['role' => 'user', 'content' => $userPrompt],
            ],
        ]);

        if ($response->failed()) {
            $message = $response->json('error.message') ?? $response->body();
            throw new \RuntimeException("AI request failed ({$response->status()}): {$message}");
        }

        $text = collect($response->json('content', []))
            ->where('type', 'text')
            ->pluck('text')
            ->implode("\n");

        if (blank($text)) {
            throw new \RuntimeException('AI returned an empty response.');
        }

        return $text;
    }

    /**
     * A minimal round-trip used by the "Test Connection" button in Admin > AI Settings.
     */
    public function testConnection(): array
    {
        try {
            $reply = $this->chat(
                systemPrompt: 'Reply with exactly one word: OK.',
                userPrompt: 'Connection test.',
                maxTokens: 10,
            );

            return ['success' => true, 'message' => "Connected. Model replied: \"" . trim($reply) . '"'];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
}
