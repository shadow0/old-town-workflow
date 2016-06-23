<?php
/**
 * Created by PhpStorm.
 * User: kota
 * Date: 22.06.16
 * Time: 12:28
 */

namespace OldTown\Workflow\PhpUnitTest\Engine;

use PHPUnit_Framework_TestCase as TestCase;
use OldTown\Workflow\Engine\Transition;

class TransitionTest extends TestCase
{
    /** @var Transition  */
    static private $transition;
    /** @var \ReflectionClass */
    static private $transitionReflectionClass;

    public static function setUpBeforeClass()
    {
        self::$transitionReflectionClass = new \ReflectionClass(Transition::class);
        self::$transition = new Transition(); //FIXME
    }

    /**
     * тестируем генерацию строчных ключей из идентификатора
     */
    public function testGetStringId()
    {
        // Start by getting the class into reflection.
        $method = self::$transitionReflectionClass->getMethod('getStringId');
        $method->setAccessible(true);

        $id0 = 1;
        $id01 = $id0;
        $id2 = $id0 + 1;

        $resNull = $method->invoke(self::$transition, null);
        $res0 = $method->invoke(self::$transition, $id0);
        $res01 = $method->invoke(self::$transition, $id01);
        $res2 = $method->invoke(self::$transition, $id2);

        $this->assertEquals($res0, $res01, 'При одинаковом идентификаторе получены разные строчные идентификаторы');
        $this->assertTrue(is_string($res0), 'Должна возвращаться строка');
        $this->assertNotEmpty($resNull);
        $this->assertNotEquals($res0, $res2);
    }

    /**
     * помещение идентификатора в стек
     */
    public function testRegisterAffectedEntity()
    {
        //test $this->transition->registerAffectedEntity($id, $action);

        $method = self::$transitionReflectionClass->getMethod('registerAffectedEntity');
        $method->setAccessible(true);
        self::$transitionReflectionClass->setStaticPropertyValue('entryStack', []);
        $methodGetStringId = self::$transitionReflectionClass->getMethod('getStringId');
        $methodGetStringId->setAccessible(true);

        $id = 1;
        $action = 'edit';
        $args = [ $id, $action ];
        $method->invokeArgs(self::$transition, $args);
        $property = self::$transitionReflectionClass->getStaticPropertyValue('entryStack');

        $stringId = $methodGetStringId->invoke(self::$transition, $id);
        $this->assertArrayHasKey($stringId, $property);
        try {
            $method->invokeArgs(self::$transition, $args);
            $this->fail('Попытка засунуть существующий id должна вызывать исключение');
        } catch (\OldTown\Workflow\Exception\CycleOperationException $e) {
            //OK
        }
    }

    /**
     * удаление из стека идентификатора.
     */
    public function testUnregisterAffectedEntity()
    {
        //self::$transition->unregisterAffectedEntity($id);
        $method = self::$transitionReflectionClass->getMethod('unregisterAffectedEntity');
        $method->setAccessible(true);
        self::$transitionReflectionClass->setStaticPropertyValue('entryStack', []);
        $methodGetStringId = self::$transitionReflectionClass->getMethod('getStringId');
        $methodGetStringId->setAccessible(true);

        $id = 1;
        $stringId = $methodGetStringId->invoke(self::$transition, $id);

        $propertyOLD = self::$transitionReflectionClass->getStaticPropertyValue('entryStack');
        $this->assertArrayHasKey($stringId, $propertyOLD, 'при тесте ключ '.$stringId.' должен быть в массиве');

        $method->invokeArgs(self::$transition, $id);
        $property = self::$transitionReflectionClass->getStaticPropertyValue('entryStack');
        $this->assertArrayNotHasKey($stringId, $property, 'метод должен удалять ключ из массива');
    }

    public function testCycle()
    {
        //TODO
    }
}
