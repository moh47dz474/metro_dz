<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ArrivalsController extends Controller
{
    public function byStation(Request $r)
    {
        $station = $r->query('station', '');
        if ($station === '') return response()->json([]);

        $rows = DB::table('arrivals')
            ->where('station', $station)
            ->orderBy('eta_min')->limit(20)->get();

        return response()->json($rows->map(function($row){
            return [
                'line' => $row->line_code,
                'destination' => $row->destination,
                'time' => $row->eta_min.' min',
                'color' => $row->color_hex,
            ];
        }));
    }
}
