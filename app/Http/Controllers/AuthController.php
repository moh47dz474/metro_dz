<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Firebase\JWT\JWT;

class AuthController extends Controller
{
    public function register(Request $r)
    {
        $r->validate([
            'full_name' => 'required|string|max:150',
            'email' => 'required|email',
            'phone' => 'nullable|string|max:32',
            'password' => 'required|string|min:6',
            'birth_date' => 'nullable|string'
        ]);

        $exists = DB::table('passengers')->where('email', $r->email)->exists();
        if ($exists) return response()->json(['error'=>'Email already used'], 409);

        $id = DB::table('passengers')->insertGetId([
            'full_name' => $r->full_name,
            'email' => $r->email,
            'phone' => $r->phone,
            'password_hash' => Hash::make($r->password),
        ]);

        return response()->json(['id'=>$id, 'message'=>'Registered']);
    }

    public function login(Request $r)
    {
        $r->validate(['email'=>'required|email', 'password'=>'required|string']);
        $user = DB::table('passengers')->where('email',$r->email)->first();
        if (!$user || !Hash::check($r->password, $user->password_hash)) {
            return response()->json(['error'=>'Invalid credentials'], 401);
        }
        $payload = [
            'sub' => $user->id,
            'role' => 'user',
            'exp' => time() + 3600,
            'iat' => time(),
        ];
        $token = JWT::encode($payload, env('JWT_SECRET'), 'HS256');
        return response()->json([
            'token'=>$token,
            'user'=>['id'=>$user->id,'full_name'=>$user->full_name]
        ]);
    }
}
