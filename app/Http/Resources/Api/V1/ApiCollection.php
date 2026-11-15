<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * A list of API v1 resources. A paged one describes its page in camelCase
 * like every other answer — the framework's default is snake_case.
 */
class ApiCollection extends AnonymousResourceCollection
{
    /**
     * @param  array{current_page: int, last_page: int, per_page: int, total: int, from: int|null, to: int|null, first_page_url: string, last_page_url: string, prev_page_url: string|null, next_page_url: string|null}  $paginated
     * @param  array<string, mixed>  $default
     * @return array{meta: array<string, int|null>, links: array<string, string|null>}
     */
    public function paginationInformation(Request $request, array $paginated, array $default): array
    {
        return [
            'meta' => [
                'currentPage' => $paginated['current_page'],
                'lastPage' => $paginated['last_page'],
                'perPage' => $paginated['per_page'],
                'total' => $paginated['total'],
                'from' => $paginated['from'],
                'to' => $paginated['to'],
            ],
            'links' => [
                'first' => $paginated['first_page_url'],
                'last' => $paginated['last_page_url'],
                'prev' => $paginated['prev_page_url'],
                'next' => $paginated['next_page_url'],
            ],
        ];
    }
}
