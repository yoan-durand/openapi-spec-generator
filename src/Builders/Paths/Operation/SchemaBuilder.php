<?php


namespace LaravelJsonApi\OpenApiSpec\Builders\Paths\Operation;


use GoldSpecDigital\ObjectOrientedOAS\Contracts\SchemaContract;
use GoldSpecDigital\ObjectOrientedOAS\Objects\OneOf;
use Illuminate\Support\Str;
use LaravelJsonApi\Contracts\Schema\Schema as JASchema;
use LaravelJsonApi\OpenApiSpec\Builders\Builder;
use LaravelJsonApi\OpenApiSpec\ComponentsContainer;
use LaravelJsonApi\OpenApiSpec\Contracts\Descriptors\SchemaDescriptor as SchemaDescriptorContract;
use LaravelJsonApi\OpenApiSpec\Generator;
use LaravelJsonApi\OpenApiSpec\Route;
use LaravelJsonApi\OpenApiSpec\Descriptors\Schema\Schema as SchemaDescriptor;


class SchemaBuilder extends Builder
{

    protected ComponentsContainer $components;

    /**
     * SchemaBuilder constructor.
     *
     * @param  \LaravelJsonApi\OpenApiSpec\Generator  $generator
     * @param  \LaravelJsonApi\OpenApiSpec\ComponentsContainer  $components
     */
    public function __construct(
      Generator $generator,
      ComponentsContainer $components
    ) {
        parent::__construct($generator);
        $this->components = $components;
    }

    /**
     * @param  \LaravelJsonApi\OpenApiSpec\Route  $route
     * @param  bool  $isRequest
     *
     * @return \GoldSpecDigital\ObjectOrientedOAS\Objects\Schema
     * @throws \GoldSpecDigital\ObjectOrientedOAS\Exceptions\InvalidArgumentException
     * @todo Use a schema descriptor container (Container should allow customs
     *   via attribute)
     */
    public function build(Route $route, bool $isRequest = false): SchemaContract
    {

        $objectId = self::objectId($route, $isRequest);

        if ($data = $this->components->getSchema($objectId)) {
            return $data;
        }

        $descriptor = new SchemaDescriptor($this->generator);

        if ($isRequest) {
            $schema = $this->buildRequestSchema($route, $descriptor, $objectId);
        } else {
            $schema = $this->buildResponseSchema($route, $descriptor, $objectId);
        }

        return $this->components->addSchema($schema);
    }

    /**
     * @param  \LaravelJsonApi\OpenApiSpec\Route  $route
     * @param  \LaravelJsonApi\OpenApiSpec\Contracts\Descriptors\SchemaDescriptor  $descriptor
     * @param  string  $objectId
     *
     * @return \GoldSpecDigital\ObjectOrientedOAS\Contracts\SchemaContract
     * @throws \GoldSpecDigital\ObjectOrientedOAS\Exceptions\InvalidArgumentException
     */
    protected function buildResponseSchema(
      Route $route,
      SchemaDescriptorContract $descriptor,
      string $objectId
    ): SchemaContract {

        $method = $route->action();

        if ($data = $this->components->getSchema($objectId)) {
            return $data;
        }

        if ($method === 'showRelated' && $route->isPolymorphic()) {
            $schemas = collect($route->inversSchemas())
              ->map(function (JASchema $schema, string $name)
              use ($descriptor) {

                  $objectId = "resources.$name.resource.fetch";
                  if ($data = $this->components->getSchema($objectId)) {
                      return $data;
                  }

                  return $this->components->addSchema(
                    $descriptor->fetch(
                      $schema,
                      $objectId,
                      $name,
                      ucfirst(Str::singular($name))
                    )
                  );
              });
            return OneOf::create($objectId)
              ->schemas(...array_values($schemas->toArray()));
        }


        if ($method !== 'showRelated' && $route->isRelation()) {
            $schema = $descriptor->fetchRelationship($route);
        } else {
            $schema = match ($method) {
                'index', 'show', 'store', 'update' => $descriptor->fetch(
                  $route->schema(),
                  $objectId,
                  $route->resource(),
                  $route->name(true)
                ),
                'showRelated' => $descriptor->fetch(
                  $route->inversSchema(),
                  $objectId,
                  $route->relation()?->inverse(),
                  $route->inverseName(true)
                ),
                default => die($method) // @todo Add proper Exception
            };
        }

        return $schema->objectId($objectId);
    }

    /**
     * @param  \LaravelJsonApi\OpenApiSpec\Route  $route
     * @param  \LaravelJsonApi\OpenApiSpec\Contracts\Descriptors\SchemaDescriptor  $descriptor
     * @param  string  $objectId
     *
     * @return \GoldSpecDigital\ObjectOrientedOAS\Contracts\SchemaContract
     * @throws \GoldSpecDigital\ObjectOrientedOAS\Exceptions\InvalidArgumentException
     */
    protected function buildRequestSchema(
      Route $route,
      SchemaDescriptorContract $descriptor,
      string $objectId
    ): SchemaContract {

        $method = $route->action();
        if ($route->isRelation()) {
            $schema = match ($method) {
                'update' => $descriptor->updateRelationship($route),
                'attach' => $descriptor->attachRelationship($route),
                'detach' => $descriptor->detachRelationship($route),
                default => die("Request ".$method) // @todo Add proper Exception
            };
        } else {
            $schema = match ($method) {
                'store' => $descriptor->store($route),
                'update' => $descriptor->update($route),
                default => die("Request ".$method) // @todo Add proper Exception
            };
        }

        return $schema->objectId($objectId);
    }

    /**
     * @param  \LaravelJsonApi\OpenApiSpec\Route  $route
     * @param  bool  $isRequest
     *
     * @return string
     */
    public static function objectId(
      Route $route,
      bool $isRequest = false
    ): string {
        if ($isRequest) {

            $method = $route->action();

            $resource = $route->resource();
        } else {

            $method = match ($route->action()) {
                'index', 'show', 'showRelated', 'store', 'update', 'attach', 'detach' => 'fetch',
                default => $route->action()
            };

            $resource = $route->action() === 'showRelated' ? $route->relation()?->inverse() : $route->resource();
        }

        if ($route->isPolymorphic() && $route->action() === 'showRelated') {
            $resource = $route->resource();
            $type = "related.{$route->relationName()}";
        } else {
            $type = $route->isRelation() && $route->action() !== 'showRelated' ?
              "relationship.{$route->relationName()}" : 'resource';
        }


        return "resources.$resource.$type.$method";
    }

}
