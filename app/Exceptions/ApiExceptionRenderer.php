<?php

namespace App\Exceptions;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Renders every API failure with one envelope:
 * { "message": "...", "code": "SOME_CODE", "errors": {} }
 */
class ApiExceptionRenderer
{
    /** @var array<int, string> */
    private const array STATUS_CODES = [
        400 => 'BAD_REQUEST',
        401 => 'UNAUTHENTICATED',
        403 => 'FORBIDDEN',
        404 => 'NOT_FOUND',
        405 => 'METHOD_NOT_ALLOWED',
        419 => 'PAGE_EXPIRED',
        422 => 'VALIDATION_FAILED',
        429 => 'TOO_MANY_REQUESTS',
        503 => 'SERVICE_UNAVAILABLE',
    ];

    public function __construct(private readonly bool $debug) {}

    public function render(Throwable $e): JsonResponse
    {
        return match (true) {
            $e instanceof ApiException => $this->envelope($e->getMessage(), $e->errorCode, $e->status),
            $e instanceof ValidationException => $this->envelope($e->getMessage(), 'VALIDATION_FAILED', $e->status, $e->errors()),
            $e instanceof AuthenticationException => $this->envelope('Unauthenticated', 'UNAUTHENTICATED', 401),
            $e instanceof HttpExceptionInterface => $this->renderHttpException($e),
            default => $this->envelope(
                $this->debug ? $e->getMessage() : 'Server error',
                'SERVER_ERROR',
                500,
            ),
        };
    }

    private function renderHttpException(HttpExceptionInterface&Throwable $e): JsonResponse
    {
        $status = $e->getStatusCode();
        $previous = $e->getPrevious();

        if ($previous instanceof ModelNotFoundException) {
            $model = Str::snake(class_basename($previous->getModel()));

            return $this->envelope(
                Str::ucfirst(str_replace('_', ' ', $model)).' not found',
                Str::upper($model).'_NOT_FOUND',
                404,
            );
        }

        $message = $e->getMessage() !== '' ? $e->getMessage() : 'HTTP error';

        return $this->envelope($message, self::STATUS_CODES[$status] ?? 'HTTP_ERROR', $status, headers: $e->getHeaders());
    }

    /**
     * @param  array<string, list<string>>  $errors
     * @param  array<string, string>  $headers
     */
    private function envelope(string $message, string $code, int $status, array $errors = [], array $headers = []): JsonResponse
    {
        return new JsonResponse([
            'message' => $message,
            'code' => $code,
            'errors' => (object) $errors,
        ], $status, $headers);
    }
}
