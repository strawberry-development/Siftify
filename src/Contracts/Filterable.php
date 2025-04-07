<?php

namespace SiftifyVendor\Siftify\Contracts;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;

interface Filterable
{
    public function filterOnModel(Model $model): self;
    public function allowedFilters(... $filters): self;
    public function relationships(... $relationships): self;
    public function withWhereConditions(... $conditions): self;
    public function ignoreFilters(array $filters): self;
    public function apply(): Builder;
    public function paginate(?int $perPage = null): self;
    public function get(): self;
    public function toArray(): array;
    public function toJson(): JsonResponse;
}
