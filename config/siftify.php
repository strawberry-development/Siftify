<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Pagination Configuration
    |--------------------------------------------------------------------------
    */
    'pagination' => [
        'enabled' => true,
        'default_per_page' => 15,
        'max_per_page' => 100,
        'page_name' => 'page',
        'per_page_name' => 'per_page',
    ],

    /*
    |--------------------------------------------------------------------------
    | Meta Information Configuration
    |--------------------------------------------------------------------------
    */
    'meta' => [
        'enabled' => true,
        'include_execution_time' => true,
        'include_payload_size' => true,
        'include_filter_params' => true,
        'include_result_size' => true,
        'include_request_details' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Response Format Configuration
    |--------------------------------------------------------------------------
    */
    'response_format' => [
        'success_message' => 'Resources retrieved successfully',
        'error_message' => 'Error retrieving resources',
        'wrap_data' => true,
        'data_key' => 'data',
        'meta_key' => 'meta',
        'success_key' => 'success',
        'message_key' => 'message',
    ],

    /*
    |--------------------------------------------------------------------------
    | Security Configuration
    |--------------------------------------------------------------------------
    */
    'security' => [
        'validate_all_filters' => true,
        'strict_column_checking' => true,
        'strict_relationship_checking' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Standard Parameters
    |--------------------------------------------------------------------------
    */
    'standard_parameters' => [
        'sort',
        'order',
        'page',
        'per_page',
        'only',
        'meta_ignore',
        'meta_count_only',
        'only_meta',
        // TODO
        'group_by',
    ],
];
