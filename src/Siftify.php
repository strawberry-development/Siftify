<?php

namespace strawberrydev\Siftify;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use strawberrydev\Siftify\Contracts\Filterable;
use strawberrydev\Siftify\Support\FilterHandler;
use strawberrydev\Siftify\Support\GroupByHandler;
use strawberrydev\Siftify\Support\PaginationHandler;
use strawberrydev\Siftify\Support\ParameterParser;
use strawberrydev\Siftify\Support\RelationshipHandler;
use strawberrydev\Siftify\Support\ResponseFormatter;
use strawberrydev\Siftify\Support\WhereConditionHandler;
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
    protected ?int $resultLimit = null;
    protected array $orderByFields = [];

    protected array $standardParameters;
    protected float $startTime;
    protected int $payloadSize;
    protected array $errors = [];
    protected array $response;

    // Support handlers
    protected FilterHandler $filterHandler;
    protected PaginationHandler $paginationHandler;
    protected ResponseFormatter $responseFormatter;
    protected RelationshipHandler $relationshipHandler;
    protected WhereConditionHandler $whereConditionHandler;
    protected GroupByHandler $groupByHandler;
    protected ParameterParser $parameterParser;

    public function __construct(Request $request)
    {
        $this->request = $request;
        $this->startTime = microtime(true);
        $this->payloadSize = $this->calculateRequestPayloadSize();

        // Get default standard parameters from config
        $this->standardParameters = Config::get('siftify.standard_parameters', []);

        // Ensure abstract_search is included in standard parameters
        if (!in_array('abstract_search', $this->standardParameters)) {
            $this->standardParameters[] = 'abstract_search';
        }

        // Initialize handlers
        $this->initializeHandlers();

        // Process standard parameters
        $this->parameterParser->processStandardParameters();
    }

    /**
     * Initialize all handlers
     */
    protected function initializeHandlers(): void
    {
        $this->filterHandler = new FilterHandler($this);
        $this->paginationHandler = new PaginationHandler($this);
        $this->responseFormatter = new ResponseFormatter($this);
        $this->relationshipHandler = new RelationshipHandler($this);
        $this->whereConditionHandler = new WhereConditionHandler($this);
        $this->groupByHandler = new GroupByHandler($this);
        $this->parameterParser = new ParameterParser($this);
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

    /**
     * Limit the number of results returned
     *
     * @param int $limit Maximum number of records to return
     * @return $this
     */
    public function limit(int $limit): self
    {
        try {
            if ($limit <= 0) {
                throw new \InvalidArgumentException("Limit must be a positive integer");
            }
            $this->resultLimit = $limit;
        } catch (Throwable $e) {
            $this->handleException("Error setting result limit", $e);
        }
        return $this;
    }

    /**
     * Add an ORDER BY clause to the query
     *
     * @param string $column The column to sort by
     * @param string $direction The direction to sort (asc or desc)
     * @return $this
     */
    public function orderBy(string $column, string $direction = 'asc'): self
    {
        try {
            // Normalize direction
            $direction = strtolower($direction);

            // Validate direction
            if (!in_array($direction, ['asc', 'desc'])) {
                throw new \InvalidArgumentException("Direction must be 'asc' or 'desc'");
            }

            $this->orderByFields[] = [
                'column' => $column,
                'direction' => $direction
            ];
        } catch (Throwable $e) {
            $this->handleException("Error setting order by", $e);
        }
        return $this;
    }

    /**
     * Add a descending ORDER BY clause to the query
     *
     * @param string $column The column to sort by
     * @return $this
     */
    public function orderByDesc(string $column): self
    {
        return $this->orderBy($column, 'desc');
    }

    /**
     * Apply sorts to the query
     *
     * @return void
     */
    protected function applyOrderBy(): void
    {
        try {
            foreach ($this->orderByFields as $orderBy) {
                $this->query->orderBy($orderBy['column'], $orderBy['direction']);
            }
        } catch (Throwable $e) {
            $this->handleException("Error applying order by", $e);
        }
    }

    public function apply(): Builder
    {
        try {
            $this->relationshipHandler->loadRelationships();
            $this->whereConditionHandler->applyWhereConditions();
            $this->filterHandler->applyFilters();
            $this->filterHandler->applySorting();

            // Apply custom order by if specified
            if (!empty($this->orderByFields)) {
                $this->applyOrderBy();
            }

            // Apply group by if specified
            if (!empty($this->groupByFields)) {
                $this->groupByHandler->applyGroupBy();
            }

            // Apply field selection based on 'only' parameter
            if (!empty($this->onlyFields)) {
                $this->relationshipHandler->applySelectOnly();
            }

            // Apply limit if specified
            if ($this->resultLimit !== null) {
                $this->query->limit($this->resultLimit);
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

    public function paginate(?int $perPage = null): Filterable
    {
        return $this->paginationHandler->paginate($perPage);
    }

    public function withWhereConditions(...$conditions): self
    {
        try {
            $this->whereConditions = $this->whereConditionHandler->parseWhereConditions($conditions);
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
        Log::error($errorMessage, [
            'exception' => get_class($e),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString(),
            'model' => isset($this->model) ? get_class($this->model) : null,
            'request_path' => $this->request->path(),
            'request_method' => $this->request->method(),
        ]);
    }

    /**
     * Apply abstract search across all allowed filters
     *
     * @param string $searchTerm The search term to apply
     * @return $this
     */
    public function withAbstractSearch(string $searchTerm): self
    {
        try {
            $this->request->merge(['abstract_search' => $searchTerm]);
        } catch (Throwable $e) {
            $this->handleException("Error setting abstract search", $e);
        }
        return $this;
    }

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

    public function getResultLimit(): ?int
    {
        return $this->resultLimit;
    }

    public function getOrderByFields(): array
    {
        return $this->orderByFields;
    }

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

    public function setWhereConditions(array $conditions): void
    {
        $this->whereConditions = $conditions;
    }

    public function setOnlyFields(array $fields): void
    {
        $this->onlyFields = $fields;
    }

    public function setMetaIgnored(array $ignored): void
    {
        $this->metaIgnored = $ignored;
    }

    public function setMetaCountOnly(bool $value): void
    {
        $this->metaCountOnly = $value;
    }

    public function setOnlyMeta(bool $value): void
    {
        $this->onlyMeta = $value;
    }

    public function setGroupByFields(array $fields): void
    {
        $this->groupByFields = $fields;
    }

    public function setResultLimit(?int $limit): void
    {
        $this->resultLimit = $limit;
    }

    public function setOrderByFields(array $fields): void
    {
        $this->orderByFields = $fields;
    }

    public static function for(Request $request): self
    {
        return new static($request);
    }
}
