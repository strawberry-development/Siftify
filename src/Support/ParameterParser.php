<?php

namespace strawberrydev\Siftify\Support;

use strawberrydev\Siftify\Siftify;
use Throwable;

class ParameterParser
{
    protected Siftify $siftify;

    public function __construct(Siftify $siftify)
    {
        $this->siftify = $siftify;
    }

    /**
     * Process standard parameters from the request
     */
    public function processStandardParameters(): void
    {
        try {
            $request = $this->siftify->getRequest();

            // Process 'only' parameter - specify which fields to include in the response
            if ($request->has('only')) {
                $this->siftify->setOnlyFields($this->parseCommaSeparatedParameter('only'));
            }

            // Process 'meta_ignore' parameter - specify which meta fields to exclude
            if ($request->has('meta_ignore')) {
                $this->siftify->setMetaIgnored($this->parseCommaSeparatedParameter('meta_ignore'));
            }

            // Process 'meta_count_only' parameter - return only count meta data
            if ($request->has('meta_count_only')) {
                $this->siftify->setMetaCountOnly(true);
            }

            // Process 'only_meta' parameter - return only meta information
            if ($request->has('only_meta')) {
                $this->siftify->setOnlyMeta(true);
            }

            // Process 'group_by' parameter - group results by specified fields
            if ($request->has('group_by')) {
                $this->siftify->setGroupByFields($this->parseCommaSeparatedParameter('group_by'));
            }
        } catch (Throwable $e) {
            $this->siftify->addError("Error processing standard parameters: " . $e->getMessage());
        }
    }

    /**
     * Helper to parse comma-separated parameter values
     */
    public function parseCommaSeparatedParameter(string $param): array
    {
        $value = $this->siftify->getRequest()->input($param, '');
        return array_filter(explode(',', $value));
    }
}
