<?php

/**
 * @see       https://github.com/laminas/laminas-session for the canonical source repository
 * @copyright https://github.com/laminas/laminas-session/blob/master/COPYRIGHT.md
 * @license   https://github.com/laminas/laminas-session/blob/master/LICENSE.md New BSD License
 */

namespace LaminasTest\Session;

use DateTime;
use Laminas\Session\Config\SessionConfig;
use Laminas\Session\Config\StandardConfig;
use Laminas\Session\Exception\InvalidArgumentException;
use Laminas\Session\Exception\RuntimeException;
use Laminas\Session\SessionManager;
use Laminas\Session\Storage\ArrayStorage;
use Laminas\Session\Storage\SessionArrayStorage;
use Laminas\Session\Validator\Id;
use Laminas\Session\Validator\RemoteAddr;
use stdClass;

/**
 * @preserveGlobalState disabled
 * @covers Laminas\Session\SessionManager
 */
class SessionManagerTest extends \PHPUnit_Framework_TestCase
{
    public $error;

    public $cookieDateFormat = 'D, d-M-y H:i:s e';

    /**
     * @var SessionManager
     */
    protected $manager;

    public function setUp()
    {
        $this->error   = false;
        $this->manager = new SessionManager();
    }

    public function handleErrors($errno, $errstr)
    {
        $this->error = $errstr;
    }

    public function getTimestampFromCookie($cookie)
    {
        if (preg_match('/expires=([^;]+)/', $cookie, $matches)) {
            $ts = new DateTime($matches[1]);
            return $ts;
        }
        return false;
    }

    public function testManagerUsesSessionConfigByDefault()
    {
        $config = $this->manager->getConfig();
        $this->assertInstanceOf(SessionConfig::class, $config);
    }

    public function testCanPassConfigurationToConstructor()
    {
        $config = new StandardConfig();
        $manager = new SessionManager($config);
        $this->assertSame($config, $manager->getConfig());
    }

    public function testManagerUsesSessionStorageByDefault()
    {
        $storage = $this->manager->getStorage();
        $this->assertInstanceOf(SessionArrayStorage::class, $storage);
    }

    public function testCanPassStorageToConstructor()
    {
        $storage = new ArrayStorage();
        $manager = new SessionManager(null, $storage);
        $this->assertSame($storage, $manager->getStorage());
    }

    public function testCanPassSaveHandlerToConstructor()
    {
        $saveHandler = new TestAsset\TestSaveHandler();
        $manager = new SessionManager(null, null, $saveHandler);
        $this->assertSame($saveHandler, $manager->getSaveHandler());
    }

    public function testCanPassValidatorsToConstructor()
    {
        $validators = [
            'foo',
            'bar',
        ];
        $manager = new SessionManager(null, null, null, $validators);
        foreach ($validators as $validator) {
            $this->assertAttributeContains($validator, 'validators', $manager);
        }
    }

    public function testAttachDefaultValidatorsByDefault()
    {
        $manager = new SessionManager();
        $this->assertAttributeEquals([Id::class], 'validators', $manager);
    }

    public function testCanMergeValidatorsWithDefault()
    {
        $defaultValidators = [
            Id::class,
        ];
        $validators = [
            'foo',
            'bar'
        ];
        $manager = new SessionManager(null, null, null, $validators);
        $this->assertAttributeEquals(array_merge($defaultValidators, $validators), 'validators', $manager);
    }

    public function testCanDisableAttachDefaultValidators()
    {
        $options = [
            'attach_default_validators' => false,
        ];
        $manager = new SessionManager(null, null, null, [], $options);
        $this->assertAttributeEquals([], 'validators', $manager);
    }

    // Session-related functionality

    /**
     * @runInSeparateProcess
     */
    public function testSessionExistsReturnsFalseWhenNoSessionStarted()
    {
        $this->assertFalse($this->manager->sessionExists());
    }

    /**
     * @runInSeparateProcess
     */
    public function testSessionExistsReturnsTrueWhenSessionStarted()
    {
        session_start();
        $this->assertTrue($this->manager->sessionExists());
    }

    /**
     * @runInSeparateProcess
     */
    public function testSessionExistsReturnsTrueWhenSessionStartedThenWritten()
    {
        session_start();
        session_write_close();
        $this->assertTrue($this->manager->sessionExists());
    }

    /**
     * @runInSeparateProcess
     */
    public function testSessionExistsReturnsFalseWhenSessionStartedThenDestroyed()
    {
        session_start();
        session_destroy();
        $this->assertFalse($this->manager->sessionExists());
    }

    /**
     * @runInSeparateProcess
     */
    public function testSessionIsStartedAfterCallingStart()
    {
        $this->assertFalse($this->manager->sessionExists());
        $this->manager->start();
        $this->assertTrue($this->manager->sessionExists());
    }

    /**
     * @runInSeparateProcess
     */
    public function testStartDoesNothingWhenCalledAfterWriteCloseOperation()
    {
        $this->manager->start();
        $id1 = session_id();
        session_write_close();
        $this->manager->start();
        $id2 = session_id();
        $this->assertTrue($this->manager->sessionExists());
        $this->assertEquals($id1, $id2);
    }

    /**
     * @runInSeparateProcess
     */
    public function testStorageContentIsPreservedByWriteCloseOperation()
    {
        $this->manager->start();
        $storage = $this->manager->getStorage();
        $storage['foo'] = 'bar';
        $this->manager->writeClose();
        $this->assertArrayHasKey('foo', $storage);
        $this->assertEquals('bar', $storage['foo']);
    }

    /**
     * @runInSeparateProcess
     */
    public function testStartCreatesNewSessionIfPreviousSessionHasBeenDestroyed()
    {
        $this->manager->start();
        $id1 = session_id();
        session_destroy();
        $this->manager->start();
        $id2 = session_id();
        $this->assertTrue($this->manager->sessionExists());
        $this->assertNotEquals($id1, $id2);
    }

    /**
     * @outputBuffering disabled
     */
    public function testStartWillNotBlockHeaderSentNotices()
    {
        if ('cli' == PHP_SAPI) {
            $this->markTestSkipped('session_start() will not raise headers_sent warnings in CLI');
        }
        set_error_handler([$this, 'handleErrors'], E_WARNING);
        echo ' ';
        $this->assertTrue(headers_sent());
        $this->manager->start();
        restore_error_handler();
        $this->assertInternalType('string', $this->error);
        $this->assertContains('already sent', $this->error);
    }

    /**
     * @runInSeparateProcess
     */
    public function testGetNameReturnsSessionName()
    {
        $ini = ini_get('session.name');
        $this->assertEquals($ini, $this->manager->getName());
    }

    /**
     * @runInSeparateProcess
     */
    public function testSetNameRaisesExceptionOnInvalidName()
    {
        $this->setExpectedException(
            InvalidArgumentException::class,
            'Name provided contains invalid characters; must be alphanumeric only'
        );
        $this->manager->setName('foo bar!');
    }

    /**
     * @runInSeparateProcess
     */
    public function testSetNameSetsSessionNameOnSuccess()
    {
        $this->manager->setName('foobar');
        $this->assertEquals('foobar', $this->manager->getName());
        $this->assertEquals('foobar', session_name());
    }

    /**
     * @runInSeparateProcess
     */
    public function testCanSetNewSessionNameAfterSessionDestroyed()
    {
        $this->manager->start();
        session_destroy();
        $this->manager->setName('foobar');
        $this->assertEquals('foobar', $this->manager->getName());
        $this->assertEquals('foobar', session_name());
    }

    /**
     * @runInSeparateProcess
     */
    public function testSettingNameWhenAnActiveSessionExistsRaisesException()
    {
        $this->setExpectedException(
            InvalidArgumentException::class,
            'Cannot set session name after a session has already started'
        );
        $this->manager->start();
        $this->manager->setName('foobar');
    }

    /**
     * @runInSeparateProcess
     */
    public function testDestroyByDefaultSendsAnExpireCookie()
    {
        if (! extension_loaded('xdebug')) {
            $this->markTestSkipped('Xdebug required for this test');
        }

        $config = $this->manager->getConfig();
        $config->setUseCookies(true);
        $this->manager->start();
        $this->manager->destroy();
        echo '';
        $headers = xdebug_get_headers();
        $found  = false;
        $sName  = $this->manager->getName();
        foreach ($headers as $header) {
            if (stristr($header, 'Set-Cookie:') && stristr($header, $sName)) {
                $found  = true;
            }
        }
        $this->assertTrue($found, 'No session cookie found: ' . var_export($headers, true));
    }

    /**
     * @runInSeparateProcess
     */
    public function testSendingFalseToSendExpireCookieWhenCallingDestroyShouldNotSendCookie()
    {
        if (! extension_loaded('xdebug')) {
            $this->markTestSkipped('Xdebug required for this test');
        }

        $config = $this->manager->getConfig();
        $config->setUseCookies(true);
        $this->manager->start();
        $this->manager->destroy(['send_expire_cookie' => false]);
        echo '';
        $headers = xdebug_get_headers();
        $found  = false;
        $sName  = $this->manager->getName();
        foreach ($headers as $header) {
            if (stristr($header, 'Set-Cookie:') && stristr($header, $sName)) {
                $found  = true;
            }
        }
        if ($found) {
            $this->assertNotContains('expires=', $header);
        } else {
            $this->assertFalse($found, 'Unexpected session cookie found: ' . var_export($headers, true));
        }
    }

    /**
     * @runInSeparateProcess
     */
    public function testDestroyDoesNotClearSessionStorageByDefault()
    {
        $this->manager->start();
        $storage = $this->manager->getStorage();
        $storage['foo'] = 'bar';
        $this->manager->destroy();
        $this->assertTrue(isset($storage['foo']));
        $this->assertEquals('bar', $storage['foo']);
    }

    /**
     * @runInSeparateProcess
     */
    public function testPassingClearStorageOptionWhenCallingDestroyClearsStorage()
    {
        $this->manager->start();
        $storage = $this->manager->getStorage();
        $storage['foo'] = 'bar';
        $this->manager->destroy(['clear_storage' => true]);
        $this->assertFalse(isset($storage['foo']));
    }

    /**
     * @runInSeparateProcess
     */
    public function testCallingWriteCloseMarksStorageAsImmutable()
    {
        $this->manager->start();
        $storage = $this->manager->getStorage();
        $storage['foo'] = 'bar';
        $this->manager->writeClose();
        $this->assertTrue($storage->isImmutable());
    }

    /**
     * @runInSeparateProcess
     */
    public function testCallingWriteCloseShouldNotAlterSessionExistsStatus()
    {
        $this->manager->start();
        $this->manager->writeClose();
        $this->assertTrue($this->manager->sessionExists());
    }

    /**
     * @runInSeparateProcess
     */
    public function testIdShouldBeEmptyPriorToCallingStart()
    {
        $this->assertSame('', $this->manager->getId());
    }

    /**
     * @runInSeparateProcess
     */
    public function testIdShouldBeMutablePriorToCallingStart()
    {
        $this->manager->setId(__CLASS__);
        $this->assertSame(__CLASS__, $this->manager->getId());
        $this->assertSame(__CLASS__, session_id());
    }

    /**
     * @runInSeparateProcess
     */
    public function testIdShouldNotBeMutableAfterSessionStarted()
    {
        $this->setExpectedException(
            RuntimeException::class,
            'Session has already been started, to change the session ID call regenerateId()'
        );
        $this->manager->start();
        $origId = $this->manager->getId();
        $this->manager->setId(__METHOD__);
    }

    /**
     * @runInSeparateProcess
     */
    public function testRegenerateIdShouldWorkAfterSessionStarted()
    {
        $this->manager->start();
        $origId = $this->manager->getId();
        $this->manager->regenerateId();
        $this->assertNotSame($origId, $this->manager->getId());
    }

    /**
     * @runInSeparateProcess
     */
    public function testRegenerateIdDoesNothingWhenSessioIsNotStarted()
    {
        $origId = $this->manager->getId();
        $this->manager->regenerateId();
        $this->assertEquals($origId, $this->manager->getId());
        $this->assertEquals('', $this->manager->getId());
    }

    /**
     * @runInSeparateProcess
     */
    public function testRegeneratingIdAfterSessionStartedShouldSendExpireCookie()
    {
        if (! extension_loaded('xdebug')) {
            $this->markTestSkipped('Xdebug required for this test');
        }

        $config = $this->manager->getConfig();
        $config->setUseCookies(true);
        $this->manager->start();
        $origId = $this->manager->getId();
        $this->manager->regenerateId();
        $headers = xdebug_get_headers();
        $found  = false;
        $sName  = $this->manager->getName();
        foreach ($headers as $header) {
            if (stristr($header, 'Set-Cookie:') && stristr($header, $sName)) {
                $found  = true;
            }
        }
        $this->assertTrue($found, 'No session cookie found: ' . var_export($headers, true));
    }

    /**
     * @runInSeparateProcess
     */
    public function testRememberMeShouldSendNewSessionCookieWithUpdatedTimestamp()
    {
        if (! extension_loaded('xdebug')) {
            $this->markTestSkipped('Xdebug required for this test');
        }

        $config = $this->manager->getConfig();
        $config->setUseCookies(true);
        $this->manager->start();
        $this->manager->rememberMe(18600);
        $headers = xdebug_get_headers();
        $found   = false;
        $sName   = $this->manager->getName();
        $cookie  = false;
        foreach ($headers as $header) {
            if (stristr($header, 'Set-Cookie:') && stristr($header, $sName) && ! stristr($header, '=deleted')) {
                $found  = true;
                $cookie = $header;
            }
        }
        $this->assertTrue($found, 'No session cookie found: ' . var_export($headers, true));
        $ts = $this->getTimestampFromCookie($cookie);
        if (! $ts) {
            $this->fail('Cookie did not contain expiry? ' . var_export($headers, true));
        }
        $this->assertGreaterThan(
            $_SERVER['REQUEST_TIME'],
            $ts->getTimestamp(),
            'Session cookie: ' . var_export($headers, 1)
        );
    }

    /**
     * @runInSeparateProcess
     */
    public function testRememberMeShouldSetTimestampBasedOnConfigurationByDefault()
    {
        if (! extension_loaded('xdebug')) {
            $this->markTestSkipped('Xdebug required for this test');
        }

        $config = $this->manager->getConfig();
        $config->setUseCookies(true);
        $config->setRememberMeSeconds(3600);
        $ttl = $config->getRememberMeSeconds();
        $this->manager->start();
        $this->manager->rememberMe();
        $headers = xdebug_get_headers();
        $found  = false;
        $sName  = $this->manager->getName();
        $cookie = false;
        foreach ($headers as $header) {
            if (stristr($header, 'Set-Cookie:') && stristr($header, $sName) && ! stristr($header, '=deleted')) {
                $found  = true;
                $cookie = $header;
            }
        }
        $this->assertTrue($found, 'No session cookie found: ' . var_export($headers, true));
        $ts = $this->getTimestampFromCookie($cookie);
        if (! $ts) {
            $this->fail('Cookie did not contain expiry? ' . var_export($headers, true));
        }
        $compare = $_SERVER['REQUEST_TIME'] + $ttl;
        $cookieTs = $ts->getTimestamp();
        $this->assertContains($cookieTs, range($compare, $compare + 10), 'Session cookie: ' . var_export($headers, 1));
    }

    /**
     * @runInSeparateProcess
     */
    public function testForgetMeShouldSendCookieWithZeroTimestamp()
    {
        if (! extension_loaded('xdebug')) {
            $this->markTestSkipped('Xdebug required for this test');
        }

        $config = $this->manager->getConfig();
        $config->setUseCookies(true);
        $this->manager->start();
        $this->manager->forgetMe();
        $headers = xdebug_get_headers();
        $found  = false;
        $sName  = $this->manager->getName();
        foreach ($headers as $header) {
            if (stristr($header, 'Set-Cookie:') && stristr($header, $sName) && ! stristr($header, '=deleted')) {
                $found  = true;
            }
        }
        $this->assertTrue($found, 'No session cookie found: ' . var_export($headers, true));
        $this->assertNotContains('expires=', $header);
    }

    /**
     * @runInSeparateProcess
     */
    public function testStartingSessionThatFailsAValidatorShouldRaiseException()
    {
        $chain = $this->manager->getValidatorChain();
        $chain->attach('session.validate', [new TestAsset\TestFailingValidator(), 'isValid']);
        $this->setExpectedException(RuntimeException::class, 'failed');
        $this->manager->start();
    }

    /**
     * @runInSeparateProcess
     */
    public function testResumeSessionThatFailsAValidatorShouldRaiseException()
    {
        $this->manager->setSaveHandler(new TestAsset\TestSaveHandlerWithValidator);
        $this->setExpectedException(RuntimeException::class, 'failed');
        $this->manager->start();
    }

    /**
     * @runInSeparateProcess
     */
    public function testSessionWriteCloseStoresMetadata()
    {
        $this->manager->start();
        $storage = $this->manager->getStorage();
        $storage->setMetadata('foo', 'bar');
        $metaData = $storage->getMetadata();
        $this->manager->writeClose();
        $this->assertSame($_SESSION['__Laminas'], $metaData);
    }

    /**
     * @runInSeparateProcess
     */
    public function testSessionValidationDoesNotHaltOnNoopListener()
    {
        $validator = $this->getMock(stdClass::class, ['__invoke']);

        $validator->expects($this->once())->method('__invoke');

        $this->manager->getValidatorChain()->attach('session.validate', $validator);

        $this->assertTrue($this->manager->isValid());
    }

    /**
     * @runInSeparateProcess
     */
    public function testProducedSessionManagerWillNotReplaceSessionSuperGlobalValues()
    {
        $_SESSION['foo'] = 'bar';

        $this->manager->start();

        $this->assertArrayHasKey('foo', $_SESSION);
        $this->assertSame('bar', $_SESSION['foo']);
    }

    /**
     * @runInSeparateProcess
     */
    public function testValidatorChainSessionMetadataIsPreserved()
    {
        $this
            ->manager
            ->getValidatorChain()
            ->attach('session.validate', [new RemoteAddr(), 'isValid']);

        $this->assertFalse($this->manager->sessionExists());

        $this->manager->start();

        $this->assertInternalType('array', $_SESSION['__Laminas']['_VALID']);
        $this->assertArrayHasKey(RemoteAddr::class, $_SESSION['__Laminas']['_VALID']);
        $this->assertEquals('', $_SESSION['__Laminas']['_VALID'][RemoteAddr::class]);
    }

    /**
     * @runInSeparateProcess
     */
    public function testRemoteAddressValidationWillFailOnInvalidAddress()
    {
        $this
            ->manager
            ->getValidatorChain()
            ->attach('session.validate', [new RemoteAddr('123.123.123.123'), 'isValid']);

        $this->setExpectedException(RuntimeException::class, 'Session validation failed');
        $this->manager->start();
    }

    /**
     * @runInSeparateProcess
     */
    public function testRemoteAddressValidationWillSucceedWithValidPreSetData()
    {
        $_SESSION = [
            '__Laminas' => [
                '_VALID' => [RemoteAddr::class => ''],
            ],
        ];

        $this->manager->start();

        $this->assertTrue($this->manager->isValid());
    }

    /**
     * @runInSeparateProcess
     */
    public function testRemoteAddressValidationWillFailWithInvalidPreSetData()
    {
        $_SESSION = [
            '__Laminas' => [
                '_VALID' => [RemoteAddr::class => '123.123.123.123'],
            ],
        ];

        $this->setExpectedException(RuntimeException::class, 'Session validation failed');
        $this->manager->start();
    }

    /**
     * @runInSeparateProcess
     */
    public function testIdValidationWillFailOnInvalidData()
    {
        $this
            ->manager
            ->getValidatorChain()
            ->attach('session.validate', [new Id('invalid-value'), 'isValid']);

        $this->setExpectedException(RuntimeException::class, 'Session validation failed');
        $this->manager->start();
    }
}
