<?php
namespace App\Http\Controllers;
use App\Model\Master\AreaCodeList;
use Illuminate\Http\Request;

class AreaCodeController extends Controller
{
    public function index(Request $request)
    {
        $areacode = AreaCodeList::on("master")->orderBy('state_name', 'ASC')->get()->all();
        return $this->successResponse("AreaCode List", $areacode);
    }


    public function groupByAreaCode(Request $request)
    {
        $areacode = AreaCodeList::on("master")->groupBy('state_name')->orderBy('state_name', 'ASC')->get()->all();
        return $this->successResponse("AreaCode List", $areacode);
    }

}
