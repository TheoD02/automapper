<?php

declare(strict_types=1);

namespace AutoMapper\Metadata;

use AutoMapper\AutoMapper;

class MapperMetadata
{
    private const FORBIDEN_CHARACTERS_REPLACEMENT = [
        '\\' => '_',
        '/' => '_',
        ':' => '_',
        '*' => '_',
        '?' => '_',
        '"' => '_',
        '<' => '_',
        '>' => '_',
        '|' => '_',
        ' ' => '_',
        '[]' => 'Array',
    ];

    /** @var class-string<object> */
    public string $className;

    /** @var \ReflectionClass<object>|null */
    public readonly ?\ReflectionClass $sourceReflectionClass;

    /** @var \ReflectionClass<object>|null */
    public readonly ?\ReflectionClass $targetReflectionClass;

    /**
     * @param class-string<object>|'array' $source
     * @param class-string<object>|'array' $target
     */
    public function __construct(
        public string $source,
        public string $target,
        public bool $registered,
        private string $classPrefix = 'Mapper_',
    ) {
        if (class_exists($this->source) && $this->source !== \stdClass::class) {
            $reflectionSource = new \ReflectionClass($this->source);
            $this->sourceReflectionClass = $reflectionSource;
        } else {
            $this->sourceReflectionClass = null;
        }

        if (class_exists($this->target) && $this->target !== \stdClass::class) {
            $reflectionTarget = new \ReflectionClass($this->target);
            $this->targetReflectionClass = $reflectionTarget;
        } else {
            $this->targetReflectionClass = null;
        }

        /** @var class-string<object> $className */
        $className = sprintf('%s%s_%s', $this->classPrefix, str_replace(array_keys(self::FORBIDEN_CHARACTERS_REPLACEMENT), self::FORBIDEN_CHARACTERS_REPLACEMENT, $this->source), str_replace(array_keys(self::FORBIDEN_CHARACTERS_REPLACEMENT), self::FORBIDEN_CHARACTERS_REPLACEMENT, $this->target));
        $this->className = $className;
    }

    public function getHash(): string
    {
        $hash = '';

        if ($reflection = $this->sourceReflectionClass) {
            if ($filename = $reflection->getFileName()) {
                $hash .= filemtime($filename);
            } else {
                $hash .= PHP_VERSION;
            }
        }

        if ($reflection = $this->targetReflectionClass) {
            if ($filename = $reflection->getFileName()) {
                $hash .= filemtime($filename);
            } else {
                $hash .= PHP_VERSION;
            }
        }

        $hash .= AutoMapper::VERSION;

        return $hash;
    }
}
