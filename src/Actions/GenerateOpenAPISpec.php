<?php


namespace LaravelJsonApi\OpenApiSpec\Actions;


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
use Illuminate\Support\Collection;
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
use Throwable;

class GenerateOpenAPISpec
{
    public const MEDIA_TYPE = 'application/vnd.api+json';

    protected string $serverKey;
    protected OpenApi $openApi;
    protected Collection $routes;
    protected array $schemas = [];
    protected array $requests = [];
    protected array $parameters = [];

    public function __construct(string $serverKey)
    {
        $this->serverKey = $serverKey;
    }

    /**
     * @throws \cebe\openapi\exceptions\TypeErrorException
     * @throws \cebe\openapi\exceptions\UnknownPropertyException
     * @throws \Exception
     */
    public function generate(): OpenApi
    {
        $serverKey = $this->serverKey;
        $serverClass = config("jsonapi.servers.$serverKey");

        // Initial OpenAPI object
        $openapi = new OpenApi([
          'openapi' => '3.0.2',
          'info' => [
            'title' => config("openapi.servers.$serverKey.info.title"),
            'description' => config("openapi.servers.$serverKey.info.description"),
            'version' => config("openapi.servers.$serverKey.info.version"),
          ],
          'paths' => [],
          "components" => $this->getDefaultComponents(),
          'x-tagGroups' => config("openapi.servers.$serverKey.tag_groups"),
        ]);

        // Load JSON:API Server
        /** @var \LaravelJsonApi\Contracts\Server\Server $jsonapiServer */
        $jsonapiServer = new $serverClass(app(), $serverKey);

        // Add server to OpenAPI spec
        $openapi->__set('servers', [
          new OAServer([
            'url' => "{serverURL}",
            "description" => "provide your server URL",
            "variables" => [
              "serverURL" => new OAServerVariable([
                "default" => $jsonapiServer->url(""),
                "description" => "path for server",
              ]),
            ],
          ]),
        ]);

        $this->schemas = [];
        $this->requests = [];
        $this->parameters = [];

        // Get all Laravel routes associated with this JSON:API Server
        $this->routes =
          collect(Route::getRoutes()->getRoutes())->filter(
            function (\Illuminate\Routing\Route $route) use ($serverKey) {
                return StrStr::contains($route->getName(), $serverKey);
            }
          );

        $routeMethods = [];

        foreach ($this->routes as $route) {
            $uri = $route->uri;
            $routeUri = str_replace($route->getPrefix(), '', $uri);

            $requiresPath = \Str::contains($routeUri, '{');

            if ($requiresPath) {
                $schemaName = \Str::between($routeUri, '{', '}');
            } else {
                $schemaName = str_replace('/', '', $routeUri);
            }

            $schemaName = (string) \Str::of($schemaName)
              ->plural()
              ->replace('_', '-');

            $schema = $jsonapiServer->schemas()->schemaFor($schemaName);

            foreach ($route->methods() as $method) {
                $routeMethods[$routeUri][strtolower($method)] = $this->generateOperationForMethod(
                  $method,
                  $requiresPath,
                  $schema,
                  $schemaName,
                  $route,
                  $routeMethods,
                  $routeUri,
                );
            }
        }

        foreach ($jsonapiServer->schemas()->types() as $schemaName) {
            $schema = $jsonapiServer->schemas()->schemaFor($schemaName);
            $schemaNamePlural = (string) \Str::of($schemaName)
              ->plural()
              ->replace('_', '-');

            $methods = ['GET', 'PATCH', 'POST', 'DELETE'];

            foreach ($methods as $method) {
                $fieldSchemas = [];
                $includedSchemas = [];
                $parameters = [];

                if ($method === 'GET') {
                    foreach ($schema->fields() as $field) {
                        if ($field instanceof Relation) {
                            try {
                                $relationPlural = \Str::plural($field->name());
                                $includedSchemas[] = [
                                  new Reference([
                                    '$ref' => "#/components/schemas/".$relationPlural."_data",
                                  ]),
                                ];
                            } catch (Throwable $exception) {
                                continue;
                            }
                        }
                    }

                    $schemaData = $this->getOpenApiSchema(
                      $jsonapiServer,
                      $schema,
                      $schemaName,
                      $schemaNamePlural,
                      $method,
                      $this->schemas
                    );

                    $this->schemas[$schemaName] = new OASchema([
                      'title' => ucfirst($schemaName),
                      'properties' => [
                        "jsonapi" => new OASchema([
                          'title' => 'jsonapi',
                          'properties' => [
                            "version" => new OASchema([
                              "title" => "version",
                              'type' => Type::STRING,
                              "example" => "1.0",
                            ]),
                          ],
                        ]),
                        "data" => new OASchema([
                          "oneOf" => [
                            new Reference([
                              '$ref' => "#/components/schemas/".$schemaNamePlural."_data",
                            ]),
                          ],
                        ]),
                          // "included" => new OASchema([
                          //     "type" => Type::OBJECT,
                          //     "title" => "included",
                          //     "properties" => $includedSchemas
                          // ])
                      ],
                    ]);

                    $this->schemas[$schemaName."_data"] = new OASchema([
                      'title' => ucfirst($schemaName)." Data",
                      'properties' => $schemaData->__get('properties'),
                    ]);
                }

                if ($method !== 'GET' && ! empty($schema->fields())) {
                    $contents = [];
                    foreach ($schema->fields() as $field) {
                        $contents[$field->name()] = new OASchema([
                          'title' => $field->name(),
                          'type' => 'string',
                        ]);
                    }
                    $this->requests[$schemaName."_".strtolower($method)] = new RequestBody([
                      'description' => $schemaName."_".strtolower($method),
                      'content' => [
                        'application/vnd.api+json' => new MediaType([
                          "schema" => new OASchema([
                            "properties" => [
                              "data" => new OASchema([
                                "title" => 'data',
                                "type" => Type::OBJECT,
                                "oneOf" => [
                                  new Reference([
                                    '$ref' => "#/components/schemas/".$schemaNamePlural."_data",
                                  ]),
                                ],
                              ]),
                            ],
                          ]),
                        ]),
                      ],
                    ]);
                }
            }
        }

        // Add paths to OpenApi spec
        foreach ($routeMethods as $key => $method) {
            $openapi->paths[$key] = new PathItem(array_merge([
              "description" => $schemaName,
            ], $method));
        }

        $openapi->components->__set('schemas',
          array_merge($this->getDefaultSchema(), $this->schemas));
        $openapi->components->__set('requestBodies', $this->requests);
        $openapi->components->__set('parameters',
          array_merge($openapi->components->parameters, $this->parameters));



        return $openapi;
    }

    /**
     * @throws \cebe\openapi\exceptions\TypeErrorException
     */
    private function getOpenApiSchema(
      Server $server,
      Schema $schema,
      string $schemaName,
      string $schemaNamePlural,
      string $method,
      $forIncludes = false
    ) {
        if (isset($this->schemas[$schemaNamePlural])) {
            return $this->schemas[$schemaNamePlural];
        }
        $fieldSchemas = [];
        $relationSchemas = [];

        $modelClass = $schema::model();
        $models = $modelClass::all();
        $model = $modelClass::first();

        foreach ($schema->fields() as $field) {
            if ($method === 'DELETE') {
                continue;
            }
            $fieldSchema = new OASchema([
              'title' => $field->name(),
              "type" => Type::OBJECT,
            ]);
            if ($field instanceof ID) {
                continue;
            }
            if (
              $field instanceof Str ||
              $field instanceof ID ||
              $field instanceof DateTime
            ) {
                $fieldSchema->__set('type', Type::STRING);
            }
            if ($field instanceof Boolean) {
                $fieldSchema->__set('type', Type::BOOLEAN);
            }
            if ($field instanceof Number) {
                $fieldSchema->__set('type', Type::NUMBER);
            }
            if ( ! ($field instanceof Relation)) {
                try {
                    $fieldSchema->__set("example",
                      optional($model)->{$field->column()});
                } catch (Throwable $exception) {
                    // TODO: Figure out if the field is readonly
                }
            }
            if ($field instanceof Relation) {
                $relationSchema = new OASchema([
                  'title' => $field->name(),
                ]);
                $relationLinkSchema = new OASchema([
                  'title' => $field->name(),
                ]);
                $relationDataSchema = new OASchema([
                  'title' => $field->name(),
                ]);
                $fieldName = \LaravelJsonApi\Core\Support\Str::dasherize(
                  \LaravelJsonApi\Core\Support\Str::plural($field->relationName())
                );
                $relationLinkSchema->__set('properties', [
                  'related' => new OASchema([
                    'title' => 'related',
                    "type" => Type::STRING,
                  ]),
                  'self' => new OASchema([
                    'title' => 'self',
                    "type" => Type::STRING,
                  ]),
                ]);
                $relationDataSchema->__set('properties', [
                  'type' => new OASchema([
                    'title' => 'type',
                    "type" => Type::STRING,
                    "example" => $fieldName,
                  ]),
                  'id' => new OASchema([
                    'title' => 'id',
                    "type" => Type::STRING,
                    "example" => (string) optional($model)->{$schema->id()
                      ->column() ?? optional($model)->getRouteKeyName()},
                  ]),
                ]);
                $relationSchema->__set('properties', [
                  'links' => new OASchema([
                    'title' => 'links',
                    'type' => Type::OBJECT,
                    "allOf" => [$relationLinkSchema],
                    "example" => $server->url([
                      $fieldName,
                      (string) optional($model)->{$schema->id()
                        ->column() ?? optional($model)->getRouteKeyName()},
                    ]),
                  ]),
                  'data' => new OASchema([
                    'title' => 'data',
                    "allOf" => [$relationDataSchema],
                  ]),
                ]);
                if (
                  $field instanceof ToOne
                  && in_array($fieldName, $server->schemas()->types(), true)
                ) {
                    $fieldSchema->__set('oneOf', [
                      $relationSchema,
                    ]);
                }
                $relationSchemas[$field->name()] = $relationSchema;
                continue;
            }
            $fieldSchemas[$field->name()] = $fieldSchema;
            unset($fieldSchema);
        }

        return new OASchema([
          "type" => Type::OBJECT,
          "title" => "data",
          "properties" => [
            "type" => new OASchema([
              'title' => $schemaName,
              'type' => Type::STRING,
              'example' => $schemaNamePlural,
            ]),
            "id" => new OASchema([
              'title' => 'id',
              'type' => Type::STRING,
              "example" => optional($model)->id,
            ]),
            "attributes" => new OASchema([
              'title' => 'attributes',
              'properties' => $fieldSchemas,
            ]),
            "relationships" => new OASchema([
              'title' => 'relationships',
              'properties' => ! empty($relationSchemas) ? $relationSchemas : [],
            ]),
            "links" => new OASchema([
              'title' => 'links',
              "nullable" => true,
              'properties' => [
                "self" => new OASchema([
                  "title" => "self",
                  'type' => Type::STRING,
                  "example" => $server->url([$schemaNamePlural,(string) optional($model)->{$schema->id()->column() ?? optional($model)->getRouteKeyName()}]),
                ]),
              ],
            ]),
          ],
        ]);
    }

    /**
     * @throws \cebe\openapi\exceptions\TypeErrorException
     */
    private function getDefaultSchema(): array
    {
        return [
          'unauthorized' => new OASchema([
            'title' => "401 Unauthorized",
            "type" => Type::OBJECT,
            "properties" => [
              "errors" => new OASchema([
                'title' => "errors",
                "type" => Type::OBJECT,
                "properties" => [
                  "detail" => new OASchema([
                    'title' => "detail",
                    "type" => Type::STRING,
                    "example" => 'Unauthenticated.',
                  ]),
                  "status" => new OASchema([
                    'title' => "status",
                    "type" => Type::STRING,
                    "example" => '401',
                  ]),
                  "title" => new OASchema([
                    'title' => "title",
                    "type" => Type::STRING,
                    "example" => 'Unauthorized',
                  ]),
                ],
              ]),
            ],
          ]),
          'forbidden' => new OASchema([
            'title' => "403 Forbidden",
            "type" => Type::OBJECT,
            "properties" => [
              "errors" => new OASchema([
                'title' => "errors",
                "type" => Type::OBJECT,
                "properties" => [
                  "detail" => new OASchema([
                    'title' => "detail",
                    "type" => Type::STRING,
                    "example" => 'Forbidden.',
                  ]),
                  "status" => new OASchema([
                    'title' => "status",
                    "type" => Type::STRING,
                    "example" => '403',
                  ]),
                  "title" => new OASchema([
                    'title' => "title",
                    "type" => Type::STRING,
                    "example" => 'Forbidden',
                  ]),
                ],
              ]),
            ],
          ]),
          'not_found' => new OASchema([
            'title' => "404 Not Found",
            "type" => Type::OBJECT,
            "properties" => [
              "errors" => new OASchema([
                'title' => "errors",
                "type" => Type::OBJECT,
                "properties" => [
                  "status" => new OASchema([
                    'title' => "status",
                    "type" => Type::STRING,
                    "example" => '404',
                  ]),
                  "title" => new OASchema([
                    'title' => "title",
                    "type" => Type::STRING,
                    "example" => 'Not Found',
                  ]),
                ],
              ]),
            ],
          ]),
        ];
    }

    /**
     * @throws \cebe\openapi\exceptions\TypeErrorException
     */
    protected function getDefaultComponents(): Components
    {
        return new Components([
          'parameters' => [
            'sort' => new Parameter([
              "name" => "sort",
              "in" => "query",
              "description" => '[fields to sort by](https://jsonapi.org/format/#fetching-sorting)',
              "required" => false,
              "allowEmptyValue" => true,
              "style" => "form",
              "schema" => ["type" => "string"],
            ]),
            'pageSize' => new Parameter([
              "name" => "page[size]",
              "in" => "query",
              "description" => 'size of page for paginated results',
              "required" => false,
              "allowEmptyValue" => true,
              "schema" => ["type" => "integer"],
            ]),
            'pageNumber' => new Parameter([
              "name" => "page[number]",
              "in" => "query",
              "description" => 'size of page for paginated results',
              "required" => false,
              "allowEmptyValue" => true,
              "schema" => ["type" => "integer"],
            ]),
            'pageLimit' => new Parameter([
              "name" => "page[limit]",
              "in" => "query",
              "description" => 'size of page for paginated results',
              "required" => false,
              "allowEmptyValue" => true,
              "schema" => ["type" => "integer"],
            ]),
            'pageOffset' => new Parameter([
              "name" => "page[offset]",
              "in" => "query",
              "description" => 'size of page for paginated results',
              "required" => false,
              "allowEmptyValue" => true,
              "schema" => ["type" => "integer"],
            ]),
          ],
        ]);
    }

    /**
     * @param $serverKey
     * @param $operationId
     *
     * @return string|null
     */
    protected function getSummary($serverKey, $operationId): ?string
    {
        return config("openapi.servers.$serverKey.operations.$operationId.summary");
    }

    /**
     * @param $serverKey
     * @param $operationId
     *
     * @return string|null
     */
    protected function getDescription($serverKey, $operationId): ?string
    {
        return config("openapi.servers.$serverKey.operations.$operationId.description");
    }

    /**
     * @param $serverKey
     * @param $operationId
     *
     * @return string[]
     */
    protected function getTags($serverKey, $operationId): array
    {
        return config("openapi.servers.$serverKey.operations.$operationId.extra_tags",
          []);
    }

    /**
     * @param  mixed  $method
     * @param  bool  $requiresPath
     * @param  \LaravelJsonApi\Contracts\Schema\Schema  $schema
     * @param  string  $schemaName
     * @param  mixed  $route
     * @param  array  $routeMethods
     * @param  array|string  $routeUri
     *
     * @return \cebe\openapi\spec\Operation|null
     * @throws \cebe\openapi\exceptions\TypeErrorException
     */
    protected function generateOperationForMethod(
      mixed $method,
      bool $requiresPath,
      Schema $schema,
      string $schemaName,
      \Illuminate\Routing\Route $route,
      array $routeMethods,
      array|string $routeUri
    ): ?Operation {
        $serverKey = $this->serverKey;
        $parameters = [];
        $responses = new Responses([]);

        if ($method === 'HEAD') {
            return null;
        }

        if (($method === 'GET') && ! $requiresPath) {
            foreach ($schema->filters() as $filter) {
                $examples = $schema::model()::all()
                  ->pluck($filter->key())
                  ->mapWithKeys(function ($f) {
                      return [
                        $f => new Example([
                          'value' => $f,
                        ]),
                      ];
                  })
                  ->toArray();

                $parameters[] = new Parameter([
                  'name' => "filter[{$filter->key()}]",
                  'in' => 'query',
                  'required' => false,
                  'allowEmptyValue' => true,
                  'examples' => $examples,
                  'schema' => new OASchema([
                    "type" => Type::STRING,
                  ]),
                ]);
            }
            foreach ([
                       "sort",
                       "pageSize",
                       "pageNumber",
                       "pageLimit",
                       "pageOffset",
                     ] as $parameter) {
                $parameters[] = ['$ref' => "#/components/parameters/$parameter"];
            }
        }

        if ($method !== 'DELETE') {
            $responses->addResponse(200, new Response([
              'description' => "$method $schemaName",
              "content" => [
                "application/vnd.api+json" => new MediaType([
                  "schema" => new OASchema([
                    "oneOf" => [
                      new Reference([
                        '$ref' => "#/components/schemas/$schemaName",
                      ]),
                    ],
                  ]),
                ]),
              ],
            ]));
        } else {
            $responses->addResponse(200, new Response([
              'description' => "$method $schemaName",
            ]));
        }

        if ($method === 'POST') {
            $responses->addResponse(201, new Response([
              'description' => "$method $schemaName",
              "content" => [
                self::MEDIA_TYPE => new MediaType([
                  "schema" => new OASchema([
                    "oneOf" => [
                      new Reference([
                        '$ref' => "#/components/schemas/$schemaName",
                      ]),
                    ],
                  ]),
                ]),
              ],
            ]));
        }

        if (in_array($method, ['POST', 'PATCH'])) {
            $responses->addResponse(202, new Response([
              'description' => "$method $schemaName",
              "content" => [
                self::MEDIA_TYPE => new MediaType([
                  "schema" => new OASchema([
                    "oneOf" => [
                      new Reference([
                        '$ref' => "#/components/schemas/$schemaName",
                      ]),
                    ],
                  ]),
                ]),
              ],
            ]));
        }

        $responses->addResponse(401, new Response([
          'description' => "Unauthorized Action",
          "content" => [
            self::MEDIA_TYPE => new MediaType([
              "schema" => new OASchema([
                "oneOf" => [
                  new Reference([
                    '$ref' => "#/components/schemas/unauthorized",
                  ]),
                ],
              ]),
            ]),
          ],
        ]));

        $responses->addResponse(403, new Response([
          'description' => "Forbidden Action",
          "content" => [
            self::MEDIA_TYPE => new MediaType([
              "schema" => new OASchema([
                "oneOf" => [
                  new Reference([
                    '$ref' => "#/components/schemas/forbidden",
                  ]),
                ],
              ]),
            ]),
          ],
        ]));

        $responses->addResponse(404, new Response([
          'description' => "Content Not Found",
          "content" => [
            self::MEDIA_TYPE => new MediaType([
              "schema" => new OASchema([
                "oneOf" => [
                  new Reference([
                    '$ref' => "#/components/schemas/not_found",
                  ]),
                ],
              ]),
            ]),
          ],
        ]));

        if ($requiresPath) {
            $models = ($schema::model())::all();
            $parameters[] = new Parameter([
              'name' => $schemaName,
              'in' => 'path',
              'required' => true,
              'allowEmptyValue' => false,
              "examples" => optional($models)->mapWithKeys(function (
                $model
              ) use ($schema) {
                  return [
                    $model->{$schema->id()
                      ->column() ?? $model->getRouteKeyName()} => new Example([
                      "value" => $model->{$schema->id()
                        ->column() ?? $model->getRouteKeyName()},
                    ]),
                  ];
              })->toArray(),
              'schema' => new OASchema([
                'title' => $schemaName,
              ]),
            ]);
        }

        // Make nice summaries
        $action = StrStr::of($route->getName())->explode('.')->last();

        switch ($action) {
            case "index":
                $summary = "Get all $schemaName";
                break;

            case "show":
                $summary = "Get a $schemaName";
                break;

            case "store":
                $summary = "Create a new $schemaName";
                break;

            case "update":
                $summary = "Update the $schemaName";
                break;

            case "delete":
                $summary = "Delete the $schemaName";
                break;

            default:
                $summary = ucfirst($action);
                break;
        }

        if ( ! isset($routeMethods[$routeUri])) {
            $routeMethods[$routeUri] = [];
        }

        $operationId = str_replace(".", "_", $route->getName());

        $operation = new Operation([
          "summary" => $this->getSummary($serverKey,
              $operationId) ?? $summary,
          "description" => $this->getDescription($serverKey,
              $operationId) ?? "",
          "operationId" => $operationId,
          "parameters" => $parameters,
          "responses" => $responses,
          "tags" => [
            ucfirst($schemaName),
            ...$this->getTags($serverKey, $operationId),
          ],
        ]);
        if (in_array($method, ['POST', 'PATCH'])) {
            $requestBody = ['$ref' => "#/components/requestBodies/".$schemaName."_".strtolower($method)];
            $operation->requestBody = $requestBody;
        }
        return $operation;
    }
}
