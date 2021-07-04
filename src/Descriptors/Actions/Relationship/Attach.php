<?php


namespace LaravelJsonApi\OpenApiSpec\Descriptors\Actions\Relationship;


use LaravelJsonApi\Contracts\Schema\Schema;
use LaravelJsonApi\OpenApiSpec\Actions\GenerateOpenAPISpec;
use LaravelJsonApi\OpenApiSpec\Descriptors\Actions\ActionsDescriptor;
use LaravelJsonApi\OpenApiSpec\Descriptors\Actions\Route;


class Attach extends ActionsDescriptor
{
    protected ?string $relation;

    /**
     * @param  \LaravelJsonApi\OpenApiSpec\Actions\GenerateOpenAPISpec  $generator
     * @param  \LaravelJsonApi\Contracts\Schema\Schema  $schema
     * @param  \LaravelJsonApi\OpenApiSpec\Descriptors\Actions\Route  $route
     *
     * @todo Implement request/response
     */
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
        return 'attach';
    }

}
