<?php

declare(strict_types=1);

namespace app\components;

/**
 * The stable error envelope from contracts/openapi.yaml (`components.schemas.Error`).
 *
 * Only `code`, `message` and `details` are ever serialised. No exception class, file path,
 * SQL fragment or stack trace can leak through this type, which is what makes FR-010 and
 * the production-mode acceptance check enforceable rather than aspirational.
 */
final class ApiError implements \JsonSerializable
{
    public const BAD_REQUEST = 'bad_request';
    public const FORBIDDEN = 'forbidden';
    public const NOT_FOUND = 'not_found';
    public const METHOD_NOT_ALLOWED = 'method_not_allowed';
    public const UNSUPPORTED_MEDIA_TYPE = 'unsupported_media_type';
    public const VALIDATION_FAILED = 'validation_failed';
    public const INTERNAL_ERROR = 'internal_error';

    /**
     * @param array<string, list<string>> $details
     */
    public function __construct(
        public readonly string $code,
        public readonly string $message,
        public readonly array $details = [],
    ) {
    }

    /**
     * Maps an HTTP status to its stable machine-readable code.
     */
    public static function codeForStatus(int $status): string
    {
        return match ($status) {
            400 => self::BAD_REQUEST,
            403 => self::FORBIDDEN,
            404 => self::NOT_FOUND,
            405 => self::METHOD_NOT_ALLOWED,
            415 => self::UNSUPPORTED_MEDIA_TYPE,
            422 => self::VALIDATION_FAILED,
            default => self::INTERNAL_ERROR,
        };
    }

    /**
     * Builds a 422 body from Yii model errors.
     *
     * `details` is always present on a validation failure, as quickstart.md's API smoke
     * test requires, even though the OpenAPI schema marks the field optional overall.
     *
     * @param array<string, list<string>> $modelErrors
     */
    public static function validation(array $modelErrors): self
    {
        return new self(
            self::VALIDATION_FAILED,
            'The submitted data failed validation.',
            $modelErrors,
        );
    }

    public static function notFound(string $message = 'The requested item does not exist.'): self
    {
        return new self(self::NOT_FOUND, $message);
    }

    /**
     * @return array{code: string, message: string, details?: array<string, list<string>>}
     */
    public function jsonSerialize(): array
    {
        $payload = [
            'code' => $this->code,
            'message' => $this->message,
        ];

        if ($this->details !== []) {
            $payload['details'] = $this->details;
        }

        return $payload;
    }
}
