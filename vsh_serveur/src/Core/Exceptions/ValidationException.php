<?php

declare(strict_types=1);

namespace Vsh\Core\Exceptions;

final class ValidationException extends HttpException
{
    /**
     * @param array<string,string[]> $errors Messages par champ
     */
    public function __construct(array $errors, string $message = 'Les données fournies sont invalides.')
    {
        parent::__construct(422, 'VALIDATION_ERROR', $message, $errors);
    }
}
