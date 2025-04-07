<?php

namespace SiftifyVendor\Siftify;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use SiftifyVendor\Siftify\Contracts\Filterable;
use SiftifyVendor\Siftify\Support\FilterHandler;
use SiftifyVendor\Siftify\Support\PaginationHandler;
use SiftifyVendor\Siftify\Support\ResponseFormatter;
use Throwable;

class Siftify implements Filterable
{
    protected Request $request;
    protected Builder $query;
    protected Model $model;

    protected array $ignoredFilters = [];
    protected array $whereConditions = [];
    protected array $allowedFilters = [];
    protected array $relationships = [];
    protected array $meta = [];
    protected array $appends = [];
    protected bool $countExecuted = false;
    protected int $totalCount = 0;
    protected array $onlyFields = [];
    protected array $metaIgnored = [];
    protected bool $metaCountOnly = false;
    protected bool $onlyMeta = false;
    protected array $groupByFields = [];

    protected array $standardParameters;
    protected float $startTime;
    protected int $payloadSize;
    protected array $errors = [];
    protected array $response;

    protected FilterHandler $filterHandler;
    protected PaginationHandler $paginationHandler;
    protected ResponseFormatter $responseFormatter;

    public function __construct(Request $request)
    {
        $this->request = $request;
        $this->standardParameters = Config::get('siftify.standard_parameters', []);
        $this->startTime = microtime(true);
        $this->payloadSize = $this->calculateRequestPayloadSize();

        // Process standard parameters
        $this->processStandardParameters();

        // Initialize handlers
        $this->filterHandler = new FilterHandler($this);
        $this->paginationHandler = new PaginationHandler($this);
        $this->responseFormatter = new ResponseFormatter($this);
    }

    /**
     * Process standard parameters from the request
     */
    protected function processStandardParameters(): void
    {
        try {
            // Process 'only' parameter - specify which fields to include in the response
            if ($this->request->has('only')) {
                $this->onlyFields = $this->parseCommaSeparatedParameter('only');
            }

            // Process 'meta_ignore' parameter - specify which meta fields to exclude
            if ($this->request->has('meta_ignore')) {
                $this->metaIgnored = $this->parseCommaSeparatedParameter('meta_ignore');
            }

            // Process 'meta_count_only' parameter - return only count meta data
            if ($this->request->has('meta_count_only')) {
                $this->metaCountOnly = true;
            }

            // Process 'only_meta' parameter - return only meta information
            if ($this->request->has('only_meta')) {
                $this->onlyMeta = true;
            }

            // Process 'group_by' parameter - group results by specified fields
            if ($this->request->has('group_by')) {
                $this->groupByFields = $this->parseCommaSeparatedParameter('group_by');
            }
        } catch (Throwable $e) {
            $this->handleException("Error processing standard parameters", $e);
        }
    }

    /**
     * Helper to parse comma-separated parameter values
     */
    protected function parseCommaSeparatedParameter(string $param): array
    {
        $value = $this->request->input($param, '');
        return array_filter(explode(',', $value));
    }

    public function filterOnModel(Model $model): self
    {
        try {
            $this->model = $model;
            $this->query = $model->newQuery();
        } catch (Throwable $e) {
            $this->handleException("Error initializing model query", $e);
        }
        return $this;
    }

    public function allowedFilters(...$filters): self
    {
        try {
            $this->allowedFilters = $filters;
        } catch (Throwable $e) {
            $this->handleException("Error setting allowed filters", $e);
        }
        return $this;
    }

    public function relationships(...$relationships): self
    {
        try {
            $this->relationships = $relationships;
        } catch (Throwable $e) {
            $this->handleException("Error setting relationships", $e);
        }
        return $this;
    }

    public function ignoreFilters(array $filters): self
    {
        try {
            $this->ignoredFilters = $filters;
        } catch (Throwable $e) {
            $this->handleException("Error setting ignored filters", $e);
        }
        return $this;
    }

    public function append(array $appends): self
    {
        try {
            $this->appends = array_merge($this->appends, $appends);
        } catch (Throwable $e) {
            $this->handleException("Error appending data", $e);
        }
        return $this;
    }

    public function apply(): Builder
    {
        try {
            $this->loadRelationships();
            $this->applyWhereConditions();
            $this->filterHandler->applyFilters();
            $this->filterHandler->applySorting();

            // Apply group by if specified
            if (!empty($this->groupByFields)) {
                $this->applyGroupBy();
            }

            // Apply field selection based on 'only' parameter
            if (!empty($this->onlyFields)) {
                $this->applySelectOnly();
            }

            // Calculate total count before pagination is applied
            if (!$this->countExecuted) {
                $this->totalCount = $this->query->toBase()->getCountForPagination();
                $this->countExecuted = true;
            }
        } catch (Throwable $e) {
            $this->handleException("Error applying filters", $e);
        }

        return $this->query;
    }

    /**
     * Apply GROUP BY clause to the query
     */
    protected function applyGroupBy(): void
    {
        try {
            foreach ($this->groupByFields as $field) {
                // Check if it's a relationship field
                if (str_contains($field, '.') || str_contains($field, '*')) {
                    // For relationship fields, we need special handling
                    [$relation, $column] = $this->parseRelationshipKey($field);

                    // Join the related table to perform the group by
                    $model = $this->model;
                    $relationInstance = $model->$relation();
                    $relatedTable = $relationInstance->getRelated()->getTable();
                    $parentTable = $model->getTable();

                    // The specific join logic depends on the relationship type
                    // This is a simplified example for BelongsTo relations
                    $foreignKey = $relationInstance->getForeignKeyName();
                    $ownerKey = $relationInstance->getOwnerKeyName();

                    $this->query->join(
                        $relatedTable,
                        $parentTable . '.' . $foreignKey,
                        '=',
                        $relatedTable . '.' . $ownerKey
                    );

                    $this->query->groupBy($relatedTable . '.' . $column);
                } else {
                    // Simple group by for direct model fields
                    $this->query->groupBy($field);
                }
            }
        } catch (Throwable $e) {
            $this->handleException("Error applying group by", $e);
        }
    }

    /**
     * Apply SELECT clause to the query based on 'only' fields
     */
    protected function applySelectOnly(): void
    {
        try {
            // Handle direct model fields
            $tableFields = array_filter($this->onlyFields, function($field) {
                return !str_contains($field, '.') && !str_contains($field, '*');
            });

            if (!empty($tableFields)) {
                // Ensure primary key is always included
                $primaryKey = $this->model->getKeyName();
                if (!in_array($primaryKey, $tableFields)) {
                    $tableFields[] = $primaryKey;
                }

                // Apply direct field selection
                $this->query->select($tableFields);
            }

            // Relationship fields are handled via the relationship loading
        } catch (Throwable $e) {
            $this->handleException("Error applying select only", $e);
        }
    }

    protected function applyWhereConditions(): void
    {
        foreach ($this->whereConditions as $condition) {
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
                $this->query->where($column, $operator, $value);
            }
        }
    }

    protected function applyRelationshipWhereCondition(string $key, string $operator, $value): void
    {
        [$relation, $column] = $this->parseRelationshipKey($key);

        $this->query->whereHas($relation, function ($query) use ($column, $operator, $value) {
            $query->where($column, $operator, $value);
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

    public function paginate(?int $perPage = null): Filterable
    {
        return $this->paginationHandler->paginate($perPage);
    }

    public function withWhereConditions(...$conditions): self
    {
        try {
            foreach ($conditions as $condition) {
                if (!is_array($condition)) {
                    continue;
                }

                $count = count($condition);

                if ($count === 2) {
                    // Format: ['column', value] - use = as default operator
                    $this->whereConditions[] = [
                        'column' => $condition[0],
                        'operator' => '=',
                        'value' => $condition[1]
                    ];
                } elseif ($count === 3) {
                    // Format: ['column', 'operator', value]
                    $this->whereConditions[] = [
                        'column' => $condition[0],
                        'operator' => $condition[1],
                        'value' => $condition[2]
                    ];
                }
            }
        } catch (Throwable $e) {
            $this->handleException("Error setting where conditions", $e);
        }
        return $this;
    }

    public function get(): Siftify
    {
        try {
            if (isset($this->meta['paginator'])) {
                $results = $this->meta['paginator'];
            } elseif (isset($this->meta['results'])) {
                $results = $this->meta['results'];
            } else {
                $results = $this->apply()->get();
            }

            $this->response = $this->responseFormatter->formatResponse($results);

            return $this;
        } catch (Throwable $e) {
            $this->handleException("Error retrieving results", $e);
            $this->response = $this->responseFormatter->formatErrorResponse();

            return $this;
        }
    }

    public function toArray(): array
    {
        return $this->response;
    }

    public function toJson(): JsonResponse
    {
        return response()->json($this->response);
    }

    protected function loadRelationships(): void
    {
        if (!empty($this->relationships)) {
            // If we have 'only' fields with relationships, we need to restrict loaded attributes
            if (!empty($this->onlyFields)) {
                $relationFields = [];
                foreach ($this->onlyFields as $field) {
                    if (str_contains($field, '.') || str_contains($field, '*')) {
                        [$relation, $column] = $this->parseRelationshipKey($field);
                        if (!isset($relationFields[$relation])) {
                            $relationFields[$relation] = [];
                        }
                        $relationFields[$relation][] = $column;
                    }
                }

                // Load relationships with selected fields
                foreach ($this->relationships as $relationship) {
                    if (isset($relationFields[$relationship])) {
                        $this->query->with([$relationship => function ($query) use ($relationship, $relationFields) {
                            $query->select($relationFields[$relationship]);

                            // Make sure to include the primary key and any necessary foreign keys
                            $relatedModel = $query->getModel();
                            $primaryKey = $relatedModel->getKeyName();
                            if (!in_array($primaryKey, $relationFields[$relationship])) {
                                $query->addSelect($primaryKey);
                            }
                        }]);
                    } else {
                        $this->query->with($relationship);
                    }
                }
            } else {
                // Load all relationships normally
                $this->query->with($this->relationships);
            }
        }
    }

    protected function calculateRequestPayloadSize(): int
    {
        try {
            $content = $this->request->getContent();
            if (!empty($content)) {
                return strlen($content);
            }

            $queryParams = $this->request->query();
            if (!empty($queryParams)) {
                return strlen(http_build_query($queryParams));
            }
        } catch (Throwable $e) {
            Log::warning('Error calculating request payload size: ' . $e->getMessage());
        }

        return 0;
    }

    protected function handleException(string $context, Throwable $e): void
    {
        $errorMessage = $context . ": " . $e->getMessage();
        $this->errors[] = $errorMessage;

        // Log the error with full details for debugging
        \Illuminate\Support\Facades\Log::error($errorMessage, [
            'exception' => get_class($e),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString(),
            'model' => isset($this->model) ? get_class($this->model) : null,
            'request_path' => $this->request->path(),
            'request_method' => $this->request->method(),
        ]);
    }

    // Getters for internal properties (used by handlers)
    public function getRequest(): Request
    {
        return $this->request;
    }

    public function getQuery(): Builder
    {
        return $this->query;
    }

    public function getModel(): Model
    {
        return $this->model;
    }

    public function getIgnoredFilters(): array
    {
        return $this->ignoredFilters;
    }

    public function getAllowedFilters(): array
    {
        return $this->allowedFilters;
    }

    public function getRelationships(): array
    {
        return $this->relationships;
    }

    public function getWhereConditions(): array
    {
        return $this->whereConditions;
    }

    public function getStandardParameters(): array
    {
        return $this->standardParameters;
    }

    public function getErrors(): array
    {
        return $this->errors;
    }

    public function getAppends(): array
    {
        return $this->appends;
    }

    public function getMeta(): array
    {
        return $this->meta;
    }

    public function getStartTime(): float
    {
        return $this->startTime;
    }

    public function getPayloadSize(): int
    {
        return $this->payloadSize;
    }

    public function getTotalCount(): int
    {
        return $this->totalCount;
    }

    public function isCountExecuted(): bool
    {
        return $this->countExecuted;
    }

    public function getOnlyFields(): array
    {
        return $this->onlyFields;
    }

    public function getMetaIgnored(): array
    {
        return $this->metaIgnored;
    }

    public function isMetaCountOnly(): bool
    {
        return $this->metaCountOnly;
    }

    public function isOnlyMeta(): bool
    {
        return $this->onlyMeta;
    }

    public function getGroupByFields(): array
    {
        return $this->groupByFields;
    }

    // Setters for properties that need to be modified by handlers
    public function setQuery(Builder $query): void
    {
        $this->query = $query;
    }

    public function setMeta(array $meta): void
    {
        $this->meta = $meta;
    }

    public function addError(string $error): void
    {
        $this->errors[] = $error;
    }

    public function setTotalCount(int $count): void
    {
        $this->totalCount = $count;
        $this->countExecuted = true;
    }

    public static function for(Request $request): self
    {
        return new static($request);
    }
}
