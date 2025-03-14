<?php

declare(strict_types=1);

namespace AutoMapper\Tests;

use AutoMapper\AutoMapper;
use AutoMapper\Configuration;
use AutoMapper\ConstructorStrategy;
use AutoMapper\Event\PropertyMetadataEvent;
use AutoMapper\Exception\CircularReferenceException;
use AutoMapper\Exception\InvalidMappingException;
use AutoMapper\Exception\MissingConstructorArgumentsException;
use AutoMapper\Exception\ReadOnlyTargetException;
use AutoMapper\MapperContext;
use AutoMapper\Provider\EarlyReturn;
use AutoMapper\Tests\Fixtures\Address;
use AutoMapper\Tests\Fixtures\AddressDTO;
use AutoMapper\Tests\Fixtures\AddressDTOReadonlyClass;
use AutoMapper\Tests\Fixtures\AddressDTOWithReadonly;
use AutoMapper\Tests\Fixtures\AddressDTOWithReadonlyPromotedProperty;
use AutoMapper\Tests\Fixtures\AddressType;
use AutoMapper\Tests\Fixtures\AddressWithEnum;
use AutoMapper\Tests\Fixtures\BuiltinClass;
use AutoMapper\Tests\Fixtures\ClassWithMapToContextAttribute;
use AutoMapper\Tests\Fixtures\ClassWithNullablePropertyInConstructor;
use AutoMapper\Tests\Fixtures\ClassWithPrivateProperty;
use AutoMapper\Tests\Fixtures\ConstructorWithDefaultValues;
use AutoMapper\Tests\Fixtures\ConstructorWithDefaultValuesAsObjects;
use AutoMapper\Tests\Fixtures\DifferentSetterGetterType;
use AutoMapper\Tests\Fixtures\DoctrineCollections\Book;
use AutoMapper\Tests\Fixtures\DoctrineCollections\Library;
use AutoMapper\Tests\Fixtures\Dog;
use AutoMapper\Tests\Fixtures\Fish;
use AutoMapper\Tests\Fixtures\FooGenerator;
use AutoMapper\Tests\Fixtures\FooProvider;
use AutoMapper\Tests\Fixtures\GroupOverride;
use AutoMapper\Tests\Fixtures\HasDateTime;
use AutoMapper\Tests\Fixtures\HasDateTimeImmutable;
use AutoMapper\Tests\Fixtures\HasDateTimeImmutableWithNullValue;
use AutoMapper\Tests\Fixtures\HasDateTimeInterfaceWithImmutableInstance;
use AutoMapper\Tests\Fixtures\HasDateTimeInterfaceWithMutableInstance;
use AutoMapper\Tests\Fixtures\HasDateTimeInterfaceWithNullValue;
use AutoMapper\Tests\Fixtures\HasDateTimeWithNullValue;
use AutoMapper\Tests\Fixtures\IntDTO;
use AutoMapper\Tests\Fixtures\Issue111\Colour;
use AutoMapper\Tests\Fixtures\Issue111\ColourTransformer;
use AutoMapper\Tests\Fixtures\Issue111\FooDto;
use AutoMapper\Tests\Fixtures\Issue189\User as Issue189User;
use AutoMapper\Tests\Fixtures\Issue189\UserPatchInput as Issue189UserPatchInput;
use AutoMapper\Tests\Fixtures\ObjectsUnion\Bar;
use AutoMapper\Tests\Fixtures\ObjectsUnion\Foo;
use AutoMapper\Tests\Fixtures\ObjectsUnion\ObjectsUnionProperty;
use AutoMapper\Tests\Fixtures\ObjectWithDateTime;
use AutoMapper\Tests\Fixtures\ObjectWithPropertyAsUnknownArray\ComponentDto;
use AutoMapper\Tests\Fixtures\ObjectWithPropertyAsUnknownArray\Page;
use AutoMapper\Tests\Fixtures\ObjectWithPropertyAsUnknownArray\PageDto;
use AutoMapper\Tests\Fixtures\Order;
use AutoMapper\Tests\Fixtures\PetOwner;
use AutoMapper\Tests\Fixtures\PetOwnerWithConstructorArguments;
use AutoMapper\Tests\Fixtures\PrivatePropertyInConstructors\ChildClass;
use AutoMapper\Tests\Fixtures\PrivatePropertyInConstructors\OtherClass;
use AutoMapper\Tests\Fixtures\Provider\CustomProvider;
use AutoMapper\Tests\Fixtures\SourceForConstructorWithDefaultValues;
use AutoMapper\Tests\Fixtures\Transformer\MoneyTransformerFactory;
use AutoMapper\Tests\Fixtures\Uninitialized;
use AutoMapper\Tests\Fixtures\UserPromoted;
use Doctrine\Common\Collections\ArrayCollection;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Attribute\Ignore;
use Symfony\Component\Serializer\Attribute\MaxDepth;
use Symfony\Component\Serializer\Mapping\ClassDiscriminatorFromClassMetadata;
use Symfony\Component\Serializer\NameConverter\AdvancedNameConverterInterface;
use Symfony\Component\Serializer\NameConverter\NameConverterInterface;
use Symfony\Component\Uid\Ulid;
use Symfony\Component\Uid\Uuid;

/**
 * @author Joel Wurtz <jwurtz@jolicode.com>
 */
class AutoMapperTest extends AutoMapperBaseTest
{
    public function testAutoMapping(): void
    {
        $this->buildAutoMapper(mapPrivatePropertiesAndMethod: true);

        $address = new Address();
        $address->setCity('Toulon');
        $user = new Fixtures\User(1, 'yolo', '13');
        $user->address = $address;
        $user->addresses[] = $address;
        $user->money = 20.10;

        /** @var Fixtures\UserDTO $userDto */
        $userDto = $this->autoMapper->map($user, Fixtures\UserDTO::class);

        self::assertInstanceOf(Fixtures\UserDTO::class, $userDto);
        self::assertSame(1, $userDto->id);
        self::assertSame('yolo', $userDto->getName());
        self::assertSame(13, $userDto->age);
        self::assertCount(1, $userDto->addresses);
        self::assertInstanceOf(AddressDTO::class, $userDto->address);
        self::assertInstanceOf(AddressDTO::class, $userDto->addresses[0]);
        self::assertSame('Toulon', $userDto->address->city);
        self::assertSame('Toulon', $userDto->addresses[0]->city);
        self::assertIsArray($userDto->money);
        self::assertCount(1, $userDto->money);
        self::assertSame(20.10, $userDto->money[0]);
    }

    public function testAutoMapperFromArray(): void
    {
        $this->buildAutoMapper(mapPrivatePropertiesAndMethod: true);

        $user = [
            'id' => 1,
            'address' => [
                'city' => 'Toulon',
            ],
            'createdAt' => '1987-04-30T06:00:00Z',
        ];

        /** @var Fixtures\UserDTO $userDto */
        $userDto = $this->autoMapper->map($user, Fixtures\UserDTO::class);

        self::assertInstanceOf(Fixtures\UserDTO::class, $userDto);
        self::assertEquals(1, $userDto->id);
        self::assertInstanceOf(AddressDTO::class, $userDto->address);
        self::assertSame('Toulon', $userDto->address->city);
        self::assertInstanceOf(\DateTimeInterface::class, $userDto->createdAt);
        self::assertEquals(1987, $userDto->createdAt->format('Y'));
    }

    public function testAutoMapperFromArrayCustomDateTime(): void
    {
        $this->buildAutoMapper(classPrefix: 'CustomDateTime_', dateTimeFormat: 'U');

        $customFormat = 'U';
        $dateTime = \DateTime::createFromFormat(\DateTime::RFC3339, '1987-04-30T06:00:00Z');
        $user = [
            'id' => 1,
            'address' => [
                'city' => 'Toulon',
            ],
            'createdAt' => $dateTime->format($customFormat),
        ];

        /** @var Fixtures\UserDTO $userDto */
        $userDto = $this->autoMapper->map($user, Fixtures\UserDTO::class);

        self::assertInstanceOf(Fixtures\UserDTO::class, $userDto);
        self::assertEquals($dateTime->format($customFormat), $userDto->createdAt->format($customFormat));
    }

    public function testAutoMapperToArray(): void
    {
        $address = new Address();
        $address->setCity('Toulon');
        $user = new Fixtures\User(1, 'yolo', '13');
        $user->address = $address;
        $user->addresses[] = $address;

        $userData = $this->autoMapper->map($user, 'array');

        self::assertIsArray($userData);
        self::assertEquals(1, $userData['id']);
        self::assertIsArray($userData['address']);
        self::assertIsString($userData['createdAt']);
    }

    public function testAutoMapperToArrayGroups(): void
    {
        $address = new Address();
        $address->setCity('Toulon');
        $user = new Fixtures\User(1, 'yolo', '13');
        $user->address = $address;
        $user->addresses[] = $address;

        $userData = $this->autoMapper->map($user, 'array', [MapperContext::GROUPS => ['dummy']]);

        self::assertIsArray($userData);
        self::assertEmpty($userData);
    }

    public function testAutoMapperFromStdObject(): void
    {
        $this->buildAutoMapper(mapPrivatePropertiesAndMethod: true);

        $user = new \stdClass();
        $user->id = 1;

        /** @var Fixtures\UserDTO $userDto */
        $userDto = $this->autoMapper->map($user, Fixtures\UserDTO::class);

        self::assertInstanceOf(Fixtures\UserDTO::class, $userDto);
        self::assertEquals(1, $userDto->id);
    }

    public function testAutoMapperToStdObject(): void
    {
        $userDto = new Fixtures\UserDTO();
        $userDto->id = 1;

        $user = $this->autoMapper->map($userDto, \stdClass::class);

        self::assertInstanceOf(\stdClass::class, $user);
        self::assertEquals(1, $user->id);
    }

    public function testAutoMapperStdObjectToStdObject(): void
    {
        $user = new \stdClass();
        $user->id = 1;
        $nestedStd = new \stdClass();
        $nestedStd->id = 2;
        $user->nestedStd = $nestedStd;
        $userStd = $this->autoMapper->map($user, \stdClass::class);

        self::assertInstanceOf(\stdClass::class, $userStd);
        self::assertNotSame($user, $userStd);
        self::assertNotSame($user->nestedStd, $userStd->nestedStd);
        self::assertEquals($user, $userStd);
    }

    public function testNotReadable(): void
    {
        $this->buildAutoMapper(classPrefix: 'CustomDateTime_');

        $address = new Address();
        $address->setCity('test');

        $addressArray = $this->autoMapper->map($address, 'array');

        self::assertIsArray($addressArray);
        self::assertArrayNotHasKey('city', $addressArray);

        $addressMapped = $this->autoMapper->map($address, Address::class);

        self::assertInstanceOf(Address::class, $addressMapped);

        $property = (new \ReflectionClass($addressMapped))->getProperty('city');
        $property->setAccessible(true);

        $city = $property->getValue($addressMapped);

        self::assertNull($city);
    }

    public function testNoTypes(): void
    {
        $this->buildAutoMapper(classPrefix: 'NotReadable_');

        $address = new Fixtures\AddressNoTypes();
        $address->city = 'test';

        $addressArray = $this->autoMapper->map($address, 'array');

        self::assertIsArray($addressArray);
        self::assertArrayHasKey('city', $addressArray);
        self::assertEquals('test', $addressArray['city']);
    }

    public function testNoTransformer(): void
    {
        $addressFoo = new Fixtures\AddressFoo();
        $addressFoo->city = new Fixtures\CityFoo();
        $addressFoo->city->name = 'test';

        $addressBar = $this->autoMapper->map($addressFoo, Fixtures\AddressBar::class);

        self::assertInstanceOf(Fixtures\AddressBar::class, $addressBar);
        self::assertNull($addressBar->city);
    }

    public function testNoProperties(): void
    {
        $noProperties = new Fixtures\FooNoProperties();
        $noPropertiesMapped = $this->autoMapper->map($noProperties, Fixtures\FooNoProperties::class);

        self::assertInstanceOf(Fixtures\FooNoProperties::class, $noPropertiesMapped);
        self::assertNotSame($noProperties, $noPropertiesMapped);
    }

    public function testGroupsSourceTarget(): void
    {
        if (!class_exists(Groups::class)) {
            self::markTestSkipped('Symfony Serializer is required to run this test.');
        }

        $foo = new Fixtures\Foo();
        $foo->setId(10);

        $bar = $this->autoMapper->map($foo, Fixtures\Bar::class, [MapperContext::GROUPS => ['group2']]);

        self::assertInstanceOf(Fixtures\Bar::class, $bar);
        self::assertEquals(10, $bar->getId());

        $bar = $this->autoMapper->map($foo, Fixtures\Bar::class, [MapperContext::GROUPS => ['group1', 'group3']]);

        self::assertInstanceOf(Fixtures\Bar::class, $bar);
        self::assertEquals(10, $bar->getId());

        $bar = $this->autoMapper->map($foo, Fixtures\Bar::class, [MapperContext::GROUPS => ['group1']]);

        self::assertInstanceOf(Fixtures\Bar::class, $bar);
        self::assertNull($bar->getId());

        $bar = $this->autoMapper->map($foo, Fixtures\Bar::class, [MapperContext::GROUPS => []]);

        self::assertInstanceOf(Fixtures\Bar::class, $bar);
        self::assertNull($bar->getId());

        $bar = $this->autoMapper->map($foo, Fixtures\Bar::class);

        self::assertInstanceOf(Fixtures\Bar::class, $bar);
        self::assertNull($bar->getId());
    }

    public function testGroupsToArray(): void
    {
        if (!class_exists(Groups::class)) {
            self::markTestSkipped('Symfony Serializer is required to run this test.');
        }

        $foo = new Fixtures\Foo();
        $foo->setId(10);

        $fooArray = $this->autoMapper->map($foo, 'array', [MapperContext::GROUPS => ['group1']]);

        self::assertIsArray($fooArray);
        self::assertEquals(10, $fooArray['id']);

        $fooArray = $this->autoMapper->map($foo, 'array', [MapperContext::GROUPS => []]);

        self::assertIsArray($fooArray);
        self::assertArrayNotHasKey('id', $fooArray);

        $fooArray = $this->autoMapper->map($foo, 'array');

        self::assertIsArray($fooArray);
        self::assertArrayNotHasKey('id', $fooArray);
    }

    public function testSkippedGroups(): void
    {
        if (!class_exists(Groups::class)) {
            self::markTestSkipped('Symfony Serializer is required to run this test.');
        }

        $eventDispatcher = new EventDispatcher();
        $eventDispatcher->addListener(PropertyMetadataEvent::class, function (PropertyMetadataEvent $event) {
            $event->disableGroupsCheck = true;
        });

        $this->buildAutoMapper(eventDispatcher: $eventDispatcher, classPrefix: 'SkippedGroups_');

        $foo = new Fixtures\Foo();
        $foo->setId(10);

        $fooArray = $this->autoMapper->map($foo, 'array', [MapperContext::GROUPS => ['group1']]);

        self::assertIsArray($fooArray);
        self::assertEquals(10, $fooArray['id']);

        $fooArray = $this->autoMapper->map($foo, 'array', [MapperContext::GROUPS => []]);

        self::assertIsArray($fooArray);
        self::assertEquals(10, $fooArray['id']);

        $fooArray = $this->autoMapper->map($foo, 'array');

        self::assertIsArray($fooArray);
        self::assertEquals(10, $fooArray['id']);
    }

    public function testDeepCloning(): void
    {
        $nodeA = new Fixtures\Node();
        $nodeB = new Fixtures\Node();
        $nodeB->parent = $nodeA;
        $nodeC = new Fixtures\Node();
        $nodeC->parent = $nodeB;
        $nodeA->parent = $nodeC;

        $newNode = $this->autoMapper->map($nodeA, Fixtures\Node::class);

        self::assertInstanceOf(Fixtures\Node::class, $newNode);
        self::assertNotSame($newNode, $nodeA);
        self::assertInstanceOf(Fixtures\Node::class, $newNode->parent);
        self::assertNotSame($newNode->parent, $nodeA->parent);
        self::assertInstanceOf(Fixtures\Node::class, $newNode->parent->parent);
        self::assertNotSame($newNode->parent->parent, $nodeA->parent->parent);
        self::assertInstanceOf(Fixtures\Node::class, $newNode->parent->parent->parent);
        self::assertSame($newNode, $newNode->parent->parent->parent);
    }

    public function testDeepCloningArray(): void
    {
        $nodeA = new Fixtures\Node();
        $nodeB = new Fixtures\Node();
        $nodeB->parent = $nodeA;
        $nodeC = new Fixtures\Node();
        $nodeC->parent = $nodeB;
        $nodeA->parent = $nodeC;

        $newNode = $this->autoMapper->map($nodeA, 'array');

        self::assertIsArray($newNode);
        self::assertIsArray($newNode['parent']);
        self::assertIsArray($newNode['parent']['parent']);
        self::assertIsArray($newNode['parent']['parent']['parent']);
        self::assertSame($newNode, $newNode['parent']['parent']['parent']);
    }

    public function testCircularReferenceDeep(): void
    {
        $foo = new Fixtures\CircularFoo();
        $bar = new Fixtures\CircularBar();
        $baz = new Fixtures\CircularBaz();

        $foo->bar = $bar;
        $bar->baz = $baz;
        $baz->foo = $foo;

        $newFoo = $this->autoMapper->map($foo, Fixtures\CircularFoo::class);

        self::assertNotSame($foo, $newFoo);
        self::assertNotNull($newFoo->bar);
        self::assertNotSame($bar, $newFoo->bar);
        self::assertNotNull($newFoo->bar->baz);
        self::assertNotSame($baz, $newFoo->bar->baz);
        self::assertNotNull($newFoo->bar->baz->foo);
        self::assertSame($newFoo, $newFoo->bar->baz->foo);
    }

    public function testCircularReferenceArray(): void
    {
        $nodeA = new Fixtures\Node();
        $nodeB = new Fixtures\Node();

        $nodeA->childs[] = $nodeB;
        $nodeB->childs[] = $nodeA;

        $newNode = $this->autoMapper->map($nodeA, 'array');

        self::assertIsArray($newNode);
        self::assertIsArray($newNode['childs'][0]);
        self::assertIsArray($newNode['childs'][0]['childs'][0]);
        self::assertSame($newNode, $newNode['childs'][0]['childs'][0]);
    }

    public function testPrivate(): void
    {
        $this->buildAutoMapper(mapPrivatePropertiesAndMethod: true);

        $user = new Fixtures\PrivateUser(10, 'foo', 'bar');
        /** @var Fixtures\PrivateUserDTO $userDto */
        $userDto = $this->autoMapper->map($user, Fixtures\PrivateUserDTO::class);

        self::assertInstanceOf(Fixtures\PrivateUserDTO::class, $userDto);
        self::assertSame(10, $userDto->getId());
        self::assertSame('foo', $userDto->getFirstName());
        self::assertSame('bar', $userDto->getLastName());
    }

    public function testConstructor(): void
    {
        $user = new Fixtures\UserDTO();
        $user->id = 10;
        $user->setName('foo');
        $user->age = 3;
        /** @var Fixtures\UserConstructorDTO $userDto */
        $userDto = $this->autoMapper->map($user, Fixtures\UserConstructorDTO::class);

        self::assertInstanceOf(Fixtures\UserConstructorDTO::class, $userDto);
        self::assertSame('10', $userDto->getId());
        self::assertSame('foo', $userDto->getName());
        self::assertSame(3, $userDto->getAge());
        self::assertTrue($userDto->getConstructor());
    }

    public function testConstructorWithNullSource(): void
    {
        $user = new Fixtures\UserDTO();
        $user->id = 10;
        $user->setName('foo');
        $user->age = null;
        /** @var Fixtures\UserConstructorDTO $userDto */
        $userDto = $this->autoMapper->map($user, Fixtures\UserConstructorDTO::class);

        self::assertInstanceOf(Fixtures\UserConstructorDTO::class, $userDto);
        self::assertSame('10', $userDto->getId());
        self::assertSame('foo', $userDto->getName());
        // since age is null we take default value from constructor
        self::assertSame(30, $userDto->getAge());
        self::assertTrue($userDto->getConstructor());
    }

    public function testConstructorAndRelationMissing(): void
    {
        $user = ['name' => 'foo'];
        $this->expectException(MissingConstructorArgumentsException::class);

        /** @var Fixtures\UserConstructorDTOWithRelation $userDto */
        $userDto = $this->autoMapper->map($user, Fixtures\UserConstructorDTOWithRelation::class);
    }

    public function testConstructorAndRelationMissing2(): void
    {
        $user = ['name' => 'foo', 'int' => ['foo' => 1]];
        /** @var Fixtures\UserConstructorDTOWithRelation $userDto */
        $userDto = $this->autoMapper->map($user, Fixtures\UserConstructorDTOWithRelation::class);

        self::assertInstanceOf(Fixtures\UserConstructorDTOWithRelation::class, $userDto);
        self::assertSame(1, $userDto->int->foo);
        self::assertSame('foo', $userDto->name);
        self::assertSame(30, $userDto->age);
    }

    public function testConstructorAndRelationMissingAndContext(): void
    {
        $user = ['name' => 'foo'];
        /** @var Fixtures\UserConstructorDTOWithRelation $userDto */
        $userDto = $this->autoMapper->map($user, Fixtures\UserConstructorDTOWithRelation::class, [
            MapperContext::CONSTRUCTOR_ARGUMENTS => [
                Fixtures\UserConstructorDTOWithRelation::class => ['int' => new IntDTO(1)],
            ],
        ]);

        self::assertInstanceOf(Fixtures\UserConstructorDTOWithRelation::class, $userDto);
        self::assertSame(1, $userDto->int->foo);
        self::assertSame('foo', $userDto->name);
        self::assertSame(30, $userDto->age);
    }

    public function testConstructorArrayArgumentFromContext(): void
    {
        $data = ['baz' => 'baz'];
        /** @var ConstructorWithDefaultValues $userDto */
        $object = $this->autoMapper->map($data, ConstructorWithDefaultValues::class, [MapperContext::CONSTRUCTOR_ARGUMENTS => [
            ConstructorWithDefaultValues::class => ['someOtters' => [1]],
        ]]);

        self::assertInstanceOf(ConstructorWithDefaultValues::class, $object);
        self::assertSame('baz', $object->baz);
        self::assertSame([1], $object->someOtters);
    }

    public function testConstructorNotAllowed(): void
    {
        $this->buildAutoMapper(mapPrivatePropertiesAndMethod: true, constructorStrategy: ConstructorStrategy::NEVER, classPrefix: 'NotAllowedMapper_');

        $user = new Fixtures\UserDTO();
        $user->id = 10;
        $user->setName('foo');
        $user->age = 3;

        /** @var Fixtures\UserConstructorDTO $userDto */
        $userDto = $this->autoMapper->map($user, Fixtures\UserConstructorDTO::class);

        self::assertInstanceOf(Fixtures\UserConstructorDTO::class, $userDto);
        self::assertSame('10', $userDto->getId());
        self::assertSame('foo', $userDto->getName());
        self::assertSame(3, $userDto->getAge());
        self::assertFalse($userDto->getConstructor());
    }

    public function testConstructorForced(): void
    {
        $this->buildAutoMapper(constructorStrategy: ConstructorStrategy::ALWAYS, classPrefix: 'AlwaysConstructorMapper_');

        $data = ['baz' => 'baz'];
        /** @var ConstructorWithDefaultValues $object */
        $object = $this->autoMapper->map($data, ConstructorWithDefaultValues::class);

        self::assertInstanceOf(ConstructorWithDefaultValues::class, $object);
        self::assertSame(1, $object->foo);
        self::assertSame(0, $object->bar);
        self::assertSame('baz', $object->baz);

        $data = new SourceForConstructorWithDefaultValues();
        $data->foo = 10;
        /** @var ConstructorWithDefaultValues $object */
        $object = $this->autoMapper->map($data, ConstructorWithDefaultValues::class, [MapperContext::CONSTRUCTOR_ARGUMENTS => [
            ConstructorWithDefaultValues::class => ['baz' => 'test'],
        ]]);

        self::assertInstanceOf(ConstructorWithDefaultValues::class, $object);
        self::assertSame(10, $object->foo);
        self::assertSame(0, $object->bar);
        self::assertSame('test', $object->baz);
    }

    public function testConstructorForcedException(): void
    {
        $this->buildAutoMapper(constructorStrategy: ConstructorStrategy::ALWAYS, classPrefix: 'AlwaysConstructorMapper_');
        $data = new SourceForConstructorWithDefaultValues();
        $data->foo = 10;

        $this->expectException(MissingConstructorArgumentsException::class);

        $this->autoMapper->map($data, ConstructorWithDefaultValues::class);
    }

    public function testConstructorWithDefaultFromStdClass(): void
    {
        $data = (object) ['baz' => 'baz'];
        /** @var ConstructorWithDefaultValues $object */
        $object = $this->autoMapper->map($data, ConstructorWithDefaultValues::class);

        self::assertInstanceOf(ConstructorWithDefaultValues::class, $object);
    }

    public function testConstructorWithDefault(): void
    {
        $user = new Fixtures\UserDTONoAge();
        $user->id = 10;
        $user->name = 'foo';
        /** @var Fixtures\UserConstructorDTO $userDto */
        $userDto = $this->autoMapper->map($user, Fixtures\UserConstructorDTO::class);

        self::assertInstanceOf(Fixtures\UserConstructorDTO::class, $userDto);
        self::assertSame('10', $userDto->getId());
        self::assertSame('foo', $userDto->getName());
        self::assertSame(30, $userDto->getAge());
    }

    public function testConstructorWithDefaultsAsObjects(): void
    {
        $data = ['baz' => 'baz'];
        /** @var ConstructorWithDefaultValuesAsObjects $object */
        $object = $this->autoMapper->map($data, ConstructorWithDefaultValuesAsObjects::class);

        self::assertInstanceOf(ConstructorWithDefaultValuesAsObjects::class, $object);
        self::assertInstanceOf(\DateTimeImmutable::class, $object->date);
        self::assertInstanceOf(IntDTO::class, $object->IntDTO);
        self::assertSame('baz', $object->baz);

        $stdClassData = (object) $data;
        /** @var ConstructorWithDefaultValuesAsObjects $object */
        $object = $this->autoMapper->map($stdClassData, ConstructorWithDefaultValuesAsObjects::class);

        self::assertInstanceOf(ConstructorWithDefaultValuesAsObjects::class, $object);
        self::assertInstanceOf(\DateTimeImmutable::class, $object->date);
        self::assertInstanceOf(IntDTO::class, $object->IntDTO);
        self::assertSame('baz', $object->baz);
    }

    public function testConstructorDisable(): void
    {
        $this->buildAutoMapper(mapPrivatePropertiesAndMethod: true);

        $user = new Fixtures\UserDTONoName();
        $user->id = 10;
        /** @var Fixtures\UserConstructorDTO $userDto */
        $userDto = $this->autoMapper->map($user, Fixtures\UserConstructorDTO::class);

        self::assertInstanceOf(Fixtures\UserConstructorDTO::class, $userDto);
        self::assertSame('10', $userDto->getId());
        self::assertNull($userDto->getName());
        self::assertNull($userDto->getAge());
    }

    public function testMaxDepth(): void
    {
        if (!class_exists(MaxDepth::class)) {
            self::markTestSkipped('Symfony Serializer is required to run this test.');
        }

        $foo = new Fixtures\FooMaxDepth(0, new Fixtures\FooMaxDepth(1, new Fixtures\FooMaxDepth(2, new Fixtures\FooMaxDepth(3, new Fixtures\FooMaxDepth(4)))));
        $fooArray = $this->autoMapper->map($foo, 'array');

        self::assertNotNull($fooArray['child']);
        self::assertNotNull($fooArray['child']['child']);
        self::assertFalse(isset($fooArray['child']['child']['child']));
    }

    public function testIgnoreInSource(): void
    {
        if (!class_exists(Ignore::class)) {
            self::markTestSkipped('Symfony Serializer is required to run this test.');
        }

        $foo = new Fixtures\FooIgnore();
        $foo->id = 5;
        $fooArray = $this->autoMapper->map($foo, 'array');

        self::assertSame([], $fooArray);
    }

    public function testIgnoreInTarget(): void
    {
        if (!class_exists(Ignore::class)) {
            self::markTestSkipped('Symfony Serializer is required to run this test.');
        }

        $foo = new Fixtures\Foo();
        $fooIgnore = $this->autoMapper->map($foo, Fixtures\FooIgnore::class);

        self::assertNull($fooIgnore->id);
    }

    public function testObjectToPopulate(): void
    {
        $user = new Fixtures\User(1, 'yolo', '13');
        $userDtoToPopulate = new Fixtures\UserDTO();

        $userDto = $this->autoMapper->map($user, Fixtures\UserDTO::class, [MapperContext::TARGET_TO_POPULATE => $userDtoToPopulate]);

        self::assertSame($userDtoToPopulate, $userDto);
    }

    public function testObjectToPopulateWithoutContext(): void
    {
        $user = new Fixtures\User(1, 'yolo', '13');
        $userDtoToPopulate = new Fixtures\UserDTO();

        $userDto = $this->autoMapper->map($user, $userDtoToPopulate);

        self::assertSame($userDtoToPopulate, $userDto);
    }

    public function testArrayToPopulate(): void
    {
        $user = new Fixtures\User(1, 'yolo', '13');
        $array = [];
        $arrayMapped = $this->autoMapper->map($user, $array);

        self::assertIsArray($arrayMapped);
        self::assertSame(1, $arrayMapped['id']);
        self::assertSame('yolo', $arrayMapped['name']);
        self::assertSame('13', $arrayMapped['age']);
    }

    public function testCircularReferenceLimitOnContext(): void
    {
        $nodeA = new Fixtures\Node();
        $nodeA->parent = $nodeA;

        $context = new MapperContext();
        $context->setCircularReferenceLimit(1);

        $this->expectException(CircularReferenceException::class);

        $this->autoMapper->map($nodeA, 'array', $context->toArray());
    }

    public function testCircularReferenceHandlerOnContext(): void
    {
        $nodeA = new Fixtures\Node();
        $nodeA->parent = $nodeA;

        $context = new MapperContext();
        $context->setCircularReferenceHandler(function () {
            return 'foo';
        });

        $nodeArray = $this->autoMapper->map($nodeA, 'array', $context->toArray());

        self::assertSame('foo', $nodeArray['parent']);
    }

    public function testAllowedAttributes(): void
    {
        $user = new Fixtures\User(1, 'yolo', '13');
        $address = new Address();
        $address->setCity('some city');
        $user->setAddress($address);

        $this->buildAutoMapper(mapPrivatePropertiesAndMethod: true);

        /** @var Fixtures\UserDTO $userDto */
        $userDto = $this->autoMapper->map($user, Fixtures\UserDTO::class, [MapperContext::ALLOWED_ATTRIBUTES => ['id', 'age', 'address']]);

        self::assertNull($userDto->getName());
        self::assertInstanceOf(AddressDTO::class, $userDto->address);
        self::assertSame('some city', $userDto->address->city);
    }

    public function testIgnoredAttributes(): void
    {
        $user = new Fixtures\User(1, 'yolo', '13');
        $userDto = $this->autoMapper->map($user, Fixtures\UserDTO::class, [MapperContext::IGNORED_ATTRIBUTES => ['name']]);

        self::assertNull($userDto->getName());
    }

    public function testNameConverter(): void
    {
        if (!interface_exists(AdvancedNameConverterInterface::class)) {
            self::markTestSkipped('Symfony Serializer is required to run this test.');
        }

        if (Kernel::MAJOR_VERSION >= 7 && Kernel::MINOR_VERSION >= 2) {
            $nameConverter = new class() implements NameConverterInterface {
                public function normalize($propertyName, ?string $class = null, ?string $format = null, array $context = []): string
                {
                    if ('id' === $propertyName) {
                        return '@id';
                    }

                    return $propertyName;
                }

                public function denormalize($propertyName, ?string $class = null, ?string $format = null, array $context = []): string
                {
                    if ('@id' === $propertyName) {
                        return 'id';
                    }

                    return $propertyName;
                }
            };
        } else {
            $nameConverter = new class() implements AdvancedNameConverterInterface {
                public function normalize(string $propertyName, ?string $class = null, ?string $format = null, array $context = []): string
                {
                    if ('id' === $propertyName) {
                        return '@id';
                    }

                    return $propertyName;
                }

                public function denormalize(string $propertyName, ?string $class = null, ?string $format = null, array $context = []): string
                {
                    if ('@id' === $propertyName) {
                        return 'id';
                    }

                    return $propertyName;
                }
            };
        }

        $autoMapper = AutoMapper::create(new Configuration(classPrefix: 'Mapper2_'), nameConverter: $nameConverter);
        $user = new Fixtures\User(1, 'yolo', '13');

        $userArray = $autoMapper->map($user, 'array');

        self::assertIsArray($userArray);
        self::assertArrayHasKey('@id', $userArray);
        self::assertSame(1, $userArray['@id']);
    }

    public function testDefaultArguments(): void
    {
        $user = new Fixtures\UserDTONoAge();
        $user->id = 10;
        $user->name = 'foo';

        $context = new MapperContext();
        $context->setConstructorArgument(Fixtures\UserConstructorDTO::class, 'age', 50);

        /** @var Fixtures\UserConstructorDTO $userDto */
        $userDto = $this->autoMapper->map($user, Fixtures\UserConstructorDTO::class, $context->toArray());

        self::assertInstanceOf(Fixtures\UserConstructorDTO::class, $userDto);
        self::assertSame(50, $userDto->getAge());
    }

    public function testDiscriminator(): void
    {
        if (!class_exists(ClassDiscriminatorFromClassMetadata::class)) {
            self::markTestSkipped('Symfony Serializer is required to run this test.');
        }

        $this->buildAutoMapper(classPrefix: 'Discriminator');

        $data = [
            'type' => 'cat',
        ];

        $pet = $this->autoMapper->map($data, Fixtures\Pet::class);

        self::assertInstanceOf(Fixtures\Cat::class, $pet);
    }

    public function testInvalidMappingBothArray(): void
    {
        $this->expectException(InvalidMappingException::class);

        $data = ['test' => 'foo'];
        $array = $this->autoMapper->map($data, 'array');
    }

    public function testNoAutoRegister(): void
    {
        $this->expectException(InvalidMappingException::class);

        $automapper = AutoMapper::create(new Configuration(autoRegister: false, classPrefix: 'NoAutoRegister_'));
        $automapper->getMapper(Fixtures\User::class, Fixtures\UserDTO::class);
    }

    public function testStrictTypes(): void
    {
        $this->expectException(\TypeError::class);

        $automapper = AutoMapper::create(new Configuration(strictTypes: true, classPrefix: 'StrictTypes_'));
        $data = ['foo' => 1.1];
        $automapper->map($data, IntDTO::class);
    }

    public function testStrictTypesFromMapper(): void
    {
        $this->expectException(\TypeError::class);

        $automapper = AutoMapper::create(new Configuration(strictTypes: false, classPrefix: 'StrictTypesFromMapper_'));
        $data = ['foo' => 1.1];
        $automapper->map($data, Fixtures\IntDTOWithMapper::class);
    }

    public function testWithMixedArray(): void
    {
        $user = new Fixtures\User(1, 'yolo', '13');
        $user->setProperties(['foo' => 'bar']);

        /** @var Fixtures\UserDTOProperties $dto */
        $dto = $this->autoMapper->map($user, Fixtures\UserDTOProperties::class);

        self::assertInstanceOf(Fixtures\UserDTOProperties::class, $dto);
        self::assertSame(['foo' => 'bar'], $dto->getProperties());
    }

    public function testCustomTransformerFromArrayToObject(): void
    {
        $this->buildAutoMapper(mapPrivatePropertiesAndMethod: true, transformerFactories: [new MoneyTransformerFactory()]);

        $data = [
            'id' => 4582,
            'price' => [
                'amount' => 1000,
                'currency' => 'EUR',
            ],
        ];
        $order = $this->autoMapper->map($data, Order::class);

        self::assertInstanceOf(Order::class, $order);
        self::assertInstanceOf(\Money\Money::class, $order->price);
        self::assertEquals(1000, $order->price->getAmount());
        self::assertEquals('EUR', $order->price->getCurrency()->getCode());
    }

    public function testCustomTransformerFromObjectToArray(): void
    {
        $this->buildAutoMapper(transformerFactories: [new MoneyTransformerFactory()]);

        $order = new Order();
        $order->id = 4582;
        $order->price = new \Money\Money(1000, new \Money\Currency('EUR'));
        $data = $this->autoMapper->map($order, 'array');

        self::assertIsArray($data);
        self::assertEquals(4582, $data['id']);
        self::assertIsArray($data['price']);
        self::assertEquals(1000, $data['price']['amount']);
        self::assertEquals('EUR', $data['price']['currency']);
    }

    public function testCustomTransformerFromObjectToObject(): void
    {
        $this->buildAutoMapper(transformerFactories: [new MoneyTransformerFactory()]);

        $order = new Order();
        $order->id = 4582;
        $order->price = new \Money\Money(1000, new \Money\Currency('EUR'));
        $newOrder = new Order();
        $newOrder = $this->autoMapper->map($order, $newOrder);

        self::assertInstanceOf(Order::class, $newOrder);
        self::assertInstanceOf(\Money\Money::class, $newOrder->price);
        self::assertEquals(1000, $newOrder->price->getAmount());
        self::assertEquals('EUR', $newOrder->price->getCurrency()->getCode());
    }

    public function testIssue425(): void
    {
        $data = [1, 2, 3, 4, 5];
        $foo = new Fixtures\Issue425\Foo($data);
        $bar = $this->autoMapper->map($foo, Fixtures\Issue425\Bar::class);

        self::assertEquals($data, $bar->property);
    }

    public function testObjectWithPropertyAsUnknownArrayToObject(): void
    {
        $entity = new Page();
        $entity->components[] = ['name' => 'my name'];

        $bar = $this->autoMapper->map($entity, PageDto::class);

        self::assertEquals('my title', $bar->title);
        self::assertCount(1, $bar->components);
        self::assertInstanceOf(ComponentDto::class, $bar->components[0]);
        self::assertEquals('my name', $bar->components[0]->name);
    }

    public function testObjectToObjectWithPropertyAsUnknownArray(): void
    {
        $dto = new PageDto('my title', [new ComponentDto('my name')]);
        $bar = $this->autoMapper->map($dto, Page::class);

        self::assertEquals('my title', $bar->title);
        self::assertIsArray($bar->components);
        self::assertCount(1, $bar->components);
        self::assertIsArray($bar->components[0]);
        self::assertEquals('my name', $bar->components[0]['name']);
    }

    public function testArrayWithKeys(): void
    {
        $arguments = ['foo', 'azerty' => 'bar', 'baz'];
        $parameters = new Fixtures\Parameters($arguments);

        $data = $this->autoMapper->map($parameters, 'array');
        self::assertEquals($arguments, $data['parameters']);

        // ----------------------------------------------------------------------------------------------------

        $arguments = ['foo', 'bar', 'baz'];
        $parameters = new Fixtures\Parameters($arguments);

        $data = $this->autoMapper->map($parameters, 'array');
        self::assertEquals($arguments, $data['parameters']);

        // ----------------------------------------------------------------------------------------------------

        $arguments = ['foo' => 'azerty', 'bar' => 'qwerty', 'baz' => 'dvorak'];
        $parameters = new Fixtures\Parameters($arguments);

        $data = $this->autoMapper->map($parameters, 'array');
        self::assertEquals($arguments, $data['parameters']);
    }

    public function testArrayWithFailedKeys(): void
    {
        $arguments = ['foo', 'azerty' => 'bar', 'baz'];
        $parameters = new Fixtures\WrongParameters($arguments);

        $data = $this->autoMapper->map($parameters, 'array');
        self::assertNotEquals($arguments, $data['parameters']);

        // ----------------------------------------------------------------------------------------------------

        $arguments = ['foo', 'bar', 'baz'];
        $parameters = new Fixtures\WrongParameters($arguments);

        $data = $this->autoMapper->map($parameters, 'array');
        self::assertEquals($arguments, $data['parameters']);

        // ----------------------------------------------------------------------------------------------------

        $arguments = ['foo' => 'azerty', 'bar' => 'qwerty', 'baz' => 'dvorak'];
        $parameters = new Fixtures\WrongParameters($arguments);

        $data = $this->autoMapper->map($parameters, 'array');
        self::assertNotEquals($arguments, $data['parameters']);
    }

    public function testSymfonyUlid(): void
    {
        // array -> object
        $data = [
            'ulid' => '01EXE87A54256F05N8P6SB2M9M',
            'name' => 'Grégoire Pineau',
        ];
        /** @var Fixtures\SymfonyUlidUser $user */
        $user = $this->autoMapper->map($data, Fixtures\SymfonyUlidUser::class);
        self::assertInstanceOf(Ulid::class, $user->getUlid());
        self::assertEquals('01EXE87A54256F05N8P6SB2M9M', $user->getUlid()->toBase32());
        self::assertEquals('Grégoire Pineau', $user->name);

        // object -> array
        $user = new Fixtures\SymfonyUlidUser(new Ulid('01EXE89XR69GERC6GV3J4X38FJ'), 'Grégoire Pineau');
        $data = $this->autoMapper->map($user, 'array');
        self::assertEquals('01EXE89XR69GERC6GV3J4X38FJ', $data['ulid']);
        self::assertEquals('Grégoire Pineau', $data['name']);

        // object -> object
        $user = new Fixtures\SymfonyUlidUser(new Ulid('01EXE8A6TNWVCEGMZ36AX8N9MC'), 'Grégoire Pineau');
        /** @var Fixtures\SymfonyUlidUser $newUser */
        $newUser = $this->autoMapper->map($user, Fixtures\SymfonyUlidUser::class);
        self::assertInstanceOf(Ulid::class, $user->getUlid());
        self::assertEquals('01EXE8A6TNWVCEGMZ36AX8N9MC', $newUser->getUlid()->toBase32());
        self::assertEquals('Grégoire Pineau', $newUser->name);

        // array -> object // uuid v1
        $uuidV1 = Uuid::v1();
        $data = [
            'uuid' => $uuidV1->toRfc4122(),
            'name' => 'Grégoire Pineau',
        ];
        /** @var Fixtures\SymfonyUuidUser $user */
        $user = $this->autoMapper->map($data, Fixtures\SymfonyUuidUser::class);
        self::assertInstanceOf(Uuid::class, $user->getUuid());
        self::assertEquals($uuidV1->toRfc4122(), $user->getUuid()->toRfc4122());
        self::assertEquals('Grégoire Pineau', $user->name);
        // object -> array // uuid v3
        $uuidV3 = Uuid::v3(Uuid::v4(), 'jolicode');
        $user = new Fixtures\SymfonyUuidUser($uuidV3, 'Grégoire Pineau');
        $data = $this->autoMapper->map($user, 'array');
        self::assertEquals($uuidV3->toRfc4122(), $data['uuid']);
        self::assertEquals('Grégoire Pineau', $data['name']);

        // object -> object // uuid v4
        $uuidV4 = Uuid::v4();
        $user = new Fixtures\SymfonyUuidUser($uuidV4, 'Grégoire Pineau');
        /** @var Fixtures\SymfonyUuidUser $newUser */
        $newUser = $this->autoMapper->map($user, Fixtures\SymfonyUuidUser::class);
        self::assertInstanceOf(Uuid::class, $user->getUuid());
        self::assertEquals($uuidV4->toRfc4122(), $newUser->getUuid()->toRfc4122());
        self::assertEquals('Grégoire Pineau', $newUser->name);
    }

    public function testSkipNullValues(): void
    {
        $entity = new Fixtures\SkipNullValues\Entity();
        $entity->setName('foobar');
        $input = new Fixtures\SkipNullValues\Input();

        /** @var Fixtures\SkipNullValues\Entity $entity */
        $entity = $this->autoMapper->map($input, $entity, [MapperContext::SKIP_NULL_VALUES => true]);
        self::assertEquals('foobar', $entity->getName());
    }

    public function testAdderAndRemoverWithClass(): void
    {
        if (!class_exists(ClassDiscriminatorFromClassMetadata::class)) {
            self::markTestSkipped('Symfony Serializer is required to run this test.');
        }

        $this->buildAutoMapper(mapPrivatePropertiesAndMethod: true);

        $petOwner = [
            'pets' => [
                ['type' => 'cat', 'name' => 'Félix'],
                ['type' => 'dog', 'name' => 'Coco', 'bark' => 'Wouf'],
            ],
        ];

        $petOwnerData = $this->autoMapper->map($petOwner, PetOwner::class);

        self::assertIsArray($petOwnerData->getPets());
        self::assertCount(2, $petOwnerData->getPets());
        self::assertSame('Félix', $petOwnerData->getPets()[0]->name);
        self::assertSame('cat', $petOwnerData->getPets()[0]->type);
        self::assertSame('Coco', $petOwnerData->getPets()[1]->name);
        self::assertSame('dog', $petOwnerData->getPets()[1]->type);
        self::assertSame('Wouf', $petOwnerData->getPets()[1]->bark);
    }

    public function testAdderAndRemoverWithInstance(): void
    {
        if (!class_exists(ClassDiscriminatorFromClassMetadata::class)) {
            self::markTestSkipped('Symfony Serializer is required to run this test.');
        }

        $this->buildAutoMapper(mapPrivatePropertiesAndMethod: true);

        $fish = new Fish();
        $fish->name = 'Nemo';
        $fish->type = 'fish';

        $petOwner = new PetOwner();
        $petOwner->addPet($fish);

        $petOwnerAsArray = [
            'pets' => [
                ['type' => 'cat', 'name' => 'Félix'],
                ['type' => 'dog', 'name' => 'Coco'],
            ],
        ];

        $this->autoMapper->map($petOwnerAsArray, $petOwner);

        self::assertIsArray($petOwner->getPets());
        self::assertCount(3, $petOwner->getPets());
        self::assertSame('Nemo', $petOwner->getPets()[0]->name);
        self::assertSame('fish', $petOwner->getPets()[0]->type);
        self::assertSame('Félix', $petOwner->getPets()[1]->name);
        self::assertSame('cat', $petOwner->getPets()[1]->type);
        self::assertSame('Coco', $petOwner->getPets()[2]->name);
        self::assertSame('dog', $petOwner->getPets()[2]->type);
    }

    public function testAdderAndRemoverWithNull(): void
    {
        $petOwner = [
            'pets' => [
                null,
                null,
            ],
        ];

        $petOwnerData = $this->autoMapper->map($petOwner, PetOwner::class);

        self::assertIsArray($petOwnerData->getPets());
        self::assertCount(0, $petOwnerData->getPets());
    }

    public function testAdderAndRemoverWithConstructorArguments(): void
    {
        if (!class_exists(ClassDiscriminatorFromClassMetadata::class)) {
            self::markTestSkipped('Symfony Serializer is required to run this test.');
        }

        $petOwner = [
            'pets' => [
                ['type' => 'cat', 'name' => 'Félix'],
            ],
        ];

        $petOwnerData = $this->autoMapper->map($petOwner, PetOwnerWithConstructorArguments::class);

        self::assertIsArray($petOwnerData->getPets());
        self::assertCount(1, $petOwnerData->getPets());
        self::assertSame('Félix', $petOwnerData->getPets()[0]->name);
        self::assertSame('cat', $petOwnerData->getPets()[0]->type);
    }

    public function testIssueTargetToPopulate(): void
    {
        $source = new Fixtures\IssueTargetToPopulate\VatModel();
        $source->setCountryCode('fr');
        $source->setStandardVatRate(21.0);
        $source->setReducedVatRate(5.5);
        $source->setDisplayIncVatPrices(true);

        $target = new Fixtures\IssueTargetToPopulate\VatEntity('en');
        $target->setId(1);

        /** @var Fixtures\IssueTargetToPopulate\VatEntity $target */
        $target = $this->autoMapper->map($source, $target);

        self::assertEquals(1, $target->getId());
        self::assertEquals('fr', $target->getCountryCode());
        self::assertEquals(21.0, $target->getStandardVatRate());
        self::assertEquals(5.5, $target->getReducedVatRate());
        self::assertTrue($target->isDisplayIncVatPrices());
    }

    public function testPartialConstructorWithTargetToPopulate(): void
    {
        $source = new Fixtures\User(1, 'Jack', 37);
        /** @var Fixtures\UserPartialConstructor $target */
        $target = $this->autoMapper->map($source, Fixtures\UserPartialConstructor::class);

        self::assertEquals(1, $target->getId());
        self::assertEquals('Jack', $target->name);
        self::assertEquals(37, $target->age);
    }

    public function testEnum(): void
    {
        $this->buildAutoMapper(mapPrivatePropertiesAndMethod: true);

        // enum source
        $address = new AddressWithEnum();
        $address->setType(AddressType::APARTMENT);
        /** @var array $addressData */
        $addressData = $this->autoMapper->map($address, 'array');
        $var = AddressType::APARTMENT; // only here for lower PHP version handling
        self::assertEquals($var->value, $addressData['type']);

        // enum target
        $data = ['type' => 'flat'];
        /** @var AddressWithEnum $address */
        $address = $this->autoMapper->map($data, AddressWithEnum::class);
        self::assertEquals(AddressType::FLAT, $address->getType());

        // both source & target are enums
        $address = new AddressWithEnum();
        $address->setType(AddressType::FLAT);
        /** @var AddressWithEnum $copyAddress */
        $copyAddress = $this->autoMapper->map($address, AddressWithEnum::class);
        self::assertEquals($address->getType(), $copyAddress->getType());
    }

    public function testTargetReadonlyClass(): void
    {
        $data = ['city' => 'Nantes'];
        $toPopulate = new Fixtures\AddressDTOSecondReadonlyClass('city', '67100');

        self::expectException(ReadOnlyTargetException::class);
        $this->autoMapper->map($data, $toPopulate);
    }

    public function testTargetReadonlyClassSkippedContext(): void
    {
        $data = ['city' => 'Nantes'];
        $toPopulate = new Fixtures\AddressDTOSecondReadonlyClass('city', '67100');

        $this->autoMapper->map($data, $toPopulate, [MapperContext::ALLOW_READONLY_TARGET_TO_POPULATE => true]);

        // value didn't changed because the object class is readonly, we can't change the value there
        self::assertEquals('city', $toPopulate->city);
    }

    public function testTargetReadonlyClassAllowed(): void
    {
        $this->buildAutoMapper(true);

        $data = ['city' => 'Nantes'];
        $toPopulate = new AddressDTOReadonlyClass('city');

        $this->autoMapper->map($data, $toPopulate);

        // value didn't changed because the object class is readonly, we can't change the value there
        self::assertEquals('city', $toPopulate->city);
    }

    /**
     * @dataProvider provideReadonly
     */
    public function testReadonly(string $addressWithReadonlyClass): void
    {
        $this->buildAutoMapper(allowReadOnlyTargetToPopulate: true, mapPrivatePropertiesAndMethod: true);

        $address = new Address();
        $address->setCity('city');

        self::assertSame(
            ['city' => 'city'],
            $this->autoMapper->map(new $addressWithReadonlyClass('city'), 'array')
        );

        self::assertEquals(
            $address,
            $this->autoMapper->map(new $addressWithReadonlyClass('city'), Address::class)
        );

        self::assertEquals(
            new $addressWithReadonlyClass('city'),
            $this->autoMapper->map(['city' => 'city'], $addressWithReadonlyClass)
        );

        self::assertEquals(
            new $addressWithReadonlyClass('city'),
            $this->autoMapper->map($address, $addressWithReadonlyClass)
        );

        // assert that readonly property / class as a target object does not break automapping
        $address->setCity('another city');
        self::assertEquals(
            new $addressWithReadonlyClass('city'),
            $this->autoMapper->map($address, new $addressWithReadonlyClass('city'))
        );
    }

    public static function provideReadonly(): iterable
    {
        yield [AddressDTOWithReadonly::class];
        yield [AddressDTOWithReadonlyPromotedProperty::class];

        if (\PHP_VERSION_ID >= 80200) {
            yield [AddressDTOReadonlyClass::class];
        }
    }

    public function testDateTimeFormatCanBeConfiguredFromContext(): void
    {
        self::assertSame(
            ['dateTime' => '2021-01-01'],
            $this->autoMapper->map(
                new ObjectWithDateTime(new \DateTimeImmutable('2021-01-01 12:00:00')),
                'array',
                [MapperContext::DATETIME_FORMAT => 'Y-m-d']
            )
        );

        self::assertEquals(
            new ObjectWithDateTime(new \DateTimeImmutable('2023-01-24 00:00:00')),
            $this->autoMapper->map(
                ['dateTime' => '24-01-2023'],
                ObjectWithDateTime::class,
                [MapperContext::DATETIME_FORMAT => '!d-m-Y']
            )
        );
    }

    /**
     * @param class-string<HasDateTime|HasDateTimeWithNullValue|HasDateTimeImmutable|HasDateTimeImmutableWithNullValue|HasDateTimeInterfaceWithImmutableInstance|HasDateTimeInterfaceWithNullValue> $from
     * @param class-string<HasDateTime|HasDateTimeWithNullValue|HasDateTimeImmutable|HasDateTimeImmutableWithNullValue|HasDateTimeInterfaceWithImmutableInstance|HasDateTimeInterfaceWithNullValue> $to
     *
     * @dataProvider dateTimeMappingProvider
     */
    public function testDateTimeMapping(
        string $from,
        string $to,
        bool $isError,
    ): void {
        if ($isError) {
            $this->expectException(\TypeError::class);
        }

        $fromObject = $from::create();
        $toObject = $this->autoMapper->map($fromObject, $to);

        self::assertInstanceOf($to, $toObject);
        self::assertEquals($fromObject->getString(), $toObject->getString());
    }

    /**
     * @return iterable<array{0:HasDateTime|HasDateTimeWithNullValue|HasDateTimeImmutable|HasDateTimeImmutableWithNullValue|HasDateTimeInterfaceWithImmutableInstance|HasDateTimeInterfaceWithNullValue,1:HasDateTime|HasDateTimeWithNullValue|HasDateTimeImmutable|HasDateTimeImmutableWithNullValue|HasDateTimeInterfaceWithImmutableInstance|HasDateTimeInterfaceWithNullValue,2:bool}>
     */
    public function dateTimeMappingProvider(): iterable
    {
        $classes = [
            HasDateTime::class,
            HasDateTimeWithNullValue::class,
            HasDateTimeImmutable::class,
            HasDateTimeImmutableWithNullValue::class,
            HasDateTimeInterfaceWithImmutableInstance::class,
            HasDateTimeInterfaceWithMutableInstance::class,
            HasDateTimeInterfaceWithNullValue::class,
        ];

        foreach ($classes as $from) {
            foreach ($classes as $to) {
                $fromIsNullable = str_contains($from, 'NullValue');
                $toIsNullable = str_contains($to, 'NullValue');
                $isError = $fromIsNullable && !$toIsNullable;
                yield "$from to $to" => [$from, $to, $isError];
            }
        }
    }

    public function testMapToContextAttribute(): void
    {
        self::assertSame(
            [
                'propertyWithDefaultValue' => 'foo',
                'value' => 'foo_bar_baz',
                'virtualProperty' => 'foo_bar_baz',
            ],
            $this->autoMapper->map(
                new ClassWithMapToContextAttribute('bar'),
                'array',
                [MapperContext::MAP_TO_ACCESSOR_PARAMETER => ['suffix' => 'baz', 'prefix' => 'foo']]
            )
        );
    }

    public function testMapClassWithPrivateProperty(): void
    {
        $this->buildAutoMapper(mapPrivatePropertiesAndMethod: true);

        self::assertSame(
            ['bar' => 'bar', 'foo' => 'foo'],
            $this->autoMapper->map(new ClassWithPrivateProperty('foo'), 'array')
        );
        self::assertEquals(
            new ClassWithPrivateProperty('foo'),
            $this->autoMapper->map(['foo' => 'foo'], ClassWithPrivateProperty::class)
        );
    }

    /**
     * Generated mapper will be different from what "testMapClassWithPrivateProperty" generates,
     * hence the duplicated class, to avoid any conflict with autloading.
     */
    public function testItCanDisablePrivatePropertiesMapping(): void
    {
        $this->buildAutoMapper(classPrefix: 'DontMapPrivate_');

        self::assertSame(
            [],
            $this->autoMapper->map(new ClassWithPrivateProperty('foo'), 'array')
        );
    }

    public function testItCanMapFromArrayWithMissingNullableProperty(): void
    {
        self::assertEquals(
            new ClassWithNullablePropertyInConstructor(foo: 1),
            $this->autoMapper->map(['foo' => 1], ClassWithNullablePropertyInConstructor::class)
        );
    }

    public function testNoErrorWithUninitializedProperty(): void
    {
        $this->buildAutoMapper(mapPrivatePropertiesAndMethod: true);

        self::assertSame(
            ['bar' => 'bar'],
            $this->autoMapper->map(new Uninitialized(), 'array', [MapperContext::SKIP_UNINITIALIZED_VALUES => true])
        );
    }

    public function testMapWithForcedTimeZone(): void
    {
        $this->buildAutoMapper(mapPrivatePropertiesAndMethod: true);

        /** @var HasDateTimeImmutable $utc */
        $utc = $this->autoMapper->map(
            ['dateTime' => '2024-03-11 17:00:00'],
            HasDateTimeImmutable::class,
            [MapperContext::DATETIME_FORMAT => 'Y-m-d H:i:s', MapperContext::DATETIME_FORCE_TIMEZONE => 'Europe/Paris']
        );

        self::assertEquals(new \DateTimeZone('Europe/Paris'), $utc->dateTime->getTimezone());
    }

    public function testAutoMappingGenerator(): void
    {
        $this->buildAutoMapper(mapPrivatePropertiesAndMethod: true);
        $foo = new FooGenerator();

        /** @var Fixtures\BarGenerator $bar */
        $bar = $this->autoMapper->map($foo, Fixtures\BarGenerator::class);

        // Test mapping to class
        self::assertInstanceOf(Fixtures\BarGenerator::class, $bar);

        self::assertSame([1, 2, 3, 'foo' => 'bar'], $bar->generator);
        self::assertSame([1, 2, 3], $bar->array);

        // Test mapping to array
        $data = $this->autoMapper->map($foo, 'array');

        self::assertSame([1, 2, 3, 'foo' => 'bar'], $data['generator']);
        self::assertSame([1, 2, 3], $data['array']);

        // Test mapping to stdClass
        $data = $this->autoMapper->map($foo, \stdClass::class);

        self::assertSame([1, 2, 3, 'foo' => 'bar'], $data->generator);
        self::assertSame([1, 2, 3], $data->array);
    }

    public function testBuiltinClass(): void
    {
        $this->buildAutoMapper(mapPrivatePropertiesAndMethod: true);

        self::assertSame(
            [],
            $this->autoMapper->map(new BuiltinClass(new \DateInterval('P1Y')), 'array')
        );
    }

    public function testObjectsUnion(): void
    {
        self::assertSame(
            ['prop' => ['bar' => 'bar']],
            $this->autoMapper->map(new ObjectsUnionProperty(new Bar('bar')), 'array')
        );

        self::assertSame(
            ['prop' => ['foo' => 'foo']],
            $this->autoMapper->map(new ObjectsUnionProperty(new Foo('foo')), 'array')
        );
    }

    public function testMultipleArray(): void
    {
        $now = new \DateTimeImmutable();
        $userDto = new Fixtures\UserDTO();
        $userDto->times = [$now, $now];

        $user = $this->autoMapper->map($userDto, 'array');

        self::assertSame([$now->format(\DateTimeInterface::RFC3339), $now->format(\DateTimeInterface::RFC3339)], $user['times']);

        $userDto = new Fixtures\UserDTO();
        $userDto->times = [0, 1];

        $user = $this->autoMapper->map($userDto, 'array');

        self::assertSame([0, 1], $user['times']);
    }

    public function testDifferentSetterGetterType(): void
    {
        $object = new DifferentSetterGetterType(AddressType::FLAT);
        $array = $this->autoMapper->map($object, 'array');

        self::assertSame(['address' => 'flat', 'addressDocBlock' => 'flat'], $array);
    }

    public function testPromoted(): void
    {
        $address = new AddressDTO();
        $address->city = 'city';

        $object = new UserPromoted([$address, $address]);
        $array = $this->autoMapper->map($object, 'array');

        self::assertSame(['addresses' => [['city' => 'city'], ['city' => 'city']]], $array);
    }

    public function testDateTimeFromString(): void
    {
        $now = new \DateTimeImmutable();
        $data = ['dateTime' => $now->format(\DateTimeInterface::RFC3339)];
        $object = $this->autoMapper->map($data, HasDateTime::class);

        self::assertEquals($now->format(\DateTimeInterface::RFC3339), $object->dateTime->format(\DateTimeInterface::RFC3339));
    }

    public function testRealClassName(): void
    {
        require_once __DIR__ . '/Fixtures/proxies.php';

        $proxy = new \Proxies\__CG__\AutoMapper\Tests\Fixtures\Proxy();
        $proxy->foo = 'bar';

        $mapper = $this->autoMapper->getMapper($proxy::class, 'array');

        self::assertNotEquals('Mapper_Proxies___CG___AutoMapper_Tests_Fixtures_Proxy', $mapper::class);
        self::assertEquals('Mapper_AutoMapper_Tests_Fixtures_Proxy_array', $mapper::class);

        $proxy = new \MongoDBODMProxies\__PM__\AutoMapper\Tests\Fixtures\Proxy\Generated();
        $proxy->foo = 'bar';

        $mapper = $this->autoMapper->getMapper($proxy::class, 'array');

        self::assertNotEquals('Mapper_MongoDBODMProxies___PM___AutoMapper_Tests_Fixtures_Proxy_Generated_array', $mapper::class);
        self::assertEquals('Mapper_AutoMapper_Tests_Fixtures_Proxy_array', $mapper::class);
    }

    public function testDiscriminantToArray(): void
    {
        $this->buildAutoMapper(mapPrivatePropertiesAndMethod: true);

        $dog = new Dog();
        $dog->bark = 'Wouf';
        $dog->type = 'dog';
        $dog->name = 'Coco';

        $petOwner = new PetOwner();
        $petOwner->addPet($dog);

        $dog->owner = $petOwner;

        $petOwnerData = $this->autoMapper->map($petOwner, 'array');

        self::assertIsArray($petOwnerData['pets']);
        self::assertCount(1, $petOwnerData['pets']);
        self::assertSame('Coco', $petOwnerData['pets'][0]['name']);
        self::assertSame('dog', $petOwnerData['pets'][0]['type']);
        self::assertSame('Wouf', $petOwnerData['pets'][0]['bark']);
    }

    public function testGroupOverride(): void
    {
        $this->buildAutoMapper(mapPrivatePropertiesAndMethod: true);

        $group = new GroupOverride();
        $data = $this->autoMapper->map($group, 'array', ['groups' => ['group2']]);

        self::assertSame(['id' => 'id', 'name' => 'name'], $data);
    }

    public function testProvider(): void
    {
        $provided = new FooProvider();
        $provided->foo = 'bar';

        $this->buildAutoMapper(providers: [new CustomProvider($provided)]);

        $data = $this->autoMapper->map(['bar' => 'foo'], FooProvider::class);

        self::assertSame('bar', $data->foo);
        self::assertSame('foo', $data->bar);

        $data = $this->autoMapper->map(['bar' => 'foo', 'foo' => 'foo'], FooProvider::class);

        self::assertSame('foo', $data->foo);
        self::assertSame('foo', $data->bar);
    }

    public function testProviderEarlyReturn(): void
    {
        $provided = new FooProvider();
        $provided->foo = 'bar';
        $provided->bar = 'foo';

        $this->buildAutoMapper(providers: [new CustomProvider(new EarlyReturn($provided))]);

        $data = $this->autoMapper->map(['bar' => 'bar', 'foo' => 'foo'], FooProvider::class);

        self::assertSame('bar', $data->foo);
        self::assertSame('foo', $data->bar);
    }

    public function testIssue111(): void
    {
        $fooDto = new FooDto();
        $fooDto->colours = ['red', 'green', 'blue'];

        $this->buildAutoMapper(propertyTransformers: [new ColourTransformer()]);

        $foo = $this->autoMapper->map($fooDto, Fixtures\Issue111\Foo::class);

        self::assertInstanceOf(Fixtures\Issue111\Foo::class, $foo);
        self::assertEquals([new Colour('red'), new Colour('green'), new Colour('blue')], $foo->getColours());
    }

    public function testItCanMapFromArrayToClassesWithPrivatePropertiesInConstructor(): void
    {
        self::assertEquals(
            new ChildClass(parentProp: 'foo', childProp: 'bar'),
            $this->autoMapper->map(
                [
                    'parentProp' => 'foo',
                    'childProp' => 'bar',
                ],
                ChildClass::class
            )
        );
    }

    public function testItCanMapToClassesWithPrivatePropertiesInConstructor(): void
    {
        self::assertEquals(
            new ChildClass(parentProp: 'foo', childProp: 'bar'),
            $this->autoMapper->map(
                new OtherClass(parentProp: 'foo', childProp: 'bar'),
                ChildClass::class
            )
        );
    }

    public function testParamDocBlock(): void
    {
        $this->buildAutoMapper();

        $foo = new Fixtures\IssueParamDocBlock\Foo('bar', ['foo1', 'foo2']);
        $array = $this->autoMapper->map($foo, 'array');

        self::assertSame([
            'bar' => 'bar',
            'foo' => ['foo1', 'foo2'],
        ], $array);
    }

    public function testDoctrineCollectionsToArray(): void
    {
        $library = new Library();
        $library->books = new ArrayCollection([
            new Book('The Empyrean Onyx Storm'),
            new Book('Valentina'),
            new Book('Imbalance'),
        ]);

        $data = $this->autoMapper->map($library, 'array');

        self::assertCount(3, $data['books']);
        self::assertEquals('The Empyrean Onyx Storm', $data['books'][0]['name']);
        self::assertEquals('Valentina', $data['books'][1]['name']);
        self::assertEquals('Imbalance', $data['books'][2]['name']);
    }

    public function testArrayToDoctrineCollections(): void
    {
        $data = [
            'books' => [
                ['name' => 'The Empyrean Onyx Storm'],
                ['name' => 'Valentina'],
                ['name' => 'Imbalance'],
            ],
        ];

        $library = $this->autoMapper->map($data, Library::class);

        self::assertInstanceOf(Library::class, $library);
        self::assertCount(3, $library->books);
        self::assertEquals('The Empyrean Onyx Storm', $library->books[0]->name);
        self::assertEquals('Valentina', $library->books[1]->name);
        self::assertEquals('Imbalance', $library->books[2]->name);
    }

    public function testMapCollectionFromArray(): void
    {
        $this->buildAutoMapper(mapPrivatePropertiesAndMethod: true);

        $users = [
            [
                'id' => 1,
                'address' => [
                    'city' => 'Toulon',
                ],
                'createdAt' => '1987-04-30T06:00:00Z',
            ],
            [
                'id' => 2,
                'address' => [
                    'city' => 'Nantes',
                ],
                'createdAt' => '1991-10-01T06:00:00Z',
            ],
        ];

        /** @var array<Fixtures\UserDTO> $userDtos */
        $userDtos = $this->autoMapper->mapCollection($users, Fixtures\UserDTO::class);
        self::assertCount(2, $userDtos);
        self::assertEquals(1, $userDtos[0]->id);
        self::assertInstanceOf(AddressDTO::class, $userDtos[0]->address);
        self::assertSame('Toulon', $userDtos[0]->address->city);
        self::assertInstanceOf(\DateTimeInterface::class, $userDtos[0]->createdAt);
        self::assertEquals(1987, $userDtos[0]->createdAt->format('Y'));
        self::assertEquals(2, $userDtos[1]->id);
        self::assertInstanceOf(AddressDTO::class, $userDtos[1]->address);
        self::assertSame('Nantes', $userDtos[1]->address->city);
        self::assertInstanceOf(\DateTimeInterface::class, $userDtos[1]->createdAt);
        self::assertEquals(1991, $userDtos[1]->createdAt->format('Y'));
    }

    public function testMapCollectionFromArrayCustomDateTime(): void
    {
        $this->buildAutoMapper(classPrefix: 'CustomDateTime_', dateTimeFormat: 'U');

        $customFormat = 'U';
        $users = [
            [
                'id' => 1,
                'address' => [
                    'city' => 'Toulon',
                ],
                'createdAt' => \DateTime::createFromFormat(\DateTime::RFC3339, '1987-04-30T06:00:00Z')->format($customFormat),
            ],
            [
                'id' => 2,
                'address' => [
                    'city' => 'Nantes',
                ],
                'createdAt' => \DateTime::createFromFormat(\DateTime::RFC3339, '1991-10-01T06:00:00Z')->format($customFormat),
            ],
        ];

        /** @var array<Fixtures\UserDTO> $userDtos */
        $userDtos = $this->autoMapper->mapCollection($users, Fixtures\UserDTO::class);
        self::assertCount(2, $userDtos);

        self::assertInstanceOf(Fixtures\UserDTO::class, $userDtos[0]);
        self::assertEquals(\DateTime::createFromFormat(\DateTime::RFC3339, '1987-04-30T06:00:00Z')->format($customFormat), $userDtos[0]->createdAt->format($customFormat));
        self::assertInstanceOf(Fixtures\UserDTO::class, $userDtos[1]);
        self::assertEquals(\DateTime::createFromFormat(\DateTime::RFC3339, '1991-10-01T06:00:00Z')->format($customFormat), $userDtos[1]->createdAt->format($customFormat));
    }

    public function testMapCollectionToArray(): void
    {
        $users = [];
        $address = new Address();
        $address->setCity('Toulon');
        $user = new Fixtures\User(1, 'yolo', '13');
        $user->address = $address;
        $user->addresses[] = $address;
        $users[] = $user;
        $address = new Address();
        $address->setCity('Nantes');
        $user = new Fixtures\User(10, 'yolo', '13');
        $user->address = $address;
        $user->addresses[] = $address;
        $users[] = $user;

        $userDatas = $this->autoMapper->mapCollection($users, 'array');

        self::assertIsArray($userDatas);
        self::assertIsArray($userDatas[0]);
        self::assertIsArray($userDatas[1]);
        self::assertEquals(1, $userDatas[0]['id']);
        self::assertEquals(10, $userDatas[1]['id']);
        self::assertIsArray($userDatas[0]['address']);
        self::assertIsString($userDatas[0]['createdAt']);
        self::assertIsArray($userDatas[1]['address']);
        self::assertIsString($userDatas[1]['createdAt']);
    }

    public function testUninitializedProperties(): void
    {
        $payload = new Issue189UserPatchInput();
        $payload->firstName = 'John';
        $payload->lastName = 'Doe';

        /** @var Issue189User $data */
        $data = $this->autoMapper->map($payload, Issue189User::class, [MapperContext::SKIP_UNINITIALIZED_VALUES => true]);

        $this->assertEquals('John', $data->getFirstName());
        $this->assertEquals('Doe', $data->getLastName());
        $this->assertTrue(!isset($data->birthDate));
    }
}
