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
use cebe\openapi\SpecBaseObject;
use Error;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str as StrStr;
use LaravelJsonApi\Contracts\Schema\Attribute;
use LaravelJsonApi\Contracts\Schema\PolymorphicRelation;
use LaravelJsonApi\Contracts\Schema\Schema;
use LaravelJsonApi\Contracts\Server\Server;
use LaravelJsonApi\Core\Resources\JsonApiResource;
use LaravelJsonApi\Core\Support\Str;
use LaravelJsonApi\Eloquent\Fields\ArrayHash;
use LaravelJsonApi\Eloquent\Fields\ArrayList;
use LaravelJsonApi\Eloquent\Fields\Boolean;
use LaravelJsonApi\Eloquent\Fields\ID;
use LaravelJsonApi\Eloquent\Fields\Map;
use LaravelJsonApi\Eloquent\Fields\Number;
use LaravelJsonApi\Eloquent\Fields\Relations\Relation;
use LaravelJsonApi\Eloquent\Fields\Relations\ToMany;
use LaravelJsonApi\Eloquent\Fields\Relations\ToOne;
use LaravelJsonApi\Eloquent\Filters\WhereIdIn;
use Throwable;

class GenerateOpenAPISpec
{

    public const MEDIA_TYPE = 'application/vnd.api+json';

    public const OA_VERSION = '3.0.2';

    protected string $serverKey;

    protected OpenApi $openApi;

    protected Collection $routes;

    protected array $paths = [];

    protected Server $jsonapiServer;

    protected array $resources = [];

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
        $this->openApi = new OpenApi([
          'openapi' => self::OA_VERSION,
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
        $this->jsonapiServer = new $serverClass(app(), $serverKey);

        // Add server to OpenAPI spec
        $this->openApi->servers = [
          new OAServer([
            'url' => "{serverURL}",
            "description" => "provide your server URL",
            "variables" => [
              "serverURL" => new OAServerVariable([
                "default" => $this->jsonapiServer->url(""),
                "description" => "path for server",
              ]),
            ],
          ]),
        ];

        // Get all Laravel routes associated with this JSON:API Server
        $this->routes =
          collect(Route::getRoutes()->getRoutes())->filter(
            function (\Illuminate\Routing\Route $route) use ($serverKey) {
                return StrStr::contains($route->getName(), $serverKey);
            }
          );

        foreach ($this->routes as $route) {
            foreach ($route->methods() as $method) {
                $this->generateOperationForMethod(
                  $method,
                  $route,
                );
            }
        }
        // Add paths to OpenApi spec
        foreach ($this->paths as $key => $method) {
            $this->openApi->paths[$key] = new PathItem($method);
        }


        if ( ! $this->openApi->validate()) {

            throw new Error("Validation failed.");
        }
        return $this->openApi;
    }

    /**
     * @param  mixed  $method
     * @param  mixed  $route
     *
     * @throws \cebe\openapi\exceptions\TypeErrorException
     */
    protected function generateOperationForMethod(
      mixed $method,
      \Illuminate\Routing\Route $route,
    ): void {
        $serverKey = $this->serverKey;
        $routeNameSegments = explode('.', $route->getName());

        $index = array_search($serverKey, $routeNameSegments);
        $routeNameSegments = array_slice($routeNameSegments, $index + 1);
        $resourceType = $route->defaults['resource_type'];
        [, $action] = $routeNameSegments;


        $schema = $this->jsonapiServer->schemas()->schemaFor($resourceType);

        $operationId = collect($routeNameSegments)->join('_');

        $uri = '/'.$route->uri();

        $routeUri = str_replace($this->jsonapiServer->baseUri(), '', $uri);

        // @todo extract parameters by regex
        $hasPathParameter = \Str::contains($routeUri, '{');
        if ($hasPathParameter) {
            $resourceIdName = $route->defaults['resource_id_name'];
        }


        $parameters = [];
        $responses = new Responses([]);
        /** @var RequestBody|Reference|null $requestBody */
        $requestBody = null;


        if ($method === 'HEAD') {
            return;
        }


        if ($action === 'index') {
            foreach ($schema->filters() as $filter) {
                $key = $filter->key();

                if ($filter instanceof WhereIdIn) {
                    $examples = $this->getModelResources($schema::model())
                      ->mapWithKeys(function (JsonApiResource $resource) {
                          $id = $resource->id();
                          return [
                            $id => new Example([
                              'value' => $id,
                            ]),
                          ];
                      })
                      ->toArray();
                } else {
                    try {
                        $examples = $schema::model()::all()
                          ->pluck($key)
                          ->mapWithKeys(function ($f) {
                              return [
                                $f => new Example([
                                  'value' => $f,
                                ]),
                              ];
                          })
                          ->toArray();
                    } catch (\Exception) {
                        $examples = [];
                    }
                }

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
            $ref = $this->generateOAGetMultipleResponseSchema($schema,
              StrStr::singular($resourceType),
              $resourceType);
            $responses->addResponse(200, new Response([
              'description' => ucfirst($action)." $resourceType",
              "content" => [
                self::MEDIA_TYPE => new MediaType([
                  "schema" => new OASchema([
                    'oneOf' => [
                      $ref,
                    ],
                  ]),
                ]),
              ],
            ]));
        } elseif ($action === 'show') {
            $ref = $this->generateOAGetResponseSchema($schema,
              StrStr::singular($resourceType),
              $resourceType);

            $responses->addResponse(200, new Response([
              'description' => ucfirst($action)." $resourceType",
              "content" => [
                self::MEDIA_TYPE => new MediaType([
                  "schema" => new OASchema([
                    'oneOf' => [
                      $ref,
                    ],
                  ]),
                ]),
              ],
            ]));
        } elseif ($action === 'store') {

            $requestBody = $this->generateOAStoreRequestBody($schema,
              StrStr::singular($resourceType),
              $resourceType);

            $ref = $this->generateOAGetResponseSchema($schema,
              StrStr::singular($resourceType),
              $resourceType);

            $responses->addResponse(201, new Response([
              'description' => ucfirst($action)." $resourceType",
              "content" => [
                self::MEDIA_TYPE => new MediaType([
                  "schema" => new OASchema([
                    'oneOf' => [
                      $ref,
                    ],
                  ]),
                ]),
              ],
            ]));
        } elseif ($action === 'update') {


            $requestBody = $this->generateOAUpdateRequestBody($schema,
              StrStr::singular($resourceType),
              $resourceType);

            $ref = $this->generateOAGetResponseSchema($schema,
              StrStr::singular($resourceType),
              $resourceType);

            $responses->addResponse(201, new Response([
              'description' => ucfirst($action)." $resourceType",
              "content" => [
                self::MEDIA_TYPE => new MediaType([
                  "schema" => new OASchema([
                    "oneOf" => [
                      $ref,
                    ],
                  ]),
                ]),
              ],
            ]));
        } elseif ($action === 'delete') {
            $responses->addResponse(204, new Response([
              'description' => ucfirst($action)." $resourceType",
            ]));
        } elseif ($schema->isRelationship($action)) {
            // Drop out, dont know yet what todo

            $relation = $schema->relationship($action);

            $singular = $relation->toOne();

            $targetSchemaName = $relation->inverse();


            if ($relation instanceof PolymorphicRelation) {
                $targetSchemaNames = $relation->inverseTypes();

                $targetOADataSchemas = collect($targetSchemaNames)
                  ->combine(
                    collect($targetSchemaNames)
                      ->map(
                        function ($targetSchemaName) {
                            return $this->jsonapiServer->schemas()
                              ->schemaFor($targetSchemaName);
                        })
                  )
                  ->map(function ($schema, $name) {
                      return $this->generateOAGetDataSchema(
                        $schema,
                        StrStr::singular($name),
                        $name
                      );
                  });

                $singular = ! ($relation instanceof ToMany);

                if ($singular) {
                    $responses->addResponse(200, new Response([
                      'description' => ucfirst($action)." $resourceType",
                      "content" => [
                        self::MEDIA_TYPE => new MediaType([
                          "schema" => new OASchema([
                            'properties' => [
                              'jsonapi' => $this->getDefaultJsonApiSchema(),
                              'data' => new OASchema([
                                'oneOf' => $targetOADataSchemas->toArray(),
                              ]),
                            ],
                          ]),
                        ]),
                      ],
                    ]));
                } else {
                    $responses->addResponse(200, new Response([
                      'description' => ucfirst($action)." $resourceType",
                      "content" => [
                        self::MEDIA_TYPE => new MediaType([
                          "schema" => new OASchema([
                            'properties' => [
                              'jsonapi' => $this->getDefaultJsonApiSchema(),
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
                $targetSchema = $this->jsonapiServer->schemas()
                  ->schemaFor($targetSchemaName);
                if ($singular) {
                    $ref = $this->generateOAGetResponseSchema(
                      $targetSchema,
                      StrStr::singular($targetSchemaName),
                      $targetSchemaName
                    );
                } else {
                    $ref = $this->generateOAGetMultipleResponseSchema(
                      $targetSchema,
                      StrStr::singular($targetSchemaName),
                      $targetSchemaName
                    );
                }

                $responses->addResponse(200, new Response([
                  'description' => ucfirst($action)." $resourceType",
                  "content" => [
                    self::MEDIA_TYPE => new MediaType([
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


        if ($hasPathParameter) {
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

            $examples = $this->getModelResources($schema::model())
              ->mapWithKeys(function ($model) {
                  $id = $model->id();
                  return [
                    $id => new Example([
                      'value' => $id,
                    ]),
                  ];
              })->toArray();
            $parameters[] = new Parameter([
              'name' => $resourceIdName,
              'in' => 'path',
              'required' => true,
              'allowEmptyValue' => false,
              "examples" => $examples,
              'schema' => new OASchema([
                'title' => $resourceType,
              ]),
            ]);
        }

        $summary = match ($action) {
            "index" => "Get all $resourceType",
            "show" => "Get a ".StrStr::singular($resourceType),
            "store" => "Store a new ".StrStr::singular($resourceType),
            "update" => "Update the ".StrStr::singular($resourceType),
            "delete" => "Delete the ".StrStr::singular($resourceType),
            default => ucfirst($action),
        };


        $operation = new Operation([
          "summary" => $this->getSummary($serverKey,
              $operationId) ?? $summary,
          "description" => $this->getDescription($serverKey,
              $operationId) ?? "",
          "operationId" => $operationId,
          "parameters" => $parameters,
          "responses" => $responses,
          "tags" => [
            ucfirst($resourceType),
            ...$this->getTags($serverKey, $operationId),
          ],
        ]);

        if ($requestBody) {
            $operation->requestBody = $requestBody;
        }


        if ( ! isset($this->paths[$routeUri])) {
            $this->paths[$routeUri] = [];
        }
        $this->paths[$routeUri][strtolower($method)] = $operation;
    }

    /**
     * @throws \cebe\openapi\exceptions\TypeErrorException
     */
    protected function generateOAGetDataSchema(
      Schema $schema,
      string $schemaName,
      string $schemaNamePlural,
    ): Reference {
        $oaSchemaName = $schemaName.'_data';
        if ( ! ($ref = $this->hasSchema($oaSchemaName))) {

            $relationSchemas = [];

            /** @var \LaravelJsonApi\Core\Resources\JsonApiResource $resource */
            $resource = $this->getModelResources($schema::model())->first();
            $model = $resource->resource;
            $fieldValues = collect($resource->attributes(null))->toArray();
            foreach ($schema->fields() as $field) {

                $fieldSchema = new OASchema([
                  'title' => $field->name(),
                  "type" => Type::OBJECT,
                ]);

                if ($field instanceof ID) {
                    continue;
                }

                if ($field instanceof Attribute) {
                    $fieldSchema->type = match (true) {
                        $field instanceof Boolean => Type::BOOLEAN,
                        $field instanceof Number => Type::NUMBER,
                        $field instanceof ArrayList => Type::ARRAY,
                        $field instanceof ArrayHash,
                          $field instanceof Map => Type::OBJECT,
                        default => Type::STRING
                    };

                    // Try to get example data
                    try {
                        $fieldSchema->example = $fieldValues[$field->name()];
                    } catch (Throwable) {
                    }

                } elseif ($field instanceof Relation) {
                    $relationSchema = new OASchema([
                      'title' => $field->name(),
                    ]);
                    $relationLinkSchema = new OASchema([
                      'title' => $field->name(),
                    ]);
                    $relationDataSchema = new OASchema([
                      'title' => $field->name(),
                    ]);
                    $fieldName = Str::dasherize(
                      Str::plural($field->relationName())
                    );

                    $relationLinkSchema->properties = [
                      'related' => new OASchema([
                        'title' => 'related',
                        "type" => Type::STRING,
                      ]),
                      'self' => new OASchema([
                        'title' => 'self',
                        "type" => Type::STRING,
                      ]),
                    ];
                    $relationDataSchema->properties = [
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
                    ];
                    $relationSchema->properties = [
                      'links' => new OASchema([
                        'title' => 'links',
                        'type' => Type::OBJECT,
                        "allOf" => [$relationLinkSchema],
                        "example" => $this->jsonapiServer->url([
                          $fieldName,
                          (string) optional($model)->{$schema->id()
                            ->column() ?? optional($model)->getRouteKeyName()},
                        ]),
                      ]),
                      'data' => new OASchema([
                        'title' => 'data',
                        "allOf" => [$relationDataSchema],
                      ]),
                    ];

                    if (
                      $field instanceof ToOne
                      && in_array($fieldName,
                        $this->jsonapiServer->schemas()->types(), true)
                    ) {
                        $fieldSchema->__set('oneOf', [
                          $relationSchema,
                        ]);
                    }
                    $relationSchemas[$field->name()] = $relationSchema;
                    continue;
                }

                $attributes[$field->name()] = $fieldSchema;
                unset($fieldSchema);
            }


            $oaSchema = new OASchema([
              "type" => Type::OBJECT,
              "title" => ucfirst($schemaName)." data",
              "properties" => [
                "type" => new OASchema([
                  'title' => $schemaName,
                  'type' => Type::STRING,
                  'example' => $schemaNamePlural,
                ]),
                "id" => new OASchema([
                  'title' => 'id',
                  'type' => Type::STRING,
                  "example" => $resource?->id(),
                ]),
                "attributes" => new OASchema([
                  'title' => 'attributes',
                  'properties' => $attributes,
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
                      "example" => $this->jsonapiServer->url([
                        $schemaNamePlural,
                        (string) optional($model)->{$schema->id()
                          ->column() ?? optional($model)->getRouteKeyName()},
                      ]),
                    ]),
                  ],
                ]),
              ],
            ]);
            $ref = $this->addSchema($oaSchemaName, $oaSchema);
        }
        return $ref;
    }

    /**
     * @throws \cebe\openapi\exceptions\TypeErrorException
     */
    protected function generateOAGetResponseSchema(
      Schema $schema,
      string $schemaName,
      string $schemaNamePlural,
    ): Reference {
        $oaSchemaName = $schemaName.'_get_single';
        if ( ! ($ref = $this->hasSchema($oaSchemaName))) {
            $dataRef = $this->generateOAGetDataSchema(
              $schema,
              $schemaName,
              $schemaNamePlural
            );

            $ref = $this->addSchema(
              $oaSchemaName,
              new OASchema([
                'title' => ucfirst($schemaName)." response",
                'properties' => [
                  'jsonapi' => $this->getDefaultJsonApiSchema(),
                  'data' => new OASchema([
                    'oneOf' => [
                      $dataRef,
                    ],
                  ]),
                ],
              ])
            );
        }
        return $ref;
    }

    /**
     * @throws \cebe\openapi\exceptions\TypeErrorException
     */
    protected function generateOAGetMultipleResponseSchema(
      Schema $schema,
      string $schemaName,
      string $schemaNamePlural,
    ): Reference {
        $oaSchemaName = $schemaName.'_get_multiple';
        if ( ! ($ref = $this->hasSchema($oaSchemaName))) {
            $dataRef = $this->generateOAGetDataSchema($schema, $schemaName,
              $schemaNamePlural);
            $ref = $this->addSchema(
              $oaSchemaName,
              new OASchema([
                'title' => ucfirst($schemaNamePlural)." response",
                'properties' => [
                  'jsonapi' => $this->getDefaultJsonApiSchema(),
                  'data' => new OASchema([
                    'type' => Type::ARRAY,
                    'items' => $dataRef,
                  ]),
                ],
              ]));
        }
        return $ref;
    }

    /**
     * @throws \cebe\openapi\exceptions\TypeErrorException
     */
    protected function generateOAStoreRequestBody(
      Schema $schema,
      string $schemaName,
      string $schemaNamePlural,
    ): Reference {

        $requestName = $schemaName."_store";
        if ( ! isset($this->requests[$requestName])) {

            $relationSchemas = [];
            $attributes = [];

            $modelClass = $schema::model();
            $model = $modelClass::first();

            foreach ($schema->fields() as $field) {

                $fieldSchema = new OASchema([
                  'title' => $field->name(),
                  "type" => Type::OBJECT,
                ]);

                if ($field instanceof ID) {
                    continue;
                }

                if ($field instanceof Attribute) {
                    $fieldSchema->type = match (true) {
                        $field instanceof Boolean => Type::BOOLEAN,
                        $field instanceof Number => Type::NUMBER,
                        $field instanceof ArrayList => Type::ARRAY,
                        $field instanceof ArrayHash,
                          $field instanceof Map => Type::OBJECT,
                        default => Type::STRING
                    };

                    // Try to get example data
                    try {
                        $fieldSchema->example = $model?->{$field->column()};
                    } catch (Throwable) {
                    }

                } elseif ($field instanceof Relation) {
                    $relationSchema = new OASchema([
                      'title' => $field->name(),
                    ]);
                    $relationDataSchema = new OASchema([
                      'title' => $field->name(),
                    ]);
                    $fieldName = Str::dasherize(
                      Str::plural($field->relationName())
                    );

                    $relationDataSchema->properties = [
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
                    ];
                    $relationSchema->properties = [
                      'data' => new OASchema([
                        'title' => 'data',
                        "allOf" => [$relationDataSchema],
                      ]),
                    ];

                    if (
                      $field instanceof ToOne
                      && in_array($fieldName,
                        $this->jsonapiServer->schemas()->types(), true)
                    ) {
                        $fieldSchema->__set('oneOf', [
                          $relationSchema,
                        ]);
                    }
                    $relationSchemas[$field->name()] = $relationSchema;
                    continue;
                }

                $attributes[$field->name()] = $fieldSchema;
                unset($fieldSchema);
            }


            $oaSchema = new OASchema([
              "type" => Type::OBJECT,
              "title" => ucfirst($schemaName)." store data",
              "properties" => [
                "type" => new OASchema([
                  'title' => $schemaName,
                  'type' => Type::STRING,
                  'example' => $schemaNamePlural,
                ]),
                "attributes" => new OASchema([
                  'title' => 'attributes',
                  'properties' => $attributes,
                ]),
                "relationships" => new OASchema([
                  'title' => 'relationships',
                  'properties' => ! empty($relationSchemas) ? $relationSchemas : [],
                ]),
              ],
            ]);
            $oaSchemaName = $schemaName.'_store_data';
            $dataRef = $this->addSchema($oaSchemaName, $oaSchema);


            $this->addRequestBody($requestName, new RequestBody([
                'description' => ucfirst($schemaName)." store",
                'content' => [
                  'application/vnd.api+json' => new MediaType([
                    "schema" => new OASchema([
                      "properties" => [
                        "data" => new OASchema([
                          "title" => 'data',
                          "type" => Type::OBJECT,
                          "oneOf" => [
                            $dataRef,
                          ],
                        ]),
                      ],
                    ]),
                  ]),
                ],
              ])
            );
        }
        return new Reference(['$ref' => "#/components/requestBodies/$requestName"]);

    }

    /**
     * @throws \cebe\openapi\exceptions\TypeErrorException
     */
    protected function generateOAUpdateRequestBody(
      Schema $schema,
      string $schemaName,
      string $schemaNamePlural,
    ): Reference {

        $requestName = $schemaName."_update";
        if ( ! ($ref = $this->hasRequestBody($requestName))) {

            $relationSchemas = [];
            $attributes = [];

            $modelClass = $schema::model();
            $model = $modelClass::first();

            foreach ($schema->fields() as $field) {

                $fieldSchema = new OASchema([
                  'title' => $field->name(),
                  "type" => Type::OBJECT,
                ]);

                if ($field instanceof ID) {
                    continue;
                }

                if ($field instanceof Attribute) {
                    $fieldSchema->type = match (true) {
                        $field instanceof Boolean => Type::BOOLEAN,
                        $field instanceof Number => Type::NUMBER,
                        $field instanceof ArrayList => Type::ARRAY,
                        $field instanceof ArrayHash,
                          $field instanceof Map => Type::OBJECT,
                        default => Type::STRING
                    };

                    // Try to get example data
                    try {
                        $fieldSchema->example = $model?->{$field->column()};
                    } catch (Throwable) {
                    }

                } elseif ($field instanceof Relation) {
                    $relationSchema = new OASchema([
                      'title' => $field->name(),
                    ]);
                    $relationDataSchema = new OASchema([
                      'title' => $field->name(),
                    ]);
                    $fieldName = Str::dasherize(
                      Str::plural($field->relationName())
                    );

                    $relationDataSchema->properties = [
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
                    ];
                    $relationSchema->properties = [
                      'data' => new OASchema([
                        'title' => 'data',
                        "allOf" => [$relationDataSchema],
                      ]),
                    ];

                    if (
                      $field instanceof ToOne
                      && in_array($fieldName,
                        $this->jsonapiServer->schemas()->types(), true)
                    ) {
                        $fieldSchema->__set('oneOf', [
                          $relationSchema,
                        ]);
                    }
                    $relationSchemas[$field->name()] = $relationSchema;
                    continue;
                }

                $attributes[$field->name()] = $fieldSchema;
                unset($fieldSchema);
            }


            $oaSchema = new OASchema([
              "type" => Type::OBJECT,
              "title" => ucfirst($schemaName)." update data",
              "properties" => [
                "type" => new OASchema([
                  'title' => $schemaName,
                  'type' => Type::STRING,
                  'example' => $schemaNamePlural,
                ]),
                "attributes" => new OASchema([
                  'title' => 'attributes',
                  'properties' => $attributes,
                ]),
                "relationships" => new OASchema([
                  'title' => 'relationships',
                  'properties' => ! empty($relationSchemas) ? $relationSchemas : [],
                ]),
              ],
            ]);
            $oaSchemaName = $schemaName.'_store_data';
            $dataRef = $this->addSchema($oaSchemaName, $oaSchema);


            $ref = $this->addRequestBody($requestName, new RequestBody([
                'description' => $schemaName."_update",
                'content' => [
                  'application/vnd.api+json' => new MediaType([
                    "schema" => new OASchema([
                      "properties" => [
                        "data" => new OASchema([
                          "title" => 'data',
                          "type" => Type::OBJECT,
                          "oneOf" => [
                            $dataRef,
                          ],
                        ]),
                      ],
                    ]),
                  ]),
                ],
              ])
            );
        }
        return $ref;

    }

    /**
     * @throws \cebe\openapi\exceptions\TypeErrorException
     */
    protected function getDefaultSchemas(): array
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
          'schemas' => $this->getDefaultSchemas(),
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
     * @throws \cebe\openapi\exceptions\TypeErrorException
     */
    protected function getDefaultJsonApiSchema(): OASchema
    {
        return new OASchema([
          'title' => 'jsonapi',
          'properties' => [
            "version" => new OASchema([
              "title" => "version",
              'type' => Type::STRING,
              "example" => "1.0",
            ]),
          ],
        ]);
    }

    /**
     * @throws \cebe\openapi\exceptions\TypeErrorException
     */
    protected function hasSchema(string $name): Reference|bool
    {
        return isset($this->openApi->components->schemas[$name]) ?
          new Reference(['$ref' => "#/components/schemas/$name"]) :
          false;
    }

    /**
     * @throws \cebe\openapi\exceptions\TypeErrorException
     */
    protected function addSchema(string $name, OASchema $schema): Reference
    {
        $this->openApi->components->__set(
          'schemas',
          array_merge($this->openApi->components->schemas, [$name => $schema])
        );
        return new Reference(['$ref' => "#/components/schemas/$name"]);
    }

    /**
     * @throws \cebe\openapi\exceptions\TypeErrorException
     */
    protected function hasRequestBody(string $name): Reference|bool
    {
        return isset($this->openApi->components->requestBodies[$name]) ?
          new Reference(['$ref' => "#/components/requestBodies/$name"]) :
          false;
    }

    /**
     * @throws \cebe\openapi\exceptions\TypeErrorException
     */
    protected function addRequestBody(
      string $name,
      RequestBody $requestBody
    ): Reference {
        $this->openApi->components->__set(
          'requestBodies',
          array_merge($this->openApi->components->requestBodies,
            [$name => $requestBody])
        );
        return new Reference(['$ref' => "#/components/requestBodies/$name"]);
    }

    protected function getModelResources(string $model): Collection
    {
        if ( ! isset($this->resources[$model])) {
            $resources = $model::all()->map(function ($model) {
                return $this->jsonapiServer->resources()->create($model);
            });

            $this->resources[$model] = $resources;
        }

        return $this->resources[$model];
    }

}
