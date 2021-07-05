<?php


namespace LaravelJsonApi\OpenApiSpec\Descriptors\Actions\Relationship;

class Detach extends Attach
{
    protected function getSummary(): string
    {
        return "Detach {$this->relation}";
    }

    protected function getDescription(): string
    {
        return 'Detaches the given members from the relationship.';
    }

    protected static function describesAction(): string
    {
        return 'detach';
    }
}
