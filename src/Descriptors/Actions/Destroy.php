<?php


namespace LaravelJsonApi\OpenApiSpec\Descriptors\Actions;


use LaravelJsonApi\Contracts\Schema\Schema;
use LaravelJsonApi\Core\Support\Str;
use LaravelJsonApi\OpenApiSpec\Actions\GenerateOpenAPISpec;

class Destroy extends ActionsDescriptor
{

    protected function describeRoute(
      GenerateOpenAPISpec $generator,
      Schema $schema,
      Route $route
    ): void {
        // TODO: Implement describeRoute() method.
    }

    protected function getSummary(): string
    {
        $singular = Str::singular($this->resourceType);
        return "Destroy one $singular";
    }

    protected static function describesRelation(): bool
    {
        return false;
    }

    protected static function describesAction(): string
    {
        return 'destroy';
    }

}
