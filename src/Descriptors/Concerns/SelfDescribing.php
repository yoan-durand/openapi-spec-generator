<?php


namespace LaravelJsonApi\OpenApiSpec\Descriptors\Concerns;


interface SelfDescribing extends Descriptor
{
    public function describesItSelf(): bool;
}
