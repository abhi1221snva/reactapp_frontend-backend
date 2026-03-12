<?php
namespace App\Http\Controllers;
use App\Model\Master\AreaCodeList;
use Illuminate\Http\Request;

/**
 * @OA\Get(
 *   path="/area-code-list",
 *   summary="List all area codes",
 *   operationId="areaCodeIndex",
 *   tags={"Area Codes"},
 *   security={{"Bearer":{}}},
 *   @OA\Response(response=200, description="Area code list"),
 *   @OA\Response(response=401, description="Unauthenticated")
 * )
 *
 * @OA\Get(
 *   path="/state-list",
 *   summary="Get area codes grouped by state",
 *   operationId="areaCodeGroupByState",
 *   security={{"Bearer":{}}},
 *   tags={"Area Codes"},
 *   @OA\Response(response=200, description="Area codes by state")
 * )
 */

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
