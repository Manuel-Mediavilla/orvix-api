<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

class AiProxyController extends Controller
{
    private const RATE_LIMIT_PER_MINUTE = 10;
    private const RATE_LIMIT_PER_DAY    = 50;
    private const MAX_TOKENS = 4096;

    public function handle(Request $request)
    {
        $allowedOrigins = array_filter(explode(',', env('ALLOWED_ORIGINS', '')));
        $origin = $request->header('Origin', '');
        $sameOrigin = in_array($request->getSchemeAndHttpHost(), array_map(
            fn ($o) => rtrim($o, '/'),
            $allowedOrigins
        ), true);
        if (!empty($allowedOrigins) && $origin !== '' && !in_array($origin, $allowedOrigins, true) && !$sameOrigin) {
            return response()->json(['error' => 'Origen no autorizado'], 403);
        }

        $ipKey = 'ai_proxy_minute:' . $request->ip();
        if (RateLimiter::tooManyAttempts($ipKey, self::RATE_LIMIT_PER_MINUTE)) {
            return response()->json(['error' => 'Demasiadas solicitudes. Espera un momento.', 'retry_after' => RateLimiter::availableIn($ipKey)], 429);
        }
        RateLimiter::hit($ipKey, 60);

        $dayKey = 'ai_proxy_day:' . $request->ip();
        if (RateLimiter::tooManyAttempts($dayKey, self::RATE_LIMIT_PER_DAY)) {
            return response()->json(['error' => 'Límite diario alcanzado.'], 429);
        }
        RateLimiter::hit($dayKey, 86400);

        if (empty(env('OPENROUTER_API_KEY'))) {
            Log::error('OPENROUTER_API_KEY no configurada');
            return response()->json(['error' => 'API key no configurada en el servidor.'], 500);
        }

        $validated = $request->validate([
            'messages'   => 'required|array|min:1|max:10',
            'messages.*.role'    => 'required|in:user,assistant',
            'messages.*.content' => 'required|string|max:20000',
        ]);

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . env('OPENROUTER_API_KEY'),
                'Content-Type'  => 'application/json',
                'HTTP-Referer'  => env('APP_URL', 'https://orvixio.netlify.app'),
                'X-Title'       => 'Orvix Briefing Generator',
            ])
            ->timeout(60)
            ->post('https://openrouter.ai/api/v1/chat/completions', [
                'model'           => 'nvidia/nemotron-3-nano-30b-a3b:free',
                'max_tokens'      => self::MAX_TOKENS,
                'response_format' => ['type' => 'json_object'],
                'messages'        => $validated['messages'],
            ]);

            if ($response->failed()) {
                Log::error('OpenRouter error', [
                    'status' => $response->status(),
                    'body'   => $response->body(),
                    'ip'     => $request->ip(),
                ]);
                $message = $response->json('error.message') ?? 'Error del servicio de IA.';
                return response()->json(['error' => $message], $response->status());
            }

            $data = $response->json();
            $raw = $data['choices'][0]['message']['content'] ?? '';
            $briefing = $this->parseBriefing($raw);

            if ($briefing === null) {
                Log::error('Invalid AI JSON', ['sample' => substr($raw, 0, 500), 'ip' => $request->ip()]);
                return response()->json(['error' => 'La IA devolvió un formato inválido. Inténtalo de nuevo.'], 502);
            }

            return response()->json([
                'content' => [[
                    'type' => 'text',
                    'text' => json_encode($briefing, JSON_UNESCAPED_UNICODE),
                ]],
                'briefing' => $briefing,
            ]);

        } catch (\Exception $e) {
            Log::error('AI Proxy exception', ['message' => $e->getMessage(), 'ip' => $request->ip()]);
            return response()->json(['error' => 'Error interno.'], 500);
        }
    }

    private function parseBriefing(string $raw): ?array
    {
        $text = trim(preg_replace('/```json|```/i', '', $raw) ?? $raw);
        if (preg_match('/\{[\s\S]*/', $text, $m)) {
            $text = $m[0];
        }

        $decoded = json_decode($text, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $decoded;
        }

        $repaired = $this->repairJson($text);
        $decoded = json_decode($repaired, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $decoded;
        }

        return $this->extractBriefingFields($text);
    }

    private function repairJson(string $json): string
    {
        $json = rtrim($json);
        $json = preg_replace('/,\s*$/', '', $json) ?? $json;

        if (preg_match_all('/(?<!\\\\)"/', $json, $m) && (count($m[0]) % 2 !== 0)) {
            $json .= '"';
        }

        $stack = [];
        $inString = false;
        $escaped = false;
        $len = strlen($json);

        for ($i = 0; $i < $len; $i++) {
            $char = $json[$i];
            if ($escaped) {
                $escaped = false;
                continue;
            }
            if ($char === '\\') {
                $escaped = true;
                continue;
            }
            if ($char === '"') {
                $inString = !$inString;
                continue;
            }
            if (!$inString) {
                if ($char === '{' || $char === '[') {
                    $stack[] = $char;
                } elseif ($char === '}' || $char === ']') {
                    array_pop($stack);
                }
            }
        }

        while (!empty($stack)) {
            $open = array_pop($stack);
            $json .= $open === '{' ? '}' : ']';
        }

        return $json;
    }

    private function extractBriefingFields(string $text): ?array
    {
        $fields = [];
        $keys = ['executiveSummary', 'opportunities', 'roadmap', 'roiBreakdown', 'nextSteps', 'annualSavings', 'hoursPerWeek', 'paybackPeriod'];

        foreach ($keys as $key) {
            if (preg_match('/"' . $key . '"\s*:\s*"((?:[^"\\\\]|\\\\.)*)(?:"|$)/s', $text, $m)) {
                $fields[$key] = stripcslashes($m[1]);
            }
        }

        if (preg_match('/"techStack"\s*:\s*\[(.*?)\]/s', $text, $m)) {
            preg_match_all('/"((?:[^"\\\\]|\\\\.)*)"/', $m[1], $items);
            $fields['techStack'] = array_map('stripcslashes', $items[1] ?? []);
        }

        return count($fields) >= 3 ? $fields : null;
    }
}
