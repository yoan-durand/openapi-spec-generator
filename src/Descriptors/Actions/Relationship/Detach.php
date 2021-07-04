<?php


namespace LaravelJsonApi\OpenApiSpec\Descriptors\Actions\Relationship;


use LaravelJsonApi\Contracts\Schema\Schema;
use LaravelJsonApi\OpenApiSpec\Actions\GenerateOpenAPISpec;
use LaravelJsonApi\OpenApiSpec\Descriptors\Actions\ActionsDescriptor;
use LaravelJsonApi\OpenApiSpec\Descriptors\Actions\Route;

class Detach extends ActionsDescriptor
{
    protected ?string $relation;

    protected function describeRoute(
      GenerateOpenAPISpec $generator,
      Schema $schema,
      Route $route
    ): void {
        $this->relation = $route->relation;
    }

    protected function getSummary(): string
    {
        return "Attach {$this->relation}";
    }

    protected static function describesRelation(): bool
    {
        return  true;
    }

    protected static function describesAction(): string
    {
        return 'detach';
    }
}
