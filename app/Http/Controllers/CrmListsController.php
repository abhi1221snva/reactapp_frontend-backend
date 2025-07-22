<?php

namespace App\Http\Controllers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Model\Client\CrmLists;

class CrmListsController extends Controller
{
    public function crmLists(Request $request)
    {
        $crmLists = CrmLists::on("mysql_" . $request->auth->parent_id)->get()->all();
        return $this->successResponse("CRM Lists", $crmLists);
    }
    public function crmList(Request $request)
    {
        try {
            $searchTerm = $request->input('search');
            $limitString = '';
            $parameters = [];
    
            $query = "SELECT SQL_CALC_FOUND_ROWS * FROM crm_lists WHERE deleted_at IS NULL";
    
            if (!empty($searchTerm)) {
                $query .=" AND (title LIKE CONCAT(?, '%') OR title_url LIKE CONCAT(?, '%') OR url LIKE CONCAT(?, '%'))";
                $parameters[] = $searchTerm;
                $parameters[] = $searchTerm;
                $parameters[] = $searchTerm;
            }
    
            if ($request->has('lower_limit') && $request->has('upper_limit') && is_numeric($request->input('lower_limit')) && is_numeric($request->input('upper_limit'))) {
                $query .= " LIMIT ?, ?";
                $parameters[] = $request->input('lower_limit');
                $parameters[] = $request->input('upper_limit');
            }
    
            $record = DB::connection('mysql_' . $request->auth->parent_id)->select($query, $parameters);
    
            $recordCount = DB::connection('mysql_' . $request->auth->parent_id)->selectOne("SELECT FOUND_ROWS() as count");
            $recordCount = (array)$recordCount;
    
            $crm_list = (array)$record;
    
            if (!empty($crm_list)) {
                return [
                    'success' => true,
                    'message' => 'Crm List Created.',
                    'data' => $crm_list,
                    'record_count' => $recordCount['count'],
                    'searchTerm'=>$searchTerm
                ];
            }
    
            return [
                'success' => false,
                'message' => 'Crm List not found.',
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
   
    public function create(Request $request)
    {
        $this->validate($request, [
            'title' => 'required|string|max:255|unique:'.'mysql_'.$request->auth->parent_id.'.crm_lists',
        ]);
        $attributes = $request->all();
        $crm_list = CrmLists::on("mysql_" . $request->auth->parent_id)->create($attributes);
        $crm_list->saveOrFail();
        return $this->successResponse("Crm List Created", $crm_list->toArray());
    }
    public function show(Request $request, int $id)
    {
        try
        {
            $crm_list = CrmLists::on("mysql_" . $request->auth->parent_id)->findOrFail($id);
            $data = $crm_list->toArray();
            return $this->successResponse("Crm List Info", $data);
        }
        catch (ModelNotFoundException $exception)
        {
            throw new NotFoundHttpException("No Crm with id $id");
        }
        catch (\Throwable $exception)
        {
            return $this->failResponse("Failed to fetch Crm List info", [], $exception);
        }
    }

    public function update(Request $request, int $id)
    {
        $this->validate($request, [
            'title' => 'required|string|max:255',
        ]);
         $input = $request->all();
        try
        {
            $crm_list = CrmLists::on("mysql_" . $request->auth->parent_id)->findOrFail($id);
            $crm_list->update($input);
            $data = $crm_list->toArray();
           
            return $this->successResponse("Crm List updated", $data);
        }
        catch (ModelNotFoundException $exception)
        {
            throw new NotFoundHttpException("No Crm List with id $id");
        }
        catch (\Throwable $exception)
        {
            return $this->failResponse("Failed to update Crm List", [], $exception);
        }
    }
    public function delete(Request $request, int $id)
    {
        try {
            $crm_list = CrmLists::on("mysql_" . $request->auth->parent_id)->findOrFail($id);
            $crm_list->delete(); // Soft delete the record
    
            return $this->successResponse("CRM List deleted", [$crm_list]);
        } catch (ModelNotFoundException $exception) {
            throw new NotFoundHttpException("No CRM with id $id");
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to fetch Crm List info", [], $exception);
        }
    }
   
}
