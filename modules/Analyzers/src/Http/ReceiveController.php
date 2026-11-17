<?php

declare(strict_types=1);

namespace Modules\Analyzers\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Analyzers\Actions\ManageMachines;
use Modules\Analyzers\Actions\ReceiveResults;

/**
 * The address the bridge program posts machine messages to.
 *
 *   POST /api/analyzers/messages
 *   Authorization: Bearer anz_...   (the machine's key)
 *   Content-Type: application/json  {"raw": "<the message as the machine sent it>"}
 *
 * Answers 202 with what became of the message, 401 for a wrong or
 * switched-off key, 422 for an empty message. HTTPS only in production.
 */
final class ReceiveController
{
    public function __invoke(Request $request, ManageMachines $machines, ReceiveResults $receive): JsonResponse
    {
        $machine = $machines->findByKey((string) $request->bearerToken());

        if ($machine === null) {
            return response()->json(['error' => 'Unknown or switched-off machine key.'], 401);
        }

        $raw = $request->input('raw');

        if (! is_string($raw) || trim($raw) === '' || strlen($raw) > 1_000_000) {
            return response()->json(['error' => 'Send the message text in "raw" (at most 1 MB).'], 422);
        }

        $message = $receive->receive($machine, $raw);

        return response()->json([
            'message' => $message->public_id,
            'status' => $message->status,
            'samples' => $message->sample_codes,
            'saved' => $message->results()->where('status', 'applied')->count(),
            'not_saved' => $message->results()->where('status', '!=', 'applied')->count(),
        ], 202);
    }
}
