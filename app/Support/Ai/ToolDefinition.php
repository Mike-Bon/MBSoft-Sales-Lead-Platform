<?php

namespace App\Support\Ai;

/**
 * STEP 25: what one tool declares to the model — name, a precise
 * description of its allowed purpose (never a vague "manage CRM"), and
 * a JSON-schema-shaped parameter definition. This is metadata only; it
 * carries no authorization logic and no application data.
 */
final readonly class ToolDefinition
{
    /**
     * @param  array<string, mixed>  $parameters  A JSON Schema object
     *                                            (type/properties/required/etc.) describing the tool's arguments.
     */
    public function __construct(
        public string $name,
        public string $description,
        public array $parameters,
    ) {}
}
