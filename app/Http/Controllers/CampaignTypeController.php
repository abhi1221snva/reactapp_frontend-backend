<?php

namespace App\Http\Controllers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Model\Client\CampaignTypes;

class CampaignTypeController extends Controller
{
    public function index(Request $request)
    {
        $CampaignType = CampaignTypes::on("mysql_" . $request->auth->parent_id)->where('title_url', '!=', 'predictive_dial')->get()->all();
        return $this->successResponse("Campaign Type List", $CampaignType);
    }


    public function create(Request $request)
    {
        $this->validate($request, [
            'title' => 'required|string|max:255|unique:'.'mysql_'.$request->auth->parent_id.'.campaign_types',
        ]);
        $attributes = $request->only(['title', 'title_url', 'status']);
        $campaign_type = CampaignTypes::on("mysql_" . $request->auth->parent_id)->create($attributes);
        $campaign_type->saveOrFail();
        return $this->successResponse("Campaign Type Created", $campaign_type->toArray());
    }

    public function show(Request $request, int $id)
    {
        try
        {
            $campaign_type = CampaignTypes::on("mysql_" . $request->auth->parent_id)->findOrFail($id);
            $data = $campaign_type->toArray();
            return $this->successResponse("Campaign type Info", $data);
        }
        catch (ModelNotFoundException $exception)
        {
            throw new NotFoundHttpException("No Campaign Type with id $id");
        }
        catch (\Throwable $exception)
        {
            return $this->failResponse("Failed to fetch Campaign Type info", [], $exception);
        }
    }

    public function update(Request $request, int $id)
    {
        $this->validate($request, [
            'title' => 'required|string|max:255',
        ]);
         $input = $request->only(['title', 'title_url', 'status']);
        try
        {
            $campaign_type = CampaignTypes::on("mysql_" . $request->auth->parent_id)->findOrFail($id);
            $campaign_type->update($input);
            $data = $campaign_type->toArray();
           
            return $this->successResponse("Campaign type updated", $data);
        }
        catch (ModelNotFoundException $exception)
        {
            throw new NotFoundHttpException("No Campaign Type with id $id");
        }
        catch (\Throwable $exception)
        {
            return $this->failResponse("Failed to update Campaign Type", [], $exception);
        }
    }

    public function delete(Request $request, int $id)
    {
        try
        {
            $campaign_type = CampaignTypes::on("mysql_" . $request->auth->parent_id)->findOrFail($id);
            $data = $campaign_type->delete();

            return $this->successResponse("Campaign Type info", [$data]);
        }
        catch (ModelNotFoundException $exception)
        {
            throw new NotFoundHttpException("No Campaign type with id $id");
        }
        catch (\Throwable $exception)
        {
            return $this->failResponse("Failed to fetch Campaign info", [], $exception);
        }
    } 
}
