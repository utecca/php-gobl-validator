# PHP GOBL Validator

A PHP library for validating JSON against [GOBL](https://gobl.org) schemas without requiring the GOBL CLI.

## Installation

```bash
composer require ecourier/gobl-validator
```

## Usage

The validator accepts both JSON strings and objects.

### Basic Validation

The `validate()` method auto-detects the schema from the `$schema` property in your data:

```php
use Ecourier\GoblValidator\GoblValidator;

$validator = new GoblValidator();

// Validate a JSON string
$invoiceJson = '{"$schema": "https://gobl.org/draft-0/bill/invoice", ...}';
$validator->validate($invoiceJson);

// Validate an object
$invoiceObject = json_decode($invoiceJson);
$validator->validate($invoiceObject);
```

### Supported Root Schemas

The validator supports these root document types:

- `envelope` - `https://gobl.org/draft-0/envelope`
- `invoice` - `https://gobl.org/draft-0/bill/invoice`
- `order` - `https://gobl.org/draft-0/bill/order`

### Validate Envelope

Use `validateEnvelope()` to explicitly validate against the envelope schema:

```php
$validator->validateEnvelope($envelopeJson);
```

## Handling Validation Errors

When validation fails, a `GoblValidationException` is thrown containing the validation error details.

### Using getFormattedErrors() (Recommended)

The `getFormattedErrors()` method provides clean, user-friendly error messages:

```php
use Ecourier\GoblValidator\GoblValidator;
use Ecourier\GoblValidator\Exceptions\GoblValidationException;
use Ecourier\GoblValidator\Exceptions\InvalidSchemaException;

$validator = new GoblValidator();

try {
    $validator->validate($data);
} catch (InvalidSchemaException $e) {
    // The $schema property is not a supported root schema
    echo $e->getMessage();
} catch (GoblValidationException $e) {
    // Get formatted errors as an associative array (path => messages)
    $errors = $e->getFormattedErrors();
    print_r($errors);
    // Example output:
    // [
    //     '/currency' => ['The value "INVALID" is not a valid option'],
    //     '/supplier/tax_id/country' => ['The value "XX" is not a valid option'],
    //     '/customer/tax_id/country' => ['The value "YY" is not a valid option'],
    // ]
}
```

**Benefits of `getFormattedErrors()`:**

- **Returns all errors** - Validates the entire document and returns all validation failures, not just the first one
- **Consolidates enum errors** - Country codes, currency codes, and similar enum-like schemas return a single meaningful error instead of hundreds of "const mismatch" messages
- **Filters false positives** - Removes misleading "Unknown properties" errors that can appear at ancestor paths when nested validation fails
- **Clean paths** - Uses JSON Pointer-style paths like `/customer/tax_id/country`

### Using Opis ErrorFormatter (Advanced)

For more control over error formatting, you can use the raw `validationError` property with Opis's `ErrorFormatter`:

```php
use Opis\JsonSchema\Errors\ErrorFormatter;

try {
    $validator->validate($data);
} catch (GoblValidationException $e) {
    if ($e->validationError) {
        $formatter = new ErrorFormatter();
        
        // Get errors as an associative array (path => errors)
        $errors = $formatter->format($e->validationError);
        
        // Or get a flat list of error messages
        $flatErrors = $formatter->formatFlat($e->validationError);
        
        // Get detailed error information
        $detailedErrors = $formatter->formatOutput($e->validationError, 'detailed');
    }
}
```

> **Note:** The raw `ErrorFormatter` will show all sub-errors including hundreds of individual "const mismatch" errors for enum-like fields. Use `getFormattedErrors()` for cleaner output.

## Limitations

This library performs **JSON Schema validation only**. It does not replace the GOBL CLI or API for full document validation.

### What this library validates:
- Required fields (e.g., `type`, `issue_date`, `currency`, `supplier`, `totals`)
- Data types (strings, numbers, arrays, objects)
- Field formats (UUIDs, dates, country codes, currency codes)
- Enum values (invoice types, tax categories)
- Unknown properties (typos like `customer2` instead of `customer`)

### What this library does NOT validate:
- Business rules (e.g., customer required for standard invoices)
- Tax calculations (e.g., totals matching line sums)
- Regime-specific requirements (e.g., Spanish TicketBAI, French Factur-X)
- Addon-specific validation rules
- Cross-field validation logic

For complete validation including business rules, use the [GOBL CLI](https://docs.gobl.org/quick-start/cli) or the GOBL API.

This library is ideal for:
- Quick structural validation in PHP applications
- Unit testing generated GOBL documents
- Catching common errors before sending to GOBL

## GOBL Version

The bundled schemas are derived from GOBL version:

```php
echo GoblValidator::$GOBL_VERSION; // e.g., "0.303.0"
```

## License

This library is licensed under the MIT License.

The bundled GOBL schemas (in the `schemas/` directory) are © [Invopop Ltd](https://invopop.com) and licensed under the [Apache License 2.0](https://www.apache.org/licenses/LICENSE-2.0). See the [GOBL repository](https://github.com/invopop/gobl) for more information.
