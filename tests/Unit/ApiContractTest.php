<?php

declare(strict_types=1);

namespace N3\Tests\Unit;

use N3\Core\Api\ApiAccess;
use N3\Core\Api\ApiCredentialRepository;
use N3\Core\Api\ApiIdempotency;
use N3\Core\Api\ApiPagination;
use N3\Core\Api\ApiPrincipal;
use N3\Core\Api\ApiRequestRejected;
use N3\Core\Api\ApiResponder;
use N3\Core\Api\HashedBearerAuthenticator;
use N3\Core\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ApiContractTest extends TestCase
{
    public function testSuccessAndErrorEnvelopesAreStableJson(): void
    {
        $success = ApiResponder::success(['hostile' => '<script>alert(1)</script>'], 'request-1');
        $error = ApiResponder::error('invalid_request', 'Request rejected.', 'request-2', 400);

        self::assertSame('application/json; charset=UTF-8', $success->headers()['Content-Type']);
        self::assertSame('no-store', $success->headers()['Cache-Control']);
        self::assertSame([
            'data' => ['hostile' => '<script>alert(1)</script>'],
            'meta' => ['request_id' => 'request-1'],
        ], json_decode($success->body(), true, flags: JSON_THROW_ON_ERROR));
        self::assertSame('invalid_request', json_decode($error->body(), true, flags: JSON_THROW_ON_ERROR)['error']['code']);
    }

    public function testPaginationUsesBoundedOpaqueValues(): void
    {
        $pagination = ApiPagination::fromRequest(Request::create('GET', '/api/v1/pages?limit=50&cursor=opaque_123'));

        self::assertSame(50, $pagination->limit);
        self::assertSame('opaque_123', $pagination->cursor);
    }

    #[DataProvider('invalidPagination')]
    public function testInvalidPaginationIsRejected(string $query): void
    {
        $this->expectException(ApiRequestRejected::class);

        ApiPagination::fromRequest(Request::create('GET', '/api/v1/pages?' . $query));
    }

    /** @return iterable<string, array{string}> */
    public static function invalidPagination(): iterable
    {
        yield 'zero' => ['limit=0'];
        yield 'over maximum' => ['limit=101'];
        yield 'not numeric' => ['limit=ten'];
        yield 'cursor punctuation' => ['cursor=not%2Fopaque'];
        yield 'array input' => ['limit[]=10'];
    }

    public function testBearerTokensAreHashedBeforeRepositoryLookup(): void
    {
        $token = 'n3_' . str_repeat('A', 43);
        $repository = new MemoryApiCredentials(new ApiPrincipal('client:test', ['pages:read']));
        $request = Request::create('GET', '/api/v1/pages', server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);

        $principal = (new HashedBearerAuthenticator($repository))->authenticate($request);

        self::assertSame('client:test', $principal?->id);
        self::assertSame(hash('sha256', $token), $repository->receivedHash);
        self::assertNotSame($token, $repository->receivedHash);
    }

    public function testAuthorizationDistinguishesMissingAndInsufficientPrincipals(): void
    {
        try {
            ApiAccess::requireScope(null, 'pages:write');
            self::fail('Missing authentication was accepted.');
        } catch (ApiRequestRejected $exception) {
            self::assertSame('unauthenticated', $exception->errorCode);
            self::assertSame(401, $exception->status);
        }

        $this->expectException(ApiRequestRejected::class);
        $this->expectExceptionMessage('not permitted');
        ApiAccess::requireScope(new ApiPrincipal('client:test', ['pages:read']), 'pages:write');
    }

    public function testIdempotencyUsesAValidatedHeaderAndExactRequestBodyHash(): void
    {
        $request = Request::create(
            'POST',
            '/api/v1/pages',
            server: ['HTTP_IDEMPOTENCY_KEY' => 'request-operation-0001'],
            rawBody: '{"title":"A"}',
        );

        self::assertSame('request-operation-0001', ApiIdempotency::requireKey($request));
        self::assertSame(
            hash('sha256', "POST\n/api/v1/pages\n{\"title\":\"A\"}"),
            ApiIdempotency::requestHash($request),
        );
    }
}

final class MemoryApiCredentials implements ApiCredentialRepository
{
    public ?string $receivedHash = null;

    public function __construct(private readonly ?ApiPrincipal $principal)
    {
    }

    public function findActiveByTokenHash(string $tokenHash): ?ApiPrincipal
    {
        $this->receivedHash = $tokenHash;
        return $this->principal;
    }
}
