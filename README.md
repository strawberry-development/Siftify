# Siftify

[![Latest Version on Packagist](https://img.shields.io/packagist/v/strawberrydev/siftify.svg?style=flat-square)](https://packagist.org/packages/strawberrydev/siftify)  [![License: GPL v3](https://img.shields.io/badge/License-GPLv3-blue.svg?style=flat-square)](https://www.gnu.org/licenses/gpl-3.0)

Siftify is a Laravel package designed for building robust APIs with ease. It provides a flexible and intuitive API for filtering, sorting, and paginating Eloquent models—ideal for developers who want powerful query capabilities with minimal effort.

## Installation

To install Siftify, run the following composer command:

```bash
composer require strawberrydev/siftify
```

## Publish Configuration

Publish the package's configuration file by running:

```bash
php artisan vendor:publish --tag="siftify-config"
```

This will generate the configuration file in `config/siftify.php`.

## Basic Usage

Here's an example of how to use Siftify in your controller:

```php
use strawberrydev\Siftify\Siftify;

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

You can now make API requests like:

```
GET /api/users?name=John&role=admin&sort=created_at&order=desc&page=2&per_page=15
```

## Filtering Relations

You can also filter based on related models. For example:

```
GET /api/users?posts.title=Laravel&profile.country=US
```

Or use alternate syntax:

```
GET /api/users?posts*title=Laravel&profile*country=US
```

## Quick Start

For more detailed documentation, examples, and advanced usage, check out the [Quick Start Guide](quickStart.md).

## Configuration Options

The configuration file `config/siftify.php` provides options to customize:

- Pagination settings
- Response format
- Meta information
- Security settings
- Standard parameters

## Contributing

We welcome contributions! Feel free to suggest improvements or submit PRs.

## Security

If you find any security-related issues, please get in touch directly.

## License

This project is licensed under the [GNU General Public License v3](https://www.gnu.org/licenses/gpl-3.0).