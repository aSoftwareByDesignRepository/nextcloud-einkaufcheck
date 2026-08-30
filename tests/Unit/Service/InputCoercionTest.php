<?php

declare(strict_types=1);

namespace OCA\EinkaufCheck\Tests\Unit\Service;

use OCA\EinkaufCheck\Exception\ValidationException;
use OCA\EinkaufCheck\Service\InputCoercion;
use PHPUnit\Framework\TestCase;

class InputCoercionTest extends TestCase {
	public function testFalseStringIsFalse(): void {
		self::assertFalse(InputCoercion::asBool('false', 'checked'));
		self::assertFalse(InputCoercion::asBool('FALSE', 'checked'));
		self::assertFalse(InputCoercion::asBool('0', 'checked'));
		self::assertFalse(InputCoercion::asBool(0, 'checked'));
		self::assertFalse(InputCoercion::asBool(false, 'checked'));
	}

	public function testTrueVariantsAreTrue(): void {
		self::assertTrue(InputCoercion::asBool(true, 'checked'));
		self::assertTrue(InputCoercion::asBool(1, 'checked'));
		self::assertTrue(InputCoercion::asBool('1', 'checked'));
		self::assertTrue(InputCoercion::asBool('true', 'checked'));
		self::assertTrue(InputCoercion::asBool('yes', 'checked'));
	}

	public function testGarbageThrows(): void {
		$this->expectException(ValidationException::class);
		InputCoercion::asBool('maybe', 'checked');
	}

	public function testEmptyPhpTrapIsNotUsed(): void {
		// PHP !empty('false') is true — that must not be our contract.
		self::assertTrue(!empty('false'));
		self::assertFalse(InputCoercion::asBool('false', 'checked'));
	}
}
