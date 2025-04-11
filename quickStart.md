# Siftify Quick Start Guide

A comprehensive filtering, sorting, and pagination package for Laravel Eloquent models.

## Table of Contents

- [Installation](#installation)
- [Basic Usage](#basic-usage)
- [Filtering](#filtering)
    - [Basic Filters](#basic-filters)
    - [Comparison Operators](#comparison-operators)
    - [Relationship Filters](#relationship-filters)
    - [Abstract Search](#abstract-search)
- [Sorting](#sorting)
- [Pagination](#pagination)
- [Response Customization](#response-customization)
    - [Field Selection](#field-selection)
    - [Meta Information](#meta-information)
    - [Appending Data](#appending-data)
- [Additional Features](#additional-features)
    - [Custom Where Conditions](#custom-where-conditions)
    - [Grouping Results](#grouping-results)
    - [Error Handling](#error-handling)
- [Security](#security)
- [API Reference](#api-reference)
- [Examples](#examples)

## Installation

Install Siftify via Composer:

```bash
composer require strawberrydev/siftify
```

### Publish Configuration (Optional)

```bash
php artisan vendor:publish --tag="siftify-config"
```

This will create a `config/siftify.php` file with default settings.

## Basic Usage

Here's a simple example of integrating Siftify in a Laravel controller:

```php
<?php

namespace App\Http\Controllers\API;

use App\Models\User;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use strawberrydev\Siftify\Siftify;

class UserController extends Controller
{
    public function index(Request $request)
    {
        return Siftify::for($request)
            ->filterOnModel(new User())
            ->allowedFilters('name', 'email', 'role', 'created_at')
            ->paginate()
            ->get()
            ->toJson();
    }
}
```

Then register your route in `routes/api.php`:

```php
Route::get('/users', [UserController::class, 'index']);
```

## Filtering

### Basic Filters

Siftify allows clients to filter data using query parameters matching your allowed filters:

```
GET /api/users?name=John
GET /api/users?email=john@example.com
```

### Comparison Operators

Siftify supports various comparison operators for more advanced filtering.

#### Modern Syntax (Recommended)

Use the `field:operator=value` format:

```
GET /api/users?name:like=john
GET /api/users?created_at:gte=2023-01-01
GET /api/users?age:gt=30
```

Available operators:

| Operator | Description | Example |
|----------|-------------|---------|
| eq | Equal (=) | name:eq=John |
| neq | Not Equal (!=) | status:neq=inactive |
| gt | Greater Than (>) | age:gt=30 |
| lt | Less Than (<) | price:lt=100 |
| gte | Greater Than or Equal (>=) | created_at:gte=2023-01-01 |
| lte | Less Than or Equal (<=) | expires_at:lte=2023-12-31 |
| like | Contains substring | name:like=john |
| nlike | Does not contain substring | name:nlike=admin |
| in | In array | status:in=active,pending |
| nin | Not in array | status:nin=deleted,blocked |
| between | Between two values | price:between=10,50 |
| nbetween | Not between two values | price:nbetween=10,50 |

#### Legacy Syntax

Siftify also supports a legacy format for backward compatibility:

```
GET /api/users?email[operator]=like&email[value]=@example.com
GET /api/users?created_at[operator]=>=&created_at[value]=2023-01-01
```

### Relationship Filters

You can filter based on related model attributes:

```php
public function index(Request $request)
{
    return Siftify::for($request)
        ->filterOnModel(new User())
        ->allowedFilters('name', 'email')
        ->relationships('posts', 'profile', 'roles')
        ->allowedFilters('posts.title', 'profile.country', 'roles.name')
        ->paginate()
        ->get()
        ->toJson();
}
```

This enables filtering by relationship attributes:

```
GET /api/users?posts.title=Laravel Tips
GET /api/users?profile.country=US
GET /api/users?roles.name:like=admin
```

You can also use the alternative syntax for relationships with the `*` separator:

```php
->allowedFilters('posts*title', 'profile*country')
```

And for nested relationships, simply use dot notation:

```php
->allowedFilters('posts.comments.content', 'orders.items.product.name')
```

### Abstract Search

Siftify provides a powerful "abstract search" feature that searches across multiple fields:

```
GET /api/users?abstract_search=john
```

This will search all allowed filters for the term "john". You can also apply abstract search programmatically:

```php
Siftify::for($request)
    ->filterOnModel(new User())
    ->allowedFilters('name', 'email', 'profile.bio')
    ->withAbstractSearch('john')
    ->get()
    ->toJson();
```

## Sorting

Specify sorting in your requests:

```
GET /api/users?sort=created_at&order=desc
```

## Pagination

Siftify provides automatic pagination:

```php
->paginate(25) // Specifies 25 items per page
```

Users can control pagination with these parameters:

```
GET /api/users?page=2&per_page=15
```

## Response Customization

### Field Selection

Return only specific fields using the `only` parameter:

```
GET /api/users?only=id,name,email,profile.country
```

### Meta Information

Siftify automatically includes useful metadata with each response. You can:

- Return only metadata with `only_meta=1`
- Return only count metadata with `meta_count_only=1`
- Exclude specific metadata fields with `meta_ignore=execution_time,payload_size`

```
GET /api/users?only_meta=1
GET /api/users?meta_count_only=1
```

### Appending Data

Add custom data to your response:

```php
Siftify::for($request)
    ->filterOnModel(new User())
    ->allowedFilters('name', 'email')
    ->append([
        'app_version' => config('app.version'),
        'timestamp' => now()->toIso8601String(),
        'environment' => app()->environment()
    ])
    ->paginate()
    ->get()
    ->toJson();
```

## Additional Features

### Custom Where Conditions

Add custom conditions to your query:

```php
Siftify::for($request)
    ->filterOnModel(new User())
    ->allowedFilters('name', 'email')
    ->withWhereConditions(
        ['role', 'admin'],                    // role = 'admin'
        ['verified_at', '!=', null],          // verified_at IS NOT NULL
        ['created_at', '>=', '2023-01-01']    // created_at >= '2023-01-01'
    )
    ->paginate()
    ->get()
    ->toJson();
```

### Grouping Results

Group results by specified fields:

```
GET /api/orders?group_by=status,customer_id
```

### Error Handling

Siftify handles errors gracefully and returns them in the response:

```json
{
    "data": [...],
    "meta": {
        "errors": [
            "Error applying filter 'invalid_column': Column not found"
        ],
        ...
    }
}
```

## Security

Siftify includes security features to prevent SQL injection and unauthorized data access:

- Strict column checking validates that filtered columns exist (configurable)
- Relationship validation ensures only valid relationships are queried
- Operator validation prevents SQL injection via malformed operators

Configure security settings in `config/siftify.php`:

```php
'security' => [
    'strict_column_checking' => true,
    'strict_relationship_checking' => true
]
```

## API Reference

### Main Methods

| Method | Description |
|--------|-------------|
| `Siftify::for(Request $request)` | Create a new Siftify instance with request |
| `filterOnModel(Model $model)` | Set the Eloquent model to filter |
| `allowedFilters(...$filters)` | Define which fields can be filtered |
| `relationships(...$relationships)` | Load relationships |
| `ignoreFilters(array $filters)` | Specify filters to ignore |
| `paginate(?int $perPage = null)` | Enable pagination |
| `withWhereConditions(...$conditions)` | Add custom where conditions |
| `withAbstractSearch(string $searchTerm)` | Apply abstract search |
| `append(array $appends)` | Add data to response |
| `apply()` | Apply filters and return query builder |
| `get()` | Get the results |
| `toArray()` | Convert response to array |
| `toJson()` | Convert response to JSON response |

## Examples

### Basic Example

```php
// UserController.php
public function index(Request $request)
{
    return Siftify::for($request)
        ->filterOnModel(new User())
        ->allowedFilters('name', 'email', 'role')
        ->paginate()
        ->get()
        ->toJson();
}
```

### Complex Example with Relationships

```php
// OrderController.php
public function index(Request $request)
{
    return Siftify::for($request)
        ->filterOnModel(new Order())
        ->allowedFilters('status', 'total', 'created_at')
        ->relationships('customer', 'items', 'items.product')
        ->allowedFilters(
            'customer.name', 
            'customer.email',
            'items.quantity',
            'items.product.name',
            'items.product.category'
        )
        ->withWhereConditions(
            ['created_at', '>=', now()->subDays(30)]
        )
        ->append([
            'currency' => 'USD',
            'generated_at' => now()->toIso8601String()
        ])
        ->paginate(25)
        ->get()
        ->toJson();
}
```
