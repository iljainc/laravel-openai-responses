<?php

namespace Idpromogroup\LaravelOpenaiResponses\Services;

use Idpromogroup\LaravelOpenaiResponses\Models\LorRequestLog;
use Illuminate\Support\Facades\Log;

/**
 * Сохраняет usage и расчёт стоимости в lor_request_logs по ответу Responses API.
 */
class UsageBillingService
{
    public function record(LorRequestLog $log, ?array $response, ?string $fallbackModel = null): void
    {
        if (!config('openai-responses.billing.enabled', true)) {
            return;
        }

        if ($response === null) {
            return;
        }

        $usage = $response['usage'] ?? null;
        if (!is_array($usage)) {
            return;
        }

        $normalized = $this->normalizeUsage($usage);
        if ($normalized['input_tokens'] === 0 && $normalized['output_tokens'] === 0) {
            return;
        }

        $model = isset($response['model']) && is_string($response['model']) && $response['model'] !== ''
            ? $response['model']
            : ($fallbackModel ?? null);

        $priceRow = $model !== null ? $this->priceRowForModel($model) : null;
        if ($priceRow === null && $model !== null) {
            lor_debug("UsageBillingService::record() — нет цен в конфиге для модели: {$model}");
        }

        $costs = $priceRow !== null
            ? $this->computeCostBreakdown($normalized, $priceRow)
            : null;

        try {
            $log->update([
                'model' => $model,
                'input_tokens' => $normalized['input_tokens'],
                'cached_input_tokens' => $normalized['cached_input_tokens'],
                'output_tokens' => $normalized['output_tokens'],
                'reasoning_tokens' => $normalized['reasoning_tokens'],
                'input_cost' => $costs['input_cost'] ?? null,
                'cached_input_cost' => $costs['cached_input_cost'] ?? null,
                'output_cost' => $costs['output_cost'] ?? null,
                'total_cost' => $costs['total_cost'] ?? null,
            ]);
        } catch (\Throwable $e) {
            Log::warning('LOR: UsageBillingService::record() failed', [
                'request_log_id' => $log->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return array{input_tokens:int,cached_input_tokens:int,output_tokens:int,reasoning_tokens:int}
     */
    private function normalizeUsage(array $usage): array
    {
        $input = (int) ($usage['input_tokens'] ?? $usage['prompt_tokens'] ?? 0);
        $output = (int) ($usage['output_tokens'] ?? $usage['completion_tokens'] ?? 0);

        $cached = 0;
        if (isset($usage['input_tokens_details']) && is_array($usage['input_tokens_details'])) {
            $cached = (int) ($usage['input_tokens_details']['cached_tokens'] ?? 0);
        }
        $cached = min($cached, max(0, $input));

        $reasoning = 0;
        if (isset($usage['output_tokens_details']) && is_array($usage['output_tokens_details'])) {
            $reasoning = (int) ($usage['output_tokens_details']['reasoning_tokens'] ?? 0);
        }

        return [
            'input_tokens' => $input,
            'cached_input_tokens' => $cached,
            'output_tokens' => $output,
            'reasoning_tokens' => $reasoning,
        ];
    }

    /**
     * @return array{input:float,cached_input:float,output:float}|null цены за 1M токенов (USD)
     */
    private function priceRowForModel(string $model): ?array
    {
        $prices = config('openai-responses.prices', []);
        if (!is_array($prices) || $prices === []) {
            return null;
        }

        if (isset($prices[$model]) && is_array($prices[$model])) {
            return $this->normalizePriceRow($prices[$model]);
        }

        $bestKey = null;
        foreach (array_keys($prices) as $key) {
            if (!is_string($key) || $key === '') {
                continue;
            }
            if (str_starts_with($model, $key) && ($bestKey === null || strlen($key) > strlen($bestKey))) {
                $bestKey = $key;
            }
        }

        if ($bestKey !== null && isset($prices[$bestKey]) && is_array($prices[$bestKey])) {
            return $this->normalizePriceRow($prices[$bestKey]);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{input:float,cached_input:float,output:float}
     */
    private function normalizePriceRow(array $row): array
    {
        $input = (float) ($row['input'] ?? 0);
        $output = (float) ($row['output'] ?? 0);
        $cached = isset($row['cached_input']) ? (float) $row['cached_input'] : $input;

        return [
            'input' => $input,
            'cached_input' => $cached,
            'output' => $output,
        ];
    }

    /**
     * @param  array{input_tokens:int,cached_input_tokens:int,output_tokens:int,reasoning_tokens:int}  $u
     * @param  array{input:float,cached_input:float,output:float}  $p
     * @return array{input_cost:float,cached_input_cost:float,output_cost:float,total_cost:float}
     */
    private function computeCostBreakdown(array $u, array $p): array
    {
        $nonCached = max(0, $u['input_tokens'] - $u['cached_input_tokens']);

        $inputCost = round(($nonCached / 1_000_000) * $p['input'], 8);
        $cachedInputCost = round(($u['cached_input_tokens'] / 1_000_000) * $p['cached_input'], 8);
        $outputCost = round(($u['output_tokens'] / 1_000_000) * $p['output'], 8);

        return [
            'input_cost' => $inputCost,
            'cached_input_cost' => $cachedInputCost,
            'output_cost' => $outputCost,
            'total_cost' => round($inputCost + $cachedInputCost + $outputCost, 8),
        ];
    }
}
