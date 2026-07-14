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

namespace Milpa\ToolRuntime;

use Milpa\ToolRuntime\Validation\ValidationResult;

/**
 * JSON Schema validator for tool arguments.
 *
 * Validates arguments against the tool's input schema before execution.
 */
class SchemaValidator
{
    /**
     * Validate arguments against a JSON schema.
     *
     * @param array<string, mixed> $args   The arguments to validate
     * @param array<string, mixed> $schema The JSON schema
     *
     * @return ValidationResult
     */
    public function validate(array $args, array $schema): ValidationResult
    {
        $errors = [];

        // Normalize empty schema
        $schema = $this->normalizeSchema($schema);

        // Validate required properties
        if (isset($schema['required'])) {
            foreach ($schema['required'] as $required) {
                if (!array_key_exists($required, $args)) {
                    $errors[] = "Missing required field: {$required}";
                }
            }
        }

        // Validate properties
        if (isset($schema['properties'])) {
            foreach ($schema['properties'] as $propName => $propSchema) {
                if (array_key_exists($propName, $args)) {
                    $propErrors = $this->validateProperty($args[$propName], $propSchema, $propName);
                    $errors = array_merge($errors, $propErrors);
                }
            }
        }

        // Check for additional properties
        if (isset($schema['additionalProperties']) && $schema['additionalProperties'] === false) {
            $allowedProps = array_keys($schema['properties'] ?? []);
            foreach ($args as $key => $value) {
                if (!in_array($key, $allowedProps, true)) {
                    $errors[] = "Unknown property: {$key}";
                }
            }
        }

        if (empty($errors)) {
            return ValidationResult::success();
        }

        return ValidationResult::failure($errors);
    }

    /**
     * Validate and apply clamps to arguments.
     *
     * @param array<string, mixed>                $args   Arguments to clamp
     * @param array<string, array<string, mixed>> $clamps Clamp definitions ['field' => ['min' => x, 'max' => y]]
     *
     * @return array<string, mixed> Clamped arguments
     */
    public function applyClamps(array $args, array $clamps): array
    {
        foreach ($clamps as $field => $rules) {
            if (!isset($args[$field])) {
                continue;
            }

            $value = $args[$field];

            if (isset($rules['min']) && $value < $rules['min']) {
                $args[$field] = $rules['min'];
            }

            if (isset($rules['max']) && $value > $rules['max']) {
                $args[$field] = $rules['max'];
            }

            if (isset($rules['maxLength']) && is_string($value)) {
                $args[$field] = mb_substr($value, 0, $rules['maxLength']);
            }
        }

        return $args;
    }

    /**
     * Normalize an empty or minimal schema to a valid object schema.
     *
     * @param array<string, mixed> $schema
     *
     * @return array<string, mixed>
     */
    private function normalizeSchema(array $schema): array
    {
        if (empty($schema)) {
            return [
                'type' => 'object',
                'properties' => [],
                'additionalProperties' => false,
            ];
        }

        if (!isset($schema['type'])) {
            $schema['type'] = 'object';
        }

        return $schema;
    }

    /**
     * Validate a single property against its schema.
     *
     * @param array<string, mixed> $schema
     *
     * @return list<string>
     */
    private function validateProperty(mixed $value, array $schema, string $path): array
    {
        $errors = [];

        // Type validation
        if (isset($schema['type'])) {
            $valid = match ($schema['type']) {
                'string' => is_string($value),
                'integer' => is_int($value),
                'number' => is_numeric($value),
                'boolean' => is_bool($value),
                'array' => is_array($value) && array_is_list($value),
                // An associative PHP array (`{"post_id": 1}` decoded with `assoc: true`) OR a
                // `stdClass`/object instance both count as a JSON object. `array_is_list()`
                // considers an EMPTY array a list, so it is special-cased here too — an empty
                // object (`{}`) must validate, not be rejected as "not an object" (tool-runtime
                // 0.6; see SchemaValidatorTest::testValidateObjectTypeAcceptsEmptyPayload()).
                'object' => is_object($value) || (is_array($value) && ($value === [] || !array_is_list($value))),
                default => true,
            };

            if (!$valid) {
                $errors[] = "{$path}: Expected {$schema['type']}, got " . gettype($value);
            }
        }

        // Enum validation
        if (isset($schema['enum']) && !in_array($value, $schema['enum'], true)) {
            $allowed = implode(', ', $schema['enum']);
            $errors[] = "{$path}: Value must be one of: {$allowed}";
        }

        // String length validation
        if (is_string($value)) {
            if (isset($schema['minLength']) && mb_strlen($value) < $schema['minLength']) {
                $errors[] = "{$path}: String too short (min: {$schema['minLength']})";
            }
            if (isset($schema['maxLength']) && mb_strlen($value) > $schema['maxLength']) {
                $errors[] = "{$path}: String too long (max: {$schema['maxLength']})";
            }
        }

        // Number range validation
        if (is_numeric($value)) {
            if (isset($schema['minimum']) && $value < $schema['minimum']) {
                $errors[] = "{$path}: Value too small (min: {$schema['minimum']})";
            }
            if (isset($schema['maximum']) && $value > $schema['maximum']) {
                $errors[] = "{$path}: Value too large (max: {$schema['maximum']})";
            }
        }

        return $errors;
    }
}
