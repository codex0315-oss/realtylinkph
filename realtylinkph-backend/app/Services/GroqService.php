<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Property;
use App\Models\User;
use App\Support\AutoReplyPrompt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Groq's OpenAI-compatible chat API. Fast Llama models — used first for
 * RealtyLink AI's chat auto-replies, with Gemini as the fallback.
 */
class GroqService
{
    private const ENDPOINT = 'https://api.groq.com/openai/v1/chat/completions';

    public function isConfigured(): bool
    {
        return (string) config('services.groq.api_key') !== '';
    }

    /**
     * One chat completion. Returns the text, or null on any failure — the caller
     * decides whether to fall back. Bounded timeout so a queued job can't hang.
     *
     * @param  array<int, array{role:string, content:string}>  $history  oldest first
     */
    public function chat(string $system, array $history, float $temperature = 0.6, int $maxTokens = 300): ?string
    {
        if (! $this->isConfigured()) {
            return null;
        }

        $messages = [['role' => 'system', 'content' => $system]];
        foreach ($history as $m) {
            $role = ($m['role'] ?? 'user') === 'assistant' ? 'assistant' : 'user';
            $messages[] = ['role' => $role, 'content' => (string) ($m['content'] ?? '')];
        }

        try {
            $response = Http::withToken((string) config('services.groq.api_key'))
                ->timeout(20)
                ->post(self::ENDPOINT, [
                    'model'       => config('services.groq.model', 'openai/gpt-oss-120b'),
                    'messages'    => $messages,
                    'temperature' => $temperature,
                    'max_tokens'  => $maxTokens,
                ]);

            if ($response->successful()) {
                $text = $response->json('choices.0.message.content');
                if (is_string($text) && trim($text) !== '') {
                    return trim($text);
                }
                // gpt-oss is a reasoning model: with a tight max_tokens it can spend
                // the whole budget thinking and return empty content.
                Log::warning('Groq returned empty content (max_tokens too small for a reasoning model?)', [
                    'finish_reason' => $response->json('choices.0.finish_reason'),
                ]);

                return null;
            }

            Log::warning('Groq call failed', ['status' => $response->status(), 'body' => mb_substr($response->body(), 0, 300)]);
        } catch (\Throwable $e) {
            Log::warning('Groq call exception', ['error' => $e->getMessage()]);
        }

        return null;
    }

    /** @param array<int, array{role:string, content:string}> $history */
    public function autoReplyWhileAway(Property $property, User $agent, string $buyerName, array $history): ?string
    {
        // Generous budget: the model reasons before it answers, and that counts.
        return $this->chat(AutoReplyPrompt::system($property, $agent, $buyerName), $history, 0.6, 600);
    }
}
