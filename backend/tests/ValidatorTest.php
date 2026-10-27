<?php
// backend/tests/ValidatorTest.php

namespace Tests;

use PHPUnit\Framework\TestCase;
use App\Helpers\Validator;

class ValidatorTest extends TestCase
{
    public function testValidatorRequiredRules(): void
    {
        $data = ['email' => 'officer@police.gov.in'];
        $validator = Validator::make($data)->required(['email', 'password']);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('password', $validator->errors());
    }

    public function testValidatorEmailRule(): void
    {
        $invalid = ['email' => 'not-an-email'];
        $validator = Validator::make($invalid)->email('email');
        $this->assertTrue($validator->fails());

        $valid = ['email' => 'officer@police.gov.in'];
        $validator2 = Validator::make($valid)->email('email');
        $this->assertFalse($validator2->fails());
    }

    public function testValidatorInRule(): void
    {
        $data = ['role' => 'superuser'];
        $validator = Validator::make($data)->in('role', ['admin', 'user']);
        $this->assertTrue($validator->fails());

        $dataValid = ['role' => 'admin'];
        $validatorValid = Validator::make($dataValid)->in('role', ['admin', 'user']);
        $this->assertFalse($validatorValid->fails());
    }

    public function testValidatorNumericRule(): void
    {
        $data = ['confidence' => 'ninety'];
        $validator = Validator::make($data)->numeric('confidence');
        $this->assertTrue($validator->fails());

        $dataValid = ['confidence' => 94.5];
        $validatorValid = Validator::make($dataValid)->numeric('confidence');
        $this->assertFalse($validatorValid->fails());
    }
}
