<?php


namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Throwable;

class RenderableException extends \Exception
{
    protected $errors = null;

    protected $httpStatus = 500;

    /**
     * @return string|null
     */
    public function errors(): ?array
    {
        return $this->errors;
    }

    /**
     * @return int
     */
    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }

    public function __construct($message, $errors = null, $httpStatus = 0, Throwable $previous = null, ?array $input = null, ?string $location = null)
    {
        parent::__construct($message, $httpStatus, $previous);
        $this->errors = $errors;

        if ($previous) {
            $previousCode = $previous->getCode();
            if ($previous instanceof RenderableException) {
                $this->httpStatus = $previous->getHttpStatus();
            } elseif ($this->isHttpCode($previousCode)) {
                $this->httpStatus = $previousCode;
            }
            if (empty($this->errors)) $this->errors = [$previous->getMessage()];
        }

        #Preference to $httpStatus
        if ($this->isHttpCode($httpStatus)) {
            $this->httpStatus = $httpStatus;
        }

        if (empty($location)) {
            $dbt = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
            $location = $dbt[1]['class'] . "." . $dbt[1]['function'];
            if (empty($input) && isset($dbt[1]['args'])) $input = $dbt[1]['args'];
        }
        Log::error($message, [
            "errors" => $errors,
            "httpStatus" => $httpStatus,
            "input" => $input,
            "location" => $location,
            "file" => $this->getFile(),
            "line" => $this->getLine(),
            "trace" => $this->getTrace()
        ]);
    }

    /**
     * Renders a standard json response for the exception
     * @return JsonResponse
     */
    public function render(): JsonResponse
    {
        return new JsonResponse([
            'success' => 0,
            'message' => $this->getMessage(),
            'errors' => $this->errors
        ], $this->httpStatus);
    }

    public function isHttpCode($code): bool
    {
        $code = intval($code / 100);
        return ($code >= 2 && $code <= 5);
    }
}
