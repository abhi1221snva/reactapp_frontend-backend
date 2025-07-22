<?php

namespace App\Http\Controllers;

use App\Model\Campaign;
use App\Model\Client\CallTimings;
use App\Model\Client\Disposition;
use App\Model\Client\EmailTemplete;
use App\Model\Client\Holiday;
use App\Model\Client\Label;
use App\Model\Client\Lists;
use App\Model\Client\MarketingCampaign;
use App\Model\Client\OpeningQuestionsResponse;
use App\Model\Client\SmtpSetting;
use App\Model\Ivr;
use App\Model\IvrMenu;
use App\Model\Master\Did;
use App\Model\Master\OpeningQuestion;
use App\Model\SmsTemplete;
use App\Model\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OpeningQuestionsController extends Controller
{
    public function getQuestionsInfo(Request $request)
    {
        $arrOpeningQuestionsData = [];
        try {
            $intClientId = NULL;
            $arrOpeningQuestions = OpeningQuestion::orderBy('rank', 'ASC')->get()->toArray();

            foreach ($arrOpeningQuestions as $key => $arrOpeningQuestion) {

                $arrOpeningQuestionsData[$key]['id'] = $arrOpeningQuestion['id'];
                $arrOpeningQuestionsData[$key]['question'] = $arrOpeningQuestion['question'];
                $arrOpeningQuestionsData[$key]['path'] = $arrOpeningQuestion['path'];
                $arrOpeningQuestionsData[$key]['rank'] = $arrOpeningQuestion['rank'];
                $arrOpeningQuestionsData[$key]['title'] = OpeningQuestion::$arrQuestionsHeadingsByPath[$arrOpeningQuestion['path']];
                $arrOpeningQuestionsData[$key]['icon'] = OpeningQuestion::$arrQuestionsIconsByPath[$arrOpeningQuestion['path']];

                if($arrOpeningQuestion['path'] == 'add-extension'){
                    $intClientId = $request->auth->base_parent_id;
                } else {
                    $intClientId = $request->auth->parent_id;
                }
                $intCount = $this->getQuestionInformationCurrentCount($arrOpeningQuestion['path'], $intClientId);
                $arrOpeningQuestionsData[$key]['count'] = $intCount;
            }
            return $this->successResponse("All Opening Questions & responses", array_values($arrOpeningQuestionsData));

        } catch (\Throwable $exception) {
            return $this->failResponse("Failed get Opening Questions", [$exception->getMessage()], $exception);
        }
    }

    /**
     * Returns next question as pe rank/sequence
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getNextQuestion(Request $request)
    {
        $arrOpeningQuestionsData = [];
        $intExtensionCount = NULL;
        try {
            $arrOpeningQuestions = OpeningQuestion::orderBy('rank', 'ASC')->get()->toArray();
            $arrOpeningQuestionsResponse = OpeningQuestionsResponse::on("mysql_" . $request->auth->parent_id)->get()->toArray();
            $arrOpeningQuestionsResponseRekeyed = UserPackagesController::rekeyArray($arrOpeningQuestionsResponse, 'opening_question_id');

            foreach ($arrOpeningQuestions as $key => $arrOpeningQuestion) {
                if (array_key_exists($arrOpeningQuestion['id'], $arrOpeningQuestionsResponseRekeyed) && $arrOpeningQuestionsResponseRekeyed[$arrOpeningQuestion['id']]['response'] == 'yes') {
                    continue;
                }

                $intCount = $this->getQuestionInformationCurrentCount($arrOpeningQuestion['path'], $request->auth->parent_id);
                if($arrOpeningQuestion['path'] == 'add-extension'){
                    $intExtensionCount = $intCount;
                }

                if ($intCount > 0) {
                    if (OpeningQuestionsResponse::on('mysql_' . $request->auth->parent_id)->where([['opening_question_id', '=', $arrOpeningQuestion['id']]])->count() <= 0) {
                        DB::connection('mysql_' . $request->auth->parent_id)->table('opening_questions_response')->insert(['opening_question_id' => $arrOpeningQuestion['id'], 'response' => 'yes']);
                    } else {
                        DB::connection('mysql_' . $request->auth->parent_id)->table('opening_questions_response')->where('opening_question_id', $arrOpeningQuestion['id'])->update(['response' => 'yes']);
                    }
                } else {
                    $arrOpeningQuestionsData[$key]['id'] = $arrOpeningQuestion['id'];
                    $arrOpeningQuestionsData[$key]['question'] = ucfirst($arrOpeningQuestion['question']);
                    $arrOpeningQuestionsData[$key]['path'] = $arrOpeningQuestion['path'];
                    $arrOpeningQuestionsData[$key]['rank'] = $arrOpeningQuestion['rank'];

                    $arrOpeningQuestionsData[$key+1]['extensionCount'] = $intExtensionCount;
                    break;
                }
            }
            return $this->successResponse("All Opening Questions & responses", array_values($arrOpeningQuestionsData));

        } catch (\Throwable $exception) {
            return $this->failResponse("Failed get Opening Questions", [$exception->getMessage()], $exception);
        }
    }

    public function hideQuestionsPermanently(Request $request)
    {
        try {
            \App\Model\Master\Client::where("id", $request->auth->parent_id)->update(['questions_dnd' => true]);
            return $this->successResponse("Hide Preferences saved", []);

        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to save preferences", [$exception->getMessage()], $exception);
        }
    }

    public function showQuestionsPermanently(Request $request)
    {
        try {
            \App\Model\Master\Client::where("id", $request->auth->parent_id)->update(['questions_dnd' => false]);
            return $this->successResponse("Show Preferences saved", []);

        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to save preferences", [$exception->getMessage()], $exception);
        }
    }

    public function getStatus(Request $request)
    {
        try{
            $client = \App\Model\Master\Client::findOrFail($request->auth->parent_id);
            return $this->successResponse("Opening Questions status", ["status" => $client->questions_dnd]);

        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to get status", [$exception->getMessage()], $exception);
        }

    }

    public function getQuestionInformationCurrentCount($strPath, $intClientId)
    {
        $intCount = 0;
        switch ($strPath) {
            case 'add-extension':
                $intCount = User::where('parent_id', $intClientId)->count();
                //condition for 2 user min because we create default 1 user while creating client.
                if ($intCount < 2) {
                    $intCount = 0;
                }
                break;
            case 'show-buy-did':
                $intCount = Did::where('parent_id', $intClientId)->count();
                break;
            case 'ivr':
                $intCount = Ivr::on('mysql_' . $intClientId)->count();
                break;
            case 'ivr-menu':
                $intCount = IvrMenu::on('mysql_' . $intClientId)->count();
                break;
            case 'did/holidays':
                $intCount = Holiday::on('mysql_' . $intClientId)->count();
                break;
            case 'did/call-timings':
                $intCount = CallTimings::on('mysql_' . $intClientId)->count();
                break;
            case 'smtp':
                $intCount = SmtpSetting::on('mysql_' . $intClientId)->count();
                break;
            case 'disposition':
                $intCount = Disposition::on('mysql_' . $intClientId)->count();
                break;
            case 'add-campaign':
                $intCount = Campaign::on('mysql_' . $intClientId)->count();
                break;
            case 'label':
                $intCount = Label::on('mysql_' . $intClientId)->count();
                break;
            case 'list':
                $intCount = Lists::on('mysql_' . $intClientId)->count();
                break;
            case 'email-template':
                $intCount = EmailTemplete::on('mysql_' . $intClientId)->count();
                break;
            case 'sms-templete':
                $intCount = SmsTemplete::on('mysql_' . $intClientId)->count();
                break;
            case 'marketing-campaigns':
                $intCount = MarketingCampaign::on('mysql_' . $intClientId)->count();
                break;
        }
        return $intCount;
    }
}
