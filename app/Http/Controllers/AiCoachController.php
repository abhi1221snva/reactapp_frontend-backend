<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

/**
 * @OA\Get(
 *   path="/ai-coach-api",
 *   summary="AI lead coaching — transcribe call audio and score lead via AI",
 *   operationId="aiCoachIndex",
 *   tags={"AI"},
 *   @OA\Parameter(name="wav_url", in="query", required=true, @OA\Schema(type="string", format="uri")),
 *   @OA\Response(response=200, description="AI lead scorecard with follow-up email"),
 *   @OA\Response(response=400, description="Missing wav_url parameter")
 * )
 */

class AiCoachController extends Controller
{
    public function index()
    {
        $deepgramApiKey = '3cd6a9df7dd92e7c8a524c75a7a6b6de70188a7d';
        $openaiApiKey = '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';

        header('Content-Type: application/json');

        if (!isset($_GET['wav_url'])) {
            echo json_encode(['error' => 'Missing wav_url parameter.']);
            exit;
        }

        $wavUrl = $_GET['wav_url'];

        $deepgramResponse = $this->callDeepgram($wavUrl, $deepgramApiKey);

        if (
            !$deepgramResponse ||
            !isset($deepgramResponse['results']['channels'][0]['alternatives'][0]['transcript'])
        ) {
            echo json_encode(['error' => 'CallAnalyzer transcription failed.']);
            exit;
        }

        $transcript = $deepgramResponse['results']['channels'][0]['alternatives'][0]['transcript'];
        $leadScoreText = $this->callOpenAI($transcript, $openaiApiKey);

        $parsedResponse = $this->parseLeadScorecard($leadScoreText);

        echo json_encode($parsedResponse);
        exit;
    }

    function callDeepgram($audioUrl, $apiKey)
    {
        $url = "https://api.deepgram.com/v1/listen?model=enhanced&diarize=true";

        $headers = [
            "Authorization: Token $apiKey",
            "Content-Type: application/json"
        ];

        $postData = json_encode(["url" => $audioUrl]);

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $response = curl_exec($ch);
        if (curl_errno($ch)) return null;

        curl_close($ch);
        return json_decode($response, true);
    }

    function callOpenAI($transcript, $apiKey)
    {
        $prompt = "
You are a funding analyst AI. Given the call transcript below, analyze the conversation and produce a Lead Scorecard with scores from 1–10 for the following categories:

- Interest Level
- Truthfulness
- Financial Eligibility
- Cooperation & Responsiveness
- Tone/Emotion
- Urgency
- Past Lending Experience
- Credibility (Total Picture)

Also, provide notes/justification for each score.

**At the end, calculate:**
- Total Score out of 80
- Final Score scaled 1–10
- Lead Category as a color-coded status:
    - 🟢 High Potential (8–10)
    - 🟡 Moderate / Cautious Follow-Up (6–7.9)
    - 🔴 Low Quality / Disengaged (<6)

Respond only in the following format:

Lead Scorecard:

| Category                   | Score | Notes |
|----------------------------|-------|-------|
| Interest Level             |       |       |
| Truthfulness               |       |       |
| Financial Eligibility      |       |       |
| Cooperation & Responsiveness|      |       |
| Tone/Emotion               |       |       |
| Urgency                    |       |       |
| Past Lending Experience    |       |       |
| Credibility (Total Picture)|       |       |

Final Weighted Score:

- Total Score: XX / 80  
- Final Score (1–10): X.X  
- Lead Category: 🟢/🟡/🔴 Description

Follow-Up Email (based on the conversation):

Subject: [Your email subject here]

[Your email body here in HTML format]

Transcript:
\"\"\"$transcript\"\"\"
";

        $url = "https://api.openai.com/v1/chat/completions";

        $headers = [
            "Authorization: Bearer $apiKey",
            "Content-Type: application/json"
        ];

        $postData = json_encode([
            "model" => "gpt-4",
            "messages" => [
                ["role" => "system", "content" => "You are a funding analyst who scores lead calls."],
                ["role" => "user", "content" => $prompt]
            ],
            "temperature" => 0.3
        ]);

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $response = curl_exec($ch);
        if (curl_errno($ch)) {
            return "OpenAI Error: " . curl_error($ch);
        }

        curl_close($ch);

        $result = json_decode($response, true);
        return $result['choices'][0]['message']['content'] ?? "Failed to get CallAnalyzer response.";
    }

    function parseLeadScorecard($text)
    {
        $lines = explode("\n", $text);
        $scorecard = [];
        $finalScore = [];

        $emailSubject = '';
        $emailBodyHtml = '';
        $emailStart = false;
        $emailBuffer = [];

        foreach ($lines as $line) {
            // Match scorecard table
            if (preg_match('/^\|\s*(.*?)\s*\|\s*(\d+)\s*\|\s*(.*?)\s*\|$/', $line, $matches)) {
                $scorecard[] = [
                    'category' => trim($matches[1]),
                    'score' => (int) trim($matches[2]),
                    'notes' => trim($matches[3])
                ];
            }

            // Match final scores
            if (preg_match('/Total Score:\s*(\d+)\s*\/\s*80/', $line, $matches)) {
                $finalScore['total_score'] = (int)$matches[1];
            }
            if (preg_match('/Final Score\s*\(1–10\):\s*([\d\.]+)/', $line, $matches)) {
                $finalScore['final_score'] = (float)$matches[1];
            }
            if (preg_match('/Lead Category:\s*(🟢|🟡|🔴)\s*(.*)/u', $line, $matches)) {
                $finalScore['lead_category'] = [
                    'emoji' => $matches[1],
                    'description' => trim($matches[2])
                ];
            }

            // Capture email section
            if (stripos($line, 'Follow-Up Email') !== false) {
                $emailStart = true;
                continue;
            }

            if ($emailStart) {
                $emailBuffer[] = $line;
            }
        }

        $emailFull = trim(implode("\n", $emailBuffer));

        // Extract subject
        if (preg_match('/^Subject:\s*(.*)/i', $emailFull, $matches)) {
            $emailSubject = trim($matches[1]);
            $emailBodyText = trim(preg_replace('/^Subject:.*\n?/i', '', $emailFull));
        } else {
            $emailSubject = 'Thank you for your time today';
            $emailBodyText = $emailFull;
        }

        // Convert to HTML-friendly
        $emailBodyHtml = nl2br(trim($emailBodyText));

        return [
            'scorecard' => $scorecard,
            'summary' => $finalScore,
            'email' => [
                'subject' => $emailSubject,
                'body' => $emailBodyHtml
            ]
        ];
    }
}
