<?php

namespace App\Model;

use App\Http\Helper\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Model;
use Maatwebsite\Excel\Facades\Excel;

class ExcludeNumber extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $guarded = ['id'];
    protected $table = 'exclude_number';
    /*
     *Fetch Exclude Number list
     *@param integer $id
     *@return array
     */
    public function excludeNumberDetail($request)
    { 
        try {
        $searchTerm = $request->input('search');
        $limitString = '';
        $parameters = [];

        $query = "SELECT * FROM  $this->table";

        if (!empty($searchTerm)) {
            $query .= " WHERE (first_name LIKE CONCAT(?, '%') OR last_name LIKE CONCAT(?, '%') OR company_name LIKE CONCAT(?, '%') OR number LIKE CONCAT(?, '%'))";
            $parameters[] = $searchTerm;
            $parameters[] = $searchTerm;
            $parameters[] = $searchTerm;
            $parameters[] = $searchTerm;
        }

        $countQuery = "SELECT COUNT(*) as count " . substr($query, strpos($query, 'FROM'));
        $countParameters = $parameters;

        $query .= " ORDER BY updated_at DESC";

        if ($request->has('lower_limit') && $request->has('upper_limit') && is_numeric($request->input('lower_limit')) && is_numeric($request->input('upper_limit'))) {
            $query .= " LIMIT ?, ?";
            $parameters[] = $request->input('lower_limit');
            $parameters[] = $request->input('upper_limit');
        }

        $record = DB::connection('mysql_' . $request->auth->parent_id)->select($query, $parameters);

        $recordCount = DB::connection('mysql_' . $request->auth->parent_id)->selectOne($countQuery, $countParameters);
        $recordCount = (array)$recordCount;

        $data = (array)$record;

        if (!empty($data)) {
            return [
                'success' => true,
                'message' => 'Exclude Number.',
                'data' => $data,
                'record_count' => $recordCount['count'],
                'searchTerm'=>$searchTerm
            ];
        }

        return [
            'success' => false,
            'message' => 'Exclude Number not found.',
            'data' => [],
            'record_count' => 0,
            'errors' => [],
            'searchTerm'=>$searchTerm
        ];
    } catch (Exception $e) {
        Log::error($e->getMessage());
    } catch (InvalidArgumentException $e) {
        Log::error($e->getMessage());
    }
       
    }

    public function excludeNumberDetailo($request)
    {
        try
        {
            $data = array();
            $searchStr = array();
            if($request->has('number') && is_numeric($request->input('number')))
            {
                array_push($searchStr, "number like CONCAT(:number, '%')");
                $data['number'] = $request->input('number');
            }
            if($request->has('campaign_id') && is_numeric($request->input('campaign_id')))
            {
                array_push($searchStr, 'campaign_id = :campaign_id');
                $data['campaign_id'] = $request->input('campaign_id');
            }
            if ($request->has('first_name') && !empty($request->input('first_name')))
            {
                array_push($searchStr, "first_name like CONCAT(:first_name, '%')");
                $data['first_name'] = $request->input('first_name');
            }
            if ($request->has('last_name') && !empty($request->input('last_name'))) {
                array_push($searchStr, "last_name like CONCAT(:last_name, '%')");
                $data['last_name'] = $request->input('last_name');
            }
            if ($request->has('company_name') && !empty($request->input('company_name'))) {
                array_push($searchStr, "company_name like CONCAT(:company_name, '%')");
                $data['company_name'] = $request->input('company_name');
            }
            $str = !empty($searchStr) ? "  WHERE ".implode(" AND ", $searchStr) : '';
            $countData = $data; // capture params before LIMIT is added

        $limitString = '';
        if ($request->has('lower_limit') && $request->has('upper_limit') && is_numeric($request->input('lower_limit')) && is_numeric($request->input('upper_limit'))) {
            $data['lower_limit'] = $request->input('lower_limit');
            $data['upper_limit'] = $request->input('upper_limit');
            $limitString = " LIMIT :lower_limit, :upper_limit";
        }

        $sql = "SELECT * FROM " . $this->table . $str . $limitString;
            $record = DB::connection('mysql_' . $request->auth->parent_id)->select($sql, $data);
            $recordCount = DB::connection('mysql_' . $request->auth->parent_id)->selectOne("SELECT COUNT(*) as count FROM " . $this->table . $str, $countData);
            $recordCount = (array) $recordCount;
            $data = (array)$record;
            if(!empty($data))
            {
                return array(
                    'success'=> 'true',
                    'message'=> 'Exclude Number detail.',
                    'data'   => $data,
                    'record_count' => $recordCount['count']
                );
            }
            return array(
                'success'=> 'false',
                'message'=> 'Exclude Number not created.',
                'data'   => array(),
                'record_count'=>0
            );
        }
        catch (Exception $e)
        {
            Log::log($e->getMessage());
        }
        catch (InvalidArgumentException $e)
        {
            Log::log($e->getMessage());
        }
    }

    /*
     *Update Exclude Number details
     *@param object $request
     *@return array
     */
    // public function excludeNumberUpdate1($request)
    // {
    //     try {
    //         if ($request->has('number') && is_numeric($request->input('number')) && $request->has('campaign_id') && is_numeric($request->input('campaign_id')))
    //         {
    //             $updateString = array();
    //             $data['number'] = $request->input('number');
    //             $data['campaign_id'] = $request->input('campaign_id');
    //             if ($request->has('new_campaign_id') && is_numeric($request->input('new_campaign_id'))) {
    //                 array_push($updateString, 'campaign_id = :new_campaign_id');
    //                 $data['new_campaign_id'] = $request->input('new_campaign_id');
    //             }
    //             if ($request->has('first_name') && !empty($request->input('first_name')))
    //             {
    //                 array_push($updateString, 'first_name = :first_name');
    //                 $data['first_name'] = $request->input('first_name');
    //             }
    //             if ($request->has('last_name') && !empty($request->input('last_name'))) {
    //                 array_push($updateString, 'last_name = :last_name');
    //                 $data['last_name'] = $request->input('last_name');
    //             }
    //             if ($request->has('company_name') && !empty($request->input('company_name'))) {
    //                 array_push($updateString, 'company_name = :company_name');
    //                 $data['company_name'] = $request->input('company_name');
    //             }
    //             if (!empty($updateString) && !empty($data))
    //             {
    //                 $query = "UPDATE " . $this->table . " set " . implode(" , ", $updateString) . " WHERE number = :number AND campaign_id = :campaign_id";
    //                 $save = DB::connection('mysql_' . $request->auth->parent_id)->update($query, $data);
    //                 if ($save == 1) {
    //                     return array(
    //                         'success' => 'true',
    //                         'message' => 'Exclude Number updated successfully.'
    //                     );
    //                 } else {
    //                     return array(
    //                         'success' => 'false',
    //                         'message' => 'Exclude Number not updated.'
    //                     );
    //                 }
    //             }

    //             return array(
    //                 'success' => 'false',
    //                 'message' => 'Exclude Number doesn\'t exist.'
    //             );
    //         }
    //     }
    //     catch (Exception $e)
    //     {
    //         Log::log($e->getMessage());
    //     }
    //     catch (InvalidArgumentException $e)
    //     {
    //         Log::log($e->getMessage());
    //     }
    // }
// public function excludeNumberUpdate($request)
// {
//     try {

//         // Validate required fields
//         if (!$request->has('number') || !is_numeric($request->number) ||
//             !$request->has('campaign_id') || !is_numeric($request->campaign_id)) {

//             return response()->json([
//                 'success' => false,
//                 'message' => 'Invalid number or campaign_id.'
//             ], 400); // <-- return correct status code
//         }

//         $updateFields = [];
//         $data = [];

//         // OLD values (WHERE)
//         $data['old_number']     = $request->number;
//         $data['old_campaign_id'] = $request->campaign_id;

//         // Update number
//         if ($request->has('new_number') && is_numeric($request->new_number)) {
//             $updateFields[] = "number = :new_number";
//             $data['new_number'] = $request->new_number;
//         }

//         // Update campaign id
//         if ($request->has('new_campaign_id') && is_numeric($request->new_campaign_id)) {
//             $updateFields[] = "campaign_id = :new_campaign_id";
//             $data['new_campaign_id'] = $request->new_campaign_id;
//         }

//         // Update first name
//         if ($request->first_name) {
//             $updateFields[] = "first_name = :first_name";
//             $data['first_name'] = $request->first_name;
//         }

//         // Update last name
//         if ($request->last_name) {
//             $updateFields[] = "last_name = :last_name";
//             $data['last_name'] = $request->last_name;
//         }

//         // Update company name
//         if ($request->company_name) {
//             $updateFields[] = "company_name = :company_name";
//             $data['company_name'] = $request->company_name;
//         }

//         // No update fields found
//         if (empty($updateFields)) {
//             return response()->json([
//                 'success' => false,
//                 'message' => 'No valid fields to update.'
//             ], 400);
//         }

//         // SQL QUERY
//         $query = "
//             UPDATE {$this->table}
//             SET " . implode(", ", $updateFields) . "
//             WHERE number = :old_number
//             AND campaign_id = :old_campaign_id
//         ";

//         $save = DB::connection('mysql_' . $request->auth->parent_id)->update($query, $data);

//         if ($save >= 1) {
//             return response()->json([
//                 'success' => true,
//                 'message' => 'Exclude Number updated successfully.'
//             ], 200);
//         }

//         return response()->json([
//             'success' => false,
//             'message' => 'No record found with given number and campaign_id.'
//         ], 404); // <-- better response for "no changes"
//     }
//     catch (Exception $e) {

//         return response()->json([
//             'success' => false,
//             'message' => 'Server error.'
//         ], 500);
//     }
// }
public function excludeNumberUpdate($request)
{
    try {
        if (
            !$request->has('number') || !is_numeric($request->number) ||
            !$request->has('campaign_id') || !is_numeric($request->campaign_id)
        ) {
            return [
                'success' => false,
                'message' => 'Invalid number or campaign_id.',
                'code' => 400
            ];
        }

        $updateFields = [];
        $data = [];

        $data['old_number'] = $request->number;
        $data['old_campaign_id'] = $request->campaign_id;

        if ($request->has('new_number') && is_numeric($request->new_number)) {
            $updateFields[] = "number = :new_number";
            $data['new_number'] = $request->new_number;
        }

        if ($request->has('new_campaign_id') && is_numeric($request->new_campaign_id)) {
            $updateFields[] = "campaign_id = :new_campaign_id";
            $data['new_campaign_id'] = $request->new_campaign_id;
        }

        if ($request->first_name) {
            $updateFields[] = "first_name = :first_name";
            $data['first_name'] = $request->first_name;
        }

        if ($request->last_name) {
            $updateFields[] = "last_name = :last_name";
            $data['last_name'] = $request->last_name;
        }

        if ($request->company_name) {
            $updateFields[] = "company_name = :company_name";
            $data['company_name'] = $request->company_name;
        }

        if (empty($updateFields)) {
            return [
                'success' => false,
                'message' => 'No valid fields to update.',
                'code' => 400
            ];
        }

        $query = "
            UPDATE {$this->table}
            SET " . implode(', ', $updateFields) . "
            WHERE number = :old_number
              AND campaign_id = :old_campaign_id
        ";

        $save = DB::connection('mysql_' . $request->auth->parent_id)
            ->update($query, $data);

        if ($save >= 1) {
            return [
                'success' => true,
                'message' => 'Exclude Number updated successfully.',
                'code' => 200
            ];
        }
       // 3️⃣ Treat 0 as success
        return [
            'success' => true,
            'message' => $save > 0
                ? 'Exclude Number updated successfully.'
                : 'Exclude Number updated successfully.',
            'code' => 200
        ];

        return [
            'success' => false,
            'message' => 'No record found with given number and campaign_id.',
            'code' => 404
        ];

    } catch (\Throwable $e) {
        Log::error('excludeNumberUpdate', [
            'error' => $e->getMessage()
        ]);

        return [
            'success' => false,
            'message' => 'Server error.',
            'code' => 500
        ];
    }
}

    /*
     *Add Exclude Number details
     *@param object $request
     *@return array
     */
    // public function addExcludeNumber($request)
    // {
    //     try
    //     {
    //         if($request->has('number') && is_numeric($request->input('number')) && $request->has('campaign_id') && is_numeric($request->input('campaign_id'))) {
    //             $data['number'] = $request->input('number');
    //             $data['campaign_id'] = $request->input('campaign_id');
    //             $data['first_name'] = ($request->has('first_name') && !empty($request->input('first_name'))) ? $request->input('first_name') : "";
    //             $data['last_name'] = ($request->has('last_name') && !empty($request->input('last_name'))) ? $request->input('last_name') : "";
    //             $data['company_name'] = ($request->has('company_name') && !empty($request->input('company_name'))) ? $request->input('company_name') : "";
    //             $query = "INSERT INTO ".$this->table." (number, campaign_id, first_name, last_name, company_name) VALUE (:number, :campaign_id, :first_name, :last_name, :company_name)";
    //             $add =  DB::connection('mysql_'.$request->auth->parent_id)->update($query, $data);
    //             if($add == 1)
    //             {
    //                 return array(
    //                     'success'=> 'true',
    //                     'message'=> 'Exclude Number added successfully.'
    //                 );
    //             }
    //             else
    //             {
    //                 return array(
    //                     'success'=> 'false',
    //                     'message'=> 'Exclude Number are not added successfully.'
    //                 );
    //             }
    //         }

    //         return array(
    //             'success'=> 'false',
    //             'message'=> 'Exclude Number are not added successfully.'
    //         );
    //     }
    //     catch (Exception $e)
    //     {
    //         Log::log($e->getMessage());
    //     }
    //     catch (InvalidArgumentException $e)
    //     {
    //         Log::log($e->getMessage());
    //     }
    // }
    public function addExcludeNumber($request)
{
    try {

        if (
            $request->has('number') && is_numeric($request->input('number')) &&
            $request->has('campaign_id') && is_numeric($request->input('campaign_id'))
        ) {

            $number = $request->input('number');
            $campaignId = $request->input('campaign_id');

            // ✅ CHECK DUPLICATE FIRST
            $exists = DB::connection('mysql_'.$request->auth->parent_id)
                ->table($this->table)
                ->where('number', $number)
                ->where('campaign_id', $campaignId)
                ->exists();

            if ($exists) {
                return [
                    'success' => 'false',
                    'message' => 'This number is already excluded for this campaign.'
                ];
            }

            $data = [
                'number' => $number,
                'campaign_id' => $campaignId,
                'first_name' => $request->input('first_name', ''),
                'last_name' => $request->input('last_name', ''),
                'company_name' => $request->input('company_name', '')
            ];

            DB::connection('mysql_'.$request->auth->parent_id)
                ->table($this->table)
                ->insert($data);

            return [
                'success' => 'true',
                'message' => 'Exclude Number added successfully.'
            ];
        }

        return [
            'success' => 'false',
            'message' => 'Invalid input data.'
        ];

    } catch (\Exception $e) {
        Log::error($e->getMessage());

        return [
            'success' => 'false',
            'message' => 'Something went wrong.'
        ];
    }
}

    /*
     *Delete Exclude Number details
     *@param object $request
     *@return array
     */
    public function excludeNumberDelete($request)
    {
        try
        {
            if ($request->has('number') && is_numeric($request->input('number')) && $request->has('campaign_id') && is_numeric($request->input('campaign_id')))
            {
                $data['number'] = $request->input('number');
                $data['campaign_id'] = $request->input('campaign_id');
                $query = "DELETE FROM ".$this->table." WHERE number = :number AND campaign_id = :campaign_id";
                $save =  DB::connection('mysql_'.$request->auth->parent_id)->update($query, $data);
                if($save == 1)
                {
                    return array(
                        'success'=> 'true',
                        'message'=> 'Exclude Number deleted successfully.'
                    );
                }
                else
                {
                    return array(
                        'success'=> 'false',
                        'message'=> 'Exclude Number are not deleted successfully.'
                    );
                }

            }
            return array(
                'success'=> 'false',
                'message'=> 'Exclude Number doesn\'t exist.'
            );
        }
        catch (Exception $e)
        {
            Log::log($e->getMessage());
        }
        catch (InvalidArgumentException $e)
        {
            Log::log($e->getMessage());
        }
    }


    /**
     * Normalise a raw spreadsheet cell value into a phone-number string.
     */
    private function parsePhoneNumber($raw): string
    {
        if ($raw === null || $raw === '' || $raw === false) return '';
        $num = (string)(int) round((float) $raw);
        return (strlen($num) >= 7 && $num !== '0') ? $num : '';
    }

    public function uploadExcludeNumber($request, $filePath)
    {
        try {
            if (empty($filePath)) {
                return ['success' => 'false', 'message' => 'No file path provided.'];
            }

            try {
                $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($filePath);
                // formatData=false → raw cell values, no comma-separated number formatting
                $rows = $spreadsheet->getActiveSheet()->toArray(null, false, false, false);
            } catch (\Exception $e) {
                return ['success' => 'false', 'message' => 'Unable to read Excel file. Please upload a valid .xls, .xlsx, or .csv file.'];
            }

            array_shift($rows); // remove header row

            $inserted   = 0;
            $skipped    = 0;
            $firstError = null;
            $db = DB::connection('mysql_' . $request->auth->parent_id);

            foreach ($rows as $row) {
                // Scan ALL columns to find the phone number (handles any column order)
                $number     = '';
                $numberCol  = -1;
                foreach ($row as $colIdx => $cellVal) {
                    $parsed = $this->parsePhoneNumber($cellVal);
                    if ($parsed !== '') {
                        $number    = $parsed;
                        $numberCol = $colIdx;
                        break;
                    }
                }

                if ($number === '') { $skipped++; continue; }

                // Collect remaining non-empty string values (in column order) for name/company
                $strings    = [];
                $campaignId = 0;
                foreach ($row as $colIdx => $cellVal) {
                    if ($colIdx === $numberCol || $cellVal === null || $cellVal === '') continue;
                    $val = trim((string)$cellVal);
                    if ($val === '') continue;
                    // Small integer in its own column → treat as campaign_id
                    if (is_numeric($cellVal) && (int)$cellVal > 0) {
                        $campaignId = (int)$cellVal;
                    } else {
                        $strings[] = $val;
                    }
                }

                $firstName   = $strings[0] ?? '';
                $lastName    = $strings[1] ?? '';
                $companyName = $strings[2] ?? '';

                // Check for existing record to avoid duplicate inserts
                $exists = $db->table($this->table)
                    ->where('number', $number)
                    ->where('campaign_id', $campaignId)
                    ->exists();

                if ($exists) { $skipped++; continue; }

                try {
                    $db->table($this->table)->insert([
                        'number'       => $number,
                        'campaign_id'  => $campaignId,
                        'first_name'   => $firstName,
                        'last_name'    => $lastName,
                        'company_name' => $companyName,
                    ]);
                    $inserted++;
                } catch (\Exception $e) {
                    $skipped++;
                    if ($firstError === null) $firstError = $e->getMessage();
                }
            }

            if ($inserted === 0 && $skipped > 0) {
                $msg = "No new numbers inserted — {$skipped} row(s) were either duplicates or contained invalid phone numbers.";
                if ($firstError !== null) $msg .= " DB error: {$firstError}";
                return ['success' => 'false', 'message' => $msg];
            }

            return [
                'success' => 'true',
                'message' => "Exclude List import complete. {$inserted} number(s) added" . ($skipped > 0 ? ", {$skipped} duplicate(s) skipped." : "."),
            ];

        } catch (\Exception $e) {
            return ['success' => 'false', 'message' => 'Server error during import: ' . $e->getMessage()];
        }
    }
}
