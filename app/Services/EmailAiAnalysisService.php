<?php

namespace App\Services;

use App\Model\Client\GmailAiAnalysis;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class EmailAiAnalysisService
{
    protected string $apiKey;
    protected string $model;
    protected const API_URL = 'https://api.openai.com/v1/chat/completions';

    public function __construct()
    {
        $this->apiKey = config('services.openai.api_key', env('OPENAI_API_KEY'));
        $this->model = config('services.openai.model', env('OPENAI_MODEL', 'gpt-4-turbo-preview'));
    }

    /**
     * Analyze an email using OpenAI and return structured analysis.
     */
    public function analyzeEmail(array $email): ?array
    {
        if (empty($this->apiKey)) {
            Log::error('EmailAiAnalysis: OpenAI API key not configured');
            return null;
        }

        try {
            $prompt = $this->buildAnalysisPrompt($email);

            $response = Http::withToken($this->apiKey)
                ->timeout(30)
                ->post(self::API_URL, [
                    'model' => $this->model,
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => $this->getSystemPrompt(),
                        ],
                        [
                            'role' => 'user',
                            'content' => $prompt,
                        ],
                    ],
                    'temperature' => 0.3,
                    'max_tokens' => 1000,
                    'response_format' => ['type' => 'json_object'],
                ]);

            if (!$response->successful()) {
                Log::error('EmailAiAnalysis: OpenAI API request failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return null;
            }

            $data = $response->json();
            $content = $data['choices'][0]['message']['content'] ?? null;

            if (!$content) {
                Log::error('EmailAiAnalysis: Empty response from OpenAI');
                return null;
            }

            $analysis = json_decode($content, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                Log::error('EmailAiAnalysis: Failed to parse OpenAI response', [
                    'content' => $content,
                ]);
                return null;
            }

            return $this->normalizeAnalysis($analysis);

        } catch (\Exception $e) {
            Log::error('EmailAiAnalysis: Exception during analysis', [
                'error' => $e->getMessage(),
                'email_subject' => $email['subject'] ?? 'N/A',
            ]);
            return null;
        }
    }

    /**
     * Analyze email and save to database.
     */
    public function analyzeAndSave(array $email, int $userId, string $connection): ?GmailAiAnalysis
    {
        $analysis = $this->analyzeEmail($email);

        if (!$analysis) {
            return null;
        }

        try {
            $record = new GmailAiAnalysis();
            $record->setConnection($connection);
            $record->user_id = $userId;
            $record->gmail_message_id = $email['message_id'];
            $record->summary = $analysis['summary'] ?? null;
            $record->priority = $analysis['priority'] ?? 'medium';
            $record->category = $analysis['category'] ?? null;
            $record->urgency_reason = $analysis['urgency_reason'] ?? null;
            $record->suggested_actions = $analysis['suggested_actions'] ?? null;
            $record->suggested_reply = $analysis['suggested_reply'] ?? null;
            $record->sentiment = $analysis['sentiment'] ?? null;
            $record->key_points = $analysis['key_points'] ?? null;
            $record->raw_response = $analysis;
            $record->save();

            return $record;

        } catch (\Exception $e) {
            Log::error('EmailAiAnalysis: Failed to save analysis', [
                'error' => $e->getMessage(),
                'message_id' => $email['message_id'],
            ]);
            return null;
        }
    }

    /**
     * Get existing analysis from database.
     */
    public function getExistingAnalysis(string $messageId, int $userId, string $connection): ?GmailAiAnalysis
    {
        return GmailAiAnalysis::on($connection)
            ->where('user_id', $userId)
            ->where('gmail_message_id', $messageId)
            ->first();
    }

    /**
     * Build the analysis prompt from email data.
     */
    protected function buildAnalysisPrompt(array $email): string
    {
        $prompt = "Analyze the following email:\n\n";
        $prompt .= "From: {$email['sender_name']} <{$email['sender_email']}>\n";
        $prompt .= "Subject: {$email['subject']}\n";
        $prompt .= "Date: {$email['date']}\n";

        if (!empty($email['attachments'])) {
            $attachmentNames = array_map(fn($a) => $a['filename'], $email['attachments']);
            $prompt .= "Attachments: " . implode(', ', $attachmentNames) . "\n";
        }

        $prompt .= "\nEmail Body:\n";
        $prompt .= $email['preview'] ?? '[No content available]';

        return $prompt;
    }

    /**
     * Get the system prompt for email analysis.
     */
    protected function getSystemPrompt(): string
    {
        return <<<PROMPT
You are an AI email assistant that analyzes incoming emails and provides structured insights.

Analyze the email and respond with a JSON object containing:

1. "summary" (string): A concise 2-3 sentence summary of the email's main content and purpose.

2. "priority" (string): One of "high", "medium", or "low" based on:
   - HIGH: Urgent deadlines, time-sensitive requests, important business matters, issues requiring immediate attention
   - MEDIUM: Standard business communications, follow-ups, meetings, general requests
   - LOW: Newsletters, promotional content, FYI messages, non-urgent updates

3. "category" (string): One of: "sales", "support", "meeting", "newsletter", "personal", "finance", "urgent", "spam", "other"

4. "urgency_reason" (string): A brief explanation of why this priority level was assigned.

5. "suggested_actions" (array of strings): 2-4 actionable next steps the recipient should consider.

6. "suggested_reply" (string or null): If a reply seems appropriate, provide a professional draft reply. Set to null if no reply is needed (e.g., newsletters, spam).

7. "sentiment" (string): One of "positive", "neutral", or "negative" based on the tone.

8. "key_points" (array of strings): 3-5 bullet points summarizing the most important information.

Always respond with valid JSON. Be professional and concise. Focus on actionable insights.
PROMPT;
    }

    /**
     * Normalize and validate the analysis response.
     */
    protected function normalizeAnalysis(array $analysis): array
    {
        $validPriorities = ['high', 'medium', 'low'];
        $validSentiments = ['positive', 'neutral', 'negative'];
        $validCategories = ['sales', 'support', 'meeting', 'newsletter', 'personal', 'finance', 'urgent', 'spam', 'other'];

        return [
            'summary' => $this->truncateString($analysis['summary'] ?? '', 500),
            'priority' => in_array(strtolower($analysis['priority'] ?? ''), $validPriorities)
                ? strtolower($analysis['priority'])
                : 'medium',
            'category' => in_array(strtolower($analysis['category'] ?? ''), $validCategories)
                ? strtolower($analysis['category'])
                : 'other',
            'urgency_reason' => $this->truncateString($analysis['urgency_reason'] ?? '', 255),
            'suggested_actions' => is_array($analysis['suggested_actions'] ?? null)
                ? array_slice($analysis['suggested_actions'], 0, 5)
                : [],
            'suggested_reply' => isset($analysis['suggested_reply']) && is_string($analysis['suggested_reply'])
                ? $analysis['suggested_reply']
                : null,
            'sentiment' => in_array(strtolower($analysis['sentiment'] ?? ''), $validSentiments)
                ? strtolower($analysis['sentiment'])
                : 'neutral',
            'key_points' => is_array($analysis['key_points'] ?? null)
                ? array_slice($analysis['key_points'], 0, 5)
                : [],
        ];
    }

    /**
     * Truncate string to max length.
     */
    protected function truncateString(?string $str, int $maxLength): string
    {
        if (empty($str)) {
            return '';
        }

        if (mb_strlen($str) <= $maxLength) {
            return $str;
        }

        return mb_substr($str, 0, $maxLength - 3) . '...';
    }

    /**
     * Check if the service is properly configured.
     */
    public function isConfigured(): bool
    {
        return !empty($this->apiKey);
    }
}
