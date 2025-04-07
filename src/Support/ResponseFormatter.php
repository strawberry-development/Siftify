<?php

namespace strawberryDevelopment\Siftify\Support;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Config;
use strawberryDevelopment\Siftify\Siftify;

class ResponseFormatter
{
    protected Siftify $siftify;

    public function __construct(Siftify $siftify)
    {
        $this->siftify = $siftify;
    }

    public function formatResponse($results): array
    {
        $config = Config::get('siftify.response_format', []);
        $errors = $this->siftify->getErrors();

        $response = [
                $config['success_key'] ?? 'success' => empty($errors),
                $config['message_key'] ?? 'message' => empty($errors) ? ($config['success_message'] ?? 'Resources retrieved successfully')
                : 'There were errors processing some filters'
        ];

        if (!empty($errors)) {
            $response[$config['errors_key'] ?? 'errors'] = $errors;
        }

        // Handle meta data according to parameters
        if (Config::get('siftify.meta.enabled', true) && !$this->isMetaExcluded()) {
            $metaData = $this->getMetaData($results);

            // Apply meta_ignore parameter
            $metaIgnored = $this->siftify->getMetaIgnored();
            if (!empty($metaIgnored)) {
                foreach ($metaIgnored as $ignoredKey) {
                    $this->removeNestedKey($metaData, $ignoredKey);
                }
            }

            // Add meta to response
            $response[$config['meta_key'] ?? 'meta'] = $metaData;
        }

        // Handle appends
        $appends = $this->siftify->getAppends();
        if (!empty($appends)) {
            $response = array_merge($response, $appends);
        }

        // Return only meta if requested
        if ($this->siftify->isOnlyMeta()) {
            // Keep only success, message, errors and meta
            $keysToKeep = [
                $config['success_key'] ?? 'success',
                $config['message_key'] ?? 'message',
                $config['errors_key'] ?? 'errors',
                $config['meta_key'] ?? 'meta'
            ];
            $response = array_intersect_key($response, array_flip($keysToKeep));
            return $response;
        }

        // Format and add data
        $responseData = $this->formatResponseData($results);

        // Add data to response
        if ($config['wrap_data'] ?? true) {
            $response[$config['data_key'] ?? 'data'] = $responseData;
        } else {
            $response = array_merge($response, $responseData);
        }

        return $response;
    }

    protected function formatResponseData($results)
    {
        if ($results instanceof LengthAwarePaginator) {
            $data = $results->items();
        } else {
            $data = $results->toArray();
        }

        return $data;
    }

    /**
     * Set a value in an array using dot notation for the key
     */
    protected function setNestedValue(array &$array, string $path, $value): void
    {
        $keys = explode('.', $path);
        $current = &$array;

        foreach ($keys as $i => $key) {
            if ($i === count($keys) - 1) {
                $current[$key] = $value;
                return;
            }

            if (!isset($current[$key]) || !is_array($current[$key])) {
                $current[$key] = [];
            }

            $current = &$current[$key];
        }
    }

    protected function isIndexedArray(array $array): bool
    {
        return array_keys($array) === range(0, count($array) - 1);
    }

    protected function removeNestedKey(array &$array, string $path): void
    {
        $keys = explode('.', $path);
        $lastKey = array_pop($keys);
        $pointer = &$array;

        foreach ($keys as $key) {
            if (!isset($pointer[$key]) || !is_array($pointer[$key])) {
                return;
            }
            $pointer = &$pointer[$key];
        }

        unset($pointer[$lastKey]);
    }

    protected function isMetaExcluded(): bool
    {
        // Check if we should exclude all meta
        if ($this->siftify->isMetaCountOnly()) {
            return false; // We still return meta but only count info
        }

        return false;
    }

    public function formatErrorResponse(): array
    {
        $config = Config::get('siftify.response_format', []);
        $errors = $this->siftify->getErrors();

        $response = [
                $config['success_key'] ?? 'success' => false,
                $config['message_key'] ?? 'message' => $config['error_message'] ?? 'Error processing request',
                $config['errors_key'] ?? 'errors' => $errors,
                $config['data_key'] ?? 'data' => []
        ];

        if (Config::get('siftify.meta.enabled', true) && !$this->isMetaExcluded()) {
            $metaData = [
                'request_details' => [
                    'processing_time' => round(microtime(true) - $this->siftify->getStartTime(), 4) . ' seconds',
                    'payload_size' => $this->formatBytes($this->siftify->getPayloadSize()),
                    'filter_params' => array_diff_key(
                        $this->siftify->getRequest()->all(),
                        array_flip($this->siftify->getStandardParameters())
                    )
                ]
            ];

            // Apply meta_ignore parameter
            $metaIgnored = $this->siftify->getMetaIgnored();
            if (!empty($metaIgnored)) {
                foreach ($metaIgnored as $ignoredKey) {
                    $this->removeNestedKey($metaData, $ignoredKey);
                }
            }

            $response[$config['meta_key'] ?? 'meta'] = $metaData;
        }

        return $response;
    }

    public function getMetaData($results): array
    {
        $metaConfig = Config::get('siftify.meta', []);

        // Handle meta_count_only parameter
        if ($this->siftify->isMetaCountOnly()) {
            if ($results instanceof LengthAwarePaginator) {
                return ['count' => $results->total()];
            }
            return ['count' => $results->count()];
        }

        if ($results instanceof LengthAwarePaginator) {
            $meta = [
                'count' => $results->total(),
                'pagination' => [
                    'current_page' => $results->currentPage(),
                    'per_page' => $results->perPage(),
                    'total_pages' => $results->lastPage(),
                    'from' => $results->firstItem(),
                    'to' => $results->lastItem(),
                    'next_page_url' => $results->nextPageUrl(),
                    'prev_page_url' => $results->previousPageUrl(),
                    'has_more_pages' => $results->hasMorePages(),
                ],
            ];
        } else {
            $meta = [
                'count' => $results->count(),
            ];
        }

        if ($metaConfig['include_request_details'] ?? true) {
            $requestDetails = [];

            if ($metaConfig['include_execution_time'] ?? true) {
                $requestDetails['processing_time'] = round(microtime(true) - $this->siftify->getStartTime(), 4) . ' seconds';
            }

            if ($metaConfig['include_payload_size'] ?? true) {
                $requestDetails['payload_size'] = $this->formatBytes($this->siftify->getPayloadSize());
            }

            if ($metaConfig['include_filter_params'] ?? true) {
                $requestDetails['filter_params'] = array_diff_key(
                    $this->siftify->getRequest()->all(),
                    array_flip($this->siftify->getStandardParameters())
                );
            }

            if (!empty($requestDetails)) {
                $meta['request_details'] = $requestDetails;
            }
        }

        if ($metaConfig['include_result_size'] ?? true) {
            $meta['result_details'] = [
                'result_size' => $this->formatBytes(strlen(json_encode($results instanceof LengthAwarePaginator ? $results->items() : $results))),
            ];
        }

        if ($this->siftify->getRequest()->has('include')) {
            $meta['includes'] = explode(',', $this->siftify->getRequest()->input('include'));
        }

        // Add information about used standard parameters
        $usedStandardParams = [];
        foreach ($this->siftify->getStandardParameters() as $param) {
            if ($this->siftify->getRequest()->has($param)) {
                $usedStandardParams[$param] = $this->siftify->getRequest()->input($param);
            }
        }

        if (!empty($usedStandardParams)) {
            $meta['standard_parameters'] = $usedStandardParams;
        }

        // Add information about group by if used
        $groupByFields = $this->siftify->getGroupByFields();
        if (!empty($groupByFields)) {
            $meta['group_by'] = $groupByFields;
        }

        return $meta;
    }

    public function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);

        $bytes /= (1 << (10 * $pow));

        return round($bytes, $precision) . ' ' . $units[$pow];
    }
}
