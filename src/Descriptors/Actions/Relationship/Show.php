<?php


namespace LaravelJsonApi\OpenApiSpec\Descriptors\Actions\Relationship;


use LaravelJsonApi\Contracts\Schema\Schema;
use LaravelJsonApi\OpenApiSpec\Actions\GenerateOpenAPISpec;
use LaravelJsonApi\OpenApiSpec\Descriptors\Actions\ActionsDescriptor;
use LaravelJsonApi\OpenApiSpec\Descriptors\Actions\Route;

class Show extends ActionsDescriptor
{
    protected ?string $relation;

    /**
     */
    protected function describeRoute(
      GenerateOpenAPISpec $generator,
      Schema $schema,
      Route $route
    ): void {
        $this->relation = $route->relation;

        $relation = $schema->relationship($route->relation);

        $singular = $relation->toOne();

        $targetSchemaName = $relation->inverse();

    }

    protected function getSummary(): string
    {
        return "Show {$this->relation} relation";
    }

    protected static function describesRelation(): bool
    {
        return true;
    }

    protected static function describesAction(): string
    {
        return 'show';
    }

}
