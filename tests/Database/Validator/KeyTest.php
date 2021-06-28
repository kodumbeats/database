<?php

namespace Utopia\Tests\Validator;

use Utopia\Database\Validator\Key;
use PHPUnit\Framework\TestCase;

class KeyTest extends TestCase
{
    /**
     * @var Key
     */
    protected $object = null;

    public function setUp(): void
    {
        $this->object = new Key();
    }

    public function tearDown(): void
    {
    }

    public function testValues()
    {
        // Must be strings
        $this->assertEquals(false, $this->object->isValid(false));
        $this->assertEquals(false, $this->object->isValid(null));
        $this->assertEquals(false, $this->object->isValid(['value']));
        $this->assertEquals(false, $this->object->isValid(0));
        $this->assertEquals(false, $this->object->isValid(1.5));
        $this->assertEquals(true, $this->object->isValid('asdas7as9as'));
        $this->assertEquals(true, $this->object->isValid('5f058a8925807'));

        // No leading underscore
        $this->assertEquals(false, $this->object->isValid('_asdasdasdas'));
        $this->assertEquals(true, $this->object->isValid('a_sdasdasdas'));

        // No Special characters
        $this->assertEquals(false, $this->object->isValid('dasda asdasd'));
        $this->assertEquals(false, $this->object->isValid('asd"asd6sdas'));
        $this->assertEquals(false, $this->object->isValid('asd\'as0asdas'));
        $this->assertEquals(false, $this->object->isValid('as$$5dasdasdas'));
        $this->assertEquals(false, $this->object->isValid('as-5dasdasdas'));

        // At most 32 chars
        $this->assertEquals(true, $this->object->isValid('socialAccountForYoutubeSubscribe'));
        $this->assertEquals(false, $this->object->isValid('socialAccountForYoutubeSubscribers'));
        $this->assertEquals(true, $this->object->isValid('5f058a89258075f058a89258075f058t'));
        $this->assertEquals(false, $this->object->isValid('5f058a89258075f058a89258075f058tx'));
    }
}
