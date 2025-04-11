<?php

namespace strawberrydev\Siftify\Support;

use strawberrydev\Siftify\Siftify;
use Throwable;

class GroupByHandler
{
    protected Siftify $siftify;
    protected WhereConditionHandler $whereConditionHandler;

    public function __construct(Siftify $siftify)
    {
        $this->siftify = $siftify;
        $this->whereConditionHandler = new WhereConditionHandler($siftify);
    }

    /**
     * Apply GROUP BY clause to the query
     */
    public function applyGroupBy(): void
    {
        try {
            $query = $this->siftify->getQuery();
            $groupByFields = $this->siftify->getGroupByFields();
            $model = $this->siftify->getModel();

            foreach ($groupByFields as $field) {
                // Check if it's a relationship field
                if (str_contains($field, '.') || str_contains($field, '*')) {
                    // For relationship fields, we need special handling
                    [$relation, $column] = $this->whereConditionHandler->parseRelationshipKey($field);

                    // Join the related table to perform the group by
                    $relationInstance = $model->$relation();
                    $relatedTable = $relationInstance->getRelated()->getTable();
                    $parentTable = $model->getTable();

                    // The specific join logic depends on the relationship type
                    // This is a simplified example for BelongsTo relations
                    $foreignKey = $relationInstance->getForeignKeyName();
                    $ownerKey = $relationInstance->getOwnerKeyName();

                    $query->join(
                        $relatedTable,
                        $parentTable . '.' . $foreignKey,
                        '=',
                        $relatedTable . '.' . $ownerKey
                    );

                    $query->groupBy($relatedTable . '.' . $column);
                } else {
                    // Simple group by for direct model fields
                    $query->groupBy($field);
                }
            }
        } catch (Throwable $e) {
            $this->siftify->addError("Error applying group by: " . $e->getMessage());
        }
    }
}
