<?php
namespace App\Http\Helper;
class Log
{
    public static function log($message, array $context = [])
    {
        \Illuminate\Support\Facades\Log::info($message, $context);
    }
}

