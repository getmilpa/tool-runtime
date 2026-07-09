<?php

/**
 * This file is part of Milpa ToolRuntime — the AI tool-execution runtime of the Milpa PHP framework.
 *
 * (c) TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/tool-runtime
 */

declare(strict_types=1);

namespace Milpa\ToolRuntime;

use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use Milpa\Interfaces\Tooling\ToolRegistryInterface;
use Milpa\ValueObjects\Tooling\ToolOptions;
use Milpa\ToolRuntime\Attributes\Tool;
use Milpa\ToolRuntime\Attributes\Param;

/**
 * Scans classes for #[Tool] attributes and auto-registers them.
 *
 * @example
 * $scanner = new ToolScanner($toolRegistry);
 * $scanner->scan($noteToolsInstance);
 */
class ToolScanner
{
    private ToolRegistryInterface $registry;

    public function __construct(ToolRegistryInterface $registry)
    {
        $this->registry = $registry;
    }

    /**
     * Scan a tool service for #[Tool] attributed methods.
     *
     * @param object $service Instance of a tool service class
     *
     * @return int Number of tools registered
     */
    public function scan(object $service): int
    {
        $reflection = new ReflectionClass($service);
        $registered = 0;

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $toolAttrs = $method->getAttributes(Tool::class);

            if (empty($toolAttrs)) {
                continue;
            }

            $toolAttr = $toolAttrs[0]->newInstance();
            $schema = $this->buildSchema($method);
            $options = $this->buildOptions($toolAttr);

            $this->registry->register(
                $toolAttr->name,
                $toolAttr->description,
                $schema,
                fn ($args) => $this->invokeMethod($service, $method, $args),
                ToolOptions::fromArray($options)
            );

            $registered++;
        }

        return $registered;
    }

    /**
     * Build JSON schema from method parameters.
     *
     * @return array<string, mixed>
     */
    private function buildSchema(ReflectionMethod $method): array
    {
        $properties = [];
        $required = [];

        foreach ($method->getParameters() as $param) {
            $paramSchema = $this->buildParamSchema($param);
            $properties[$param->getName()] = $paramSchema;

            if (!$param->isOptional() && !$param->isDefaultValueAvailable()) {
                $required[] = $param->getName();
            }

            // Check for #[Param] required flag
            $paramAttrs = $param->getAttributes(Param::class);
            if (!empty($paramAttrs)) {
                $paramAttr = $paramAttrs[0]->newInstance();
                if ($paramAttr->required) {
                    $required[] = $param->getName();
                }
            }
        }

        $schema = [
            'type' => 'object',
            'properties' => $properties,
        ];

        if (!empty($required)) {
            $schema['required'] = array_values(array_unique($required));
        }

        return $schema;
    }

    /**
     * Build schema for a single parameter.
     *
     * The JSON-Schema `type` is inferred from the PHP parameter's native type
     * (`int`/`float`/`bool`/`array`/`string`); a `#[Param(type: ...)]` override (tool-runtime
     * 0.6) can replace that inferred type — e.g. a PHP `array $param` declared with
     * `type: 'object'` produces `type: object` instead of `type: array`, so
     * {@see SchemaValidator} validates it as an associative payload rather than requiring a list.
     *
     * @return array<string, mixed>
     */
    private function buildParamSchema(ReflectionParameter $param): array
    {
        $schema = [];
        $type = $param->getType();

        // Determine type
        if ($type instanceof ReflectionNamedType) {
            $typeName = $type->getName();
            $schema['type'] = match ($typeName) {
                'int' => 'integer',
                'float' => 'number',
                'bool' => 'boolean',
                'array' => 'array',
                'string' => 'string',
                default => 'string',
            };

            if ($type->allowsNull()) {
                $schema['nullable'] = true;
            }
        } else {
            // Union/intersection types or no type - default to string
            $schema['type'] = 'string';
        }

        // Check for #[Param] attribute
        $paramAttrs = $param->getAttributes(Param::class);
        if (!empty($paramAttrs)) {
            $paramAttr = $paramAttrs[0]->newInstance();

            if ($paramAttr->description) {
                $schema['description'] = $paramAttr->description;
            }

            if ($paramAttr->enum !== null) {
                $schema['enum'] = $paramAttr->enum;
            }

            if ($paramAttr->clamp !== null && count($paramAttr->clamp) === 2) {
                $schema['minimum'] = $paramAttr->clamp[0];
                $schema['maximum'] = $paramAttr->clamp[1];
            }

            // Explicit `type` override (tool-runtime 0.6) — lets a PHP `array $param` declare
            // itself as JSON-Schema `type: object` instead of the inferred `type: array`. Every
            // other PHP-type-derived schema above is untouched unless the tool author opts in.
            if ($paramAttr->type !== null) {
                $schema['type'] = $paramAttr->type;
            }

            // Nested object shape — only added when the tool author actually declared it, so a
            // bare `type: 'object'` param (no declared shape) never gets an empty `properties: []`
            // that would need the wire-safety stdClass normalization {@see
            // ToolRegistry::jsonSafeSchema()} applies to the top-level schema.
            if ($paramAttr->properties !== null) {
                $schema['properties'] = $paramAttr->properties;
            }

            if ($paramAttr->requiredProperties !== null) {
                $schema['required'] = $paramAttr->requiredProperties;
            }
        }

        // Add default if available
        if ($param->isDefaultValueAvailable()) {
            $schema['default'] = $param->getDefaultValue();
        }

        return $schema;
    }

    /**
     * Build options from Tool attribute.
     *
     * @return array<string, mixed>
     */
    private function buildOptions(Tool $tool): array
    {
        $options = [];

        if (!empty($tool->scopes)) {
            $options['scopes'] = $tool->scopes;
        }

        if (!empty($tool->clamps)) {
            $options['clamps'] = $tool->clamps;
        }

        if ($tool->confirm) {
            $options['requiresConfirmation'] = true;
        }

        // NOTE: $tool->category is intentionally NOT forwarded here — ToolDefinition has no
        // `category` property, so it has never been stored (see ToolScannerTest::
        // testToolWithCategoryIsScanned). ToolOptions::fromArray() rejects unknown keys, so
        // forwarding it here would now throw instead of silently doing nothing.

        if ($tool->version !== null) {
            $options['version'] = $tool->version;
        }

        if ($tool->outputSchema !== null) {
            $options['outputSchema'] = $tool->outputSchema;
        }

        return $options;
    }

    /**
     * Invoke method with arguments.
     *
     * @param array<string, mixed> $args
     */
    private function invokeMethod(object $service, ReflectionMethod $method, array $args): mixed
    {
        // Inject ToolContext into service if it supports it
        if (isset($args['_ctx']) && method_exists($service, 'setCurrentContext')) {
            $service->setCurrentContext($args['_ctx']);
        }

        $params = [];

        foreach ($method->getParameters() as $param) {
            $name = $param->getName();

            if (array_key_exists($name, $args)) {
                $params[] = $args[$name];
            } elseif ($param->isDefaultValueAvailable()) {
                $params[] = $param->getDefaultValue();
            } else {
                $params[] = null;
            }
        }

        return $method->invokeArgs($service, $params);
    }
}
