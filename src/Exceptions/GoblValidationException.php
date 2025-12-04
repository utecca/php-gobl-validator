<?php

declare(strict_types=1);

namespace Ecourier\GoblValidator\Exceptions;

use Exception;
use Opis\JsonSchema\Errors\ValidationError;

class GoblValidationException extends Exception
{
    public function __construct(
        string $message,
        public readonly ?ValidationError $validationError = null,
    ) {
        parent::__construct($message);
    }

    /**
     * Get formatted validation errors as a clean associative array.
     *
     * This method processes the raw validation errors and consolidates
     * redundant messages (e.g., oneOf+const patterns for enum-like schemas)
     * into single, meaningful error messages.
     *
     * @return array<string, list<string>> Associative array of path => error messages
     */
    public function getFormattedErrors(): array
    {
        if ($this->validationError === null) {
            return [];
        }

        $errors = [];
        $this->collectErrors($this->validationError, $errors);

        // Filter out false-positive additionalProperties errors caused by nested validation failures
        return $this->filterFalsePositiveErrors($errors);
    }

    /**
     * Filter out additionalProperties errors that are false positives.
     *
     * When a nested validation fails (e.g., invalid country code), the validator
     * may report additionalProperties errors at ancestor paths. These are misleading
     * because the properties are actually valid - only the nested value is wrong.
     *
     * @param array<string, list<string>> $errors
     * @return array<string, list<string>>
     */
    private function filterFalsePositiveErrors(array $errors): array
    {
        $paths = array_keys($errors);

        // Find paths that have nested errors (children)
        $pathsWithNestedErrors = [];
        foreach ($paths as $path) {
            foreach ($paths as $otherPath) {
                if ($path === $otherPath) {
                    continue;
                }

                // Check if otherPath is a child of path
                // Special handling for root path '/'
                $isChild = ($path === '/')
                    ? (strlen($otherPath) > 1 && str_starts_with($otherPath, '/'))
                    : str_starts_with($otherPath, $path . '/');

                if ($isChild) {
                    $pathsWithNestedErrors[$path] = true;
                    break;
                }
            }
        }

        // Filter errors - remove additionalProperties/Unknown properties errors from paths that have nested errors
        $filtered = [];
        foreach ($errors as $path => $messages) {
            if (isset($pathsWithNestedErrors[$path])) {
                // Filter out additionalProperties-type messages
                $messages = array_filter($messages, function ($msg) {
                    return !str_starts_with($msg, 'Unknown properties:');
                });
            }

            if (!empty($messages)) {
                $filtered[$path] = array_values($messages);
            }
        }

        return $filtered;
    }

    /**
     * Recursively collect and process validation errors.
     *
     * @param ValidationError $error
     * @param array<string, list<string>> $errors
     */
    private function collectErrors(ValidationError $error, array &$errors): void
    {
        $subErrors = $error->subErrors();
        $keyword = $error->keyword();
        $path = $this->formatPath($error->data()->fullPath());

        // Handle oneOf/anyOf errors - check if this is an enum-like pattern (all const failures)
        if (($keyword === 'oneOf' || $keyword === 'anyOf') && !empty($subErrors)) {
            $enumMessage = $this->tryExtractEnumError($error, $subErrors);
            if ($enumMessage !== null) {
                $errors[$path][] = $enumMessage;
                return;
            }
        }

        // If there are sub-errors, process them recursively
        if (!empty($subErrors)) {
            foreach ($subErrors as $subError) {
                $this->collectErrors($subError, $errors);
            }
            return;
        }

        // Leaf error - add the message
        $message = $this->formatErrorMessage($error);
        if ($message !== null) {
            $errors[$path][] = $message;
        }
    }

    /**
     * Try to extract a meaningful enum error from a oneOf with const sub-errors.
     *
     * @param ValidationError $parentError
     * @param list<ValidationError> $subErrors
     * @return string|null The consolidated error message, or null if not an enum pattern
     */
    private function tryExtractEnumError(ValidationError $parentError, array $subErrors): ?string
    {
        $constValues = [];

        foreach ($subErrors as $subError) {
            // Check if this sub-error is a const mismatch
            if ($subError->keyword() === 'const') {
                $args = $subError->args();
                // Opis uses 'const' key for the expected value
                if (isset($args['const'])) {
                    $constValues[] = $args['const'];
                }
                continue;
            }

            // If we find a non-const sub-error, this isn't a simple enum pattern
            return null;
        }

        // If we collected const values, this is an enum-like pattern
        if (count($constValues) > 0) {
            $data = $parentError->data()->value();
            $dataStr = is_scalar($data) ? (string) $data : json_encode($data);

            // For large enums (like country codes), show a helpful message without listing all values
            if (count($constValues) > 10) {
                return "The value \"{$dataStr}\" is not a valid option";
            }

            // For smaller enums, show the allowed values
            $allowedValues = array_map(fn($v) => is_string($v) ? $v : json_encode($v), $constValues);
            return "The value \"{$dataStr}\" is not valid. Allowed values: " . implode(', ', $allowedValues);
        }

        return null;
    }

    /**
     * Format a JSON path array into a string.
     *
     * @param list<string|int> $path
     * @return string
     */
    private function formatPath(array $path): string
    {
        if (empty($path)) {
            return '/';
        }

        return '/' . implode('/', array_map(fn($p) => (string) $p, $path));
    }

    /**
     * Format a single error message.
     *
     * @param ValidationError $error
     * @return string|null
     */
    private function formatErrorMessage(ValidationError $error): ?string
    {
        $keyword = $error->keyword();
        $args = $error->args();

        return match ($keyword) {
            'required' => isset($args['missing'])
                ? 'Missing required property: ' . implode(', ', (array) $args['missing'])
                : 'Missing required property',
            'type' => isset($args['expected'], $args['used'])
                ? "Expected type \"{$args['expected']}\", got \"{$args['used']}\""
                : $error->message(),
            'additionalProperties' => isset($args['properties'])
                ? 'Unknown properties: ' . implode(', ', (array) $args['properties'])
                : 'Additional properties are not allowed',
            'minLength' => isset($args['min'])
                ? "Must be at least {$args['min']} characters"
                : 'Value is too short',
            'maxLength' => isset($args['max'])
                ? "Must be at most {$args['max']} characters"
                : 'Value is too long',
            'minimum' => isset($args['min'])
                ? "Must be at least {$args['min']}"
                : 'Value is too small',
            'maximum' => isset($args['max'])
                ? "Must be at most {$args['max']}"
                : 'Value is too large',
            'pattern' => 'Value does not match the required pattern',
            'format' => isset($args['format'])
                ? "Value is not a valid {$args['format']}"
                : 'Value format is invalid',
            'const' => isset($args['expected'])
                ? 'Value must be: ' . (is_string($args['expected']) ? $args['expected'] : json_encode($args['expected']))
                : 'Value does not match the required constant',
            'enum' => isset($args['expected'])
                ? 'Value must be one of: ' . implode(', ', array_map(fn($v) => is_string($v) ? $v : json_encode($v), (array) $args['expected']))
                : 'Value is not in the allowed list',
            default => $error->message(),
        };
    }
}
