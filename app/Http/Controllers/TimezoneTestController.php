<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Model\Master\Timezone;
use DateTime;
use DateTimeZone;

class TimezoneTestController extends Controller
{
    /**
     * Test timezone logic for a given phone number and time.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function test(Request $request)
    {
        try {
            $phone = $request->input('phone');
            $callAt = $request->input('call_at'); // e.g. "14:30" (Server Time)

            if (!$phone) {
                return response()->json(['error' => 'Phone number required (query param: phone)'], 400);
            }

            // 1. Extract Area Code
            // Handle 10 or 11 digits (e.g. 1-212... or 212...)
            $cleanPhone = preg_replace('/[^0-9]/', '', $phone);
            if (strlen($cleanPhone) > 10) {
                 $cleanPhone = substr($cleanPhone, -10); 
            }
            $areaCode = substr($cleanPhone, 0, 3);
            
            // 2. Lookup Timezone
            $tzRow = Timezone::where('areacode', $areaCode)->first();
            $leadTimezone = $tzRow ? $tzRow->timezone : 'US/Eastern'; // Fallback to Admin Default if not found
            $foundInDb = $tzRow ? true : false;

            // 3. Compare Times
            // Server/Admin Timezone (Assuming America/New_York as per cron logic)
            $serverTz = new DateTimeZone('America/New_York'); 
            $leadTzObj = new DateTimeZone($leadTimezone);
            
            // Setup Server Time
            if ($callAt) {
                // "If I call at [callAt] New York Time..."
                $serverTime = DateTime::createFromFormat('H:i', $callAt, $serverTz);
                if (!$serverTime) {
                     // Fallback if format is wrong, try treating as current date + time
                     $serverTime = new DateTime($callAt, $serverTz);
                }
            } else {
                $serverTime = new DateTime("now", $serverTz);
            }
            
            // Calculate Lead Time
            $leadTime = clone $serverTime;
            $leadTime->setTimezone($leadTzObj);

            // Calculate UTC Time
            $utcTime = clone $serverTime;
            $utcTime->setTimezone(new DateTimeZone('UTC'));

            $logicExplanation = "";
            if ($foundInDb) {
                $logicExplanation = "Area Code {$areaCode} was FOUND in the database. Using its specific timezone: {$leadTimezone}.";
            } else {
                $logicExplanation = "Area Code {$areaCode} was NOT FOUND in the local database. The system defaulted to the Fallback Timezone: {$leadTimezone}. This usually happens for international numbers or new area codes.";
            }

            return response()->json([
                'status' => 'success',
                'inputs' => [
                    'phone' => $phone,
                    'call_at' => $callAt ?? 'NOW'
                ],
                'logic_summary' => $logicExplanation,
                'analysis' => [
                    'clean_phone' => $cleanPhone,
                    'extracted_area_code' => $areaCode,
                    'timezone_found_in_db' => $foundInDb,
                    'lead_timezone' => $leadTimezone,
                ],
                'time_comparison' => [
                    'utc_time' => $utcTime->format('Y-m-d H:i:s'),
                    'server_time_nyc' => $serverTime->format('Y-m-d H:i:s'),
                    'lead_local_time' => $leadTime->format('Y-m-d H:i:s'),
                    'is_same_time' => ($serverTime->format('H:i') === $leadTime->format('H:i')),
                    'hour_difference' => $serverTime->format('G') - $leadTime->format('G')
                ]
            ]);
            
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}
