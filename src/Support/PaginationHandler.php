<?php

namespace strawberrydev\Siftify\Support;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Config;
use strawberrydev\Siftify\Contracts\Filterable;
use strawberrydev\Siftify\Siftify;
use Throwable;

class PaginationHandler
{
    protected Siftify $siftify;

    public function __construct(Siftify $siftify)
    {
        $this->siftify = $siftify;
    }

    public function paginate(?int $perPage = null): Filterable
    {
        try {
            $perPage = $this->getPerPageValue($perPage);

            // Apply filters and get the count if not already done
            if (!$this->siftify->isCountExecuted()) {
                $this->siftify->apply();
            }

            // Create paginator with the precomputed total count
            if (Config::get('siftify.pagination.enabled', true)) {
                $request = $this->siftify->getRequest();
                $page = $request->input('page', 1);
                $query = $this->siftify->getQuery();
                $results = $query->forPage($page, $perPage)->get();

                $paginator = new LengthAwarePaginator(
                    $results,
                    $this->siftify->getTotalCount(),
                    $perPage,
                    $page,
                    ['path' => $request->url(), 'query' => $request->query()]
                );

                $meta = $this->siftify->getMeta();
                $meta['paginator'] = $paginator;
                $this->siftify->setMeta($meta);
            } else {
                $query = $this->siftify->getQuery();
                $results = $query->get();

                $meta = $this->siftify->getMeta();
                $meta['results'] = $results;
                $this->siftify->setMeta($meta);
            }
        } catch (Throwable $e) {
            $this->siftify->addError("Error during pagination: " . $e->getMessage());
            \Illuminate\Support\Facades\Log::error("Error during pagination", [
                'exception' => get_class($e),
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);

            // Create empty paginator to prevent further errors
            $request = $this->siftify->getRequest();
            $paginator = new LengthAwarePaginator(
                collect(),
                0,
                15,
                1,
                ['path' => $request->url()]
            );

            $meta = $this->siftify->getMeta();
            $meta['paginator'] = $paginator;
            $this->siftify->setMeta($meta);
        }

        return $this->siftify;
    }

    protected function getPerPageValue(?int $perPage = null): int
    {
        if (!Config::get('siftify.pagination.enabled', true)) {
            return PHP_INT_MAX;
        }

        $default = Config::get('siftify.pagination.default_per_page', 15);
        $max = Config::get('siftify.pagination.max_per_page', 100);

        $request = $this->siftify->getRequest();
        $perPageName = Config::get('siftify.pagination.per_page_name', 'per_page');
        $perPage = $perPage ?? $request->input($perPageName, $default);

        return min(max((int) $perPage, 1), $max);
    }

    public function formatPaginationLinks(LengthAwarePaginator $paginator): array
    {
        $links = [];
        $lastPage = $paginator->lastPage();
        $currentPage = $paginator->currentPage();

        // Add first and prev links
        $links[] = ['url' => $paginator->url(1), 'label' => '&laquo; First', 'active' => false];
        $links[] = [
            'url' => $paginator->previousPageUrl() ?: null,
            'label' => '&lsaquo; Previous',
            'active' => false
        ];

        // Calculate window of page links
        $window = 5; // Show 5 page numbers around the current page
        $start = max(1, $currentPage - floor($window / 2));
        $end = min($lastPage, $start + $window - 1);

        // Adjust start if we're near the end
        if ($end == $lastPage) {
            $start = max(1, $lastPage - $window + 1);
        }

        // Create numeric page links
        for ($i = $start; $i <= $end; $i++) {
            $links[] = [
                'url' => $paginator->url($i),
                'label' => (string) $i,
                'active' => $i == $currentPage
            ];
        }

        // Add next and last links
        $links[] = [
            'url' => $paginator->nextPageUrl() ?: null,
            'label' => 'Next &rsaquo;',
            'active' => false
        ];
        $links[] = ['url' => $paginator->url($lastPage), 'label' => 'Last &raquo;', 'active' => false];

        return $links;
    }
}
