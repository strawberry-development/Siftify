# Siftify

[![Latest Version on Packagist](https://img.shields.io/packagist/v/vendor/siftify.svg?style=flat-square)](https://packagist.org/packages/vendor/siftify)
[![Total Downloads](https://img.shields.io/packagist/dt/vendor/siftify.svg?style=flat-square)](https://packagist.org/packages/vendor/siftify)
[![License](https://img.shields.io/packagist/l/vendor/siftify.svg?style=flat-square)](https://packagist.org/packages/vendor/siftify)

Siftify is a Laravel package which was made for a school project. It provides a flexible and intuitive API for filtering, sorting, and paginating Eloquent models. It allows you to build robust and feature-rich APIs with minimal effort.

## Installation

You can install the package via composer:

```bash
composer require vendor/siftify
```

## Publish Configuration

```bash
php artisan vendor:publish --tag="siftify-config"
```

This will publish the configuration file to `config/siftify.php`.

## Basic Usage

```php
use SiftifyVendor\Siftify\Siftify;

class UserController extends Controller
{
    public function index(Request $request)
    {
        // Create a new Siftify instance
        $siftify = Siftify::for($request)
            ->filterOnModel(User::class)
            ->allowedFilters('name', 'email', 'role', 'created_at')
            ->relationships('posts', 'profile')
            ->paginate()
            ->get();
        
        // Return the filtered, sorted, and paginated results
        return $siftify->toJson();
    }
}
```

## Making Requests

With Siftify in place, you can make API requests like:

```
GET /api/users?name=John&role=admin&sort=created_at&order=desc&page=2&per_page=15
```

## Filtering with Operators

Siftify supports various operators for filtering:

```
GET /api/users?email[operator]=like&email[value]=example.com
```

Available operators:
- `=`, `!=` (Equals, Not Equals)
- `>`, `<`, `>=`, `<=` (Comparison)
- `like`, `not like` (Pattern matching)
- `in`, `not in` (Multiple values)
- `between`, `not between` (Range values)

## Filtering Relations

You can filter records based on related models:

```
GET /api/users?posts.title=Laravel&profile.country=US
```

or using alternate syntax:

```
GET /api/users?posts*title=Laravel&profile*country=US
```

## Quick start

For more detailed documentation, examples, and advanced usage, please visit [✨Quick start✨]().

## Configuration Options

The published configuration file (`config/siftify.php`) includes options for:

- Pagination settings
- Response format customization
- Meta information configuration
- Security settings
- Standard parameters

## Testing

**Warning** : test are not done yet.

```bash
composer test
```

## Contributing

Feel free to contribute to this project; you can also suggest improvements.

## Security

If you discover any security related issues, please contact me.

## License

The GNU License (GNU). Please see [License File](LICENSE.md) for more information.
