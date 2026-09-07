<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Csrf;

use App\Http\Csrf\CsrfTokenManager;
use App\Http\Session\ArraySession;
use Tests\TestCase;

final class CsrfTokenManagerTest extends TestCase
{
    public function testGetTokenGeneratesAndPersistsToken(): void
    {
        $session = new ArraySession();
        $csrf = new CsrfTokenManager($session);

        $token = $csrf->getToken();

        $this->assertNotSame('', $token);
        $this->assertSame($token, $session->get('_csrf_token'));
    }

    public function testGetTokenReturnsSameTokenOnSubsequentCalls(): void
    {
        $csrf = new CsrfTokenManager(new ArraySession());

        $this->assertSame($csrf->getToken(), $csrf->getToken());
    }

    public function testIsValidAcceptsMatchingToken(): void
    {
        $csrf = new CsrfTokenManager(new ArraySession());
        $token = $csrf->getToken();

        $this->assertTrue($csrf->isValid($token));
    }

    public function testIsValidRejectsWrongToken(): void
    {
        $csrf = new CsrfTokenManager(new ArraySession());
        $csrf->getToken();

        $this->assertFalse($csrf->isValid('wrong-token'));
    }

    public function testIsValidRejectsNullOrEmptySubmission(): void
    {
        $csrf = new CsrfTokenManager(new ArraySession());
        $csrf->getToken();

        $this->assertFalse($csrf->isValid(null));
        $this->assertFalse($csrf->isValid(''));
    }

    public function testIsValidRejectsWhenNoTokenEverIssued(): void
    {
        $csrf = new CsrfTokenManager(new ArraySession());

        $this->assertFalse($csrf->isValid('anything'));
    }

    public function testRegenerateChangesTheToken(): void
    {
        $csrf = new CsrfTokenManager(new ArraySession());
        $first = $csrf->getToken();
        $second = $csrf->regenerate();

        $this->assertNotSame($first, $second);
        $this->assertTrue($csrf->isValid($second));
        $this->assertFalse($csrf->isValid($first));
    }

    public function testFieldNameIsStable(): void
    {
        $csrf = new CsrfTokenManager(new ArraySession());

        $this->assertSame('csrf_token', $csrf->fieldName());
    }

    public function testHiddenFieldRendersEscapedInputWithCurrentToken(): void
    {
        $csrf = new CsrfTokenManager(new ArraySession());
        $token = $csrf->getToken();

        $field = $csrf->hiddenField();

        $this->assertStringContainsString('type="hidden"', $field);
        $this->assertStringContainsString('name="csrf_token"', $field);
        $this->assertStringContainsString('value="' . $token . '"', $field);
    }
}
