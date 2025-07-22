<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\UnauthorizedException;
use Illuminate\Validation\ValidationException;
use Laravel\Lumen\Exceptions\Handler as ExceptionHandler;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class Handler extends ExceptionHandler
{
    /**
     * A list of the exception types that should not be reported.
     *
     * @var array
     */
    protected $dontReport = [
        AuthorizationException::class,
        HttpException::class,
        ModelNotFoundException::class,
        ValidationException::class,
    ];

    /**
     * Report or log an exception.
     *
     * This is a great spot to send exceptions to Sentry, Bugsnag, etc.
     *
     * @param \Throwable $exception
     * @return void
     */
    public function report(\Throwable $exception)
    {
        parent::report($exception);
    }

    /**
     * Render an exception into an HTTP response.
     *
     * @param \Illuminate\Http\Request $request
     * @param \Throwable $exception
     * @return \Illuminate\Http\Response|\Illuminate\Http\JsonResponse
     */
    public function render($request, \Throwable $exception)
    {
        if ($exception instanceof AuthenticationException && $request->expectsJson()) {
            return $this->getJsonErrorResponse(
                $exception->getMessage(),
                401
            );
        }
        if ($exception instanceof NotFoundHttpException && $request->expectsJson()) {
            return $this->getJsonErrorResponse(
                "Resource not found",
                404,
                [$exception->getMessage()]
            );
        }
        if ($exception instanceof MethodNotAllowedHttpException && $request->expectsJson()) {
            return $this->getJsonErrorResponse($exception->getMessage(), 404, [$exception->getMessage()]);
        }
        if ($exception instanceof ModelNotFoundException && $request->expectsJson()) {
            return $this->getJsonErrorResponse($exception->getMessage(), 404, [$exception->getMessage()]);
        }
        if ($exception instanceof UnauthorizedException && $request->expectsJson()) {
            return $this->getJsonErrorResponse(
                "Unauthorized",
                401,
                [$exception->getMessage()]
            );
        }
        if ($exception instanceof ForbiddenException) {
            return $this->getJsonErrorResponse(
                "Forbidden",
                403,
                ["You are not authorize to access this resource"]
            );
        }
        if ($exception instanceof ValidationException && $request->expectsJson()) {
            return $this->getJsonErrorResponse("Invalid input", 400, $exception->errors());
        }
        if (!method_exists($exception, 'render')) {
            if (!($exception instanceof Responsable)) {
                if ($request->expectsJson()) {
                    $code = $exception->getCode();
                    $message = $exception->getMessage();
                    $log = [
                        "file" => $exception->getFile(),
                        "line" => $exception->getLine(),
                        "code" => $code
                    ];
                    $previous = $exception->getPrevious();
                    if ($previous) {
                        $log["previous"] = [
                            "message" => $previous->getMessage(),
                            "code" => $previous->getCode(),
                            "file" => $previous->getFile(),
                            "line" => $previous->getLine()
                        ];
                    }
                    Log::critical($message, $log);
                    return $this->getJsonErrorResponse("Oops! Something failed.", $code, [$message]);
                }
            }
        }
        return parent::render($request, $exception);
    }

    private function getJsonErrorResponse($message, $statusCode, $errors = [])
    {
        return response()->json([
            'success' => false,
            "message" => $message,
            "errors" => $errors
        ], ($this->isHttpCode($statusCode) ? $statusCode : 500));
    }

    private function isHttpCode($code): bool
    {
        if (!is_numeric($code)) return false;
        $code = intval($code / 100);
        return ($code >= 2 && $code <= 5);
    }
}
