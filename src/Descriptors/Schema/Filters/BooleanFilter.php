<?php


namespace LaravelJsonApi\OpenApiSpec\Descriptors\Schema\Filters;


use cebe\openapi\spec\Parameter;
use cebe\openapi\spec\Schema as OASchema;
use cebe\openapi\spec\Type;
use LaravelJsonApi\Contracts\Schema\Filter;
use LaravelJsonApi\Contracts\Schema\Schema;
use LaravelJsonApi\OpenApiSpec\Actions\GenerateOpenAPISpec;

abstract class BooleanFilter extends FilterDescriptor
{

    /**
     * @throws \cebe\openapi\exceptions\TypeErrorException
     */
    protected function describeFilter(
      GenerateOpenAPISpec $generator,
      Schema $schema,
      Filter $filter
    ): ?Parameter {
        return new Parameter([
          'name' => "filter[{$filter->key()}]",
          'description' => $this->getDescriptionText($filter),
          'in' => 'query',
          'required' => false,
          'allowEmptyValue' => false,
          'schema' => new OASchema([
            "type" => Type::BOOLEAN,
          ]),
        ]);
    }

    abstract protected function getDescriptionText(Filter $filter): string;

}
