<?php


namespace LaravelJsonApi\OpenApiSpec\Descriptors\Actions;


use cebe\openapi\spec\MediaType;
use cebe\openapi\spec\Response;
use cebe\openapi\spec\Schema as OASchema;
use Illuminate\Support\Str as StrStr;
use LaravelJsonApi\Contracts\Schema\Schema;
use LaravelJsonApi\OpenApiSpec\Actions\GenerateOpenAPISpec;
use Neomerx\JsonApi\Contracts\Http\Headers\MediaTypeInterface;

class Index extends ActionsDescriptor
{

    /**
     * @throws \cebe\openapi\exceptions\TypeErrorException
     */
    protected function describeRoute(
      GenerateOpenAPISpec $generator,
      Schema $schema,
      Route $route
    ): void {

        $this->parameters = [];

        foreach ($schema->filters() as $filter) {
            $this->parameters[] = $this->descriptorContainer->getDescriptor($filter)->describe($generator, $schema, $filter);
        }

        foreach ([
                   "sort",
                   "pageSize",
                   "pageNumber",
                   "pageLimit",
                   "pageOffset",
                 ] as $parameter) {
            $this->parameters[] = ['$ref' => "#/components/parameters/$parameter"];
        }

        $ref = $generator->generateOAGetMultipleResponseSchema($schema,
          StrStr::singular($this->resourceType),
          $this->resourceType);
        $this->responses->addResponse(200, new Response([
          'description' => ucfirst($this->action)." $this->resourceType",
          'content' => [
            MediaTypeInterface::JSON_API_MEDIA_TYPE => new MediaType([
              'schema' => new OASchema([
                'oneOf' => [
                  $ref,
                ],
              ]),
            ]),
          ],
        ]));
    }

    protected function getSummary(): string
    {
        return "Get all $this->resourceType";
    }

    protected static function describesRelation(): bool
    {
        return  false;
    }

    protected static function describesAction(): string
    {
        return 'index';
    }

}
