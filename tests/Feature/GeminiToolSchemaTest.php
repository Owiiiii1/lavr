<?php

namespace Tests\Feature;

use App\Services\Tools\ToolRegistry;
use ReflectionProperty;
use Tests\TestCase;

class GeminiToolSchemaTest extends TestCase
{
    public function test_registered_tool_arrays_declare_item_schemas(): void
    {
        $registry = app(ToolRegistry::class);
        $property = new ReflectionProperty($registry, 'tools');
        $missing = [];

        foreach ($property->getValue($registry) as $tool) {
            $this->collectArraysWithoutItems($tool->definition()->parameters, $tool->name(), $missing);
        }

        $this->assertSame([], $missing);
    }

    /**
     * @param  array<string, mixed>  $schema
     * @param  list<string>  $missing
     */
    private function collectArraysWithoutItems(array $schema, string $path, array &$missing): void
    {
        if (($schema['type'] ?? null) === 'ARRAY' && ! isset($schema['items'])) {
            $missing[] = $path;
        }

        $items = $schema['items'] ?? null;
        if (is_array($items)) {
            $this->collectArraysWithoutItems($items, $path.'.items', $missing);
        }

        $properties = $schema['properties'] ?? null;
        if (! is_array($properties)) {
            return;
        }

        foreach ($properties as $name => $property) {
            if (is_array($property)) {
                $this->collectArraysWithoutItems($property, $path.'.'.$name, $missing);
            }
        }
    }
}
