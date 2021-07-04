<?php


namespace LaravelJsonApi\OpenApiSpec\Descriptors\Schema\Filters;


use cebe\openapi\spec\Example;
use cebe\openapi\spec\Parameter;
use cebe\openapi\spec\Schema as OASchema;
use cebe\openapi\spec\Type;
use LaravelJsonApi\Contracts\Schema\Filter;
use LaravelJsonApi\Contracts\Schema\Schema;
use LaravelJsonApi\Core\Resources\JsonApiResource;
use LaravelJsonApi\Eloquent\Filters\WhereIdIn as WhereIdInFiler;
use LaravelJsonApi\Eloquent\Filters\WhereIdNotIn;
use LaravelJsonApi\OpenApiSpec\Actions\GenerateOpenAPISpec;

class WhereIdIn extends FilterDescriptor
{

    public static function canDescribe($entity): bool
    {
        return $entity instanceof WhereIdInFiler;
    }

    /**
     * @throws \cebe\openapi\exceptions\TypeErrorException
     */
    protected function describeFilter(
      GenerateOpenAPISpec $generator,
      Schema $schema,
      Filter $filter
    ): Parameter {

        $examples = $generator->getModelResources($schema::model())
          ->mapWithKeys(function (JsonApiResource $resource) {
              $id = $resource->id();
              return [
                $id => new Example([
                  'value' => [$id],
                ]),
              ];
          })
          ->toArray();

        return new Parameter([
          'name' => "filter[{$filter->key()}]",
          'description' => $filter instanceof WhereIdNotIn? 'A list of ids to exclude by.' : 'A list of ids to filter by.',
          'in' => 'query',
          'style' => 'form',
          'explode' => false,
          'required' => false,
          'allowEmptyValue' => false,
          'examples' => $examples,
          'schema' => new OASchema([
            'type' => Type::ARRAY,
            'items' => new OASchema([
              'type' => Type::STRING,
            ]),
          ]),
        ]);
    }

}
