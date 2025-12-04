<?php

declare(strict_types=1);

use Ecourier\GoblValidator\GoblValidator;
use Ecourier\GoblValidator\Exceptions\GoblValidationException;
use Ecourier\GoblValidator\Exceptions\InvalidSchemaException;

function stub(string $name): string
{
    return file_get_contents(__DIR__ . '/../stubs/' . $name . '.json');
}

describe('validate()', function () {
    it('validates a valid invoice', function () {
        $validator = new GoblValidator();
        
        $validator->validate(stub('valid-invoice'));
        
        expect(true)->toBeTrue(); // No exception thrown
    });

    it('validates a valid envelope', function () {
        $validator = new GoblValidator();
        
        $validator->validate(stub('valid-envelope'));
        
        expect(true)->toBeTrue();
    });

    it('throws when invoice is missing required totals', function () {
        $validator = new GoblValidator();
        
        $validator->validate(stub('invalid-invoice-missing-totals'));
    })->throws(GoblValidationException::class);

    it('throws when invoice has invalid currency', function () {
        $validator = new GoblValidator();
        
        $validator->validate(stub('invalid-invoice-bad-currency'));
    })->throws(GoblValidationException::class);

    it('throws when invoice has unknown property', function () {
        $validator = new GoblValidator();
        
        try {
            $validator->validate(stub('invalid-invoice-unknown-property'));
        } catch (GoblValidationException $e) {
            expect($e->validationError)->not->toBeNull();
            
            // Find the additionalProperties error in subErrors
            $subErrors = $e->validationError->subErrors();
            expect($subErrors)->not->toBeEmpty();
            
            $additionalPropsError = $subErrors[0];
            expect($additionalPropsError->keyword())->toBe('additionalProperties');
            expect($additionalPropsError->args()['properties'])->toContain('customer2');
            return;
        }
        
        $this->fail('Expected GoblValidationException to be thrown');
    });

    it('throws when $schema property is missing', function () {
        $validator = new GoblValidator();
        
        $validator->validate('{"type": "standard"}');
    })->throws(GoblValidationException::class, 'does not contain a $schema property');

    it('throws when $schema is not a supported root schema', function () {
        $validator = new GoblValidator();
        
        $validator->validate('{"$schema": "https://gobl.org/draft-0/org/party", "name": "Test"}');
    })->throws(InvalidSchemaException::class, 'not a supported GOBL root schema');

    it('throws for invalid JSON', function () {
        $validator = new GoblValidator();
        
        $validator->validate('not valid json');
    })->throws(GoblValidationException::class, 'Invalid JSON');

    it('provides validation error details on failure', function () {
        $validator = new GoblValidator();
        
        try {
            $validator->validate(stub('invalid-invoice-missing-totals'));
        } catch (GoblValidationException $e) {
            expect($e->validationError)->not->toBeNull();
            return;
        }
        
        $this->fail('Expected GoblValidationException to be thrown');
    });
});

describe('validateEnvelope()', function () {
    it('validates a valid envelope', function () {
        $validator = new GoblValidator();
        
        $validator->validateEnvelope(stub('valid-envelope'));
        
        expect(true)->toBeTrue();
    });

    it('throws when envelope is missing head', function () {
        $validator = new GoblValidator();
        
        $validator->validateEnvelope(stub('invalid-envelope-missing-head'));
    })->throws(GoblValidationException::class);

    it('throws when envelope is missing doc', function () {
        $validator = new GoblValidator();
        
        $validator->validateEnvelope(stub('invalid-envelope-missing-doc'));
    })->throws(GoblValidationException::class);

    it('throws when validating an invoice as envelope', function () {
        $validator = new GoblValidator();
        
        $validator->validateEnvelope(stub('valid-invoice'));
    })->throws(GoblValidationException::class);
});

describe('ROOT_SCHEMAS', function () {
    it('contains envelope, invoice, and order', function () {
        expect(GoblValidator::ROOT_SCHEMAS)->toHaveKeys(['envelope', 'invoice', 'order']);
    });
});

describe('$GOBL_VERSION', function () {
    it('returns a version string', function () {
        expect(GoblValidator::$GOBL_VERSION)->toBeString();
        expect(GoblValidator::$GOBL_VERSION)->toMatch('/^\d+\.\d+\.\d+$/');
    });
});

describe('getFormattedErrors()', function () {
    it('consolidates enum-like oneOf errors for invalid country codes', function () {
        $validator = new GoblValidator();
        
        try {
            $validator->validate(stub('invalid-invoice-bad-country'));
        } catch (GoblValidationException $e) {
            $errors = $e->getFormattedErrors();
            
            // Should have exactly one error for the invalid country
            expect($errors)->toHaveKey('/customer/tax_id/country');
            
            // Should be a single consolidated error, not 250+ individual const errors
            expect($errors['/customer/tax_id/country'])->toHaveCount(1);
            
            // Should contain a meaningful error message with the invalid value
            expect($errors['/customer/tax_id/country'][0])->toContain('DKs');
            expect($errors['/customer/tax_id/country'][0])->toContain('not a valid option');
            return;
        }
        
        $this->fail('Expected GoblValidationException to be thrown');
    });

    it('provides helpful messages for missing required fields', function () {
        $validator = new GoblValidator();
        
        try {
            $validator->validate(stub('invalid-invoice-missing-totals'));
        } catch (GoblValidationException $e) {
            $errors = $e->getFormattedErrors();
            
            // Should have an error at the root level for missing totals
            expect($errors)->not->toBeEmpty();
            
            // Find any error about missing 'totals'
            $foundTotalsError = false;
            foreach ($errors as $path => $messages) {
                foreach ($messages as $message) {
                    if (str_contains($message, 'totals')) {
                        $foundTotalsError = true;
                        break 2;
                    }
                }
            }
            
            expect($foundTotalsError)->toBeTrue();
            return;
        }
        
        $this->fail('Expected GoblValidationException to be thrown');
    });

    it('provides helpful messages for invalid currency', function () {
        $validator = new GoblValidator();
        
        try {
            $validator->validate(stub('invalid-invoice-bad-currency'));
        } catch (GoblValidationException $e) {
            $errors = $e->getFormattedErrors();
            
            expect($errors)->not->toBeEmpty();
            
            // Should have exactly one error for currency, not multiple const mismatches
            expect($errors)->toHaveKey('/currency');
            expect($errors['/currency'])->toHaveCount(1);
            return;
        }
        
        $this->fail('Expected GoblValidationException to be thrown');
    });

    it('returns empty array when no validation error', function () {
        $exception = new GoblValidationException('Test error');
        
        expect($exception->getFormattedErrors())->toBe([]);
    });

    it('returns all unique errors when multiple fields are invalid', function () {
        $validator = new GoblValidator();
        
        // Invoice with multiple errors: bad currency AND bad country codes
        $data = json_encode([
            '$schema' => 'https://gobl.org/draft-0/bill/invoice',
            'uuid' => '3aea7b56-59d8-4beb-90bd-f8f280d852a0',
            'type' => 'standard',
            'code' => '1234',
            'issue_date' => '2025-04-25',
            'currency' => 'INVALID',
            'supplier' => [
                'name' => 'Example Supplier AB',
                'tax_id' => ['country' => 'XX', 'code' => '16356706']
            ],
            'customer' => [
                'name' => 'Example Company AB',
                'tax_id' => ['country' => 'YY', 'code' => '41412003']
            ],
            'lines' => [[
                'i' => 1,
                'quantity' => '1',
                'item' => ['name' => 'Test', 'price' => '1000.00'],
                'sum' => '1000.00',
                'taxes' => [['cat' => 'VAT', 'percent' => '25.0%']],
                'total' => '1000.00'
            ]],
            'totals' => [
                'sum' => '1000.00',
                'total' => '1000.00',
                'taxes' => [
                    'categories' => [[
                        'code' => 'VAT',
                        'rates' => [['base' => '1000.00', 'percent' => '25.0%', 'amount' => '250.00']],
                        'amount' => '250.00'
                    ]],
                    'sum' => '250.00'
                ],
                'tax' => '250.00',
                'total_with_tax' => '1250.00',
                'payable' => '1250.00'
            ]
        ]);
        
        try {
            $validator->validate($data);
        } catch (GoblValidationException $e) {
            $errors = $e->getFormattedErrors();
            
            // Should have errors for all three invalid fields
            expect($errors)->toHaveKey('/currency');
            expect($errors)->toHaveKey('/supplier/tax_id/country');
            expect($errors)->toHaveKey('/customer/tax_id/country');
            
            // Each should have a single consolidated error
            expect($errors['/currency'])->toHaveCount(1);
            expect($errors['/supplier/tax_id/country'])->toHaveCount(1);
            expect($errors['/customer/tax_id/country'])->toHaveCount(1);
            
            // No false-positive additionalProperties errors at parent paths
            expect($errors)->not->toHaveKey('/');
            expect($errors)->not->toHaveKey('/supplier');
            expect($errors)->not->toHaveKey('/customer');
            expect($errors)->not->toHaveKey('/supplier/tax_id');
            expect($errors)->not->toHaveKey('/customer/tax_id');
            return;
        }
        
        $this->fail('Expected GoblValidationException to be thrown');
    });
});

