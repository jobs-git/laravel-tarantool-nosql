# Laravel Tarantool NoSQL Driver

[![Software License](https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square)](LICENSE)
[![PHP Version](https://img.shields.io/badge/php-%5E8.1-blue.svg?style=flat-square)](https://php.net)
[![Laravel Version](https://img.shields.io/badge/laravel-%5E10.0%20%7C%20%5E11.0%20%7C%20%5E12.0-red.svg?style=flat-square)](https://laravel.com)

A high-performance NoSQL Tarantool database driver for Laravel Eloquent, allowing you to leverage Tarantool spaces and tuples seamlessly within your Laravel applications.

## Requirements

- **PHP:** ^8.1
- **Laravel:** ^10.0 | ^11.0 | ^12.0
- **Tarantool PHP Client:** ^0.9.0 | ^0.10 | ^0.11
- **Tarantool Server:** >= 3.x

## Installation

Since this package is currently developed locally, you can require it in your main Laravel application's `composer.json` by defining a path repository:

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "../path-to-your-local-laravel-tarantool-nosql"
        }
    ]
}