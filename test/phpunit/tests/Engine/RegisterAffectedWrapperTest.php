<?php
/**
 * Created by PhpStorm.
 * User: kota
 * Date: 22.06.16
 * Time: 11:40
 */
namespace OldTown\Workflow\PhpUnitTest\Engine;

use PHPUnit_Framework_TestCase as TestCase;
use OldTown\Workflow\Engine\RegisterAffectedWrapper;
use OldTown\Workflow\Engine\Transition;

class RegisterAffectedWrapperTest extends TestCase
{
    /**
     * @return void
     */
    protected function setUp()
    {
        parent::setUp();
    }

    public function testConstruct()
    {
        $transition = $this->getMock(
            Transition::class,
            [
                'registerAffectedEntity',
                'unregisterAffectedEntity'
            ], // в этом массиве можно указать какие именно методы будут подменены
            [], // аргументы, передаваемые в конструктор
            '', // можно указать имя Mock класса
            false, // отключение вызова __construct()
            true, // отключение вызова __clone()
            true // отключение вызова __autoload()
        );

        $this->assertInstanceOf(Transition::class, $transition);
        $transition->expects($this->any())->method('registerAffectedEntity')->will($this->returnValue(null));

        // Start by getting the class into reflection.
        $reflectionClass = new \ReflectionClass(RegisterAffectedWrapper::class);
        $classId = $reflectionClass->getProperty('id');
        $classId->setAccessible(true);
        $classTransition = $reflectionClass->getProperty('transition');
        $classTransition->setAccessible(true);

        $id=1;
        $action = 'edit';
        $wrapper = new RegisterAffectedWrapper($transition, $id, $action);

        $this->assertInstanceOf(RegisterAffectedWrapper::class, $wrapper);
        $this->assertEquals(
            $id,
            $classId->getValue($wrapper),
            'Не инициализируется id в RegisterAffectedWrapper::__construct'
        );
        $this->assertEquals(
            $transition,
            $classTransition->getValue($wrapper),
            'Не инициализируется transition в RegisterAffectedWrapper::__construct'
        );
    }
}
