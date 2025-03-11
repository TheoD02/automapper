<?php

declare(strict_types=1);

namespace AutoMapper\Symfony\Bundle\DataCollector;

use AutoMapper\Extractor\WriteMutator;
use AutoMapper\Generator\UniqueVariableScope;
use AutoMapper\Metadata\GeneratorMetadata;
use AutoMapper\Metadata\MetadataFactory;
use AutoMapper\Metadata\PropertyMetadata;
use AutoMapper\Transformer\AssignedByReferenceTransformerInterface;
use PhpParser\Node\Expr;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\PrettyPrinter\Standard;
use PhpParser\PrettyPrinterAbstract;
use Symfony\Bundle\FrameworkBundle\DataCollector\AbstractDataCollector;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\DataCollector\LateDataCollectorInterface;
use Symfony\Component\VarDumper\Cloner\Data;
use function PHPUnit\Framework\matches;

class MetadataCollector extends AbstractDataCollector implements LateDataCollectorInterface
{
    private readonly PrettyPrinterAbstract $printer;
    private array $collected = [];

    public function __construct(
        private readonly MetadataFactory $metadataFactory,
    ) {
        $this->printer = new Standard();
    }

    public function collect(Request $request, Response $response, ?\Throwable $exception = null): void
    {
        $metadatas = [];

        /** @var GeneratorMetadata $metadata */
        foreach ($this->metadataFactory->listMetadata() as $metadata) {
            $fileCode = null;

            if (class_exists($metadata->mapperMetadata->className)) {
                $reflectionClass = new \ReflectionClass($metadata->mapperMetadata->className);

                if (($fileName = $reflectionClass->getFileName()) !== false && ($content = @file_get_contents($fileName)) !== false) {
                    $fileCode = $this->highlight($content);
                }
            }

            $metadatas[] = [
                'registered' => $metadata->mapperMetadata->registered,
                'source' => $metadata->mapperMetadata->source,
                'target' => $metadata->mapperMetadata->target,
                'className' => $metadata->mapperMetadata->className,
                'checkAttributes' => $metadata->checkAttributes,
                'useConstructor' => $metadata->hasConstructor(),
                'provider' => $metadata->provider,
                'usedProperties' => array_map(
                    function (PropertyMetadata $property) {
                        $readExpression = $property->source->accessor?->getExpression(new Expr\Variable('source'));
                        $uniqueVariableScope = new UniqueVariableScope();

                        if (!$readExpression) {
                            $readExpression = new Expr\ConstFetch(new Name('null'));
                        }

                        [$output, $propStatements] = $property->transformer->transform(
                            $readExpression,
                            new Expr\Variable('target'),
                            $property,
                            $uniqueVariableScope,
                            new Expr\Variable('source'),
                        );

                        if ($property->target->writeMutator && $property->target->writeMutator->type !== WriteMutator::TYPE_ADDER_AND_REMOVER) {
                            $propStatements[] = new Stmt\Expression($property->target->writeMutator->getExpression(
                                new Expr\Variable('target'),
                                $output,
                                $property->transformer instanceof AssignedByReferenceTransformerInterface
                                    ? $property->transformer->assignByRef()
                                    : false,
                            ));
                        }

                        return [
                            'source' => $property->source,
                            'target' => $property->target,
                            'transformer' => \get_class($property->transformer),
                            'maxDepth' => $property->maxDepth,
                            'if' => $property->if,
                            'groups' => $property->groups,
                            'disableGroupsCheck' => $property->disableGroupsCheck,
                            'code' => $this->highlightStatements($propStatements),
                        ];
                    },
                    array_filter($metadata->propertiesMetadata, fn (PropertyMetadata $property) => !$property->ignored),
                ),
                'notUsedProperties' => array_map(
                    fn (PropertyMetadata $property) => [
                        'source' => $property->source,
                        'target' => $property->target,
                        'reason' => $property->ignoreReason,
                    ],
                    array_filter($metadata->propertiesMetadata, fn (PropertyMetadata $property) => $property->ignored),
                ),
                'fileCode' => $fileCode,
            ];
        }

        $this->data = [
            'metadata' => $metadatas,
            'collected' => $this->collected,
        ];
    }

    /** @return array<mixed>|Data */
    public function getMetadatas(): array|Data
    {
        return $this->data['metadata'] ?? [];
    }

    public function getPerformedMappers(): array
    {
        return $this->data['collected']['mappings'] ?? [];
    }

    public static function getTemplate(): ?string
    {
        return '@AutoMapper/DataCollector/metadata.html.twig';
    }

    /**
     * @param array<Stmt> $statements
     */
    private function highlightStatements(array $statements): string
    {
        $code = $this->printer->prettyPrint($statements);

        return $this->highlight('<?php ' . $code);
    }

    private function highlight(string $code): string
    {
        $highlightComment = \ini_get('highlight.comment');
        $highlightDefault = \ini_get('highlight.default');
        $highlightHtml = \ini_get('highlight.html');
        $highlightKeyword = \ini_get('highlight.keyword');
        $highlightString = \ini_get('highlight.string');

        ini_set('highlight.comment', '#5F826B');
        ini_set('highlight.default', '#9876AA');
        ini_set('highlight.html', '#BCBEC4');
        ini_set('highlight.keyword', '#CF8E6D');
        ini_set('highlight.string', '#6AAB73');

        $code = highlight_string($code, true);

        ini_set('highlight.comment', $highlightComment);
        ini_set('highlight.default', $highlightDefault);
        ini_set('highlight.html', $highlightHtml);
        ini_set('highlight.keyword', $highlightKeyword);
        ini_set('highlight.string', $highlightString);

        return str_replace(htmlspecialchars('<?php '), '', $code);
    }

    public function lateCollect()
    {
        return array_merge($this->data, ['collected' => $this->collected]);
    }

    public function collectMapper(string $class, array|object $source, string|array|object $target, float $time): void
    {
        $source = match (true) {
            is_array($source) => 'array',
            is_object($source) => get_debug_type($source),
            default => $source,
        };

        $target = match (true) {
            is_array($target) => 'array',
            is_object($target) => get_debug_type($target),
            default => $target,
        };

        $mappingName = "{$source} => {$target}";

        $this->collected['mappings'][$mappingName]['iterations'][] = [
            'class' => $class,
            'source' => $source,
            'target' => $target,
            'time' => $time,
        ];

        if (!isset($this->collected['mappings'][$mappingName]['totalTime'])) {
            $this->collected['mappings'][$mappingName]['totalTime'] = 0;
        }
        $this->collected['mappings'][$mappingName]['totalTime'] += $time;
    }
}
