<?php

namespace App\Services;

/**
 * Replaces {{placeholder}} tokens in email template HTML/subject strings.
 */
class TemplateParser
{
    /**
     * Replace {{key}} placeholders with values from $data.
     * Unreplaced placeholders are stripped.
     */
    public static function render(string $html, array $data): string
    {
        // Replace {{key}} and {{ key }} (with optional whitespace)
        $html = preg_replace_callback('/\{\{\s*(\w+)\s*\}\}/', function ($m) use ($data) {
            $key = $m[1];
            return array_key_exists($key, $data) ? (string) $data[$key] : '';
        }, $html);

        return $html;
    }

    /**
     * Render subject line (same logic, separate method for clarity).
     */
    public static function renderSubject(string $subject, array $data): string
    {
        return self::render($subject, $data);
    }
}
