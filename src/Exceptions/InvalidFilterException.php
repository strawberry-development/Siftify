<?php

namespace SiftifyVendor\Siftify\Exceptions;

use Exception;
use Illuminate\Support\Facades\Config;
use Throwable;

class InvalidFilterException extends Exception
{
    /**
     * Create a new exception for when a filter is not allowed.
     */
    public static function filterNotAllowed(string $filter, ?array $allowedFilters = null): self
    {
        $message = "The filter '{$filter}' is not allowed.";

        if ($allowedFilters && Config::get('siftify.security.validate_all_filters', true)) {
            $message .= ' Allowed filters: ' . implode(', ', $allowedFilters);
        }

        return new static($message, 400);
    }

    /**
     * Create a new exception for invalid relationships.
     */
    public static function invalidRelationship(string $relationship, ?array $availableRelationships = null): self
    {
        $message = "The relationship '{$relationship}' does not exist or is not accessible.";

        if ($availableRelationships) {
            $message .= ' Available relationships: ' . implode(', ', $availableRelationships);
        }

        return new static($message, 400);
    }

    /**
     * Create a new exception for invalid columns.
     */
    public static function invalidColumn(string $column, ?array $availableColumns = null): self
    {
        $message = "The column '{$column}' does not exist.";

        if ($availableColumns) {
            $message .= ' Available columns: ' . implode(', ', $availableColumns);
        }

        return new static($message, 400);
    }

    /**
     * Create a new exception for invalid filter operators.
     */
    public static function invalidOperator(string $operator, ?array $allowedOperators = null): self
    {
        $message = "The operator '{$operator}' is not valid.";

        if ($allowedOperators) {
            $message .= ' Allowed operators: ' . implode(', ', $allowedOperators);
        }

        return new static($message, 400);
    }

    /**
     * Create a new exception for invalid filter values.
     */
    public static function invalidValue(string $filter, mixed $value, string $expectedType): self
    {
        $actualType = gettype($value);
        return new static(
            "Invalid value for filter '{$filter}'. Expected {$expectedType}, got {$actualType}.",
            400
        );
    }

    /**
     * Create a new exception for nested filter errors.
     */
    public static function nestedFilterError(string $filter, Throwable $previous): self
    {
        return new static(
            "Error processing nested filter '{$filter}': " . $previous->getMessage(),
            400,
            $previous
        );
    }

    /**
     * Create a new exception for unsupported filter types.
     */
    public static function unsupportedFilterType(string $type, ?array $supportedTypes = null): self
    {
        $message = "Filter type '{$type}' is not supported.";

        if ($supportedTypes) {
            $message .= ' Supported types: ' . implode(', ', $supportedTypes);
        }

        return new static($message, 400);
    }

    /**
     * Create a new exception for JSON filter parsing errors.
     */
    public static function jsonFilterError(string $filter, string $error): self
    {
        return new static(
            "Failed to parse JSON filter '{$filter}': {$error}",
            400
        );
    }

    /**
     * Create a new exception for invalid date formats.
     */
    public static function invalidDateFormat(string $filter, string $value, string $expectedFormat): self
    {
        return new static(
            "Invalid date format for filter '{$filter}'. Expected format: {$expectedFormat}, got: {$value}",
            400
        );
    }

    /**
     * Create a new exception for filter configuration errors.
     */
    public static function configurationError(string $message): self
    {
        return new static("Filter configuration error: {$message}", 500);
    }
}
