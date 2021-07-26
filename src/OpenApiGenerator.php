<?php

namespace LaravelJsonApi\OpenApiSpec;

use Storage;
use Symfony\Component\Yaml\Yaml;

class OpenApiGenerator
{

    /**
     * @throws \GoldSpecDigital\ObjectOrientedOAS\Exceptions\ValidationException
     */
    public function generate(string $serverKey): string
        {

          $generator = new Generator($serverKey);
          $openapi = $generator->generate();

            $openapi->validate();

            $yaml = Yaml::dump($openapi->toArray());


            // Save to storage
            Storage::put($serverKey.'_openapi.yaml', $yaml);

            return $yaml;
        }
}
