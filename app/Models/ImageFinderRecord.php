<?php

namespace App\Models;

abstract class ImageFinderRecord
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function __construct(protected array $attributes) {}

    public function __get(string $key): mixed
    {
        return $this->attributes[$key] ?? null;
    }

    public function __set(string $key, mixed $value): void
    {
        $this->attributes[$key] = $value;
    }

    public function __isset(string $key): bool
    {
        return isset($this->attributes[$key]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->attributes;
    }

    public function update(array $values): void
    {
        $this->attributes = array_merge($this->attributes, $values);
    }

    public function fresh(): static
    {
        return new static($this->attributes);
    }
}
