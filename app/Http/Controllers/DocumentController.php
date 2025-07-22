<?php

namespace App\Http\Controllers;

use App\Model\Client\Documents;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use App\Model\Role;
use App\Model\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Http\Helper\Log;

class DocumentController extends Controller
{
    public function listByLeadId(Request $request)
    {
        try {
            $clientId = $request->auth->parent_id;
            //$clientId = 3;
            $Documents = [];
            $Documents = Documents::on("mysql_$clientId")->where('lead_id',$request->lead_id)->get()->all();
            return $this->successResponse("List of Documents", $Documents);
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to Documents ", [$exception->getMessage()], $exception, $exception->getCode());
        }
    }

    public function listByDocumentId(Request $request, int $id)
    {
        try {
            $clientId = $request->auth->parent_id;
            //$clientId = 3;
            $Documents = [];
            $Documents = Documents::on("mysql_$clientId")->where('id',$id)->get()->first();
            return $this->successResponse("List of Documents", $Documents->toArray());
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to Documents ", [$exception->getMessage()], $exception, $exception->getCode());
        }
    }

    public function list(Request $request)
    {
        try {
            $clientId = $request->auth->parent_id;
            //$clientId = 3;
            $Documents = [];
            $Documents = Documents::on("mysql_$clientId")->get()->all();
            return $this->successResponse("List of Documents", $Documents);
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to Documents ", [$exception->getMessage()], $exception, $exception->getCode());
        }
    }

    public function create(Request $request)
    {
        $clientId = $request->auth->parent_id;
         $this->validate($request, ['document_name' => 'required|string|max:255', 'document_type' => 'required|string', 'lead_id' => 'required|int']);
        try
        {
            $Documents = new Documents();
            $Documents->setConnection("mysql_$clientId");
            $Documents->lead_id = $request->lead_id;
            $Documents->document_name = $request->document_name;
            $Documents->document_type = $request->document_type;
            $Documents->file_name = $request->file_name;
            $Documents->file_size = $request->file_size;

            $Documents->saveOrFail();
            return $this->successResponse("Document Added Successfully", $Documents->toArray());
        }
        catch (\Exception $exception)
        {
            return $this->failResponse("Failed to create Documents ", [
                $exception->getMessage()
            ], $exception, 500);
        }
    }

    public function update(Request $request,$id)
    {
        $clientId = $request->auth->parent_id;
        $this->validate($request, ['document_name' => 'required|string|max:255']);
        try {
            
            $Documents = Documents::on("mysql_$clientId")->findOrFail($id);

            if ($request->has("document_name"))
                $Documents->document_name = $request->input("document_name");
            if ($request->has("document_type"))
                $Documents->document_type = $request->input("document_type");
            if ($request->has("file_name"))
                $Documents->file_name = $request->input("file_name");
            if ($request->has("file_size"))
                $Documents->file_size = $request->input("file_size");
                
            $Documents->saveOrFail();
            return $this->successResponse("Document Updated", $Documents->toArray());
        } catch (ModelNotFoundException $exception) {
            return $this->failResponse("Document Not Found", [
                "Invalid Label id $id"
            ], $exception, 404);
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to update Document", [
                $exception->getMessage()
            ], $exception, 404);
        }
    }

    public function delete(Request $request, $id)
    {
        $clientId = $request->auth->parent_id;
        try
        {
            $Documents = Documents::on("mysql_$clientId")->find($id)->delete();
            Log:info('reached',['Documents',$Documents]);
            return $this->successResponse("Document Deleted Successfully", [$Documents]);
        }
        catch (ModelNotFoundException $exception)
        {
            throw new NotFoundHttpException("No Document Name with id $id");
        }
        catch (\Throwable $exception)
        {
            return $this->failResponse("Failed to fetch Document Name info", [], $exception);
        }
    }

    public function changeLabelStatus(Request $request)
    {
        $clientId = $request->auth->parent_id;
        try
        {
            $Label = Label::on("mysql_$clientId")->findOrFail($request->label_id);
            $Label->status =$request->status;
            $Label->saveOrFail();
            return $this->successResponse("Label Updated", $Label->toArray());
        }
        catch (ModelNotFoundException $exception)
        {
            return $this->failResponse("Label Not Found", [
                "Invalid Label id $id"
            ], $exception, 404);
        }
        catch (\Throwable $exception)
        {
            return $this->failResponse("Failed to update Label", [
                $exception->getMessage()
            ], $exception, 404);
        }
    }
}
