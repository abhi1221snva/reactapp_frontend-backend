<?php

namespace App\Http\Controllers;

use App\Model\Client\TariffLabelValues;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TariffLabelValuesController extends Controller
{
    public function index(Request $request)
    {
        $tariff_label = TariffLabelValues::on("mysql_" . $request->auth->parent_id)->get()->all();
        return $this->successResponse("Tariff Label Value List", $tariff_label);
    }

    public function create(Request $request)
    {
        $this->validate($request, [
            'tariff_id'      => 'required|integer',
            "phone_countries_id"    => "required|array",
            "rate"          => "required|array"
        ]);

        $count = count($request->phone_countries_id);
        for($i=0;$i<$count;$i++)
        {
            $tariff_label = new TariffLabelValues();
            $tariff_label->setConnection("mysql_" . $request->auth->parent_id);
            $tariff_label->tariff_id = $request->tariff_id;
            $tariff_label->phone_countries_id = $request->phone_countries_id[$i];
            $tariff_label->rate = $request->rate[$i];
            $tariff_label->save();
        }

        return $this->successResponse("Tariff Label Values created", $tariff_label->toArray());
    }

    public function show(Request $request, int $id)
    {
        try
        {
            $tariff_label = TariffLabelValues::on("mysql_" . $request->auth->parent_id)->where('tariff_id',$id)->get()->all();
           // $data = $tariff_label->toArray();
            return $this->successResponse("Tariff Label  Value info", $tariff_label);
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
        $this->validate($request, [
            'tariff_id'      => 'required|integer',
            "phone_countries_id"    => "required|array",
            "rate"          => "required|array"
        ]);
        
        try
        {
            $count = count($request->phone_countries_id);
            for($i=0;$i<$count;$i++)
            {
                $id = $request->tariff_value_id[$i];
                $input = [
                    'phone_countries_id' => $request->phone_countries_id[$i],
                    'rate' => $request->rate[$i]
                ];

                TariffLabelValues::on("mysql_" . $request->auth->parent_id)->where('id',$id)->update($input);
            }

            return $this->successResponse("Custom Field Label updated");
        }
        catch (ModelNotFoundException $exception)
        {
            throw new NotFoundHttpException("No Custom Field Label with id $id");
        }
        catch (\Throwable $exception)
        {
            return $this->failResponse("Failed to update Custom Field Label", [], $exception);
        }
    }

    public function delete(Request $request, int $id)
    {
        try
        {
            $custom_field_labels = TariffLabelValues::on("mysql_" . $request->auth->parent_id)->findOrFail($id);
            $data = $custom_field_labels->delete();
            return $this->successResponse("Tariff Label Value info deleted", [$data]);
        }
        catch (ModelNotFoundException $exception)
        {
            throw new NotFoundHttpException("No Tariff Label Value with id $id");
        }
        catch (\Throwable $exception)
        {
            return $this->failResponse("Failed to fetch Tariff Label Value info", [], $exception);
        }
    }

 
    
}
