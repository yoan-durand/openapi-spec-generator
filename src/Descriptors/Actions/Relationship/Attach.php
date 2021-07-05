<?php


namespace LaravelJsonApi\OpenApiSpec\Descriptors\Actions\Relationship;


use cebe\openapi\spec\MediaType;
use cebe\openapi\spec\RequestBody;
use cebe\openapi\spec\Response;
use cebe\openapi\spec\Responses;
use cebe\openapi\spec\Schema as OASchema;
use cebe\openapi\spec\Type;
use LaravelJsonApi\Contracts\Schema\PolymorphicRelation;
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
     * @throws \cebe\openapi\exceptions\TypeErrorException
     */
    protected function describeRoute(
      GenerateOpenAPISpec $generator,
      Schema $schema,
      Route $route
    ): void {
        $this->relation = $route->relation;
        $relation = $this->schema->relationship($this->relation);

        if ($relation instanceof PolymorphicRelation) {
            $targetSchemaNames = collect($relation->inverseTypes());


            $data = new OASchema([
              'oneOf' => $targetSchemaNames->map(function ($name) use (
                $generator
              ) {
                  return new OASchema([
                    'type' => Type::OBJECT,
                    'properties' => [
                      'type' => new OASchema([
                        'title' => 'title',
                        'type' => Type::STRING,
                        'example' => $name,
                      ]),
                      'id' => new OASchema([
                        'title' => 'id',
                        'type' => Type::STRING,
                        'example' => $generator->getModelResources($generator->getJsonapiServer()
                          ->schemas()
                          ->schemaFor($name)::model())
                          ->first()
                          ->id(),
                      ]),
                    ],
                  ]);
              })->toArray(),
            ]);


        } else {
            $data = new OASchema([
              'type' => Type::OBJECT,
              'properties' => [
                'type' => new OASchema([
                  'title' => 'title',
                  'type' => Type::STRING,
                  'example' => $relation->inverse(),
                ]),
                'id' => new OASchema([
                  'title' => 'id',
                  'type' => Type::STRING,
                  'example' => $generator->getModelResources($generator->getJsonapiServer()
                    ->schemas()
                    ->schemaFor($relation->inverse())::model())
                    ->first()
                    ->id(),
                ]),
              ],
            ]);
        }

        if ($relation->toOne()) {
            $this->requestBody = new RequestBody([
              'description' => " attach",
              'content' => [
                'application/vnd.api+json' => new MediaType([
                  "schema" => new OASchema([
                    "properties" => [
                      "data" => $data,
                    ],
                  ]),
                ]),
              ],
            ]);
        } else {
            $this->requestBody = new RequestBody([
              'description' => " attach",
              'content' => [
                'application/vnd.api+json' => new MediaType([
                  "schema" => new OASchema([
                    "properties" => [
                      "data" => new OASchema([
                        'type' => Type::ARRAY,
                        'items' => $data,
                      ]),
                    ],
                  ]),
                ]),
              ],
            ]);
        }

        $this->responses->addResponse(204, new Response([
          'description' => "No content",
        ]));

    }

    protected function getSummary(): string
    {
        return "Attach {$this->relation}";
    }

    protected function getDescription(): string
    {
        return 'Attaches the given members to the relationship. If they are already present, they won\'t be added again.';
    }

    protected static function describesRelation(): bool
    {
        return true;
    }

    protected static function describesAction(): string
    {
        return 'attach';
    }

}
