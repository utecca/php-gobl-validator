<?php

declare(strict_types=1);

namespace Ecourier\GoblValidator;

use Ecourier\GoblValidator\Exceptions\GoblValidationException;
use Ecourier\GoblValidator\Exceptions\InvalidSchemaException;
use Opis\JsonSchema\CompliantValidator;
use Opis\JsonSchema\Uri;

class GoblValidator
{
    /**
     * The version of GOBL which the schemas are derived from.
     */
    public static string $GOBL_VERSION = '0.303.0';

    private const SCHEMA_PREFIX = 'https://gobl.org/draft-0/';

    /**
     * Root document types that can be validated as standalone documents.
     */
    public const ROOT_SCHEMAS = [
        'envelope' => self::SCHEMA_PREFIX . 'envelope',
        'invoice' => self::SCHEMA_PREFIX . 'bill/invoice',
        'order' => self::SCHEMA_PREFIX . 'bill/order',
    ];

    private ?CompliantValidator $validator = null;
    private string $schemasPath;

    public function __construct(?string $schemasPath = null)
    {
        $this->schemasPath = $schemasPath ?? __DIR__ . '/../schemas';
    }

    /**
     * Validate data by auto-detecting the schema from the $schema property.
     * Only validates against known root schemas (envelope, invoice, order).
     *
     * @throws GoblValidationException If validation fails or $schema is missing
     * @throws InvalidSchemaException If the $schema is not a known root schema
     */
    public function validate(string|object $data): void
    {
        $data = $this->parseData($data);

        if (!isset($data->{'$schema'})) {
            throw new GoblValidationException('The data does not contain a $schema property.');
        }

        $schemaUri = $data->{'$schema'};

        if (!in_array($schemaUri, self::ROOT_SCHEMAS, true)) {
            throw new InvalidSchemaException($schemaUri);
        }

        $this->performValidation($data, $schemaUri);
    }

    /**
     * Validate data specifically as a GOBL envelope.
     *
     * @throws GoblValidationException If validation fails
     */
    public function validateEnvelope(string|object $data): void
    {
        $data = $this->parseData($data);
        $this->performValidation($data, self::ROOT_SCHEMAS['envelope']);
    }

    private function parseData(string|object $data): object
    {
        if (is_string($data)) {
            $data = json_decode($data);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new GoblValidationException('Invalid JSON: ' . json_last_error_msg());
            }
        }

        return $data;
    }

    private function performValidation(object $data, string $schemaUri): void
    {
        $result = $this->getValidator()->validate($data, $schemaUri);

        if (!$result->isValid()) {
            throw new GoblValidationException(
                'Validation failed against schema: ' . $schemaUri,
                $result->error(),
            );
        }
    }

    private function getValidator(): CompliantValidator
    {
        if ($this->validator !== null) {
            return $this->validator;
        }

        // Use max_errors = -1 and stop_at_first_error = false to collect all validation errors
        $this->validator = new CompliantValidator(null, -1, false);
        $schemasPath = $this->schemasPath;

        $this->validator->resolver()->registerProtocol('https', function (Uri $uri) use ($schemasPath) {
            $id = $uri->scheme() . '://' . $uri->host() . $uri->path();

            if (!str_starts_with($id, self::SCHEMA_PREFIX)) {
                return null;
            }

            $path = substr($id, strlen(self::SCHEMA_PREFIX));
            $file = $schemasPath . '/' . $path . '.json';

            if (!is_file($file)) {
                return null;
            }

            return json_decode(file_get_contents($file), false);
        });

        return $this->validator;
    }
}
