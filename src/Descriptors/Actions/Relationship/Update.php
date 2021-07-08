<?php


namespace LaravelJsonApi\OpenApiSpec\Descriptors\Actions\Relationship;

class Update extends Attach
{
    protected ?string $relation;


    protected function getSummary(): string
    {
        return "Update {$this->relation} relation";
    }

    protected static function describesAction(): string
    {
        return 'update';
    }

}
