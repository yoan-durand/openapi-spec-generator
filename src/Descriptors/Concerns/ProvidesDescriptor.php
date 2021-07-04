<?php


namespace LaravelJsonApi\OpenApiSpec\Descriptors\Concerns;


interface ProvidesDescriptor
{
    public function getDescriptor(): Descriptor;
}
