<?php


namespace LaravelJsonApi\OpenApiSpec\Descriptors\Actions\Relationship;


use cebe\openapi\spec\Example;
use cebe\openapi\spec\MediaType;
use cebe\openapi\spec\Response;
use cebe\openapi\spec\Schema as OASchema;
use cebe\openapi\spec\Type;
use cebe\openapi\SpecBaseObject;
use Illuminate\Support\Str as StrStr;
use LaravelJsonApi\Contracts\Schema\PolymorphicRelation;
use LaravelJsonApi\Contracts\Schema\Schema;
use LaravelJsonApi\Eloquent\Fields\Relations\ToMany;
use LaravelJsonApi\OpenApiSpec\Actions\GenerateOpenAPISpec;
use LaravelJsonApi\OpenApiSpec\Descriptors\Actions\ActionsDescriptor;
use LaravelJsonApi\OpenApiSpec\Descriptors\Actions\Route;
use LaravelJsonApi\OpenApiSpec\Tests\Support\Models\User;
use Neomerx\JsonApi\Contracts\Http\Headers\MediaTypeInterface;

class Show extends ActionsDescriptor
{

    protected ?string $relation;

    /**
     * @param  \LaravelJsonApi\OpenApiSpec\Actions\GenerateOpenAPISpec  $generator
     * @param  \LaravelJsonApi\Contracts\Schema\Schema  $schema
     * @param  \LaravelJsonApi\OpenApiSpec\Descriptors\Actions\Route  $route
     *
     * @throws \cebe\openapi\exceptions\TypeErrorException
     * @todo Implement request/response
     */
    protected function describeRoute(
      GenerateOpenAPISpec $generator,
      Schema $schema,
      Route $route
    ): void {
        $this->relation = $route->relation;
        $relation = $schema->relationship($route->relation);

        if ($relation instanceof PolymorphicRelation) {
            $targetSchemaNames = collect($relation->inverseTypes());


            $data = new OASchema([
              'oneOf' => $targetSchemaNames->map(function ($name) use (
                $generator,
                $relation
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

        $links = new OASchema([
          'title' => 'links',
          'nullable' => true,
          'properties' => [
            'self' => new OASchema([
              'title' => 'self',
              'type' => Type::STRING,
              'example' => $this->server->url([
                $this->resourceType,
                $generator->getModelResources($this->schema::model())
                  ->first()
                  ->id(),
                'relationships',
                $this->relation,
              ]),
            ]),
            'related' => new OASchema([
              'title' => 'self',
              'type' => Type::STRING,
              'example' => $this->server->url([
                $this->resourceType,
                $generator->getModelResources($this->schema::model())
                  ->first()
                  ->id(),
                $this->relation,
              ]),
            ]),
          ],
        ]);

        if ($relation->toOne()) {
            $this->responses->addResponse(200, new Response([
              'description' => ucfirst($this->action)." $this->resourceType",
              "content" => [
                MediaTypeInterface::JSON_API_MEDIA_TYPE => new MediaType([
                  "schema" => new OASchema([
                    'properties' => [
                      'jsonapi' => $generator->getDefaultJsonApiSchema(),
                      'data' => $data,
                      'links' => $links,
                    ],
                  ]),
                ]),
              ],
            ]));
        } else {
            $this->responses->addResponse(200, new Response([
              'description' => ucfirst($this->action)." $this->resourceType",
              "content" => [
                MediaTypeInterface::JSON_API_MEDIA_TYPE => new MediaType([
                  "schema" => new OASchema([
                    'properties' => [
                      'jsonapi' => $generator->getDefaultJsonApiSchema(),
                      'data' => new OASchema([
                        'type' => Type::ARRAY,
                        'items' => $data,
                      ]),
                      'links' => $links,
                    ],
                  ]),
                ]),
              ],
            ]));
        }
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
