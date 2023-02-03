<?php

namespace steevanb\DoctrineReadOnlyHydrator\Hydrator;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\Internal\Hydration\ArrayHydrator;
use Doctrine\ORM\Internal\HydrationCompleteHandler;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\ClassMetadataInfo;

class SimpleObjectHydrator extends ArrayHydrator
{
    public const HYDRATOR_NAME = 'simpleObject';
    public const READ_ONLY_PROPERTY = '__SIMPLE_OBJECT_HYDRATOR__READ_ONLY__';

    protected ?string $rootClassName = null;
    protected array $newEntityReflectionCache = [];
    protected array $reflectionPropertyCache = [];
    protected array $enumAttributeCache = [];
    protected array $entityClassNameCache = [];

    protected function prepare(): void
    {
        parent::prepare();

        $this->rootClassName = null;
    }

    protected function cleanup(): void
    {
        parent::cleanup();

        $this->_uow->hydrationComplete();
    }

    protected function hydrateAllData(): array
    {
        $arrayResult = parent::hydrateAllData();
        $readOnlyResult = [];
        if (is_array($arrayResult)) {
            foreach ($arrayResult as $data) {
                $readOnlyResult[] = $this->doHydrateRowData($this->getRootClassName(), $data);
            }
        }

        return $readOnlyResult;
    }

    protected function getRootClassName(): string
    {
        // i don't understand when we can have more than one item in ArrayHydrator::$_rootAliases
        // so, i assume first one is the right one
        if ($this->rootClassName === null) {
            $rootAlias = key($this->getPrivatePropertyValue($this, '_rootAliases'));
            $this->rootClassName = $this->_rsm->aliasMap[$rootAlias];
        }

        return $this->rootClassName;
    }

    protected function getReflectionClassProperty(ClassMetadata $classMetaData, string $property): ?\ReflectionProperty
    {
        $cacheKey = spl_object_id($classMetaData).$property;
        if(!isset($this->reflectionPropertyCache[$cacheKey])) {
            try {
                $this->reflectionPropertyCache[$cacheKey] = $classMetaData->getReflectionClass()->getProperty($property);
            } catch (\ReflectionException) {
                $this->reflectionPropertyCache[$cacheKey] = null;
            }
        }

        return $this->reflectionPropertyCache[$cacheKey];
    }

    protected function doHydrateRowData(string $className, array $data): object
    {
        $classMetaData = $this->_em->getClassMetadata($className);
        $mappings = $classMetaData->getAssociationMappings();
        $entity = $this->createEntity($classMetaData, $data);

        foreach ($data as $name => $value) {
            if (isset($mappings[$name]) && is_array($value)) {
                $value = match ($mappings[$name]['type']) {
                    ClassMetadataInfo::ONE_TO_ONE => $this->hydrateOneToOne($mappings[$name], $value),
                    ClassMetadataInfo::ONE_TO_MANY => $this->hydrateOneToMany($mappings[$name], $value),
                    ClassMetadataInfo::MANY_TO_ONE => $this->hydrateManyToOne($mappings[$name], $value),
                    ClassMetadataInfo::MANY_TO_MANY => $this->hydrateManyToMany($mappings[$name], $value),
                    default => throw new \Exception('Unknow mapping type "' . $mappings[$name]['type'] . '".'),
                };
            }

            $property = $this->getReflectionClassProperty($classMetaData, $name);
            if($property === null) {
                continue;
            }

            if ($property->isPublic()) {
                $entity->$name = $value;
                continue;
            }

            //$property->setAccessible(true);
            $property->setValue($entity, $value);
            //$property->setAccessible(false);
        }

        return $entity;
    }

    protected function getEnumForValue(ClassMetadata $classMetaData, \ReflectionProperty $property, mixed $value): \UnitEnum|\BackedEnum|null
    {
        $cacheKey = spl_object_id($classMetaData).$property->name;
        if(!isset($this->enumAttributeCache[$cacheKey])) {

            /** @var \UnitEnum|\BackedEnum $enumClass */
            $enumClass = null;
            foreach ($property->getAttributes() as $attribute) {
                $enumClass = $attribute->getArguments()['enumType'] ?? null;
                if ($enumClass !== null) {
                    break;
                }
            }

            if($enumClass === null) {
                return $this->enumAttributeCache[$cacheKey] = null;
            }

            $enumInterfaces = array_keys(class_implements($enumClass));

            $this->enumAttributeCache[$cacheKey] = [
                'class' => $enumClass,
                'interfaces' => $enumInterfaces,
            ];
        }

        if($this->enumAttributeCache[$cacheKey] === null) {
            return null;
        }

        if (in_array(\BackedEnum::class, $this->enumAttributeCache[$cacheKey]['interfaces'], true)) {
            return $this->enumAttributeCache[$cacheKey]['class']::tryFrom($value);
        }

        if (in_array(\UnitEnum::class, $this->enumAttributeCache[$cacheKey]['interfaces'], true)) {
            return $this->enumAttributeCache[$cacheKey]['class']::$value;
        }

        return null;
    }

    protected function createEntity(ClassMetadata $classMetaData, array $data): object
    {
        $cacheKey = spl_object_id($classMetaData);
        if(!isset($this->newEntityReflectionCache[$cacheKey])) {
            $className = $this->getEntityClassName($classMetaData, $data);
            $this->newEntityReflectionCache[$cacheKey] = new \ReflectionClass($className);
        }

        $entity = $this->newEntityReflectionCache[$cacheKey]->newInstanceWithoutConstructor();
        $entity->{static::READ_ONLY_PROPERTY} = true;

        $this->deferPostLoadInvoking($classMetaData, $entity);

        return $entity;
    }

    protected function deferPostLoadInvoking(ClassMetadata $classMetaData, object $entity): self
    {
        /** @var HydrationCompleteHandler $handler */
        $handler = $this->getPrivatePropertyValue($this->_uow, 'hydrationCompleteHandler');
        $handler->deferPostLoadInvoking($classMetaData, $entity);

        return $this;
    }

    protected function resolveClassMetadataInheritance(ClassMetadata $classMetaData, array $data): string
    {
        if (isset($data[$classMetaData->discriminatorColumn['name']]) === false) {
            $exception = 'Discriminator column "' . $classMetaData->discriminatorColumn['name'] . '" ';
            $exception .= 'for "' . $classMetaData->name . '" does not exists in $data.';
            throw new \Exception($exception);
        }

        $discriminator = $data[$classMetaData->discriminatorColumn['name']];
        return $classMetaData->discriminatorMap[$discriminator];
    }

    protected function getEntityClassName(ClassMetadata $classMetaData, array $data): string
    {
        $cacheKey = spl_object_id($classMetaData);
        if(!isset($this->entityClassNameCache[$cacheKey])) {
            $this->entityClassNameCache[$cacheKey] = match ($classMetaData->inheritanceType) {
                ClassMetadataInfo::INHERITANCE_TYPE_NONE => $classMetaData->name,

                ClassMetadataInfo::INHERITANCE_TYPE_SINGLE_TABLE,
                ClassMetadataInfo::INHERITANCE_TYPE_JOINED => $this->resolveClassMetadataInheritance($classMetaData, $data),

                default => throw new \Exception('Unknow inheritance type "' . $classMetaData->inheritanceType . '".'),
            };
        }

        return $this->entityClassNameCache[$cacheKey];
    }

    protected function hydrateOneToOne(array $mapping, mixed $data): object
    {
        return $this->doHydrateRowData($mapping['targetEntity'], $data);
    }

    protected function hydrateOneToMany(array $mapping, mixed $data): ArrayCollection
    {
        $entities = new ArrayCollection();
        foreach ($data as $key => $linkedData) {
            $entities->set($key, $this->doHydrateRowData($mapping['targetEntity'], $linkedData));
        }

        return $entities;
    }

    protected function hydrateManyToOne(array $mapping, mixed $data): object
    {
        return $this->doHydrateRowData($mapping['targetEntity'], $data);
    }

    protected function hydrateManyToMany(array $mapping, mixed $data): ArrayCollection
    {
        $entities = new ArrayCollection();
        foreach ($data as $key => $linkedData) {
            $entities->set($key, $this->doHydrateRowData($mapping['targetEntity'], $linkedData));
        }

        return $entities;
    }

    protected function getPrivatePropertyValue(object $object, string $property)
    {
        $classNames = array_merge([get_class($object)], array_values(class_parents(get_class($object))));

        $cacheKey = implode("", $classNames);
        if(!isset($this->reflectionPropertyCache[$cacheKey])) {
            $classNameIndex = 0;
            do {
                try {
                    $reflection = new \ReflectionProperty($classNames[$classNameIndex], $property);
                    $continue = false;
                } catch (\ReflectionException) {
                    $classNameIndex++;
                    $continue = true;
                }
            } while ($continue);

            if (!isset($reflection) || $reflection instanceof \ReflectionProperty === false) {
                throw new \Exception(get_class($object) . '::$' . $property . ' does not exists.');
            }

            $this->reflectionPropertyCache[$cacheKey] = $reflection;
        }

        $reflection = $this->reflectionPropertyCache[$cacheKey];

        //$accessible = $reflection->isPublic();
        //$reflection->setAccessible(true);
        $value = $reflection->getValue($object);
        //$reflection->setAccessible($accessible);

        return $value;
    }
}
