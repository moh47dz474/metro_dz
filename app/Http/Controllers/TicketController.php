<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

class TicketController extends Controller
{
    // HMAC secret key - MOVE THIS TO .env IN PRODUCTION!
    //private const HMAC_SECRET = env('TICKET_HMAC_SECRET');

    private function getHmacSecret()
    {
        return env('TICKET_HMAC_SECRET', '8kN9mP2vL5xR7wQ4tY6zH3jF0bG1nD8cK5sA9eW2rT7uI4oX6vB3mN0pL9qZ5hJ8');
    }
    
    // Show current ticket or subscription QR — does NOT auto-create
    public function buyTicket(Request $request)
    {
        $userId = $request->attributes->get('jwt_user_id');
        if (!$userId) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        // Check active subscription first
        $subscription = DB::table('subscriptions')
            ->where('passenger_id', $userId)
            ->where('valid_to', '>', now())
            ->where('status', 'ACTIVE')
            ->first();

        if ($subscription) {
            $qr = $this->generateSubscriberQrCode($subscription, $userId);
            return response()->json([
                'qr_payload'      => $qr,
                'ticket_id'       => null,
                'ticket_type'     => null,
                'remaining_trips' => 999,
                'trip_number'     => 1,
                'expires_at'      => $subscription->valid_to,
                'is_subscriber'   => true,
            ]);
        }

        // Check for a valid purchased ticket (no auto-create)
        $ticket = DB::table('tickets')
            ->where('user_id', $userId)
            ->where('status', 0)
            ->where('remaining_trips', '>', 0)
            ->where(function ($query) {
                $query->whereNull('expires_at')
                      ->orWhere('expires_at', '>', now());
            })
            ->latest('created_at')
            ->first();

        // No ticket and no subscription
        if (!$ticket) {
            return response()->json([
                'no_ticket' => true,
                'message'   => 'No active ticket or subscription found.',
            ]);
        }

        $ticketType = DB::table('ticket_types')->find($ticket->ticket_type_id);
        $qr = $this->generateNonSubscriberQrCode($ticket, $ticketType);

        return response()->json([
            'qr_payload'      => $qr,
            'ticket_id'       => $ticket->id,
            'ticket_type'     => $ticketType,
            'remaining_trips' => $ticket->remaining_trips,
            'trip_number'     => $ticket->used_trips + 1,
            'expires_at'      => $ticket->expires_at,
            'is_subscriber'   => false,
        ]);
    }
    // Scan ticket and mark as used
    # REPLACE the scanTicket() method in your TicketController with this:

public function scanTicket(Request $request)
{
    $qrData = $request->input('qr_data') ?? $request->input('data');

    if (!$qrData) {
        return response()->json([
            'success' => false,
            'message' => "No QR data provided!"
        ]);
    }

    $ticketInfo = $this->decodeQrData($qrData);

    if (!$ticketInfo) {
        return response()->json([
            'success' => false,
            'message' => "Invalid QR code format!"
        ]);
    }

    // -------------------------
    // VERY IMPORTANT FIX #1
    // -------------------------
    if (!$this->verifySignature($ticketInfo)) {
        return response()->json([
            'success' => false,
            'message' => "Invalid signature!"
        ]);
    }

    try {

        return DB::transaction(function () use ($ticketInfo) {

            // -------------------------
            // VERY IMPORTANT FIX #2
            // lock row
            // -------------------------
            $ticket = DB::table('tickets')
                ->where('ticket_uuid', $ticketInfo['ticket_id'])
                ->lockForUpdate()
                ->first();

            if (!$ticket) {
                return response()->json([
                    'success' => false,
                    'message' => "Ticket not found!"
                ]);
            }

            // Check expiry (DB only)
            if ($ticket->expires_at && strtotime($ticket->expires_at) < time()) {
                return response()->json([
                    'success' => false,
                    'message' => "Ticket expired!"
                ]);
            }

            if ($ticket->remaining_trips <= 0) {
                return response()->json([
                    'success' => false,
                    'message' => "No trips remaining!"
                ]);
            }

            // -------------------------
            // Anti-replay (nonce)
            // -------------------------
            if (
                isset($ticketInfo['anti_fraud']['nonce']) &&
                $ticket->nonce !== $ticketInfo['anti_fraud']['nonce']
            ) {
                return response()->json([
                    'success' => false,
                    'message' => "Invalid nonce! Possible replay attack!"
                ]);
            }

            // -------------------------
            // Create trip
            // -------------------------
            $tripId = DB::table('trips')->insertGetId([
                'ticket_id'     => $ticket->id,
                'user_id'       => $ticket->user_id,
                'entry_station' => 'Main Station',
                'entry_time'    => now(),
                'status'        => 'active',
                'created_at'    => now(),
            ]);

            $newRemainingTrips = $ticket->remaining_trips - 1;
            $newUsedTrips     = ($ticket->used_trips ?? 0) + 1;
            $newScanCounter   = ($ticket->scan_counter ?? 0) + 1;

            DB::table('tickets')
                ->where('id', $ticket->id)
                ->update([
                    'remaining_trips' => $newRemainingTrips,
                    'used_trips'      => $newUsedTrips,
                    'scan_counter'    => $newScanCounter,
                    'status'          => $newRemainingTrips <= 0 ? 1 : 0,
                    'used_at'         => $newRemainingTrips <= 0 ? now() : $ticket->used_at,
                    'last_scan_time'  => now(),

                    // rotate nonce
                    'nonce'           => $this->generateNonce(),
                ]);

            $ticketType = DB::table('ticket_types')
                ->find($ticket->ticket_type_id);

            return response()->json([
                'success' => true,
                'message' => "Ticket successfully scanned!",
                'ticket_id' => $ticket->ticket_uuid,
                'ticket_type' => $ticketType,
                'remaining_trips' => $newRemainingTrips,
                'trip_id' => $tripId
            ]);

        }, 3);

    } catch (\Throwable $e) {

        return response()->json([
            'success' => false,
            'message' => 'Scan failed',
            'error' => $e->getMessage()
        ], 500);
    }
}

    /*public function scanTicket(Request $request)
    {
        // Get the scanned QR data
        $qrData = $request->input('qr_data') ?? $request->input('data');
        $gateId = $request->input('gate_id', 'GATE_' . str_pad(rand(1, 20), 2, '0', STR_PAD_LEFT));
        
        if (!$qrData) {
            return view('ticket_scan_result', [
                'message' => "No QR data provided! / لم يتم توفير بيانات QR!",
                'success' => false
            ]);
        }

        // Decode the QR data
        $ticketInfo = $this->decodeQrData($qrData);
        
        if (!$ticketInfo) {
            return view('ticket_scan_result', [
                'message' => "Invalid QR code format! / تنسيق رمز QR غير صالح!",
                'success' => false
            ]);
        }

        // Verify signature
        if (!$this->verifySignature($ticketInfo)) {
            return view('ticket_scan_result', [
                'message' => "Invalid signature! QR code may be counterfeit! / توقيع غير صالح! قد يكون رمز QR مزيفاً!",
                'success' => false
            ]);
        }

        // Handle subscriber vs non-subscriber differently
        if ($ticketInfo['ticket_type'] === 'SUBSCRIPTION') {
            return $this->scanSubscriberTicket($ticketInfo, $gateId);
        } else {
            return $this->scanNonSubscriberTicket($ticketInfo, $gateId);
        }
    }*/

    // Scan non-subscriber (PAYG) ticket
    private function scanNonSubscriberTicket($ticketInfo, $gateId)
    {
        // Find the ticket in database using UUID
        $ticket = DB::table('tickets')
            ->where('ticket_uuid', $ticketInfo['ticket_id'])
            ->first();

        if (!$ticket) {
            return response()->json(['success' => false, 'message' => "Ticket not found!"]);
        }

        // Check cooldown (prevent rapid re-scans)
        if ($ticket->last_scan_time) {
            $cooldownSeconds = $ticketInfo['usage_rules']['cooldown_seconds'] ?? 120;
            $timeSinceLastScan = time() - strtotime($ticket->last_scan_time);
            if ($timeSinceLastScan < $cooldownSeconds) {
                return response()->json([
                    'success' => false,
                    'message' => "Please wait " . ($cooldownSeconds - $timeSinceLastScan) . " seconds before scanning again!"
                ]);
            }
        }

        // Check if ticket is expired
        if ($ticket->expires_at && strtotime($ticket->expires_at) < time()) {
            return response()->json(['success' => false, 'message' => "Ticket expired!"]);
        }

        // Check if ticket has remaining trips
        if ($ticket->remaining_trips <= 0) {
            return response()->json(['success' => false, 'message' => "No trips remaining!"]);
        }

        // Check nonce to prevent replay attacks
        if ($ticket->nonce !== $ticketInfo['anti_fraud']['nonce']) {
            return response()->json(['success' => false, 'message' => "Invalid nonce! Possible replay attack!"]);
        }

        // Create trip record
        $tripId = DB::table('trips')->insertGetId([
            'ticket_id'     => $ticket->id,
            'user_id'       => $ticket->user_id,
            'entry_station' => 'Main Station',
            'entry_time'    => now(),
            'gate_id'       => $gateId,
            'status'        => 'active',
            'created_at'    => now(),
        ]);

        // Update ticket
        $newRemainingTrips = $ticket->remaining_trips - 1;
        $newUsedTrips      = $ticket->used_trips + 1;
        $newScanCounter    = $ticket->scan_counter + 1;

        DB::table('tickets')->where('id', $ticket->id)->update([
            'remaining_trips' => $newRemainingTrips,
            'used_trips'      => $newUsedTrips,
            'scan_counter'    => $newScanCounter,
            'last_scan_time'  => now(),
            'last_gate_id'    => $gateId,
            'status'          => $newRemainingTrips <= 0 ? 1 : 0,
            'used_at'         => $newRemainingTrips <= 0 ? now() : $ticket->used_at,
            'nonce'           => $this->generateNonce(),
        ]);

        $ticketType = DB::table('ticket_types')->find($ticket->ticket_type_id);

        return response()->json([
            'success'         => true,
            'message'         => "Ticket successfully scanned!",
            'ticket_type'     => $ticketType,
            'remaining_trips' => $newRemainingTrips,
            'trip_id'         => $tripId,
            'gate_id'         => $gateId
        ]);
    }

    // Scan subscriber ticket
    private function scanSubscriberTicket($ticketInfo, $gateId)
    {
        // Find subscription
        $subscription = DB::table('subscriptions')
            ->where('subscription_uuid', $ticketInfo['subscription_id'])
            ->first();

        if (!$subscription) {
            return response()->json(['success' => false, 'message' => "Subscription not found!"]);
        }

        // Check if subscription is active
        if ($subscription->status !== 'ACTIVE') {
            return response()->json(['success' => false, 'message' => "Subscription is not active!"]);
        }

        // Check if subscription is valid
        if (strtotime($subscription->valid_to) < time()) {
            return response()->json(['success' => false, 'message' => "Subscription expired!"]);
        }

        // Check cooldown
        if ($subscription->last_scan_time) {
            $cooldownSeconds = $ticketInfo['usage_limits']['cooldown_seconds'] ?? 90;
            $timeSinceLastScan = time() - strtotime($subscription->last_scan_time);
            if ($timeSinceLastScan < $cooldownSeconds) {
                return response()->json([
                    'success' => false,
                    'message' => "Please wait " . ($cooldownSeconds - $timeSinceLastScan) . " seconds!"
                ]);
            }
        }

        // Check daily limit
        $ridesToday = DB::table('trips')
            ->where('user_id', $subscription->passenger_id)
            ->whereDate('created_at', today())
            ->count();

        if ($ridesToday >= ($ticketInfo['usage_limits']['daily_limit'] ?? 999)) {
            return response()->json(['success' => false, 'message' => "Daily ride limit reached!"]);
        }

        // Verify QR rotation (check if QR is still valid for current hour)
        $currentEpoch = floor(time() / 3600) * 3600;
        if ($ticketInfo['qr_rotation']['epoch_start'] !== $currentEpoch) {
            return response()->json(['success' => false, 'message' => "QR code expired! Please refresh your ticket."]);
        }

        // Create trip record
        $tripId = DB::table('trips')->insertGetId([
            'subscription_id' => $subscription->id,
            'user_id'         => $subscription->passenger_id,
            'entry_station'   => 'Main Station',
            'entry_time'      => now(),
            'gate_id'         => $gateId,
            'status'          => 'active',
            'created_at'      => now(),
        ]);

        // Update subscription
        DB::table('subscriptions')->where('id', $subscription->id)->update([
            'rides_today'         => $ridesToday + 1,
            'scan_counter_today'  => DB::raw('scan_counter_today + 1'),
            'last_scan_time'      => now(),
            'last_gate_id'        => $gateId,
        ]);

        return response()->json([
            'success'    => true,
            'message'    => "Welcome! Subscription verified!",
            'rides_today'=> $ridesToday + 1,
            'trip_id'    => $tripId,
            'gate_id'    => $gateId
        ]);
    }

    // Generate QR code for non-subscriber (PAYG)
    private function generateNonSubscriberQrCode($ticket, $ticketType)
    {
        $qrData = [
            'version' => 1,
            
            'ticket_type' => 'PAYG',
            'ticket_id' => $ticket->ticket_uuid ?? 'TKT-' . Str::uuid(),
            
            'issuer' => 'METRO_DZ',
            'sale_channel' => 'MOBILE_APP',
            
            'issued_at' => strtotime($ticket->created_at),
            'expires_at' => strtotime($ticket->expires_at),
            
            'fare' => [
                'fare_class' => 'STANDARD',
                'zones' => ['A', 'B'],
                'price_cents' => (int) ($ticketType->price * 100),
                'currency' => 'DZD'
            ],
            
            'trips' => [
                'total' => $ticket->total_trips ?? $ticket->remaining_trips,
                'used' => $ticket->used_trips ?? 0,
                'remaining' => $ticket->remaining_trips
            ],
            
            'usage_rules' => [
                'gate_policy' => 'ENTRY_ONLY',
                'cooldown_seconds' => 120,
                'max_scans_per_minute' => 1,
                'allow_transfer' => false
            ],
            
            'anti_fraud' => [
                'nonce' => $ticket->nonce ?? $this->generateNonce(),
                'max_parallel_devices' => 1,
                'last_scan_time' => $ticket->last_scan_time ? strtotime($ticket->last_scan_time) : null,
                'last_gate_id' => $ticket->last_gate_id,
                'scan_counter' => $ticket->scan_counter ?? 0
            ],
            
            'validation' => [
                'online_required' => false,
                'offline_grace_seconds' => 300
            ]
        ];

        // Generate HMAC signature
        $signature = $this->generateHmacSignature($qrData);
        $qrData['signature'] = [
            'alg' => 'HMAC-SHA256',
            'value' => $signature
        ];

        // Simple load for the scanner to read
        $minimalPayload = [
            'version'     => 1,
            'ticket_type' => 'STANDARD',
            'ticket_id'   => $ticket->ticket_uuid,
            'issuer'      => 'METRO_DZ',
            'expires_at'  => strtotime($ticket->expires_at),
            'anti_fraud'  => [
            'nonce' => $ticket->nonce ?? $this->generateNonce(),
            ],
        ];

        $signature = $this->generateHmacSignature($minimalPayload);
        $minimalPayload['signature'] = ['value' => $signature];

        return json_encode($minimalPayload);    

        // Dense QR code hard to read.
        //return json_encode($qrData);

        /*$jsonData = json_encode($qrData, JSON_PRETTY_PRINT);

        return QrCode::size(300)
                     ->format('svg')
                     ->margin(1)
                     ->errorCorrection('H')
                     ->generate($jsonData);*/
    }

    // Generate QR code for subscriber
    private function generateSubscriberQrCode($subscription, $userId)
    {
        // Get rides today count
        $ridesToday = DB::table('trips')
            ->where('user_id', $userId)
            ->whereDate('created_at', today())
            ->count();

        // Calculate current epoch (hourly rotation)
        $currentEpoch = floor(time() / 3600) * 3600;
        
        $qrData = [
            'version' => 1,
            
            'ticket_type' => 'SUBSCRIPTION',
            'subscription_id' => $subscription->subscription_uuid ?? 'SUB-' . rand(100000, 999999),
            
            'user' => [
                'user_id' => 'USR-' . str_pad($userId, 6, '0', STR_PAD_LEFT),
                'account_status' => $subscription->status ?? 'ACTIVE'
            ],
            
            'subscription' => [
                'type' => $subscription->duration_type ?? 'MONTHLY',
                'plan_code' => $subscription->plan_code ?? 'MONTHLY_UNLIMITED',
                'valid_from' => strtotime($subscription->valid_from),
                'valid_until' => strtotime($subscription->valid_to),
                'zones' => ['A', 'B', 'C']
            ],
            
            'qr_rotation' => [
                'epoch_start' => $currentEpoch,
                'ttl_seconds' => 3600,
                'rotation_policy' => 'HOURLY'
            ],
            
            'usage_limits' => [
                'daily_limit' => 999,
                'rides_today' => $ridesToday,
                'cooldown_seconds' => 90
            ],
            
            'gate_rules' => [
                'gate_policy' => 'ENTRY_ONLY',
                'allow_transfer' => true
            ],
            
            'anti_fraud' => [
                'nonce' => $this->generateHourlyNonce($subscription->id, $currentEpoch),
                'last_scan_time' => $subscription->last_scan_time ? strtotime($subscription->last_scan_time) : null,
                'last_gate_id' => $subscription->last_gate_id,
                'device_fingerprint_hint' => 'ANDROID',
                'scan_counter_today' => $ridesToday
            ],
            
            'validation' => [
                'online_required' => true,
                'offline_grace_seconds' => 60
            ]
        ];

        // Generate ECDSA-style signature (using HMAC for simplicity)
        $signature = $this->generateHmacSignature($qrData);
        $qrData['signature'] = [
            'alg' => 'ECDSA-P256',
            'key_id' => 'METRO-KEY-01',
            'value' => $signature
        ];

        // Simple one.
        $minimalPayload = [
            'version'         => 1,
            'ticket_type'     => 'SUBSCRIPTION',
            'subscription_id' => $subscription->subscription_uuid ?? 'SUB-' . rand(100000, 999999),
            'issuer'          => 'METRO_DZ',
            'anti_fraud'      => [
            'nonce' => $this->generateHourlyNonce($subscription->id, $currentEpoch),
            ],
        ];

        $signature = $this->generateHmacSignature($minimalPayload);
        $minimalPayload['signature'] = ['value' => $signature];

        return json_encode($minimalPayload);

        // Dense QR code hard to read by scanner.
        //return json_encode($qrData);
        
        /*$jsonData = json_encode($qrData, JSON_PRETTY_PRINT);

        return QrCode::size(300)
                     ->format('svg')
                     ->margin(1)
                     ->errorCorrection('H')
                     ->generate($jsonData);*/
    }

    // Decode QR data back to ticket info
    private function decodeQrData($qrData)
    {
        try {
            $data = json_decode($qrData, true);
            
            if (!$data || !isset($data['version'])) {
                return null;
            }

            return $data;
        } catch (\Exception $e) {
            return null;
        }
    }

    // Generate HMAC signature
    private function generateHmacSignature($data)
    {
        // Remove signature field if it exists
        unset($data['signature']);
        
        // Create canonical string
        $canonical = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        
        // Generate HMAC
        return base64_encode(hash_hmac('sha256', $canonical, $this->getHmacSecret(), true));
    }

    // Verify HMAC signature
    private function verifySignature($ticketInfo)
    {
        if (!isset($ticketInfo['signature']['value'])) {
            return false;
        }

        $providedSignature = $ticketInfo['signature']['value'];
        $calculatedSignature = $this->generateHmacSignature($ticketInfo);

        return hash_equals($calculatedSignature, $providedSignature);
    }

    // Generate random nonce
    private function generateNonce($length = 18)
    {
        $characters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
        $nonce = '';
        for ($i = 0; $i < $length; $i++) {
            $nonce .= $characters[rand(0, strlen($characters) - 1)];
        }
        return $nonce;
    }

    // Generate hourly rotating nonce for subscribers
    private function generateHourlyNonce($subscriptionId, $epoch)
    {
        return hash('sha256', $subscriptionId . $epoch . $this->getHmacSecret());
    }

    // Generate 38-bit ticket payload (keeping for backward compatibility)
    private function generateNewTicket($userId)
    {
        $reserved     = rand(0, 3);
        $security     = rand(0, 63);
        $crc          = rand(0, 15);
        $timestamp    = time() & 0xFFF;
        $ticketType   = rand(0, 7);
        $subscription = rand(0, 1);
        $userIdBits   = $userId & 0x3FF;

        return ($userIdBits << 28)
             | ($subscription << 27)
             | ($ticketType << 24)
             | ($timestamp << 12)
             | ($crc << 8)
             | ($security << 2)
             | $reserved;
    }

    private function decodeTicket($payload)
    {
        return [
            'user_id'      => ($payload >> 28) & 0x3FF,
            'subscription' => ($payload >> 27) & 0x1,
            'ticket_type'  => ($payload >> 24) & 0x7,
            'timestamp'    => ($payload >> 12) & 0xFFF,
            'crc'          => ($payload >> 8) & 0xF,
            'security'     => ($payload >> 2) & 0x3F,
            'reserved'     => $payload & 0x3,
        ];
    }
}