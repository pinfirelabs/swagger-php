<?php declare(strict_types=1);

namespace OpenApi\Tests\Processors;

use OpenApi\Annotations as OA;
use OpenApi\Processors\AugmentRefs;
use OpenApi\Processors\MergeIntoComponents;
use OpenApi\Processors\MergeIntoOpenApi;
use OpenApi\Processors\MergeJsonContent;
use OpenApi\Processors\NormalizeOperationShorthand;
use OpenApi\Tests\OpenApiTestCase;

final class NormalizeOperationShorthandTest extends OpenApiTestCase
{
    public function testExpandsBodyAndTypedStatusMap(): void
    {
        $analysis = $this->analysisFromFixtures(['ExpandedSchemaProperties.php'], $this->processorPipeline([
            new MergeIntoOpenApi(),
            new MergeIntoComponents(),
            new NormalizeOperationShorthand(),
            new AugmentRefs(),
            new MergeJsonContent(),
        ]));

        $operation = $analysis->getAnnotationsOfType(OA\Post::class)[0];
        $this->assertTrue($operation->requestBody->required);
        $this->assertSame('#/components/schemas/VirtualUser', $operation->requestBody->content['application/json']->schema->ref);

        $responses = [];
        foreach ($operation->responses as $response) {
            $responses[$response->response] = $response;
        }
        $this->assertSame('Created', $responses[201]->description);
        $this->assertSame('#/components/schemas/VirtualUser', $responses[201]->content['application/json']->schema->ref);
        $this->assertSame('Bad Request', $responses[400]->description);
        $this->assertCount(2, $responses[400]->content['application/json']->schema->oneOf);
        $this->assertSame('#/components/schemas/VirtualUser', $responses[400]->content['application/json']->schema->oneOf[0]->ref);
        $this->assertSame('#/components/schemas/ExpandedProblem', $responses[400]->content['application/json']->schema->oneOf[1]->ref);
        $this->assertSame('Forbidden', $responses[403]->description);
        $this->assertArrayNotHasKey('content', (array) $responses[403]->jsonSerialize());
    }
}
