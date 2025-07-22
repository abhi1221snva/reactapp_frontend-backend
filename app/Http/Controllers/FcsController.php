<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Model\Client\Fcs;
use App\Model\Client\Lender;
use App\Model\Client\FcsLenderList;
use Illuminate\Support\Facades\Log;

class FcsController extends Controller
{
    public function index(Request $request,$id)
    {
        try {
            $clientId = $request->auth->parent_id;
    
            // Get all records from the Fcs table
            $events = Fcs::on("mysql_$clientId")->where('lead_id',$id)->get();
    
            // Group records by bank_name
            $groupedRecords = $events->groupBy('bank_name');
    
            // Initialize an array to store the result
            $eventsArray = [];
    
            // Loop through each bank and retrieve its records
            foreach ($groupedRecords as $bankName => $records) {
                // Add the bank name and its records to the results array
                $eventsArray[] = [
                    'bank_name' => $bankName,
                    'records' => $records
                ];
            }
    
            return $this->successResponse("List of Events", $eventsArray);
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to retrieve events", [$exception->getMessage()], $exception, $exception->getCode());
        }
    }
    
    public function addBank(Request $request)
    {
        try {
            Log::info('reached bank', [$request->all()]);
            $clientId = $request->auth->parent_id;
    
            // Check if the bank name already exists
            $existingBank = Fcs::on("mysql_$clientId")->where('lead_id', $request->input('lead_id'))->where('bank_id', $request->input('bank_id'))->first();
    
            if ($existingBank) {
                // Update the bank_name for the existing bank entry
                $existingBank->bank_name = $request->input('bank_name');
                $existingBank->save(); // Save updated bank_name
    
                $events = $existingBank;
            } else {
                // Bank doesn't exist, create a new one
                $events = new Fcs;
                $events->setConnection("mysql_$clientId");
                $events->lead_id = $request->input('lead_id');
                $events->bank_id = $request->input('bank_id');
                $events->bank_name = $request->input('bank_name');
                $events->save(); // Save the new bank first
            }
    
            // Get monthly data from the request
            $monthlyData = $request->input('monthly_data', []); // Default to an empty array if not present
            Log::info('reached monthlyData', ['monthlyData' => $monthlyData]);
    
            // Check if monthlyData is an array
            if (!is_array($monthlyData)) {
                return $this->failResponse("Invalid monthly data format", [], null, 400);
            }
    
            // Loop through each month and update or create the respective data
            foreach ($monthlyData as $month => $data) {
                // Check if the month is valid (skip if the month key is 'bank_id' or any invalid value)
                if ($month === 'bank_id') {
                    Log::warning("Skipping invalid month entry", ['month' => $month, 'data' => $data]);
                    continue; // Skip this iteration
                }
    
                // Check if monthly data already exists for this bank and month
                $monthlyRecord = Fcs::on("mysql_$clientId")
                    ->where('bank_id', $events->bank_id)  // Ensure you use the updated or created bank ID
                    ->where('lead_id', $request->input('lead_id'))
                    ->where('month', $month)
                    ->first();
    
                if ($monthlyRecord) {
                    // Update existing monthly record
                    $monthlyRecord->bank_name = $request->input('bank_name'); // Update the bank name for the monthly record
                    $monthlyRecord->deposits = $data['deposit1'] ?? null;
                    $monthlyRecord->adjustment = $data['adjustment'] ?? null;
                    $monthlyRecord->revenue = ($data['deposit1'] ?? 0) - ($data['adjustment'] ?? 0);
                    $monthlyRecord->adb = $data['adb'] ?? null;
                    $monthlyRecord->deposits2 = $data['deposit2'] ?? null;
                    $monthlyRecord->nsfs = $data['nsfs'] ?? null;
                    $monthlyRecord->negatives = $data['negatives'] ?? null;
                    $monthlyRecord->ending_balance = $data['ending_balance'] ?? null;
                    $monthlyRecord->save(); // Save the updated record
                } else {
                    // Create new monthly record if not found
                    $monthlyRecord = new Fcs;
                    $monthlyRecord->setConnection("mysql_$clientId");
                    $monthlyRecord->lead_id = $request->input('lead_id');
                    $monthlyRecord->bank_id = $events->bank_id; // Use the updated or created bank ID
                    $monthlyRecord->bank_name = $request->input('bank_name');
                    $monthlyRecord->month = $month;
                    $monthlyRecord->deposits = $data['deposit1'] ?? null;
                    $monthlyRecord->adjustment = $data['adjustment'] ?? null;
                    $monthlyRecord->revenue = ($data['deposit1'] ?? 0) - ($data['adjustment'] ?? 0);
                    $monthlyRecord->adb = $data['adb'] ?? null;
                    $monthlyRecord->deposits2 = $data['deposit2'] ?? null;
                    $monthlyRecord->nsfs = $data['nsfs'] ?? null;
                    $monthlyRecord->negatives = $data['negatives'] ?? null;
                    $monthlyRecord->ending_balance = $data['ending_balance'] ?? null;
                    $monthlyRecord->save(); // Save the new record
                }
            }
    
            // Return a success response with the created or updated data
            $eventsArray = $events->toArray();
            return $this->successResponse("Fcs Added/Updated with Monthly Data", $eventsArray);
    
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to add/update Fcs", [$exception->getMessage()], $exception, $exception->getCode());
        }
    }
    
    
 
    public function eligibleLender(Request $request, $lead_id, $bank_id)
    {
        try {
            $clientId = $request->auth->parent_id;
    
            // Fetching aggregated data from the Fcs table for a lead and bank
            $fcsData = Fcs::on("mysql_$clientId")
                        ->where('lead_id', $lead_id)
                        ->where('bank_id', $bank_id)
                        ->selectRaw('
                        COALESCE(SUM(negatives), 0) as max_negatives,
                        COALESCE(MIN(deposits), 0) as monthly_deposits,
                        COALESCE(SUM(deposits2), 0) as total_deposits,
                        COALESCE(SUM(adjustment), 0) as max_adjustment,
                        COALESCE(SUM(adb), 0) as max_adb,
                        COALESCE(SUM(nsfs), 0) as max_nsfs,
                        COALESCE(SUM(ending_balance), 0) as max_ending_balance,
                        COALESCE(AVG(revenue), 0) as avg_revenue

                    ')
                        ->first();
    Log::info('reached fcs data',['fcsData'=>$fcsData]);
            if (!$fcsData) {
                return $this->failResponse("No Fcs data found for the given lead and bank", []);
            }
    
            // Get all lenders that match conditions based on Fcs aggregated data
            $lenders = Lender::on("mysql_$clientId")
                ->where('max_negative_days', '<=', $fcsData->max_negatives)
                ->where('min_deposits', '<=', $fcsData->total_deposits) // example for deposits
                 ->where('daily_balance', '<=', $fcsData->max_adb)
                 ->where('min_avg_revenue', '<=', $fcsData->avg_revenue)
                 ->where('min_monthly_deposit', '<=', $fcsData->monthly_deposits)

                ->get();
                Log::info('reached lenders',['lenders'=>$lenders]);

            if ($lenders->isEmpty()) {
                return $this->failResponse("No eligible lenders found", []);
            }
    
            return $this->successResponse("List of eligible lenders",$lenders->toArray());
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to retrieve eligible lenders", [$exception->getMessage()], $exception, $exception->getCode());
        }
    }
    
    public function LenderList(Request $request, $lead_id, $bank_id)
{
    try {
        Log::info('Reached bank', [$request->all()]);
        $clientId = $request->auth->parent_id;

        // Get arrays of input data
        $lenderNames = $request->input('lender_name', []);
        $fundingDates = $request->input('funding_date', []);
        $fundingFactors = $request->input('funding_factor', []);
        $weeklies = $request->input('weekly', []);
        $dailies = $request->input('daily', []);
        $balances = $request->input('balance', []);
        $daysArray = $request->input('days', []);
        $withholds = $request->input('withhold', []);
        $endDates = $request->input('end_date', []);
        $transferAccounts = $request->input('transfer_accounts', []);
        $notesArray = $request->input('notes', []);
        $nets = $request->input('net', []);
        $fundings = $request->input('funding', []);

        // Loop through each set of values
        for ($i = 0; $i < count($lenderNames); $i++) {
            // Check if the record already exists
            $monthlyRecord = FcsLenderList::on("mysql_$clientId")
                ->where('lead_id', $lead_id)
                ->where('bank_id', $bank_id)
                ->where('lender_name', $lenderNames[$i])
                ->first();

            if (!$monthlyRecord) {
                // Create a new record if it doesn't exist
                $monthlyRecord = new FcsLenderList;
                $monthlyRecord->setConnection("mysql_$clientId");
                
                // Set all fields for the new record
                $monthlyRecord->lead_id = $lead_id;
                $monthlyRecord->bank_id = $bank_id;
                $monthlyRecord->lender_name = $lenderNames[$i];
                $monthlyRecord->funding_date = $fundingDates[$i] ?? null;
                $monthlyRecord->net = $nets[$i] ?? null;
                $monthlyRecord->funding = $fundings[$i] ?? null;
                $monthlyRecord->funding_factor = $fundingFactors[$i] ?? null;
                $monthlyRecord->weekly = $weeklies[$i] ?? null;
                $monthlyRecord->daily = $dailies[$i] ?? null;
                $monthlyRecord->balance = $balances[$i] ?? null;
                $monthlyRecord->days = $daysArray[$i] ?? null;
                $monthlyRecord->withhold = $withholds[$i] ?? null;
                $monthlyRecord->end_date = $endDates[$i] ?? null;
                $monthlyRecord->transfer_accounts = $transferAccounts[$i] ?? null;
                $monthlyRecord->notes = $notesArray[$i] ?? null;
            } else {
                // If it exists, make sure to set the connection
                $monthlyRecord->setConnection("mysql_$clientId");

                // Update the existing record with new values
                $monthlyRecord->funding_date = $fundingDates[$i] ?? $monthlyRecord->funding_date;
                $monthlyRecord->net = $nets[$i] ?? $monthlyRecord->net;
                $monthlyRecord->funding = $fundings[$i] ?? $monthlyRecord->funding;
                $monthlyRecord->funding_factor = $fundingFactors[$i] ?? $monthlyRecord->funding_factor;
                $monthlyRecord->weekly = $weeklies[$i] ?? $monthlyRecord->weekly;
                $monthlyRecord->daily = $dailies[$i] ?? $monthlyRecord->daily;
                $monthlyRecord->balance = $balances[$i] ?? $monthlyRecord->balance;
                $monthlyRecord->days = $daysArray[$i] ?? $monthlyRecord->days;
                $monthlyRecord->withhold = $withholds[$i] ?? $monthlyRecord->withhold;
                $monthlyRecord->end_date = $endDates[$i] ?? $monthlyRecord->end_date;
                $monthlyRecord->transfer_accounts = $transferAccounts[$i] ?? $monthlyRecord->transfer_accounts;
                $monthlyRecord->notes = $notesArray[$i] ?? $monthlyRecord->notes;
            }

            // Save the record (insert if new, update if existing)
            $monthlyRecord->save();
        }

        // Return a success response with a summary or count of processed records
        return $this->successResponse("Lenders Added/Updated with Monthly Data", [
            'count' => count($lenderNames),
            'lead_id' => $lead_id,
            'bank_id' => $bank_id,
        ]);
    } catch (\Throwable $exception) {
        return $this->failResponse("Failed to add/update Lender", [$exception->getMessage()], $exception, $exception->getCode());
    }
}

    public function GetLenderList(Request $request,$lead_id)
    {
        try {
            $clientId = $request->auth->parent_id;
    
            // Get all records from the Fcs table
            $events = FcsLenderList::on("mysql_$clientId")->where('lead_id',$lead_id)->get();
            Log::info('reached',[$request->all()]);
            // Group records by bank_name
            $eventsArray = $events->toArray();
    
            return $this->successResponse("List of Events", $eventsArray);
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to retrieve events", [$exception->getMessage()], $exception, $exception->getCode());
        }
    }




}
