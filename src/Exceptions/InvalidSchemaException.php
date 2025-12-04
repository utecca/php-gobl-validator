<?php

declare(strict_types=1);

namespace Ecourier\GoblValidator\Exceptions;

class InvalidSchemaException extends GoblValidationException
{
    public function __construct(string $schema)
    {
        parent::__construct("The schema '{$schema}' is not a supported GOBL root schema. Supported schemas: envelope, invoice, order.");
    }
}

