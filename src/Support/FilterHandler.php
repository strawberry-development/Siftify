<?php

namespace strawberryDevelopment\Siftify\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;
use strawberryDevelopment\Siftify\Exceptions\InvalidFilterException;
use strawberryDevelopment\Siftify\Siftify;
use Throwable;

class FilterHandler
{
    protected Siftify $siftify;

    public function __construct(Siftify $siftify)
    {
        $this->siftify = $siftify;
    }

    public function applyFilters(): void
    {
        $request = $this->siftify->getRequest();

        foreach ($request->all() as $key => $value) {
            if ($this->shouldIgnoreFilter($key)) {
                continue;
            }

            try {
                // Validate if filter is allowed
                $allowedFilters = $this->siftify->getAllowedFilters();
                $standardParameters = $this->siftify->getStandardParameters();
                if (!in_array($key, $allowedFilters) && !in_array($key, $standardParameters)) {
                    throw InvalidFilterException::filterNotAllowed($key, $allowedFilters + $standardParameters);
                }

                if ($this->isRelationshipFilter($key)) {
                    $this->applyRelationshipFilter($key, $value);
                } else {
                    $this->applyDirectFilter($key, $value);
                }
            } catch (InvalidFilterException $e) {
                // Add to errors but continue processing other filters
                $this->siftify->addError($e->getMessage());
                \Illuminate\Support\Facades\Log::warning('Siftify filter error: ' . $e->getMessage());
            } catch (Throwable $e) {
                $this->siftify->addError("Error applying filter '{$key}': " . $e->getMessage());
                \Illuminate\Support\Facades\Log::error("Error applying filter '{$key}'", [
                    'exception' => get_class($e),
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine()
                ]);
            }
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
        return in_array($key, $this->siftify->getStandardParameters()) ||
            in_array($key, $this->siftify->getIgnoredFilters());
    }

    protected function isRelationshipFilter(string $key): bool
    {
        return str_contains($key, '*') || str_contains($key, '.');
    }

    protected function applyRelationshipFilter(string $key, $value): void
    {
        [$relation, $column] = $this->parseRelationshipKey($key);
        $model = $this->siftify->getModel();

        if (Config::get('siftify.security.strict_relationship_checking', true)) {
            if (!method_exists($model, $relation)) {
                throw InvalidFilterException::invalidRelationship(
                    $relation,
                    $this->getAvailableRelationships()
                );
            }

            $relationInstance = $model->$relation();
            $relatedModel = $relationInstance->getRelated();

            if (Config::get('siftify.security.strict_column_checking', true)) {
                if (!$relatedModel->getConnection()->getSchemaBuilder()->hasColumn($relatedModel->getTable(), $column)) {
                    throw InvalidFilterException::invalidColumn(
                        $column,
                        $this->getAvailableColumns($relatedModel)
                    );
                }
            }
        }

        $query = $this->siftify->getQuery();
        $query->whereHas($relation, function ($query) use ($column, $value) {
            $this->applyFilterCondition($query, $column, $value);
        });
    }

    protected function parseRelationshipKey(string $key): array
    {
        if (str_contains($key, '*')) {
            return explode('*', $key, 2);
        }

        $parts = explode('.', $key);
        $column = array_pop($parts);
        $relation = implode('.', $parts);

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

    protected function getAvailableRelationships(): array
    {
        try {
            $model = $this->siftify->getModel();
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
