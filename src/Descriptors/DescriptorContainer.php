<?php


namespace LaravelJsonApi\OpenApiSpec\Descriptors;


use LaravelJsonApi\Contracts\Schema\Filter;
use LaravelJsonApi\OpenApiSpec\Descriptors\Concerns\Descriptor;
use LaravelJsonApi\OpenApiSpec\Descriptors\Concerns\ProvidesDescriptor;
use LaravelJsonApi\OpenApiSpec\Descriptors\Concerns\SelfDescribing;
use LaravelJsonApi\OpenApiSpec\Descriptors\Schema\Filters\Scope;
use LaravelJsonApi\OpenApiSpec\Descriptors\Schema\Filters\Where;
use LaravelJsonApi\OpenApiSpec\Descriptors\Schema\Filters\WhereIdIn;
use LaravelJsonApi\OpenApiSpec\Descriptors\Schema\Filters\WhereIn;
use LaravelJsonApi\OpenApiSpec\Descriptors\Schema\Filters\WithTrashed;
use LogicException;

class DescriptorContainer
{

    /**
     * @var array|string[]
     */
    protected array $filters = [
      WhereIdIn::class,
      WhereIn::class,
      Scope::class,
      WithTrashed::class,
      Where::class,
    ];

    public function getDescriptor(mixed $needsDescription): Descriptor
    {
        if (
          $needsDescription instanceof SelfDescribing
          && $needsDescription->describesItSelf()
        ) {
            return $needsDescription;
        }

        return $this->resolve($needsDescription);
    }

    public function resolve($for): Descriptor
    {
        if ($for instanceof ProvidesDescriptor) {
            return $for->getDescriptor();
        }

        return match (true) {
            $for instanceof Filter => $this->getFilterDescriptor($for),
        };
    }

    protected function getFilterDescriptor(Filter $for): ?Descriptor
    {
        foreach ($this->filters as $descriptor) {
            if ($descriptor::canDescribe($for)) {
                return new $descriptor();
            }
        }
        throw new LogicException("Can resolve Descriptor for ".$for::class);
    }

}
