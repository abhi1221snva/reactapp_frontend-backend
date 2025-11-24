<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class VoiceController extends Controller
{
    public function getVoices()
    {
        $voices = [
            [
                "value" => "alloy",
                "label" => "Alloy (Male) - Warm & confident"
            ],
            [
                "value" => "ash",
                "label" => "Ash (Male) - Calm & grounded"
            ],
            [
                "value" => "ballad",
                "label" => "Ballad (Male) - Expressive & emotional"
            ],
            [
                "value" => "coral",
                "label" => "Coral (Female) - Bright & energetic"
            ],
            [
                "value" => "echo",
                "label" => "Echo (Male) - Clear & neutral"
            ],
            [
                "value" => "sage",
                "label" => "Sage (Female) - Gentle & wise"
            ],
            [
                "value" => "shimmer",
                "label" => "Shimmer (Female) - Soft & friendly"
            ],
            [
                "value" => "verse",
                "label" => "Verse (Male) - Balanced & intelligent"
            ],
        ];

        return response()->json([
            "success" => true,
            "data" => $voices
        ]);
    }
}
