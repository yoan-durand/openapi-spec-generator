<?php

namespace LaravelJsonApi\OpenApiSpec\Commands;

use GoldSpecDigital\ObjectOrientedOAS\Exceptions\ValidationException;
use Illuminate\Console\Command;
use LaravelJsonApi\OpenApiSpec\Facades\GeneratorFacade;

class GenerateCommand extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'jsonapi:openapi:generate {serverKey}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generates an Open API v3 spec';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $serverKey = $this->argument('serverKey');

        $this->info('Generating Open API spec...');
        try {
            GeneratorFacade::generate($serverKey);
        } catch (ValidationException $exception) {
            $this->error('Validation failed');
            $this->line('Errors:');
            collect($exception->getErrors())
              ->map(function ($val) {
                  return collect($val)->map(function ($val, $key) {
                      return sprintf("%s: %s", ucfirst($key), $val);
                  })->join("\n");
                })->each(function ($string) {
                  $this->line($string);
                  $this->line("\n");
              });
            return 1;
        }

        $this->line('Complete! /storage/app/'.$serverKey.'_openapi.yaml');
        $this->newLine();
        $this->line('Run the following to see your API docs');
        $this->info('speccy serve storage/app/'.$serverKey.'_openapi.yaml');
        $this->newLine();

        return 0;
    }

}
