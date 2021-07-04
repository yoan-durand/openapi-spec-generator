<?php


namespace LaravelJsonApi\OpenApiSpec\Descriptors\Actions;


use cebe\openapi\spec\MediaType;
use cebe\openapi\spec\Response;
use cebe\openapi\spec\Schema as OASchema;
use Illuminate\Support\Str as StrStr;
use LaravelJsonApi\Contracts\Schema\Schema;
use LaravelJsonApi\Core\Support\Str;
use LaravelJsonApi\OpenApiSpec\Actions\GenerateOpenAPISpec;
use Neomerx\JsonApi\Contracts\Http\Headers\MediaTypeInterface;

class Update extends ActionsDescriptor
{

    /**
     * @throws \cebe\openapi\exceptions\TypeErrorException
     */
    protected function describeRoute(
      GenerateOpenAPISpec $generator,
      Schema $schema,
      Route $route
    ): void {

        $this->requestBody = $generator->generateOAUpdateRequestBody($schema,
          StrStr::singular($this->resourceType),
          $this->resourceType);

        $ref = $generator->generateOAGetResponseSchema($schema,
          StrStr::singular($this->resourceType),
          $this->resourceType);

        $this->responses->addResponse(201, new Response([
          'description' => ucfirst($this->action)." $this->resourceType",
          "content" => [
            MediaTypeInterface::JSON_API_MEDIA_TYPE => new MediaType([
              "schema" => new OASchema([
                "oneOf" => [
                  $ref,
                ],
              ]),
            ]),
          ],
        ]));
    }

    protected function getSummary(): string
    {
        $singular = Str::singular($this->resourceType);
        return "Update one $singular";
    }

    protected static function describesRelation(): bool
    {
        return  false;
    }

    protected static function describesAction(): string
    {
        return 'update';
    }

}
