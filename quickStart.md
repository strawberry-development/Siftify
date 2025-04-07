# Siftify Quick Start Guide

This guide will help you get started with Siftify in a Laravel application.

## 1. Installation

First, install Siftify via Composer:

```bash
composer require vendor/siftify
```

## 2. Publish Configuration (Optional)

```bash
php artisan vendor:publish --tag="siftify-config"
```

This will create a `config/siftify.php` file with default settings.

## 3. Basic Implementation

Let's create a simple API endpoint for a `User` model:

### Controller Implementation

```php
<?php

namespace App\Http\Controllers\API;

use App\Models\User;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use strawberryDevelopment\Siftify\Siftify;

class UserController extends Controller
{
    public function index(Request $request)
    {
        return Siftify::for($request)
            ->filterOnModel(User::class)
            ->allowedFilters('name', 'email', 'role', 'created_at')
            ->paginate()
            ->get()
            ->toJson();
    }
}
```

### Route Registration

Add the route in your `routes/api.php` file:

```php
Route::get('/users', [App\Http\Controllers\API\UserController::class, 'index']);
```

### Testing Your API

Now you can make requests to your API with various filters:

```
GET /api/users?name=John
GET /api/users?email[operator]=like&email[value]=@example.com
GET /api/users?role=admin&sort=created_at&order=desc
GET /api/users?created_at[operator]=>=&created_at[value]=2023-01-01
```

## 4. Adding Relationship Filters

If you want to filter users based on their relationships:

```php
public function index(Request $request)
{
    return Siftify::for($request)
        ->filterOnModel(User::class)
        ->allowedFilters('name', 'email', 'role', 'created_at')
        ->relationships('posts', 'profile')
        ->allowedFilters('posts.title', 'profile.country')
        ->paginate()
        ->get()
        ->toJson();
}
```

Now you can filter by relationship attributes:

```
GET /api/users?posts.title=Laravel Tips
GET /api/users?profile.country=US
```

## 5. Custom Where Conditions

You can add custom where conditions:

```php
public function index(Request $request)
{
    return Siftify::for($request)
        ->filterOnModel(User::class)
        ->allowedFilters('name', 'email')
        ->withWhereConditions(
            ['role', 'admin'],                    // role = 'admin'
            ['verified_at', '!=', null],          // verified_at IS NOT NULL
            ['created_at', '>=', '2023-01-01']    // created_at >= '2023-01-01'
        )
        ->paginate()
        ->get()
        ->toJson();
}
```

## 6. Customizing Response Format

You can append additional data to your response:

```php
public function index(Request $request)
{
    return Siftify::for($request)
        ->filterOnModel(User::class)
        ->allowedFilters('name', 'email')
        ->append([
            'app_version' => config('app.version'),
            'timestamp' => now()->toIso8601String()
        ])
        ->paginate()
        ->get()
        ->toJson();
}
```

## 7. Using Only Specific Fields

If you want to return only specific fields:

```
GET /api/users?only=id,name,email
```

## 8. Getting Only Count

If you need only the count of matching records:

```
GET /api/users?meta_count_only=1
```
