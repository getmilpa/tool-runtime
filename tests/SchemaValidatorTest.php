<?php

/**
 * This file is part of Milpa ToolRuntime — the AI tool-execution runtime of the Milpa PHP framework.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/tool-runtime
 */

declare(strict_types=1);

namespace Milpa\ToolRuntime\Tests;

use PHPUnit\Framework\TestCase;
use Milpa\ToolRuntime\SchemaValidator;
use Milpa\ToolRuntime\Validation\ValidationResult;

class SchemaValidatorTest extends TestCase
{
    private SchemaValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new SchemaValidator();
    }

    public function testValidateEmptySchemaRejectsUnknownProperties(): void
    {
        // Empty schema gets normalized to additionalProperties: false
        $result = $this->validator->validate(['foo' => 'bar'], []);
        // With additionalProperties: false, unknown properties are rejected
        $this->assertFalse($result->valid);
        $this->assertStringContainsString('Unknown property', $result->getErrorMessage());
    }

    public function testValidateEmptySchemaAcceptsEmptyObject(): void
    {
        $result = $this->validator->validate([], []);
        $this->assertTrue($result->valid);
    }

    public function testValidateRequiredFieldMissing(): void
    {
        $schema = [
            'type' => 'object',
            'required' => ['name', 'email'],
            'properties' => [
                'name' => ['type' => 'string'],
                'email' => ['type' => 'string'],
            ],
        ];

        $result = $this->validator->validate(['name' => 'John'], $schema);

        $this->assertFalse($result->valid);
        $this->assertStringContainsString('email', $result->getErrorMessage());
    }

    public function testValidateRequiredFieldsPresent(): void
    {
        $schema = [
            'type' => 'object',
            'required' => ['name'],
            'properties' => [
                'name' => ['type' => 'string'],
            ],
        ];

        $result = $this->validator->validate(['name' => 'John'], $schema);

        $this->assertTrue($result->valid);
    }

    public function testValidateStringType(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string'],
            ],
        ];

        $validResult = $this->validator->validate(['name' => 'John'], $schema);
        $this->assertTrue($validResult->valid);

        $invalidResult = $this->validator->validate(['name' => 123], $schema);
        $this->assertFalse($invalidResult->valid);
        $this->assertStringContainsString('Expected string', $invalidResult->getErrorMessage());
    }

    public function testValidateIntegerType(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'age' => ['type' => 'integer'],
            ],
        ];

        $validResult = $this->validator->validate(['age' => 25], $schema);
        $this->assertTrue($validResult->valid);

        $invalidResult = $this->validator->validate(['age' => 'twenty-five'], $schema);
        $this->assertFalse($invalidResult->valid);
    }

    public function testValidateNumberType(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'price' => ['type' => 'number'],
            ],
        ];

        $resultInt = $this->validator->validate(['price' => 100], $schema);
        $this->assertTrue($resultInt->valid);

        $resultFloat = $this->validator->validate(['price' => 99.99], $schema);
        $this->assertTrue($resultFloat->valid);

        $resultString = $this->validator->validate(['price' => '99.99'], $schema);
        $this->assertTrue($resultString->valid); // is_numeric accepts numeric strings
    }

    public function testValidateBooleanType(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'active' => ['type' => 'boolean'],
            ],
        ];

        $validResult = $this->validator->validate(['active' => true], $schema);
        $this->assertTrue($validResult->valid);

        $invalidResult = $this->validator->validate(['active' => 'yes'], $schema);
        $this->assertFalse($invalidResult->valid);
    }

    public function testValidateEnumValue(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'status' => [
                    'type' => 'string',
                    'enum' => ['active', 'inactive', 'pending'],
                ],
            ],
        ];

        $validResult = $this->validator->validate(['status' => 'active'], $schema);
        $this->assertTrue($validResult->valid);

        $invalidResult = $this->validator->validate(['status' => 'unknown'], $schema);
        $this->assertFalse($invalidResult->valid);
        $this->assertStringContainsString('must be one of', $invalidResult->getErrorMessage());
    }

    public function testValidateStringMinLength(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'password' => [
                    'type' => 'string',
                    'minLength' => 8,
                ],
            ],
        ];

        $validResult = $this->validator->validate(['password' => 'secretpassword'], $schema);
        $this->assertTrue($validResult->valid);

        $invalidResult = $this->validator->validate(['password' => 'short'], $schema);
        $this->assertFalse($invalidResult->valid);
        $this->assertStringContainsString('too short', $invalidResult->getErrorMessage());
    }

    public function testValidateStringMaxLength(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'username' => [
                    'type' => 'string',
                    'maxLength' => 20,
                ],
            ],
        ];

        $validResult = $this->validator->validate(['username' => 'john'], $schema);
        $this->assertTrue($validResult->valid);

        $invalidResult = $this->validator->validate(['username' => 'this_is_a_very_long_username_that_exceeds_limit'], $schema);
        $this->assertFalse($invalidResult->valid);
        $this->assertStringContainsString('too long', $invalidResult->getErrorMessage());
    }

    public function testValidateNumberMinimum(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'age' => [
                    'type' => 'integer',
                    'minimum' => 18,
                ],
            ],
        ];

        $validResult = $this->validator->validate(['age' => 25], $schema);
        $this->assertTrue($validResult->valid);

        $invalidResult = $this->validator->validate(['age' => 15], $schema);
        $this->assertFalse($invalidResult->valid);
        $this->assertStringContainsString('too small', $invalidResult->getErrorMessage());
    }

    public function testValidateNumberMaximum(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'quantity' => [
                    'type' => 'integer',
                    'maximum' => 100,
                ],
            ],
        ];

        $validResult = $this->validator->validate(['quantity' => 50], $schema);
        $this->assertTrue($validResult->valid);

        $invalidResult = $this->validator->validate(['quantity' => 150], $schema);
        $this->assertFalse($invalidResult->valid);
        $this->assertStringContainsString('too large', $invalidResult->getErrorMessage());
    }

    public function testValidateAdditionalPropertiesFalse(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string'],
            ],
            'additionalProperties' => false,
        ];

        $validResult = $this->validator->validate(['name' => 'John'], $schema);
        $this->assertTrue($validResult->valid);

        $invalidResult = $this->validator->validate(['name' => 'John', 'extra' => 'field'], $schema);
        $this->assertFalse($invalidResult->valid);
        $this->assertStringContainsString('Unknown property', $invalidResult->getErrorMessage());
    }

    public function testApplyClampsMinValue(): void
    {
        $args = ['page' => -5];
        $clamps = ['page' => ['min' => 1, 'max' => 100]];

        $result = $this->validator->applyClamps($args, $clamps);

        $this->assertEquals(1, $result['page']);
    }

    public function testApplyClampsMaxValue(): void
    {
        $args = ['page' => 500];
        $clamps = ['page' => ['min' => 1, 'max' => 100]];

        $result = $this->validator->applyClamps($args, $clamps);

        $this->assertEquals(100, $result['page']);
    }

    public function testApplyClampsValueInRange(): void
    {
        $args = ['page' => 50];
        $clamps = ['page' => ['min' => 1, 'max' => 100]];

        $result = $this->validator->applyClamps($args, $clamps);

        $this->assertEquals(50, $result['page']);
    }

    public function testApplyClampsMaxLength(): void
    {
        $args = ['message' => 'This is a very long message that should be truncated'];
        $clamps = ['message' => ['maxLength' => 20]];

        $result = $this->validator->applyClamps($args, $clamps);

        $this->assertEquals('This is a very long ', $result['message']);
        $this->assertEquals(20, mb_strlen($result['message']));
    }

    public function testApplyClampsMissingField(): void
    {
        $args = ['other' => 'value'];
        $clamps = ['page' => ['min' => 1, 'max' => 100]];

        $result = $this->validator->applyClamps($args, $clamps);

        $this->assertEquals(['other' => 'value'], $result);
    }

    public function testValidateArrayType(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'tags' => ['type' => 'array'],
            ],
        ];

        $validResult = $this->validator->validate(['tags' => ['php', 'testing']], $schema);
        $this->assertTrue($validResult->valid);

        // Associative array should fail array validation (it's an object)
        $invalidResult = $this->validator->validate(['tags' => ['key' => 'value']], $schema);
        $this->assertFalse($invalidResult->valid);
    }

    // ========== Object-param gap fix (tool-runtime 0.6) ==========
    // See orch-f4-report.md finding #1: a `type: object` property must accept an associative
    // payload — e.g. `{"post_id": 1}` decoded to `['post_id' => 1]` — WITHOUT running
    // `array_is_list()` on it (that check stays reserved for `type: array`, below).

    public function testValidateObjectTypeAcceptsAssociativeArray(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'updates' => ['type' => 'object'],
            ],
        ];

        $result = $this->validator->validate(['updates' => ['post_id' => 1, 'title' => 'New']], $schema);

        $this->assertTrue($result->valid);
    }

    public function testValidateObjectTypeRejectsListArray(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'updates' => ['type' => 'object'],
            ],
        ];

        $result = $this->validator->validate(['updates' => ['a', 'b', 'c']], $schema);

        $this->assertFalse($result->valid);
        $this->assertStringContainsString('Expected object', $result->getErrorMessage());
    }

    public function testValidateObjectTypeAcceptsEmptyPayload(): void
    {
        // `{}` decodes to `[]` in PHP — `array_is_list([])` is `true`, so a naive
        // `!array_is_list()` check would wrongly reject an empty object. Must validate.
        $schema = [
            'type' => 'object',
            'properties' => [
                'updates' => ['type' => 'object'],
            ],
        ];

        $result = $this->validator->validate(['updates' => []], $schema);

        $this->assertTrue($result->valid);
    }

    public function testValidateObjectTypeAcceptsStdClassInstance(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'updates' => ['type' => 'object'],
            ],
        ];

        $result = $this->validator->validate(['updates' => new \stdClass()], $schema);

        $this->assertTrue($result->valid);
    }

    public function testValidateObjectTypeRejectsScalar(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'updates' => ['type' => 'object'],
            ],
        ];

        $result = $this->validator->validate(['updates' => 'not-an-object'], $schema);

        $this->assertFalse($result->valid);
        $this->assertStringContainsString('Expected object', $result->getErrorMessage());
    }

    public function testValidateArrayTypeStillRejectsAssociativeArrayNoRegression(): void
    {
        // Regression guard: 'array' must keep meaning "JSON list" exactly as before — the new
        // 'object' branch above must not loosen this check.
        $schema = [
            'type' => 'object',
            'properties' => [
                'items' => ['type' => 'array'],
            ],
        ];

        $validList = $this->validator->validate(['items' => [1, 2, 3]], $schema);
        $this->assertTrue($validList->valid);

        $invalidAssoc = $this->validator->validate(['items' => ['post_id' => 1]], $schema);
        $this->assertFalse($invalidAssoc->valid);
        $this->assertStringContainsString('Expected array', $invalidAssoc->getErrorMessage());
    }

    public function testValidationResultErrorMessage(): void
    {
        $result = ValidationResult::failure(['Error 1', 'Error 2', 'Error 3']);

        $this->assertFalse($result->valid);
        $this->assertCount(3, $result->errors);
        $this->assertEquals('Error 1; Error 2; Error 3', $result->getErrorMessage());
    }

    public function testValidationResultSuccess(): void
    {
        $result = ValidationResult::success();

        $this->assertTrue($result->valid);
        $this->assertEmpty($result->errors);
        $this->assertEquals('', $result->getErrorMessage());
    }
}
