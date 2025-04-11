<?php

namespace strawberrydev\Siftify\Support;

use strawberrydev\Siftify\Siftify;
use Throwable;

class RelationshipHandler
{
    protected Siftify $siftify;
    protected WhereConditionHandler $whereConditionHandler;

    public function __construct(Siftify $siftify)
    {
        $this->siftify = $siftify;
        $this->whereConditionHandler = new WhereConditionHandler($siftify);
    }

    /**
     * Load relationships with proper field selection
     */
    public function loadRelationships(): void
    {
        try {
            $query = $this->siftify->getQuery();
            $relationships = $this->siftify->getRelationships();
            $onlyFields = $this->siftify->getOnlyFields();

            if (empty($relationships)) {
                return;
            }

            // If we have 'only' fields with relationships, we need to restrict loaded attributes
            if (!empty($onlyFields)) {
                $relationFields = $this->extractRelationFields($onlyFields);

                // Load relationships with selected fields
                foreach ($relationships as $relationship) {
                    if (isset($relationFields[$relationship])) {
                        $query->with([$relationship => function ($query) use ($relationship, $relationFields) {
                            $query->select($relationFields[$relationship]);

                            // Make sure to include the primary key and any necessary foreign keys
                            $relatedModel = $query->getModel();
                            $primaryKey = $relatedModel->getKeyName();
                            if (!in_array($primaryKey, $relationFields[$relationship])) {
                                $query->addSelect($primaryKey);
                            }
                        }]);
                    } else {
                        $query->with($relationship);
                    }
                }
            } else {
                // Load all relationships normally
                $query->with($relationships);
            }
        } catch (Throwable $e) {
            $this->siftify->addError("Error loading relationships: " . $e->getMessage());
        }
    }

    /**
     * Extract relationship fields from only fields
     */
    protected function extractRelationFields(array $onlyFields): array
    {
        $relationFields = [];

        foreach ($onlyFields as $field) {
            if (str_contains($field, '.') || str_contains($field, '*')) {
                [$relation, $column] = $this->whereConditionHandler->parseRelationshipKey($field);
                if (!isset($relationFields[$relation])) {
                    $relationFields[$relation] = [];
                }
                $relationFields[$relation][] = $column;
            }
        }

        return $relationFields;
    }

    /**
     * Apply SELECT clause to the query based on 'only' fields
     */
    public function applySelectOnly(): void
    {
        try {
            $query = $this->siftify->getQuery();
            $onlyFields = $this->siftify->getOnlyFields();
            $model = $this->siftify->getModel();

            // Handle direct model fields
            $tableFields = array_filter($onlyFields, function($field) {
                return !str_contains($field, '.') && !str_contains($field, '*');
            });

            if (!empty($tableFields)) {
                // Ensure primary key is always included
                $primaryKey = $model->getKeyName();
                if (!in_array($primaryKey, $tableFields)) {
                    $tableFields[] = $primaryKey;
                }

                // Apply direct field selection
                $query->select($tableFields);
            }

            // Relationship fields are handled via the relationship loading
        } catch (Throwable $e) {
            $this->siftify->addError("Error applying select only: " . $e->getMessage());
        }
    }
}
