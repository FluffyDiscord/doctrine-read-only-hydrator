[![version](https://img.shields.io/badge/version-1.0.0-green.svg)](https://github.com/steevanb/doctrine-read-only-hydrator/tree/1.0.0)
[![doctrine](https://img.shields.io/badge/doctrine/orm-^2.4.8-blue.svg)](http://www.doctrine-project.org)
![Lines](https://img.shields.io/badge/code lines-330-green.svg)
![Total Downloads](https://poser.pugx.org/steevanb/doctrine-read-only-hydrator/downloads)
[![SensionLabsInsight](https://img.shields.io/badge/SensionLabsInsight-platinum-brightgreen.svg)](https://insight.sensiolabs.com/projects/bd1b7a42-6a2c-4918-9986-3361dd40cc86/analyses/2)
[![Scrutinizer](https://scrutinizer-ci.com/g/steevanb/doctrine-read-only-hydrator/badges/quality-score.png?b=master)](https://scrutinizer-ci.com/g/steevanb/doctrine-read-only-hydrator/)

doctrine-read-only-hydrator
===========================

When you retrieve data with Doctrine, you can get an array with values, or a fully hydrated object.

Hydration is a very slow process, who return same instance of entity if several hydrations have same entity to hydrate. 
It's fine when you want to insert / update / delete your entity. But when you just want to retrieve data without editing it (to show it in list for example), it's way to slow.

If you want to really retrieve data from your database, and don't get UnitOfWork reference, with Doctrine hydration you can't, cause each request will not hydrate a new entity with data taken in your query, it will return the first hydrated entity.

So, in case you don't need to modify your entity, you want to be really faster, or just retrieve data stored in your database, you can use ReadOnlyHydrator !

This hydrated entities can't be persisted / flushed and nothing will be lazy loaded : it's not the goal of this hydration ! Choose when you need Doctrine hydrator, and when you need ReadOnlyHydrator.

[Changelog](changelog.md)

Benchmark
---------

This table show simple benchmark results (time and memory_get_peak_usage()), with 30, 1000 and 5000 entities retrieved from a MySQL 5.7 database, PHP 5.6.23 and Doctrine 2.5.4.

getArrayResult() return an array, used by ReadOnlyHydrator::hydrate() to make it's hydration. getResult() is equivalent to getArrayResult() + Doctrine hydration.

memory_get_peak_usage() in ReadOnlyHydrator::hydrate() is the addition of getArrayResult() and ReadOnlyHydrator::hydrate(), but memory_get_peak_usage() in getResult() is only getResult().

| Entities | getArrayResult() | ReadOnlyHydrator::hydrate() | getResult() |
| -------- | ---------------- | --------------------------- | ----------- |
| 30 | 1 ms, 15 Mo | 0 ms, 16 Mo | 8 ms, 18 Mo |
| 1000 | 27 ms, 18 Mo | 31 ms, 24 Mo | 120 ms, 31 Mo |
| 5000 | 125 ms, 30 Mo | 114 ms, 54 Mo | 681 ms, 86 Mo |

As expected, getArrayResult() is the fastest way to retrieve data. But, you have to work with array, so you can't use entity methods.

Example
-------

```php
# Foo\Repository\BarRepository
class BarRepository
{
    public function getReadOnlyUser($id)
    {
        $result = $this
            ->createQueryBuilder('user')
            ->select('user', 'comments')
            ->join('user.comments', 'comments')
            ->where('user.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            # getArrayResult() will not hydrate anything, it's the fastest way to get data
            ->getArrayResult();

        # return a really new User instance, hydrated with database data,
        # who can't be persisted or flushed
        return ReadOnlyHydrator::hydrate($this->_em, $this->getClassName(), $result[0]);
    }
}
```

Installation
------------
```bash
composer require steevanb/doctrine-read-only-hydrator 1.0.*
```

Symfony 2.x integration
-----------------------
```php
# app/AppKernel.php
class AppKernel extends Kernel
{
    public function registerBundles()
    {
        $bundles = [
            new steevanb\DoctrineReadOnlyHydrator\Bridge\Symfony2\ReadOnlyHydratorBundle()
        ];
    }
}
```

Symfony 3.x integration
-----------------------
```php
# app/AppKernel.php
class AppKernel extends Kernel
{
    public function registerBundles()
    {
        $bundles = [
            new steevanb\DoctrineReadOnlyHydrator\Bridge\Symfony3\ReadOnlyHydratorBundle()
        ];
    }
}
```
