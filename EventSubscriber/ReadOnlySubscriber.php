<?php

namespace steevanb\DoctrineReadOnlyHydrator\EventSubscriber;

use Doctrine\ORM\Event\OnClassMetadataNotFoundEventArgs;
use steevanb\DoctrineReadOnlyHydrator\Hydrator\SimpleObjectHydrator;
use Doctrine\Common\EventSubscriber;
use Doctrine\ORM\Event\PreFlushEventArgs;
use Doctrine\ORM\Events;
use steevanb\DoctrineReadOnlyHydrator\Entity\ReadOnlyEntityInterface;
use steevanb\DoctrineReadOnlyHydrator\Exception\ReadOnlyEntityCantBeFlushedException;
use steevanb\DoctrineReadOnlyHydrator\Exception\ReadOnlyEntityCantBePersistedException;

class ReadOnlySubscriber implements EventSubscriber
{
    /** @return array */
    public function getSubscribedEvents()
    {
        return [
            Events::prePersist,
            Events::preFlush,
            Events::onClassMetadataNotFound,
            Events::postLoad
        ];
    }

    public function prePersist($args)
    {
        if ($this->isReadOnlyEntity($args->getObject())) {
            throw new ReadOnlyEntityCantBePersistedException($args->getObject());
        }
    }

    public function preFlush($args)
    {
        $unitOfWork = $args->getEntityManager()->getUnitOfWork();
        $entities = array_merge(
            $unitOfWork->getScheduledEntityInsertions(),
            $unitOfWork->getScheduledEntityUpdates(),
            $unitOfWork->getScheduledEntityDeletions()
        );
        foreach ($entities as $entity) {
            if ($this->isReadOnlyEntity($entity)) {
                throw new ReadOnlyEntityCantBeFlushedException($entity);
            }
        }
    }

    public function onClassMetadataNotFound($eventArgs)
    {
        try {
            if(empty($eventArgs->getClassName())) {
                return;
            }
            if (class_implements(
                $eventArgs->getClassName(),
                'steevanb\\DoctrineReadOnlyHydrator\\Entity\\ReadOnlyEntityInterface'
            )) {
                $eventArgs->setFoundMetadata(
                    $eventArgs->getObjectManager()->getClassMetadata(get_parent_class($eventArgs->getClassName()))
                );
            }
        } catch (\Exception $exception) {}
    }

    public function postLoad($eventArgs)
    {
        if ($eventArgs->getObject() instanceof ReadOnlyEntityInterface) {
            // add ReadOnlyProxy to classMetada list
            // without it, you can't use Doctrine automatic id finder
            // like $queryBuilder->setParameter('foo', $foo)
            // instead of  $queryBuilder->setParameter('foo', $foo->getId())
            $eventArgs->getObjectManager()->getClassMetadata(get_class($eventArgs->getObject()));
        }
    }

    /**
     * @param object $entity
     * @return bool
     */
    protected function isReadOnlyEntity($entity)
    {
        return
            $entity instanceof ReadOnlyEntityInterface
            || isset($entity->{SimpleObjectHydrator::READ_ONLY_PROPERTY});
    }
}
