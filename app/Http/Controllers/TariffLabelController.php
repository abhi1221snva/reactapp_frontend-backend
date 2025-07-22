<?php

namespace App\Http\Controllers;
use App\Model\Master\TariffLabel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Model\Client\TariffLabelValues;

class TariffLabelController extends Controller
{
    public function index(Request $request)
    {
        $tariff_label = TariffLabel::on("master")->get()->all();
        return $this->successResponse("Tariff Label List", $tariff_label);
    }

    public function create(Request $request)
    {
        $this->validate($request, [
            'title' => 'required|string|max:255|unique:'.'master'.'.tariff_label',
        ]);
        $attributes = $request->all();
        $tariff_label = TariffLabel::on("master")->create($attributes);
        $tariff_label->saveOrFail();
        return $this->successResponse("Tariff Label created", $tariff_label->toArray());
    }

    public function show(Request $request, int $id)
    {
        try
        {
            $tariff_label = TariffLabel::on("master")->findOrFail($id);
            $data = $tariff_label->toArray();
            return $this->successResponse("Tariff Label info", $data);
        }
        catch (ModelNotFoundException $exception)
        {
            throw new NotFoundHttpException("No Tariff Label with id $id");
        }
        catch (\Throwable $exception)
        {
            return $this->failResponse("Failed to fetch Tariff Label info", [], $exception);
        }
    }

    public function update(Request $request, int $id)
    {
        $this->validate($request, ['title' => 'required|string|max:255','description' =>'required|string|max:255' ]);
        $input = $request->all();
        try
        {
            $custom_field_labels = TariffLabel::on("master")->findOrFail($id);
            $custom_field_labels->update($input);
            $data = $custom_field_labels->toArray();
           
            return $this->successResponse("Tariff Label updated", $data);
        }
        catch (ModelNotFoundException $exception)
        {
            throw new NotFoundHttpException("No Tariff Label with id $id");
        }
        catch (\Throwable $exception)
        {
            return $this->failResponse("Failed to update Tariff Label", [], $exception);
        }
    }

    public function delete(Request $request, int $id)
    {
        try
        {
            $custom_field_labels = TariffLabel::on("master")->findOrFail($id);
            $data = $custom_field_labels->delete();

            $custom_field_values = TariffLabelValues::on("mysql_" . $request->auth->parent_id)->where('tariff_id',$id);
            $custom_field_values->delete();
            return $this->successResponse("Tariff Label info", [$data]);
        }
        catch (ModelNotFoundException $exception)
        {
            throw new NotFoundHttpException("No Tariff Label with id $id");
        }
        catch (\Throwable $exception)
        {
            return $this->failResponse("Failed to fetch Tariff Label info", [], $exception);
        }
    } 
}
