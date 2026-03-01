<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProfileController extends Controller
{
    public function me(Request $r)
    {
        $uid = $r->attributes->get('jwt_user_id');
        $u = DB::table('passengers')->where('id',$uid)->first();
        if (!$u) return response()->json(['error'=>'Not found'], 404);

        $sub = DB::table('subscriptions')
            ->where('passenger_id',$uid)
            ->where('status','ACTIVE')
            ->orderByDesc('valid_to')->first();

        return response()->json([
            'id' => $u->id,
            'full_name' => $u->full_name,
            'email' => $u->email,
            'subscription_expiry' => $sub ? $sub->valid_to : null
        ]);
    }
}
