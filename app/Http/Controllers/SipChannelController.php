<?php

namespace App\Http\Controllers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Model\Client\SipChannelProvider;
use Illuminate\Validation\Rule;


class SipChannelController extends Controller
{
   
    public function index(Request $request)
{
    try {
        $searchTerm = $request->input('search');
        $limitString = '';
        $parameters = [];

        $query = "SELECT * FROM sip_channel_provider  WHERE deleted_at IS NULL";

        if (!empty($searchTerm)) {
            $query .= " AND (title LIKE CONCAT(?, '%') OR channel_provider LIKE CONCAT(?, '%'))";
            $parameters[] = $searchTerm;
            $parameters[] = $searchTerm;
        }

        $countQuery = "SELECT COUNT(*) as count " . substr($query, strpos($query, 'FROM'));
        $countParameters = $parameters;

        if ($request->has('lower_limit') && $request->has('upper_limit') && is_numeric($request->input('lower_limit')) && is_numeric($request->input('upper_limit'))) {
            $query .= " LIMIT ?, ?";
            $parameters[] = $request->input('lower_limit');
            $parameters[] = $request->input('upper_limit');
        }

        $record = DB::connection('mysql_' . $request->auth->parent_id)->select($query, $parameters);

        $recordCount = DB::connection('mysql_' . $request->auth->parent_id)->selectOne($countQuery, $countParameters);
        $recordCount = (array)$recordCount;
        $campaignTypes = DB::connection('mysql_' . $request->auth->parent_id)->select("SELECT * FROM campaign_types WHERE status = '1'");

        $sip_channel = (array)$record;

        if (!empty($sip_channel)) {
            return [
                'success' => 'true',
                'message' => 'Sip Channel Provider Created.',
                'data' => $sip_channel,
                'record_count' => $recordCount['count'],
                'campaign_types' => $campaignTypes, 
                'searchTerm'=>$searchTerm
            ];
        }
else{
        return [
            'success' => 'false',
            'message' => 'Sip Channel Provider List not found.',
            'data' => [],
            'record_count' => 0,
            'campaign_types' => $campaignTypes, 
            'errors' => [], // Add an empty array for the "errors" property
            'searchTerm'=>$searchTerm
        ];
    }
    } catch (Exception $e) {
        Log::error($e->getMessage());
    } catch (InvalidArgumentException $e) {
        Log::error($e->getMessage());
    }
    
}

    
    public function create(Request $request)
    {
    
        $this->validate($request, [
            'title' => [
                'required',
                'string',
                'max:255',
                Rule::unique('mysql_'.$request->auth->parent_id.'.sip_channel_provider')->where(function ($query) {
                    $query->where('status', 1);
                }),
            ],
        ], [
            'title.unique' => 'The same title with an active status already exists.',
        ]);

        // $this->validate($request, [
        //     'title' => 'required|string|max:255|unique:'.'mysql_'.$request->auth->parent_id.'.sip_channel_provider',
        // ]);
        $attributes = $request->all();
        $sip_list = SipChannelProvider::on("mysql_" . $request->auth->parent_id)->create($attributes);
        $sip_list->saveOrFail();
        return $this->successResponse("Sip Provider List Created", $sip_list->toArray());
    }
    public function show(Request $request, int $id)
    {
        try
        {
            $sip_list = SipChannelProvider::on("mysql_" . $request->auth->parent_id)->findOrFail($id);
            $data = $sip_list->toArray();
            return $this->successResponse("Sip Provider List Info", $data);
        }
        catch (ModelNotFoundException $exception)
        {
            throw new NotFoundHttpException("No Sip Provider with id $id");
        }
        catch (\Throwable $exception)
        {
            return $this->failResponse("Failed to fetch Sip Provider info", [], $exception);
        }
    }
    public function update(Request $request, int $id)
{
    $this->validate($request, [
        'title' => [
            'required',
            'string',
            'max:255',
            Rule::unique('mysql_' . $request->auth->parent_id . '.sip_channel_provider')->where(function ($query) use ($id) {
                $query->where('status', 1)->where('id', '<>', $id);
            }),
        ],
    ], [
        'title.unique' => 'The same title with an active status already exists.',
    ]);

    try {
        $input = $request->all();
        $sip_list = SipChannelProvider::on("mysql_" . $request->auth->parent_id)->findOrFail($id);
        $sip_list->update($input);
        $data = $sip_list->toArray();

        return $this->successResponse("Sip Provider List updated", $data);
    } catch (ModelNotFoundException $exception) {
        throw new NotFoundHttpException("No Sip Provider List with id $id");
    } catch (\Throwable $exception) {
        return $this->failResponse("Failed to update Sip Provider List", [], $exception);
    }
}

   
    public function delete(Request $request, int $id)
    {
        try {
            $sip_list = SipChannelProvider::on("mysql_" . $request->auth->parent_id)->findOrFail($id);
            $sip_list->delete(); // Soft delete the record
    
            return $this->successResponse("Sip Provider List deleted", [$sip_list]);
        } catch (ModelNotFoundException $exception) {
            throw new NotFoundHttpException("No Sip Provider with id $id");
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to fetch Sip Provider info", [], $exception);
        }
    }
  
   
 

}
