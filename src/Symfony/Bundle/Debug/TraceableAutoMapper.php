<?php

namespace AutoMapper\Symfony\Bundle\Debug;

use AutoMapper\AutoMapperInterface;
use AutoMapper\MapperInterface;
use AutoMapper\Symfony\Bundle\DataCollector\MetadataCollector;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;

#[AsDecorator(AutoMapperInterface::class)]
class TraceableAutoMapper implements AutoMapperInterface
{
    public function __construct(
        #[AutowireDecorated]
        private readonly AutoMapperInterface $inner,
        private MetadataCollector $metadataCollector,
    )
    {
    }

    public function map(object|array $source, object|array|string $target, array $context = []): array|object|null
    {
        $start = microtime(true);
        $result = $this->inner->map($source, $target, $context);
        $time = microtime(true) - $start;

        $this->metadataCollector->collectMapper($this->inner::class, $source, $target, $time);

        return $result;
    }

    public function mapCollection(iterable $collection, string $target, array $context = []): array
    {
        $start = microtime(true);
        $result = $this->inner->mapCollection($collection, $target, $context);
        $time = microtime(true) - $start;

        // TODO: not tested yet
        $first = $collection instanceof \Traversable ? $collection->current() : reset($collection);
        $this->metadataCollector->collectMapper($this->inner::class, $first, $target, $time);

        return $result;
    }
}