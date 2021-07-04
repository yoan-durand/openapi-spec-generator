<?php


namespace LaravelJsonApi\OpenApiSpec\Descriptors\Concerns;


use LaravelJsonApi\Contracts\Schema\Schema;
use LaravelJsonApi\OpenApiSpec\Actions\GenerateOpenAPISpec;

interface Descriptor
{
    public function describe(GenerateOpenAPISpec $generator, Schema $schema, mixed $entity): mixed;

    public static function canDescribe(mixed $entity): bool;
}
