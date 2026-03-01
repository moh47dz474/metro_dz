<?php

// ─────────────────────────────────────────────────────────────────────────────
//  app/Http/Controllers/ScannerController.php
//
//  Dedicated gate scanner controller.
//  Handles ONLY QR scanning & validation at the gate.
//  Ticket creation lives in TicketController — this never touches that.
//
//  SETUP:
//  Add to routes/api.php:
//    use App\Http\Controllers\ScannerController;
//    Route::post('/scanner/scan', [ScannerController::class, 'scan']);
//
//  Test with curl:
//    curl -X POST http://localhost:8000/api/scanner/scan \
//      -H "Content-Type: application/json" \
//      -H "Accept: application/json" \
//      -d '{"qr_data": "<paste raw QR JSON string here>"}'
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class ScannerController extends Controller
{
    // Must match the secret in TicketController / .env
    private function getHmacSecret(): string
    {
        return env('TICKET_HMAC_SECRET', '8kN9mP2vL5xR7wQ4tY6zH3jF0bG1nD8cK5sA9eW2rT7uI4oX6vB3mN0pL9qZ5hJ8');
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  POST /api/scanner/scan
    //  Called by the Flutter gate scanner app.
    //
    //  Request:  { "qr_data": "<raw JSON string from QR code>", "gate_id": "GATE-01" }
    //  Response: { "valid": true/false, "message": "...", ... }
    // ─────────────────────────────────────────────────────────────────────────
    public function scan(Request $request): JsonResponse
    {
        $request->validate([
            'qr_data' => 'required|string',
            'gate_id' => 'nullable|string|max:50',
        ]);

        $gateId = $request->input('gate_id', 'UNKNOWN');

        // Step 1 — Decode the QR JSON payload
        $payload = $this->decodeQrData($request->input('qr_data'));
        if (!$payload) {
            return $this->fail('INVALID_FORMAT',
                'This QR code is not a valid metro ticket.');
        }

        // Step 2 — Verify HMAC signature (prevents forged/tampered QR codes)
        if (!$this->verifySignature($payload)) {
            return $this->fail('INVALID_SIGNATURE',
                'This QR code has an invalid signature and cannot be trusted.');
        }

        // Step 3 — Route to the right validator based on ticket type
        $ticketType = $payload['ticket_type'] ?? null;

        return match ($ticketType) {
            'STANDARD'     => $this->validateStandardTicket($payload, $gateId),
            'SUBSCRIPTION' => $this->validateSubscription($payload, $gateId),
            default        => $this->fail('UNKNOWN_TICKET_TYPE',
                                 "Unrecognised ticket type: \"{$ticketType}\"."),
        };
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  Validate a standard (trip-based) ticket
    // ─────────────────────────────────────────────────────────────────────────
    private function validateStandardTicket(array $payload, string $gateId): JsonResponse
    {
        $ticketUuid = $payload['ticket_id'] ?? null;
        if (!$ticketUuid) {
            return $this->fail('MISSING_TICKET_ID', 'Ticket ID is missing from the QR code.');
        }

        try {
            return DB::transaction(function () use ($payload, $ticketUuid, $gateId) {

                // Lock the row so two simultaneous scans can't both pass
                $ticket = DB::table('tickets')
                    ->where('ticket_uuid', $ticketUuid)
                    ->lockForUpdate()
                    ->first();

                if (!$ticket) {
                    return $this->fail('NOT_FOUND', 'Ticket not found in the system.');
                }

                // ── Status checks ─────────────────────────────────────────────
                if ($ticket->status === 2) {
                    return $this->fail('EXPIRED', 'This ticket has expired.');
                }
                if ($ticket->status === 1) {
                    return $this->fail('EXHAUSTED', 'This ticket has no remaining trips.');
                }
                if ($ticket->status === 3) {
                    return $this->fail('SUSPENDED',
                        'This ticket is suspended. Please contact support.');
                }

                // ── Expiry date ───────────────────────────────────────────────
                if ($ticket->expires_at && strtotime($ticket->expires_at) < time()) {
                    DB::table('tickets')
                        ->where('id', $ticket->id)
                        ->update(['status' => 2, 'updated_at' => now()]);
                    return $this->fail('EXPIRED', 'This ticket has expired.');
                }

                // ── Remaining trips ───────────────────────────────────────────
                if ($ticket->remaining_trips <= 0) {
                    DB::table('tickets')
                        ->where('id', $ticket->id)
                        ->update(['status' => 1, 'updated_at' => now()]);
                    return $this->fail('NO_TRIPS', 'No remaining trips on this ticket.');
                }

                // ── Nonce / anti-replay ───────────────────────────────────────
                $payloadNonce = $payload['anti_fraud']['nonce'] ?? null;
                if ($payloadNonce && $ticket->nonce !== $payloadNonce) {
                    return $this->fail('REPLAY_ATTACK',
                        'This QR code has already been used. Please refresh your ticket.');
                }

                // ── Cooldown check (120 seconds between scans) ────────────────
                $cooldown = $payload['usage_rules']['cooldown_seconds'] ?? 120;
                if ($ticket->last_scan_time) {
                    $secondsSinceLastScan = time() - strtotime($ticket->last_scan_time);
                    if ($secondsSinceLastScan < $cooldown) {
                        $wait = $cooldown - $secondsSinceLastScan;
                        return $this->fail('COOLDOWN',
                            "Please wait {$wait} seconds before scanning again.");
                    }
                }

                // ── All checks passed — consume one trip ──────────────────────
                $newRemaining = $ticket->remaining_trips - 1;
                $newUsed      = ($ticket->used_trips ?? 0) + 1;
                $newStatus    = $newRemaining <= 0 ? 1 : 0;
                $newNonce     = $this->generateNonce();

                // Record the trip
                DB::table('trips')->insert([
                    'ticket_id'     => $ticket->id,
                    'user_id'       => $ticket->user_id,
                    'entry_station' => 'Gate ' . $gateId,
                    'entry_time'    => now(),
                    'status'        => 'active',
                    'created_at'    => now(),
                ]);

                // Update the ticket
                DB::table('tickets')
                    ->where('id', $ticket->id)
                    ->update([
                        'remaining_trips' => $newRemaining,
                        'used_trips'      => $newUsed,
                        'status'          => $newStatus,
                        'nonce'           => $newNonce,
                        'scan_counter'    => ($ticket->scan_counter ?? 0) + 1,
                        'last_scan_time'  => now(),
                        'last_gate_id'    => $gateId,
                        'updated_at'      => now(),
                    ]);

                // Get passenger name for the response
                $passenger = DB::table('passengers')
                    ->where('id', $ticket->user_id)
                    ->select('full_name')
                    ->first();

                return response()->json([
                    'valid'           => true,
                    'message'         => "Ticket verified — you're good to go!",
                    'passenger'       => $passenger?->full_name ?? 'Passenger',
                    'remaining_trips' => $newRemaining,
                    'gate_id'         => $gateId,
                    'scanned_at'      => now()->toDateTimeString(),
                ], 200);
            });
        } catch (\Exception $e) {
            return $this->fail('SERVER_ERROR',
                'A server error occurred. Please try again.');
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  Validate a subscription ticket
    // ─────────────────────────────────────────────────────────────────────────
    private function validateSubscription(array $payload, string $gateId): JsonResponse
    {
        $subscriptionUuid = $payload['subscription_id'] ?? null;
        if (!$subscriptionUuid) {
            return $this->fail('MISSING_SUB_ID', 'Subscription ID is missing from the QR code.');
        }

        try {
            return DB::transaction(function () use ($payload, $subscriptionUuid, $gateId) {

                $subscription = DB::table('subscriptions')
                    ->where('subscription_uuid', $subscriptionUuid)
                    ->lockForUpdate()
                    ->first();

                // Fallback: some subscriptions might not have a uuid column yet
                if (!$subscription) {
                    $userId = $payload['user']['user_id'] ?? null;
                    if ($userId) {
                        // Strip "USR-" prefix if present
                        $rawId = ltrim($userId, 'USR-0');
                        $subscription = DB::table('subscriptions')
                            ->where('passenger_id', $rawId)
                            ->where('status', 'ACTIVE')
                            ->lockForUpdate()
                            ->first();
                    }
                }

                if (!$subscription) {
                    return $this->fail('NOT_FOUND', 'Subscription not found.');
                }

                // ── Status ────────────────────────────────────────────────────
                if ($subscription->status !== 'ACTIVE') {
                    return $this->fail('INACTIVE_SUBSCRIPTION',
                        "Your subscription is {$subscription->status}.");
                }

                // ── Expiry ────────────────────────────────────────────────────
                if (strtotime($subscription->valid_to) < time()) {
                    return $this->fail('EXPIRED',
                        'Your subscription has expired. Please renew.');
                }

                // ── Hourly nonce check ────────────────────────────────────────
                $currentEpoch     = floor(time() / 3600) * 3600;
                $expectedNonce    = $this->generateHourlyNonce($subscription->id, $currentEpoch);
                $payloadNonce     = $payload['anti_fraud']['nonce'] ?? null;

                if ($payloadNonce && !hash_equals($expectedNonce, $payloadNonce)) {
                    return $this->fail('EXPIRED_QR',
                        'Your QR code has expired. Please refresh the ticket screen.');
                }

                // ── Cooldown (90 seconds for subscribers) ─────────────────────
                $cooldown = $payload['usage_limits']['cooldown_seconds'] ?? 90;
                if (!empty($subscription->last_scan_time)) {
                    $secondsSince = time() - strtotime($subscription->last_scan_time);
                    if ($secondsSince < $cooldown) {
                        $wait = $cooldown - $secondsSince;
                        return $this->fail('COOLDOWN',
                            "Please wait {$wait} seconds before scanning again.");
                    }
                }

                // ── Record trip & update subscription ─────────────────────────
                $userId = $subscription->passenger_id;

                DB::table('trips')->insert([
                    'ticket_id'     => null,
                    'user_id'       => $userId,
                    'entry_station' => 'Gate ' . $gateId,
                    'entry_time'    => now(),
                    'status'        => 'active',
                    'created_at'    => now(),
                ]);

                DB::table('subscriptions')
                    ->where('id', $subscription->id)
                    ->update([
                        'last_scan_time' => now(),
                        'last_gate_id'   => $gateId,
                        'updated_at'     => now(),
                    ]);

                $passenger = DB::table('passengers')
                    ->where('id', $userId)
                    ->select('full_name')
                    ->first();

                return response()->json([
                    'valid'       => true,
                    'message'     => "Subscription valid — welcome aboard!",
                    'passenger'   => $passenger?->full_name ?? 'Subscriber',
                    'plan'        => $payload['subscription']['plan_code'] ?? 'SUBSCRIPTION',
                    'valid_until' => date('d M Y', strtotime($subscription->valid_to)),
                    'gate_id'     => $gateId,
                    'scanned_at'  => now()->toDateTimeString(),
                ], 200);
            });
        } catch (\Exception $e) {
            return $this->fail('SERVER_ERROR',
                'A server error occurred. Please try again.');
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  Helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function decodeQrData(string $raw): ?array
    {
        try {
            $data = json_decode($raw, true);
            if (!is_array($data) || !isset($data['version'])) {
                return null;
            }
            return $data;
        } catch (\Exception $e) {
            return null;
        }
    }

    private function verifySignature(array $payload): bool
    {
        if (!isset($payload['signature']['value'])) {
            return false;
        }
        $provided   = $payload['signature']['value'];
        $calculated = $this->generateHmacSignature($payload);
        return hash_equals($calculated, $provided);
    }

    private function generateHmacSignature(array $data): string
    {
        unset($data['signature']);
        $canonical = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return base64_encode(hash_hmac('sha256', $canonical, $this->getHmacSecret(), true));
    }

    private function generateNonce(int $length = 18): string
    {
        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
        $nonce = '';
        for ($i = 0; $i < $length; $i++) {
            $nonce .= $chars[rand(0, strlen($chars) - 1)];
        }
        return $nonce;
    }

    private function generateHourlyNonce(int $subscriptionId, int $epoch): string
    {
        return hash('sha256', $subscriptionId . $epoch . $this->getHmacSecret());
    }

    private function fail(string $errorCode, string $message): JsonResponse
    {
        return response()->json([
            'valid'      => false,
            'message'    => $message,
            'error_code' => $errorCode,
        ], 422);
    }
}