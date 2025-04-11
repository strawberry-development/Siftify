<?php

namespace strawberrydev\Siftify\Support;

use strawberrydev\Siftify\Siftify;
use Throwable;

class WhereConditionHandler
{
    protected Siftify $siftify;

    public function __construct(Siftify $siftify)
    {
        $this->siftify = $siftify;
    }

    /**
     * Parse where conditions from array format into standardized structure
     */
    public function parseWhereConditions(array $conditions): array
    {
        $parsedConditions = [];

        foreach ($conditions as $condition) {
            if (!is_array($condition)) {
                continue;
            }

            $count = count($condition);

            if ($count === 2) {
                // Format: ['column', value] - use = as default operator
                $parsedConditions[] = [
                    'column' => $condition[0],
                    'operator' => '=',
                    'value' => $condition[1]
                ];
            } elseif ($count === 3) {
                // Format: ['column', 'operator', value]
                $parsedConditions[] = [
                    'column' => $condition[0],
                    'operator' => $condition[1],
                    'value' => $condition[2]
                ];
            }
        }

        return $parsedConditions;
    }

    /**
     * Apply where conditions to the query
     */
    public function applyWhereConditions(): void
    {
        try {
            $query = $this->siftify->getQuery();

            foreach ($this->siftify->getWhereConditions() as $condition) {
                if (!isset($condition['column'])) {
                    continue;
                }

                $column = $condition['column'];
                $operator = $condition['operator'] ?? '=';
                $value = $condition['value'] ?? null;

                // Handle relationship filters (contains . or *)
                if (str_contains($column, '.') || str_contains($column, '*')) {
                    $this->applyRelationshipWhereCondition($column, $operator, $value);
                } else {
                    // Direct model filter
                    $query->where($column, $operator, $value);
                }
            }
        } catch (Throwable $e) {
            $this->siftify->addError("Error applying where conditions: " . $e->getMessage());
        }
    }

    /**
     * Apply where condition to a relationship
     */
    protected function applyRelationshipWhereCondition(string $key, string $operator, $value): void
    {
        try {
            $query = $this->siftify->getQuery();
            [$relation, $column] = $this->parseRelationshipKey($key);

            $query->whereHas($relation, function ($query) use ($column, $operator, $value) {
                $query->where($column, $operator, $value);
            });
        } catch (Throwable $e) {
            $this->siftify->addError("Error applying relationship where condition: " . $e->getMessage());
        }
    }

    /**
     * Parse a relationship key into relation and column parts
     */
    public function parseRelationshipKey(string $key): array
    {
        if (str_contains($key, '*')) {
            return explode('*', $key, 2);
        }

        $parts = explode('.', $key);
        $column = array_pop($parts);
        $relation = implode('.', $parts);

        return [$relation, $column];
    }
}
