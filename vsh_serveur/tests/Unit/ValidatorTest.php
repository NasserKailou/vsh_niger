<?php

declare(strict_types=1);

namespace Vsh\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Vsh\Core\Exceptions\ValidationException;
use Vsh\Core\Validation\Validator;

final class ValidatorTest extends TestCase
{
    /** @var Validator */
    private $validator;

    protected function setUp(): void
    {
        $lang = require dirname(__DIR__, 2) . '/lang/fr.php';
        $this->validator = new Validator($lang['validation']);
    }

    public function testReturnsOnlyDeclaredFieldsAndOmitsAbsentOptionalOnes(): void
    {
        $result = $this->validator->validate(
            ['first_name' => '  Aïcha  ', 'is_admin' => true],
            ['first_name' => 'required|string|max:100', 'last_name' => 'string']
        );

        $this->assertSame(['first_name' => 'Aïcha'], $result);
    }

    public function testRequiredFieldMissingOrEmpty(): void
    {
        $errors = $this->errorsFor(['name' => '   '], ['name' => 'required|string', 'code' => 'required']);

        $this->assertSame(['Ce champ est obligatoire.'], $errors['name']);
        $this->assertArrayHasKey('code', $errors);
    }

    public function testNullableAcceptsNullButOtherwiseNullIsRejected(): void
    {
        $this->assertSame(['landmark' => null], $this->validator->validate(['landmark' => null], ['landmark' => 'nullable|string']));

        $errors = $this->errorsFor(['landmark' => null], ['landmark' => 'string']);
        $this->assertSame(['Ce champ ne peut pas être vide.'], $errors['landmark']);
    }

    public function testIntegerAndNumericNormalization(): void
    {
        $result = $this->validator->validate(
            ['age' => '42', 'weight' => '12.5', 'count' => 3.0],
            ['age' => 'integer|min:0', 'weight' => 'numeric', 'count' => 'integer']
        );

        $this->assertSame(['age' => 42, 'weight' => 12.5, 'count' => 3], $result);
        $this->assertArrayHasKey('age', $this->errorsFor(['age' => '4.2'], ['age' => 'integer']));
    }

    public function testMaxCountsMultibyteCharacters(): void
    {
        $this->assertSame(['city' => 'Né'], $this->validator->validate(['city' => 'Né'], ['city' => 'string|max:2']));

        $errors = $this->errorsFor(['city' => 'Niamé'], ['city' => 'string|max:4']);
        $this->assertSame(['Au plus 4 caractères.'], $errors['city']);
    }

    public function testInRule(): void
    {
        $this->assertSame(['sex' => 'F'], $this->validator->validate(['sex' => 'F'], ['sex' => 'required|in:M,F']));
        $this->assertArrayHasKey('sex', $this->errorsFor(['sex' => 'X'], ['sex' => 'required|in:M,F']));
    }

    public function testPhoneIsNormalizedToE164(): void
    {
        $rules = ['phone' => 'required|phone'];

        $this->assertSame(['phone' => '+22790123456'], $this->validator->validate(['phone' => '90 12 34 56'], $rules));
        $this->assertSame(['phone' => '+22790123456'], $this->validator->validate(['phone' => '0022790123456'], $rules));
        $this->assertSame(['phone' => '+22790123456'], $this->validator->validate(['phone' => '227 90-12-34-56'], $rules));
        $this->assertSame(['phone' => '+33612345678'], $this->validator->validate(['phone' => '+33 6 12 34 56 78'], $rules));
        $this->assertArrayHasKey('phone', $this->errorsFor(['phone' => '12345'], $rules));
    }

    public function testUuidIsLowercased(): void
    {
        $result = $this->validator->validate(
            ['id' => 'A1B2C3D4-E5F6-4A7B-8C9D-0E1F2A3B4C5D'],
            ['id' => 'uuid']
        );

        $this->assertSame('a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d', $result['id']);
        $this->assertArrayHasKey('id', $this->errorsFor(['id' => 'not-a-uuid'], ['id' => 'uuid']));
    }

    public function testDateRejectsImpossibleDates(): void
    {
        $this->assertSame(['d' => '2024-02-29'], $this->validator->validate(['d' => '2024-02-29'], ['d' => 'date']));
        $this->assertArrayHasKey('d', $this->errorsFor(['d' => '2026-02-30'], ['d' => 'date']));
        $this->assertArrayHasKey('d', $this->errorsFor(['d' => '25/09/2026'], ['d' => 'date']));
    }

    public function testDatetimeIsConvertedToUtc(): void
    {
        $rules = ['at' => 'datetime'];

        $this->assertSame(['at' => '2026-09-25 09:00:00'], $this->validator->validate(['at' => '2026-09-25T10:00:00+01:00'], $rules));
        $this->assertSame(['at' => '2026-09-25 10:00:00'], $this->validator->validate(['at' => '2026-09-25T10:00:00.123Z'], $rules));
        $this->assertSame(['at' => '2026-09-25 10:00:00'], $this->validator->validate(['at' => '2026-09-25 10:00'], $rules));
        $this->assertArrayHasKey('at', $this->errorsFor(['at' => '2026-09-31T10:00:00Z'], $rules));
        $this->assertArrayHasKey('at', $this->errorsFor(['at' => '2026-09-25T24:00:00Z'], $rules));
    }

    public function testCoordinates(): void
    {
        $result = $this->validator->validate(
            ['lat' => '13.5116', 'lng' => 2.1254],
            ['lat' => 'latitude', 'lng' => 'longitude']
        );

        $this->assertSame(['lat' => 13.5116, 'lng' => 2.1254], $result);
        $this->assertArrayHasKey('lat', $this->errorsFor(['lat' => 91], ['lat' => 'latitude']));
        $this->assertArrayHasKey('lng', $this->errorsFor(['lng' => -180.5], ['lng' => 'longitude']));
    }

    public function testBooleanAndEmail(): void
    {
        $result = $this->validator->validate(
            ['active' => 'false', 'email' => 'Contact@Exemple.NE'],
            ['active' => 'boolean', 'email' => 'email']
        );

        $this->assertSame(['active' => false, 'email' => 'contact@exemple.ne'], $result);
    }

    public function testExceptionCarriesStandardCodeAndStatus(): void
    {
        try {
            $this->validator->validate([], ['name' => 'required']);
            $this->fail('ValidationException attendue');
        } catch (ValidationException $exception) {
            $this->assertSame(422, $exception->getStatus());
            $this->assertSame('VALIDATION_ERROR', $exception->getErrorCode());
        }
    }

    /**
     * @return array<string,string[]>
     */
    private function errorsFor(array $data, array $rules): array
    {
        try {
            $this->validator->validate($data, $rules);
        } catch (ValidationException $exception) {
            return $exception->getErrors();
        }
        $this->fail('ValidationException attendue');
        return [];
    }
}
