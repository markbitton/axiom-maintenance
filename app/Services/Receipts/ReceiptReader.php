<?php

namespace App\Services\Receipts;

use Anthropic\Client;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Reads a receipt photo with Claude: store, date, totals and each line item.
 * Only runs when AI is turned on (AI_TRIAGE_ENABLED + ANTHROPIC_API_KEY); otherwise the tech types the prices.
 */
class ReceiptReader
{
    private const PROMPT = <<<'TXT'
This is a photo of a store receipt for maintenance parts and supplies.
Read it and return the store name as printed, the purchase date (YYYY-MM-DD), subtotal, tax, total,
and every purchased item line: description as printed (expand obvious abbreviations), the item/SKU number if shown,
quantity, unit price and line amount. Skip payment, change, coupons summary and loyalty lines; include discounts as
negative amounts on the item they apply to. Use null for anything you can't read.
TXT;

    public function __construct(private ?Client $client = null) {}

    public function enabled(): bool
    {
        return (bool) config('axiom.ai.enabled') && (string) config('services.anthropic.key') !== '';
    }

    /** @return array{vendor:?string, date:?string, subtotal:?float, tax:?float, total:?float, lines:list<array>}|null */
    public function read(string $bytes, string $mediaType): ?array
    {
        if (! $this->enabled() || ! in_array($mediaType, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) {
            return null;
        }
        try {
            $client = $this->client ?? new Client(apiKey: (string) config('services.anthropic.key'));
            $message = $client->messages->create(
                model: config('axiom.ai.model'),
                maxTokens: 4000,
                messages: [[
                    'role' => 'user',
                    'content' => [
                        ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $mediaType, 'data' => base64_encode($bytes)]],
                        ['type' => 'text', 'text' => self::PROMPT],
                    ],
                ]],
                outputConfig: [
                    'effort' => config('axiom.ai.effort', 'low'),
                    'format' => ['type' => 'json_schema', 'schema' => self::schema()],
                ],
            );
            if ($message->stopReason === 'refusal') {
                return null;
            }
            foreach ($message->content as $block) {
                if ($block->type === 'text') {
                    $data = json_decode($block->text, true);

                    return is_array($data) ? $data : null;
                }
            }
        } catch (Throwable $e) {
            Log::warning('Receipt reading failed', ['error' => $e->getMessage()]);
        }

        return null;
    }

    private static function schema(): array
    {
        $num = ['type' => ['number', 'null']];
        $str = ['type' => ['string', 'null']];

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['vendor', 'date', 'subtotal', 'tax', 'total', 'lines'],
            'properties' => [
                'vendor' => $str, 'date' => $str, 'subtotal' => $num, 'tax' => $num, 'total' => $num,
                'lines' => ['type' => 'array', 'items' => [
                    'type' => 'object', 'additionalProperties' => false,
                    'required' => ['description', 'sku', 'quantity', 'unit_price', 'amount'],
                    'properties' => ['description' => ['type' => 'string'], 'sku' => $str, 'quantity' => $num, 'unit_price' => $num, 'amount' => $num],
                ]],
            ],
        ];
    }
}
