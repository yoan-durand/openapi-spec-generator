# OpenAPI v3 Spec Generator

Designed to work with [Laravel JSON:API](https://laraveljsonapi.io/)

!!! Disclaimer: this project is work in progress and likely contains many bugs, etc !!!

## TODO

- [x] Command to generate to storage folder
- [x] Get basic test suite running with GitHub Actions
- [x] Add extra operation descriptions via config
- [x] Add in tags & x-tagGroups (via config)
- [x] Add tests (Use the dummy by laraveljsonapi to integrate all features)
- [x] Add custom actions
- [x] Split schemas/requests/responses by action
- [ ] Consider field attributes
  - [x] bool readonly
  - [x] bool hidden
  - [ ] closure based readonly (create/update)
  - [ ] closure based hidden
- [ ] List sortable fields 
- [ ] Fix includes and relations
  - [x] Add relationship routes
  - [ ] Add includes 
- [ ] Add authentication
- [ ] Add custom queries/filters
- [ ] Add a way to document custom actions
- [ ] Tidy up the code!!
- [ ] Replace `cebe/php-openapi` with `goldspecdigital/oooas`
- [ ] Move to an architecture inspired by `vyuldashev/laravel-openapi`
- [ ] Use php8 attributes on actions/classes to generate custom docs

🙏 Based upon initial prototype by [martianatwork](https://github.com/martianatwork)

## Usage

Install package
```
composer require byteit/openapi-spec-generator
```

Publish the config file

```
php artisan vendor:publish --provider="LaravelJsonApi\OpenApiSpec\OpenApiServiceProvider"
```

Generate the Open API spec
```
php artisan jsonapi:openapi:generate v1
```
Note that a seeded DB is required! The seeded data will be used to generate Samples. 

## Generating Documentation

A quick way to preview your documentation is to use [Speccy](https://speccy.io/).
Ensure you have installed Speccy globally and then you can use the following command.

```
speccy serve storage/app/v1_openapi.yaml
```


