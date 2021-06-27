# OpenAPI v3 Spec Generator

Designed to work with [Laravel JSON:API](https://laraveljsonapi.io/)

!!! Disclaimer: this project is work in progress and likely contains many bugs, etc !!!

## TODO

- [x] Command to generate to storage folder
- [x] Get basic test suite running with GitHub Actions
- [x] Add extra operation descriptions via config
- [x] Add in tags & x-tagGroups (via config)
- [ ] Consider `->readonly()` etc in routes
- [ ] Remove links in payload data when saving resources
- [ ] Fix includes and relations
  - [ ] Create separate schemas for get/post/patch
  - [ ] Add relationship routes
- [ ] Add authentication
- [ ] Add custom queries/filters
- [ ] Tidy up the code!!
- [ ] Add tests (Use the dummy by laraveljsonapi to integrate all features)

🙏 Based upon initial prototype by [martianatwork](https://github.com/martianatwork)

## Usage

Install package
```
composer require neondigital/openapi-spec-generator
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


