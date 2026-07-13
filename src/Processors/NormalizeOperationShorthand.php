<?php declare(strict_types=1);

namespace OpenApi\Processors;

use OpenApi\Analysis;
use OpenApi\Annotations as OA;
use OpenApi\Context;
use OpenApi\Undefined;

/**
 * Expand source-only JSON body and status-keyed response shorthand before the
 * normal OpenAPI processors run.
 */
class NormalizeOperationShorthand
{
    /** @var array<int|string,string> */
    private const STATUS_DESCRIPTIONS = [
        200 => 'OK',
        201 => 'Created',
        202 => 'Accepted',
        204 => 'No Content',
        400 => 'Bad Request',
        401 => 'Unauthorized',
        403 => 'Forbidden',
        404 => 'Not Found',
        409 => 'Conflict',
        422 => 'Unprocessable Content',
        429 => 'Too Many Requests',
        500 => 'Internal Server Error',
        503 => 'Service Unavailable',
        'default' => 'Unexpected error',
    ];

    public function __invoke(Analysis $analysis): void
    {
        foreach ($analysis->getAnnotationsOfType(OA\Operation::class) as $operation) {
            $this->normalizeBody($analysis, $operation);
            $this->normalizeResponses($analysis, $operation);
        }
    }

    private function normalizeBody(Analysis $analysis, OA\Operation $operation): void
    {
        if (Undefined::isDefault($operation->body) || !Undefined::isDefault($operation->requestBody)) {
            return;
        }

        $context = new Context(['nested' => $operation, 'generated' => true], $operation->_context);
        $body = new OA\RequestBody([
            'required' => true,
            '_context' => $context,
        ]);
        $body->_unmerged[] = $this->jsonContent('ref', $operation->body, $body);
        $operation->requestBody = $body;
        $analysis->addAnnotation($body, $body->_context);
    }

    private function normalizeResponses(Analysis $analysis, OA\Operation $operation): void
    {
        if (Undefined::isDefault($operation->responses) || !is_array($operation->responses) || array_is_list($operation->responses)) {
            return;
        }

        $responses = [];
        foreach ($operation->responses as $status => $value) {
            $response = $this->response($analysis, $status, $value, $operation);
            if (!$response instanceof OA\Response) {
                continue;
            }
            $responses[] = $response;
            $analysis->addAnnotation($response, $response->_context);
        }
        $operation->responses = $responses;
    }

    private function response(Analysis $analysis, int|string $status, mixed $value, OA\Operation $operation): ?OA\Response
    {
        $context = new Context(['nested' => $operation, 'generated' => true], $operation->_context);
        if ($value instanceof OA\Response) {
            if (Undefined::isDefault($value->response)) {
                $value->response = $status;
            }
            if (Undefined::isDefault($value->description) && Undefined::isDefault($value->ref)) {
                $value->description = $this->description($status);
            }
            $value->_context = $context;
            $this->normalizeExplicitResponse($value);

            return $value;
        }

        $response = new OA\Response([
            'response' => $status,
            'description' => $this->description($status),
            '_context' => $context,
        ]);
        if ($value === null) {
            return $response;
        }

        if ($value instanceof OA\OneOf) {
            $response->_unmerged[] = $this->jsonContent('oneOf', $value->types, $response);
            $analysis->removeAnnotation($value);
        } elseif ($value instanceof OA\AnyOf) {
            $response->_unmerged[] = $this->jsonContent('anyOf', $value->types, $response);
            $analysis->removeAnnotation($value);
        } elseif (is_string($value) || is_object($value)) {
            $response->_unmerged[] = $this->jsonContent('ref', $value, $response);
        } else {
            $response->_context->logger->warning('Unexpected response shorthand for status ' . $status . ' in ' . $operation->_context);

            return null;
        }

        return $response;
    }

    private function normalizeExplicitResponse(OA\Response $response): void
    {
        if (!Undefined::isDefault($response->content)) {
            return;
        }
        if (!Undefined::isDefault($response->schema)) {
            $response->_unmerged[] = $this->jsonContent('ref', $response->schema, $response);
        } elseif (!Undefined::isDefault($response->oneOf)) {
            $response->_unmerged[] = $this->jsonContent('oneOf', $response->oneOf, $response);
        } elseif (!Undefined::isDefault($response->anyOf)) {
            $response->_unmerged[] = $this->jsonContent('anyOf', $response->anyOf, $response);
        }
    }

    private function jsonContent(string $kind, mixed $value, OA\Response|OA\RequestBody $parent): OA\JsonContent
    {
        $properties = ['_context' => new Context(['nested' => $parent, 'generated' => true], $parent->_context)];
        if ($kind === 'ref') {
            $properties['ref'] = $this->schemaRef($value);
        } else {
            $properties[$kind] = array_map(
                fn (mixed $type): OA\Schema => new OA\Schema(['ref' => $this->schemaRef($type), '_context' => new Context(['generated' => true], $parent->_context)]),
                (array) $value,
            );
        }

        return new OA\JsonContent($properties);
    }

    private function schemaRef(string|object $schema): string|object
    {
        if (!is_string($schema) || str_starts_with($schema, '#/') || class_exists($schema) || interface_exists($schema)) {
            return $schema;
        }

        return OA\Components::ref($schema);
    }

    private function description(int|string $status): string
    {
        return self::STATUS_DESCRIPTIONS[$status] ?? 'Response';
    }
}
