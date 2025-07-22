<?php

if (!function_exists('hhmmss')) {
    function hhmmss($seconds) {
        $t = round($seconds);
        return sprintf('%02d:%02d:%02d', ($t/3600),($t/60%60), $t%60);
    }
}

function buildContext(\Throwable $throwable, array $context = []): array
{
    $context["message"] = $throwable->getMessage();
    $context["file"] = $throwable->getFile();
    $context["line"] = $throwable->getLine();
    $context["code"] = $throwable->getCode();
    buildPrevious($throwable, $context);
    return $context;
}

function buildPrevious(\Throwable $throwable, array &$context, $index = 0)
{
    $previous = $throwable->getPrevious();
    if ($previous) {
        $context["previous.$index"] = [
            "message" => $throwable->getMessage(),
            "file" => $throwable->getFile(),
            "line" => $throwable->getLine(),
            "code" => $throwable->getCode()
        ];
        buildPrevious($previous, $context, $index++);
    }
}
