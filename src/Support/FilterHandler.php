<?php

namespace strawberrydev\Siftify\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;
use strawberrydev\Siftify\Exceptions\InvalidFilterException;
use strawberrydev\Siftify\Siftify;
use Throwable;

class FilterHandler
{
    protected Siftify $siftify;

    // Mapping of shorthand operators to their actual database operators
    protected array $operatorMap = [
        'eq' => '=',
        'neq' => '!=',
        'gt' => '>',
        'lt' => '<',
        'gte' => '>=',
        'lte' => '<=',
        'like' => 'like',
        'nlike' => 'not like',
        'in' => 'in',
        'nin' => 'not in',
        'between' => 'between',
        'nbetween' => 'not between',
    ];

    public function __construct(Siftify $siftify)
    {
        $this->siftify = $siftify;
    }

    public function applyFilters(): void
    {
        $request = $this->siftify->getRequest();
        $requestData = $request->all();
        $allowedFilters = $this->siftify->getAllowedFilters();

        // Check for abstract search parameter first
        if ($request->has('abstract_search') && !empty($allowedFilters)) {
            $this->applyAbstractSearch($request->input('abstract_search'));
        }

        // Create a map of underscore keys to dot notation keys for relationship filters
        $filterMap = [];
        foreach ($allowedFilters as $filter) {
            if (str_contains($filter, '.') || str_contains($filter, '*')) {
                $underscoreKey = str_replace(['.', '*'], '_', $filter);
                $filterMap[$underscoreKey] = $filter;
            }
        }

        foreach ($requestData as $key => $value) {
            // Skip abstract_search and other standard params as we've already processed them
            if ($key === 'abstract_search' || $this->shouldIgnoreFilter($key)) {
                continue;
            }

            try {
                $originalKey = $key;
                $operator = '='; // Default operator
                $filterValue = $value;

                // Check for new operator syntax: field:operator=value
                if (str_contains($key, ':')) {
                    [$field, $operatorKey] = explode(':', $key, 2);

                    // Validate operator
                    if (isset($this->operatorMap[$operatorKey])) {
                        $key = $field;
                        $operator = $this->operatorMap[$operatorKey];
                    } else {
                        throw InvalidFilterException::invalidOperator(
                            $operatorKey,
                            array_keys($this->operatorMap)
                        );
                    }
                } else {
                    // Handle legacy format: field[operator] and field[value]
                    // This maintains backward compatibility
                    if (is_array($value) && isset($value['operator']) && isset($value['value'])) {
                        $operator = $value['operator'];
                        $filterValue = $value['value'];
                    }
                }

                // Check if this is a transformed relationship key and map it back
                if (isset($filterMap[$key])) {
                    $key = $filterMap[$key];
                }

                // Validate if filter is allowed
                $standardParameters = $this->siftify->getStandardParameters();
                if (!in_array($key, $allowedFilters) && !in_array($key, $standardParameters)) {
                    throw InvalidFilterException::filterNotAllowed($key, array_merge($allowedFilters, $standardParameters));
                }

                if (str_contains($key, '.') || str_contains($key, '*')) {
                    if ($operator !== '=') {
                        // Apply relationship filter with operator
                        $this->applyRelationshipFilterWithOperator($key, $operator, $filterValue);
                    } else {
                        // Use the original method for backward compatibility
                        $this->applyRelationshipFilter($key, $filterValue);
                    }
                } else {
                    if ($operator !== '=') {
                        // Apply direct filter with operator
                        $this->applyDirectFilterWithOperator($key, $operator, $filterValue);
                    } else {
                        // Use the original method for backward compatibility
                        $this->applyDirectFilter($key, $filterValue);
                    }
                }
            } catch (InvalidFilterException $e) {
                // Add to errors but continue processing other filters
                $this->siftify->addError($e->getMessage());
                \Illuminate\Support\Facades\Log::warning('Siftify filter error: ' . $e->getMessage());
            } catch (Throwable $e) {
                $this->siftify->addError("Error applying filter '{$originalKey}': " . $e->getMessage());
                \Illuminate\Support\Facades\Log::error("Error applying filter '{$originalKey}'", [
                    'exception' => get_class($e),
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine()
                ]);
            }
        }
    }

    /**
     * Apply a direct filter with a specific operator
     *
     * @param string $key The column name
     * @param string $operator The operator to use
     * @param mixed $value The value to filter by
     * @return void
     */
    protected function applyDirectFilterWithOperator(string $key, string $operator, $value): void
    {
        $model = $this->siftify->getModel();

        if (Config::get('siftify.security.strict_column_checking', true)) {
            if (!$model->getConnection()->getSchemaBuilder()->hasColumn($model->getTable(), $key)) {
                throw InvalidFilterException::invalidColumn(
                    $key,
                    $this->getAvailableColumns()
                );
            }
        }

        $query = $this->siftify->getQuery();
        $this->applyOperatorCondition($query, $key, $operator, $value);
    }

    /**
     * Apply a relationship filter with a specific operator
     *
     * @param string $key The relationship.column
     * @param string $operator The operator to use
     * @param mixed $value The value to filter by
     * @return void
     */
    protected function applyRelationshipFilterWithOperator(string $key, string $operator, $value): void
    {
        [$relationPath, $column] = $this->parseRelationshipKey($key);
        $model = $this->siftify->getModel();
        $query = $this->siftify->getQuery();

        // Check if this is a nested relationship (contains dots)
        $relations = explode('.', $relationPath);

        // For strict checking, validate each relationship in the chain
        if (Config::get('siftify.security.strict_relationship_checking', true)) {
            $currentModel = $model;
            $relationChain = [];

            foreach ($relations as $relationName) {
                $relationChain[] = $relationName;
                $currentRelationPath = implode('.', $relationChain);

                if (!method_exists($currentModel, $relationName)) {
                    throw InvalidFilterException::invalidRelationship(
                        $currentRelationPath,
                        $this->getAvailableRelationships($currentModel)
                    );
                }

                $relationInstance = $currentModel->$relationName();
                $currentModel = $relationInstance->getRelated();
            }

            // After validating relationships, validate the column on the final related model
            if (Config::get('siftify.security.strict_column_checking', true)) {
                if (!$currentModel->getConnection()->getSchemaBuilder()->hasColumn($currentModel->getTable(), $column)) {
                    throw InvalidFilterException::invalidColumn(
                        $column,
                        $this->getAvailableColumns($currentModel)
                    );
                }
            }
        }

        // Apply the whereHas with nested relationship support
        $query->whereHas($relations[0], function ($q) use ($relations, $column, $operator, $value) {
            // Remove the first relation since we're already inside it
            $nestedRelations = array_slice($relations, 1);

            if (empty($nestedRelations)) {
                // Direct relationship
                $this->applyOperatorCondition($q, $column, $operator, $value);
            } else {
                // Nested relationships
                $this->applyNestedRelationshipFilterWithOperator($q, $nestedRelations, $column, $operator, $value);
            }
        });
    }

    /**
     * Apply nested relationship filter with a specific operator
     */
    protected function applyNestedRelationshipFilterWithOperator(Builder $query, array $relations, string $column, string $operator, $value): void
    {
        $relation = array_shift($relations);

        if (empty($relations)) {
            // Last level of nesting
            $query->whereHas($relation, function ($q) use ($column, $operator, $value) {
                $this->applyOperatorCondition($q, $column, $operator, $value);
            });
        } else {
            // Continue nesting
            $query->whereHas($relation, function ($q) use ($relations, $column, $operator, $value) {
                $this->applyNestedRelationshipFilterWithOperator($q, $relations, $column, $operator, $value);
            });
        }
    }

    /**
     * Apply abstract search across all allowed filters
     *
     * @param string $searchTerm The search term to apply
     * @return void
     */
    protected function applyAbstractSearch(string $searchTerm): void
    {
        if (empty($searchTerm)) {
            return;
        }

        try {
            $allowedFilters = $this->siftify->getAllowedFilters();
            $query = $this->siftify->getQuery();
            $model = $this->siftify->getModel();

            // We need to group the filters by direct model fields and relationship fields
            $directFilters = [];
            $relationshipFilters = [];

            foreach ($allowedFilters as $filter) {
                if (str_contains($filter, '.') || str_contains($filter, '*')) {
                    $relationshipFilters[] = $filter;
                } else {
                    // Validate column if strict checking is enabled
                    if (Config::get('siftify.security.strict_column_checking', true)) {
                        if ($model->getConnection()->getSchemaBuilder()->hasColumn($model->getTable(), $filter)) {
                            $directFilters[] = $filter;
                        }
                    } else {
                        $directFilters[] = $filter;
                    }
                }
            }

            // Apply search using OR conditions
            $query->where(function ($q) use ($directFilters, $relationshipFilters, $searchTerm) {
                // Apply for direct model fields
                foreach ($directFilters as $filter) {
                    $q->orWhere($filter, 'LIKE', '%' . $searchTerm . '%');
                }

                // Apply for relationship fields
                foreach ($relationshipFilters as $filter) {
                    [$relationPath, $column] = $this->parseRelationshipKey($filter);

                    // Split relationship path for nested relationships
                    $relations = explode('.', $relationPath);

                    $q->orWhereHas($relations[0], function ($subQuery) use ($relations, $column, $searchTerm) {
                        // Remove the first relation since we're already inside it
                        $nestedRelations = array_slice($relations, 1);

                        if (empty($nestedRelations)) {
                            // Direct relationship
                            $subQuery->where($column, 'LIKE', '%' . $searchTerm . '%');
                        } else {
                            // Handle nested relationships
                            $this->applyNestedRelationshipAbstractSearch($subQuery, $nestedRelations, $column, $searchTerm);
                        }
                    });
                }
            });
        } catch (Throwable $e) {
            $this->siftify->addError("Error applying abstract search: " . $e->getMessage());
            \Illuminate\Support\Facades\Log::error("Error applying abstract search", [
                'exception' => get_class($e),
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'search_term' => $searchTerm
            ]);
        }
    }

    /**
     * Apply nested relationship abstract search
     *
     * @param Builder $query The query builder
     * @param array $relations The nested relations array
     * @param string $column The column to search
     * @param string $searchTerm The search term
     * @return void
     */
    protected function applyNestedRelationshipAbstractSearch(Builder $query, array $relations, string $column, string $searchTerm): void
    {
        $relation = array_shift($relations);

        if (empty($relations)) {
            // Last level of nesting
            $query->whereHas($relation, function ($q) use ($column, $searchTerm) {
                $q->where($column, 'LIKE', '%' . $searchTerm . '%');
            });
        } else {
            // Continue nesting
            $query->whereHas($relation, function ($q) use ($relations, $column, $searchTerm) {
                $this->applyNestedRelationshipAbstractSearch($q, $relations, $column, $searchTerm);
            });
        }
    }

    public function applySorting(): void
    {
        $request = $this->siftify->getRequest();

        if (!$request->has('sort')) {
            return;
        }

        try {
            $column = $request->input('sort');
            $direction = strtolower($request->input('order', 'asc'));

            if (!in_array($direction, ['asc', 'desc'])) {
                $direction = 'asc';
            }

            if ($this->isRelationshipFilter($column)) {
                $this->applyRelationshipSort($column, $direction);
            } else {
                // Validate column if strict checking is enabled
                if (Config::get('siftify.security.strict_column_checking', true)) {
                    $model = $this->siftify->getModel();
                    if (!$model->getConnection()->getSchemaBuilder()->hasColumn($model->getTable(), $column)) {
                        throw InvalidFilterException::invalidColumn(
                            $column,
                            $this->getAvailableColumns()
                        );
                    }
                }

                $query = $this->siftify->getQuery();
                $query->orderBy($column, $direction);
            }
        } catch (Throwable $e) {
            $this->siftify->addError("Error applying sorting: " . $e->getMessage());
            \Illuminate\Support\Facades\Log::error("Error applying sorting", [
                'exception' => get_class($e),
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
        }
    }

    protected function shouldIgnoreFilter(string $key): bool
    {
        // Add abstract_search to standard parameters if not already included
        $standardParameters = $this->siftify->getStandardParameters();
        if (!in_array('abstract_search', $standardParameters)) {
            $standardParameters[] = 'abstract_search';
        }

        // For the new format field:operator, we need to check the base field name
        if (str_contains($key, ':')) {
            $field = explode(':', $key, 2)[0];
            return in_array($field, $this->siftify->getIgnoredFilters());
        }

        return in_array($key, $standardParameters) ||
            in_array($key, $this->siftify->getIgnoredFilters());
    }

    protected function isRelationshipFilter(string $key): bool
    {
        // For the new format field:operator, we need to extract the field name
        if (str_contains($key, ':')) {
            $field = explode(':', $key, 2)[0];
            return str_contains($field, '*') || str_contains($field, '.');
        }

        return str_contains($key, '*') || str_contains($key, '.');
    }

    protected function applyRelationshipFilter(string $key, $value): void
    {
        [$relationPath, $column] = $this->parseRelationshipKey($key);
        $model = $this->siftify->getModel();
        $query = $this->siftify->getQuery();

        // Check if this is a nested relationship (contains dots)
        $relations = explode('.', $relationPath);

        // For strict checking, validate each relationship in the chain
        if (Config::get('siftify.security.strict_relationship_checking', true)) {
            $currentModel = $model;
            $relationChain = [];

            foreach ($relations as $relationName) {
                $relationChain[] = $relationName;
                $currentRelationPath = implode('.', $relationChain);

                if (!method_exists($currentModel, $relationName)) {
                    throw InvalidFilterException::invalidRelationship(
                        $currentRelationPath,
                        $this->getAvailableRelationships($currentModel)
                    );
                }

                $relationInstance = $currentModel->$relationName();
                $currentModel = $relationInstance->getRelated();
            }

            // After validating relationships, validate the column on the final related model
            if (Config::get('siftify.security.strict_column_checking', true)) {
                if (!$currentModel->getConnection()->getSchemaBuilder()->hasColumn($currentModel->getTable(), $column)) {
                    throw InvalidFilterException::invalidColumn(
                        $column,
                        $this->getAvailableColumns($currentModel)
                    );
                }
            }
        }

        // Apply the whereHas with nested relationship support
        $query->whereHas($relations[0], function ($q) use ($relations, $column, $value) {
            // Remove the first relation since we're already inside it
            $nestedRelations = array_slice($relations, 1);

            if (empty($nestedRelations)) {
                // Direct relationship
                $this->applyFilterCondition($q, $column, $value);
            } else {
                // Nested relationships
                $this->applyNestedRelationshipFilter($q, $nestedRelations, $column, $value);
            }
        });
    }

    protected function applyNestedRelationshipFilter(Builder $query, array $relations, string $column, $value): void
    {
        $relation = array_shift($relations);

        if (empty($relations)) {
            // Last level of nesting
            $query->whereHas($relation, function ($q) use ($column, $value) {
                $this->applyFilterCondition($q, $column, $value);
            });
        } else {
            // Continue nesting
            $query->whereHas($relation, function ($q) use ($relations, $column, $value) {
                $this->applyNestedRelationshipFilter($q, $relations, $column, $value);
            });
        }
    }

    protected function parseRelationshipKey(string $key): array
    {
        // Handle the new format field:operator by extracting the field first
        if (str_contains($key, ':')) {
            $field = explode(':', $key, 2)[0];
            return $this->parseRelationshipKeyInternal($field);
        }

        return $this->parseRelationshipKeyInternal($key);
    }

    /**
     * Internal method to parse relationship key
     */
    protected function parseRelationshipKeyInternal(string $key): array
    {
        if (str_contains($key, '*')) {
            // Handle the asterisk case
            list($relation, $column) = explode('*', $key, 2);
            return [$relation, $column];
        }

        // For dot notation
        $parts = explode('.', $key);
        $column = array_pop($parts); // Last part is the column name
        $relation = implode('.', $parts); // The rest is the relation path

        return [$relation, $column];
    }

    protected function applyDirectFilter(string $key, $value): void
    {
        $model = $this->siftify->getModel();

        if (Config::get('siftify.security.strict_column_checking', true)) {
            if (!$model->getConnection()->getSchemaBuilder()->hasColumn($model->getTable(), $key)) {
                throw InvalidFilterException::invalidColumn(
                    $key,
                    $this->getAvailableColumns()
                );
            }
        }

        $query = $this->siftify->getQuery();
        $this->applyFilterCondition($query, $key, $value);
    }

    /**
     * @throws InvalidFilterException
     */
    protected function applyFilterCondition(Builder $query, string $column, $value): void
    {
        if (is_array($value)) {
            if (isset($value['operator']) && isset($value['value'])) {
                $this->applyOperatorCondition($query, $column, $value['operator'], $value['value']);
            } else {
                $query->whereIn($column, $value);
            }
        } elseif ($value === 'null' || $value === 'NULL') {
            $query->whereNull($column);
        } elseif ($value === '!null' || $value === '!NULL') {
            $query->whereNotNull($column);
        } else {
            $query->where($column, $value);
        }
    }

    protected function applyOperatorCondition(Builder $query, string $column, string $operator, $value): void
    {
        $validOperators = ['=', '!=', '>', '<', '>=', '<=', 'like', 'not like', 'in', 'not in', 'between', 'not between'];

        if (!in_array(strtolower($operator), $validOperators)) {
            throw InvalidFilterException::invalidOperator($operator, $validOperators);
        }

        switch (strtolower($operator)) {
            case 'like':
            case 'not like':
                $query->where($column, $operator, '%'.$value.'%');
                break;
            case 'in':
            case 'not in':
                $query->whereIn($column, (array)$value, 'and', $operator === 'not in');
                break;
            case 'between':
                $query->whereBetween($column, (array)$value);
                break;
            case 'not between':
                $query->whereNotBetween($column, (array)$value);
                break;
            default:
                $query->where($column, $operator, $value);
        }
    }

    protected function applyRelationshipSort(string $column, string $direction): void
    {
        [$relation, $column] = $this->parseRelationshipKey($column);
        $model = $this->siftify->getModel();

        if (Config::get('siftify.security.strict_relationship_checking', true)) {
            if (!method_exists($model, $relation)) {
                throw InvalidFilterException::invalidRelationship(
                    $relation,
                    $this->getAvailableRelationships()
                );
            }
        }

        $query = $this->siftify->getQuery();
        $query->with([$relation => function ($query) use ($column, $direction) {
            $query->orderBy($column, $direction);
        }]);
    }

    protected function getAvailableColumns(?Model $model = null): array
    {
        try {
            $model = $model ?? $this->siftify->getModel();
            return $model->getConnection()
                ->getSchemaBuilder()
                ->getColumnListing($model->getTable());
        } catch (Throwable $e) {
            \Illuminate\Support\Facades\Log::error("Error getting available columns", [
                'exception' => get_class($e),
                'message' => $e->getMessage()
            ]);
            return [];
        }
    }

    protected function getAvailableRelationships(?Model $model = null): array
    {
        try {
            $model = $model ?? $this->siftify->getModel();
            $methods = get_class_methods($model);
            if (!is_array($methods)) {
                return [];
            }

            return array_filter($methods, function ($method) use ($model) {
                if (!method_exists($model, $method)) {
                    return false;
                }

                try {
                    $relation = $model->$method();
                    return $relation instanceof \Illuminate\Database\Eloquent\Relations\Relation;
                } catch (Throwable $e) {
                    return false;
                }
            });
        } catch (Throwable $e) {
            \Illuminate\Support\Facades\Log::error("Error getting available relationships", [
                'exception' => get_class($e),
                'message' => $e->getMessage()
            ]);
            return [];
        }
    }
}
