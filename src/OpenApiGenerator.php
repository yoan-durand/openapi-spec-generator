<?php

namespace LaravelJsonApi\OpenApiSpec;

use cebe\openapi\spec\Components;
use cebe\openapi\spec\Example;
use cebe\openapi\spec\MediaType;
use cebe\openapi\spec\OpenApi;
use cebe\openapi\spec\Operation;
use cebe\openapi\spec\Parameter;
use cebe\openapi\spec\PathItem;
use cebe\openapi\spec\Reference;
use cebe\openapi\spec\RequestBody;
use cebe\openapi\spec\Response;
use cebe\openapi\spec\Responses;
use cebe\openapi\spec\Schema as OASchema;
use cebe\openapi\spec\Server as OAServer;
use cebe\openapi\spec\ServerVariable as OAServerVariable;
use cebe\openapi\spec\Type;
use cebe\openapi\Writer;
use Exception;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str as StrStr;
use LaravelJsonApi\Contracts\Schema\Schema;
use LaravelJsonApi\Contracts\Server\Server;
use LaravelJsonApi\Eloquent\Fields\Boolean;
use LaravelJsonApi\Eloquent\Fields\DateTime;
use LaravelJsonApi\Eloquent\Fields\ID;
use LaravelJsonApi\Eloquent\Fields\Number;
use LaravelJsonApi\Eloquent\Fields\Relations\Relation;
use LaravelJsonApi\Eloquent\Fields\Relations\ToOne;
use LaravelJsonApi\Eloquent\Fields\Str;
use LaravelJsonApi\OpenApiSpec\Actions\GenerateOpenAPISpec;
use Storage;
use Throwable;

class OpenApiGenerator
{

    /**
     * @throws \cebe\openapi\exceptions\UnknownPropertyException
     * @throws \cebe\openapi\exceptions\TypeErrorException
     * @throws \Exception
     */
    public function generate(string $serverKey): string
        {

            $openapi = (new GenerateOpenAPISpec($serverKey))->generate();

            if ($openapi->validate()) {
                $yaml = Writer::writeToYaml($openapi);
            } else {
                dump($openapi->getErrors());
                throw new Exception('Open API not valid.');
            }

            // Save to storage
            Storage::put($serverKey.'_openapi.yaml', $yaml);

            return $yaml;
        }
}
