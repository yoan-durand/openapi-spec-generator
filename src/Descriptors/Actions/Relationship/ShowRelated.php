<?php


namespace LaravelJsonApi\OpenApiSpec\Descriptors\Actions\Relationship;


use cebe\openapi\spec\MediaType;
use cebe\openapi\spec\Response;
use cebe\openapi\spec\Schema as OASchema;
use cebe\openapi\spec\Type;
use Illuminate\Support\Str as StrStr;
use LaravelJsonApi\Contracts\Schema\PolymorphicRelation;
use LaravelJsonApi\Contracts\Schema\Schema;
use LaravelJsonApi\Eloquent\Fields\Relations\ToMany;
use LaravelJsonApi\OpenApiSpec\Actions\GenerateOpenAPISpec;
use LaravelJsonApi\OpenApiSpec\Descriptors\Actions\ActionsDescriptor;
use LaravelJsonApi\OpenApiSpec\Descriptors\Actions\Route;
use Neomerx\JsonApi\Contracts\Http\Headers\MediaTypeInterface;

class ShowRelated extends ActionsDescriptor
{
    protected ?string $relation;

    /**
     * @throws \cebe\openapi\exceptions\TypeErrorException
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

        if ($relation instanceof PolymorphicRelation) {
            $targetSchemaNames = $relation->inverseTypes();

            $targetOADataSchemas = collect($targetSchemaNames)
              ->combine(
                collect($targetSchemaNames)
                  ->map(
                    function ($targetSchemaName) {
                        return $this->server->schemas()
                          ->schemaFor($targetSchemaName);
                    })
              )
              ->map(function ($schema, $name) use ($generator){
                  return $generator->generateOAGetDataSchema(
                    $schema,
                    StrStr::singular($name),
                    $name
                  );
              });

            $singular = ! ($relation instanceof ToMany);

            if ($singular) {
                $this->responses->addResponse(200, new Response([
                  'description' => ucfirst($this->action)." $this->resourceType",
                  "content" => [
                    MediaTypeInterface::JSON_API_MEDIA_TYPE => new MediaType([
                      "schema" => new OASchema([
                        'properties' => [
                          'jsonapi' => $generator->getDefaultJsonApiSchema(),
                          'data' => new OASchema([
                            'oneOf' => $targetOADataSchemas->toArray(),
                          ]),
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
                            'items' => new OASchema([
                              'oneOf' => $targetOADataSchemas->toArray(),
                            ]),
                          ]),
                        ],
                      ]),
                    ]),
                  ],
                ]));
            }
        } else {
            $targetSchema = $this->server->schemas()
              ->schemaFor($targetSchemaName);
            if ($singular) {
                $ref = $generator->generateOAGetResponseSchema(
                  $targetSchema,
                  StrStr::singular($targetSchemaName),
                  $targetSchemaName
                );
            } else {
                $ref = $generator->generateOAGetMultipleResponseSchema(
                  $targetSchema,
                  StrStr::singular($targetSchemaName),
                  $targetSchemaName
                );
            }

            $this->responses->addResponse(200, new Response([
              'description' => ucfirst($this->action)." $this->resourceType",
              "content" => [
                MediaTypeInterface::JSON_API_MEDIA_TYPE => new MediaType([
                  "schema" => new OASchema([
                    'oneOf' => [
                      $ref,
                    ],
                  ]),
                ]),
              ],
            ]));
        }
    }

    protected function getSummary(): string
    {
        return "Show related {$this->relation}";
    }

    protected static function describesRelation(): bool
    {
        return true;
    }

    protected static function describesAction(): string
    {
        return 'showRelated';
    }


}
