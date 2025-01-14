<?php


namespace LaravelJsonApi\OpenApiSpec\Builders;


use GoldSpecDigital\ObjectOrientedOAS\Objects\Info;
use LaravelJsonApi\OpenApiSpec\Descriptors\Server;

class InfoBuilder extends Builder
{

    /**
     * @return \GoldSpecDigital\ObjectOrientedOAS\Objects\Info
     */
    public function build(): Info
    {
        return (new Server($this->generator))->info();
    }

}
