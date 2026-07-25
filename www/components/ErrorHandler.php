<?php

declare(strict_types=1);

namespace app\components;

use yii\base\InvalidRouteException;
use yii\web\HttpException;
use yii\web\Response;

/**
 * Renders API failures as the stable {@see ApiError} envelope (FR-010).
 *
 * The 2017 controllers wrapped every action in `catch (\Exception)` and returned
 * `['error' => 'Unknown error']`, which hid real faults from operators while still risking
 * a leak whenever an error escaped the try block. Here the two concerns are separated:
 * the client always receives a safe, typed envelope, and the full exception is written to
 * the structured log where an operator can actually act on it.
 *
 * The distinction that matters for security is 4xx vs 5xx. A 4xx message is written by
 * this application and is safe to echo. A 5xx message may contain a DSN, a file path or an
 * SQL fragment, so it is replaced by a fixed string in every environment; the detail is
 * only ever added when YII_DEBUG is on, which production forbids.
 */
final class ErrorHandler extends \yii\web\ErrorHandler
{
    /**
     * @param \Throwable $exception
     */
    protected function renderException($exception): void
    {
        if (!$this->wantsJson()) {
            parent::renderException($exception);

            return;
        }

        $response = \Yii::$app->getResponse();
        $response->clearOutputBuffers();
        $response->format = Response::FORMAT_JSON;
        $response->setStatusCodeByException($exception);
        $response->data = $this->toApiError($exception, $response->getStatusCode())->jsonSerialize();
        $response->send();
    }

    /**
     * JSON is used for /api/*, and for any request whose client asked for it. HTML error
     * pages remain for the single browser page.
     */
    private function wantsJson(): bool
    {
        $request = \Yii::$app->getRequest();

        if (!$request instanceof \yii\web\Request) {
            return false;
        }

        if (str_starts_with('/' . ltrim($request->getPathInfo(), '/'), '/api/')) {
            return true;
        }

        $accept = $request->getHeaders()->get('Accept', '');

        return \is_string($accept) && str_contains($accept, 'application/json');
    }

    private function toApiError(\Throwable $exception, int $status): ApiError
    {
        $code = ApiError::codeForStatus($status);

        if ($status >= 500 || !$exception instanceof HttpException) {
            // InvalidRouteException surfaces as a 404 through setStatusCodeByException,
            // but is not an HttpException, so handle the not-found case explicitly.
            if ($exception instanceof InvalidRouteException) {
                return ApiError::notFound('The requested endpoint does not exist.');
            }

            return new ApiError(
                ApiError::INTERNAL_ERROR,
                'An internal error occurred. The incident has been logged.',
            );
        }

        $message = $exception->getMessage();

        return new ApiError(
            $code,
            $message === '' ? 'Request rejected.' : $message,
        );
    }
}
