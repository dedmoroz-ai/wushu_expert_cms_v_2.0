<?php

namespace App\Support;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Клиент LLM для AI-аналитики (docs/ANALYTICS.md).
 *
 * OpenAI-совместимый API polza.ai (POST /chat/completions, Bearer-ключ).
 * Модель по умолчанию — anthropic/claude-haiku-5.5 (быстрая и точная),
 * отдаём достаточно max_tokens и щедрый таймаут. Ответ просим в JSON
 * (response_format json_object), но терпимо парсим и ```json-блок —
 * провайдеры различаются.
 *
 * Переменные окружения:
 *  - AI_BASE_URL (по умолчанию https://polza.ai/api/v1)
 *  - AI_API_KEY  (Bearer; без него вызов завершается понятной ошибкой)
 *  - AI_MODEL    (по умолчанию anthropic/claude-haiku-5.5)
 *  - AI_TIMEOUT  (секунды; по умолчанию 180)
 */
class AiClient
{
    /** Дефолтная модель polza.ai для аналитики. */
    public const DEFAULT_MODEL = 'anthropic/claude-haiku-5.5';

    /** Таймаут по умолчанию, секунды (reasoning-модель думает долго). */
    public const DEFAULT_TIMEOUT = 180;

    /**
     * Запрос к chat/completions с JSON-ответом.
     *
     * @param  string  $system  системная инструкция
     * @param  string  $user  пользовательское сообщение (обычно JSON-дайджест)
     * @param  array<string, mixed>  $options  переопределения: model, temperature, max_tokens
     * @return array{content: string, model: string, usage: array<string, int>}
     *
     * @throws RuntimeException при отсутствии ключа, HTTP-ошибке или пустом ответе
     */
    public function complete(string $system, string $user, array $options = []): array
    {
        $apiKey = (string) config('services.ai.key', env('AI_API_KEY', ''));
        if ($apiKey === '') {
            throw new RuntimeException(
                'AI-аналитика не настроена: укажите AI_API_KEY в .env (см. docs/ANALYTICS.md).',
            );
        }

        $base = rtrim((string) config('services.ai.base_url', env('AI_BASE_URL', 'https://polza.ai/api/v1')), '/');
        $model = $options['model'] ?? config('services.ai.model', env('AI_MODEL', self::DEFAULT_MODEL));
        $timeout = (int) ($options['timeout'] ?? config('services.ai.timeout', env('AI_TIMEOUT', self::DEFAULT_TIMEOUT)));

        // reasoning-модель: include_reasoning не запрашиваем (нужен только
        // финальный текст), max_tokens щедрый, таймаут — до 3 минут.
        $response = Http::baseUrl($base)
            ->withToken($apiKey)
            ->acceptJson()
            ->timeout($timeout)
            ->retry(1, 500, fn ($e) => $e instanceof ConnectionException, throw: false)
            ->post('/chat/completions', [
                'model' => $model,
                'temperature' => $options['temperature'] ?? 0.4,
                'max_tokens' => $options['max_tokens'] ?? 12000,
                'response_format' => ['type' => 'json_object'],
                'messages' => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => $user],
                ],
            ]);

        if ($response->failed()) {
            throw new RuntimeException(sprintf(
                'LLM-запрос не выполнен: HTTP %s, %s',
                $response->status(),
                mb_substr($response->body(), 0, 500),
            ));
        }

        $content = (string) $response->json('choices.0.message.content', '');
        if (trim($content) === '') {
            throw new RuntimeException('LLM вернул пустой ответ (choices[0].message.content пуст).');
        }

        return [
            'content' => $content,
            'model' => (string) $response->json('model', $model),
            'usage' => array_filter([
                'prompt_tokens' => (int) $response->json('usage.prompt_tokens', 0),
                'completion_tokens' => (int) $response->json('usage.completion_tokens', 0),
                'total_tokens' => (int) $response->json('usage.total_tokens', 0),
            ]),
        ];
    }

    /**
     * Достаёт JSON-объект из ответа модели: сначала как есть (json_object),
     * затем — из ```json-блока, затем — первый сбалансированный объект {...}.
     *
     * @return array<string, mixed>
     *
     * @throws RuntimeException если JSON извлечь не удалось
     */
    public static function extractJson(string $content): array
    {
        $trimmed = trim($content);

        $decoded = json_decode($trimmed, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        // ```json … ``` (или просто ```) — типовой формат chat-моделей.
        if (preg_match('/```(?:json)?\s*(.*?)```/isu', $trimmed, $m)) {
            $decoded = json_decode(trim($m[1]), true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        // Первый сбалансированный объект {...} в тексте (с учётом строк и \").
        $start = strpos($trimmed, '{');
        if ($start !== false) {
            $depth = 0;
            $inString = false;
            $escape = false;
            $len = strlen($trimmed);
            for ($i = $start; $i < $len; $i++) {
                $ch = $trimmed[$i];
                if ($escape) {
                    $escape = false;

                    continue;
                }
                if ($ch === '\\') {
                    $escape = true;

                    continue;
                }
                if ($ch === '"') {
                    $inString = ! $inString;

                    continue;
                }
                if ($inString) {
                    continue;
                }
                if ($ch === '{') {
                    $depth++;
                } elseif ($ch === '}') {
                    $depth--;
                    if ($depth === 0) {
                        $decoded = json_decode(substr($trimmed, $start, $i - $start + 1), true);
                        if (is_array($decoded)) {
                            return $decoded;
                        }
                        break;
                    }
                }
            }
        }

        throw new RuntimeException('Не удалось разобрать JSON из ответа LLM: '.mb_substr($trimmed, 0, 300));
    }
}
