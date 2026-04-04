<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class GeocoderProxyController extends Controller
{
    /**
     * Proxy address search to Nominatim (OpenStreetMap).
     * Avoids browser CORS restrictions.
     */
    public function search(Request $request)
    {
        $this->validate($request, [
            'q' => 'required|string|min:3|max:200',
        ]);

        $query = $request->input('q');

        try {
            $response = Http::timeout(5)
                ->withHeaders([
                    'User-Agent' => 'RocketDialer-CRM/1.0 (admin@rocketdialer.com)',
                ])
                ->get('https://nominatim.openstreetmap.org/search', [
                    'format'         => 'jsonv2',
                    'q'              => $query,
                    'addressdetails' => 1,
                    'limit'          => 6,
                    'countrycodes'   => 'us',
                ]);

            if ($response->failed()) {
                return response()->json([], 200);
            }

            return response()->json($response->json());
        } catch (\Throwable $e) {
            return response()->json([], 200);
        }
    }
}
