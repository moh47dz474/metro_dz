<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PurchaseController extends Controller
{
    // ─────────────────────────────────────────────────────────────────────────
    //  POST /api/tickets/purchase
    //  Body: { "ticket_type_id": 1, "payment_method": "cash" }
    // ─────────────────────────────────────────────────────────────────────────
    public function purchaseTicket(Request $request)
    {
        $userId = $request->attributes->get('jwt_user_id');
        if (!$userId) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $request->validate([
            'ticket_type_id'  => 'required|integer|exists:ticket_types,id',
            'payment_method'  => 'required|in:cash,online',
        ]);

        // Online payment not implemented yet
        if ($request->payment_method === 'online') {
            return response()->json([
                'error' => 'Online payment is not available yet. Please use cash.'
            ], 422);
        }

        $ticketType = DB::table('ticket_types')->find($request->ticket_type_id);

        if (!$ticketType) {
            return response()->json(['error' => 'Ticket type not found'], 404);
        }

        // Create the ticket
        $ticketUuid = 'TKT-' . Str::uuid();
        $nonce      = $this->generateNonce();
        $expiresAt  = now()->addHours($ticketType->validity_hours);
        $tripCount  = $ticketType->trip_count == -1 ? 999 : $ticketType->trip_count;

        // Build ticket payload (same format as TicketController)
        $ticketPayload = ($userId & 0x3FF) << 28;

        $ticketId = DB::table('tickets')->insertGetId([
            'ticket_uuid'     => $ticketUuid,
            'ticket_payload'  => $ticketPayload,
            'user_id'         => $userId,
            'ticket_type_id'  => $ticketType->id,
            'remaining_trips' => $tripCount,
            'total_trips'     => $tripCount,
            'used_trips'      => 0,
            'expires_at'      => $expiresAt,
            'status'          => 0,
            'nonce'           => $nonce,
            'scan_counter'    => 0,
            'last_scan_time'  => null,
            'last_gate_id'    => null,
            'created_at'      => now(),
        ]);

        $ticket = DB::table('tickets')->where('id', $ticketId)->first();

        return response()->json([
            'success'         => true,
            'message'         => 'Ticket purchased successfully!',
            'ticket_id'       => $ticket->id,
            'ticket_uuid'     => $ticket->ticket_uuid,
            'ticket_type'     => $ticketType,
            'remaining_trips' => $ticket->remaining_trips,
            'expires_at'      => $ticket->expires_at,
            'payment_method'  => $request->payment_method,
        ], 201);
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  POST /api/subscriptions/purchase
    //  Body: { "plan_id": "scolaire_monthly", "payment_method": "cash" }
    // ─────────────────────────────────────────────────────────────────────────
    public function purchaseSubscription(Request $request)
    {
        $userId = $request->attributes->get('jwt_user_id');
        if (!$userId) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $request->validate([
            'plan_id'        => 'required|string',
            'payment_method' => 'required|in:cash,online',
        ]);

        // Online payment not implemented yet
        if ($request->payment_method === 'online') {
            return response()->json([
                'error' => 'Online payment is not available yet. Please use cash.'
            ], 422);
        }

        // Resolve plan details from plan_id
        $plan = $this->resolvePlan($request->plan_id);
        if (!$plan) {
            return response()->json(['error' => 'Invalid plan ID'], 422);
        }

        // Check if user already has an active subscription
        $existing = DB::table('subscriptions')
            ->where('passenger_id', $userId)
            ->where('status', 'ACTIVE')
            ->where('valid_to', '>', now())
            ->first();

        if ($existing) {
            return response()->json([
                'error'            => 'You already have an active subscription.',
                'expires_at'       => $existing->valid_to,
                'subscription_uuid'=> $existing->subscription_uuid,
            ], 422);
        }

        // Calculate validity period
        $validFrom  = now();
        $validTo    = match($plan['duration_type']) {
            'weekly'  => now()->addWeek(),
            'monthly' => now()->addMonth(),
            'yearly'  => now()->addYear(),
            default   => now()->addMonth(),
        };

        $subscriptionUuid = 'SUB-' . Str::uuid();

        $subscriptionId = DB::table('subscriptions')->insertGetId([
            'subscription_uuid' => $subscriptionUuid,
            'passenger_id'      => $userId,
            'plan_name'         => $plan['name'],
            'plan_code'         => $plan['id'],
            'category'          => $plan['category'],
            'price'             => $plan['price'],
            'duration_type'     => $plan['duration_type'],
            'valid_from'        => $validFrom,
            'valid_to'          => $validTo,
            'status'            => 'ACTIVE',
            'payment_method'    => $request->payment_method,
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);

        $subscription = DB::table('subscriptions')->where('id', $subscriptionId)->first();

        return response()->json([
            'success'           => true,
            'message'           => 'Subscription activated successfully!',
            'subscription_id'   => $subscription->id,
            'subscription_uuid' => $subscription->subscription_uuid,
            'plan'              => $plan,
            'valid_from'        => $subscription->valid_from,
            'valid_to'          => $subscription->valid_to,
            'status'            => $subscription->status,
            'payment_method'    => $request->payment_method,
        ], 201);
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  Plan definitions — matches Flutter's SubscriptionPlan list
    // ─────────────────────────────────────────────────────────────────────────
    private function resolvePlan(string $planId): ?array
    {
        $plans = [
            'scolaire_monthly' => ['id' => 'scolaire_monthly', 'name' => 'Scolaire Monthly',   'category' => 'Scolaire',   'price' => 400,  'duration_type' => 'monthly'],
            'scolaire_yearly'  => ['id' => 'scolaire_yearly',  'name' => 'Scolaire Yearly',    'category' => 'Scolaire',   'price' => 4000, 'duration_type' => 'yearly'],
            'student_monthly'  => ['id' => 'student_monthly',  'name' => 'Student Monthly',    'category' => 'Student',    'price' => 700,  'duration_type' => 'monthly'],
            'student_yearly'   => ['id' => 'student_yearly',   'name' => 'Student Yearly',     'category' => 'Student',    'price' => 7000, 'duration_type' => 'yearly'],
            'teenagers_monthly'=> ['id' => 'teenagers_monthly','name' => 'Teenagers Monthly',  'category' => 'Teenagers',  'price' => 1200, 'duration_type' => 'monthly'],
            'public_weekly'    => ['id' => 'public_weekly',    'name' => 'All Public Weekly',   'category' => 'All Public', 'price' => 540,  'duration_type' => 'weekly'],
            'public_monthly'   => ['id' => 'public_monthly',   'name' => 'All Public Monthly',  'category' => 'All Public', 'price' => 1820, 'duration_type' => 'monthly'],
            'senior_monthly'   => ['id' => 'senior_monthly',   'name' => 'Senior Monthly',     'category' => 'Senior',     'price' => 1000, 'duration_type' => 'monthly'],
        ];

        return $plans[$planId] ?? null;
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
}