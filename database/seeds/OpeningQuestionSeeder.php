<?php

use App\Model\Master\Client;
use App\Model\Master\OpeningQuestion;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class OpeningQuestionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $arrQuestions = [
            ['question' => 'Do you want to setup a user ?', 'path' => 'add-extension', 'rank' => 1],
            ['question' => 'Do you want to set up a Inbound Phone Number for your company ?', 'path' => 'show-buy-did', 'rank' => 2],
            ['question' => 'do you want to Set up a Welcome Message for your caller ?', 'path' => 'ivr', 'rank' => 3],
            ['question' => 'Do you want to setup IVR options for callers ?', 'path' => 'ivr-menu', 'rank' => 4],
            ['question' => 'Do you want to setup holidays when you won’t be able to take calls ?', 'path' => 'did/holidays', 'rank' => 5],
            ['question' => 'Do you want to setup Call Times when you are available to take calls i.e Monday - Friday 09:00 to 17:00  ?', 'path' => 'did/call-timings', 'rank' => 6],
            ['question' => 'Do you want to setup your SMTP Customization ?', 'path' => 'smtp', 'rank' => 7],
            ['question' => 'Do you want to setup dispositions for your outbound calling campaigns ?', 'path' => 'disposition', 'rank' => 8],
            ['question' => 'Do you want to setup campaigns for your outbound calling campaigns ?', 'path' => 'add-campaign', 'rank' => 9],
            ['question' => 'Do you want to upload Labels for your excel leads ?', 'path' => 'label', 'rank' => 10],
            ['question' => 'Do you want to upload leads for any of your outbound campaigns ?', 'path' => 'list', 'rank' => 11],
            ['question' => 'Do you want to setup Email Templates ?', 'path' => 'email-template', 'rank' => 12],
            ['question' => 'Do you want to setup Text Templates ?', 'path' => 'sms-templete', 'rank' => 13],
            ['question' => 'Do you want to setup Marketing Campaign ? ', 'path' => 'marketing-campaigns', 'rank' => 14]

        ];
        $clients = Client::all();
        foreach ( $clients as $client ) {
            DB::connection("mysql_" . $client->id)->statement("DELETE FROM opening_questions_response;");
            DB::connection("mysql_" . $client->id)->statement("ALTER TABLE opening_questions_response AUTO_INCREMENT = 1");
        }

        DB::statement('DELETE FROM opening_questions');
        DB::statement('ALTER TABLE opening_questions AUTO_INCREMENT = 1');

        foreach ($arrQuestions as $key => $arrQuestion) {
            $objOpeningQuestion = new OpeningQuestion();
            $objOpeningQuestion->question = $arrQuestion['question'];
            $objOpeningQuestion->path = $arrQuestion['path'];
            $objOpeningQuestion->rank = $arrQuestion['rank'];
            $objOpeningQuestion->saveOrFail();
        }
    }
}
