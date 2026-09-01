<?php

declare(strict_types=1);

namespace N3\Core\Api;

use InvalidArgumentException;
use N3\Core\Http\Request;

final readonly class ApiPagination
{
    public function __construct(public int $limit, public ?string $cursor)
    {
    }

    public static function fromRequest(Request $request, int $default = 25, int $maximum = 100): self
    {
        if ($default < 1 || $default > $maximum || $maximum > 100) {
            throw new InvalidArgumentException('API pagination configuration is invalid.');
        }

        $rawLimit = $request->query('limit', (string) $default);
        if ((!is_string($rawLimit) && !is_int($rawLimit)) || !preg_match('/^[1-9][0-9]{0,2}$/D', (string) $rawLimit)) {
            throw new ApiRequestRejected('invalid_pagination', 'Pagination parameters are invalid.');
        }
        $limit = (int) $rawLimit;
        if ($limit > $maximum) {
            throw new ApiRequestRejected('invalid_pagination', 'Pagination parameters are invalid.');
        }

        $cursor = $request->query('cursor');
        if ($cursor !== null && (!is_string($cursor) || !preg_match('/^[A-Za-z0-9_-]{1,512}$/D', $cursor))) {
            throw new ApiRequestRejected('invalid_pagination', 'Pagination parameters are invalid.');
        }

        return new self($limit, $cursor);
    }
}
