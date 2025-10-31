<?php

namespace Tourze\PHPUnitSymfonyKernelTest;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Common\Collections\Order;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Exception\MissingIdentifierField;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\Persisters\Exception\UnrecognizedField;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Medium;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use Tourze\PHPUnitBase\TestCaseHelper;
use Tourze\PHPUnitSymfonyKernelTest\Attribute\AsRepository;

/**
 * 通用的仓库类测试用例，提供部分通用的用例生成能力，减少重复工作
 *
 * @template TEntity of object
 */
#[Medium]
abstract class AbstractRepositoryTestCase extends AbstractIntegrationTestCase
{
    /**
     * 创建一个新的实体，要注意的是不要存储这个实体到EntityManager，只需要创建并确保他不会重复即可
     */
    abstract protected function createNewEntity(): object;

    #[Test]
    final public function testRepositoryClassShouldHaveAsRepositoryAttribute(): void
    {
        $reflection = new \ReflectionClass($this->getRepository());
        $className = (new \ReflectionClass($this->getRepository()->getClassName()))->getShortName();
        $this->assertNotEmpty(
            $reflection->getAttributes(AsRepository::class),
            $this->getRepository()::class . ' must have AsRepository attribute, but not found.'
            . 'Insert `use Tourze\PHPUnitSymfonyKernelTest\Attribute\AsRepository;`,' .
            " and add `#[AsRepository(entityClass: {$className}::class)]` to the class header."
        );
    }

    /**
     * 确保测试用例保存的总是符合预期的对象
     */
    #[Test]
    final public function testCreateNewEntityShouldNotBePersisted(): object
    {
        $entity = $this->createNewEntity();
        $this->assertInstanceOf($this->getRepository()->getClassName(), $entity);
        $this->assertFalse(
            $this->getEntityManager()->getUnitOfWork()->isInIdentityMap($entity),
            'createNewEntity方法返回的对象，不应该存储到 EntityManager UnitOfWork，实现方只需要确保有创建对象并确保字段完整、不会有冲突即可'
        );

        return $entity;
    }

    /**
     * 测试保存成功的场景
     */
    #[Test]
    final public function testCreateNewEntityShouldPersistedSuccess(): void
    {
        $entity = $this->createNewEntity();
        $this->assertInstanceOf($this->getRepository()->getClassName(), $entity);

        // 仅仅保存
        $this->getEntityManager()->persist($entity);
        //        $this->assertTrue(
        //            $this->getEntityManager()->getUnitOfWork()->isInIdentityMap($entity),
        //            '调用实体管理器的 persist 方法保存失败，但是在 UnitOfWork 对象中找不到'
        //        );

        // 真实刷新数据到数据库
        $this->getEntityManager()->flush();
        $this->assertTrue($this->getEntityManager()->getUnitOfWork()->isInIdentityMap($entity));
    }

    /**
     * 测试保存成功后detach
     */
    #[Test]
    final public function testCreateNewEntityAndDetachShouldNotInIdentityMap(): void
    {
        $entity = $this->createNewEntity();
        $this->assertInstanceOf($this->getRepository()->getClassName(), $entity);
        $this->getEntityManager()->persist($entity);
        $this->getEntityManager()->flush();

        $this->assertTrue($this->getEntityManager()->getUnitOfWork()->isInIdentityMap($entity));

        $this->getEntityManager()->detach($entity);
        $this->assertFalse($this->getEntityManager()->getUnitOfWork()->isInIdentityMap($entity));
    }

    /**
     * 此测试用于验证当查询条件包含不存在的字段时，会抛出预期的 `\Doctrine\ORM\Persisters\Exception\UnrecognizedField` 异常。
     */
    #[Test]
    #[TestWith(['non_exist.nonExistentField_'])]
    #[TestWith(['nonExistentField_'])]
    #[TestWith(['anotherInvalidField'])]
    #[TestWith(['_non_existent_field_with_underscores'])]
    final public function testFindByWithNonExistentFieldShouldThrowException(string $field): void
    {
        $this->expectException(UnrecognizedField::class);
        $this->getRepository()->findBy([$field => 'value']);
    }

    /**
     * 此测试用于验证当查询无结果时，`findBy` 能返回一个空数组。
     */
    #[Test]
    final public function testFindByWithNonMatchingCriteriaShouldReturnEmptyArray(): void
    {
        $pk = $this->getEntityManager()->getClassMetadata($this->getRepository()->getClassName())->getSingleIdentifierFieldName();
        $results = $this->getRepository()->findBy([$pk => -1]);
        $this->assertIsArray($results);
        $this->assertEmpty($results);
    }

    final public function testFindByWithMatchingCriteriaShouldReturnArrayOfEntities(): void
    {
        $entity1 = $this->createNewEntity();
        $this->getEntityManager()->persist($entity1);
        $entity2 = $this->createNewEntity();
        $this->getEntityManager()->persist($entity2);

        // 保存到数据库
        $this->getEntityManager()->flush();

        $pk = $this->getEntityManager()->getClassMetadata($this->getRepository()->getClassName())->getSingleIdentifierFieldName();
        $id1 = $this->getEntityManager()->getUnitOfWork()->getSingleIdentifierValue($entity1);
        $id2 = $this->getEntityManager()->getUnitOfWork()->getSingleIdentifierValue($entity2);

        $results = $this->getRepository()->findBy([$pk => [$id1, $id2]]);
        $this->assertCount(2, $results);

        $this->assertContainsOnlyInstancesOf($this->getRepository()->getClassName(), $results);

        foreach ($results as $result) {
            $this->assertContains(
                $this->getEntityManager()->getUnitOfWork()->getSingleIdentifierValue($result),
                [$id1, $id2],
            );
        }
    }

    #[Test]
    final public function testFindByShouldRespectOrderByClause(): void
    {
        $entity1 = $this->createNewEntity();
        $entity2 = $this->createNewEntity();

        $this->getEntityManager()->persist($entity1);
        $this->getEntityManager()->persist($entity2);
        $this->getEntityManager()->flush();

        $id1 = $this->getEntityManager()->getUnitOfWork()->getEntityIdentifier($entity1);
        $id2 = $this->getEntityManager()->getUnitOfWork()->getEntityIdentifier($entity2);
        $this->assertNotEquals(
            $id1,
            $id2,
            '两次保存实体时，主键不可能一样的',
        );

        $primaryKey = $this->getEntityManager()->getClassMetadata($this->getRepository()->getClassName())->getSingleIdentifierFieldName();

        $results = $this->getRepository()->findBy([], [$primaryKey => Order::Descending->value]);
        $this->assertIsArray($results);
        $this->assertGreaterThanOrEqual(2, \count($results));
        $this->assertEquals($id2, $this->getEntityManager()->getUnitOfWork()->getEntityIdentifier($results[0]));
        $this->assertEquals($id1, $this->getEntityManager()->getUnitOfWork()->getEntityIdentifier($results[1]));
    }

    /**
     * 此测试用于验证查询无结果时的行为。请使用一个必然不存在的条件进行测试，并断言返回值为 `null`
     */
    #[Test]
    final public function testFindOneByWithNonMatchingCriteriaShouldReturnNull(): void
    {
        $pk = $this->getEntityManager()->getClassMetadata($this->getRepository()->getClassName())->getSingleIdentifierFieldName();
        $found = $this->getRepository()->findOneBy([$pk => -1]);
        $this->assertNull($found);
    }

    /**
     * 使用一个不存在的字段进行查询，并断言抛出了 `ORMException`
     */
    #[Test]
    final public function testFindOneByWithNonExistentFieldShouldThrowException(): void
    {
        $this->expectException(UnrecognizedField::class);
        $this->getRepository()->findOneBy(['nonExistentField' => 'value']);
    }

    final public function testFindOneByWithMatchingCriteriaShouldReturnEntity(): void
    {
        $entity = $this->createNewEntity();
        $this->getEntityManager()->persist($entity);
        $this->getEntityManager()->flush();

        $pkColumn = $this->getEntityManager()->getClassMetadata($this->getRepository()->getClassName())->getSingleIdentifierColumnName();
        $pkValue = $this->getEntityManager()->getUnitOfWork()->getEntityIdentifier($entity);

        $result = $this->getRepository()->findOneBy([$pkColumn => $pkValue]);

        $this->assertInstanceOf($this->getRepository()->getClassName(), $result);
        $this->assertEquals($pkValue, $this->getEntityManager()->getUnitOfWork()->getEntityIdentifier($result));
    }

    /**
     * 测试 findOneBy 的排序字段生效
     */
    #[Test]
    #[DataProvider('orderByDataProvider')]
    /**
     * @param array<int, string> $columns
     */
    final public function testFindOneByShouldSortOrder(array $columns): void
    {
        $repository = $this->getRepository();

        $orderBy = [];
        foreach ($columns as $column) {
            $orderBy[$column] = 'ASC';
        }

        $pk = $this->getEntityManager()->getClassMetadata($this->getRepository()->getClassName())->getSingleIdentifierFieldName();
        $result = $repository->findOneBy([$pk => -999], $orderBy);
        $this->assertNull($result);
    }

    /**
     * 生成排序测试数据
     */
    /**
     * @return iterable<array<int, string>>
     */
    final public static function orderByDataProvider(): iterable
    {
        // 获取实体的元数据
        $repositoryClass = TestCaseHelper::extractCoverClass(new \ReflectionClass(static::class));
        if (null === $repositoryClass) {
            throw new \RuntimeException(static::class . '找不到关联的Repository类，请检查是否已实现了CoversClass注解');
        }

        if (!class_exists($repositoryClass)) {
            throw new \RuntimeException("Repository类 {$repositoryClass} 不存在");
        }

        // 强制只从 AsRepository 读取关联的仓库类
        $entityClass = null;
        foreach ((new \ReflectionClass($repositoryClass))->getAttributes(AsRepository::class) as $attribute) {
            $attribute = $attribute->newInstance();
            /** @var AsRepository $attribute */
            $entityClass = $attribute->entityClass;
            break;
        }
        if (null === $entityClass) {
            return;
        }

        if (!class_exists($entityClass)) {
            return;
        }

        $sortableFields = [];

        // 获取所有可排序的字段
        foreach ((new \ReflectionClass($entityClass))->getProperties() as $property) {
            foreach ($property->getAttributes(ORM\Column::class) as $attribute) {
                $attribute = $attribute->newInstance();
                /** @var ORM\Column $attribute */
                // 跳过部分特殊类型
                if (in_array($attribute->type, [Types::JSON, Types::TEXT, Types::SIMPLE_ARRAY])) {
                    continue;
                }

                $name = $property->getName();
                yield "single field {$name}" => [
                    [$name],
                ];
                $sortableFields[] = $name;
            }

            foreach ($property->getAttributes(ORM\ManyToOne::class) as $attribute) {
                yield "single relation {$property->getName()}" => [
                    [$property->getName()],
                ];
                $sortableFields[] = $property->getName();
            }

            foreach ($property->getAttributes(ORM\OneToOne::class) as $attribute) {
                /** @var ORM\OneToOne $definition */
                $definition = $attribute->newInstance();

                // 反向端（mappedBy 非空）不能用于排序，直接跳过
                if (null !== $definition->mappedBy) {
                    continue;
                }

                yield "single relation {$property->getName()}" => [
                    [$property->getName()],
                ];
                $sortableFields[] = $property->getName();
            }
        }

        // 使用代表性组合策略，避免组合爆炸
        $fieldCount = count($sortableFields);

        // 2. 代表性双字段组合
        if ($fieldCount >= 2) {
            // 第一个和第二个字段
            yield "2 fields {$sortableFields[0]}, {$sortableFields[1]}" => [
                [$sortableFields[0], $sortableFields[1]],
            ];

            // 第一个和最后一个字段
            $fieldCount = count($sortableFields);
            if ($fieldCount > 2) {
                yield "2 fields {$sortableFields[0]}, {$sortableFields[$fieldCount - 1]}" => [
                    [$sortableFields[0], $sortableFields[$fieldCount - 1]],
                ];
            }

            // 中间两个字段（如果字段数超过4个）
            $fieldCount = count($sortableFields);
            if ($fieldCount > 4) {
                $mid = intval($fieldCount / 2);
                yield "2 fields {$sortableFields[$mid - 1]}, {$sortableFields[$mid]}" => [
                    [$sortableFields[$mid - 1], $sortableFields[$mid]],
                ];
            }
        }

        // 3. 一个三字段组合（前三个字段）
        $fieldCount = count($sortableFields);
        if ($fieldCount >= 3) {
            yield "3 fields {$sortableFields[0]}, {$sortableFields[1]}, {$sortableFields[2]}" => [
                [$sortableFields[0], $sortableFields[1], $sortableFields[2]],
            ];
        }

        // 4. 全字段组合（当字段数超过5个时）
        $fieldCount = count($sortableFields);
        if ($fieldCount > 5) {
            yield "all {$fieldCount} fields" => [
                $sortableFields,
            ];
        }
    }

    /**
     * 测试 findAll 方法 - 当没有记录时返回空数组
     */
    #[Test]
    final public function testFindAllWhenNoRecordsExistShouldReturnEmptyArray(): void
    {
        $repository = $this->getRepository();

        // 先清理可能存在的所有 tokens
        foreach ($repository->findAll() as $entity) {
            $this->getEntityManager()->remove($entity);
        }
        $this->getEntityManager()->flush();

        $result = $repository->findAll();

        $this->assertSame([], $result);
    }

    final public function testFindAllWhenRecordsExistShouldReturnArrayOfEntities(): void
    {
        // 初始数据
        $initArr = $this->getRepository()->findAll();
        $this->assertContainsOnlyInstancesOf($this->getRepository()->getClassName(), $initArr);

        // 新增一条数据
        $newOne = $this->createNewEntity();
        $this->getEntityManager()->persist($newOne);
        $this->getEntityManager()->flush();

        // 数据还是一个数组
        $newArr = $this->getRepository()->findAll();
        $this->assertContainsOnlyInstancesOf($this->getRepository()->getClassName(), $newArr);

        // 数量应该有变化
        $this->assertEquals(count($initArr) + 1, count($newArr));
    }

    #[Test]
    final public function testFindWithNonExistentIdShouldReturnNull(): void
    {
        $result = $this->getRepository()->find('999999999');
        $this->assertNull($result);
    }

    #[Test]
    final public function testFindWithZeroIdShouldReturnNull(): void
    {
        $result = $this->getRepository()->find(0);
        $this->assertNull($result);
    }

    #[Test]
    final public function testFindWithNegativeIdShouldReturnNull(): void
    {
        $result = $this->getRepository()->find(-1);
        $this->assertNull($result);
    }

    #[Test]
    final public function testFindWithNullIdShouldThrowException(): void
    {
        $this->expectException(MissingIdentifierField::class);
        $this->getRepository()->find(null);
    }

    #[Test]
    final public function testFindWithExistingIdShouldReturnEntity(): void
    {
        $entity = $this->createNewEntity();
        $this->getEntityManager()->persist($entity);
        $this->getEntityManager()->flush();

        $id = $this->getEntityManager()->getUnitOfWork()->getSingleIdentifierValue($entity);
        $this->assertNotNull($id);

        $found = $this->getRepository()->find($id);
        $this->assertInstanceOf($this->getRepository()->getClassName(), $found);
        $this->assertSame($id, $this->getEntityManager()->getUnitOfWork()->getSingleIdentifierValue($found));
    }

    /**
     * 因为我们要求每个实体都必须有DataFixtures，所以这里应该总是能查到数据大于0的
     */
    #[Test]
    final public function testCountWithDataFixtureShouldReturnGreaterThanZero(): void
    {
        $this->assertGreaterThan(0, $this->getRepository()->count(), $this->getRepository()->getClassName() . ' 这个实体的数据库记录数不应该为0，请检查DataFixtures是否完整');
    }

    /**
     * 一般是存在ID字段的，如果记录数为0，那就符合预期
     */
    #[Test]
    final public function testCountWithNotFoundShouldReturnZero(): void
    {
        $pk = $this->getEntityManager()->getClassMetadata($this->getRepository()->getClassName())->getSingleIdentifierFieldName();
        $this->assertEquals(0, $this->getRepository()->count([$pk => -88888]));
    }

    /**
     * 执行count时，使用了错误的、不存在的字段
     */
    #[Test]
    #[TestWith(['non_exist.nonExistentField_'])]
    #[TestWith(['nonExistentField_'])]
    #[TestWith(['anotherInvalidField'])]
    #[TestWith(['_non_existent_field_with_underscores'])]
    final public function testCountWithNonExistentFieldShouldThrowException(string $field): void
    {
        $this->expectException(UnrecognizedField::class);
        $this->getRepository()->count([$field => 'value']);
    }

    #[Test]
    final public function testSaveMethodShouldExists(): void
    {
        $repository = $this->getRepository();
        $repositoryClass = $repository::class;

        self::assertTrue(
            method_exists($repository, 'save'),
            sprintf('Repository [%s] must have a "save" method.', $repositoryClass)
        );

        $reflectionMethod = new \ReflectionMethod($repositoryClass, 'save');

        // 1. 检查参数数量
        self::assertSame(
            2,
            $reflectionMethod->getNumberOfParameters(),
            sprintf('Method "save" in class [%s] should have exactly 2 parameters.', $repositoryClass)
        );

        $parameters = $reflectionMethod->getParameters();

        // 2. 检查第一个参数 $entity
        $entityParameter = $parameters[0];
        self::assertSame(
            'entity',
            $entityParameter->getName(),
            sprintf('The first parameter of "save" method in class [%s] should be named "entity".', $repositoryClass)
        );
        self::assertTrue(
            $entityParameter->hasType(),
            sprintf('The "entity" parameter in class [%s] must have a type hint.', $repositoryClass)
        );
        $entityType = $entityParameter->getType();
        self::assertInstanceOf(
            \ReflectionNamedType::class,
            $entityType,
            sprintf('The "entity" parameter in class [%s] must have a named type hint.', $repositoryClass)
        );
        self::assertFalse(
            $entityType->isBuiltin(),
            sprintf('The type hint for the "entity" parameter in class [%s] should be a class, not a built-in type.', $repositoryClass)
        );
        // 验证类型是否匹配
        self::assertSame(
            $repository->getClassName(),
            $entityType->getName(),
            sprintf('The type hint for the "entity" parameter in class [%s] should be "%s".', $repositoryClass, $repository->getClassName())
        );

        // 3. 检查第二个参数 $flush
        $flushParameter = $parameters[1];
        self::assertSame(
            'flush',
            $flushParameter->getName(),
            sprintf('The second parameter of "save" method in class [%s] should be named "flush".', $repositoryClass)
        );
        self::assertTrue(
            $flushParameter->hasType(),
            sprintf('The "flush" parameter in class [%s] must have a type hint.', $repositoryClass)
        );
        $flushType = $flushParameter->getType();
        self::assertInstanceOf(
            \ReflectionNamedType::class,
            $flushType,
            sprintf('The "flush" parameter in class [%s] must have a named type hint.', $repositoryClass)
        );
        self::assertSame(
            'bool',
            $flushType->getName(),
            sprintf('The "flush" parameter in class [%s] must be of type "bool".', $repositoryClass)
        );
        self::assertTrue(
            $flushParameter->isOptional(),
            sprintf('The "flush" parameter in class [%s] should be optional.', $repositoryClass)
        );
        self::assertTrue(
            $flushParameter->getDefaultValue(),
            sprintf('The "flush" parameter in class [%s] should have a default value of true.', $repositoryClass)
        );
    }

    #[Test]
    final public function testSaveWithFlushTrueShouldPersistAndFlush(): void
    {
        $entity = $this->createNewEntity();

        $this->getEntityManager()->persist($entity);
        $this->getEntityManager()->flush();
        $id = $this->getEntityManager()->getUnitOfWork()->getSingleIdentifierValue($entity);
        $this->assertNotNull($id);

        $this->assertTrue($this->getEntityManager()->contains($entity), 'Repository类保存实体时，发现实体没被存储');
    }

    #[Test]
    final public function testSaveWithFlushFalseShouldNotImmediatelyPersist(): void
    {
        $entity = $this->createNewEntity();
        $this->getEntityManager()->persist($entity);

        // 在flush前，ID可能还是null或0
        //        $id = $this->getEntityManager()->getUnitOfWork()->getSingleIdentifierValue($entity);
        //        $this->assertTrue($id === null || $id === 0 | $id === '');
        // 因为无法兼容 Snowflake，暂时屏蔽了

        // 手动flush
        $this->getEntityManager()->flush();

        // flush后应该有ID
        $id = $this->getEntityManager()->getUnitOfWork()->getSingleIdentifierValue($entity);
        $this->assertNotNull($entity->getId());
    }

    #[Test]
    final public function testRemoveMethodShouldExists(): void
    {
        $repository = $this->getRepository();
        $repositoryClass = $repository::class;

        self::assertTrue(
            method_exists($repository, 'remove'),
            sprintf('Repository [%s] must have a "remove" method.', $repositoryClass)
        );

        $reflectionMethod = new \ReflectionMethod($repositoryClass, 'remove');

        // 1. 检查参数数量
        self::assertSame(
            2,
            $reflectionMethod->getNumberOfParameters(),
            sprintf('Method "remove" in class [%s] should have exactly 2 parameters.', $repositoryClass)
        );

        $parameters = $reflectionMethod->getParameters();

        // 2. 检查第一个参数 $entity
        $entityParameter = $parameters[0];
        self::assertSame(
            'entity',
            $entityParameter->getName(),
            sprintf('The first parameter of "remove" method in class [%s] should be named "entity".', $repositoryClass)
        );
        self::assertTrue(
            $entityParameter->hasType(),
            sprintf('The "entity" parameter in class [%s] must have a type hint.', $repositoryClass)
        );
        $entityType = $entityParameter->getType();
        self::assertInstanceOf(
            \ReflectionNamedType::class,
            $entityType,
            sprintf('The "entity" parameter in class [%s] must have a named type hint.', $repositoryClass)
        );
        self::assertFalse(
            $entityType->isBuiltin(),
            sprintf('The type hint for the "entity" parameter in class [%s] should be a class, not a built-in type.', $repositoryClass)
        );
        // 验证类型是否匹配
        self::assertSame(
            $repository->getClassName(),
            $entityType->getName(),
            sprintf('The type hint for the "entity" parameter in class [%s] should be "%s".', $repositoryClass, $repository->getClassName())
        );

        // 3. 检查第二个参数 $flush
        $flushParameter = $parameters[1];
        self::assertSame(
            'flush',
            $flushParameter->getName(),
            sprintf('The second parameter of "remove" method in class [%s] should be named "flush".', $repositoryClass)
        );
        self::assertTrue(
            $flushParameter->hasType(),
            sprintf('The "flush" parameter in class [%s] must have a type hint.', $repositoryClass)
        );
        $flushType = $flushParameter->getType();
        self::assertInstanceOf(
            \ReflectionNamedType::class,
            $flushType,
            sprintf('The "flush" parameter in class [%s] must have a named type hint.', $repositoryClass)
        );
        self::assertSame(
            'bool',
            $flushType->getName(),
            sprintf('The "flush" parameter in class [%s] must be of type "bool".', $repositoryClass)
        );
        self::assertTrue(
            $flushParameter->isOptional(),
            sprintf('The "flush" parameter in class [%s] should be optional.', $repositoryClass)
        );
        self::assertTrue(
            $flushParameter->getDefaultValue(),
            sprintf('The "flush" parameter in class [%s] should have a default value of true.', $repositoryClass)
        );
    }

    #[Test]
    final public function testRemoveWithFlush(): void
    {
        $entity = $this->createNewEntity();
        $this->getEntityManager()->persist($entity);
        $this->getEntityManager()->flush();

        $id = $this->getEntityManager()->getUnitOfWork()->getSingleIdentifierValue($entity);
        $this->getEntityManager()->remove($entity);
        $this->getEntityManager()->flush();

        $found = $this->getRepository()->find($id);
        $this->assertNull($found);
    }

    final public function testRemoveWithoutFlush(): void
    {
        $entity = $this->createNewEntity();
        $this->getEntityManager()->persist($entity);
        $this->getEntityManager()->flush();

        $id = $this->getEntityManager()->getUnitOfWork()->getSingleIdentifierValue($entity);
        $this->getEntityManager()->remove($entity);

        $found = $this->getRepository()->find($id);
        $this->assertInstanceOf($this->getRepository()->getClassName(), $found);
    }

    /**
     * @return ServiceEntityRepository<TEntity>
     */
    abstract protected function getRepository(): ServiceEntityRepository;
}
