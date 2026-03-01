<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SubscriptionsController extends Controller
{
    public function activeByMediaUid(Request $r)
    {
        $mediaUid = $r->query('media_uid','');
        if ($mediaUid === '') return response()->json(null);

        $row = DB::table('subscriptions as s')
            ->join('media as m', 'm.id', '=', 's.media_id')
            ->join('products as p', 'p.id', '=', 's.product_id')
            ->where('m.media_uid', $mediaUid)
            ->where('s.status', 'ACTIVE')
            ->whereRaw('CURDATE() BETWEEN s.valid_from AND s.valid_to')
            ->orderByDesc('s.valid_to')
            ->select('s.*','p.name as product_name','p.code as product_code')
            ->first();

        return response()->json($row ?: null);
    }
}
