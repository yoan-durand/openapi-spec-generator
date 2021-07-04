<?php


namespace LaravelJsonApi\OpenApiSpec\Descriptors\Actions;


class Route
{
    public function __construct(
      public \Illuminate\Routing\Route $route,
      public string $resource,
      public string $action,
      public ?string $relation = null
    )
    {
    }
}
